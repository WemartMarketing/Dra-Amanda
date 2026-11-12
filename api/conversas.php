<?php
/**
 * POST /api/conversas.php  { acao, ... } — central de conversas do WhatsApp.
 * Permissão whatsapp_atender. Toda conversa é conferida contra o escopo do
 * usuário (wa_conversa_carregar): fora do escopo → 404, sem dizer se existe.
 *
 * acao:
 *   lista          { q?, filtro?, selecionada? }
 *   abrir          { id, depois_id? }     mensagens (todas ou só as novas) + painel do cliente
 *   enviar_texto   { id, texto }          só com a janela de 24 h aberta
 *   enviar_template{ id, template_id }
 *   enviar_arquivo { id, arquivo }        PDF/JPG/PNG, só com a janela aberta
 *   consentimento  { id, finalidade, acao: concedido|revogado, evidencia }
 *   vincular_lead  { id, lead_id }        conversas sem lead (quem configura o WhatsApp)
 *   buscar_leads   { q }
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/whatsapp_conversas.php';
require_once __DIR__ . '/../includes/automacao.php';
require_once __DIR__ . '/../includes/funil.php';

$usuario = api_iniciar('whatsapp_atender');
$empresaId = (int) $usuario['empresa_ativa_id'];
$uid = (int) $usuario['id'];
$acao = (string) ($_POST['acao'] ?? '');

function conv_carregar(array $usuario): array
{
    $c = wa_conversa_carregar($usuario, (int) ($_POST['id'] ?? 0));
    if (!$c) {
        api_erro('Conversa não encontrada.', 404);
    }
    return $c;
}

function conv_nome(array $c): string
{
    return $c['lead_nome'] ?: ($c['nome_perfil'] ?: wa_tel_formatado($c['telefone']));
}

function wa_tel_formatado(string $t): string
{
    return preg_match('/^55(\d{2})(\d{4,5})(\d{4})$/', $t, $m) ? '(' . $m[1] . ') ' . $m[2] . '-' . $m[3] : '+' . $t;
}

/** Envia na hora (sem esperar o cron) e devolve a mensagem atualizada. */
function conv_enviar(int $empresaId, array $c, array $envio, int $uid): void
{
    $r = wa_enfileirar($empresaId, (int) $c['contato_id'], $envio, ['usuario_id' => $uid, 'lead_id' => $c['lead_id'] ? (int) $c['lead_id'] : null]);
    if (!$r['ok']) {
        api_erro(($r['motivo'] ?? 'Mensagem não enviada.') . (!empty($r['erros']) ? ' ' . implode(' ', $r['erros']) : ''), 422);
    }
    fila_executar_chave('wa_enviar:' . $r['mensagem_id'], automacao_tratadores());
    $stmt = db()->prepare('SELECT m.*, u.nome AS usuario_nome FROM whatsapp_mensagens m LEFT JOIN usuarios u ON u.id = m.enviada_por WHERE m.id = :id AND m.empresa_id = :e');
    $stmt->execute(['id' => $r['mensagem_id'], 'e' => $empresaId]);
    $m = $stmt->fetch();
    if (in_array($m['status'], ['falhou', 'cancelada'], true)) {
        api_responder(['ok' => false, 'erro' => 'Não enviada: ' . ($m['erro_texto'] ?: 'recusada.'), 'mensagem' => wa_mensagem_json($m)], 502);
    }
    api_responder(['ok' => true, 'mensagem' => wa_mensagem_json($m)]);
}

