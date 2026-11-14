<?php
/**
 * Retroalimentação do histórico estruturado (migração 013) a partir de
 * FONTES REGISTRADAS — nunca por suposição. Tudo que é criado aqui fica com
 * reconstruido = 1.
 *
 * Fontes usadas:
 *   atribuições   roleta_distribuicoes, lead_transferencias (para_usuario_id),
 *                 auditoria "lead.criado" com responsável, e o dono atual
 *                 (leads.atribuido_em) quando nenhuma outra fonte o explica;
 *   1º atendimento  lead_eventos (contato, mudança de etapa, visita) do próprio
 *                 corretor, pendências resolvidas por ele, visitas/retornos
 *                 concluídos dele, WhatsApp real enviado por ele;
 *   contato efetivo  WhatsApp recebido do cliente, pendência "respondeu",
 *                 auditoria de etapa (usuário → primeiro contato ou além),
 *                 visita realizada;
 *   etapas        auditoria "lead.etapa_alterada";
 *   fila          roleta_fila (só a última passagem de cada lead).
 *
 * Idempotente: um lead que já tem linhas reconstruídas não é refeito, e só
 * o que aconteceu ANTES da primeira linha gravada ao vivo é reconstruído.
 *
 * Estrutura (equipe/gerente/superintendência) na data: a da transferência
 * quando houver (gravada na hora); senão a anotada como "de" na
 * transferência seguinte do mesmo corretor; senão, só para a atribuição
 * ainda em aberto, a equipe atual do lead. Sem fonte → fica vazia.
 */

require_once __DIR__ . '/../historico.php';

