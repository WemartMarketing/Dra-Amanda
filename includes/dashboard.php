<?php
/**
 * Dashboards e relatórios gerenciais (Etapa 6 / seção 12 do prompt mestre).
 *
 * Todas as contas desta tela e das exportações passam por aqui, para que o
 * número da tela e o da planilha saiam da MESMA consulta. Regras fixas:
 *
 *   - Escopo primeiro: leads sempre dentro de rbac_leads_scope(); perdas e
 *     transferências dentro de rbac_transferencias_scope() (pela estrutura
 *     ANTERIOR do lead). Os filtros só restringem — nunca abrem exceção.
 *   - Tudo agregado no banco (GROUP BY), sem uma consulta por linha.
 *   - Nada aqui devolve nome ou telefone de lead: só contagens e nomes de
 *     pessoas/estruturas do recorte de quem vê.
 *
 * Definições (as mesmas dos textos de ajuda "?" na tela):
 *   Leads recebidos       leads que ENTRARAM no período (criado_em).
 *   Distribuídos          desses, os que estão com um corretor.
 *   Sem atendimento       desses, com corretor há mais de DASH_HORAS_SEM_ATENDIMENTO
 *                         horas e ainda sem nenhum feedback válido.
 *   Taxa de 1º contato    com primeiro contato ÷ distribuídos.
 *   Tempo até 1º contato  de atribuido_em até primeiro_contato_em.
 *   Visitas agendadas     visitas criadas na agenda no período (reagendamentos
 *                         não contam de novo).
 *   Visitas realizadas    visitas marcadas como realizadas no período.
 *   Contratos assinados   leads que entraram em "Contrato assinado" no período.
 *   Taxa de conversão     contratos assinados ÷ leads recebidos, no período.
 *   Perdidos por inatividade  leads retirados pela recuperação automática no período.
 */
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/leads.php';

/** Horas com corretor sem nenhum feedback para o lead contar como "sem atendimento". */
function dash_horas_sem_atendimento(): int
{
    return defined('DASH_HORAS_SEM_ATENDIMENTO') ? max(1, (int) DASH_HORAS_SEM_ATENDIMENTO) : 2;
}

function dash_periodos(): array
{
    return [
        'hoje' => 'Hoje',
        '7d' => 'Últimos 7 dias',
        '30d' => 'Últimos 30 dias',
        '90d' => 'Últimos 90 dias',
        'mes' => 'Mês atual',
        'mes_anterior' => 'Mês anterior',
        'personalizado' => 'Personalizado',
    ];
}

/** Agrupamentos da produtividade e das perdas. */
function dash_dimensoes(): array
{
    return [
        'corretor' => 'Corretor',
        'equipe' => 'Equipe',
        'gerente' => 'Gerente',
        'superintendencia' => 'Superintendência',
        'empreendimento' => 'Empreendimento',
        'campanha' => 'Campanha',
        'origem' => 'Origem',
    ];
}

/** Agrupamento inicial conforme o escopo de quem vê (gerente: corretores; superintendente: equipes...). */
function dash_dimensao_padrao(array $usuario): string
{
    return [
        'toda_empresa' => 'superintendencia',
        'todos_produtos' => 'superintendencia',
        'produtos_vinculados' => 'equipe',
        'superintendencia' => 'equipe',
        'equipe' => 'corretor',
    ][$usuario['escopo'] ?? ''] ?? 'corretor';
}

function dash_data_valida(?string $s): ?string
{
    $s = trim((string) $s);
    $dt = DateTime::createFromFormat('!Y-m-d', $s);
    return $dt && $dt->format('Y-m-d') === $s ? $s : null;
}

/**
 * Lê e valida os filtros (GET ou POST da exportação). Devolve o período
 * [ini, fim) com o período anterior de mesmo tamanho para a comparação.
 */
function dash_filtros(array $usuario, array $in): array
{
    $periodo = (string) ($in['periodo'] ?? '30d');
    if (!array_key_exists($periodo, dash_periodos())) {
        $periodo = '30d';
    }
    $hoje = new DateTimeImmutable('today');
    $amanha = $hoje->modify('+1 day');
    $de = $ate = null;

    switch ($periodo) {
        case 'hoje':
            $ini = $hoje; $fim = $amanha;
            break;
        case '7d': case '30d': case '90d':
            $dias = (int) $periodo;
            $ini = $hoje->modify('-' . ($dias - 1) . ' days'); $fim = $amanha;
            break;
        case 'mes':
            $ini = $hoje->modify('first day of this month'); $fim = $amanha;
            break;
        case 'mes_anterior':
            $ini = $hoje->modify('first day of last month'); $fim = $hoje->modify('first day of this month');
            break;
        default: // personalizado
            $de = dash_data_valida($in['de'] ?? null);
            $ate = dash_data_valida($in['ate'] ?? null);
            if (!$de || !$ate) {
                $de = $hoje->modify('-29 days')->format('Y-m-d');
                $ate = $hoje->format('Y-m-d');
            }
            if ($ate < $de) {
                [$de, $ate] = [$ate, $de];
            }
            $ini = new DateTimeImmutable($de);
            $fim = (new DateTimeImmutable($ate))->modify('+1 day');
            if ($fim->getTimestamp() - $ini->getTimestamp() > 366 * 86400) { // no máximo 1 ano por consulta
                $ini = $fim->modify('-366 days');
            }
    }
    $dias = (int) round(($fim->getTimestamp() - $ini->getTimestamp()) / 86400);

    // Período anterior: o mesmo intervalo logo antes. "Mês atual" compara
    // com os mesmos dias do mês passado; "Mês anterior", com o mês antes dele.
    if ($periodo === 'mes') {
        $antIni = $ini->modify('first day of last month');
        $antFim = $antIni->modify('+' . $dias . ' days');
        $limite = $ini;
        if ($antFim > $limite) {
            $antFim = $limite;
        }
    } elseif ($periodo === 'mes_anterior') {
        $antIni = $ini->modify('first day of last month');
        $antFim = $ini;
    } else {
        $antIni = $ini->modify('-' . $dias . ' days');
        $antFim = $ini;
    }

    $int = fn ($k) => (int) ($in[$k] ?? 0) ?: null;
    $origem = (string) ($in['origem'] ?? '');
    $campanha = mb_substr(trim((string) ($in['campanha'] ?? '')), 0, 120);
    $fmt = fn (DateTimeImmutable $a, DateTimeImmutable $b) => $a->format('d/m/Y') . ($b->modify('-1 day')->format('Y-m-d') !== $a->format('Y-m-d') ? ' a ' . $b->modify('-1 day')->format('d/m/Y') : '');

    return [
        'periodo' => $periodo,
        'de' => $de ?? $ini->format('Y-m-d'),
        'ate' => $ate ?? $fim->modify('-1 day')->format('Y-m-d'),
        'ini' => $ini->format('Y-m-d H:i:s'),
        'fim' => $fim->format('Y-m-d H:i:s'),
        'ant_ini' => $antIni->format('Y-m-d H:i:s'),
        'ant_fim' => $antFim->format('Y-m-d H:i:s'),
        'dias' => $dias,
        'rotulo' => $fmt($ini, $fim),
        'rotulo_anterior' => $fmt($antIni, $antFim),
        'empreendimento' => $int('empreendimento'),
        'superintendencia' => $int('superintendencia'),
        'equipe' => $int('equipe'),
        'gerente' => $int('gerente'),
        'corretor' => $int('corretor'),
        'origem' => array_key_exists($origem, lead_origens()) ? $origem : null,
        'campanha' => $campanha !== '' ? $campanha : null,
    ];
}

