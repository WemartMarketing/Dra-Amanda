<?php
/**
 * Jornadas Inteligentes — motor (Fase 4: novo lead, boas-vindas e book).
 *
 * Uma jornada é uma sequência de passos (jornada_passos). Cada lead entra
 * no máximo UMA vez em cada jornada (jornada_execucoes, UNIQUE jornada+lead):
 * trocar de corretor não recomeça nada. Cada passo vira uma tarefa
 * 'jornada_passo' na automacao_fila — o cron (ou o webhook) executa, sem
 * processo rodando o tempo todo.
 *
 * Nenhuma mensagem sai "por fora": os envios passam por wa_enfileirar(),
 * que aplica consentimento, janela de 24 h, template aprovado, descadastro,
 * teto de frequência e horário. Se a regra bloquear, a jornada registra o
 * motivo (log + histórico do lead) e segue a configuração do passo.
 *
 * Tipos de passo implementados nesta fase: enviar_template, enviar_documento
 * (book do empreendimento), esperar, encerrar. Os demais (condição, tarefa,
 * notificação…) chegam nas Fases 5–7 sem mudar a estrutura.
 */
require_once __DIR__ . '/whatsapp.php';
require_once __DIR__ . '/automacao_fila.php';
require_once __DIR__ . '/leads.php';
require_once __DIR__ . '/pendencias.php';

const JORNADA_BOAS_VINDAS = 'Boas-vindas a novo lead';
const JORNADA_FOLLOW_UP = 'Follow-up de lead sem resposta';

function jornada_log(int $empresaId, int $execucaoId, ?string $passo, string $evento, ?string $detalhe = null): void
{
    db()->prepare('INSERT INTO jornada_logs (empresa_id, execucao_id, passo_chave, evento, detalhe) VALUES (:e, :x, :p, :ev, :d)')
        ->execute(['e' => $empresaId, 'x' => $execucaoId, 'p' => $passo, 'ev' => mb_substr($evento, 0, 40), 'd' => $detalhe !== null ? mb_substr($detalhe, 0, 500) : null]);
}

/** Histórico do lead (tipo "jornada"), sem contar como atividade do corretor. */
function jornada_evento_lead(int $leadId, string $descricao): void
{
    db()->prepare("INSERT INTO lead_eventos (lead_id, usuario_id, tipo, descricao) VALUES (:l, NULL, 'jornada', :d)")
        ->execute(['l' => $leadId, 'd' => mb_substr($descricao, 0, 500)]);
}

/**
 * Garante a jornada padrão "Boas-vindas a novo lead" da empresa (criada
 * desligada — o Master liga quando quiser). Devolve o id.
 */
function jornada_garantir_boas_vindas(int $empresaId): int
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM jornadas WHERE empresa_id = :e AND nome = :n LIMIT 1');
    $stmt->execute(['e' => $empresaId, 'n' => JORNADA_BOAS_VINDAS]);
    if ($id = $stmt->fetchColumn()) {
        return (int) $id;
    }
    $pdo->prepare("INSERT INTO jornadas (empresa_id, nome, descricao, gatilho, gatilho_config, status, max_mensagens)
                   VALUES (:e, :n, :d, 'novo_lead', :g, 'rascunho', 3)")
        ->execute(['e' => $empresaId, 'n' => JORNADA_BOAS_VINDAS,
                   'd' => 'Assim que o lead chega, envia a mensagem de boas-vindas do empreendimento oferecendo o book — a qualquer hora, mesmo sem corretor online.',
                   'g' => json_encode(['origens' => ['meta_ads']])]);
    $id = (int) $pdo->lastInsertId();
    $passo = $pdo->prepare('INSERT INTO jornada_passos (jornada_id, chave, ordem, tipo, titulo, config, proximo_chave) VALUES (:j, :c, :o, :t, :ti, :cfg, :p)');
    $passo->execute(['j' => $id, 'c' => 'boas_vindas', 'o' => 1, 't' => 'enviar_template', 'ti' => 'Mensagem de boas-vindas',
                     'cfg' => json_encode(['template' => 'do_empreendimento', 'template_id' => null, 'ignorar_horario' => true, 'se_bloqueado' => 'encerrar']), 'p' => 'fim']);
    $passo->execute(['j' => $id, 'c' => 'fim', 'o' => 2, 't' => 'encerrar', 'ti' => 'Fim', 'cfg' => null, 'p' => null]);
    return $id;
}

/**
 * Garante a jornada padrão de follow-up (seção 7), criada desligada:
 *   2 h úteis sem resposta → lembrete · 24 h → pergunta ao corretor se houve
 *   resposta por outro canal · 48 h sem resposta → nova abordagem · fim.
 * Para sozinha quando o cliente responde ou o lead sai de "Tentando contato /
 * Primeiro contato". A recuperação em 4 dias continua sendo a regra de
 * Recuperação de leads (ver includes/recuperacao.php).
 */
