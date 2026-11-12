<?php
/**
 * Central de conversas do WhatsApp (Fase 3).
 *
 * Acesso: a conversa segue SEMPRE o lead. Quem enxerga o lead (escopo da
 * função — rbac_leads_scope) enxerga a conversa; quando o lead é
 * transferido, o acesso muda na hora, sem copiar nada. Conversa ainda sem
 * lead (número desconhecido) só aparece para quem configura o WhatsApp
 * (Master), que pode ligá-la a um lead. Responder exige whatsapp_atender.
 */
require_once __DIR__ . '/whatsapp.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/arquivos.php';

/** A empresa usa a central (tem conta cadastrada e já ativada pelo menos uma vez)? */
function wa_central_ativa(int $empresaId): bool
{
    static $cache = [];
    if (!array_key_exists($empresaId, $cache)) {
        $conta = wa_conta_da_empresa($empresaId);
        $cache[$empresaId] = $conta !== null && $conta['status'] !== 'rascunho';
    }
    return $cache[$empresaId];
}

function wa_pode_atender(array $usuario): bool
{
    return empty($usuario['eh_superadmin']) && usuario_tem_permissao($usuario, 'whatsapp_atender');
}

function wa_ve_conversas_sem_lead(array $usuario): bool
{
    return empty($usuario['eh_superadmin']) && usuario_tem_permissao($usuario, 'whatsapp_configurar');
}

/**
 * Link do botão "Conversar no WhatsApp": a conversa dentro do WeMart quando
 * a empresa usa a central; senão o wa.me de antes (abre o WhatsApp do aparelho).
 * @return array{href: string, interno: bool}|null
 */
function wa_link_conversar(array $usuario, array $lead): ?array
{
    if (wa_central_ativa((int) $usuario['empresa_ativa_id']) && wa_pode_atender($usuario)) {
        return ['href' => '/pages/conversas.php?lead=' . (int) $lead['id'], 'interno' => true];
    }
    $externo = lead_link_whatsapp($lead['telefone'] ?? null);
    return $externo ? ['href' => $externo, 'interno' => false] : null;
}

/** Atributos HTML do link (interno não abre outra aba nem registra "tentativa"). */
function wa_link_atributos(array $link, int $leadId): string
{
    if ($link['interno']) {
        return 'href="' . htmlspecialchars($link['href']) . '"';
    }
    return 'href="' . htmlspecialchars($link['href']) . '" target="_blank" rel="noopener" data-registrar="whatsapp" data-lead="' . $leadId . '"';
}

/** SQL de visibilidade das conversas (alias cv e leads), com os parâmetros. */
function wa_conversas_escopo(array $usuario): array
{
    $scope = rbac_leads_scope($usuario);
    $semLead = wa_ve_conversas_sem_lead($usuario) ? 1 : 0;
    return [
        'sql' => '(cv.empresa_id = :wa_e AND ((cv.lead_id IS NOT NULL AND ' . $scope['sql'] . ') OR (cv.lead_id IS NULL AND ' . $semLead . ' = 1)))',
        'params' => $scope['params'] + ['wa_e' => (int) $usuario['empresa_ativa_id']],
    ];
}

const WA_SQL_CONVERSA = 'SELECT cv.*, ct.telefone, ct.nome_perfil, ct.opt_out_em, ct.ultima_entrada_em AS contato_entrada_em, ct.wa_id,
        leads.nome AS lead_nome, leads.responsavel_id, leads.empreendimento_id, leads.etapa_id, u.nome AS corretor_nome
    FROM whatsapp_conversas cv
    JOIN whatsapp_contatos ct ON ct.id = cv.contato_id AND ct.empresa_id = cv.empresa_id
    LEFT JOIN leads ON leads.id = cv.lead_id AND leads.empresa_id = cv.empresa_id
    LEFT JOIN usuarios u ON u.id = leads.responsavel_id';

/** Conversa que o usuário pode ver, ou null (outra empresa, fora do escopo ou inexistente). */
function wa_conversa_carregar(array $usuario, int $id): ?array
{
    $esc = wa_conversas_escopo($usuario);
    $stmt = db()->prepare(WA_SQL_CONVERSA . ' WHERE cv.id = :id AND ' . $esc['sql']);
    $stmt->execute(['id' => $id] + $esc['params']);
    return $stmt->fetch() ?: null;
}

/**
 * Lista de conversas. $filtro: todas | nao_lidas | aguardando (última mensagem foi do cliente) | sem_lead.
 */
