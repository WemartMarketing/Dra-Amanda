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

const JORNADA_BOAS_VINDAS = 'Boas-vindas a novo lead';

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
        if ($origens && !in_array($origem, $origens, true)) {
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
            $min = max(1, (int) ($cfg['minutos'] ?? 60));
            jornada_log($empresaId, $execId, $chave, 'esperando', $min . ' minuto(s)');
            if ($seguir) {
                $pdo->prepare("UPDATE jornada_execucoes SET status = 'aguardando' WHERE id = :id")->execute(['id' => $execId]);
                jornada_agendar_passo($empresaId, $execId, $seguir, new DateTimeImmutable('+' . $min . ' minutes'));
                return ['status' => 'ok', 'mensagem' => 'Aguardando ' . $min . ' min.'];
            }
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