function jornada_garantir_follow_up(int $empresaId): int
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM jornadas WHERE empresa_id = :e AND nome = :n LIMIT 1');
    $stmt->execute(['e' => $empresaId, 'n' => JORNADA_FOLLOW_UP]);
    if ($id = $stmt->fetchColumn()) {
        return (int) $id;
    }
    $pdo->prepare("INSERT INTO jornadas (empresa_id, nome, descricao, gatilho, gatilho_config, status, max_mensagens)
                   VALUES (:e, :n, :d, 'novo_lead', :g, 'rascunho', 2)")
        ->execute(['e' => $empresaId, 'n' => JORNADA_FOLLOW_UP,
                   'd' => 'Acompanha o lead que ainda não respondeu: lembrete, pergunta ao corretor e uma nova abordagem — e para sozinha quando o cliente responde.',
                   'g' => json_encode(['origens' => ['meta_ads'], 'parar_quando_responder' => true, 'somente_categorias' => ['novo_lead', 'primeiro_contato']])]);
    $id = (int) $pdo->lastInsertId();
    $semResposta = ['se' => ['cliente_respondeu', 'corretor_confirmou', 'humano_respondeu']];
    $passos = [
        ['espera_1', 'esperar', 'Esperar 2 horas úteis', ['horas_uteis' => 2], 'checa_1', null],
        ['checa_1', 'condicao', 'Houve resposta?', $semResposta, 'fim', 'lembrete'],
        ['lembrete', 'enviar_template', 'Lembrete', ['template_id' => null, 'ignorar_horario' => false, 'se_bloqueado' => 'seguir', 'se_sem_template' => 'pular'], 'espera_2', null],
        ['espera_2', 'esperar', 'Esperar até completar 24 h', ['horas' => 22], 'confirmar', null],
        ['confirmar', 'confirmar_atendimento', 'Perguntar ao corretor', ['so_se_tentativa' => true], 'espera_3', null],
        ['espera_3', 'esperar', 'Esperar até completar 48 h', ['horas' => 24], 'checa_2', null],
        ['checa_2', 'condicao', 'Houve resposta?', $semResposta, 'fim', 'reengajamento'],
        ['reengajamento', 'enviar_template', 'Nova abordagem', ['template_id' => null, 'ignorar_horario' => false, 'se_bloqueado' => 'seguir', 'se_sem_template' => 'pular'], 'fim', null],
        ['fim', 'encerrar', 'Fim', [], null, null],
    ];
    $ins = $pdo->prepare('INSERT INTO jornada_passos (jornada_id, chave, ordem, tipo, titulo, config, proximo_chave, senao_chave) VALUES (:j, :c, :o, :t, :ti, :cfg, :p, :n)');
    foreach ($passos as $i => [$chave, $tipo, $titulo, $cfg, $prox, $senao]) {
        $ins->execute(['j' => $id, 'c' => $chave, 'o' => $i + 1, 't' => $tipo, 'ti' => $titulo, 'cfg' => $cfg ? json_encode($cfg) : null, 'p' => $prox, 'n' => $senao]);
    }
    return $id;
}

/** Soma minutos ÚTEIS (dentro do horário e dos dias de envio da empresa). */
function jornada_somar_minutos_uteis(DateTimeImmutable $de, int $minutos, string $ini, string $fim, string $dias): DateTimeImmutable
{
    $diasOk = array_map('intval', array_filter(explode(',', str_replace(' ', '', $dias)), 'strlen'));
    if ($ini >= $fim || !$diasOk) {
        return $de->modify("+$minutos minutes");   // sem horário comercial definido: tempo corrido
    }
    $cursor = $de;
    $resta = $minutos;
    for ($i = 0; $i < 60 && $resta > 0; $i++) {
        $dia = $cursor->format('Y-m-d');
        $abre = new DateTimeImmutable("$dia $ini", $cursor->getTimezone());
        $fecha = new DateTimeImmutable("$dia $fim", $cursor->getTimezone());
        if (!in_array((int) $cursor->format('N'), $diasOk, true) || $cursor >= $fecha) {
            $cursor = (new DateTimeImmutable("$dia $ini", $cursor->getTimezone()))->modify('+1 day');
            continue;
        }
        if ($cursor < $abre) {
            $cursor = $abre;
        }
        $livre = intdiv($fecha->getTimestamp() - $cursor->getTimestamp(), 60);
        $usar = min($livre, $resta);
        $cursor = $cursor->modify("+$usar minutes");
        $resta -= $usar;
        if ($resta > 0) {
            $cursor = $abre->modify('+1 day');
        }
    }
    return $cursor;
}

