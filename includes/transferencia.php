<?php
/**
 * Transferência de leads (seção 5 do prompt mestre).
 *
 * A visibilidade acompanha a nova estrutura automaticamente: o lead passa
 * a ter o responsável e a equipe do destino, e rbac_leads_scope() filtra
 * por esses campos — o gerente/superintendência anteriores perdem o
 * acesso no mesmo instante (a menos que a nova equipe ainda esteja sob
 * eles). A estrutura anterior fica só com a linha em lead_transferencias,
 * que os Relatórios contam de forma agregada, sem nome nem telefone.
 */
require_once __DIR__ . '/leads.php';
require_once __DIR__ . '/auditoria.php';

/** Responsável → equipe, gerente e superintendência (estrutura comercial atual). */
function estrutura_do_usuario(?int $usuarioId): array
{
    $vazio = ['usuario_id' => $usuarioId, 'equipe_id' => null, 'gerente_id' => null, 'superintendencia_id' => null];
    if (!$usuarioId) {
        return $vazio;
    }
    $stmt = db()->prepare('
        SELECT u.id AS usuario_id, u.equipe_id, equipes.gerente_id, equipes.superintendencia_id
        FROM usuarios u LEFT JOIN equipes ON equipes.id = u.equipe_id
        WHERE u.id = :id');
    $stmt->execute(['id' => $usuarioId]);
    return $stmt->fetch() ?: $vazio;
}

/** Estrutura gravada no lead hoje (pela equipe do lead, não do usuário). */
function estrutura_do_lead(array $lead): array
{
    $estrutura = ['usuario_id' => $lead['responsavel_id'] ? (int) $lead['responsavel_id'] : null, 'equipe_id' => null, 'gerente_id' => null, 'superintendencia_id' => null];
    if (!empty($lead['equipe_id'])) {
        $stmt = db()->prepare('SELECT id, gerente_id, superintendencia_id FROM equipes WHERE id = :id');
        $stmt->execute(['id' => $lead['equipe_id']]);
        if ($eq = $stmt->fetch()) {
            $estrutura['equipe_id'] = (int) $eq['id'];
            $estrutura['gerente_id'] = $eq['gerente_id'] ? (int) $eq['gerente_id'] : null;
            $estrutura['superintendencia_id'] = $eq['superintendencia_id'] ? (int) $eq['superintendencia_id'] : null;
        }
    }
    return $estrutura;
}

/**
 * Transfere um lead para outro responsável (ou, em `mudanca_equipe`, mantém
 * o responsável e só acompanha a nova equipe dele). Grava
 * lead_transferencias, evento no histórico e auditoria; os compromissos
 * ainda pendentes do lead passam para o novo responsável.
 *
 * $motivo: administrativa | inatividade | mudanca_equipe | roleta
 * $executor: usuário que fez a ação (null = rotina automática)
 */
function lead_transferir(array $lead, int $destinoUsuarioId, string $motivo, ?array $executor, ?string $observacao = null): void
{
    $de = estrutura_do_lead($lead);
    $para = estrutura_do_usuario($destinoUsuarioId);
    $empresaId = (int) $lead['empresa_id'];
    $executorId = $executor ? (int) $executor['id'] : null;

    db()->prepare('UPDATE leads SET responsavel_id = :responsavel, equipe_id = :equipe WHERE id = :id AND empresa_id = :empresa_id')
        ->execute(['responsavel' => $destinoUsuarioId, 'equipe' => $para['equipe_id'], 'id' => $lead['id'], 'empresa_id' => $empresaId]);
    // Novo dono recebe o prazo inteiro da recuperação (mudança de equipe não troca o dono).
    if ($motivo !== 'mudanca_equipe') {
        lead_marcar_atribuicao((int) $lead['id']);
    }
    // Lead recuperado por inatividade que agora ganhou dono: completa a
    // estrutura de destino na linha da perda.
    transferencia_completar_recuperacao((int) $lead['id'], $destinoUsuarioId);

    db()->prepare('
        INSERT INTO lead_transferencias
            (empresa_id, lead_id, empreendimento_id, de_usuario_id, de_equipe_id, de_gerente_id, de_superintendencia_id,
             para_usuario_id, para_equipe_id, para_gerente_id, para_superintendencia_id, motivo, observacao, executado_por)
        VALUES
            (:empresa_id, :lead_id, :empreendimento_id, :de_usuario, :de_equipe, :de_gerente, :de_super,
             :para_usuario, :para_equipe, :para_gerente, :para_super, :motivo, :observacao, :executado_por)
    ')->execute([
        'empresa_id' => $empresaId, 'lead_id' => $lead['id'], 'empreendimento_id' => $lead['empreendimento_id'] ?: null,
        'de_usuario' => $de['usuario_id'], 'de_equipe' => $de['equipe_id'], 'de_gerente' => $de['gerente_id'], 'de_super' => $de['superintendencia_id'],
        'para_usuario' => $destinoUsuarioId, 'para_equipe' => $para['equipe_id'], 'para_gerente' => $para['gerente_id'], 'para_super' => $para['superintendencia_id'],
        'motivo' => $motivo, 'observacao' => $observacao, 'executado_por' => $executorId,
    ]);
    $transferenciaId = (int) db()->lastInsertId();
    $forma = ['mudanca_equipe' => 'mudanca_equipe', 'inatividade' => 'redistribuicao', 'roleta' => 'roleta', 'oferta_ativa' => 'oferta_ativa'][$motivo] ?? 'transferencia';
    historico_abrir_atribuicao((int) $lead['id'], $destinoUsuarioId, $forma, ['transferencia_id' => $transferenciaId]);

    // Lead que esperava na fila da roleta e foi atribuído à mão sai da fila.
    db()->prepare('UPDATE roleta_fila SET resolvido_em = NOW() WHERE lead_id = :lead AND resolvido_em IS NULL')
        ->execute(['lead' => $lead['id']]);
    historico_fila_saiu((int) $lead['id'], 'atribuido_manual');

    // Compromissos ainda pendentes seguem o novo responsável.
    db()->prepare("UPDATE agenda_compromissos SET usuario_id = :novo WHERE lead_id = :lead AND status = 'agendado'")
        ->execute(['novo' => $destinoUsuarioId, 'lead' => $lead['id']]);

    $stmt = db()->prepare('SELECT nome FROM usuarios WHERE id = :id');
    $stmt->execute(['id' => $destinoUsuarioId]);
    $nomeDestino = (string) $stmt->fetchColumn();
    $descricao = $motivo === 'mudanca_equipe'
        ? 'Lead acompanhou ' . $nomeDestino . ' na mudança de equipe'
        : 'Lead transferido para ' . $nomeDestino . ($observacao ? ' — ' . $observacao : '');
    lead_registrar_evento((int) $lead['id'], $executorId, 'transferencia', $descricao);

    auditoria_registrar($empresaId, $executorId, 'lead.transferido', 'lead', (int) $lead['id'], [
        'motivo' => $motivo, 'de' => $de, 'para' => $para,
    ]);
}

