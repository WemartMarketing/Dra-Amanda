<?php
/**
 * Central de pendências do corretor (seção 6): perguntas e tarefas criadas
 * pelas automações — "o cliente respondeu à sua tentativa de contato?",
 * "responder o cliente no WhatsApp", tarefas de jornada.
 *
 * Sem pop-ups: as pendências ficam numa central (Início, sino do topo e
 * página própria). Cada pendência pertence ao corretor responsável pelo
 * lead NO MOMENTO; se o lead for transferido, ela some da lista do
 * anterior (a consulta exige que ele ainda seja o responsável).
 *
 * Responder "Não respondeu" NUNCA dispara mensagem: só registra. O que
 * acontece depois é decidido pela jornada, com todas as regras de envio.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/leads.php';
require_once __DIR__ . '/funil.php';

function pendencia_tipos(): array
{
    return ['confirmar_resposta' => 'Confirmar resposta do cliente', 'responder_whatsapp' => 'Responder no WhatsApp', 'tarefa_jornada' => 'Tarefa'];
}

/**
 * Cria uma pendência (não duplica: mesma chave_unica, ou já existe uma
 * aberta do mesmo tipo para o mesmo lead). Devolve o id ou null.
 */
function pendencia_criar(int $empresaId, int $usuarioId, int $leadId, string $tipo, string $titulo, ?string $chaveUnica = null, ?int $execucaoId = null): ?int
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id FROM corretor_pendencias WHERE empresa_id = :e AND lead_id = :l AND tipo = :t AND status IN ('aberta','adiada') LIMIT 1");
    $stmt->execute(['e' => $empresaId, 'l' => $leadId, 't' => $tipo]);
    if ($stmt->fetchColumn()) {
        return null;
    }
    $ins = $pdo->prepare('INSERT IGNORE INTO corretor_pendencias (empresa_id, usuario_id, lead_id, tipo, titulo, execucao_id, chave_unica)
                          VALUES (:e, :u, :l, :t, :ti, :x, :c)');
    $ins->execute(['e' => $empresaId, 'u' => $usuarioId, 'l' => $leadId, 't' => $tipo, 'ti' => mb_substr($titulo, 0, 190), 'x' => $execucaoId, 'c' => $chaveUnica]);
    return $ins->rowCount() === 1 ? (int) $pdo->lastInsertId() : null;
}