/** Avalia uma regra de condição para a execução. */
function jornada_condicao(int $empresaId, array $x, string $regra): bool
{
    $pdo = db();
    $desde = $x['iniciada_em'];
    $leadId = (int) $x['lead_id'];
    switch ($regra) {
        case 'cliente_respondeu':
            $stmt = $pdo->prepare("SELECT 1 FROM whatsapp_mensagens m JOIN whatsapp_conversas cv ON cv.id = m.conversa_id
                                   WHERE cv.empresa_id = :e AND cv.lead_id = :l AND m.direcao = 'entrada' AND m.tipo <> 'reacao' AND m.criado_em >= :d LIMIT 1");
            break;
        case 'humano_respondeu':
            $stmt = $pdo->prepare("SELECT 1 FROM whatsapp_mensagens m JOIN whatsapp_conversas cv ON cv.id = m.conversa_id
                                   WHERE cv.empresa_id = :e AND cv.lead_id = :l AND m.direcao = 'saida' AND m.enviada_por IS NOT NULL
                                     AND m.status NOT IN ('falhou','cancelada') AND m.criado_em >= :d LIMIT 1");
            break;
        case 'corretor_confirmou':
            $stmt = $pdo->prepare("SELECT 1 FROM corretor_pendencias WHERE empresa_id = :e AND lead_id = :l AND status = 'resolvida' AND resposta = 'respondeu' AND resolvida_em >= :d LIMIT 1");
            break;
        case 'houve_tentativa':
            // Tentativa feita pelo corretor RESPONSÁVEL ATUAL (ligação, clique no WhatsApp, contato
            // registrado) desde que o lead chegou — depois de uma transferência, a tentativa do
            // corretor anterior não gera pergunta para o novo.
            $stmt = $pdo->prepare("SELECT 1 FROM lead_eventos ev JOIN leads l ON l.id = ev.lead_id
                                   WHERE l.empresa_id = :e AND ev.lead_id = :l AND ev.usuario_id = l.responsavel_id AND ev.tipo IN ('ligacao','whatsapp','contato')
                                     AND ev.criado_em >= :d LIMIT 1");
            break;
        case 'tem_responsavel':
            $stmt = $pdo->prepare('SELECT 1 FROM leads WHERE empresa_id = :e AND id = :l AND responsavel_id IS NOT NULL AND :d IS NOT NULL');
            break;
        default:
            return false;
    }
    $stmt->execute(['e' => $empresaId, 'l' => $leadId, 'd' => $desde]);
    return (bool) $stmt->fetchColumn();
}

/**
 * O lead ganhou um (novo) corretor: as jornadas em andamento CONTINUAM de
 * onde estavam (nada de boas-vindas de novo; os limites de mensagens já
 * contam o que foi enviado) — só fica registrado no histórico da execução.
 */
function jornada_lead_atribuido(int $leadId): void
{
    $stmt = db()->prepare("SELECT x.id, x.empresa_id, x.passo_chave, u.nome FROM jornada_execucoes x JOIN leads l ON l.id = x.lead_id
                           LEFT JOIN usuarios u ON u.id = l.responsavel_id
                           WHERE x.lead_id = :l AND x.status IN ('ativa','aguardando','pausada')");
    $stmt->execute(['l' => $leadId]);
    foreach ($stmt->fetchAll() as $x) {
        jornada_log((int) $x['empresa_id'], (int) $x['id'], $x['passo_chave'], 'novo_corretor', 'Lead passou para ' . ($x['nome'] ?: 'outro corretor') . '; a jornada continua de onde estava.');
    }
}

/** O lead foi para a recuperação por inatividade: registra nas jornadas em andamento. */
function jornada_lead_recuperado(int $empresaId, int $leadId, string $texto): void
{
    $stmt = db()->prepare("SELECT id, passo_chave FROM jornada_execucoes WHERE empresa_id = :e AND lead_id = :l AND status IN ('ativa','aguardando','pausada')");
    $stmt->execute(['e' => $empresaId, 'l' => $leadId]);
    foreach ($stmt->fetchAll() as $x) {
        jornada_log($empresaId, (int) $x['id'], $x['passo_chave'], 'recuperacao', $texto);
    }
}

/** Encerra as execuções que param quando há resposta (follow-ups de "sem resposta"). */
function jornada_parar_por_resposta(int $empresaId, int $leadId, string $motivo): int
{
    $stmt = db()->prepare("SELECT x.id, j.gatilho_config FROM jornada_execucoes x JOIN jornadas j ON j.id = x.jornada_id
                           WHERE x.empresa_id = :e AND x.lead_id = :l AND x.status IN ('ativa','aguardando','pausada')");
    $stmt->execute(['e' => $empresaId, 'l' => $leadId]);
    $n = 0;
    foreach ($stmt->fetchAll() as $x) {
        $cfg = json_decode((string) $x['gatilho_config'], true) ?: [];
        if (!empty($cfg['parar_quando_responder'])) {
            jornada_finalizar($empresaId, (int) $x['id'], 'concluida', $motivo);
            $n++;
        }
    }
    return $n;
}

function jornada_carregar(int $empresaId, int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM jornadas WHERE id = :id AND empresa_id = :e');
    $stmt->execute(['id' => $id, 'e' => $empresaId]);
    $j = $stmt->fetch();
    if ($j) {
        $j['gatilho_config'] = json_decode((string) $j['gatilho_config'], true) ?: [];
    }
    return $j ?: null;
}

function jornada_passo(int $jornadaId, ?string $chave): ?array
{
    if ($chave === null) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM jornada_passos WHERE jornada_id = :j AND chave = :c');
    $stmt->execute(['j' => $jornadaId, 'c' => $chave]);
    $p = $stmt->fetch();
    if ($p) {
        $p['config'] = json_decode((string) $p['config'], true) ?: [];
    }
    return $p ?: null;
}

function jornada_primeiro_passo(int $jornadaId): ?string
{
    $stmt = db()->prepare('SELECT chave FROM jornada_passos WHERE jornada_id = :j ORDER BY ordem, id LIMIT 1');
    $stmt->execute(['j' => $jornadaId]);
    return ($c = $stmt->fetchColumn()) !== false ? (string) $c : null;
}

/** Agenda a execução de um passo (chave única: o mesmo passo da mesma execução nunca entra duas vezes). */
function jornada_agendar_passo(int $empresaId, int $execucaoId, string $passo, ?DateTimeInterface $quando = null): void
{
    fila_agendar($empresaId, 'jornada_passo', ['execucao_id' => $execucaoId, 'passo' => $passo], 'jornada:' . $execucaoId . ':' . $passo,
                 $quando, 'jornada_execucao', $execucaoId, 5);
    db()->prepare('UPDATE jornada_execucoes SET passo_chave = :p, proxima_acao_em = :q, atualizada_em = NOW() WHERE id = :id')
        ->execute(['p' => $passo, 'q' => ($quando ?? new DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $execucaoId]);
}

function jornada_finalizar(int $empresaId, int $execucaoId, string $status, string $motivo): void
{
    db()->prepare("UPDATE jornada_execucoes SET status = :s, motivo = :m, finalizada_em = NOW(), proxima_acao_em = NULL, atualizada_em = NOW()
                   WHERE id = :id AND status IN ('ativa','aguardando','pausada')")
        ->execute(['s' => $status, 'm' => mb_substr($motivo, 0, 190), 'id' => $execucaoId]);
    fila_cancelar_referencia('jornada_execucao', $execucaoId, 'Jornada encerrada: ' . $motivo);
    jornada_log($empresaId, $execucaoId, null, $status === 'concluida' ? 'concluiu' : 'cancelou', $motivo);
}

/**
 * Dispara as jornadas ativas de um gatilho para um lead. Devolve os ids das
 * execuções NOVAS (lead que já passou pela jornada não entra de novo).
 * $contexto: origem (meta_ads, manual…).
 */
function jornada_disparar(string $gatilho, int $empresaId, int $leadId, array $contexto = []): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT empreendimento_id, origem FROM leads WHERE id = :l AND empresa_id = :e AND excluido_em IS NULL');
    $stmt->execute(['l' => $leadId, 'e' => $empresaId]);
    $lead = $stmt->fetch();
    if (!$lead) {
        return [];
    }
    $origem = (string) ($contexto['origem'] ?? $lead['origem']);
    $stmt = $pdo->prepare("SELECT id FROM jornadas WHERE empresa_id = :e AND gatilho = :g AND status = 'ativa'
                           AND (empreendimento_id IS NULL OR empreendimento_id = :emp) ORDER BY id");
    $stmt->execute(['e' => $empresaId, 'g' => $gatilho, 'emp' => $lead['empreendimento_id']]);
    $novas = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $jid) {
        $j = jornada_carregar($empresaId, (int) $jid);
        $origens = (array) ($j['gatilho_config']['origens'] ?? []);
        if ($gatilho === 'novo_lead' && $origens && !in_array($origem, $origens, true)) {
            continue;
        }
        if ($gatilho === 'mudanca_etapa' && (int) ($j['gatilho_config']['etapa_id'] ?? 0) !== (int) ($contexto['etapa_id'] ?? -1)) {
            continue;
        }
        $primeiro = jornada_primeiro_passo((int) $jid);
        if ($primeiro === null) {
            continue;
        }
        $ins = $pdo->prepare("INSERT IGNORE INTO jornada_execucoes (empresa_id, jornada_id, lead_id, status, passo_chave) VALUES (:e, :j, :l, 'ativa', :p)");
        $ins->execute(['e' => $empresaId, 'j' => $jid, 'l' => $leadId, 'p' => $primeiro]);
        if ($ins->rowCount() !== 1) {
            continue;   // este lead já passou (ou está passando) por esta jornada
        }
        $execId = (int) $pdo->lastInsertId();
        jornada_log($empresaId, $execId, null, 'iniciou', 'Gatilho: ' . $gatilho . ' (origem ' . $origem . ')');
        jornada_agendar_passo($empresaId, $execId, $primeiro);
        $novas[] = $execId;
    }
    return $novas;
}

/**
 * Gatilhos "lead muda de etapa" e "lead transferido" (Fase 7). Dispara as
 * jornadas ligadas e já executa o primeiro passo (o resto segue pela fila).
 * Cada jornada roda uma vez por lead.
 */
function jornada_evento_gatilho(string $gatilho, int $empresaId, int $leadId, array $contexto = []): array
{
    $novas = jornada_disparar($gatilho, $empresaId, $leadId, $contexto);
    foreach ($novas as $execId) {
        $stmt = db()->prepare('SELECT passo_chave FROM jornada_execucoes WHERE id = :id');
        $stmt->execute(['id' => $execId]);
        fila_executar_chave('jornada:' . $execId . ':' . $stmt->fetchColumn(), ['jornada_passo' => 'jornada_tarefa_passo'] + automacao_tratadores_jornada());
    }
    return $novas;
}

/** Template a usar num passo de envio: o do empreendimento do lead, senão o escolhido no passo. */
function jornada_template_do_passo(int $empresaId, array $passo, ?int $empreendimentoId): ?array
{
    $cfg = $passo['config'];
    if (($cfg['template'] ?? '') === 'do_empreendimento' && $empreendimentoId) {
        $stmt = db()->prepare('SELECT wa_template_boas_vindas_id FROM empreendimentos WHERE id = :id AND empresa_id = :e');
        $stmt->execute(['id' => $empreendimentoId, 'e' => $empresaId]);
        if ($tid = $stmt->fetchColumn()) {
            return wa_template($empresaId, (int) $tid);
        }
    }
    return !empty($cfg['template_id']) ? wa_template($empresaId, (int) $cfg['template_id']) : null;
}

/** Tratador da tarefa 'jornada_passo'. */
function jornada_tarefa_passo(array $tarefa): array
{
    $pdo = db();
    $empresaId = (int) $tarefa['empresa_id'];
    $execId = (int) ($tarefa['payload']['execucao_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT x.*, l.telefone, l.empreendimento_id, l.excluido_em, l.etapa AS lead_etapa
                           FROM jornada_execucoes x JOIN leads l ON l.id = x.lead_id WHERE x.id = :id AND x.empresa_id = :e');
    $stmt->execute(['id' => $execId, 'e' => $empresaId]);
    $x = $stmt->fetch();
    if (!$x || !in_array($x['status'], ['ativa', 'aguardando'], true)) {
        return ['status' => 'ok', 'mensagem' => 'Execução encerrada; nada a fazer.'];
    }
    $jornada = jornada_carregar($empresaId, (int) $x['jornada_id']);
    if (!$jornada || $jornada['status'] !== 'ativa') {
        // Jornada pausada: o lead fica parado neste passo e continua quando ela for religada.
        $pdo->prepare("UPDATE jornada_execucoes SET status = 'pausada', atualizada_em = NOW() WHERE id = :id")->execute(['id' => $execId]);
        return ['status' => 'ok', 'mensagem' => 'Jornada pausada.'];
    }
    // Paradas obrigatórias.
    if ($x['excluido_em'] !== null) {
        jornada_finalizar($empresaId, $execId, 'cancelada', 'Lead excluído');
        return ['status' => 'ok', 'mensagem' => 'Lead excluído.'];
    }
    if (in_array($x['lead_etapa'], ['contrato_assinado', 'perdido', 'sem_interesse'], true)) {
        jornada_finalizar($empresaId, $execId, 'cancelada', 'Lead encerrado no funil');
        return ['status' => 'ok', 'mensagem' => 'Lead encerrado.'];
    }
    $somente = (array) ($jornada['gatilho_config']['somente_categorias'] ?? []);
    if ($somente && !in_array($x['lead_etapa'], $somente, true)) {
        jornada_finalizar($empresaId, $execId, 'concluida', 'Lead avançou no funil');
        return ['status' => 'ok', 'mensagem' => 'Lead avançou.'];
    }
    $chave = (string) ($tarefa['payload']['passo'] ?? $x['passo_chave']);
    $passo = jornada_passo((int) $x['jornada_id'], $chave);
    if (!$passo) {
        jornada_finalizar($empresaId, $execId, 'erro', 'Passo "' . $chave . '" não existe mais');
        return ['status' => 'ok', 'mensagem' => 'Passo inexistente.'];
    }
    $pdo->prepare("UPDATE jornada_execucoes SET status = 'ativa', passo_chave = :p, atualizada_em = NOW() WHERE id = :id")->execute(['p' => $chave, 'id' => $execId]);
    $cfg = $passo['config'];
    $seguir = $passo['proximo_chave'];

    switch ($passo['tipo']) {
        case 'enviar_template':
        case 'enviar_documento':
            if ((int) $x['mensagens_enviadas'] >= (int) $jornada['max_mensagens']) {
                jornada_finalizar($empresaId, $execId, 'concluida', 'Limite de mensagens desta jornada atingido');
                return ['status' => 'ok', 'mensagem' => 'Limite atingido.'];
            }
            $contato = $x['telefone'] ? wa_contato_obter($empresaId, (string) $x['telefone'], (int) $x['lead_id']) : null;
            $r = null;
            if (!$contato) {
                $r = ['ok' => false, 'motivo' => 'Lead sem telefone válido para WhatsApp.'];
            } elseif ($passo['tipo'] === 'enviar_template') {
                $tpl = jornada_template_do_passo($empresaId, $passo, $x['empreendimento_id'] ? (int) $x['empreendimento_id'] : null);
                if (!$tpl && ($cfg['se_sem_template'] ?? '') === 'pular') {
                    jornada_log($empresaId, $execId, $chave, 'pulado', 'Nenhum template definido para este passo.');
                    break;
                }
                $r = $tpl
                    ? wa_enfileirar($empresaId, (int) $contato['id'], ['tipo' => 'template', 'template_id' => (int) $tpl['id'], 'dados' => wa_dados_lead($empresaId, (int) $x['lead_id'])],
                        ['idempotencia' => 'jornada:' . $execId . ':' . $chave, 'jornada_execucao_id' => $execId, 'ignorar_horario' => !empty($cfg['ignorar_horario']), 'lead_id' => (int) $x['lead_id']])
                    : ['ok' => false, 'motivo' => 'Nenhum template de boas-vindas definido para o empreendimento nem na jornada.'];
            } else {
                $r = jornada_enviar_book($empresaId, (int) $x['lead_id'], (int) $contato['id'], 'jornada:' . $execId . ':' . $chave, $execId);
            }
            if (!$r['ok']) {
                $motivo = ($r['motivo'] ?? 'bloqueada') . (!empty($r['erros']) ? ' ' . implode(' ', $r['erros']) : '');
                jornada_log($empresaId, $execId, $chave, 'bloqueado', $motivo);
                jornada_evento_lead((int) $x['lead_id'], 'Jornada "' . $jornada['nome'] . '": mensagem não enviada — ' . $motivo);
                if (($cfg['se_bloqueado'] ?? 'encerrar') === 'encerrar') {
                    jornada_finalizar($empresaId, $execId, 'concluida', 'Envio bloqueado: ' . $motivo);
                    return ['status' => 'ok', 'mensagem' => 'Bloqueado: ' . $motivo];
                }
            } else {
                if (empty($r['duplicada'])) {
                    $pdo->prepare('UPDATE jornada_execucoes SET mensagens_enviadas = mensagens_enviadas + 1, contato_id = :c WHERE id = :id')
                        ->execute(['c' => $contato['id'], 'id' => $execId]);
                }
                jornada_log($empresaId, $execId, $chave, 'enviou', 'Mensagem ' . $r['mensagem_id'] . ($r['agendada_para'] ?? null ? ' agendada para ' . $r['agendada_para'] : ' na fila de envio'));
                // Envia logo (o lead acabou de chegar); se o cron chegar antes, a fila evita envio em dobro.
                fila_executar_chave('wa_enviar:' . $r['mensagem_id'], automacao_tratadores_jornada());
            }
            break;

        case 'esperar':
            $agora = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
            if (!empty($cfg['horas_uteis'])) {
                $wc = wa_config($empresaId);
                $quando = jornada_somar_minutos_uteis($agora, (int) round((float) $cfg['horas_uteis'] * 60), $wc['envio_inicio'], $wc['envio_fim'], $wc['envio_dias']);
                $texto = $cfg['horas_uteis'] . ' hora(s) útil(eis)';
            } else {
                $min = !empty($cfg['horas']) ? (int) round((float) $cfg['horas'] * 60) : max(1, (int) ($cfg['minutos'] ?? 60));
                $quando = $agora->modify("+$min minutes");
                $texto = $min >= 60 ? round($min / 60, 1) . ' h' : $min . ' min';
            }
            jornada_log($empresaId, $execId, $chave, 'esperando', $texto . ' — até ' . $quando->format('d/m H:i'));
            if ($seguir) {
                $pdo->prepare("UPDATE jornada_execucoes SET status = 'aguardando' WHERE id = :id")->execute(['id' => $execId]);
                jornada_agendar_passo($empresaId, $execId, $seguir, $quando);
                return ['status' => 'ok', 'mensagem' => 'Aguardando até ' . $quando->format('d/m H:i')];
            }
            break;

        case 'condicao':
            $regras = (array) ($cfg['se'] ?? []);
            $verdadeira = null;
            foreach ($regras as $regra) {
                if (jornada_condicao($empresaId, $x, (string) $regra)) {
                    $verdadeira = $regra;
                    break;
                }
            }
            jornada_log($empresaId, $execId, $chave, $verdadeira ? 'condicao_sim' : 'condicao_nao', $verdadeira ? 'Sim: ' . $verdadeira : 'Nenhuma das condições: ' . implode(', ', $regras));
            $seguir = $verdadeira ? $passo['proximo_chave'] : $passo['senao_chave'];
            break;

        case 'confirmar_atendimento':
            $stmt = $pdo->prepare('SELECT nome, responsavel_id FROM leads WHERE id = :l');
            $stmt->execute(['l' => $x['lead_id']]);
            $lead = $stmt->fetch();
            if (!$lead['responsavel_id']) {
                jornada_log($empresaId, $execId, $chave, 'pulado', 'Lead ainda sem corretor: ninguém para perguntar.');
            } elseif (jornada_condicao($empresaId, $x, 'cliente_respondeu')) {
                jornada_log($empresaId, $execId, $chave, 'pulado', 'O cliente já respondeu pelo WhatsApp: não é preciso perguntar.');
            } elseif (!empty($cfg['so_se_tentativa']) && !jornada_condicao($empresaId, $x, 'houve_tentativa')) {
                jornada_log($empresaId, $execId, $chave, 'pulado', 'Nenhuma tentativa de contato registrada pelo corretor.');
            } else {
                $pid = pendencia_criar($empresaId, (int) $lead['responsavel_id'], (int) $x['lead_id'], 'confirmar_resposta',
                    'O cliente ' . $lead['nome'] . ' respondeu à sua tentativa de contato?', 'jornada:' . $execId . ':' . $chave, $execId);
                jornada_log($empresaId, $execId, $chave, $pid ? 'perguntou' : 'pulado', $pid ? 'Pergunta enviada à central de pendências do corretor.' : 'Já havia pergunta aberta para este lead.');
            }
            break;

        case 'criar_tarefa':
        case 'notificar_corretor':
            $stmt = $pdo->prepare('SELECT responsavel_id FROM leads WHERE id = :l');
            $stmt->execute(['l' => $x['lead_id']]);
            if ($resp = $stmt->fetchColumn()) {
                pendencia_criar($empresaId, (int) $resp, (int) $x['lead_id'], 'tarefa_jornada', (string) ($cfg['titulo'] ?? $passo['titulo'] ?? 'Tarefa da jornada'),
                                'jornada:' . $execId . ':' . $chave, $execId);
                jornada_log($empresaId, $execId, $chave, 'tarefa', (string) ($cfg['titulo'] ?? $passo['titulo']));
            } else {
                jornada_log($empresaId, $execId, $chave, 'pulado', 'Lead sem corretor.');
            }
            break;

        case 'atualizar_etapa':
            // Movimento feito pela automação: NÃO conta como atendimento do corretor
            // (não mexe no prazo da recuperação) e nunca encerra o lead.
            $et = funil_etapa($empresaId, (int) ($cfg['etapa_id'] ?? 0));
            if (!$et || !(int) $et['ativa'] || in_array($et['categoria'], funil_categorias_encerradas(), true)) {
                jornada_log($empresaId, $execId, $chave, 'pulado', 'Etapa inexistente, inativa ou de encerramento.');
            } else {
                $upd = $pdo->prepare('UPDATE leads SET etapa_id = :et, etapa = :cat WHERE id = :l AND empresa_id = :e AND (etapa_id IS NULL OR etapa_id <> :et2)');
                $upd->execute(['et' => $et['id'], 'cat' => $et['categoria'], 'l' => $x['lead_id'], 'e' => $empresaId, 'et2' => $et['id']]);
                if ($upd->rowCount()) {
                    jornada_evento_lead((int) $x['lead_id'], 'Jornada "' . $jornada['nome'] . '": lead movido para "' . $et['nome'] . '"');
                }
                jornada_log($empresaId, $execId, $chave, 'etapa', $et['nome']);
            }
            break;

        case 'encaminhar_fila':
            require_once __DIR__ . '/roleta.php';
            $res = roleta_distribuir_lead((int) $x['lead_id'], 'reprocessamento', null);
            $txt = ['distribuido' => 'Distribuído para ' . ($res['usuario_nome'] ?? 'um corretor'), 'fila' => 'Na fila de espera: ' . ($res['motivo'] ?? ''),
                    'ja_atribuido' => 'O lead já tinha corretor'][$res['status']] ?? $res['status'];
            jornada_log($empresaId, $execId, $chave, 'roleta', $txt);
            break;

        case 'encerrar':
            jornada_finalizar($empresaId, $execId, 'concluida', (string) ($cfg['motivo'] ?? 'Jornada concluída'));
            return ['status' => 'ok', 'mensagem' => 'Concluída.'];

        default:
            jornada_log($empresaId, $execId, $chave, 'ignorado', 'Tipo de passo ainda não disponível: ' . $passo['tipo']);
    }

    if ($seguir) {
        $proximo = jornada_passo((int) $x['jornada_id'], $seguir);
        if ($proximo && $proximo['tipo'] === 'encerrar') {
            // Último passo é só "fim": encerra já, sem esperar a próxima rodada do cron.
            jornada_finalizar($empresaId, $execId, 'concluida', (string) ($proximo['config']['motivo'] ?? 'Jornada concluída'));
            return ['status' => 'ok', 'mensagem' => 'Concluída.'];
        }
        jornada_agendar_passo($empresaId, $execId, $seguir);
        return ['status' => 'ok', 'mensagem' => 'Próximo passo: ' . $seguir];
    }
    jornada_finalizar($empresaId, $execId, 'concluida', 'Último passo executado');
    return ['status' => 'ok', 'mensagem' => 'Concluída.'];
}

/** Os tratadores sem depender de automacao.php (evita inclusão circular). */
function automacao_tratadores_jornada(): array
{
    return ['wa_enviar' => 'wa_tarefa_enviar'];
}

// ================================================================== book

/** Book (PDF) do empreendimento do lead, ou null. */
function jornada_book_do_lead(int $empresaId, int $leadId): ?array
{
    $stmt = db()->prepare('SELECT emp.id, emp.nome, emp.book_arquivo, emp.book_nome FROM leads l JOIN empreendimentos emp ON emp.id = l.empreendimento_id AND emp.empresa_id = l.empresa_id
                           WHERE l.id = :l AND l.empresa_id = :e');
    $stmt->execute(['l' => $leadId, 'e' => $empresaId]);
    $emp = $stmt->fetch();
    return $emp ?: null;
}

/**
 * Envia o book do empreendimento do lead. Sem book cadastrado, não inventa
 * nada: avisa o cliente que um consultor vai mandar e registra no lead.
 */
function jornada_enviar_book(int $empresaId, int $leadId, int $contatoId, string $chave, ?int $execId = null): array
{
    $emp = jornada_book_do_lead($empresaId, $leadId);
    if ($emp && $emp['book_arquivo'] && wa_arquivo_permitido($emp['book_arquivo'])) {
        $r = wa_enfileirar($empresaId, $contatoId, ['tipo' => 'documento', 'arquivo' => $emp['book_arquivo'], 'mime' => 'application/pdf',
                                                     'nome' => $emp['book_nome'] ?: ('Book ' . $emp['nome'] . '.pdf'), 'legenda' => '📄 Book do ' . $emp['nome']],
                           ['idempotencia' => 'book:' . $chave, 'jornada_execucao_id' => $execId, 'ignorar_horario' => true, 'lead_id' => $leadId, 'resposta_ao_cliente' => true]);
        if ($r['ok']) {
            jornada_evento_lead($leadId, 'Book do ' . $emp['nome'] . ' enviado automaticamente pelo WhatsApp.');
        }
        return $r;
    }
    $nomeEmp = $emp['nome'] ?? null;
    jornada_evento_lead($leadId, 'Cliente pediu o book' . ($nomeEmp ? ' do ' . $nomeEmp : '') . ', mas não há book cadastrado: envie manualmente.');
    return wa_enfileirar($empresaId, $contatoId, ['tipo' => 'texto', 'texto' => 'Recebemos seu pedido! Um consultor vai te enviar o material' . ($nomeEmp ? ' do ' . $nomeEmp : '') . ' em breve. 😊'],
                         ['idempotencia' => 'book_sem_arquivo:' . $chave, 'jornada_execucao_id' => $execId, 'ignorar_horario' => true, 'lead_id' => $leadId, 'resposta_ao_cliente' => true]);
}

// ================================================================== respostas do cliente

/** Ação ligada ao botão que o cliente tocou (pela configuração do template que enviamos por último). */
function jornada_acao_do_botao(int $empresaId, int $conversaId, array $msg): ?string
{
    if ($msg['tipo'] === 'interativo' && $msg['botao_payload'] && isset(wa_acoes_botao()[$msg['botao_payload']])) {
        return $msg['botao_payload'] ?: null;   // botões que nós mesmos montamos usam a ação como id
    }
    if ($msg['tipo'] !== 'botao') {
        return null;
    }
    $texto = (string) ($msg['botao_payload'] ?: $msg['conteudo']);
    $stmt = db()->prepare("SELECT t.botoes FROM whatsapp_mensagens m JOIN whatsapp_templates t ON t.id = m.template_id
                           WHERE m.conversa_id = :c AND m.empresa_id = :e AND m.direcao = 'saida' AND m.template_id IS NOT NULL ORDER BY m.id DESC LIMIT 5");
    $stmt->execute(['c' => $conversaId, 'e' => $empresaId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
        foreach (json_decode((string) $json, true) ?: [] as $b) {
            if (($b['texto'] ?? null) === $texto && !empty($b['acao'])) {
                return $b['acao'];
            }
        }
    }
    return null;
}

/**
 * Gancho chamado por wa_evento_mensagem() para cada mensagem recebida.
 * Botões configurados disparam ações; quando ninguém pode atender ainda
 * (lead na fila da roleta, sem corretor), confirma o recebimento sem
 * informar preços ou condições.
 */
function jornada_ao_receber_mensagem(int $empresaId, int $conversaId, int $mensagemId, bool $descadastro): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT m.*, cv.lead_id, cv.contato_id FROM whatsapp_mensagens m JOIN whatsapp_conversas cv ON cv.id = m.conversa_id WHERE m.id = :id AND m.empresa_id = :e');
    $stmt->execute(['id' => $mensagemId, 'e' => $empresaId]);
    $msg = $stmt->fetch();
    if (!$msg || !$msg['lead_id']) {
        return;
    }
    $leadId = (int) $msg['lead_id'];
    // Execuções em andamento deste lead.
    $stmt = $pdo->prepare("SELECT id FROM jornada_execucoes WHERE empresa_id = :e AND lead_id = :l AND status IN ('ativa','aguardando','pausada')");
    $stmt->execute(['e' => $empresaId, 'l' => $leadId]);
    $ativas = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    if ($descadastro) {
        foreach ($ativas as $x) {
            jornada_finalizar($empresaId, $x, 'cancelada', 'Cliente pediu para não receber mensagens');
        }
        return;
    }
    foreach ($ativas as $x) {
        jornada_log($empresaId, $x, null, 'cliente_respondeu', mb_substr((string) $msg['conteudo'], 0, 200));
    }
    if ($msg['tipo'] === 'reacao') {
        return;
    }
    // O cliente respondeu: os lembretes de "sem resposta" param (seção 9).
    jornada_parar_por_resposta($empresaId, $leadId, 'Cliente respondeu pelo WhatsApp');
    // Atendimento humano em primeiro lugar: avisa o corretor responsável na central de pendências.
    $stmt = $pdo->prepare('SELECT nome, responsavel_id FROM leads WHERE id = :l AND empresa_id = :e');
    $stmt->execute(['l' => $leadId, 'e' => $empresaId]);
    $leadInfo = $stmt->fetch();
    if ($leadInfo && $leadInfo['responsavel_id']) {
        pendencia_criar($empresaId, (int) $leadInfo['responsavel_id'], $leadId, 'responder_whatsapp', $leadInfo['nome'] . ' mandou mensagem no WhatsApp', 'wa:' . $mensagemId);
    }
    $acao = jornada_acao_do_botao($empresaId, $conversaId, $msg);
    $contatoId = (int) $msg['contato_id'];
    $chave = 'msg' . $mensagemId;
    if ($acao === 'pedir_book') {
        $r = jornada_enviar_book($empresaId, $leadId, $contatoId, $chave, $ativas[0] ?? null);
        if ($r['ok']) {
            fila_executar_chave('wa_enviar:' . $r['mensagem_id'], automacao_tratadores_jornada());
        } else {
            jornada_evento_lead($leadId, 'Pedido de book não atendido automaticamente: ' . ($r['motivo'] ?? 'bloqueado'));
        }
        return;
    }
    if ($acao === 'agora_nao') {
        jornada_evento_lead($leadId, 'Cliente respondeu "agora não" à mensagem automática. As mensagens automáticas deste lead foram encerradas.');
        foreach ($ativas as $x) {
            jornada_finalizar($empresaId, $x, 'concluida', 'Cliente respondeu "agora não"');
        }
        return;
    }
    if ($acao === 'falar_consultor') {
        jornada_evento_lead($leadId, 'Cliente pediu para falar com um consultor pelo WhatsApp.');
    }
    // Ninguém atende ainda (lead na fila da roleta): confirma o recebimento, no máximo uma vez a cada 12 h.
    $stmt = $pdo->prepare('SELECT responsavel_id FROM leads WHERE id = :l AND empresa_id = :e');
    $stmt->execute(['l' => $leadId, 'e' => $empresaId]);
    if ($stmt->fetchColumn()) {
        return;
    }
    $texto = trim((string) (wa_config($empresaId)['resposta_sem_corretor'] ?? ''));
    if ($texto === '') {
        return;
    }
    $janela = (int) floor(time() / (12 * 3600));
    $r = wa_enfileirar($empresaId, $contatoId, ['tipo' => 'texto', 'texto' => $texto],
                       ['idempotencia' => 'sem_corretor:' . $conversaId . ':' . $janela, 'ignorar_horario' => true, 'lead_id' => $leadId, 'resposta_ao_cliente' => true]);
    if ($r['ok'] && empty($r['duplicada'])) {
        fila_executar_chave('wa_enviar:' . $r['mensagem_id'], automacao_tratadores_jornada());
        jornada_evento_lead($leadId, 'Resposta automática enviada: nenhum consultor disponível no momento.');
    }
}

// ================================================================== autorização vinda do formulário do Meta

/**
 * O formulário do Meta tem pergunta de autorização e o cliente disse sim?
 * Só respostas afirmativas claras contam ("Sim", "Aceito", "Autorizo"…).
 */
function jornada_consentimento_do_formulario(array $fieldData, ?string $campo): ?string
{
    $campo = mb_strtolower(trim((string) $campo));
    if ($campo === '') {
        return null;
    }
    foreach ($fieldData as $f) {
        if (mb_strtolower(trim((string) ($f['name'] ?? ''))) !== $campo) {
            continue;
        }
        $v = $f['values'] ?? [];
        $resposta = trim(is_array($v) ? implode(', ', array_map('strval', $v)) : (string) $v);
        $norm = wa_texto_normalizado($resposta);
        if (preg_match('/^(SIM|YES|ACEITO|CONCORDO|AUTORIZO|TRUE|1)\b/', $norm) && !preg_match('/\bNAO\b/', $norm)) {
            return $resposta;
        }
        return null;
    }
    return null;
}

/** Estatísticas da jornada (tela de Jornadas). */
function jornada_estatisticas(int $empresaId, int $jornadaId): array
{
    $stmt = db()->prepare("SELECT COUNT(*) AS iniciadas, SUM(status IN ('ativa','aguardando')) AS andamento,
                                  SUM(mensagens_enviadas > 0) AS com_mensagem
                           FROM jornada_execucoes WHERE empresa_id = :e AND jornada_id = :j");
    $stmt->execute(['e' => $empresaId, 'j' => $jornadaId]);
    $s = array_map('intval', $stmt->fetch() ?: []);
    $stmt = db()->prepare("SELECT COUNT(*) FROM jornada_logs lg JOIN jornada_execucoes x ON x.id = lg.execucao_id
                           WHERE x.empresa_id = :e AND x.jornada_id = :j AND lg.evento = 'bloqueado'");
    $stmt->execute(['e' => $empresaId, 'j' => $jornadaId]);
    $s['bloqueadas'] = (int) $stmt->fetchColumn();
    $stmt = db()->prepare("SELECT COUNT(*) FROM whatsapp_mensagens m JOIN jornada_execucoes x ON x.id = m.jornada_execucao_id
                           WHERE x.empresa_id = :e AND x.jornada_id = :j AND m.tipo = 'documento' AND m.status NOT IN ('falhou','cancelada')");
    $stmt->execute(['e' => $empresaId, 'j' => $jornadaId]);
    $s['books'] = (int) $stmt->fetchColumn();
    return $s;
}

/**
 * Ponto único de chegada de lead novo (Meta Ads, cadastro manual…):
 * registra a autorização do formulário, quando existir, e dispara as
 * jornadas de "novo lead". Nunca derruba a criação do lead: qualquer
 * falha aqui só é registrada no log do servidor.
 *
 * $form: linha de meta_formularios (wa_consentimento_campo/texto) ou null.
 */
function jornada_lead_chegou(int $empresaId, int $leadId, string $origem, array $fieldData = [], ?array $form = null): array
{
    try {
        $resposta = $form ? jornada_consentimento_do_formulario($fieldData, $form['wa_consentimento_campo'] ?? null) : null;
        if ($resposta !== null) {
            $stmt = db()->prepare('SELECT telefone FROM leads WHERE id = :l AND empresa_id = :e');
            $stmt->execute(['l' => $leadId, 'e' => $empresaId]);
            $contato = wa_contato_obter($empresaId, (string) $stmt->fetchColumn(), $leadId);
            if ($contato) {
                wa_consentimento_registrar($empresaId, (int) $contato['id'], 'atendimento', 'concedido', 'formulario_meta',
                    'Formulário "' . ($form['nome'] ?? '') . '", pergunta "' . $form['wa_consentimento_campo'] . '": "' . $resposta . '"'
                    . (!empty($form['wa_consentimento_texto']) ? ' — aviso exibido: ' . $form['wa_consentimento_texto'] : ''), null, $leadId);
                jornada_evento_lead($leadId, 'Cliente autorizou contato pelo WhatsApp no formulário do Meta Ads ("' . mb_substr($resposta, 0, 60) . '").');
            }
        }
        return jornada_disparar('novo_lead', $empresaId, $leadId, ['origem' => $origem]);
    } catch (Throwable $e) {
        error_log('[jornadas] lead ' . $leadId . ': ' . $e->getMessage());
        return [];
    }
}
