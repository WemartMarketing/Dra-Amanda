<?php
/**
 * Recuperação automática por inatividade (seção 11 do prompt mestre).
 *
 * Um lead é PERDIDO pelo corretor quando, ao mesmo tempo:
 *   - a regra da empresa está ativa;
 *   - o lead tem responsável e não foi excluído;
 *   - a categoria da etapa está entre as categorias da regra (nunca as
 *     encerradas: sem interesse, contrato assinado, perdido);
 *   - o último feedback VÁLIDO (leads.ultimo_feedback_em — ver
 *     lead_registrar_feedback()) é mais antigo que `dias_sem_feedback`;
 *   - não há visita nem retorno agendado para o futuro.
 *
 * Na perda (uma transação): linha em lead_transferencias (motivo
 * inatividade, estrutura anterior completa, destino em branco até alguém
 * receber), lead sem responsável/equipe, compromissos pendentes
 * cancelados, evento "recuperacao" no histórico, auditoria e fila da
 * roleta com origem "recuperacao" e o corretor anterior em
 * `evitar_usuario_id`. Se a regra manda redistribuir, a roleta tenta
 * entregar na hora (pulando quem perdeu).
 *
 * Roda pelo cron (cron/rotinas.php) — nunca por requisição de usuário.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/leads.php';
require_once __DIR__ . '/funil.php';
require_once __DIR__ . '/auditoria.php';
require_once __DIR__ . '/transferencia.php';
require_once __DIR__ . '/roleta.php';
require_once __DIR__ . '/rbac.php';

/** Categorias que a regra pode incluir (as encerradas nunca entram). */
function recuperacao_categorias_permitidas(): array
{
    $todas = funil_categorias();
    foreach (funil_categorias_encerradas() as $c) {
        unset($todas[$c]);
    }
    return $todas;
}

/** Regra da empresa (com os padrões se ainda não houver linha). */
function recuperacao_regra(int $empresaId): array
{
    $stmt = db()->prepare('SELECT * FROM recuperacao_regras WHERE empresa_id = :e');
    $stmt->execute(['e' => $empresaId]);
    $r = $stmt->fetch() ?: [
        'empresa_id' => $empresaId, 'ativa' => 1, 'dias_sem_feedback' => 4, 'avisar_dias_antes' => 1,
        'categorias' => 'novo_lead,primeiro_contato,em_atendimento', 'redistribuir_automaticamente' => 1, 'atualizado_em' => null,
    ];
    $permitidas = array_keys(recuperacao_categorias_permitidas());
    $r['lista_categorias'] = array_values(array_intersect($permitidas, array_map('trim', explode(',', (string) $r['categorias']))));
    $r['ativa'] = (int) $r['ativa'];
    $r['dias_sem_feedback'] = max(1, (int) $r['dias_sem_feedback']);
    $r['avisar_dias_antes'] = max(0, min((int) $r['avisar_dias_antes'], $r['dias_sem_feedback'] - 1));
    $r['redistribuir_automaticamente'] = (int) $r['redistribuir_automaticamente'];
    return $r;
}

/**
 * Condições SQL (alias `leads`) de um lead sujeito à regra. $dias = limite
 * de dias sem feedback a testar (dias da regra para a perda; dias − aviso
 * para o alerta). Devolve [sql, params] — sem empresa_id (o chamador põe).
 */
function recuperacao_condicoes(array $regra, int $dias): array
{
    $cats = $regra['lista_categorias'];
    if (!$cats) {
        return ['0 = 1', []];
    }
    $ph = [];
    $params = ['rc_dias' => $dias];
    foreach ($cats as $i => $c) {
        $ph[] = ':rc_cat' . $i;
        $params['rc_cat' . $i] = $c;
    }
    $sql = 'leads.responsavel_id IS NOT NULL AND leads.excluido_em IS NULL
        AND leads.etapa IN (' . implode(',', $ph) . ")
        AND COALESCE(leads.ultimo_feedback_em, leads.ultima_atividade_em, leads.criado_em) < DATE_SUB(NOW(), INTERVAL :rc_dias DAY)
        AND NOT EXISTS (SELECT 1 FROM agenda_compromissos rc_ag WHERE rc_ag.lead_id = leads.id AND rc_ag.status = 'agendado'
                        AND rc_ag.tipo IN ('visita','retorno') AND rc_ag.inicio >= NOW())";
    return [$sql, $params];
}

/**
 * Executa a regra para uma empresa (ou todas). Retorna contadores.
 * Segura contra execuções paralelas: cada lead é retravado (FOR UPDATE)
 * e as condições conferidas de novo dentro da transação.
 */
function recuperacao_executar(?int $empresaId = null, int $limite = 500): array
{
    $total = ['perdidos' => 0, 'redistribuidos' => 0, 'em_fila' => 0];
    $sql = 'SELECT e.id FROM empresas e INNER JOIN recuperacao_regras r ON r.empresa_id = e.id WHERE r.ativa = 1 AND e.ativa = 1';
    $params = [];
    if ($empresaId) {
        $sql .= ' AND e.id = :e';
        $params['e'] = $empresaId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    foreach (array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)) as $eid) {
        $regra = recuperacao_regra($eid);
        [$cond, $p] = recuperacao_condicoes($regra, $regra['dias_sem_feedback']);
        $q = db()->prepare('SELECT leads.id FROM leads WHERE leads.empresa_id = :rc_e AND ' . $cond . ' ORDER BY leads.id LIMIT ' . max(1, $limite));
        $q->execute($p + ['rc_e' => $eid]);
        foreach (array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)) as $leadId) {
            $res = recuperacao_perder_lead($leadId, $regra);
            if ($res === null) {
                continue;
            }
            $total['perdidos']++;
            if ($res === 'distribuido') {
                $total['redistribuidos']++;
            } else {
                $total['em_fila']++;
            }
        }
    }
    return $total;
}

