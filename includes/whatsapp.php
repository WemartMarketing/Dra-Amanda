<?php
/**
 * WhatsApp — domínio: contas, conversas, envio pela fila, webhooks e
 * templates. Toda saída passa por:
 *
 *   wa_enfileirar()        valida as regras (whatsapp_regras.php), monta e
 *                          registra a mensagem 'na_fila' e agenda a tarefa
 *                          'wa_enviar' na automacao_fila;
 *   wa_tarefa_enviar()     (cron/webhook) confere as regras DE NOVO no
 *                          momento do envio — consentimento pode ter mudado —
 *                          e envia: em modo de teste só simula; senão chama
 *                          a Cloud API (whatsapp_api.php).
 *
 * Entrada: webhook/whatsapp.php grava cada aviso em whatsapp_webhook_eventos
 * (deduplicado) e wa_processar_evento() registra mensagens recebidas e
 * atualizações de status.
 *
 * Isolamento: toda consulta filtra por empresa_id; a empresa de um webhook
 * vem SÓ do phone_number_id cadastrado (nunca do conteúdo da mensagem).
 */
require_once __DIR__ . '/whatsapp_regras.php';
require_once __DIR__ . '/whatsapp_api.php';
require_once __DIR__ . '/automacao_fila.php';
require_once __DIR__ . '/leads.php';
require_once __DIR__ . '/cripto.php';

const WA_EVENTO_MAX_TENTATIVAS = 6;

// ================================================================== contas

function wa_conta(int $empresaId, int $contaId): ?array
{
    $stmt = db()->prepare('SELECT * FROM whatsapp_contas WHERE id = :id AND empresa_id = :e');
    $stmt->execute(['id' => $contaId, 'e' => $empresaId]);
    return $stmt->fetch() ?: null;
}

function wa_conta_token(array $conta): ?string
{
    return cripto_decifrar($conta['access_token_cifrado'] ?? null);
}

/** Pode enviar de verdade? (ativa, fora do modo de teste, com número e token) */
function wa_conta_envio_real(array $conta): bool
{
    return $conta['status'] === 'ativa' && !(int) $conta['modo_teste'] && $conta['phone_number_id'] && $conta['access_token_cifrado'];
}

function wa_qualidade_local(?string $q): string
{
    return ['GREEN' => 'verde', 'YELLOW' => 'amarela', 'RED' => 'vermelha'][strtoupper((string) $q)] ?? 'desconhecida';
}