/** Retorna contagens: leads, atribuicoes, etapas, fila, contato_efetivo_lead. */
function retroalimentar_historico(?int $empresaId = null, bool $simular = false): array
{
    if (!historico_instalado()) {
        throw new RuntimeException('A migração 013 ainda não foi aplicada.');
    }
    $pdo = db();
    $rel = ['leads' => 0, 'atribuicoes' => 0, 'etapas' => 0, 'fila' => 0, 'contato_efetivo_lead' => 0];
    $sql = 'SELECT id, empresa_id, responsavel_id, equipe_id, atribuido_em, criado_em, excluido_em, oferta_lista_id, empreendimento_id FROM leads';
    $params = [];
    if ($empresaId) {
        $sql .= ' WHERE empresa_id = :e';
        $params['e'] = $empresaId;
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY id');
    $stmt->execute($params);
    $leads = $stmt->fetchAll();

    $pdo->beginTransaction();
    try {
        foreach ($leads as $lead) {
            $mudou = false;
            $n = retro_atribuicoes($lead);
            $rel['atribuicoes'] += $n;
            $mudou = $mudou || $n > 0;
            $n = retro_etapas($lead);
            $rel['etapas'] += $n;
            $mudou = $mudou || $n > 0;
            $n = retro_fila($lead);
            $rel['fila'] += $n;
            $mudou = $mudou || $n > 0;
            if (retro_contato_efetivo_lead($lead)) {
                $rel['contato_efetivo_lead']++;
                $mudou = true;
            }
            $rel['leads'] += $mudou ? 1 : 0;
        }
        $simular ? $pdo->rollBack() : $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return $rel;
}

function retro_atribuicoes(array $lead): int
{
    $pdo = db();
    $leadId = (int) $lead['id'];
    $stmt = $pdo->prepare('SELECT SUM(reconstruido = 1) AS rec, MIN(CASE WHEN reconstruido = 0 THEN atribuido_em END) AS corte,
                                  MIN(CASE WHEN reconstruido = 0 THEN forma END) AS forma_corte
                           FROM lead_atribuicoes WHERE lead_id = :l');
    $stmt->execute(['l' => $leadId]);
    $st = $stmt->fetch();
    if ((int) $st['rec'] > 0) {
        return 0;
    }
    $corte = $st['corte'] ?: '9999-12-31 23:59:59';

    // ---- eventos de entrada e de saída, em ordem
    $ev = [];
    $q = $pdo->prepare('SELECT distribuido_em AS em, usuario_id, origem, roleta_id FROM roleta_distribuicoes WHERE lead_id = :l AND distribuido_em < :c');
    $q->execute(['l' => $leadId, 'c' => $corte]);
    foreach ($q->fetchAll() as $r) {
        $ev[] = ['em' => $r['em'], 'tipo' => 'entrada', 'usuario_id' => (int) $r['usuario_id'],
                 'forma' => $r['origem'] === 'recuperacao' ? 'redistribuicao' : 'roleta', 'roleta_id' => (int) $r['roleta_id']];
    }
    $q = $pdo->prepare('SELECT * FROM lead_transferencias WHERE lead_id = :l AND criado_em < :c');
    $q->execute(['l' => $leadId, 'c' => $corte]);
    $transf = $q->fetchAll();
    foreach ($transf as $t) {
        if ($t['para_usuario_id']) {
            $forma = ['mudanca_equipe' => 'mudanca_equipe', 'inatividade' => 'redistribuicao', 'roleta' => 'roleta', 'oferta_ativa' => 'oferta_ativa'][$t['motivo']] ?? 'transferencia';
            $ev[] = ['em' => $t['criado_em'], 'tipo' => 'entrada', 'usuario_id' => (int) $t['para_usuario_id'], 'forma' => $forma,
                     'transferencia_id' => (int) $t['id'],
                     'estrutura' => ['equipe_id' => $t['para_equipe_id'], 'gerente_id' => $t['para_gerente_id'], 'superintendencia_id' => $t['para_superintendencia_id']]];
        } else {
            $ev[] = ['em' => $t['criado_em'], 'tipo' => 'saida', 'motivo' => $t['motivo'] === 'inatividade' ? 'inatividade' : 'transferencia'];
        }
    }
    $q = $pdo->prepare("SELECT criado_em, detalhes_json FROM auditoria WHERE acao = 'lead.criado' AND entidade = 'lead' AND entidade_id = :l AND criado_em < :c");
    $q->execute(['l' => $leadId, 'c' => $corte]);
    foreach ($q->fetchAll() as $a) {
        $d = json_decode((string) $a['detalhes_json'], true) ?: [];
        if (!empty($d['responsavel_id'])) {
            $ev[] = ['em' => $a['criado_em'], 'tipo' => 'entrada', 'usuario_id' => (int) $d['responsavel_id'], 'forma' => 'cadastro'];
        }
    }
    if ($lead['excluido_em'] && $lead['excluido_em'] < $corte) {
        $ev[] = ['em' => $lead['excluido_em'], 'tipo' => 'saida', 'motivo' => 'excluido'];
    }
    // Ordem: data; na mesma data, saída antes de entrada (perda → redistribuição).
    usort($ev, fn ($a, $b) => [$a['em'], $a['tipo'] === 'entrada' ? 1 : 0] <=> [$b['em'], $b['tipo'] === 'entrada' ? 1 : 0]);

    // Dono atual sem nenhuma fonte que o explique (ou que chegou depois da última fonte).
    $ultimaEntrada = null;
    foreach ($ev as $e) {
        if ($e['tipo'] === 'entrada') {
            $ultimaEntrada = $e;
        }
    }
    if (!$st['corte'] && $lead['responsavel_id'] && !$lead['excluido_em']) {
        $quando = $lead['atribuido_em'] ?: $lead['criado_em'];
        if (!$ultimaEntrada || ((int) $ultimaEntrada['usuario_id'] !== (int) $lead['responsavel_id'] && $quando > $ultimaEntrada['em'])) {
            $ev[] = ['em' => $quando, 'tipo' => 'entrada', 'usuario_id' => (int) $lead['responsavel_id'],
                     'forma' => $lead['oferta_lista_id'] ? 'oferta_ativa' : 'desconhecida'];
        }
    }

    // ---- monta as atribuições (deduplica a mesma entrada repetida em até 5 s por fontes diferentes)
    $atr = [];
    foreach ($ev as $e) {
        $aberta = $atr ? count($atr) - 1 : null;
        if ($e['tipo'] === 'saida') {
            if ($aberta !== null && $atr[$aberta]['encerrada_em'] === null) {
                $atr[$aberta]['encerrada_em'] = $e['em'];
                $atr[$aberta]['motivo'] = $e['motivo'];
            }
            continue;
        }
        if ($aberta !== null && $atr[$aberta]['encerrada_em'] === null && $atr[$aberta]['usuario_id'] === $e['usuario_id']
            && abs(strtotime($e['em']) - strtotime($atr[$aberta]['atribuido_em'])) <= 5) {
            $atr[$aberta] = array_merge($atr[$aberta], array_filter(['transferencia_id' => $e['transferencia_id'] ?? null, 'roleta_id' => $e['roleta_id'] ?? null,
                'estrutura' => $e['estrutura'] ?? null]));
            continue;
        }
        if ($aberta !== null && $atr[$aberta]['encerrada_em'] === null) {
            $atr[$aberta]['encerrada_em'] = $e['em'];
            $atr[$aberta]['motivo'] = $e['forma'] === 'mudanca_equipe' ? 'mudanca_equipe' : 'transferencia';
        }
        $atr[] = ['usuario_id' => $e['usuario_id'], 'forma' => $e['forma'], 'atribuido_em' => $e['em'], 'encerrada_em' => null, 'motivo' => null,
                  'roleta_id' => $e['roleta_id'] ?? null, 'transferencia_id' => $e['transferencia_id'] ?? null, 'estrutura' => $e['estrutura'] ?? null];
    }
    if (!$atr) {
        return 0;
    }
    // A última reconstruída termina onde começa a primeira gravada ao vivo.
    $ult = count($atr) - 1;
    if ($st['corte'] && $atr[$ult]['encerrada_em'] === null) {
        $atr[$ult]['encerrada_em'] = $st['corte'];
        $atr[$ult]['motivo'] = $st['forma_corte'] === 'mudanca_equipe' ? 'mudanca_equipe' : 'transferencia';
    }

    $ins = $pdo->prepare('INSERT INTO lead_atribuicoes (empresa_id, lead_id, usuario_id, equipe_id, gerente_id, superintendencia_id, empreendimento_id,
            forma, roleta_id, transferencia_id, atribuido_em, primeiro_atendimento_em, primeiro_contato_efetivo_em, contato_efetivo_via,
            encerrada_em, motivo_encerramento, reconstruido)
        VALUES (:e, :l, :u, :eq, :g, :s, :emp, :f, :r, :t, :em, :pa, :pc, :via, :enc, :mot, 1)');
    foreach ($atr as $i => $a) {
        $est = $a['estrutura'];
        if (!$est) {
            foreach ($transf as $t) { // "de" da transferência seguinte que tirou o lead deste corretor
                if ((int) $t['de_usuario_id'] === $a['usuario_id'] && $t['criado_em'] >= $a['atribuido_em']
                    && ($a['encerrada_em'] === null || $t['criado_em'] <= $a['encerrada_em'])) {
                    $est = ['equipe_id' => $t['de_equipe_id'], 'gerente_id' => $t['de_gerente_id'], 'superintendencia_id' => $t['de_superintendencia_id']];
                    break;
                }
            }
        }
        if (!$est && $a['encerrada_em'] === null && (int) $lead['responsavel_id'] === $a['usuario_id']) {
            $est = estrutura_do_lead($lead);
        }
        [$pa, $pc, $via] = retro_primeiros($leadId, $a['usuario_id'], $a['atribuido_em'], $a['encerrada_em'] ?? $corte);
        $ins->execute([
            'e' => $lead['empresa_id'], 'l' => $leadId, 'u' => $a['usuario_id'],
            'eq' => $est['equipe_id'] ?? null, 'g' => $est['gerente_id'] ?? null, 's' => $est['superintendencia_id'] ?? null,
            'emp' => $lead['empreendimento_id'] ?: null, 'f' => $a['forma'], 'r' => $a['roleta_id'] ?: null, 't' => $a['transferencia_id'] ?: null,
            'em' => $a['atribuido_em'], 'pa' => $pa, 'pc' => $pc, 'via' => $via, 'enc' => $a['encerrada_em'], 'mot' => $a['motivo'],
        ]);
    }
    // Primeira linha ao vivo foi só a mudança de equipe do mesmo corretor: herda o que ele já tinha feito.
    if ($st['corte'] && $st['forma_corte'] === 'mudanca_equipe') {
        $pdo->prepare("UPDATE lead_atribuicoes v JOIN lead_atribuicoes r ON r.lead_id = v.lead_id AND r.reconstruido = 1 AND r.usuario_id = v.usuario_id AND r.encerrada_em = v.atribuido_em
                       SET v.primeiro_atendimento_em = COALESCE(v.primeiro_atendimento_em, r.primeiro_atendimento_em),
                           v.primeiro_contato_efetivo_em = COALESCE(v.primeiro_contato_efetivo_em, r.primeiro_contato_efetivo_em),
                           v.contato_efetivo_via = COALESCE(v.contato_efetivo_via, r.contato_efetivo_via)
                       WHERE v.lead_id = :l AND v.reconstruido = 0 AND v.forma = 'mudanca_equipe' AND v.atribuido_em = :c")
            ->execute(['l' => $leadId, 'c' => $st['corte']]);
    }
    return count($atr);
}

/** [primeiro_atendimento, primeiro_contato_efetivo, via] do corretor no intervalo [ini, fim). */
function retro_primeiros(int $leadId, int $usuarioId, string $ini, string $fim): array
{
    $p = ['l' => $leadId, 'u' => $usuarioId, 'i' => $ini, 'f' => $fim];
    $min = function (string $sql) use ($p) {
        $stmt = db()->prepare($sql);
        $stmt->execute($p);
        $v = $stmt->fetchColumn();
        return $v ?: null;
    };
    $categorias = "'" . implode("','", historico_categorias_contato()) . "'";
    $atuacao = array_filter([
        $min("SELECT MIN(criado_em) FROM lead_eventos WHERE lead_id = :l AND usuario_id = :u AND tipo IN ('contato','mudanca_etapa','visita') AND criado_em >= :i AND criado_em < :f"),
        $min("SELECT MIN(resolvida_em) FROM corretor_pendencias WHERE lead_id = :l AND resolvida_por = :u AND resposta IN ('respondeu','nao_respondeu','tentativa') AND resolvida_em >= :i AND resolvida_em < :f"),
        $min("SELECT MIN(concluido_em) FROM agenda_compromissos WHERE lead_id = :l AND usuario_id = :u AND status IN ('realizado','cliente_ausente') AND concluido_em >= :i AND concluido_em < :f"),
        $min("SELECT MIN(m.criado_em) FROM whatsapp_mensagens m JOIN whatsapp_conversas c ON c.id = m.conversa_id
              WHERE c.lead_id = :l AND m.direcao = 'saida' AND m.enviada_por = :u AND m.status IN ('enviada','entregue','lida') AND m.criado_em >= :i AND m.criado_em < :f"),
    ]);
    $contato = array_filter([
        'whatsapp' => $min("SELECT MIN(m.criado_em) FROM whatsapp_mensagens m JOIN whatsapp_conversas c ON c.id = m.conversa_id
              WHERE c.lead_id = :l AND m.direcao = 'entrada' AND m.tipo NOT IN ('reacao','sistema') AND m.criado_em >= :i AND m.criado_em < :f AND :u > 0"),
        'pendencia' => $min("SELECT MIN(resolvida_em) FROM corretor_pendencias WHERE lead_id = :l AND resolvida_por = :u AND resposta = 'respondeu' AND resolvida_em >= :i AND resolvida_em < :f"),
        'etapa' => $min("SELECT MIN(criado_em) FROM auditoria WHERE acao = 'lead.etapa_alterada' AND entidade = 'lead' AND entidade_id = :l AND usuario_id = :u
              AND JSON_UNQUOTE(JSON_EXTRACT(detalhes_json, '$.para')) IN ($categorias) AND criado_em >= :i AND criado_em < :f"),
        'visita' => $min("SELECT MIN(concluido_em) FROM agenda_compromissos WHERE lead_id = :l AND usuario_id = :u AND tipo = 'visita' AND status = 'realizado' AND concluido_em >= :i AND concluido_em < :f"),
    ]);
    $pc = $contato ? min($contato) : null;
    $via = $pc ? array_search($pc, $contato, true) : null;
    if ($pc && $via !== 'whatsapp') {
        $atuacao[] = $pc;
    }
    return [$atuacao ? min($atuacao) : null, $pc, $via ?: null];
}

function retro_etapas(array $lead): int
{
    $pdo = db();
    $leadId = (int) $lead['id'];
    $stmt = $pdo->prepare('SELECT SUM(reconstruido = 1) AS rec, MIN(CASE WHEN reconstruido = 0 THEN ocorrido_em END) AS corte FROM lead_etapa_historico WHERE lead_id = :l');
    $stmt->execute(['l' => $leadId]);
    $st = $stmt->fetch();
    if ((int) $st['rec'] > 0) {
        return 0;
    }
    $q = $pdo->prepare("SELECT usuario_id, detalhes_json, criado_em FROM auditoria WHERE acao = 'lead.etapa_alterada' AND entidade = 'lead' AND entidade_id = :l
                        AND criado_em < :c ORDER BY criado_em, id");
    $q->execute(['l' => $leadId, 'c' => $st['corte'] ?: '9999-12-31 23:59:59']);
    $resp = $pdo->prepare('SELECT usuario_id FROM lead_atribuicoes WHERE lead_id = :l AND atribuido_em <= :t1 AND (encerrada_em IS NULL OR encerrada_em > :t2)
                           ORDER BY atribuido_em DESC LIMIT 1');
    $ins = $pdo->prepare("INSERT INTO lead_etapa_historico (empresa_id, lead_id, de_etapa_id, para_etapa_id, de_categoria, para_categoria, usuario_id, responsavel_id, origem, ocorrido_em, reconstruido)
                          VALUES (:e, :l, :de, :para, :dc, :pc, :u, :r, :o, :em, 1)");
    $n = 0;
    foreach ($q->fetchAll() as $a) {
        $d = json_decode((string) $a['detalhes_json'], true) ?: [];
        if (empty($d['para_etapa_id']) || empty($d['para'])) {
            continue; // registro antigo sem id/categoria de destino: não dá para reconstruir
        }
        $resp->execute(['l' => $leadId, 't1' => $a['criado_em'], 't2' => $a['criado_em']]);
        $r = $resp->fetchColumn();
        $ins->execute([
            'e' => $lead['empresa_id'], 'l' => $leadId, 'de' => $d['de_etapa_id'] ?? null, 'para' => (int) $d['para_etapa_id'],
            'dc' => $d['de'] ?? null, 'pc' => mb_substr((string) $d['para'], 0, 30), 'u' => $a['usuario_id'] ?: null, 'r' => $r ?: null,
            'o' => $a['usuario_id'] ? 'usuario' : 'sistema', 'em' => $a['criado_em'],
        ]);
        $n++;
    }
    return $n;
}

function retro_fila(array $lead): int
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM roleta_fila_historico WHERE lead_id = :l');
    $stmt->execute(['l' => $lead['id']]);
    if ((int) $stmt->fetchColumn() > 0) {
        return 0;
    }
    $stmt = $pdo->prepare('SELECT * FROM roleta_fila WHERE lead_id = :l');
    $stmt->execute(['l' => $lead['id']]);
    $f = $stmt->fetch();
    if (!$f) {
        return 0;
    }
    $pdo->prepare('INSERT INTO roleta_fila_historico (empresa_id, lead_id, roleta_id, origem, entrou_em, saiu_em, resultado, reconstruido)
                   VALUES (:e, :l, :r, :o, :en, :s, :res, 1)')
        ->execute(['e' => $f['empresa_id'], 'l' => $f['lead_id'], 'r' => $f['roleta_id'], 'o' => $f['origem'], 'en' => $f['entrou_em'],
                   's' => $f['resolvido_em'], 'res' => $f['resolvido_em'] ? 'nao_registrado' : null]);
    return 1;
}

/**
 * leads.primeiro_contato_efetivo_em: o primeiro contato efetivo do lead com
 * qualquer corretor. Se um contato ao vivo já foi gravado depois da
 * migração, um contato registrado ANTES dele prevalece.
 */
function retro_contato_efetivo_lead(array $lead): bool
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT MIN(primeiro_contato_efetivo_em) FROM lead_atribuicoes WHERE lead_id = :l AND reconstruido = 1');
    $stmt->execute(['l' => $lead['id']]);
    $pc = $stmt->fetchColumn();
    if (!$pc) {
        return false;
    }
    $upd = $pdo->prepare('UPDATE leads SET primeiro_contato_efetivo_em = :pc WHERE id = :l AND (primeiro_contato_efetivo_em IS NULL OR primeiro_contato_efetivo_em > :pc2)');
    $upd->execute(['pc' => $pc, 'pc2' => $pc, 'l' => $lead['id']]);
    return $upd->rowCount() > 0;
}