/**
 * Tira o lead do corretor por inatividade. null = não se aplica mais
 * (alguém deu feedback, outra execução já tratou); senão o resultado da
 * redistribuição: 'distribuido' | 'fila'.
 */
function recuperacao_perder_lead(int $leadId, array $regra): ?string
{
    $pdo = db();
    $empresaId = (int) $regra['empresa_id'];
    [$cond, $p] = recuperacao_condicoes($regra, $regra['dias_sem_feedback']);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT leads.* FROM leads WHERE leads.id = :rc_id AND leads.empresa_id = :rc_e AND ' . $cond . ' FOR UPDATE');
        $stmt->execute($p + ['rc_id' => $leadId, 'rc_e' => $empresaId]);
        $lead = $stmt->fetch();
        if (!$lead) {
            $pdo->commit();
            return null;
        }
        $anterior = (int) $lead['responsavel_id'];
        $de = estrutura_do_lead($lead);
        $feedback = $lead['ultimo_feedback_em'] ?: ($lead['ultima_atividade_em'] ?: $lead['criado_em']);
        $dias = max($regra['dias_sem_feedback'], (int) floor((time() - strtotime($feedback)) / 86400));
        $texto = 'Sem feedback válido há ' . $dias . ' dia' . ($dias === 1 ? '' : 's') . ' — enviado para recuperação';

        $upd = $pdo->prepare('UPDATE leads SET responsavel_id = NULL, equipe_id = NULL WHERE id = :id AND responsavel_id = :ant');
        $upd->execute(['id' => $leadId, 'ant' => $anterior]);
        if ($upd->rowCount() !== 1) {
            $pdo->rollBack();
            return null;
        }
        $pdo->prepare('
            INSERT INTO lead_transferencias
                (empresa_id, lead_id, empreendimento_id, de_usuario_id, de_equipe_id, de_gerente_id, de_superintendencia_id,
                 para_usuario_id, para_equipe_id, para_gerente_id, para_superintendencia_id, motivo, observacao, executado_por)
            VALUES (:e, :l, :emp, :du, :deq, :dg, :ds, NULL, NULL, NULL, NULL, \'inatividade\', :obs, NULL)
        ')->execute([
            'e' => $empresaId, 'l' => $leadId, 'emp' => $lead['empreendimento_id'] ?: null,
            'du' => $de['usuario_id'], 'deq' => $de['equipe_id'], 'dg' => $de['gerente_id'], 'ds' => $de['superintendencia_id'],
            'obs' => $texto,
        ]);
        // Compromissos pendentes eram do corretor anterior (os futuros de visita/retorno impediriam a perda).
        $canc = $pdo->prepare("UPDATE agenda_compromissos SET status = 'cancelado', concluido_em = NOW(),
                observacao = LEFT(CONCAT(COALESCE(observacao, ''), IF(observacao IS NULL OR observacao = '', '', ' — '), 'Cancelado: lead enviado para recuperação'), 500)
            WHERE lead_id = :l AND status = 'agendado'");
        $canc->execute(['l' => $leadId]);

        lead_registrar_evento($leadId, null, 'recuperacao', $texto . ($canc->rowCount() ? ' (' . $canc->rowCount() . ' compromisso(s) pendente(s) cancelado(s))' : ''));
        auditoria_registrar($empresaId, null, 'lead.recuperado_inatividade', 'lead', $leadId, ['de' => $de, 'dias_sem_feedback' => $dias]);

        $pdo->prepare("INSERT INTO roleta_fila (empresa_id, roleta_id, lead_id, origem, evitar_usuario_id, motivo, tentativas, entrou_em)
                       VALUES (:e, NULL, :l, 'recuperacao', :ev, 'Recuperado por inatividade', 0, NOW())
                       ON DUPLICATE KEY UPDATE origem = 'recuperacao', evitar_usuario_id = VALUES(evitar_usuario_id),
                           motivo = VALUES(motivo), tentativas = 0, entrou_em = NOW(), resolvido_em = NULL")
            ->execute(['e' => $empresaId, 'l' => $leadId, 'ev' => $anterior]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    if ($regra['redistribuir_automaticamente']) {
        $res = roleta_distribuir_lead($leadId, 'recuperacao', null);
        return $res['status'] === 'distribuido' ? 'distribuido' : 'fila';
    }
    return 'fila';
}

/**
 * Leads do próprio usuário que vão para recuperação em breve (dentro da
 * janela de aviso). Cada item ganha `recuperacao_em` (data/hora limite).
 */
function recuperacao_em_risco(array $usuario, int $limite = 20): array
{
    $empresaId = (int) $usuario['empresa_ativa_id'];
    $regra = recuperacao_regra($empresaId);
    if (!$regra['ativa'] || $regra['avisar_dias_antes'] < 1) {
        return [];
    }
    [$cond, $p] = recuperacao_condicoes($regra, $regra['dias_sem_feedback'] - $regra['avisar_dias_antes']);
    $stmt = db()->prepare('SELECT leads.id, leads.nome, leads.telefone, leads.etapa,
            COALESCE(leads.ultimo_feedback_em, leads.ultima_atividade_em, leads.criado_em) AS feedback_em
        FROM leads WHERE leads.empresa_id = :rc_e AND leads.responsavel_id = :rc_u AND ' . $cond . '
        ORDER BY feedback_em ASC LIMIT ' . max(1, $limite));
    $stmt->execute($p + ['rc_e' => $empresaId, 'rc_u' => (int) $usuario['id']]);
    $itens = $stmt->fetchAll();
    foreach ($itens as &$l) {
        $l['recuperacao_em'] = date('Y-m-d H:i:s', strtotime($l['feedback_em']) + $regra['dias_sem_feedback'] * 86400);
        $l['quando'] = recuperacao_quando($l['recuperacao_em']);
    }
    return $itens;
}

/** "hoje", "amanhã" ou "em N dias" até a data limite. */
function recuperacao_quando(string $dataLimite): string
{
    $dias = (int) floor((strtotime(date('Y-m-d', strtotime($dataLimite))) - strtotime(date('Y-m-d'))) / 86400);
    if ($dias <= 0) {
        return 'hoje';
    }
    return $dias === 1 ? 'amanhã' : 'em ' . $dias . ' dias';
}

/** [lead_id => 'amanhã'|...] dos leads do usuário em risco (para marcar cards). */
function recuperacao_mapa_risco(array $usuario): array
{
    $mapa = [];
    foreach (recuperacao_em_risco($usuario, 500) as $l) {
        $mapa[(int) $l['id']] = $l['quando'];
    }
    return $mapa;
}

/**
 * Perdas por inatividade agregadas pela estrutura ANTERIOR. $where/$params
 * vêm de rbac_transferencias_scope() (alias t) já com os filtros da tela —
 * o recorte do usuário é sempre aplicado. Só números: nada de nome,
 * telefone ou id de lead. $agrupar: corretor | equipe | superintendencia
 */
function recuperacao_perdas_agregadas(string $where, array $params, string $agrupar): array
{
    $colunas = [
        'corretor' => ['t.de_usuario_id', 'LEFT JOIN usuarios x ON x.id = t.de_usuario_id', 'x.nome'],
        'equipe' => ['t.de_equipe_id', 'LEFT JOIN equipes x ON x.id = t.de_equipe_id', 'x.nome'],
        'superintendencia' => ['t.de_superintendencia_id', 'LEFT JOIN superintendencias x ON x.id = t.de_superintendencia_id', 'x.nome'],
    ];
    [$col, $join, $nome] = $colunas[$agrupar] ?? $colunas['corretor'];
    $stmt = db()->prepare("SELECT {$col} AS id, {$nome} AS nome, COUNT(*) AS perdas,
                   SUM(t.para_usuario_id IS NOT NULL) AS redistribuidos
            FROM lead_transferencias t {$join}
            WHERE t.motivo = 'inatividade' AND {$where}
            GROUP BY {$col}, {$nome} ORDER BY perdas DESC, nome LIMIT 50");
    $stmt->execute($params);
    return $stmt->fetchAll();
}