/** Só os filtros (sem datas calculadas) — para links, formulários de exportação e auditoria. */
function dash_filtros_publicos(array $f): array
{
    $out = ['periodo' => $f['periodo']];
    if ($f['periodo'] === 'personalizado') {
        $out['de'] = $f['de'];
        $out['ate'] = $f['ate'];
    }
    foreach (['empreendimento', 'superintendencia', 'equipe', 'gerente', 'corretor', 'origem', 'campanha'] as $k) {
        if ($f[$k] !== null) {
            $out[$k] = $f[$k];
        }
    }
    return $out;
}

/** Escopo + filtros sobre `leads` (sem o período). */
function dash_leads_where(array $usuario, array $f): array
{
    $scope = rbac_leads_scope($usuario);
    $sql = $scope['sql'];
    $p = $scope['params'];
    if ($f['empreendimento']) { $sql .= ' AND leads.empreendimento_id = :d_emp'; $p['d_emp'] = $f['empreendimento']; }
    if ($f['equipe']) { $sql .= ' AND leads.equipe_id = :d_eq'; $p['d_eq'] = $f['equipe']; }
    if ($f['superintendencia']) { $sql .= ' AND leads.equipe_id IN (SELECT dq1.id FROM equipes dq1 WHERE dq1.superintendencia_id = :d_su)'; $p['d_su'] = $f['superintendencia']; }
    if ($f['gerente']) { $sql .= ' AND leads.equipe_id IN (SELECT dq2.id FROM equipes dq2 WHERE dq2.gerente_id = :d_ge)'; $p['d_ge'] = $f['gerente']; }
    if ($f['corretor']) { $sql .= ' AND leads.responsavel_id = :d_co'; $p['d_co'] = $f['corretor']; }
    if ($f['origem']) { $sql .= ' AND leads.origem = :d_or'; $p['d_or'] = $f['origem']; }
    if ($f['campanha'] === '__sem__') { $sql .= " AND (leads.campanha IS NULL OR leads.campanha = '')"; }
    elseif ($f['campanha']) { $sql .= ' AND leads.campanha = :d_ca'; $p['d_ca'] = $f['campanha']; }
    return [$sql, $p];
}

/** Escopo + filtros sobre `lead_transferencias t` (pela estrutura de ORIGEM), com o período [ini, fim). */
function dash_transf_where(array $usuario, array $f, string $ini, string $fim): array
{
    $scope = rbac_transferencias_scope($usuario);
    $sql = $scope['sql'] . ' AND t.criado_em >= :t_ini AND t.criado_em < :t_fim';
    $p = $scope['params'] + ['t_ini' => $ini, 't_fim' => $fim];
    if ($f['empreendimento']) { $sql .= ' AND t.empreendimento_id = :t_emp'; $p['t_emp'] = $f['empreendimento']; }
    if ($f['equipe']) { $sql .= ' AND t.de_equipe_id = :t_eq'; $p['t_eq'] = $f['equipe']; }
    if ($f['superintendencia']) { $sql .= ' AND t.de_superintendencia_id = :t_su'; $p['t_su'] = $f['superintendencia']; }
    if ($f['gerente']) { $sql .= ' AND t.de_gerente_id = :t_ge'; $p['t_ge'] = $f['gerente']; }
    if ($f['corretor']) { $sql .= ' AND t.de_usuario_id = :t_co'; $p['t_co'] = $f['corretor']; }
    if ($f['origem']) { $sql .= ' AND t.lead_id IN (SELECT tl1.id FROM leads tl1 WHERE tl1.origem = :t_or)'; $p['t_or'] = $f['origem']; }
    if ($f['campanha'] === '__sem__') { $sql .= " AND t.lead_id IN (SELECT tl2.id FROM leads tl2 WHERE tl2.campanha IS NULL OR tl2.campanha = '')"; }
    elseif ($f['campanha']) { $sql .= ' AND t.lead_id IN (SELECT tl3.id FROM leads tl3 WHERE tl3.campanha = :t_ca)'; $p['t_ca'] = $f['campanha']; }
    return [$sql, $p];
}

function dash_um(string $sql, array $params)
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