function wa_conversas_listar(array $usuario, string $q = '', string $filtro = 'todas', ?int $incluirId = null, int $limite = 80): array
{
    $esc = wa_conversas_escopo($usuario);
    $where = [$esc['sql'], '(cv.ultima_mensagem_em IS NOT NULL' . ($incluirId ? ' OR cv.id = :incluir' : '') . ')'];
    $params = $esc['params'];
    if ($incluirId) {
        $params['incluir'] = $incluirId;
    }
    if ($filtro === 'nao_lidas') {
        $where[] = 'cv.nao_lidas > 0';
    } elseif ($filtro === 'aguardando') {
        $where[] = "cv.ultima_entrada_em IS NOT NULL AND cv.ultima_entrada_em > COALESCE(cv.ultima_saida_em, '1970-01-01')";
    } elseif ($filtro === 'sem_lead') {
        $where[] = 'cv.lead_id IS NULL';
    }
    $q = trim($q);
    if ($q !== '') {
        $digitos = preg_replace('/\D/', '', $q);
        $cond = '(leads.nome LIKE :q OR ct.nome_perfil LIKE :q2';
        $params['q'] = '%' . $q . '%';
        $params['q2'] = '%' . $q . '%';
        if (strlen($digitos) >= 4) {
            $cond .= ' OR ct.telefone LIKE :qd';
            $params['qd'] = '%' . $digitos . '%';
        }
        $where[] = $cond . ')';
    }
    $stmt = db()->prepare(WA_SQL_CONVERSA . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY cv.ultima_mensagem_em IS NULL, cv.ultima_mensagem_em DESC, cv.id DESC LIMIT ' . max(1, min(200, $limite)));
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Quantas conversas com mensagem não lida o usuário enxerga (selo no menu). */
function wa_conversas_nao_lidas(array $usuario): int
{
    if (!wa_pode_atender($usuario) || !wa_central_ativa((int) $usuario['empresa_ativa_id'])) {
        return 0;
    }
    $esc = wa_conversas_escopo($usuario);
    $stmt = db()->prepare('SELECT COUNT(*) FROM whatsapp_conversas cv LEFT JOIN leads ON leads.id = cv.lead_id AND leads.empresa_id = cv.empresa_id
                           WHERE cv.nao_lidas > 0 AND ' . $esc['sql']);
    $stmt->execute($esc['params']);
    return (int) $stmt->fetchColumn();
}

/** Mensagens da conversa (as últimas $limite, ou só as novas depois de $depoisId), em ordem. */
function wa_conversa_mensagens(int $empresaId, int $conversaId, int $depoisId = 0, int $limite = 150): array
{
    if ($depoisId > 0) {
        $stmt = db()->prepare('SELECT m.*, u.nome AS usuario_nome FROM whatsapp_mensagens m LEFT JOIN usuarios u ON u.id = m.enviada_por
                               WHERE m.empresa_id = :e AND m.conversa_id = :c AND m.id > :d ORDER BY m.id LIMIT 300');
        $stmt->execute(['e' => $empresaId, 'c' => $conversaId, 'd' => $depoisId]);
        return $stmt->fetchAll();
    }
    $stmt = db()->prepare('SELECT * FROM (SELECT m.*, u.nome AS usuario_nome FROM whatsapp_mensagens m LEFT JOIN usuarios u ON u.id = m.enviada_por
                           WHERE m.empresa_id = :e AND m.conversa_id = :c ORDER BY m.id DESC LIMIT ' . max(1, min(500, $limite)) . ') x ORDER BY id');
    $stmt->execute(['e' => $empresaId, 'c' => $conversaId]);
    return $stmt->fetchAll();
}

/** Situação atualizada das mensagens enviadas recentemente (para os ✓✓ mudarem sem recarregar). */
function wa_conversa_status_recentes(int $empresaId, int $conversaId): array
{
    $stmt = db()->prepare("SELECT id, status, erro_texto FROM whatsapp_mensagens WHERE empresa_id = :e AND conversa_id = :c AND direcao = 'saida'
                           AND criado_em >= DATE_SUB(NOW(), INTERVAL 3 DAY) ORDER BY id DESC LIMIT 60");
    $stmt->execute(['e' => $empresaId, 'c' => $conversaId]);
    return $stmt->fetchAll();
}

function wa_conversa_marcar_lida(int $empresaId, int $conversaId): void
{
    db()->prepare('UPDATE whatsapp_conversas SET nao_lidas = 0 WHERE id = :id AND empresa_id = :e AND nao_lidas > 0')->execute(['id' => $conversaId, 'e' => $empresaId]);
}

/**
 * Abre (ou cria) a conversa de um lead a partir do botão "Conversar no WhatsApp".
 * @return array{ok: bool, conversa_id?: int, erro?: string}
 */
function wa_conversa_do_lead(array $usuario, int $leadId): array
{
    $empresaId = (int) $usuario['empresa_ativa_id'];
    if (!wa_pode_atender($usuario)) {
        return ['ok' => false, 'erro' => 'Sua função não atende pelo WhatsApp.'];
    }
    if (!rbac_pode_ver_lead($usuario, $leadId)) {
        return ['ok' => false, 'erro' => 'Lead não encontrado.'];
    }
    $conta = wa_conta_da_empresa($empresaId);
    if (!$conta) {
        return ['ok' => false, 'erro' => 'O WhatsApp da empresa ainda não foi conectado.'];
    }
    $stmt = db()->prepare('SELECT telefone FROM leads WHERE id = :l AND empresa_id = :e');
    $stmt->execute(['l' => $leadId, 'e' => $empresaId]);
    $tel = (string) $stmt->fetchColumn();
    // Conversa já ligada a este lead (mesmo que o telefone tenha mudado).
    $stmt = db()->prepare('SELECT id FROM whatsapp_conversas WHERE empresa_id = :e AND lead_id = :l ORDER BY ultima_mensagem_em DESC, id DESC LIMIT 1');
    $stmt->execute(['e' => $empresaId, 'l' => $leadId]);
    if ($id = $stmt->fetchColumn()) {
        return ['ok' => true, 'conversa_id' => (int) $id];
    }
    $norm = wa_normalizar_telefone($tel);
    if (!$norm) {
        return ['ok' => false, 'erro' => 'O lead não tem um telefone válido para WhatsApp.'];
    }
    // Procura o contato com e sem o 9 antes de criar.
    $contato = null;
    foreach (wa_variantes_telefone($norm) as $v) {
        $stmt = db()->prepare('SELECT * FROM whatsapp_contatos WHERE empresa_id = :e AND (telefone = :t OR wa_id = :t2) LIMIT 1');
        $stmt->execute(['e' => $empresaId, 't' => $v, 't2' => $v]);
        if ($contato = $stmt->fetch()) {
            break;
        }
    }
    $contato = $contato ?: wa_contato_obter($empresaId, '+' . $norm, $leadId);
    if (!$contato['lead_id']) {
        db()->prepare('UPDATE whatsapp_contatos SET lead_id = :l WHERE id = :id AND lead_id IS NULL')->execute(['l' => $leadId, 'id' => $contato['id']]);
    }
    $conversa = wa_conversa_obter($empresaId, (int) $conta['id'], (int) $contato['id'], $leadId);
    if (!$conversa['lead_id']) {
        // Conversa do número existia sem lead (cliente escreveu antes de virar lead).
        db()->prepare('UPDATE whatsapp_conversas SET lead_id = :l WHERE id = :id AND lead_id IS NULL')->execute(['l' => $leadId, 'id' => $conversa['id']]);
    }
    return ['ok' => true, 'conversa_id' => (int) $conversa['id']];
}

/** Templates prontos para o chat: aprovados, sem arquivo no cabeçalho e com todas as variáveis ligadas. */
function wa_templates_para_chat(int $empresaId, array $dados): array
{
    $stmt = db()->prepare("SELECT * FROM whatsapp_templates WHERE empresa_id = :e AND status = 'aprovado' AND cabecalho_tipo IN ('nenhum','texto') ORDER BY finalidade, nome");
    $stmt->execute(['e' => $empresaId]);
    $saida = [];
    foreach ($stmt->fetchAll() as $t) {
        $r = wa_regra_renderizar($t['corpo'], wa_template_variaveis($t), $dados);
        $saida[] = ['id' => (int) $t['id'], 'nome' => $t['nome'], 'categoria' => $t['categoria'], 'previa' => $r['texto'],
                    'pronto' => $r['ok'], 'erros' => $r['erros']];
    }
    return $saida;
}

/** Próximos compromissos do lead (painel lateral). */
function wa_lead_proximos(int $empresaId, int $leadId): array
{
    $stmt = db()->prepare("SELECT titulo, tipo, inicio FROM agenda_compromissos WHERE empresa_id = :e AND lead_id = :l AND status = 'agendado'
                           ORDER BY inicio LIMIT 3");
    $stmt->execute(['e' => $empresaId, 'l' => $leadId]);
    return $stmt->fetchAll();
}

// ------------------------------------------------------------------ anexos e mídia

/** Tipos que o corretor pode anexar no chat. */
function wa_anexo_tipos(): array
{
    return ['application/pdf' => 'PDF', 'image/jpeg' => 'JPG', 'image/png' => 'PNG'];
}

const WA_ANEXO_MAX_BYTES = 15 * 1024 * 1024;

/**
 * Baixa da Meta a mídia de uma mensagem recebida (foto, documento…) e
 * guarda em storage/ para as próximas vezes. Devolve o caminho local.
 */
function wa_midia_local(array $msg, array $conta): array
{
    if ($msg['arquivo_ref']) {
        $abs = wa_arquivo_permitido($msg['arquivo_ref']);
        return $abs ? ['ok' => true, 'caminho' => $abs] : ['ok' => false, 'erro' => 'Arquivo não encontrado no servidor.'];
    }
    if (!$msg['midia_id_meta']) {
        return ['ok' => false, 'erro' => 'Esta mensagem não tem arquivo.'];
    }
    if (!wa_conta_envio_real($conta)) {
        return ['ok' => false, 'erro' => 'Em modo de teste os arquivos não são baixados da Meta.'];
    }
    $token = wa_conta_token($conta);
    $info = wa_api('GET', $msg['midia_id_meta'], $token);
    if (!$info['ok'] || empty($info['data']['url'])) {
        return ['ok' => false, 'erro' => $info['erro']['mensagem'] ?? 'A Meta não devolveu o endereço do arquivo.'];
    }
    if ((int) ($info['data']['file_size'] ?? 0) > WA_ANEXO_MAX_BYTES * 2) {
        return ['ok' => false, 'erro' => 'Arquivo grande demais para baixar aqui.'];
    }
    $url = (string) $info['data']['url'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false,
                            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
                            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token], CURLOPT_USERAGENT => 'WeMartSales/1.0']);
    $bytes = curl_exec($ch);
    $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($bytes === false || $st >= 400 || $bytes === '' || strlen($bytes) > WA_ANEXO_MAX_BYTES * 2) {
        return ['ok' => false, 'erro' => 'Não foi possível baixar o arquivo da Meta.'];
    }
    if (!is_dir(LEAD_ARQUIVO_DIR) && !mkdir(LEAD_ARQUIVO_DIR, 0750, true)) {
        return ['ok' => false, 'erro' => 'Pasta de arquivos indisponível no servidor.'];
    }
    $nome = bin2hex(random_bytes(16));
    file_put_contents(LEAD_ARQUIVO_DIR . '/' . $nome, $bytes);
    @chmod(LEAD_ARQUIVO_DIR . '/' . $nome, 0640);
    $ref = 'storage/lead_arquivos/' . $nome;
    db()->prepare('UPDATE whatsapp_mensagens SET arquivo_ref = :r WHERE id = :id AND arquivo_ref IS NULL')->execute(['r' => $ref, 'id' => $msg['id']]);
    return ['ok' => true, 'caminho' => LEAD_ARQUIVO_DIR . '/' . $nome];
}

/** Mensagem para o navegador (só o necessário; texto vai como texto, nunca HTML). */
function wa_mensagem_json(array $m): array
{
    return [
        'id' => (int) $m['id'],
        'direcao' => $m['direcao'],
        'tipo' => $m['tipo'],
        'texto' => (string) $m['conteudo'],
        'status' => $m['status'],
        'erro' => $m['erro_texto'],
        'quando' => $m['criado_em'],
        'autor' => $m['direcao'] === 'saida' ? ($m['usuario_nome'] ?? null) : null,
        'automatica' => $m['direcao'] === 'saida' && $m['enviada_por'] === null,
        'tem_arquivo' => (bool) ($m['arquivo_ref'] || ($m['midia_id_meta'] && $m['direcao'] === 'entrada')),
    ];
}

/** Abas do módulo WhatsApp (Conversas para quem atende; configuração para quem configura). */
function wa_render_abas(array $usuario, string $ativa): void
{
    $abas = [];
    if (wa_pode_atender($usuario)) {
        $abas['conversas'] = ['/pages/conversas.php', 'Conversas'];
    }
    if (usuario_tem_permissao($usuario, 'whatsapp_configurar')) {
        $abas['templates'] = ['/pages/whatsapp.php?aba=templates', 'Templates'];
        $abas['conexao'] = ['/pages/whatsapp.php?aba=conexao', 'Conexão'];
        $abas['atividade'] = ['/pages/whatsapp.php?aba=atividade', 'Atividade'];
    }
    if (count($abas) < 2) {
        return;
    }
    echo '<div class="tabs" role="tablist" aria-label="Seções do WhatsApp">';
    foreach ($abas as $chave => [$href, $rotulo]) {
        $atual = $chave === $ativa;
        echo '<a role="tab" href="' . htmlspecialchars($href) . '" class="tab' . ($atual ? ' is-current' : '') . '" aria-selected="' . ($atual ? 'true' : 'false') . '">' . htmlspecialchars($rotulo) . '</a>';
    }
    echo '</div>';
}
