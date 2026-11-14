<?php
/**
 * Relatórios especializados da Central (Etapa 4): funil em detalhe,
 * plantões e roletas, origens/UTM, visitas e vendas, perdas e
 * redistribuições. Mesmas regras do motor: permissão no SQL, filtro inválido
 * → vazio, sem base → null, nada por suposição.
 */
require_once __DIR__ . '/motor.php';

function relesp_vazio(array $f): bool
{
    return $f['vazio'] || !empty($f['erro']);
}

// ===================================================================== Funil em detalhe

/**
 * - posicao: onde estão HOJE os leads que entraram no período (por etapa).
 * - alcance: dos leads do período, quantos já CHEGARAM a cada fase do funil
 *   linear (etapa atual ou alguma passagem registrada no histórico) — é o
 *   funil de conversão da coorte, que não "perde" quem voltou de etapa.
 * - matriz: transições no período (de → para, por categoria), separando
 *   usuário e jornada.
 * - tempo_etapa_horas: de relatorio_funil().
 */
function relatorio_funil_detalhe(array $usuario, array $f): array
{
    $vazio = ['posicao' => [], 'alcance' => [], 'matriz' => [], 'coorte' => 0, 'transicoes' => 0, 'avancos' => 0, 'retrocessos' => 0,
              'por_origem' => ['usuario' => 0, 'jornada' => 0, 'sistema' => 0], 'tempo_etapa_horas' => []];
    if (relesp_vazio($f)) {
        return $vazio;
    }
    [$w, $p] = motor_where_leads($usuario, $f);
    $per = ['ini' => $f['periodo']['ini'], 'fim' => $f['periodo']['fim']];
    $res = relatorio_funil($usuario, $f) + $vazio;
    // Onde estão hoje (agrupado no banco).
    $stmt = db()->prepare("SELECT fe.id AS etapa_id, fe.nome, leads.etapa AS categoria, COALESCE(fe.ordem, 999) AS ordem, COALESCE(fe.cor, '#8c96aa') AS cor, COUNT(*) AS total
        FROM leads LEFT JOIN funil_etapas fe ON fe.id = leads.etapa_id WHERE {$w} AND leads.criado_em BETWEEN :ini AND :fim
        GROUP BY fe.id, fe.nome, leads.etapa, fe.ordem, fe.cor ORDER BY ordem, fe.id");
    $stmt->execute($p + $per);
    foreach ($stmt->fetchAll() as $l) {
        $res['posicao'][] = ['etapa_id' => $l['etapa_id'] ? (int) $l['etapa_id'] : null, 'nome' => $l['nome'] ?? lead_etapa_rotulo((string) $l['categoria']),
                             'categoria' => $l['categoria'], 'ordem' => (int) $l['ordem'], 'cor' => $l['cor'], 'total' => (int) $l['total']];
    }
    $res['coorte'] = array_sum(array_column($res['posicao'], 'total'));

    // Alcance pela ordem linear das categorias: fase mais adiantada de cada lead
    // (etapa atual ou passagem registrada), calculada no banco.
    $caso = function (string $col) {
        $c = 'CASE ' . $col;
        foreach (lead_etapas_funil() as $i => $cat) {
            $c .= " WHEN '" . $cat . "' THEN " . $i;
        }
        return $c . ' ELSE 0 END';
    };
    $hist = historico_instalado()
        ? 'COALESCE((SELECT MAX(' . $caso('h.para_categoria') . ') FROM lead_etapa_historico h WHERE h.lead_id = leads.id), 0)' : '0';
    $stmt = db()->prepare("SELECT m, COUNT(*) AS n FROM (SELECT GREATEST(" . $caso('leads.etapa') . ", {$hist}) AS m FROM leads
        WHERE {$w} AND leads.criado_em BETWEEN :ini AND :fim) x GROUP BY m");
    $stmt->execute($p + $per);
    $porNivel = array_map('intval', array_column($stmt->fetchAll(), 'n', 'm'));
    $acum = 0;
    $alc = [];
    foreach (array_reverse(lead_etapas_funil(), true) as $i => $cat) {
        $acum += $porNivel[$i] ?? 0;
        $alc[$i] = $acum;
    }
    foreach (lead_etapas_funil() as $i => $cat) {
        $n = $alc[$i];
        $res['alcance'][] = ['categoria' => $cat, 'rotulo' => lead_etapa_rotulo($cat), 'total' => $n,
                             'pct_coorte' => motor_taxa($n, $res['coorte']), 'pct_anterior' => $i === 0 ? null : motor_taxa($n, $alc[$i - 1])];
    }
    $res['alcance'][0]['nota'] = 'Todos os leads do período começam aqui.';

    if (historico_instalado()) {
        $stmt = db()->prepare("SELECT COALESCE(h.de_categoria, '—') AS de, h.para_categoria AS para, h.origem, COUNT(*) AS n
            FROM lead_etapa_historico h JOIN leads ON leads.id = h.lead_id WHERE {$w} AND h.ocorrido_em BETWEEN :ini AND :fim
            GROUP BY 1, 2, 3 ORDER BY n DESC");
        $stmt->execute($p + $per);
        $m = [];
        foreach ($stmt->fetchAll() as $t) {
            $k = $t['de'] . '>' . $t['para'];
            $m[$k] = $m[$k] ?? ['de' => $t['de'], 'para' => $t['para'], 'usuario' => 0, 'jornada' => 0, 'sistema' => 0, 'total' => 0];
            $m[$k][$t['origem']] += (int) $t['n'];
            $m[$k]['total'] += (int) $t['n'];
        }
        usort($m, fn ($a, $b) => $b['total'] <=> $a['total']);
        $res['matriz'] = $m;
    }
    return $res;
}

// ===================================================================== Plantões e roletas

/**
 * Por roleta: entregas (atribuições pela roleta), chegadas e chegadas fora do
 * horário, passagens pela fila e espera mediana, horas de presença dos
 * corretores. Presença: só de quem está no recorte de pessoas de quem vê.
 */
function relatorio_plantoes(array $usuario, array $f): array
{
    if (relesp_vazio($f)) {
        return ['roletas' => [], 'presenca' => []];
    }
    $e = (int) $usuario['empresa_ativa_id'];
    $per = ['ini' => $f['periodo']['ini'], 'fim' => $f['periodo']['fim']];
    $plantoes = modulo_tabela_existe('plantoes');
    $sql = 'SELECT r.id, r.nome' . ($plantoes ? ', p.nome AS plantao' : ', NULL AS plantao') . ' FROM roletas r'
        . ($plantoes ? ' LEFT JOIN plantoes p ON p.id = r.plantao_id' : '') . ' WHERE r.empresa_id = :e';
    $pr = ['e' => $e];
    if (!empty($f['dim']['roleta'])) {
        $sql .= ' AND r.id IN (' . motor_in($f['dim']['roleta'], 'pr_r', $pr) . ')';
    }
    if (!empty($f['dim']['plantao'])) {
        $sql .= ' AND r.plantao_id IN (' . motor_in($f['dim']['plantao'], 'pr_p', $pr) . ')';
    }
    $stmt = db()->prepare($sql . ' ORDER BY r.nome');
    $stmt->execute($pr);
    $rol = [];
    foreach ($stmt->fetchAll() as $r) {
        $rol[(int) $r['id']] = ['id' => (int) $r['id'], 'nome' => $r['nome'], 'plantao' => $r['plantao'], 'entregas' => 0, 'redistribuicoes' => 0,
            'chegadas' => 0, 'fora_horario' => 0, 'fila_passagens' => 0, 'fila_aguardando' => 0, 'espera_mediana_min' => null, 'horas_presenca' => 0.0, 'corretores_presentes' => 0];
    }
    if (!$rol) {
        return ['roletas' => [], 'presenca' => []];
    }
    $h = historico_instalado();
    if ($h) {
        [$w, $p] = motor_where_atribuicoes($usuario, $f);
        $stmt = db()->prepare("SELECT a.roleta_id, SUM(a.forma = 'roleta') AS roleta, SUM(a.forma = 'redistribuicao') AS redis
            FROM lead_atribuicoes a JOIN leads l ON l.id = a.lead_id WHERE {$w} AND a.roleta_id IS NOT NULL AND a.atribuido_em BETWEEN :ini AND :fim GROUP BY a.roleta_id");
        $stmt->execute($p + $per);
        foreach ($stmt->fetchAll() as $r) {
            if (isset($rol[(int) $r['roleta_id']])) {
                $rol[(int) $r['roleta_id']]['entregas'] = (int) $r['roleta'];
                $rol[(int) $r['roleta_id']]['redistribuicoes'] = (int) $r['redis'];
            }
        }
        [$wl, $pl] = motor_where_leads($usuario, $f);
        $stmt = db()->prepare("SELECT leads.chegada_roleta_id AS r, COUNT(*) AS n, SUM(leads.chegada_roleta_aberta = 0) AS fora
            FROM leads WHERE {$wl} AND leads.chegada_roleta_id IS NOT NULL AND leads.criado_em BETWEEN :ini AND :fim GROUP BY leads.chegada_roleta_id");
        $stmt->execute($pl + $per);
        foreach ($stmt->fetchAll() as $r) {
            if (isset($rol[(int) $r['r']])) {
                $rol[(int) $r['r']]['chegadas'] = (int) $r['n'];
                $rol[(int) $r['r']]['fora_horario'] = (int) $r['fora'];
            }
        }
        $stmt = db()->prepare("SELECT fh.roleta_id, fh.saiu_em, TIMESTAMPDIFF(MINUTE, fh.entrou_em, fh.saiu_em) AS m
            FROM roleta_fila_historico fh JOIN leads ON leads.id = fh.lead_id WHERE {$wl} AND fh.roleta_id IS NOT NULL AND fh.entrou_em BETWEEN :ini AND :fim");
        $stmt->execute($pl + $per);
        $esperas = [];
        foreach ($stmt->fetchAll() as $r) {
            $id = (int) $r['roleta_id'];
            if (!isset($rol[$id])) {
                continue;
            }
            $rol[$id]['fila_passagens']++;
            if ($r['saiu_em'] === null) {
                $rol[$id]['fila_aguardando']++;
            } else {
                $esperas[$id][] = (int) $r['m'];
            }
        }
        foreach ($esperas as $id => $v) {
            $rol[$id]['espera_mediana_min'] = motor_mediana($v);
        }
    }
    // Presença (sessões que tocam o período, recortadas a ele).
    $us = rbac_usuarios_scope($usuario);
    $stmt = db()->prepare("SELECT s.roleta_id, s.usuario_id, u.nome,
            SUM(GREATEST(0, TIMESTAMPDIFF(SECOND, GREATEST(s.entrou_em, :pi1), LEAST(COALESCE(s.saiu_em, s.ultima_verificacao_em, s.entrou_em), :pf1)))) AS seg
        FROM roleta_sessoes s JOIN usuarios u ON u.id = s.usuario_id
        WHERE s.empresa_id = :pe AND s.entrou_em <= :pf2 AND COALESCE(s.saiu_em, s.ultima_verificacao_em, s.entrou_em) >= :pi2
          AND s.usuario_id IN (SELECT u.id FROM usuarios u WHERE {$us['sql']})
        GROUP BY s.roleta_id, s.usuario_id, u.nome");
    $stmt->execute($us['params'] + ['pe' => $e, 'pi1' => $per['ini'], 'pf1' => $per['fim'], 'pi2' => $per['ini'], 'pf2' => $per['fim']]);
    $presenca = [];
    foreach ($stmt->fetchAll() as $r) {
        $id = (int) $r['roleta_id'];
        if (!isset($rol[$id]) || !empty($f['dim']['corretor']) && !in_array((int) $r['usuario_id'], $f['dim']['corretor'], true)) {
            continue;
        }
        $horas = round((int) $r['seg'] / 3600, 1);
        $rol[$id]['horas_presenca'] = round($rol[$id]['horas_presenca'] + $horas, 1);
        $rol[$id]['corretores_presentes']++;
        $presenca[] = ['roleta' => $rol[$id]['nome'], 'roleta_id' => $id, 'usuario_id' => (int) $r['usuario_id'], 'nome' => $r['nome'], 'horas' => $horas];
    }
    usort($presenca, fn ($a, $b) => $b['horas'] <=> $a['horas']);
    return ['roletas' => array_values($rol), 'presenca' => $presenca, 'historico' => $h];
}

// ===================================================================== Origens e UTMs

function relatorio_origens_agrupamentos(): array
{
    return ['origem' => 'Origem', 'canal' => 'Grupo de origem', 'utm_source' => 'utm_source', 'utm_medium' => 'utm_medium', 'utm_campaign' => 'utm_campaign'];
}

/**
 * Coorte: leads que ENTRARAM no período, agrupados pela origem de aquisição,
 * e o que já aconteceu com eles até agora (distribuídos, contato efetivo,
 * visita realizada, contrato validado). Investimento não existe no sistema:
 * custo por lead fica "não disponível".
 */
function relatorio_origens(array $usuario, array $f, string $por): array
{
    if (relesp_vazio($f) || !isset(relatorio_origens_agrupamentos()[$por])) {
        return [];
    }
    $temOrig = modulo_tabela_existe('lead_origens');
    if (!$temOrig && $por !== 'canal') {
        return [];
    }
    $h = historico_instalado();
    [$w, $p] = motor_where_leads($usuario, $f);
    $grupo = [
        'origem' => "COALESCE(o.nome, 'Não informada')",
        'canal' => 'leads.origem',
        'utm_source' => "COALESCE(leads.utm_source, '(sem UTM)')",
        'utm_medium' => "COALESCE(leads.utm_medium, '(sem UTM)')",
        'utm_campaign' => "COALESCE(leads.utm_campaign, '(sem UTM)')",
    ][$por];
    $contrato = modulo_tabela_existe('lead_contratos')
        ? "SUM(EXISTS (SELECT 1 FROM lead_contratos c WHERE c.lead_id = leads.id AND c.status = 'validado'))" : 'NULL';
    $gid = ['origem' => $temOrig ? 'o.id' : 'NULL', 'canal' => 'leads.origem'][$por] ?? 'NULL';
    $stmt = db()->prepare("SELECT {$grupo} AS nome, {$gid} AS gid, COUNT(*) AS leads,
            " . ($h ? 'SUM(EXISTS (SELECT 1 FROM lead_atribuicoes x WHERE x.lead_id = leads.id))' : 'SUM(leads.responsavel_id IS NOT NULL)') . " AS distribuidos,
            " . ($h ? 'SUM(leads.primeiro_contato_efetivo_em IS NOT NULL)' : 'NULL') . " AS contato_efetivo,
            SUM(EXISTS (SELECT 1 FROM agenda_compromissos ag WHERE ag.lead_id = leads.id AND ag.tipo = 'visita' AND ag.status = 'realizado')) AS com_visita,
            {$contrato} AS com_contrato
        FROM leads " . ($temOrig ? 'LEFT JOIN lead_origens o ON o.id = leads.origem_detalhe_id' : '') . "
        WHERE {$w} AND leads.criado_em BETWEEN :ini AND :fim GROUP BY 1, 2 ORDER BY leads DESC, nome");
    $stmt->execute($p + ['ini' => $f['periodo']['ini'], 'fim' => $f['periodo']['fim']]);
    $linhas = [];
    foreach ($stmt->fetchAll() as $r) {
        foreach (['leads', 'distribuidos', 'contato_efetivo', 'com_visita', 'com_contrato'] as $k) {
            $r[$k] = $r[$k] === null ? null : (int) $r[$k];
        }
        if ($por === 'canal') {
            $r['nome'] = lead_origens()[$r['nome']] ?? $r['nome'];
        }
        $r['taxa_contato_efetivo'] = $r['contato_efetivo'] === null ? null : motor_taxa($r['contato_efetivo'], $r['distribuidos']);
        $r['taxa_visita'] = motor_taxa($r['com_visita'], $r['leads']);
        $r['taxa_contrato'] = $r['com_contrato'] === null ? null : motor_taxa($r['com_contrato'], $r['leads']);
        $r['investimento'] = null;
        $r['custo_por_lead'] = null;      // sem investimento lançado: não disponível (nunca estimado)
        $r['custo_por_contrato'] = null;
        $linhas[] = $r;
    }
    // Custos por linha: só quando há investimento lançado para aquela origem (ou grupo) e o recorte permite.
    require_once __DIR__ . '/../investimentos.php';
    if ($linhas && in_array($por, ['origem', 'canal'], true) && investimento_custos_permitidos($usuario, $f)[0]) {
        foreach ($linhas as &$r) {
            if ($r['gid'] === null) {
                continue;
            }
            $ids = $por === 'origem' ? [(int) $r['gid']]
                : array_map('intval', db()->query('SELECT id FROM lead_origens WHERE empresa_id = ' . (int) $usuario['empresa_ativa_id'] . ' AND canal = ' . db()->quote($r['gid']))->fetchAll(PDO::FETCH_COLUMN));
            $inv = investimento_no_periodo((int) $usuario['empresa_ativa_id'], $f['periodo']['ini'], $f['periodo']['fim'], $ids, $f['dim']['empreendimento'] ?? null);
            if ($inv['valor'] !== null) {
                $r['investimento'] = $inv['valor'];
                $r['custo_por_lead'] = $r['leads'] ? round($inv['valor'] / $r['leads'], 2) : null;
                $r['custo_por_contrato'] = $r['com_contrato'] ? round($inv['valor'] / $r['com_contrato'], 2) : null;
            }
        }
        unset($r);
    }
    return $linhas;
}

// ===================================================================== Visitas e vendas

/**
 * Visitas no escopo de agenda de quem vê, agrupadas por corretor (quem
 * conduziu) ou empreendimento do lead. Cada status pela própria data:
 * agendada (criação, sem reagendamento), confirmada (confirmada_em),
 * realizada/ausente/cancelada/reagendada (concluido_em). Conversão:
 * dos leads com visita realizada no período, quantos têm contrato validado.
 */
function relatorio_visitas_por(array $usuario, array $f, string $dim): array
{
    if (relesp_vazio($f) || !in_array($dim, ['corretor', 'empreendimento'], true)) {
        return [];
    }
    $s = rbac_agenda_scope($usuario);
    $cond = [$s['sql'], "ag.tipo = 'visita'", 'leads.excluido_em IS NULL'];
    $p = $s['params'];
    if (!empty($f['dim']['corretor'])) {
        $cond[] = 'ag.usuario_id IN (' . motor_in($f['dim']['corretor'], 'vp_c', $p) . ')';
    }
    if (!empty($f['dim']['empreendimento'])) {
        $cond[] = 'leads.empreendimento_id IN (' . motor_in($f['dim']['empreendimento'], 'vp_e', $p) . ')';
    }
    foreach (['equipe' => 'equipe_id', 'superintendencia' => null] as $k => $col) {
        if (!empty($f['dim'][$k])) {
            $cond[] = $k === 'equipe'
                ? 'ag.usuario_id IN (SELECT id FROM usuarios WHERE equipe_id IN (' . motor_in($f['dim'][$k], 'vp_q', $p) . '))'
                : 'ag.usuario_id IN (SELECT u2.id FROM usuarios u2 JOIN equipes q2 ON q2.id = u2.equipe_id WHERE q2.superintendencia_id IN (' . motor_in($f['dim'][$k], 'vp_s', $p) . '))';
        }
    }
    $conf = modulo_tabela_existe('lead_atribuicoes');
    $grp = $dim === 'corretor' ? 'ag.usuario_id' : 'leads.empreendimento_id';
    $nome = $dim === 'corretor' ? 'u.nome' : 'emp.nome';
    $join = $dim === 'corretor' ? 'LEFT JOIN usuarios u ON u.id = ag.usuario_id' : 'LEFT JOIN empreendimentos emp ON emp.id = leads.empreendimento_id';
    $per = $f['periodo'];
    foreach (range(1, 7) as $i) {
        $p['vi' . $i] = $per['ini'];
        $p['vf' . $i] = $per['fim'];
    }
    $contrato = modulo_tabela_existe('lead_contratos')
        ? "COUNT(DISTINCT CASE WHEN ag.status = 'realizado' AND COALESCE(ag.concluido_em BETWEEN :vi7 AND :vf7, 0)
               AND EXISTS (SELECT 1 FROM lead_contratos c WHERE c.lead_id = ag.lead_id AND c.status = 'validado') THEN ag.lead_id END)"
        : 'NULL';
    if ($contrato === 'NULL') {
        unset($p['vi7'], $p['vf7']);
    }
    if (!$conf) {
        unset($p['vi2'], $p['vf2']);
    }
    $stmt = db()->prepare("SELECT {$grp} AS id, {$nome} AS nome,
            COALESCE(SUM(ag.criado_em BETWEEN :vi1 AND :vf1 AND ag.reagendado_de IS NULL), 0) AS agendadas,
            " . ($conf ? 'COALESCE(SUM(COALESCE(ag.confirmada_em BETWEEN :vi2 AND :vf2, 0)), 0)' : 'NULL') . " AS confirmadas,
            COALESCE(SUM(ag.status = 'realizado' AND COALESCE(ag.concluido_em BETWEEN :vi3 AND :vf3, 0)), 0) AS realizadas,
            COALESCE(SUM(ag.status = 'cliente_ausente' AND COALESCE(ag.concluido_em BETWEEN :vi4 AND :vf4, 0)), 0) AS ausentes,
            COALESCE(SUM(ag.status = 'cancelado' AND COALESCE(ag.concluido_em BETWEEN :vi5 AND :vf5, 0)), 0) AS canceladas,
            COALESCE(SUM(ag.status = 'reagendado' AND COALESCE(ag.concluido_em BETWEEN :vi6 AND :vf6, 0)), 0) AS reagendadas,
            {$contrato} AS viraram_contrato
        FROM agenda_compromissos ag JOIN leads ON leads.id = ag.lead_id {$join}
        WHERE " . implode(' AND ', $cond) . " GROUP BY {$grp}, {$nome}");
    $stmt->execute($p);
    $linhas = [];
    foreach ($stmt->fetchAll() as $r) {
        $r = array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, $r);
        if (!$r['agendadas'] && !$r['realizadas'] && !$r['ausentes'] && !$r['canceladas'] && !$r['reagendadas'] && !$r['confirmadas']) {
            continue;
        }
        $r['nome'] = $r['nome'] ?? ($dim === 'corretor' ? 'Sem corretor' : 'Sem empreendimento');
        $r['comparecimento'] = motor_taxa($r['realizadas'], $r['realizadas'] + $r['ausentes']);
        $r['taxa_contrato'] = $r['viraram_contrato'] === null ? null : motor_taxa($r['viraram_contrato'], $r['realizadas']);
        $linhas[] = $r;
    }
    usort($linhas, fn ($a, $b) => [$b['realizadas'], $b['agendadas']] <=> [$a['realizadas'], $a['agendadas']]);
    return $linhas;
}

// ===================================================================== Perdas e redistribuições

/**
 * Saídas de leads da carteira no período, pela estrutura de QUEM perdeu
 * (gravada na atribuição): por inatividade e por transferência (lead excluído
 * pela LGPD não entra em nenhum relatório). Para
 * as perdas por inatividade: quantas já foram redistribuídas, em quanto
 * tempo (mediana), e o que aconteceu depois (contato efetivo do novo
 * corretor, contrato validado).
 */
function relatorio_perdas_por(array $usuario, array $f, string $dim): array
{
    $ag = relatorio_agrupamentos()[$dim] ?? null;
    if (!$ag || relesp_vazio($f) || !historico_instalado()) {
        return [];
    }
    [$col, $rotulo, $tabela] = $ag;
    [$w, $p] = motor_where_atribuicoes($usuario, $f);
    $temC = modulo_tabela_existe('lead_contratos');
    $stmt = db()->prepare("SELECT a.{$col} AS id, n.nome, a.lead_id, a.usuario_id, a.motivo_encerramento AS motivo, a.encerrada_em,
            nx.atribuido_em AS prox_em, nx.primeiro_contato_efetivo_em AS prox_contato
            " . ($temC ? ", EXISTS (SELECT 1 FROM lead_contratos c WHERE c.lead_id = a.lead_id AND c.status = 'validado' AND c.usuario_id <> a.usuario_id) AS contrato_depois" : ', 0 AS contrato_depois') . "
        FROM lead_atribuicoes a JOIN leads l ON l.id = a.lead_id LEFT JOIN {$tabela} n ON n.id = a.{$col}
        LEFT JOIN lead_atribuicoes nx ON nx.id = (SELECT x.id FROM lead_atribuicoes x WHERE x.lead_id = a.lead_id AND x.id <> a.id AND x.atribuido_em >= a.encerrada_em
                                                   AND x.forma <> 'mudanca_equipe' ORDER BY x.atribuido_em, x.id LIMIT 1)
        WHERE {$w} AND a.encerrada_em BETWEEN :ini AND :fim AND a.motivo_encerramento IN ('inatividade', 'transferencia')");
    $stmt->execute($p + ['ini' => $f['periodo']['ini'], 'fim' => $f['periodo']['fim']]);
    $g = [];
    $horas = [];
    foreach ($stmt->fetchAll() as $r) {
        $k = (string) $r['id'];
        $g[$k] = $g[$k] ?? ['id' => $r['id'] === null ? null : (int) $r['id'], 'nome' => $r['nome'] ?? ('Sem ' . mb_strtolower($rotulo) . ' registrado(a)'),
            'inatividade' => 0, 'transferidos' => 0, 'redistribuidos' => 0, 'contato_depois' => 0, 'contrato_depois' => 0, 'tempo_redistribuicao_h' => null];
        if ($r['motivo'] === 'inatividade') {
            $g[$k]['inatividade']++;
            if ($r['prox_em']) {
                $g[$k]['redistribuidos']++;
                $horas[$k][] = round((strtotime($r['prox_em']) - strtotime($r['encerrada_em'])) / 3600, 1);
                $g[$k]['contato_depois'] += $r['prox_contato'] ? 1 : 0;
            }
            $g[$k]['contrato_depois'] += (int) $r['contrato_depois'];
        } else {
            $g[$k]['transferidos']++;
        }
    }
    foreach ($g as $k => &$l) {
        $l['tempo_redistribuicao_h'] = motor_mediana($horas[$k] ?? []);
        $l['taxa_contato_depois'] = motor_taxa($l['contato_depois'], $l['redistribuidos']);
        $l['total'] = $l['inatividade'] + $l['transferidos'];
    }
    unset($l);
    usort($g, fn ($a, $b) => [$b['inatividade'], $b['total']] <=> [$a['inatividade'], $a['total']]);
    return array_values($g);
}

/** Para onde foram os leads perdidos por inatividade no período (estrutura de quem recebeu depois). */
function relatorio_perdas_destino(array $usuario, array $f, string $dim = 'equipe'): array
{
    $ag = relatorio_agrupamentos()[$dim] ?? null;
    if (!$ag || relesp_vazio($f) || !historico_instalado()) {
        return [];
    }
    [$col, $rotulo, $tabela] = $ag;
    [$w, $p] = motor_where_atribuicoes($usuario, $f);
    $stmt = db()->prepare("SELECT COALESCE(n.nome, IF(nx.id IS NULL, 'Ainda sem corretor', 'Sem " . mb_strtolower($rotulo) . " registrado(a)')) AS nome, COUNT(*) AS total
        FROM lead_atribuicoes a JOIN leads l ON l.id = a.lead_id
        LEFT JOIN lead_atribuicoes nx ON nx.id = (SELECT x.id FROM lead_atribuicoes x WHERE x.lead_id = a.lead_id AND x.id <> a.id AND x.atribuido_em >= a.encerrada_em
                                                   AND x.forma <> 'mudanca_equipe' ORDER BY x.atribuido_em, x.id LIMIT 1)
        LEFT JOIN {$tabela} n ON n.id = nx.{$col}
        WHERE {$w} AND a.motivo_encerramento = 'inatividade' AND a.encerrada_em BETWEEN :ini AND :fim GROUP BY 1 ORDER BY total DESC");
    $stmt->execute($p + ['ini' => $f['periodo']['ini'], 'fim' => $f['periodo']['fim']]);
    return array_map(fn ($r) => ['rotulo' => $r['nome'], 'total' => (int) $r['total']], $stmt->fetchAll());
}