function dash_mediana(array $valores): ?float
{
    $n = count($valores);
    if (!$n) {
        return null;
    }
    sort($valores, SORT_NUMERIC);
    $meio = intdiv($n, 2);
    return $n % 2 ? (float) $valores[$meio] : ($valores[$meio - 1] + $valores[$meio]) / 2;
}

/** Indicadores principais de um período [ini, fim). */
function dash_kpis(array $usuario, array $f, string $ini, string $fim): array
{
    [$w, $p] = dash_leads_where($usuario, $f);
    $limite = date('Y-m-d H:i:s', time() - dash_horas_sem_atendimento() * 3600);
    $tempo = 'CASE WHEN leads.responsavel_id IS NOT NULL AND leads.primeiro_contato_em IS NOT NULL AND leads.atribuido_em IS NOT NULL
                   THEN GREATEST(0, TIMESTAMPDIFF(SECOND, leads.atribuido_em, leads.primeiro_contato_em)) END';

    $r = dash_um("SELECT COUNT(*) AS recebidos,
            COALESCE(SUM(leads.responsavel_id IS NOT NULL), 0) AS distribuidos,
            COALESCE(SUM(leads.responsavel_id IS NOT NULL AND leads.primeiro_contato_em IS NULL AND leads.atribuido_em < :k_lim), 0) AS sem_atendimento,
            COALESCE(SUM(leads.responsavel_id IS NOT NULL AND leads.primeiro_contato_em IS NOT NULL), 0) AS com_contato,
            AVG({$tempo}) AS tempo_medio
        FROM leads WHERE {$w} AND leads.criado_em >= :k_ini AND leads.criado_em < :k_fim",
        $p + ['k_lim' => $limite, 'k_ini' => $ini, 'k_fim' => $fim]);

    $stmt = db()->prepare("SELECT {$tempo} AS s FROM leads WHERE {$w} AND leads.criado_em >= :k_ini AND leads.criado_em < :k_fim HAVING s IS NOT NULL");
    $stmt->execute($p + ['k_ini' => $ini, 'k_fim' => $fim]);
    $mediana = dash_mediana(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

    $v = dash_um("SELECT
            COALESCE(SUM(ag.reagendado_de IS NULL AND ag.criado_em >= :v_ini AND ag.criado_em < :v_fim), 0) AS agendadas,
            COALESCE(SUM(ag.status = 'realizado' AND ag.concluido_em >= :v_ini2 AND ag.concluido_em < :v_fim2), 0) AS realizadas
        FROM agenda_compromissos ag INNER JOIN leads ON leads.id = ag.lead_id
        WHERE {$w} AND ag.empresa_id = :v_emp AND ag.tipo = 'visita'
          AND ((ag.criado_em >= :v_ini3 AND ag.criado_em < :v_fim3) OR (ag.concluido_em >= :v_ini4 AND ag.concluido_em < :v_fim4))",
        $p + ['v_ini' => $ini, 'v_fim' => $fim, 'v_ini2' => $ini, 'v_fim2' => $fim, 'v_ini3' => $ini, 'v_fim3' => $fim, 'v_ini4' => $ini, 'v_fim4' => $fim,
              'v_emp' => (int) $usuario['empresa_ativa_id']]);

    $contratos = (int) dash_um("SELECT COUNT(*) AS n FROM leads WHERE {$w} AND leads.etapa = 'contrato_assinado'
        AND leads.contrato_assinado_em >= :c_ini AND leads.contrato_assinado_em < :c_fim", $p + ['c_ini' => $ini, 'c_fim' => $fim])['n'];

    [$tw, $tp] = dash_transf_where($usuario, $f, $ini, $fim);
    $perdas = (int) dash_um("SELECT COUNT(*) AS n FROM lead_transferencias t WHERE {$tw} AND t.motivo = 'inatividade'", $tp)['n'];

    $recebidos = (int) $r['recebidos'];
    $distribuidos = (int) $r['distribuidos'];
    $comContato = (int) $r['com_contato'];
    return [
        'recebidos' => $recebidos,
        'distribuidos' => $distribuidos,
        'sem_atendimento' => (int) $r['sem_atendimento'],
        'com_contato' => $comContato,
        'taxa_contato' => $distribuidos ? $comContato / $distribuidos * 100 : null,
        'tempo_medio' => $r['tempo_medio'] !== null ? (float) $r['tempo_medio'] : null,
        'tempo_mediana' => $mediana,
        'visitas_agendadas' => (int) $v['agendadas'],
        'visitas_realizadas' => (int) $v['realizadas'],
        'contratos' => $contratos,
        'taxa_conversao' => $recebidos ? $contratos / $recebidos * 100 : null,
        'perdidos_inatividade' => $perdas,
    ];
}

/** Leads recebidos por dia (ou por semana, em períodos acima de 92 dias), com os dias vazios preenchidos. */
function dash_serie_leads(array $usuario, array $f): array
{
    [$w, $p] = dash_leads_where($usuario, $f);
    $stmt = db()->prepare("SELECT DATE(leads.criado_em) AS dia, COUNT(*) AS total FROM leads
        WHERE {$w} AND leads.criado_em >= :s_ini AND leads.criado_em < :s_fim GROUP BY DATE(leads.criado_em)");
    $stmt->execute($p + ['s_ini' => $f['ini'], 's_fim' => $f['fim']]);
    $porDia = [];
    foreach ($stmt->fetchAll() as $row) {
        $porDia[$row['dia']] = (int) $row['total'];
    }
    return dash_preencher_serie($f, $porDia);
}

/** [rotulo, total] por dia/semana do período, com zeros. */
function dash_preencher_serie(array $f, array $porDia): array
{
    $semanal = $f['dias'] > 92;
    $serie = [];
    $d = new DateTimeImmutable(substr($f['ini'], 0, 10));
    $fim = new DateTimeImmutable(substr($f['fim'], 0, 10));
    while ($d < $fim) {
        if ($semanal) {
            $total = 0;
            for ($i = 0; $i < 7 && $d->modify("+{$i} days") < $fim; $i++) {
                $total += $porDia[$d->modify("+{$i} days")->format('Y-m-d')] ?? 0;
            }
            $serie[] = ['data' => $d->format('Y-m-d'), 'rotulo' => 'Sem. ' . $d->format('d/m'), 'total' => $total];
            $d = $d->modify('+7 days');
        } else {
            $serie[] = ['data' => $d->format('Y-m-d'), 'rotulo' => $d->format('d/m'), 'total' => $porDia[$d->format('Y-m-d')] ?? 0];
            $d = $d->modify('+1 day');
        }
    }
    return $serie;
}

/** Onde estão hoje os leads recebidos no período, por categoria do funil. */
function dash_funil(array $usuario, array $f): array
{
    [$w, $p] = dash_leads_where($usuario, $f);
    $stmt = db()->prepare("SELECT leads.etapa, COUNT(*) AS total FROM leads
        WHERE {$w} AND leads.criado_em >= :u_ini AND leads.criado_em < :u_fim GROUP BY leads.etapa");
    $stmt->execute($p + ['u_ini' => $f['ini'], 'u_fim' => $f['fim']]);
    $tot = array_column($stmt->fetchAll(), 'total', 'etapa');
    $out = [];
    foreach (array_merge(lead_etapas_funil(), ['sem_interesse', 'perdido']) as $cat) {
        $out[] = ['categoria' => $cat, 'rotulo' => lead_etapa_rotulo($cat), 'total' => (int) ($tot[$cat] ?? 0)];
    }
    return $out;
}

/**
 * Tabela por agrupamento (produtividade, empreendimento, campanha, origem).
 * Cada linha: recebidos, distribuídos, com 1º contato, taxa, tempo médio,
 * visitas realizadas, contratos, conversão e perdidos por inatividade.
 * Linhas sem chave (lead sem responsável, sem campanha...) vêm como "Sem ...".
 */
function dash_quebra(array $usuario, array $f, string $dim): array
{
    $defs = [
        'corretor'         => ['leads.responsavel_id', '', 't.de_usuario_id', 'Sem responsável'],
        'equipe'           => ['leads.equipe_id', '', 't.de_equipe_id', 'Sem equipe'],
        'gerente'          => ['eqd.gerente_id', 'LEFT JOIN equipes eqd ON eqd.id = leads.equipe_id', 't.de_gerente_id', 'Sem gerente'],
        'superintendencia' => ['eqd.superintendencia_id', 'LEFT JOIN equipes eqd ON eqd.id = leads.equipe_id', 't.de_superintendencia_id', 'Sem superintendência'],
        'empreendimento'   => ['leads.empreendimento_id', '', 't.empreendimento_id', 'Sem empreendimento'],
        'campanha'         => ["NULLIF(leads.campanha, '')", '', null, 'Sem campanha'],
        'origem'           => ['leads.origem', '', null, '—'],
    ];
    if (!isset($defs[$dim])) {
        $dim = 'corretor';
    }
    [$chave, $join, $tChave, $semRotulo] = $defs[$dim];
    [$w, $p] = dash_leads_where($usuario, $f);
    $ini = $f['ini'];
    $fim = $f['fim'];
    $linhas = [];
    $linha = function ($k) use (&$linhas) {
        $k = $k === null ? '' : (string) $k;
        if (!isset($linhas[$k])) {
            $linhas[$k] = ['chave' => $k, 'recebidos' => 0, 'distribuidos' => 0, 'com_contato' => 0, 'tempo_soma' => 0, 'tempo_n' => 0,
                'visitas_realizadas' => 0, 'contratos' => 0, 'perdidos_inatividade' => 0];
        }
        return $k;
    };

    $stmt = db()->prepare("SELECT {$chave} AS k, COUNT(*) AS recebidos,
            COALESCE(SUM(leads.responsavel_id IS NOT NULL), 0) AS distribuidos,
            COALESCE(SUM(leads.responsavel_id IS NOT NULL AND leads.primeiro_contato_em IS NOT NULL), 0) AS com_contato,
            COALESCE(SUM(CASE WHEN leads.responsavel_id IS NOT NULL AND leads.primeiro_contato_em IS NOT NULL AND leads.atribuido_em IS NOT NULL
                THEN GREATEST(0, TIMESTAMPDIFF(SECOND, leads.atribuido_em, leads.primeiro_contato_em)) END), 0) AS tempo_soma,
            COALESCE(SUM(leads.responsavel_id IS NOT NULL AND leads.primeiro_contato_em IS NOT NULL AND leads.atribuido_em IS NOT NULL), 0) AS tempo_n
        FROM leads {$join} WHERE {$w} AND leads.criado_em >= :q_ini AND leads.criado_em < :q_fim GROUP BY {$chave}");
    $stmt->execute($p + ['q_ini' => $ini, 'q_fim' => $fim]);
    foreach ($stmt->fetchAll() as $r) {
        $k = $linha($r['k']);
        foreach (['recebidos', 'distribuidos', 'com_contato', 'tempo_soma', 'tempo_n'] as $c) {
            $linhas[$k][$c] = (int) $r[$c];
        }
    }

    $stmt = db()->prepare("SELECT {$chave} AS k, COUNT(*) AS n FROM agenda_compromissos ag INNER JOIN leads ON leads.id = ag.lead_id {$join}
        WHERE {$w} AND ag.empresa_id = :q_emp AND ag.tipo = 'visita' AND ag.status = 'realizado' AND ag.concluido_em >= :q_ini AND ag.concluido_em < :q_fim
        GROUP BY {$chave}");
    $stmt->execute($p + ['q_emp' => (int) $usuario['empresa_ativa_id'], 'q_ini' => $ini, 'q_fim' => $fim]);
    foreach ($stmt->fetchAll() as $r) {
        $linhas[$linha($r['k'])]['visitas_realizadas'] = (int) $r['n'];
    }

    $stmt = db()->prepare("SELECT {$chave} AS k, COUNT(*) AS n FROM leads {$join}
        WHERE {$w} AND leads.etapa = 'contrato_assinado' AND leads.contrato_assinado_em >= :q_ini AND leads.contrato_assinado_em < :q_fim GROUP BY {$chave}");
    $stmt->execute($p + ['q_ini' => $ini, 'q_fim' => $fim]);
    foreach ($stmt->fetchAll() as $r) {
        $linhas[$linha($r['k'])]['contratos'] = (int) $r['n'];
    }

    if ($tChave) {
        [$tw, $tp] = dash_transf_where($usuario, $f, $ini, $fim);
        $stmt = db()->prepare("SELECT {$tChave} AS k, COUNT(*) AS n FROM lead_transferencias t WHERE {$tw} AND t.motivo = 'inatividade' GROUP BY {$tChave}");
        $stmt->execute($tp);
        foreach ($stmt->fetchAll() as $r) {
            $linhas[$linha($r['k'])]['perdidos_inatividade'] = (int) $r['n'];
        }
    }

    $nomes = dash_nomes($dim, array_filter(array_keys($linhas), fn ($k) => $k !== ''), (int) $usuario['empresa_ativa_id']);
    foreach ($linhas as $k => &$l) {
        $l['nome'] = $k === '' ? $semRotulo : ($nomes[$k] ?? ('#' . $k));
        $l['taxa_contato'] = $l['distribuidos'] ? $l['com_contato'] / $l['distribuidos'] * 100 : null;
        $l['tempo_medio'] = $l['tempo_n'] ? $l['tempo_soma'] / $l['tempo_n'] : null;
        $l['taxa_conversao'] = $l['recebidos'] ? $l['contratos'] / $l['recebidos'] * 100 : null;
    }
    unset($l);
    $linhas = array_values($linhas);
    usort($linhas, fn ($a, $b) => [$b['recebidos'], $b['contratos'], $a['nome']] <=> [$a['recebidos'], $a['contratos'], $b['nome']]);
    return $linhas;
}

/** Linha de totais de uma quebra (taxas recalculadas sobre as somas, não média de taxas). */
function dash_totais(array $linhas): array
{
    $t = ['nome' => 'Total', 'recebidos' => 0, 'distribuidos' => 0, 'com_contato' => 0, 'tempo_soma' => 0, 'tempo_n' => 0,
        'visitas_realizadas' => 0, 'contratos' => 0, 'perdidos_inatividade' => 0];
    foreach ($linhas as $l) {
        foreach (['recebidos', 'distribuidos', 'com_contato', 'tempo_soma', 'tempo_n', 'visitas_realizadas', 'contratos', 'perdidos_inatividade'] as $c) {
            $t[$c] += $l[$c];
        }
    }
    $t['taxa_contato'] = $t['distribuidos'] ? $t['com_contato'] / $t['distribuidos'] * 100 : null;
    $t['tempo_medio'] = $t['tempo_n'] ? $t['tempo_soma'] / $t['tempo_n'] : null;
    $t['taxa_conversao'] = $t['recebidos'] ? $t['contratos'] / $t['recebidos'] * 100 : null;
    return $t;
}

/** Nomes das chaves de um agrupamento (uma consulta só, sempre filtrada pela empresa). */
function dash_nomes(string $dim, array $ids, int $empresaId): array
{
    if ($dim === 'origem') {
        return lead_origens();
    }
    if ($dim === 'campanha') {
        return array_combine($ids, $ids) ?: [];
    }
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $sql = [
        'corretor' => "SELECT id, nome FROM usuarios WHERE id IN ({$ph}) AND empresa_id = ?",
        'gerente' => "SELECT id, nome FROM usuarios WHERE id IN ({$ph}) AND empresa_id = ?",
        'equipe' => "SELECT e.id, IF(u.nome IS NULL, e.nome, CONCAT(e.nome, ' (', u.nome, ')')) AS nome FROM equipes e LEFT JOIN usuarios u ON u.id = e.gerente_id WHERE e.id IN ({$ph}) AND e.empresa_id = ?",
        'superintendencia' => "SELECT id, nome FROM superintendencias WHERE id IN ({$ph}) AND empresa_id = ?",
        'empreendimento' => "SELECT id, nome FROM empreendimentos WHERE id IN ({$ph}) AND empresa_id = ?",
    ][$dim] ?? null;
    if (!$sql) {
        return [];
    }
    $stmt = db()->prepare($sql);
    $stmt->execute(array_merge($ids, [$empresaId]));
    return array_column($stmt->fetchAll(), 'nome', 'id');
}

/** Só a contagem de leads recebidos por origem/empreendimento/campanha (gráficos), em uma consulta. */
function dash_recebidos_por(array $usuario, array $f, string $dim): array
{
    $col = ['origem' => 'leads.origem', 'empreendimento' => 'leads.empreendimento_id', 'campanha' => "NULLIF(leads.campanha, '')"][$dim] ?? 'leads.origem';
    $sem = ['origem' => '—', 'empreendimento' => 'Sem empreendimento', 'campanha' => 'Sem campanha'][$dim] ?? '—';
    [$w, $p] = dash_leads_where($usuario, $f);
    $stmt = db()->prepare("SELECT {$col} AS k, COUNT(*) AS total FROM leads
        WHERE {$w} AND leads.criado_em >= :g_ini AND leads.criado_em < :g_fim GROUP BY {$col} ORDER BY total DESC");
    $stmt->execute($p + ['g_ini' => $f['ini'], 'g_fim' => $f['fim']]);
    $rows = $stmt->fetchAll();
    $nomes = dash_nomes($dim, array_filter(array_column($rows, 'k')), (int) $usuario['empresa_ativa_id']);
    return array_map(fn ($r) => ['rotulo' => $r['k'] === null ? $sem : ($nomes[$r['k']] ?? '#' . $r['k']), 'total' => (int) $r['total']], $rows);
}

/** Resultados por roleta: leads entregues no período e o que aconteceu com eles. */
function dash_por_roleta(array $usuario, array $f): array
{
    [$w, $p] = dash_leads_where($usuario, $f);
    $stmt = db()->prepare("SELECT r.id, r.nome, COUNT(d.id) AS entregues, COUNT(DISTINCT d.usuario_id) AS corretores,
            COALESCE(SUM(leads.responsavel_id = d.usuario_id AND leads.primeiro_contato_em IS NOT NULL), 0) AS com_contato,
            AVG(CASE WHEN leads.responsavel_id = d.usuario_id AND leads.primeiro_contato_em IS NOT NULL
                THEN GREATEST(0, TIMESTAMPDIFF(SECOND, d.distribuido_em, leads.primeiro_contato_em)) END) AS tempo_medio,
            COALESCE(SUM(leads.etapa = 'contrato_assinado'), 0) AS contratos
        FROM roleta_distribuicoes d
        INNER JOIN roletas r ON r.id = d.roleta_id AND r.empresa_id = :r_emp
        INNER JOIN leads ON leads.id = d.lead_id
        WHERE {$w} AND d.distribuido_em >= :r_ini AND d.distribuido_em < :r_fim
        GROUP BY r.id, r.nome ORDER BY entregues DESC, r.nome");
    $stmt->execute($p + ['r_emp' => (int) $usuario['empresa_ativa_id'], 'r_ini' => $f['ini'], 'r_fim' => $f['fim']]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $e = (int) $r['entregues'];
        $out[] = [
            'nome' => $r['nome'], 'entregues' => $e, 'corretores' => (int) $r['corretores'], 'com_contato' => (int) $r['com_contato'],
            'taxa_contato' => $e ? (int) $r['com_contato'] / $e * 100 : null,
            'tempo_medio' => $r['tempo_medio'] !== null ? (float) $r['tempo_medio'] : null,
            'contratos' => (int) $r['contratos'], 'taxa_conversao' => $e ? (int) $r['contratos'] / $e * 100 : null,
        ];
    }
    return $out;
}

/** Leads aguardando corretor na fila da roleta agora (dentro do escopo). */
function dash_fila_agora(array $usuario, array $f): int
{
    [$w, $p] = dash_leads_where($usuario, $f);
    return (int) dash_um("SELECT COUNT(*) AS n FROM roleta_fila fq INNER JOIN leads ON leads.id = fq.lead_id
        WHERE {$w} AND fq.resolvido_em IS NULL AND leads.responsavel_id IS NULL", $p)['n'];
}

// ------------------------------------------------------------------ Perdas

/**
 * Perdas e transferências agrupadas pela estrutura ANTERIOR do lead
 * (inatividade e administrativas separadas). $dim: corretor | equipe |
 * gerente | superintendencia | empreendimento.
 */
function dash_perdas(array $usuario, array $f, string $dim): array
{
    $defs = [
        'corretor' => ['t.de_usuario_id', 'Sem responsável'],
        'equipe' => ['t.de_equipe_id', 'Sem equipe'],
        'gerente' => ['t.de_gerente_id', 'Sem gerente'],
        'superintendencia' => ['t.de_superintendencia_id', 'Sem superintendência'],
        'empreendimento' => ['t.empreendimento_id', 'Sem empreendimento'],
    ];
    if (!isset($defs[$dim])) {
        $dim = 'corretor';
    }
    [$col, $semRotulo] = $defs[$dim];
    [$tw, $tp] = dash_transf_where($usuario, $f, $f['ini'], $f['fim']);
    $stmt = db()->prepare("SELECT {$col} AS k,
            COALESCE(SUM(t.motivo = 'inatividade'), 0) AS inatividade,
            COALESCE(SUM(t.motivo = 'inatividade' AND t.para_usuario_id IS NOT NULL), 0) AS redistribuidos,
            COALESCE(SUM(t.motivo = 'administrativa'), 0) AS administrativas,
            COALESCE(SUM(t.motivo = 'mudanca_equipe'), 0) AS mudanca_equipe,
            COUNT(*) AS total
        FROM lead_transferencias t WHERE {$tw} AND t.motivo IN ('inatividade', 'administrativa', 'mudanca_equipe')
        GROUP BY {$col}");
    $stmt->execute($tp);
    $rows = $stmt->fetchAll();
    $nomes = dash_nomes($dim, array_filter(array_column($rows, 'k')), (int) $usuario['empresa_ativa_id']);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'nome' => $r['k'] === null ? $semRotulo : ($nomes[$r['k']] ?? '#' . $r['k']),
            'inatividade' => (int) $r['inatividade'], 'redistribuidos' => (int) $r['redistribuidos'],
            'administrativas' => (int) $r['administrativas'], 'mudanca_equipe' => (int) $r['mudanca_equipe'], 'total' => (int) $r['total'],
        ];
    }
    usort($out, fn ($a, $b) => [$b['inatividade'], $b['administrativas'], $a['nome']] <=> [$a['inatividade'], $a['administrativas'], $b['nome']]);
    return $out;
}

/** Tendência das perdas: inatividade e administrativas por dia/semana. */
function dash_perdas_serie(array $usuario, array $f): array
{
    [$tw, $tp] = dash_transf_where($usuario, $f, $f['ini'], $f['fim']);
    $stmt = db()->prepare("SELECT DATE(t.criado_em) AS dia, t.motivo, COUNT(*) AS n FROM lead_transferencias t
        WHERE {$tw} AND t.motivo IN ('inatividade', 'administrativa') GROUP BY DATE(t.criado_em), t.motivo");
    $stmt->execute($tp);
    $ina = $adm = [];
    foreach ($stmt->fetchAll() as $r) {
        if ($r['motivo'] === 'inatividade') { $ina[$r['dia']] = (int) $r['n']; } else { $adm[$r['dia']] = (int) $r['n']; }
    }
    $sIna = dash_preencher_serie($f, $ina);
    $sAdm = dash_preencher_serie($f, $adm);
    foreach ($sIna as $i => &$ponto) {
        $ponto['inatividade'] = $ponto['total'];
        $ponto['administrativas'] = $sAdm[$i]['total'];
        $ponto['total'] = $ponto['inatividade'] + $ponto['administrativas'];
    }
    return $sIna;
}

/** Leads encerrados como perdidos/sem interesse (recebidos no período), por motivo informado. */
function dash_perdas_por_motivo(array $usuario, array $f): array
{
    [$w, $p] = dash_leads_where($usuario, $f);
    $stmt = db()->prepare("SELECT COALESCE(NULLIF(leads.motivo_perda, ''), 'Sem motivo informado') AS motivo, COUNT(*) AS total FROM leads
        WHERE {$w} AND leads.etapa IN ('perdido', 'sem_interesse') AND leads.criado_em >= :m_ini AND leads.criado_em < :m_fim
        GROUP BY COALESCE(NULLIF(leads.motivo_perda, ''), 'Sem motivo informado') ORDER BY total DESC LIMIT 20");
    $stmt->execute($p + ['m_ini' => $f['ini'], 'm_fim' => $f['fim']]);
    return array_map(fn ($r) => ['motivo' => $r['motivo'], 'total' => (int) $r['total']], $stmt->fetchAll());
}

// --------------------------------------------------------- Opções de filtro

/** Opções dos filtros, limitadas ao que quem vê alcança. */
function dash_opcoes(array $usuario): array
{
    $empresaId = (int) $usuario['empresa_ativa_id'];
    $escopo = $usuario['escopo'] ?? '';
    $todaEmpresa = in_array($escopo, ['toda_empresa', 'todos_produtos'], true);
    $q = function (string $sql, array $p) {
        $stmt = db()->prepare($sql);
        $stmt->execute($p);
        return $stmt->fetchAll();
    };
    $us = rbac_usuarios_scope($usuario);

    if ($todaEmpresa) {
        $empreendimentos = $q('SELECT id, nome FROM empreendimentos WHERE empresa_id = :e ORDER BY nome', ['e' => $empresaId]);
        $equipes = $q("SELECT e.id, IF(u.nome IS NULL, e.nome, CONCAT(e.nome, ' (', u.nome, ')')) AS nome, e.gerente_id, e.superintendencia_id
            FROM equipes e LEFT JOIN usuarios u ON u.id = e.gerente_id WHERE e.empresa_id = :e ORDER BY e.nome", ['e' => $empresaId]);
    } else {
        $ls = rbac_leads_scope($usuario);
        $empreendimentos = $escopo === 'produtos_vinculados'
            ? $q('SELECT e.id, e.nome FROM empreendimentos e INNER JOIN usuario_empreendimentos ue ON ue.empreendimento_id = e.id
                  WHERE e.empresa_id = :e AND ue.usuario_id = :u ORDER BY e.nome', ['e' => $empresaId, 'u' => (int) $usuario['id']])
            : $q("SELECT e.id, e.nome FROM empreendimentos e WHERE e.empresa_id = :oe_e AND e.id IN (SELECT DISTINCT leads.empreendimento_id FROM leads WHERE {$ls['sql']}) ORDER BY e.nome",
                $ls['params'] + ['oe_e' => $empresaId]);
        $condEquipe = [
            'superintendencia' => 'e.superintendencia_id IN (SELECT s.id FROM superintendencias s WHERE s.superintendente_id = :oq_u)',
            'equipe' => 'e.gerente_id = :oq_u',
            'produtos_vinculados' => 'e.id IN (SELECT ee.equipe_id FROM equipe_empreendimentos ee INNER JOIN usuario_empreendimentos ue ON ue.empreendimento_id = ee.empreendimento_id WHERE ue.usuario_id = :oq_u)',
        ][$escopo] ?? '1 = 0';
        $equipes = $q("SELECT e.id, IF(u.nome IS NULL, e.nome, CONCAT(e.nome, ' (', u.nome, ')')) AS nome, e.gerente_id, e.superintendencia_id
            FROM equipes e LEFT JOIN usuarios u ON u.id = e.gerente_id WHERE e.empresa_id = :oq_e AND {$condEquipe} ORDER BY e.nome",
            ['oq_e' => $empresaId, 'oq_u' => (int) $usuario['id']]);
    }
    $superIds = array_values(array_unique(array_filter(array_map('intval', array_column($equipes, 'superintendencia_id')))));
    $gerenteIds = array_values(array_unique(array_filter(array_map('intval', array_column($equipes, 'gerente_id')))));
    $superintendencias = $todaEmpresa
        ? $q('SELECT id, nome FROM superintendencias WHERE empresa_id = :e ORDER BY nome', ['e' => $empresaId])
        : ($superIds ? $q('SELECT id, nome FROM superintendencias WHERE empresa_id = ? AND id IN (' . implode(',', $superIds) . ') ORDER BY nome', [$empresaId]) : []);
    $gerentes = $gerenteIds ? $q('SELECT id, nome FROM usuarios WHERE empresa_id = ? AND id IN (' . implode(',', $gerenteIds) . ') ORDER BY nome', [$empresaId]) : [];
    $corretores = $q("SELECT u.id, u.nome FROM usuarios u INNER JOIN funcoes fc ON fc.id = u.funcao_id
        WHERE {$us['sql']} AND fc.escopo = 'propria_carteira' ORDER BY u.nome", $us['params']);
    $ls = rbac_leads_scope($usuario);
    $campanhas = array_column($q("SELECT DISTINCT leads.campanha FROM leads WHERE {$ls['sql']} AND leads.campanha IS NOT NULL AND leads.campanha <> '' ORDER BY leads.campanha LIMIT 300", $ls['params']), 'campanha');

    return compact('empreendimentos', 'equipes', 'superintendencias', 'gerentes', 'corretores', 'campanhas');
}

// ------------------------------------------------------------------ Formatos

/** "1 h 20 min", "35 min", "2 d 4 h", "menos de 1 min" — ou "—". */
function dash_duracao(?float $segundos): string
{
    if ($segundos === null) {
        return '—';
    }
    $s = (int) round($segundos);
    if ($s < 60) {
        return 'menos de 1 min';
    }
    $min = intdiv($s, 60);
    if ($min < 60) {
        return $min . ' min';
    }
    $h = intdiv($min, 60);
    $m = $min % 60;
    if ($h < 24) {
        return $h . ' h' . ($m ? ' ' . $m . ' min' : '');
    }
    $d = intdiv($h, 24);
    $hr = $h % 24;
    return $d . ' d' . ($hr ? ' ' . $hr . ' h' : '');
}

function dash_pct(?float $v, int $casas = 0): string
{
    return $v === null ? '—' : number_format($v, $casas, ',', '.') . '%';
}

function dash_num($v): string
{
    return number_format((float) $v, 0, ',', '.');
}

/**
 * Variação contra o período anterior. $tipo: 'qtd' (percentual), 'pp'
 * (pontos percentuais, para taxas) ou 'tempo' (menor é melhor).
 * Devolve [texto, classe] — classe up (bom), down (ruim) ou ''.
 */
function dash_variacao($atual, $anterior, string $tipo = 'qtd', bool $menorMelhor = false): array
{
    if ($atual === null || $anterior === null) {
        return ['sem base de comparação', ''];
    }
    if ($tipo === 'pp') {
        $dif = $atual - $anterior;
        if (abs($dif) < 0.05) {
            return ['igual ao período anterior', ''];
        }
        $texto = ($dif > 0 ? '+' : '−') . number_format(abs($dif), 1, ',', '.') . ' p.p. vs. anterior';
    } elseif ($tipo === 'tempo') {
        $dif = $atual - $anterior;
        if (abs($dif) < 60) {
            return ['igual ao período anterior', ''];
        }
        $texto = ($dif > 0 ? '+' : '−') . dash_duracao(abs($dif)) . ' vs. anterior';
    } else {
        if ((float) $anterior == 0.0) {
            return [(float) $atual == 0.0 ? 'igual ao período anterior' : 'antes: 0', ''];
        }
        $dif = ($atual - $anterior) / $anterior * 100;
        if (abs($dif) < 0.5) {
            return ['igual ao período anterior', ''];
        }
        $texto = ($dif > 0 ? '+' : '−') . number_format(abs($dif), 0, ',', '.') . '% vs. anterior';
    }
    $bom = $menorMelhor ? $dif < 0 : $dif > 0;
    return [$texto, $bom ? 'up' : 'down'];
}

/** Colunas da tabela de quebra (tela e exportação usam a mesma lista). */
function dash_colunas_quebra(string $dim): array
{
    $cols = [
        'nome' => [dash_dimensoes()[$dim] ?? 'Nome', 'texto'],
        'recebidos' => ['Leads recebidos', 'int'],
        'distribuidos' => ['Distribuídos', 'int'],
        'com_contato' => ['Com 1º contato', 'int'],
        'taxa_contato' => ['Taxa de 1º contato (%)', 'pct'],
        'tempo_medio' => ['Tempo médio até 1º contato', 'tempo'],
        'visitas_realizadas' => ['Visitas realizadas', 'int'],
        'contratos' => ['Contratos assinados', 'int'],
        'taxa_conversao' => ['Conversão (%)', 'pct'],
        'perdidos_inatividade' => ['Perdidos por inatividade', 'int'],
    ];
    if (in_array($dim, ['campanha', 'origem'], true)) {
        unset($cols['perdidos_inatividade']);
    }
    return $cols;
}

function dash_colunas_roleta(): array
{
    return [
        'nome' => ['Roleta', 'texto'],
        'entregues' => ['Leads entregues', 'int'],
        'corretores' => ['Corretores', 'int'],
        'com_contato' => ['Com 1º contato', 'int'],
        'taxa_contato' => ['Taxa de 1º contato (%)', 'pct'],
        'tempo_medio' => ['Tempo médio até 1º contato', 'tempo'],
        'contratos' => ['Viraram contrato', 'int'],
        'taxa_conversao' => ['Conversão (%)', 'pct'],
    ];
}

function dash_colunas_perdas(string $dim): array
{
    return [
        'nome' => [dash_dimensoes()[$dim] ?? 'Nome', 'texto'],
        'inatividade' => ['Perdidos por inatividade', 'int'],
        'redistribuidos' => ['Já redistribuídos', 'int'],
        'administrativas' => ['Transferências administrativas', 'int'],
        'mudanca_equipe' => ['Mudança de equipe', 'int'],
        'total' => ['Total que saiu', 'int'],
    ];
}

/** Formata uma célula para a tela conforme o tipo da coluna. */
function dash_celula($valor, string $tipo): string
{
    switch ($tipo) {
        case 'int': return dash_num($valor);
        case 'pct': return dash_pct($valor === null ? null : (float) $valor);
        case 'tempo': return dash_duracao($valor === null ? null : (float) $valor);
        default: return (string) $valor;
    }
}

/** Valor "ordenável" (data-sort) para a ordenação da tabela no navegador. */
function dash_ordem($valor, string $tipo): string
{
    if ($tipo === 'texto') {
        return mb_strtolower((string) $valor);
    }
    return $valor === null ? '-1' : (string) round((float) $valor, 4);
}