/** Pendências visíveis agora para o usuário (abertas + adiadas cujo lembrete venceu). */
function pendencias_do_usuario(array $usuario, int $limite = 50): array
{
    $stmt = db()->prepare("SELECT p.*, l.nome AS lead_nome, l.telefone, l.etapa_id, emp.nome AS empreendimento
                           FROM corretor_pendencias p
                           JOIN leads l ON l.id = p.lead_id AND l.empresa_id = p.empresa_id AND l.excluido_em IS NULL AND l.responsavel_id = p.usuario_id
                           LEFT JOIN empreendimentos emp ON emp.id = l.empreendimento_id
                           WHERE p.empresa_id = :e AND p.usuario_id = :u
                             AND (p.status = 'aberta' OR (p.status = 'adiada' AND p.lembrar_em <= NOW()))
                           ORDER BY p.tipo = 'responder_whatsapp' DESC, p.criado_em LIMIT " . max(1, $limite));
    $stmt->execute(['e' => (int) $usuario['empresa_ativa_id'], 'u' => (int) $usuario['id']]);
    return $stmt->fetchAll();
}

function pendencias_contar(array $usuario): int
{
    if (!empty($usuario['eh_superadmin'])) {
        return 0;
    }
    $stmt = db()->prepare("SELECT COUNT(*) FROM corretor_pendencias p
                           JOIN leads l ON l.id = p.lead_id AND l.empresa_id = p.empresa_id AND l.excluido_em IS NULL AND l.responsavel_id = p.usuario_id
                           WHERE p.empresa_id = :e AND p.usuario_id = :u AND (p.status = 'aberta' OR (p.status = 'adiada' AND p.lembrar_em <= NOW()))");
    $stmt->execute(['e' => (int) $usuario['empresa_ativa_id'], 'u' => (int) $usuario['id']]);
    return (int) $stmt->fetchColumn();
}

/** Pendência do próprio usuário (para responder), ou null. */
function pendencia_do_usuario(array $usuario, int $id): ?array
{
    $stmt = db()->prepare("SELECT p.*, l.nome AS lead_nome FROM corretor_pendencias p
                           JOIN leads l ON l.id = p.lead_id AND l.empresa_id = p.empresa_id AND l.excluido_em IS NULL AND l.responsavel_id = p.usuario_id
                           WHERE p.id = :id AND p.empresa_id = :e AND p.usuario_id = :u AND p.status IN ('aberta','adiada')");
    $stmt->execute(['id' => $id, 'e' => (int) $usuario['empresa_ativa_id'], 'u' => (int) $usuario['id']]);
    return $stmt->fetch() ?: null;
}

/**
 * Resposta do corretor. $resposta:
 *   respondeu          o cliente respondeu (por telefone/outro canal): registra o resultado,
 *                      conta como atendimento e encerra os lembretes automáticos;
 *   nao_respondeu      registra a tentativa sem resposta — NÃO envia mensagem;
 *   tentativa          registra uma nova tentativa de contato;
 *   adiar              lembra de novo em $horas.
 * $etapaId (opcional, com "respondeu"): atualiza a etapa do Kanban.
 */
function pendencia_responder(array $usuario, int $id, string $resposta, string $observacao = '', ?int $etapaId = null, int $horas = 2): array
{
    $p = pendencia_do_usuario($usuario, $id);
    if (!$p) {
        return ['ok' => false, 'erro' => 'Pendência não encontrada ou já resolvida.'];
    }
    $pdo = db();
    $uid = (int) $usuario['id'];
    $leadId = (int) $p['lead_id'];
    $obs = trim(mb_substr($observacao, 0, 400));
    if ($resposta === 'adiar') {
        $pdo->prepare("UPDATE corretor_pendencias SET status = 'adiada', lembrar_em = DATE_ADD(NOW(), INTERVAL :h HOUR) WHERE id = :id")
            ->execute(['h' => max(1, min(48, $horas)), 'id' => $id]);
        return ['ok' => true, 'mensagem' => 'Combinado: lembramos de novo em ' . max(1, min(48, $horas)) . ' h.'];
    }
    if ($resposta === 'respondeu') {
        if (mb_strlen($obs) < 3) {
            return ['ok' => false, 'erro' => 'Conte em poucas palavras o que o cliente respondeu.'];
        }
        lead_registrar_evento($leadId, $uid, 'contato', 'Cliente respondeu (confirmado pelo corretor): ' . $obs);
        lead_registrar_feedback($leadId);
        if ($etapaId) {
            $stmt = $pdo->prepare('SELECT * FROM leads WHERE id = :l');
            $stmt->execute(['l' => $leadId]);
            if (!lead_mudar_etapa($stmt->fetch(), $etapaId, $usuario)) {
                return ['ok' => false, 'erro' => 'Etapa inválida.'];
            }
        }
        $msg = 'Registrado. Os lembretes automáticos deste cliente foram encerrados.';
    } elseif ($resposta === 'nao_respondeu') {
        lead_registrar_evento($leadId, $uid, 'contato', 'Tentativa de contato sem resposta (confirmado pelo corretor)' . ($obs !== '' ? ': ' . $obs : '.'));
        lead_registrar_feedback($leadId);
        $msg = 'Registrado. Nenhuma mensagem é enviada por isso; a sequência automática segue as regras dela.';
    } elseif ($resposta === 'tentativa') {
        lead_registrar_evento($leadId, $uid, 'ligacao', 'Nova tentativa de contato registrada' . ($obs !== '' ? ': ' . $obs : '.'));
        lead_registrar_feedback($leadId);
        $msg = 'Tentativa registrada.';
    } elseif ($resposta === 'resolvida') {
        $msg = 'Pendência concluída.';
    } else {
        return ['ok' => false, 'erro' => 'Resposta inválida.'];
    }
    $pdo->prepare("UPDATE corretor_pendencias SET status = 'resolvida', resposta = :r, observacao = :o, resolvida_em = NOW(), resolvida_por = :u WHERE id = :id")
        ->execute(['r' => $resposta, 'o' => $obs !== '' ? $obs : null, 'u' => $uid, 'id' => $id]);
    if ($resposta === 'respondeu' && function_exists('jornada_parar_por_resposta')) {
        jornada_parar_por_resposta((int) $p['empresa_id'], $leadId, 'Corretor confirmou que o cliente respondeu');
    }
    return ['ok' => true, 'mensagem' => $msg];
}

/** Resolve sozinho as pendências "responder no WhatsApp" quando alguém responde o cliente. */
function pendencias_resolver_whatsapp(int $empresaId, int $leadId, int $usuarioId): void
{
    db()->prepare("UPDATE corretor_pendencias SET status = 'resolvida', resposta = 'respondido_whatsapp', resolvida_em = NOW(), resolvida_por = :u
                   WHERE empresa_id = :e AND lead_id = :l AND tipo = 'responder_whatsapp' AND status IN ('aberta','adiada')")
        ->execute(['u' => $usuarioId, 'e' => $empresaId, 'l' => $leadId]);
}

/**
 * O lead mudou de dono (transferência, roleta, redistribuição da
 * recuperação). As atividades pendentes acompanham o novo corretor:
 *   - "Responder no WhatsApp" e tarefas passam para ele;
 *   - "O cliente respondeu à sua tentativa?" era sobre a tentativa do
 *     corretor ANTERIOR — é cancelada (o novo não tem como responder);
 *   - se a última mensagem da conversa é do cliente e ninguém respondeu,
 *     o novo corretor recebe "Responder no WhatsApp".
 * Sem responsável (lead na fila da recuperação) nada muda: as pendências
 * ficam guardadas até alguém receber o lead. Devolve quantas mudaram.
 */
function pendencias_seguir_responsavel(int $leadId): int
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, empresa_id, nome, responsavel_id FROM leads WHERE id = :l AND excluido_em IS NULL');
    $stmt->execute(['l' => $leadId]);
    $lead = $stmt->fetch();
    if (!$lead || !$lead['responsavel_id']) {
        return 0;
    }
    $empresaId = (int) $lead['empresa_id'];
    $novo = (int) $lead['responsavel_id'];
    $canc = $pdo->prepare("UPDATE corretor_pendencias SET status = 'cancelada', resposta = 'transferido', resolvida_em = NOW()
                           WHERE empresa_id = :e AND lead_id = :l AND tipo = 'confirmar_resposta' AND usuario_id <> :u AND status IN ('aberta','adiada')");
    $canc->execute(['e' => $empresaId, 'l' => $leadId, 'u' => $novo]);
    $mov = $pdo->prepare("UPDATE corretor_pendencias SET usuario_id = :u, status = 'aberta', lembrar_em = NULL
                          WHERE empresa_id = :e AND lead_id = :l AND tipo <> 'confirmar_resposta' AND usuario_id <> :u2 AND status IN ('aberta','adiada')");
    $mov->execute(['u' => $novo, 'u2' => $novo, 'e' => $empresaId, 'l' => $leadId]);
    $total = $canc->rowCount() + $mov->rowCount();

    // Cliente esperando resposta no WhatsApp (última mensagem é dele).
    $stmt = $pdo->prepare('SELECT id FROM whatsapp_conversas WHERE empresa_id = :e AND lead_id = :l AND ultima_entrada_em IS NOT NULL
                           AND (ultima_saida_em IS NULL OR ultima_entrada_em > ultima_saida_em) ORDER BY ultima_entrada_em DESC LIMIT 1');
    $stmt->execute(['e' => $empresaId, 'l' => $leadId]);
    if ($stmt->fetchColumn() && pendencia_criar($empresaId, $novo, $leadId, 'responder_whatsapp', $lead['nome'] . ' está esperando resposta no WhatsApp')) {
        $total++;
    }
    return $total;
}