/**
 * Depois que um usuário muda de equipe, seus leads ABERTOS acompanham-no
 * (motivo mudanca_equipe). Encerrados (contrato assinado, sem interesse,
 * perdido) ficam com a equipe em que foram trabalhados. Retorna quantos
 * leads foram movidos. Chamado por api/equipe_membro.php e
 * api/usuario_salvar.php — os dois lugares que mudam usuarios.equipe_id.
 */
function transferencia_acompanhar_mudanca_equipe(int $empresaId, int $usuarioId, ?array $executor): int
{
    $stmt = db()->prepare("
        SELECT * FROM leads
        WHERE empresa_id = :empresa_id AND responsavel_id = :uid AND excluido_em IS NULL
          AND etapa NOT IN ('sem_interesse', 'contrato_assinado', 'perdido')
          AND NOT (equipe_id <=> (SELECT equipe_id FROM usuarios WHERE id = :uid2))");
    $stmt->execute(['empresa_id' => $empresaId, 'uid' => $usuarioId, 'uid2' => $usuarioId]);
    $leads = $stmt->fetchAll();
    foreach ($leads as $lead) {
        lead_transferir($lead, $usuarioId, 'mudanca_equipe', $executor);
    }
    return count($leads);
}

/**
 * Completa os campos para_* da última perda por inatividade ainda sem
 * destino (o lead ficou na fila de recuperação e acabou de ser atribuído).
 */
function transferencia_completar_recuperacao(int $leadId, int $destinoUsuarioId): void
{
    $para = estrutura_do_usuario($destinoUsuarioId);
    db()->prepare("UPDATE lead_transferencias
        SET para_usuario_id = :u, para_equipe_id = :eq, para_gerente_id = :g, para_superintendencia_id = :s
        WHERE lead_id = :lead AND motivo = 'inatividade' AND para_usuario_id IS NULL
        ORDER BY id DESC LIMIT 1")
        ->execute(['u' => $destinoUsuarioId, 'eq' => $para['equipe_id'], 'g' => $para['gerente_id'], 's' => $para['superintendencia_id'], 'lead' => $leadId]);
}

function transferencia_motivos(): array
{
    return [
        'administrativa' => 'Transferência administrativa',
        'inatividade'    => 'Perda por inatividade',
        'mudanca_equipe' => 'Mudança de equipe do corretor',
        'roleta'         => 'Redistribuição pela roleta',
    ];
}