switch ($acao) {
    case 'lista':
        $filtro = in_array($_POST['filtro'] ?? '', ['todas', 'nao_lidas', 'aguardando', 'sem_lead'], true) ? $_POST['filtro'] : 'todas';
        $sel = (int) ($_POST['selecionada'] ?? 0) ?: null;
        $itens = [];
        foreach (wa_conversas_listar($usuario, mb_substr((string) ($_POST['q'] ?? ''), 0, 80), $filtro, $sel) as $c) {
            $itens[] = [
                'id' => (int) $c['id'], 'nome' => conv_nome($c), 'telefone' => wa_tel_formatado($c['telefone']),
                'previa' => (string) $c['ultima_previa'], 'quando' => $c['ultima_mensagem_em'], 'nao_lidas' => (int) $c['nao_lidas'],
                'aguardando' => $c['ultima_entrada_em'] && $c['ultima_entrada_em'] > ($c['ultima_saida_em'] ?? '1970-01-01'),
                'sem_lead' => $c['lead_id'] === null, 'corretor' => $c['corretor_nome'],
            ];
        }
        api_responder(['ok' => true, 'conversas' => $itens, 'nao_lidas' => wa_conversas_nao_lidas($usuario)]);

    case 'abrir':
        $c = conv_carregar($usuario);
        $depois = max(0, (int) ($_POST['depois_id'] ?? 0));
        $msgs = array_map('wa_mensagem_json', wa_conversa_mensagens($empresaId, (int) $c['id'], $depois));
        if ((int) $c['nao_lidas'] > 0) {
            wa_conversa_marcar_lida($empresaId, (int) $c['id']);
        }
        $conta = wa_conta_da_empresa($empresaId);
        $janela = wa_regra_janela_aberta($c['contato_entrada_em']);
        $expira = wa_janela_expira_em($c['contato_entrada_em']);
        $resp = [
            'ok' => true,
            'conversa' => [
                'id' => (int) $c['id'], 'nome' => conv_nome($c), 'telefone' => wa_tel_formatado($c['telefone']),
                'janela_aberta' => $janela, 'janela_expira' => $janela && $expira ? $expira->format('Y-m-d H:i:s') : null,
                'opt_out' => $c['opt_out_em'] !== null,
                'reabriu' => $c['opt_out_em'] !== null && $c['contato_entrada_em'] && $c['contato_entrada_em'] > $c['opt_out_em'],
                'conta_status' => $conta['status'] ?? 'sem_conta', 'modo_teste' => $conta ? (int) $conta['modo_teste'] === 1 : false,
            ],
            'mensagens' => $msgs,
            'status' => $depois ? wa_conversa_status_recentes($empresaId, (int) $c['id']) : [],
        ];
        if (!$depois) {
            // Painel do cliente e opções de envio: só na abertura (o polling leva só o que é novo).
            $lead = null;
            if ($c['lead_id']) {
                $stmt = db()->prepare('SELECT l.id, l.nome, l.telefone, l.etapa_id, emp.nome AS empreendimento, u.nome AS corretor
                                       FROM leads l LEFT JOIN empreendimentos emp ON emp.id = l.empreendimento_id AND emp.empresa_id = l.empresa_id
                                       LEFT JOIN usuarios u ON u.id = l.responsavel_id WHERE l.id = :l AND l.empresa_id = :e');
                $stmt->execute(['l' => $c['lead_id'], 'e' => $empresaId]);
                $l = $stmt->fetch();
                $etapa = $l['etapa_id'] ? funil_etapa($empresaId, (int) $l['etapa_id']) : null;
                $lead = ['id' => (int) $l['id'], 'nome' => $l['nome'], 'telefone' => lead_formatar_telefone($l['telefone']), 'empreendimento' => $l['empreendimento'],
                         'corretor' => $l['corretor'], 'etapa' => $etapa['nome'] ?? null, 'etapa_cor' => $etapa['cor'] ?? null,
                         'proximos' => array_map(fn ($p) => ['titulo' => $p['titulo'], 'quando' => $p['inicio']], wa_lead_proximos($empresaId, (int) $l['id']))];
            }
            $consent = [];
            foreach (['atendimento', 'marketing'] as $f) {
                $a = wa_consentimento_atual($empresaId, (int) $c['contato_id'], $f);
                $consent[$f] = ['concedido' => $a['concedido'], 'origem' => $a['registro']['origem'] ?? null, 'quando' => $a['registro']['criado_em'] ?? null];
            }
            $resp['lead'] = $lead;
            $resp['consentimento'] = $consent;
            $resp['templates'] = wa_templates_para_chat($empresaId, wa_dados_lead($empresaId, $c['lead_id'] ? (int) $c['lead_id'] : null));
            $resp['pode_vincular'] = $c['lead_id'] === null && wa_ve_conversas_sem_lead($usuario);
        }
        api_responder($resp);

    case 'enviar_texto':
        $c = conv_carregar($usuario);
        $texto = trim((string) ($_POST['texto'] ?? ''));
        if ($texto === '' || mb_strlen($texto) > 4000) {
            api_erro($texto === '' ? 'Escreva a mensagem.' : 'Mensagem longa demais (máximo 4.000 caracteres).', 422);
        }
        conv_enviar($empresaId, $c, ['tipo' => 'texto', 'texto' => $texto], $uid);

    case 'enviar_template':
        $c = conv_carregar($usuario);
        $tpl = wa_template($empresaId, (int) ($_POST['template_id'] ?? 0));
        if (!$tpl) {
            api_erro('Template não encontrado.', 404);
        }
        conv_enviar($empresaId, $c, ['tipo' => 'template', 'template_id' => (int) $tpl['id'], 'dados' => wa_dados_lead($empresaId, $c['lead_id'] ? (int) $c['lead_id'] : null)], $uid);

    case 'enviar_arquivo':
        $c = conv_carregar($usuario);
        $r = lead_arquivo_receber('arquivo');
        if (!$r['ok']) {
            api_erro($r['erro'], 422);
        }
        if (!isset(wa_anexo_tipos()[$r['mime']]) || $r['tamanho'] > WA_ANEXO_MAX_BYTES) {
            @unlink(LEAD_ARQUIVO_DIR . '/' . $r['arquivo']);
            api_erro('Pelo WhatsApp envie PDF, JPG ou PNG de até 15 MB.', 422);
        }
        $legenda = trim((string) ($_POST['legenda'] ?? ''));
        conv_enviar($empresaId, $c, ['tipo' => 'documento', 'arquivo' => 'storage/lead_arquivos/' . $r['arquivo'], 'nome' => $r['nome_original'],
                                     'mime' => $r['mime'], 'legenda' => $legenda !== '' ? mb_substr($legenda, 0, 1000) : $r['nome_original']], $uid);

    case 'consentimento':
        $c = conv_carregar($usuario);
        $f = $_POST['finalidade'] ?? '';
        $a = $_POST['acao_consentimento'] ?? 'concedido';
        $evid = trim((string) ($_POST['evidencia'] ?? ''));
        if (!in_array($f, ['atendimento', 'marketing'], true) || !in_array($a, ['concedido', 'revogado'], true)) {
            api_erro('Opção inválida.', 422);
        }
        if ($a === 'concedido' && mb_strlen($evid) < 10) {
            api_erro('Descreva como o cliente autorizou (ex.: "autorizou por telefone em 12/11 às 10h"). Fica registrado como evidência.', 422);
        }
        wa_consentimento_registrar($empresaId, (int) $c['contato_id'], $f, $a, 'manual',
            ($evid !== '' ? $evid : 'Revogado pelo atendimento') . ' — registrado por ' . $usuario['nome'], $uid, $c['lead_id'] ? (int) $c['lead_id'] : null);
        if ($c['lead_id']) {
            wa_lead_evento((int) $c['lead_id'], $uid, 'Autorização de ' . ($f === 'marketing' ? 'ofertas (marketing)' : 'contato pelo WhatsApp') . ($a === 'concedido' ? ' registrada' : ' revogada') . ': ' . mb_substr($evid, 0, 200));
        }
        api_responder(['ok' => true, 'mensagem' => $a === 'concedido' ? 'Autorização registrada.' : 'Autorização revogada.']);

    case 'vincular_lead':
        $c = conv_carregar($usuario);
        if (!wa_ve_conversas_sem_lead($usuario) || $c['lead_id'] !== null) {
            api_erro('Esta conversa não pode ser vinculada.', 403);
        }
        $leadId = (int) ($_POST['lead_id'] ?? 0);
        if (!rbac_pode_ver_lead($usuario, $leadId)) {
            api_erro('Lead não encontrado.', 404);
        }
        db()->prepare('UPDATE whatsapp_conversas SET lead_id = :l WHERE id = :id AND empresa_id = :e AND lead_id IS NULL')->execute(['l' => $leadId, 'id' => $c['id'], 'e' => $empresaId]);
        db()->prepare('UPDATE whatsapp_contatos SET lead_id = :l WHERE id = :id AND empresa_id = :e AND lead_id IS NULL')->execute(['l' => $leadId, 'id' => $c['contato_id'], 'e' => $empresaId]);
        wa_lead_evento($leadId, $uid, 'Conversa do WhatsApp ' . wa_tel_formatado($c['telefone']) . ' ligada a este lead.');
        auditoria_registrar($empresaId, $uid, 'whatsapp.conversa_vinculada', 'whatsapp_conversa', (int) $c['id'], ['lead_id' => $leadId]);
        api_responder(['ok' => true, 'mensagem' => 'Conversa ligada ao lead.']);

    case 'buscar_leads':
        $q = trim((string) ($_POST['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            api_responder(['ok' => true, 'leads' => []]);
        }
        $scope = rbac_leads_scope($usuario);
        $digitos = preg_replace('/\D/', '', $q);
        $sql = 'SELECT leads.id, leads.nome, leads.telefone FROM leads WHERE ' . $scope['sql'] . ' AND (leads.nome LIKE :q'
             . (strlen($digitos) >= 4 ? ' OR ' . lead_sql_telefone_digitos('leads.telefone') . ' LIKE :d' : '') . ') ORDER BY leads.nome LIMIT 10';
        $params = $scope['params'] + ['q' => '%' . $q . '%'];
        if (strlen($digitos) >= 4) {
            $params['d'] = '%' . $digitos . '%';
        }
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        api_responder(['ok' => true, 'leads' => array_map(fn ($l) => ['id' => (int) $l['id'], 'nome' => $l['nome'], 'telefone' => lead_formatar_telefone($l['telefone'])], $stmt->fetchAll())]);

    default:
        api_erro('Ação desconhecida.', 400);
}