/** Testa o token/número na Meta e atualiza nome verificado, número e qualidade. */
function wa_conta_testar(array $conta): array
{
    $token = wa_conta_token($conta);
    if (!$conta['phone_number_id'] || !$token) {
        return ['ok' => false, 'mensagem' => 'Cadastre o ID do número e o token antes de testar.'];
    }
    $r = wa_api_numero($conta['phone_number_id'], $token);
    $pdo = db();
    if (!$r['ok']) {
        $pdo->prepare("UPDATE whatsapp_contas SET status = IF(status = 'pausada', status, 'erro'), ultimo_erro = :m, atualizado_em = NOW() WHERE id = :id")
            ->execute(['m' => $r['erro']['mensagem'], 'id' => $conta['id']]);
        return ['ok' => false, 'mensagem' => $r['erro']['mensagem']];
    }
    $d = $r['data'];
    $pdo->prepare("UPDATE whatsapp_contas SET numero_exibicao = :n, nome_verificado = :v, qualidade = :q, token_validado_em = NOW(),
                          status = IF(status = 'pausada', status, 'ativa'), ultimo_erro = NULL, atualizado_em = NOW() WHERE id = :id")
        ->execute(['n' => mb_substr((string) ($d['display_phone_number'] ?? ''), 0, 30) ?: null, 'v' => mb_substr((string) ($d['verified_name'] ?? ''), 0, 190) ?: null,
                   'q' => wa_qualidade_local($d['quality_rating'] ?? null), 'id' => $conta['id']]);
    return ['ok' => true, 'mensagem' => 'Conexão OK: ' . ($d['verified_name'] ?? 'número') . ' (' . ($d['display_phone_number'] ?? $conta['phone_number_id']) . ').'];
}

/** Inscreve o app na WABA (sem isso a Meta não envia os webhooks desta conta). */
function wa_conta_assinar(array $conta): array
{
    $token = wa_conta_token($conta);
    if (!$conta['waba_id'] || !$token) {
        return ['ok' => false, 'mensagem' => 'Cadastre o ID da conta do WhatsApp Business (WABA) e o token.'];
    }
    $r = wa_api_assinar($conta['waba_id'], $token);
    if (!$r['ok'] || empty($r['data']['success'])) {
        return ['ok' => false, 'mensagem' => $r['erro']['mensagem'] ?? 'A Meta não confirmou a inscrição.'];
    }
    db()->prepare('UPDATE whatsapp_contas SET webhook_assinado_em = NOW(), atualizado_em = NOW() WHERE id = :id')->execute(['id' => $conta['id']]);
    return ['ok' => true, 'mensagem' => 'Recebimento ativado: mensagens e confirmações de entrega desta conta passam a chegar ao WeMart Sales.'];
}

// ================================================================== contatos, leads, conversas

/** Formas do mesmo número brasileiro com e sem o 9 (contas antigas do WhatsApp usam 8 dígitos). */
function wa_variantes_telefone(string $tel): array
{
    $v = [$tel];
    if (preg_match('/^55([1-9][0-9])9(\d{8})$/', $tel, $m)) {
        $v[] = '55' . $m[1] . $m[2];
    } elseif (preg_match('/^55([1-9][0-9])([6-9]\d{7})$/', $tel, $m)) {
        $v[] = '55' . $m[1] . '9' . $m[2];
    }
    return $v;
}

/** Contato que chega pelo webhook: procura nas variantes antes de criar. */
function wa_contato_entrada(int $empresaId, string $waId, ?string $nomePerfil): ?array
{
    $tel = wa_normalizar_telefone('+' . preg_replace('/\D/', '', $waId));
    if ($tel === null) {
        return null;
    }
    $variantes = wa_variantes_telefone($tel);
    $ph = implode(',', array_fill(0, count($variantes), '?'));
    $stmt = db()->prepare("SELECT * FROM whatsapp_contatos WHERE empresa_id = ? AND (telefone IN ($ph) OR wa_id IN ($ph)) ORDER BY id LIMIT 1");
    $stmt->execute(array_merge([$empresaId], $variantes, $variantes));
    $contato = $stmt->fetch();
    if (!$contato) {
        $contato = wa_contato_obter($empresaId, '+' . $tel, null, $nomePerfil ? mb_substr($nomePerfil, 0, 190) : null);
    }
    if ($contato && ($contato['wa_id'] !== $waId || ($nomePerfil && $contato['nome_perfil'] !== $nomePerfil))) {
        db()->prepare('UPDATE whatsapp_contatos SET wa_id = :w, nome_perfil = COALESCE(:n, nome_perfil), atualizado_em = NOW() WHERE id = :id')
            ->execute(['w' => mb_substr($waId, 0, 30), 'n' => $nomePerfil ? mb_substr($nomePerfil, 0, 190) : null, 'id' => $contato['id']]);
        $contato['wa_id'] = $waId;
    }
    return $contato ?: null;
}

/** Lead da empresa com este telefone (o mais recente; ignora excluídos). Compara dígitos, com e sem o 9. */
function wa_lead_por_telefone(int $empresaId, string $telNormalizado): ?int
{
    $alvos = [];
    foreach (wa_variantes_telefone($telNormalizado) as $v) {
        $alvos[] = str_starts_with($v, '55') ? substr($v, 2) : $v;   // como lead_telefone_normalizado()
    }
    $fim = substr($telNormalizado, -8);
    $stmt = db()->prepare('SELECT id, telefone FROM leads WHERE empresa_id = :e AND excluido_em IS NULL AND '
        . lead_sql_telefone_digitos('telefone') . ' LIKE :fim ORDER BY COALESCE(ultima_atividade_em, criado_em) DESC, id DESC LIMIT 50');
    $stmt->execute(['e' => $empresaId, 'fim' => '%' . substr($fim, -7)]);
    foreach ($stmt->fetchAll() as $l) {
        if (in_array(lead_telefone_normalizado($l['telefone']), $alvos, true)) {
            return (int) $l['id'];
        }
    }
    return null;
}

/** Conversa única por (número da empresa, contato). Liga ao lead se ainda não estiver ligada. */
function wa_conversa_obter(int $empresaId, int $contaId, int $contatoId, ?int $leadId): array
{
    $pdo = db();
    $pdo->prepare('INSERT INTO whatsapp_conversas (empresa_id, conta_id, contato_id, lead_id) VALUES (:e, :c, :ct, :l)
                   ON DUPLICATE KEY UPDATE lead_id = COALESCE(lead_id, VALUES(lead_id))')
        ->execute(['e' => $empresaId, 'c' => $contaId, 'ct' => $contatoId, 'l' => $leadId]);
    $stmt = $pdo->prepare('SELECT * FROM whatsapp_conversas WHERE conta_id = :c AND contato_id = :ct AND empresa_id = :e');
    $stmt->execute(['c' => $contaId, 'ct' => $contatoId, 'e' => $empresaId]);
    return $stmt->fetch();
}

/** Histórico do lead SEM contar como atividade do corretor (mensagem do cliente/automação não é atendimento). */
function wa_lead_evento(int $leadId, ?int $usuarioId, string $descricao): void
{
    db()->prepare("INSERT INTO lead_eventos (lead_id, usuario_id, tipo, descricao) VALUES (:l, :u, 'whatsapp', :d)")
        ->execute(['l' => $leadId, 'u' => $usuarioId, 'd' => mb_substr($descricao, 0, 500)]);
}

/** Valor de tarifa vigente para estimativa (da empresa, senão o padrão da plataforma). */
function wa_tarifa(int $empresaId, ?string $categoria): ?float
{
    if (!$categoria) {
        return null;
    }
    $stmt = db()->prepare('SELECT valor FROM whatsapp_tarifas WHERE (empresa_id = :e OR empresa_id IS NULL) AND categoria = :c AND vigente_desde <= CURDATE()
                           ORDER BY empresa_id IS NULL, vigente_desde DESC LIMIT 1');
    $stmt->execute(['e' => $empresaId, 'c' => $categoria]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (float) $v;
}

// ================================================================== templates

function wa_template(int $empresaId, int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM whatsapp_templates WHERE id = :id AND empresa_id = :e');
    $stmt->execute(['id' => $id, 'e' => $empresaId]);
    return $stmt->fetch() ?: null;
}

function wa_template_variaveis(array $tpl): array
{
    return json_decode((string) ($tpl['variaveis'] ?? ''), true) ?: [];
}

function wa_template_botoes(array $tpl): array
{
    return json_decode((string) ($tpl['botoes'] ?? ''), true) ?: [];
}

/** Ações que um botão de resposta rápida pode disparar (usadas pelas jornadas). */
function wa_acoes_botao(): array
{
    return ['' => 'Nenhuma ação', 'pedir_book' => 'Enviar o book do empreendimento', 'falar_consultor' => 'Pedir atendimento de um consultor', 'agora_nao' => 'Cliente não quer agora'];
}

/** Dados reais para preencher variáveis a partir do lead. */
function wa_dados_lead(int $empresaId, ?int $leadId): array
{
    $stmt = db()->prepare('SELECT nome FROM empresas WHERE id = :e');
    $stmt->execute(['e' => $empresaId]);
    $dados = ['empresa.nome' => (string) $stmt->fetchColumn()];
    if ($leadId) {
        $stmt = db()->prepare('SELECT l.nome, emp.nome AS emp_nome, emp.bairro AS emp_bairro, u.nome AS corretor
                               FROM leads l LEFT JOIN empreendimentos emp ON emp.id = l.empreendimento_id AND emp.empresa_id = l.empresa_id
                               LEFT JOIN usuarios u ON u.id = l.responsavel_id
                               WHERE l.id = :l AND l.empresa_id = :e');
        $stmt->execute(['l' => $leadId, 'e' => $empresaId]);
        if ($l = $stmt->fetch()) {
            $dados += [
                'lead.nome' => (string) $l['nome'], 'lead.primeiro_nome' => (string) strtok(trim((string) $l['nome']), ' '),
                'empreendimento.nome' => (string) $l['emp_nome'], 'empreendimento.bairro' => (string) $l['emp_bairro'],
                'corretor.nome' => (string) $l['corretor'], 'corretor.primeiro_nome' => (string) strtok(trim((string) $l['corretor']), ' '),
            ];
        }
    }
    return $dados;
}

/** Dados fictícios para a prévia (nunca usados para enviar). */
function wa_dados_ficticios(string $empresaNome): array
{
    return ['lead.nome' => 'Maria Exemplo da Silva', 'lead.primeiro_nome' => 'Maria', 'empresa.nome' => $empresaNome,
            'empreendimento.nome' => 'Residencial Exemplo', 'empreendimento.bairro' => 'Centro',
            'corretor.nome' => 'João Corretor', 'corretor.primeiro_nome' => 'João'];
}

function wa_status_template_local(string $s): string
{
    return ['APPROVED' => 'aprovado', 'PENDING' => 'pendente', 'IN_APPEAL' => 'pendente', 'REJECTED' => 'rejeitado', 'PAUSED' => 'pausado',
            'DISABLED' => 'desativado', 'PENDING_DELETION' => 'desativado', 'DELETED' => 'desativado', 'ARCHIVED' => 'desativado',
            'LIMIT_EXCEEDED' => 'desativado'][strtoupper($s)] ?? 'pendente';
}

/** Converte um template da Meta para as colunas locais (mantém a ação já escolhida para cada botão). */
function wa_template_de_meta(array $t, array $botoesAntigos = []): array
{
    $acaoPorTexto = [];
    foreach ($botoesAntigos as $b) {
        $acaoPorTexto[$b['texto'] ?? ''] = $b['acao'] ?? '';
    }
    $l = ['cabecalho_tipo' => 'nenhum', 'corpo' => '', 'rodape' => null, 'botoes' => []];
    foreach ((array) ($t['components'] ?? []) as $c) {
        $tipo = strtoupper($c['type'] ?? '');
        if ($tipo === 'HEADER') {
            $l['cabecalho_tipo'] = ['TEXT' => 'texto', 'DOCUMENT' => 'documento', 'IMAGE' => 'imagem', 'VIDEO' => 'video'][strtoupper($c['format'] ?? '')] ?? 'nenhum';
        } elseif ($tipo === 'BODY') {
            $l['corpo'] = (string) ($c['text'] ?? '');
        } elseif ($tipo === 'FOOTER') {
            $l['rodape'] = mb_substr((string) ($c['text'] ?? ''), 0, 60);
        } elseif ($tipo === 'BUTTONS') {
            foreach ((array) ($c['buttons'] ?? []) as $b) {
                $texto = (string) ($b['text'] ?? '');
                $bt = strtoupper($b['type'] ?? '');
                $l['botoes'][] = ['tipo' => $bt === 'QUICK_REPLY' ? 'resposta' : ($bt === 'URL' ? 'link' : ($bt === 'PHONE_NUMBER' ? 'telefone' : 'outro')),
                                  'texto' => $texto, 'acao' => $bt === 'QUICK_REPLY' ? ($acaoPorTexto[$texto] ?? '') : ''];
            }
        }
    }
    $cat = strtolower((string) ($t['category'] ?? ''));
    $l['categoria'] = in_array($cat, ['marketing', 'utility', 'authentication'], true) ? $cat : 'utility';
    $l['status'] = wa_status_template_local((string) ($t['status'] ?? ''));
    return $l;
}

/** Busca os templates na Meta e atualiza a lista local. Mapeamentos feitos no WeMart são preservados. */
function wa_templates_sincronizar(array $conta): array
{
    $token = wa_conta_token($conta);
    if (!$conta['waba_id'] || !$token) {
        return ['ok' => false, 'mensagem' => 'Cadastre o ID da conta do WhatsApp Business (WABA) e o token.'];
    }
    $r = wa_api_templates($conta['waba_id'], $token);
    if (!$r['ok']) {
        return ['ok' => false, 'mensagem' => $r['erro']['mensagem']];
    }
    $pdo = db();
    $busca = $pdo->prepare('SELECT id, botoes FROM whatsapp_templates WHERE conta_id = :c AND nome = :n AND idioma = :i');
    $novos = $atualizados = 0;
    $vistos = [];
    foreach ($r['data'] as $t) {
        $nome = (string) ($t['name'] ?? '');
        $idioma = (string) ($t['language'] ?? 'pt_BR');
        if ($nome === '') {
            continue;
        }
        $busca->execute(['c' => $conta['id'], 'n' => $nome, 'i' => $idioma]);
        $atual = $busca->fetch();
        $l = wa_template_de_meta($t, $atual ? (json_decode((string) $atual['botoes'], true) ?: []) : []);
        $params = ['mid' => (string) ($t['id'] ?? '') ?: null, 'cat' => $l['categoria'], 'st' => $l['status'], 'cab' => $l['cabecalho_tipo'],
                   'corpo' => $l['corpo'], 'rod' => $l['rodape'], 'bot' => $l['botoes'] ? json_encode($l['botoes'], JSON_UNESCAPED_UNICODE) : null,
                   'mot' => isset($t['rejected_reason']) && $t['rejected_reason'] !== 'NONE' ? mb_substr((string) $t['rejected_reason'], 0, 500) : null];
        if ($atual) {
            $pdo->prepare('UPDATE whatsapp_templates SET meta_template_id = :mid, categoria = :cat, status = :st, cabecalho_tipo = :cab, corpo = :corpo,
                                  rodape = :rod, botoes = :bot, motivo_rejeicao = :mot, sincronizado_em = NOW(), atualizado_em = NOW() WHERE id = :id')
                ->execute($params + ['id' => $atual['id']]);
            $vistos[] = (int) $atual['id'];
            $atualizados++;
        } else {
            $pdo->prepare('INSERT INTO whatsapp_templates (empresa_id, conta_id, meta_template_id, nome, idioma, categoria, status, cabecalho_tipo, corpo, rodape, botoes, motivo_rejeicao, sincronizado_em)
                           VALUES (:e, :c, :mid, :nome, :idi, :cat, :st, :cab, :corpo, :rod, :bot, :mot, NOW())')
                ->execute($params + ['e' => $conta['empresa_id'], 'c' => $conta['id'], 'nome' => mb_substr($nome, 0, 512), 'idi' => mb_substr($idioma, 0, 10)]);
            $vistos[] = (int) $pdo->lastInsertId();
            $novos++;
        }
    }
    // Sumiu da Meta (apagado lá): não pode mais ser usado.
    $sql = "UPDATE whatsapp_templates SET status = 'desativado', atualizado_em = NOW() WHERE conta_id = ? AND meta_template_id IS NOT NULL AND status <> 'desativado'";
    $args = [$conta['id']];
    if ($vistos) {
        $sql .= ' AND id NOT IN (' . implode(',', array_fill(0, count($vistos), '?')) . ')';
        $args = array_merge($args, $vistos);
    }
    $sumiram = $pdo->prepare($sql);
    $sumiram->execute($args);
    return ['ok' => true, 'novos' => $novos, 'atualizados' => $atualizados, 'removidos' => $sumiram->rowCount(),
            'mensagem' => "Templates sincronizados: $novos novo(s), $atualizados atualizado(s)" . ($sumiram->rowCount() ? ', ' . $sumiram->rowCount() . ' removido(s) na Meta' : '') . '.'];
}

// ================================================================== envio

/**
 * Registra uma mensagem para envio e agenda a tarefa. NÃO envia na hora.
 *
 * $envio:
 *   ['tipo' => 'texto', 'texto' => …]
 *   ['tipo' => 'template', 'template_id' => …, 'dados' => [campo => valor]]  (dados: wa_dados_lead())
 *   ['tipo' => 'documento', 'arquivo' => caminho relativo ao projeto (storage/…), 'nome' => …, 'mime' => …, 'legenda' => …]
 *   ['tipo' => 'botoes', 'texto' => …, 'botoes' => [['id' => …, 'titulo' => …], …]]
 * $opcoes: usuario_id (null = automação), idempotencia, executar_em (DateTimeInterface),
 *          jornada_execucao_id, campanha_id, ignorar_horario, lead_id
 *
 * @return array{ok: bool, mensagem_id?: int, duplicada?: bool, codigo?: string, motivo?: string, erros?: string[]}
 */
function wa_enfileirar(int $empresaId, int $contatoId, array $envio, array $opcoes = []): array
{
    $pdo = db();
    $contato = wa_contato($empresaId, $contatoId);
    if (!$contato) {
        return ['ok' => false, 'codigo' => 'contato_invalido', 'motivo' => 'Contato não encontrado nesta empresa.'];
    }
    $conta = wa_conta_da_empresa($empresaId);
    if (!$conta) {
        return ['ok' => false, 'codigo' => 'conta_inativa', 'motivo' => 'Nenhum WhatsApp conectado nesta empresa.'];
    }
    $idem = isset($opcoes['idempotencia']) ? mb_substr((string) $opcoes['idempotencia'], 0, 120) : null;
    if ($idem) {
        $stmt = $pdo->prepare('SELECT id FROM whatsapp_mensagens WHERE idempotencia_chave = :k AND empresa_id = :e');
        $stmt->execute(['k' => $idem, 'e' => $empresaId]);
        if ($id = $stmt->fetchColumn()) {
            return ['ok' => true, 'mensagem_id' => (int) $id, 'duplicada' => true];
        }
    }

    $tipo = $envio['tipo'] ?? '';
    $automatica = !isset($opcoes['usuario_id']);
    $template = null;
    $conteudo = '';
    $tipoMsg = $tipo;
    $categoria = 'service';
    $payload = ['envio' => $envio];
    switch ($tipo) {
        case 'texto':
            $conteudo = trim((string) ($envio['texto'] ?? ''));
            if ($conteudo === '') {
                return ['ok' => false, 'codigo' => 'vazia', 'motivo' => 'Escreva a mensagem.'];
            }
            break;
        case 'botoes':
            $conteudo = trim((string) ($envio['texto'] ?? ''));
            $tipoMsg = 'interativo';
            if ($conteudo === '' || empty($envio['botoes'])) {
                return ['ok' => false, 'codigo' => 'vazia', 'motivo' => 'Mensagem com botões precisa de texto e de pelo menos um botão.'];
            }
            break;
        case 'documento':
            $caminho = wa_arquivo_permitido((string) ($envio['arquivo'] ?? ''));
            if (!$caminho) {
                return ['ok' => false, 'codigo' => 'arquivo_invalido', 'motivo' => 'Arquivo não encontrado ou fora da pasta permitida.'];
            }
            $conteudo = (string) ($envio['legenda'] ?? $envio['nome'] ?? 'Documento');
            break;
        case 'template':
            $template = wa_template($empresaId, (int) ($envio['template_id'] ?? 0));
            if (!$template) {
                return ['ok' => false, 'codigo' => 'template_invalido', 'motivo' => 'Template não encontrado.'];
            }
            if (in_array($template['cabecalho_tipo'], ['documento', 'imagem', 'video'], true) && empty($envio['cabecalho'])) {
                return ['ok' => false, 'codigo' => 'cabecalho', 'motivo' => 'Este template tem um arquivo no cabeçalho: ele só pode ser enviado junto com o arquivo (book).'];
            }
            $render = wa_regra_renderizar($template['corpo'], wa_template_variaveis($template), (array) ($envio['dados'] ?? []));
            if (!$render['ok']) {
                return ['ok' => false, 'codigo' => 'variaveis', 'motivo' => 'Dados incompletos para o template.', 'erros' => $render['erros']];
            }
            $conteudo = $render['texto'];
            $categoria = $template['categoria'];
            $payload['parametros'] = $render['parametros'];
            break;
        default:
            return ['ok' => false, 'codigo' => 'tipo_invalido', 'motivo' => 'Tipo de mensagem desconhecido.'];
    }

    $regra = wa_verificar_envio($empresaId, $contatoId, [
        'tipo' => $tipo === 'template' ? 'template' : 'livre', 'automatica' => $automatica, 'template' => $template,
        'ignorar_horario' => !empty($opcoes['ignorar_horario']), 'resposta_ao_cliente' => !empty($opcoes['resposta_ao_cliente']),
    ]);
    $quando = $opcoes['executar_em'] ?? null;
    if (!$regra['pode']) {
        if (!$automatica || !$regra['adiar_para']) {
            return ['ok' => false, 'codigo' => $regra['codigo'], 'motivo' => $regra['motivo']];
        }
        $quando = $regra['adiar_para'];   // automação fora do horário: agenda para o próximo horário permitido
    }

    $leadId = isset($opcoes['lead_id']) ? (int) $opcoes['lead_id'] : ($contato['lead_id'] ? (int) $contato['lead_id'] : null);
    $conversa = wa_conversa_obter($empresaId, (int) $conta['id'], $contatoId, $leadId);
    try {
        $pdo->prepare('INSERT INTO whatsapp_mensagens (empresa_id, conversa_id, direcao, tipo, conteudo, template_id, categoria, arquivo_ref, idempotencia_chave,
                              status, enviada_por, jornada_execucao_id, campanha_id)
                       VALUES (:e, :cv, \'saida\', :t, :c, :tpl, :cat, :arq, :idem, \'na_fila\', :u, :j, :camp)')
            ->execute(['e' => $empresaId, 'cv' => $conversa['id'], 't' => $tipoMsg, 'c' => mb_substr($conteudo, 0, 4096),
                       'tpl' => $template['id'] ?? null, 'cat' => $categoria, 'arq' => $tipo === 'documento' ? (string) $envio['arquivo'] : null,
                       'idem' => $idem, 'u' => $opcoes['usuario_id'] ?? null, 'j' => $opcoes['jornada_execucao_id'] ?? null, 'camp' => $opcoes['campanha_id'] ?? null]);
    } catch (PDOException $e) {
        if ($idem && $e->getCode() === '23000') {   // corrida: outro processo registrou a mesma chave
            $stmt = $pdo->prepare('SELECT id FROM whatsapp_mensagens WHERE idempotencia_chave = :k AND empresa_id = :e');
            $stmt->execute(['k' => $idem, 'e' => $empresaId]);
            return ['ok' => true, 'mensagem_id' => (int) $stmt->fetchColumn(), 'duplicada' => true];
        }
        throw $e;
    }
    $mensagemId = (int) $pdo->lastInsertId();
    $payload['mensagem_id'] = $mensagemId;
    $payload['ignorar_horario'] = !empty($opcoes['ignorar_horario']);
    $payload['resposta_ao_cliente'] = !empty($opcoes['resposta_ao_cliente']);
    fila_agendar($empresaId, 'wa_enviar', $payload, 'wa_enviar:' . $mensagemId, $quando, 'wa_contato', $contatoId, 5);
    return ['ok' => true, 'mensagem_id' => $mensagemId, 'agendada_para' => $quando ? $quando->format('Y-m-d H:i:s') : null];
}

/** Caminho absoluto de um arquivo que pode ser enviado (só dentro de storage/ e uploads/ do projeto). */
function wa_arquivo_permitido(string $relativo): ?string
{
    if ($relativo === '' || str_contains($relativo, '..')) {
        return null;
    }
    $raiz = realpath(__DIR__ . '/..');
    $abs = realpath($raiz . '/' . ltrim($relativo, '/'));
    if (!$abs || !is_file($abs)) {
        return null;
    }
    foreach (['/storage/', '/uploads/'] as $pasta) {
        if (str_starts_with($abs, $raiz . $pasta)) {
            return $abs;
        }
    }
    return null;
}

/** Tratador da tarefa 'wa_enviar' (automacao_fila). */
function wa_tarefa_enviar(array $tarefa): array
{
    $pdo = db();
    $empresaId = (int) $tarefa['empresa_id'];
    $p = $tarefa['payload'];
    $stmt = $pdo->prepare('SELECT m.*, cv.contato_id, cv.conta_id, cv.lead_id FROM whatsapp_mensagens m JOIN whatsapp_conversas cv ON cv.id = m.conversa_id
                           WHERE m.id = :id AND m.empresa_id = :e');
    $stmt->execute(['id' => (int) ($p['mensagem_id'] ?? 0), 'e' => $empresaId]);
    $msg = $stmt->fetch();
    if (!$msg) {
        return ['status' => 'falha_definitiva', 'mensagem' => 'Mensagem não encontrada.'];
    }
    if ($msg['status'] !== 'na_fila') {
        return ['status' => 'ok', 'mensagem' => 'Já processada (' . $msg['status'] . ').'];   // idempotente
    }
    $conta = wa_conta($empresaId, (int) $msg['conta_id']);
    $contato = wa_contato($empresaId, (int) $msg['contato_id']);
    $envio = (array) ($p['envio'] ?? []);
    $template = $msg['template_id'] ? wa_template($empresaId, (int) $msg['template_id']) : null;
    $ultima = (int) $tarefa['tentativas'] >= (int) $tarefa['max_tentativas'];
    $cancelar = function (string $status, string $motivo, ?string $codigo = null) use ($pdo, $msg) {
        $pdo->prepare('UPDATE whatsapp_mensagens SET status = :s, erro_codigo = :c, erro_texto = :t WHERE id = :id')
            ->execute(['s' => $status, 'c' => $codigo, 't' => mb_substr($motivo, 0, 500), 'id' => $msg['id']]);
    };

    // Regras de novo, no momento do envio: o cliente pode ter se descadastrado, o template pode ter sido pausado…
    $regra = wa_verificar_envio($empresaId, (int) $msg['contato_id'], [
        'tipo' => ($envio['tipo'] ?? '') === 'template' ? 'template' : 'livre', 'automatica' => $msg['enviada_por'] === null,
        'template' => $template, 'ignorar_horario' => !empty($p['ignorar_horario']),
        'resposta_ao_cliente' => !empty($p['resposta_ao_cliente']), 'mensagem_id' => (int) $msg['id'],
    ]);
    if (!$regra['pode']) {
        if ($regra['adiar_para'] && !$ultima) {
            return ['status' => 'adiar', 'quando' => $regra['adiar_para'], 'mensagem' => $regra['motivo']];
        }
        $cancelar('cancelada', $regra['motivo'], $regra['codigo']);
        return ['status' => 'falha_definitiva', 'mensagem' => 'Não enviada: ' . $regra['motivo']];
    }

    $pdo->prepare("UPDATE whatsapp_mensagens SET status = 'enviando' WHERE id = :id AND status = 'na_fila'")->execute(['id' => $msg['id']]);
    $para = $contato['wa_id'] ?: $contato['telefone'];
    $simulada = !wa_conta_envio_real($conta);
    if ($simulada) {
        if ($conta['status'] !== 'ativa') {
            $cancelar('cancelada', 'WhatsApp da empresa pausado ou com erro.', 'conta_inativa');
            return ['status' => 'falha_definitiva', 'mensagem' => 'Conta inativa.'];
        }
        $r = ['ok' => true, 'wamid' => 'simulado.' . bin2hex(random_bytes(12)), 'wa_id' => null];
    } else {
        $token = wa_conta_token($conta);
        $corpo = null;
        switch ($envio['tipo'] ?? '') {
            case 'texto':
                $corpo = wa_api_corpo_texto($para, (string) $envio['texto']);
                break;
            case 'botoes':
                $corpo = wa_api_corpo_botoes($para, (string) $envio['texto'], (array) $envio['botoes']);
                break;
            case 'template':
                $corpo = wa_api_corpo_template($para, $template['nome'], $template['idioma'], (array) ($p['parametros'] ?? []));
                break;
            case 'documento':
                $abs = wa_arquivo_permitido((string) ($envio['arquivo'] ?? ''));
                if (!$abs) {
                    $cancelar('falhou', 'Arquivo não encontrado no servidor.', 'arquivo');
                    return ['status' => 'falha_definitiva', 'mensagem' => 'Arquivo não encontrado.'];
                }
                $up = wa_api_upload_midia($conta['phone_number_id'], $token, $abs, (string) ($envio['mime'] ?? 'application/pdf'), (string) ($envio['nome'] ?? basename($abs)));
                if (!$up['ok']) {
                    $r = $up;
                    break;
                }
                $pdo->prepare('UPDATE whatsapp_mensagens SET midia_id_meta = :m WHERE id = :id')->execute(['m' => $up['media_id'], 'id' => $msg['id']]);
                $corpo = wa_api_corpo_documento($para, $up['media_id'], (string) ($envio['nome'] ?? basename($abs)), $envio['legenda'] ?? null);
                break;
        }
        if ($corpo !== null) {
            $r = wa_api_enviar($conta['phone_number_id'], $token, $corpo);
        } elseif (!isset($r)) {
            $cancelar('falhou', 'Tipo de mensagem desconhecido.', 'tipo');
            return ['status' => 'falha_definitiva', 'mensagem' => 'Tipo desconhecido.'];
        }
    }

    if (!$r['ok']) {
        $erro = $r['erro'];
        wa_aplicar_efeito_erro($empresaId, $conta, $template, (int) $msg['contato_id'], $erro);
        if ($erro['temporario'] && !$ultima) {
            $pdo->prepare("UPDATE whatsapp_mensagens SET status = 'na_fila', erro_codigo = :c, erro_texto = :t WHERE id = :id")
                ->execute(['c' => $erro['codigo'], 't' => $erro['mensagem'], 'id' => $msg['id']]);
            return ['status' => 'falha', 'mensagem' => $erro['mensagem']];
        }
        $cancelar('falhou', $erro['mensagem'], $erro['codigo']);
        if ($msg['lead_id']) {
            wa_lead_evento((int) $msg['lead_id'], null, 'WhatsApp não enviado: ' . $erro['mensagem']);
        }
        return ['status' => 'falha_definitiva', 'mensagem' => $erro['mensagem']];
    }

    $custo = $template ? wa_tarifa($empresaId, $template['categoria']) : wa_tarifa($empresaId, 'service');
    $pdo->prepare("UPDATE whatsapp_mensagens SET status = :s, wamid = :w, enviada_em = NOW(), erro_codigo = NULL, erro_texto = NULL, custo_estimado = :c WHERE id = :id")
        ->execute(['s' => $simulada ? 'simulada' : 'enviada', 'w' => $r['wamid'], 'c' => $simulada ? null : $custo, 'id' => $msg['id']]);
    if (!empty($r['wa_id']) && $r['wa_id'] !== $contato['wa_id']) {
        $pdo->prepare('UPDATE whatsapp_contatos SET wa_id = :w WHERE id = :id')->execute(['w' => mb_substr($r['wa_id'], 0, 30), 'id' => $contato['id']]);
    }
    $previa = mb_substr(($simulada ? '[teste] ' : '') . $msg['conteudo'], 0, 190);
    $pdo->prepare('UPDATE whatsapp_conversas SET ultima_saida_em = NOW(), ultima_mensagem_em = NOW(), ultima_previa = :p,
                          humano_assumiu_em = IF(:humano = 1, COALESCE(humano_assumiu_em, NOW()), humano_assumiu_em) WHERE id = :id')
        ->execute(['p' => $previa, 'humano' => $msg['enviada_por'] !== null ? 1 : 0, 'id' => $msg['conversa_id']]);
    if ($msg['lead_id'] && $msg['enviada_por'] !== null && function_exists('pendencias_resolver_whatsapp')) {
        pendencias_resolver_whatsapp($empresaId, (int) $msg['lead_id'], (int) $msg['enviada_por']);
    }
    if ($msg['lead_id']) {
        $quem = $msg['enviada_por'] === null ? 'Mensagem automática' : 'Mensagem';
        wa_lead_evento((int) $msg['lead_id'], $msg['enviada_por'] !== null ? (int) $msg['enviada_por'] : null,
            $quem . ($simulada ? ' SIMULADA (modo de teste, não enviada)' : ' enviada') . ' pelo WhatsApp: "' . mb_substr($msg['conteudo'], 0, 300) . '"');
    }
    return ['status' => 'ok', 'mensagem' => $simulada ? 'Simulada (modo de teste).' : 'Enviada: ' . $r['wamid']];
}

/** Efeitos de um erro da Meta: conta com problema, template pausado, cliente que saiu do marketing. */
function wa_aplicar_efeito_erro(int $empresaId, ?array $conta, ?array $template, int $contatoId, array $erro): void
{
    $pdo = db();
    switch ($erro['efeito']) {
        case 'conta_erro':
            if ($conta) {
                $pdo->prepare("UPDATE whatsapp_contas SET status = 'erro', ultimo_erro = :m, atualizado_em = NOW() WHERE id = :id")
                    ->execute(['m' => $erro['mensagem'], 'id' => $conta['id']]);
                auditoria_registrar($empresaId, null, 'whatsapp.conta_erro', 'whatsapp_conta', (int) $conta['id'], ['codigo' => $erro['codigo']]);
            }
            break;
        case 'template_pausado':
        case 'template_desativado':
            if ($template) {
                $pdo->prepare('UPDATE whatsapp_templates SET status = :s, atualizado_em = NOW() WHERE id = :id')
                    ->execute(['s' => $erro['efeito'] === 'template_pausado' ? 'pausado' : 'desativado', 'id' => $template['id']]);
            }
            break;
        case 'opt_out_marketing':
            if (wa_consentimento_atual($empresaId, $contatoId, 'marketing')['concedido']) {
                wa_consentimento_registrar($empresaId, $contatoId, 'marketing', 'revogado', 'sistema', 'Meta informou: cliente desativou mensagens de marketing (' . $erro['codigo'] . ')');
            }
            break;
    }
}

// ================================================================== webhook

/**
 * Grava os avisos de um corpo de webhook (assinatura JÁ conferida). Cada
 * mensagem e cada status vira um evento com chave única: o mesmo aviso
 * reenviado pela Meta é descartado. Devolve os ids NOVOS.
 */
function wa_webhook_registrar(array $corpo): array
{
    if (($corpo['object'] ?? '') !== 'whatsapp_business_account' || !is_array($corpo['entry'] ?? null)) {
        return [];
    }
    $pdo = db();
    $buscaConta = $pdo->prepare('SELECT id, empresa_id FROM whatsapp_contas WHERE phone_number_id = :p');
    $ins = $pdo->prepare('INSERT IGNORE INTO whatsapp_webhook_eventos (empresa_id, conta_id, phone_number_id, tipo, chave_unica, payload, status)
                          VALUES (:e, :c, :p, :t, :k, :pl, :s)');
    $novos = [];
    $guardar = function (?array $conta, ?string $pnid, string $tipo, string $chave, array $dados) use ($ins, $pdo, &$novos) {
        $ins->execute(['e' => $conta['empresa_id'] ?? null, 'c' => $conta['id'] ?? null, 'p' => $pnid, 't' => $tipo, 'k' => mb_substr($chave, 0, 190),
                       'pl' => json_encode($dados, JSON_UNESCAPED_UNICODE), 's' => $conta ? 'pendente' : 'ignorado']);
        if ($ins->rowCount() === 1 && $conta) {
            $novos[] = (int) $pdo->lastInsertId();
        }
    };
    foreach ($corpo['entry'] as $entry) {
        $wabaId = (string) ($entry['id'] ?? '');
        foreach ((array) ($entry['changes'] ?? []) as $ch) {
            $campo = (string) ($ch['field'] ?? '');
            $v = (array) ($ch['value'] ?? []);
            if ($campo === 'messages') {
                $pnid = (string) ($v['metadata']['phone_number_id'] ?? '');
                $buscaConta->execute(['p' => $pnid]);
                $conta = $buscaConta->fetch() ?: null;
                if ($conta) {
                    $pdo->prepare('UPDATE whatsapp_contas SET ultimo_webhook_em = NOW() WHERE id = :id')->execute(['id' => $conta['id']]);
                }
                $perfis = [];
                foreach ((array) ($v['contacts'] ?? []) as $c) {
                    $perfis[(string) ($c['wa_id'] ?? '')] = (string) ($c['profile']['name'] ?? '');
                }
                foreach ((array) ($v['messages'] ?? []) as $m) {
                    if (empty($m['id'])) {
                        continue;
                    }
                    $guardar($conta, $pnid, 'mensagem', 'msg:' . $m['id'], ['message' => $m, 'nome' => $perfis[(string) ($m['from'] ?? '')] ?? null]);
                }
                foreach ((array) ($v['statuses'] ?? []) as $s) {
                    if (empty($s['id']) || empty($s['status'])) {
                        continue;
                    }
                    $guardar($conta, $pnid, 'status', 'st:' . $s['id'] . ':' . $s['status'], ['status' => $s]);
                }
            } elseif ($campo === 'message_template_status_update') {
                $stmt = $pdo->prepare('SELECT id, empresa_id FROM whatsapp_contas WHERE waba_id = :w LIMIT 1');
                $stmt->execute(['w' => $wabaId]);
                $guardar($stmt->fetch() ?: null, null, 'template', 'tpl:' . sha1($wabaId . json_encode($v)), ['template' => $v]);
            } elseif ($campo !== '') {
                $stmt = $pdo->prepare('SELECT id, empresa_id FROM whatsapp_contas WHERE waba_id = :w LIMIT 1');
                $stmt->execute(['w' => $wabaId]);
                $guardar($stmt->fetch() ?: null, null, 'conta', 'conta:' . sha1($wabaId . $campo . json_encode($v)), ['campo' => $campo, 'valor' => $v]);
            }
        }
    }
    return $novos;
}

/** Processa um evento gravado. Devolve o status final. Erro temporário → nova tentativa com espera. */
function wa_processar_evento(int $id): string
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM whatsapp_webhook_eventos WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $ev = $stmt->fetch();
    if (!$ev || !in_array($ev['status'], ['pendente', 'erro'], true) || !$ev['empresa_id']) {
        return $ev['status'] ?? 'inexistente';
    }
    $fim = function (string $status, ?string $erro = null, ?int $esperarMin = null) use ($pdo, $ev) {
        $tent = (int) $ev['tentativas'] + 1;
        $proxima = $status === 'erro' && $tent < WA_EVENTO_MAX_TENTATIVAS ? date('Y-m-d H:i:s', time() + 60 * ($esperarMin ?? [1 => 1, 2 => 5, 3 => 15, 4 => 60][$tent] ?? 180)) : null;
        $pdo->prepare('UPDATE whatsapp_webhook_eventos SET status = :s, erro = :e, tentativas = :t, proxima_tentativa_em = :p, processado_em = IF(:s2 = \'erro\', NULL, NOW()) WHERE id = :id')
            ->execute(['s' => $status, 'e' => $erro !== null ? mb_substr($erro, 0, 500) : null, 't' => $tent, 'p' => $proxima, 's2' => $status, 'id' => $ev['id']]);
        return $status;
    };
    try {
        $dados = json_decode((string) $ev['payload'], true) ?: [];
        $empresaId = (int) $ev['empresa_id'];
        switch ($ev['tipo']) {
            case 'mensagem':
                return $fim(wa_evento_mensagem($empresaId, (int) $ev['conta_id'], (array) ($dados['message'] ?? []), $dados['nome'] ?? null));
            case 'status':
                $r = wa_evento_status($empresaId, (array) ($dados['status'] ?? []), $ev['recebido_em']);
                return $r === 'aguardar' ? $fim('erro', 'Mensagem ainda não registrada; nova tentativa em instantes.', 1) : $fim($r);
            case 'template':
                return $fim(wa_evento_template($empresaId, (array) ($dados['template'] ?? [])));
            default:
                return $fim('processado');
        }
    } catch (Throwable $e) {
        error_log('[whatsapp] evento ' . $id . ': ' . $e->getMessage());
        return $fim('erro', meta_texto_seguro($e->getMessage(), [], 300));
    }
}

/** Avisos pendentes e com nova tentativa vencida (cron). */
function wa_processar_pendentes(int $limite = 200): array
{
    $stmt = db()->prepare("SELECT id FROM whatsapp_webhook_eventos
                           WHERE status = 'pendente' OR (status = 'erro' AND proxima_tentativa_em IS NOT NULL AND proxima_tentativa_em <= NOW())
                           ORDER BY id LIMIT " . max(1, $limite));
    $stmt->execute();
    $r = ['tentados' => 0, 'processados' => 0, 'duplicados' => 0, 'erros' => 0, 'ignorados' => 0];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $r['tentados']++;
        $s = wa_processar_evento((int) $id);
        $chave = ['processado' => 'processados', 'duplicado' => 'duplicados', 'erro' => 'erros', 'ignorado' => 'ignorados'][$s] ?? null;
        if ($chave) {
            $r[$chave]++;
        }
    }
    return $r;
}

/** Converte a mensagem da Meta em [tipo local, texto, payload do botão, id da mídia]. */
function wa_mensagem_de_meta(array $m): array
{
    $tipo = (string) ($m['type'] ?? '');
    switch ($tipo) {
        case 'text':
            return ['texto', (string) ($m['text']['body'] ?? ''), null, null];
        case 'button':   // resposta rápida de template
            return ['botao', (string) ($m['button']['text'] ?? ''), (string) ($m['button']['payload'] ?? $m['button']['text'] ?? ''), null];
        case 'interactive':
            $i = (array) ($m['interactive'] ?? []);
            $r = $i['button_reply'] ?? $i['list_reply'] ?? [];
            return ['interativo', (string) ($r['title'] ?? ''), (string) ($r['id'] ?? ''), null];
        case 'image': case 'document': case 'audio': case 'video': case 'sticker':
            $midia = (array) ($m[$tipo] ?? []);
            $local = ['image' => 'imagem', 'document' => 'documento', 'audio' => 'audio', 'video' => 'video', 'sticker' => 'imagem'][$tipo];
            $rotulo = ['imagem' => '📷 Imagem', 'documento' => '📄 Documento', 'audio' => '🎤 Áudio', 'video' => '🎬 Vídeo'][$local];
            $texto = trim($rotulo . ' ' . ($midia['filename'] ?? '') . (isset($midia['caption']) ? ' — ' . $midia['caption'] : ''));
            return [$local, $texto, null, $midia['id'] ?? null];
        case 'location':
            $l = (array) ($m['location'] ?? []);
            return ['localizacao', '📍 Localização' . (isset($l['name']) ? ': ' . $l['name'] : ''), null, null];
        case 'contacts':
            return ['contato', '👤 Cartão de contato', null, null];
        case 'reaction':
            return ['reacao', (string) ($m['reaction']['emoji'] ?? ''), null, null];
        case 'system':
            return ['sistema', (string) ($m['system']['body'] ?? 'Aviso do WhatsApp'), null, null];
        default:
            return ['outro', 'Mensagem de um tipo que o WhatsApp não permite ler aqui.', null, null];
    }
}

function wa_evento_mensagem(int $empresaId, int $contaId, array $m, ?string $nomePerfil): string
{
    $pdo = db();
    $contato = wa_contato_entrada($empresaId, (string) ($m['from'] ?? ''), $nomePerfil ?: null);
    if (!$contato) {
        return 'ignorado';
    }
    $leadId = $contato['lead_id'] ? (int) $contato['lead_id'] : wa_lead_por_telefone($empresaId, $contato['telefone']);
    if ($leadId && !$contato['lead_id']) {
        $pdo->prepare('UPDATE whatsapp_contatos SET lead_id = :l WHERE id = :id AND lead_id IS NULL')->execute(['l' => $leadId, 'id' => $contato['id']]);
    }
    $conversa = wa_conversa_obter($empresaId, $contaId, (int) $contato['id'], $leadId);
    [$tipo, $texto, $botao, $midia] = wa_mensagem_de_meta($m);
    $ts = isset($m['timestamp']) && ctype_digit((string) $m['timestamp']) ? date('Y-m-d H:i:s', (int) $m['timestamp']) : date('Y-m-d H:i:s');

    $ins = $pdo->prepare("INSERT IGNORE INTO whatsapp_mensagens (empresa_id, conversa_id, direcao, tipo, conteudo, botao_payload, midia_id_meta, wamid, status, criado_em)
                          VALUES (:e, :cv, 'entrada', :t, :c, :b, :mid, :w, 'recebida', :ts)");
    $ins->execute(['e' => $empresaId, 'cv' => $conversa['id'], 't' => $tipo, 'c' => mb_substr($texto, 0, 4096), 'b' => $botao ? mb_substr($botao, 0, 190) : null,
                   'mid' => $midia, 'w' => mb_substr((string) $m['id'], 0, 128), 'ts' => $ts]);
    if ($ins->rowCount() === 0) {
        return 'duplicado';
    }
    $mensagemId = (int) $pdo->lastInsertId();

    // Reação a uma mensagem não abre a janela de 24 h nem conta como não lida.
    if ($tipo !== 'reacao') {
        $pdo->prepare('UPDATE whatsapp_contatos SET ultima_entrada_em = GREATEST(COALESCE(ultima_entrada_em, :ts1), :ts2), atualizado_em = NOW() WHERE id = :id')
            ->execute(['ts1' => $ts, 'ts2' => $ts, 'id' => $contato['id']]);
        $pdo->prepare("UPDATE whatsapp_conversas SET ultima_entrada_em = GREATEST(COALESCE(ultima_entrada_em, :ts1), :ts2),
                              ultima_mensagem_em = GREATEST(COALESCE(ultima_mensagem_em, :ts3), :ts4), nao_lidas = nao_lidas + 1,
                              ultima_previa = :p, status = 'aberta' WHERE id = :id")
            ->execute(['ts1' => $ts, 'ts2' => $ts, 'ts3' => $ts, 'ts4' => $ts, 'p' => mb_substr($texto, 0, 190), 'id' => $conversa['id']]);
    }

    $cfg = wa_config($empresaId);
    $descadastro = in_array($tipo, ['texto', 'botao', 'interativo'], true) && wa_regra_eh_descadastro($texto, $cfg['palavras_descadastro']);
    if ($descadastro) {
        wa_descadastrar($empresaId, (int) $contato['id'], 'Cliente respondeu "' . mb_substr($texto, 0, 60) . '" no WhatsApp');
    }
    if ($leadId) {
        wa_lead_evento($leadId, null, $descadastro
            ? 'Cliente pediu pelo WhatsApp para não receber mais mensagens ("' . mb_substr($texto, 0, 60) . '").'
            : 'Cliente enviou pelo WhatsApp: "' . mb_substr($texto, 0, 300) . '"');
    }
    // Fases seguintes (jornadas, notificação ao corretor) se penduram aqui.
    if (function_exists('jornada_ao_receber_mensagem')) {
        jornada_ao_receber_mensagem($empresaId, (int) $conversa['id'], $mensagemId, $descadastro);
    }
    return 'processado';
}

/**
 * Status de uma mensagem enviada. Só avança (enviada → entregue → lida);
 * aviso fora de ordem não volta o status. "aguardar" = a mensagem ainda não
 * tem o wamid gravado (a Meta foi mais rápida que nós): tenta de novo.
 */
function wa_evento_status(int $empresaId, array $s, string $recebidoEm): string
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT m.*, cv.contato_id, cv.lead_id FROM whatsapp_mensagens m JOIN whatsapp_conversas cv ON cv.id = m.conversa_id
                           WHERE m.wamid = :w AND m.empresa_id = :e');
    $stmt->execute(['w' => (string) $s['id'], 'e' => $empresaId]);
    $msg = $stmt->fetch();
    if (!$msg) {
        // Mensagem enviada fora do WeMart (ex.: aplicativo do celular) ou ainda sendo gravada.
        return strtotime($recebidoEm) > time() - 15 * 60 ? 'aguardar' : 'ignorado';
    }
    $ordem = ['na_fila' => 0, 'enviando' => 1, 'enviada' => 2, 'entregue' => 3, 'lida' => 4];
    $novo = ['sent' => 'enviada', 'delivered' => 'entregue', 'read' => 'lida', 'failed' => 'falhou'][(string) $s['status']] ?? null;
    if (!$novo) {
        return 'ignorado';
    }
    $ts = isset($s['timestamp']) && ctype_digit((string) $s['timestamp']) ? date('Y-m-d H:i:s', (int) $s['timestamp']) : date('Y-m-d H:i:s');
    $sets = [];
    $params = ['id' => $msg['id']];
    $atual = $ordem[$msg['status']] ?? null;
    if ($novo === 'falhou') {
        if ($atual === null || $atual <= 2) {
            $erro = (array) ($s['errors'][0] ?? []);
            $class = wa_api_classificar_erro(400, ['code' => $erro['code'] ?? 0, 'message' => $erro['title'] ?? '', 'error_data' => $erro['error_data'] ?? []]);
            $sets[] = "status = 'falhou'";
            $sets[] = 'erro_codigo = :c';
            $sets[] = 'erro_texto = :t';
            $params += ['c' => mb_substr($class['codigo'], 0, 20), 't' => $class['mensagem']];
            $template = $msg['template_id'] ? wa_template($empresaId, (int) $msg['template_id']) : null;
            wa_aplicar_efeito_erro($empresaId, null, $template, (int) $msg['contato_id'], $class);
            if ($msg['lead_id']) {
                wa_lead_evento((int) $msg['lead_id'], null, 'WhatsApp não entregue: ' . $class['mensagem']);
            }
        }
    } else {
        if ($atual !== null && $ordem[$novo] > $atual) {
            $sets[] = 'status = :s';
            $params['s'] = $novo;
        }
        // Marca os horários mesmo fora de ordem (ex.: "lida" chegou antes de "entregue").
        $sets[] = 'enviada_em = COALESCE(enviada_em, :t1)';
        $params['t1'] = $ts;
        if ($novo === 'entregue' || $novo === 'lida') {
            $sets[] = 'entregue_em = COALESCE(entregue_em, :t2)';
            $params['t2'] = $ts;
        }
        if ($novo === 'lida') {
            $sets[] = 'lida_em = COALESCE(lida_em, :t3)';
            $params['t3'] = $ts;
        }
    }
    if (isset($s['pricing']['billable'])) {
        $sets[] = 'cobravel = :cob';
        $params['cob'] = $s['pricing']['billable'] ? 1 : 0;
    }
    $cat = strtolower((string) ($s['pricing']['category'] ?? ''));
    if (in_array($cat, ['marketing', 'utility', 'authentication', 'service'], true)) {
        $sets[] = 'categoria = :cat';
        $params['cat'] = $cat;
    }
    if ($sets) {
        $pdo->prepare('UPDATE whatsapp_mensagens SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
    }
    return 'processado';
}

function wa_evento_template(int $empresaId, array $v): string
{
    $evento = strtoupper((string) ($v['event'] ?? ''));
    if ($evento === '') {
        return 'ignorado';
    }
    $status = wa_status_template_local($evento);
    $stmt = db()->prepare('UPDATE whatsapp_templates SET status = :s, motivo_rejeicao = :m, atualizado_em = NOW()
                           WHERE empresa_id = :e AND (meta_template_id = :mid OR (nome = :n AND idioma = :i))');
    $stmt->execute(['s' => $status, 'm' => isset($v['reason']) && $v['reason'] !== 'NONE' ? mb_substr((string) $v['reason'], 0, 500) : null, 'e' => $empresaId,
                    'mid' => (string) ($v['message_template_id'] ?? ''), 'n' => (string) ($v['message_template_name'] ?? ''), 'i' => (string) ($v['message_template_language'] ?? '')]);
    return $stmt->rowCount() ? 'processado' : 'ignorado';
}

/** URL pública do webhook do WhatsApp. */
function wa_webhook_url(): string
{
    $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'seu-dominio.com.br'));
    return 'https://' . $host . '/webhook/whatsapp.php';
}

/** Token de verificação do webhook do WhatsApp (próprio, ou o mesmo do Lead Ads). */
function wa_verify_token(): string
{
    if (defined('WA_VERIFY_TOKEN') && WA_VERIFY_TOKEN !== '') {
        return WA_VERIFY_TOKEN;
    }
    return defined('META_VERIFY_TOKEN') ? (string) META_VERIFY_TOKEN : '';
}

function wa_plataforma_configurada(): bool
{
    return defined('META_APP_SECRET') && META_APP_SECRET !== '' && wa_verify_token() !== '';
}

/** Rótulos e cores dos status (telas). */
function wa_status_mensagem(): array
{
    return ['na_fila' => ['Na fila', 'pill-muted'], 'enviando' => ['Enviando', 'pill-muted'], 'enviada' => ['Enviada', 'pill-blue'],
            'entregue' => ['Entregue', 'pill-green'], 'lida' => ['Lida', 'pill-green'], 'falhou' => ['Falhou', 'pill-red'],
            'recebida' => ['Recebida', 'pill-blue'], 'simulada' => ['Simulada (teste)', 'pill-amber'], 'cancelada' => ['Não enviada', 'pill-muted']];
}

function wa_status_evento(): array
{
    return ['pendente' => ['Pendente', 'pill-muted'], 'processado' => ['Processado', 'pill-green'], 'duplicado' => ['Repetido (ignorado)', 'pill-muted'],
            'erro' => ['Erro', 'pill-red'], 'ignorado' => ['Ignorado', 'pill-muted']];
}
