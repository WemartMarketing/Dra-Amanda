<?php
/**
 * Motor da Central de Relatórios (Etapa 2).
 *
 * Uma única fonte de cálculo por indicador: telas, gráficos e exportações
 * (Etapas 3–5) chamam estas funções. Definições: docs/RELATORIOS_DICIONARIO.md.
 *
 * Regras:
 *   - Permissão SEMPRE no SQL:
 *       indicadores de LEADS (coorte pela data de entrada) → rbac_leads_scope
 *       (o que o usuário enxerga hoje);
 *       indicadores de ATRIBUIÇÃO (o que cada corretor recebeu/fez) → a
 *       estrutura gravada NA DATA da atribuição (motor_escopo_atribuicoes),
 *       para o gestor continuar vendo o histórico da sua equipe mesmo depois
 *       que o lead saiu dela. Só números agregados.
 *   - Filtro com id que não existe na empresa → resultado vazio (nunca
 *     "ignorar o filtro" e mostrar tudo).
 *   - Sem base → null ("—"), nunca 0% inventado.
 *   - Lead excluído (LGPD) não entra em nada.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../rbac.php';
require_once __DIR__ . '/../historico.php';
require_once __DIR__ . '/../funil.php';

// ===================================================================== Períodos

function relatorio_periodos(): array
{
    return ['hoje' => 'Hoje', 'ontem' => 'Ontem', '7d' => 'Últimos 7 dias', '30d' => 'Últimos 30 dias', 'mes' => 'Este mês',
            'mes_anterior' => 'Mês anterior', 'trimestre' => 'Este trimestre', 'ano' => 'Este ano', 'personalizado' => 'Personalizado'];
}

/**
 * Resolve o período. Retorna ['chave','ini','fim','rotulo','dias','anterior'=>[ini,fim,rotulo]] ou ['erro'].
 * ini/fim são 'Y-m-d H:i:s' (00:00:00 e 23:59:59, fuso do sistema). O
 * período anterior tem a mesma duração e termina no dia antes do início
 * (mês/trimestre/ano → o mês/trimestre/ano anterior inteiro).
 */
function relatorio_periodo(string $chave, ?string $ini = null, ?string $fim = null, ?DateTimeImmutable $agora = null): array
{
    $hoje = ($agora ?? new DateTimeImmutable('now'))->setTime(0, 0);
    $chave = isset(relatorio_periodos()[$chave]) ? $chave : '30d';
    $anterior = null;
    switch ($chave) {
        case 'hoje':
            [$a, $b] = [$hoje, $hoje];
            break;
        case 'ontem':
            [$a, $b] = [$hoje->modify('-1 day'), $hoje->modify('-1 day')];
            break;
        case '7d':
            [$a, $b] = [$hoje->modify('-6 days'), $hoje];
            break;
        case '30d':
            [$a, $b] = [$hoje->modify('-29 days'), $hoje];
            break;
        case 'mes':
            [$a, $b] = [$hoje->modify('first day of this month'), $hoje];
            $anterior = [$a->modify('first day of previous month'), $a->modify('-1 day')];
            break;
        case 'mes_anterior':
            $a = $hoje->modify('first day of previous month');
            $b = $hoje->modify('first day of this month')->modify('-1 day');
            $anterior = [$a->modify('first day of previous month'), $a->modify('-1 day')];
            break;
        case 'trimestre':
            $m = (int) $hoje->format('n');
            $a = $hoje->setDate((int) $hoje->format('Y'), $m - (($m - 1) % 3), 1);
            $b = $hoje;
            $anterior = [$a->modify('-3 months'), $a->modify('-1 day')];
            break;
        case 'ano':
            $a = $hoje->setDate((int) $hoje->format('Y'), 1, 1);
            $b = $hoje;
            $anterior = [$a->modify('-1 year'), $a->modify('-1 day')];
            break;
        default: // personalizado
            $a = $ini ? DateTimeImmutable::createFromFormat('!Y-m-d', $ini) : false;
            $b = $fim ? DateTimeImmutable::createFromFormat('!Y-m-d', $fim) : false;
            if (!$a || !$b || $a->format('Y-m-d') !== $ini || $b->format('Y-m-d') !== $fim) {
                return ['erro' => 'Informe as duas datas do período.'];
            }
            if ($a > $b) {
                return ['erro' => 'A data inicial é depois da final.'];
            }
            if ($a->diff($b)->days > 366 * 3) {
                return ['erro' => 'Escolha um período de até 3 anos.'];
            }
    }
    $dias = $a->diff($b)->days + 1;
    if (!$anterior) {
        $anterior = [$a->modify('-' . $dias . ' days'), $a->modify('-1 day')];
    }
    $fmt = fn (DateTimeImmutable $x, DateTimeImmutable $y) => $x == $y ? $x->format('d/m/Y') : $x->format('d/m/Y') . ' a ' . $y->format('d/m/Y');
    return [
        'chave' => $chave, 'ini' => $a->format('Y-m-d 00:00:00'), 'fim' => $b->format('Y-m-d 23:59:59'), 'dias' => $dias,
        'rotulo' => relatorio_periodos()[$chave] . ' (' . $fmt($a, $b) . ')',
        'anterior' => ['ini' => $anterior[0]->format('Y-m-d 00:00:00'), 'fim' => $anterior[1]->format('Y-m-d 23:59:59'), 'rotulo' => $fmt($anterior[0], $anterior[1])],
    ];
}

// ===================================================================== Filtros

/** Dimensões aceitas: chave => [tabela para validar o id na empresa | null para valores fixos, rótulo]. */
function relatorio_dimensoes(): array
{
    return [
        'empreendimento' => ['empreendimentos', 'Empreendimento'],
        'plantao' => ['plantoes', 'Plantão'],
        'roleta' => ['roletas', 'Roleta'],
        'superintendencia' => ['superintendencias', 'Superintendência'],
        'gerente' => ['usuarios', 'Gerente'],
        'equipe' => ['equipes', 'Equipe'],
        'corretor' => ['usuarios', 'Corretor'],
        'origem' => ['lead_origens', 'Origem'],
        'etapa' => ['funil_etapas', 'Etapa atual'],
        'canal' => [null, 'Grupo de origem'],
        'campanha' => [null, 'Campanha (Meta)'],
        'conjunto' => [null, 'Conjunto de anúncios (Meta)'],
        'anuncio' => [null, 'Anúncio (Meta)'],
        'forma' => [null, 'Forma de recebimento'],
    ];
}

/**
 * Normaliza filtros vindos da tela/exportação. Cada dimensão aceita uma
 * lista. Id que não é da empresa, ou valor fixo inválido → 'vazio' = true
 * (o motor devolve resultado vazio, nunca "tudo").
 */
function relatorio_filtros(array $usuario, array $entrada, ?DateTimeImmutable $agora = null): array
{
    $empresaId = (int) $usuario['empresa_ativa_id'];
    $p = relatorio_periodo((string) ($entrada['periodo'] ?? '30d'), $entrada['ini'] ?? null, $entrada['fim'] ?? null, $agora);
    $f = ['empresa_id' => $empresaId, 'periodo' => $p, 'erro' => $p['erro'] ?? null, 'vazio' => false, 'dim' => []];
    foreach (relatorio_dimensoes() as $chave => [$tabela, $rotulo]) {
        $bruto = $entrada[$chave] ?? null;
        if ($bruto === null || $bruto === '' || $bruto === []) {
            continue;
        }
        $lista = array_values(array_unique(array_filter(array_map('trim', array_map('strval', (array) $bruto)), fn ($v) => $v !== '')));
        if (!$lista) {
            continue;
        }
        if ($tabela) {
            $ids = array_values(array_unique(array_map('intval', $lista)));
            if (in_array(0, $ids, true) || count($ids) !== count($lista)) {
                $f['vazio'] = true;
                continue;
            }
            if (in_array($tabela, ['plantoes', 'lead_origens'], true) && !modulo_tabela_existe($tabela)) {
                $f['vazio'] = true;
                continue;
            }
            $in = implode(',', $ids);
            $stmt = db()->prepare("SELECT COUNT(*) FROM {$tabela} WHERE empresa_id = :e AND id IN ({$in})");
            $stmt->execute(['e' => $empresaId]);
            if ((int) $stmt->fetchColumn() !== count($ids)) {
                $f['vazio'] = true;
            }
            $f['dim'][$chave] = $ids;
        } else {
            $validos = ['canal' => array_keys(lead_origens()), 'forma' => array_keys(historico_formas())][$chave] ?? null;
            if ($validos !== null && array_diff($lista, $validos)) {
                $f['vazio'] = true;
            }
            $f['dim'][$chave] = array_map(fn ($v) => mb_substr($v, 0, 64), $lista);
        }
    }
    return $f;
}

/** Gera placeholders únicos (:pfx0, :pfx1...) para IN (...). */
function motor_in(array $valores, string $pfx, array &$params): string
{
    $nomes = [];
    foreach (array_values($valores) as $i => $v) {
        $params[$pfx . $i] = $v;
        $nomes[] = ':' . $pfx . $i;
    }
    return implode(',', $nomes);
}

/**
 * WHERE para leads (alias `leads`): permissão de hoje + dimensões.
 * Estrutura/corretor/roleta/plantão/forma: o lead teve pelo menos uma
 * atribuição com essa estrutura NA DATA (histórico), não só o dono atual.
 */
function motor_where_leads(array $usuario, array $f): array
{
    $scope = rbac_leads_scope($usuario);
    $sql = [$scope['sql']];
    $params = $scope['params'];
    $d = $f['dim'];
    if (!empty($d['empreendimento'])) {
        $sql[] = 'leads.empreendimento_id IN (' . motor_in($d['empreendimento'], 'f_emp', $params) . ')';
    }
    motor_condicoes_lead($d, $params, 'leads', 'fl', $sql);
    $atr = motor_condicoes_atribuicao($d, $params, 'fa');
    if ($atr) {
        $sql[] = 'EXISTS (SELECT 1 FROM lead_atribuicoes fa WHERE fa.lead_id = leads.id AND ' . implode(' AND ', $atr) . ')';
    }
    return [implode(' AND ', $sql), $params];
}

/** Condições de estrutura/corretor/roleta/plantão/forma sobre uma linha de lead_atribuicoes (alias $a). */
function motor_condicoes_atribuicao(array $d, array &$params, string $a): array
{
    $c = [];
    foreach (['superintendencia' => 'superintendencia_id', 'gerente' => 'gerente_id', 'equipe' => 'equipe_id', 'corretor' => 'usuario_id', 'roleta' => 'roleta_id', 'forma' => 'forma'] as $k => $col) {
        if (!empty($d[$k])) {
            $c[] = "{$a}.{$col} IN (" . motor_in($d[$k], $a . '_' . $k, $params) . ')';
        }
    }
    if (!empty($d['plantao'])) {
        $c[] = "{$a}.roleta_id IN (SELECT id FROM roletas WHERE plantao_id IN (" . motor_in($d['plantao'], $a . '_pl', $params) . '))';
    }
    return $c;
}

/**
 * Permissão sobre atribuições (alias `a`) pela estrutura gravada na data.
 * Mesmo desenho de rbac_transferencias_scope().
 */
function motor_escopo_atribuicoes(array $usuario, string $a = 'a'): array
{
    $e = 'r' . $a . '_e';
    $u = 'r' . $a . '_u';
    $params = [$e => (int) $usuario['empresa_ativa_id']];
    $base = "{$a}.empresa_id = :{$e}";
    $uid = (int) $usuario['id'];
    switch ($usuario['escopo']) {
        case 'toda_empresa':
        case 'todos_produtos':
            return [$base, $params];
        case 'produtos_vinculados':
            $params[$u] = $uid;
            return [$base . " AND {$a}.empreendimento_id IN (SELECT empreendimento_id FROM usuario_empreendimentos WHERE usuario_id = :{$u})", $params];
        case 'superintendencia':
            $params[$u] = $uid;
            return [$base . " AND {$a}.superintendencia_id IN (SELECT id FROM superintendencias WHERE superintendente_id = :{$u})", $params];
        case 'equipe':
            $params[$u] = $uid;
            return [$base . " AND {$a}.equipe_id IN (SELECT id FROM equipes WHERE gerente_id = :{$u})", $params];
        default:
            $params[$u] = $uid;
            return [$base . " AND {$a}.usuario_id = :{$u}", $params];
    }
}

/**
 * WHERE de contratos (alias c + l): permissão pela estrutura gravada no
 * contrato; roleta/plantão/forma valem pela atribuição que levou o lead ao
 * corretor do contrato.
 */
function motor_where_contratos(array $usuario, array $f): array
{
    [$sql, $params] = motor_escopo_atribuicoes($usuario, 'c');
    $partes = [$sql, 'l.excluido_em IS NULL'];
    $d = $f['dim'];
    foreach (['superintendencia' => 'superintendencia_id', 'gerente' => 'gerente_id', 'equipe' => 'equipe_id', 'corretor' => 'usuario_id', 'empreendimento' => 'empreendimento_id'] as $k => $col) {
        if (!empty($d[$k])) {
            $partes[] = "c.{$col} IN (" . motor_in($d[$k], 'c_' . $k, $params) . ')';
        }
    }
    $extra = motor_condicoes_atribuicao(array_intersect_key($d, ['roleta' => 1, 'plantao' => 1, 'forma' => 1]), $params, 'ca');
    if ($extra) {
        $partes[] = 'EXISTS (SELECT 1 FROM lead_atribuicoes ca WHERE ca.lead_id = c.lead_id AND ca.usuario_id = c.usuario_id AND ' . implode(' AND ', $extra) . ')';
    }
    motor_condicoes_lead($d, $params, 'l', 'cl', $partes);
    return [implode(' AND ', $partes), $params];
}

/** Dimensões que são colunas do lead (origem, grupo, etapa, Meta) sobre o alias $l. */
function motor_condicoes_lead(array $d, array &$params, string $l, string $pfx, array &$partes): void
{
    foreach (['origem' => 'origem_detalhe_id', 'canal' => 'origem', 'etapa' => 'etapa_id', 'campanha' => 'meta_campanha_id', 'conjunto' => 'meta_adset_id', 'anuncio' => 'meta_ad_id'] as $k => $col) {
        if (!empty($d[$k])) {
            $partes[] = "{$l}.{$col} IN (" . motor_in($d[$k], $pfx . '_' . $k, $params) . ')';
        }
    }
}

/** WHERE completo de atribuições: permissão na data + dimensões + lead não excluído (alias a + l). */
function motor_where_atribuicoes(array $usuario, array $f): array
{
    [$sql, $params] = motor_escopo_atribuicoes($usuario);
    $partes = [$sql, 'l.excluido_em IS NULL'];
    $d = $f['dim'];
    $partes = array_merge($partes, motor_condicoes_atribuicao($d, $params, 'a'));
    if (!empty($d['empreendimento'])) {
        $partes[] = 'a.empreendimento_id IN (' . motor_in($d['empreendimento'], 'a_emp', $params) . ')';
    }
    motor_condicoes_lead($d, $params, 'l', 'al', $partes);
    return [implode(' AND ', $partes), $params];
}

// ===================================================================== Utilidades

function motor_um(string $sql, array $params)
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function motor_taxa(?int $num, ?int $den): ?float
{
    return ($num === null || !$den) ? null : round($num * 100 / $den, 1);
}

/** Mediana de uma lista de números (null se vazia). */
function motor_mediana(array $v): ?float
{
    $v = array_values(array_filter($v, fn ($x) => $x !== null));
    if (!$v) {
        return null;
    }
    sort($v);
    $n = count($v);
    return $n % 2 ? (float) $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
}

/** Resultado padrão de um indicador. */
function motor_res($valor, string $base = '', string $nota = '', array $extra = []): array
{
    return ['valor' => $valor, 'base' => $base, 'nota' => $nota] + $extra;
}

/** Desde quando o histórico é gravado ao vivo, e quanto do período foi reconstruído. */
function relatorio_cobertura_historico(array $usuario, array $f): array
{
    if (!historico_instalado()) {
        return ['instalado' => false, 'ao_vivo_desde' => null, 'reconstruidas' => 0, 'total' => 0];
    }
    $e = (int) $usuario['empresa_ativa_id'];
    $desde = motor_um('SELECT MIN(atribuido_em) FROM lead_atribuicoes WHERE empresa_id = :e AND reconstruido = 0', ['e' => $e]);
    [$w, $p] = motor_where_atribuicoes($usuario, $f);
    $stmt = db()->prepare("SELECT COUNT(*) AS total, SUM(a.reconstruido) AS rec FROM lead_atribuicoes a JOIN leads l ON l.id = a.lead_id
                           WHERE {$w} AND a.atribuido_em BETWEEN :ini AND :fim");
    $stmt->execute($p + ['ini' => $f['periodo']['ini'], 'fim' => $f['periodo']['fim']]);
    $r = $stmt->fetch();
    return ['instalado' => true, 'ao_vivo_desde' => $desde ?: null, 'reconstruidas' => (int) $r['rec'], 'total' => (int) $r['total']];
}

// ===================================================================== Indicadores de leads (coorte)

/** Chave de pessoa pelo telefone: só dígitos, com DDI 55 (10/11 dígitos ganham o 55). NULL se não houver telefone válido. */
function motor_sql_chave_telefone(string $col): string
{
    $d = lead_sql_telefone_digitos($col);
    return "CASE WHEN LENGTH({$d}) IN (10, 11) THEN CONCAT('55', {$d}) WHEN LENGTH({$d}) IN (12, 13) AND LEFT({$d}, 2) = '55' THEN {$d} ELSE NULL END";
}

/**
 * Indicadores centrais de leads para os leads que ENTRARAM no período
 * (coorte por leads.criado_em). Taxas sobre leads distribuídos.
 */
function relatorio_leads(array $usuario, array $f, ?array $periodo = null): array
{
    $per = $periodo ?? $f['periodo'];
    $vazio = $f['vazio'] || !empty($f['erro']);
    if ($vazio) {
        return relatorio_leads_vazio();
    }
    [$w, $p] = motor_where_leads($usuario, $f);
    $p += ['ini' => $per['ini'], 'fim' => $per['fim']];
    $h = historico_instalado();
    $stmt = db()->prepare("SELECT
            COUNT(*) AS recebidos,
            COUNT(DISTINCT COALESCE(" . motor_sql_chave_telefone('leads.telefone') . ", LOWER(NULLIF(leads.email, '')), CONCAT('id:', leads.id))) AS unicos,
            COALESCE(" . ($h ? 'SUM(EXISTS (SELECT 1 FROM lead_atribuicoes x WHERE x.lead_id = leads.id))' : 'SUM(leads.responsavel_id IS NOT NULL)') . ", 0) AS distribuidos,
            COALESCE(" . ($h ? 'SUM(EXISTS (SELECT 1 FROM lead_atribuicoes x WHERE x.lead_id = leads.id AND x.primeiro_atendimento_em IS NOT NULL))' : 'SUM(leads.primeiro_contato_em IS NOT NULL)') . ", 0) AS com_atuacao,
            " . ($h ? 'COALESCE(SUM(leads.primeiro_contato_efetivo_em IS NOT NULL), 0)' : 'NULL') . " AS contato_efetivo,
            " . ($h ? 'COALESCE(SUM(leads.chegada_roleta_aberta <=> 0), 0)' : 'NULL') . " AS fora_horario,
            " . ($h ? 'COALESCE(SUM(leads.chegada_roleta_aberta IS NOT NULL), 0)' : 'NULL') . " AS chegada_avaliada
        FROM leads WHERE {$w} AND leads.criado_em BETWEEN :ini AND :fim");
    $stmt->execute($p);
    $r = array_map(fn ($v) => $v === null ? null : (int) $v, $stmt->fetch());

    // Tempo até o 1º atendimento: na PRIMEIRA atribuição de cada lead (mediana, em minutos).
    $tempos = [];
    if ($h) {
        $stmt = db()->prepare("SELECT TIMESTAMPDIFF(MINUTE, a.atribuido_em, a.primeiro_atendimento_em) FROM leads
            JOIN lead_atribuicoes a ON a.id = (SELECT x.id FROM lead_atribuicoes x WHERE x.lead_id = leads.id AND x.forma <> 'mudanca_equipe' ORDER BY x.atribuido_em, x.id LIMIT 1)
            WHERE {$w} AND leads.criado_em BETWEEN :ini AND :fim AND a.primeiro_atendimento_em IS NOT NULL");
        $stmt->execute($p);
        $tempos = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    $notaH = $h ? '' : 'Histórico estruturado não instalado (migração 013): usando o dono atual.';
    return [
        'leads_recebidos' => motor_res($r['recebidos'], 'Leads com data de entrada no período.'),
        'leads_unicos' => motor_res($r['unicos'], 'Pessoas diferentes (telefone; sem telefone, e-mail).'),
        'leads_distribuidos' => motor_res($r['distribuidos'], 'Leads do período que chegaram a um corretor.', $notaH),
        'leads_com_atuacao' => motor_res($r['com_atuacao'], 'Leads distribuídos com 1ª atuação válida de um corretor.', $notaH),
        'contatos_efetivos' => motor_res($r['contato_efetivo'], 'Leads em que o cliente foi efetivamente alcançado.', $h ? '' : 'Requer a migração 013.'),
        'taxa_primeiro_contato' => motor_res(motor_taxa($r['com_atuacao'], $r['distribuidos']), 'Com atuação ÷ distribuídos.', '', ['unidade' => '%']),
        'taxa_contato_efetivo' => motor_res(motor_taxa($r['contato_efetivo'], $r['distribuidos']), 'Com contato efetivo ÷ distribuídos.', '', ['unidade' => '%']),
        'tempo_primeiro_contato' => motor_res(motor_mediana($tempos), 'Mediana, em minutos, da 1ª atribuição até a 1ª atuação.', count($tempos) . ' lead(s) na base.', ['unidade' => 'min']),
        'leads_fora_horario' => motor_res($r['chegada_avaliada'] ? $r['fora_horario'] : null, 'Leads que chegaram com a roleta fechada ou sem corretor no horário.',
            $r['chegada_avaliada'] !== null ? $r['chegada_avaliada'] . ' lead(s) com a chegada registrada (só a partir da migração 013).' : 'Requer a migração 013.'),
    ];
}

function relatorio_leads_vazio(): array
{
    $z = fn ($u = null) => motor_res(0, '', 'Nenhum resultado para os filtros escolhidos.', $u ? ['unidade' => $u] : []);
    $n = fn ($u = null) => motor_res(null, '', 'Nenhum resultado para os filtros escolhidos.', $u ? ['unidade' => $u] : []);
    return ['leads_recebidos' => $z(), 'leads_unicos' => $z(), 'leads_distribuidos' => $z(), 'leads_com_atuacao' => $z(), 'contatos_efetivos' => $z(),
            'taxa_primeiro_contato' => $n('%'), 'taxa_contato_efetivo' => $n('%'), 'tempo_primeiro_contato' => $n('min'), 'leads_fora_horario' => $z()];
}

/** Leads por origem detalhada (coorte do período). */
function relatorio_leads_por_origem(array $usuario, array $f): array
{
    if ($f['vazio'] || !empty($f['erro'])) {
        return [];
    }
    [$w, $p] = motor_where_leads($usuario, $f);
    $temOrigens = modulo_tabela_existe('lead_origens');
    $stmt = db()->prepare('SELECT ' . ($temOrigens ? "COALESCE(o.nome, 'Não informada')" : 'leads.origem') . ' AS origem, leads.origem AS canal, COUNT(*) AS leads'
        . ($temOrigens ? ', SUM(leads.primeiro_contato_efetivo_em IS NOT NULL) AS contato_efetivo' : '') . '
        FROM leads ' . ($temOrigens ? 'LEFT JOIN lead_origens o ON o.id = leads.origem_detalhe_id' : '') . "
        WHERE {$w} AND leads.criado_em BETWEEN :ini AND :fim GROUP BY 1, 2 ORDER BY leads DESC, origem");
    $stmt->execute($p + ['ini' => $f['periodo']['ini'], 'fim' => $f['periodo']['fim']]);
    return $stmt->fetchAll();
}

// ===================================================================== Corretores (atribuições)

/** Agrupamentos de atribuições: chave => [coluna em lead_atribuicoes/lead_contratos, rótulo, tabela do nome]. */
function relatorio_agrupamentos(): array
{
    return [
        'corretor' => ['usuario_id', 'Corretor', 'usuarios'],
        'equipe' => ['equipe_id', 'Equipe', 'equipes'],
        'gerente' => ['gerente_id', 'Gerente', 'usuarios'],
        'superintendencia' => ['superintendencia_id', 'Superintendência', 'superintendencias'],
        'empreendimento' => ['empreendimento_id', 'Empreendimento', 'empreendimentos'],
    ];
}

/**
 * Uma linha por grupo (corretor, equipe, gerente, superintendência ou
 * empreendimento) com o que foi RECEBIDO no período — pela data da
 * atribuição e pela estrutura daquela data — e o que foi feito nessas
 * atribuições, mais os contratos validados do grupo (data da assinatura).
 * Mudança de equipe não conta como recebimento. Grupo vazio (ex.: sem equipe
 * registrada) vira uma linha "Sem ... registrado(a)" com id null.
 */
function relatorio_agrupado(array $usuario, array $f, string $dim): array
{
    $ag = relatorio_agrupamentos()[$dim] ?? null;
    if (!$ag || $f['vazio'] || !empty($f['erro']) || !historico_instalado()) {
        return [];
    }
    [$col, $rotulo, $tabela] = $ag;
    [$w, $p] = motor_where_atribuicoes($usuario, $f);
    $p += ['ini' => $f['periodo']['ini'], 'fim' => $f['periodo']['fim'], 'ini2' => $f['periodo']['ini'], 'fim2' => $f['periodo']['fim']];
    $stmt = db()->prepare("SELECT a.{$col} AS id, n.nome,
            COUNT(*) AS recebimentos,
            COUNT(DISTINCT a.lead_id) AS leads_recebidos,
            SUM(a.forma = 'roleta') AS pela_roleta,
            SUM(a.forma = 'redistribuicao') AS por_redistribuicao,
            SUM(a.forma IN ('transferencia','oferta_ativa','cadastro','desconhecida')) AS outras_formas,
            COUNT(DISTINCT CASE WHEN a.primeiro_atendimento_em IS NOT NULL THEN a.lead_id END) AS atendidos,
            COUNT(DISTINCT CASE WHEN a.primeiro_contato_efetivo_em IS NOT NULL THEN a.lead_id END) AS contato_efetivo,
            COALESCE(SUM(a.motivo_encerramento <=> 'inatividade' AND COALESCE(a.encerrada_em BETWEEN :ini2 AND :fim2, 0)), 0) AS perdidos_inatividade,
            SUM(a.reconstruido) AS reconstruidas
        FROM lead_atribuicoes a JOIN leads l ON l.id = a.lead_id LEFT JOIN {$tabela} n ON n.id = a.{$col}
        WHERE {$w} AND a.forma <> 'mudanca_equipe' AND a.atribuido_em BETWEEN :ini AND :fim
        GROUP BY a.{$col}, n.nome ORDER BY leads_recebidos DESC, n.nome");
    $stmt->execute($p);
    $linhas = [];
    foreach ($stmt->fetchAll() as $r) {
        $r = array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, $r);
        $r['id'] = $r['id'] === null ? null : (int) $r['id'];
        $r['nome'] = $r['nome'] ?? ('Sem ' . mb_strtolower($rotulo) . ' registrado(a)');
        $r['taxa_atendimento'] = motor_taxa($r['atendidos'], $r['leads_recebidos']);
        $r['taxa_contato_efetivo'] = motor_taxa($r['contato_efetivo'], $r['leads_recebidos']);
        $linhas[(string) $r['id']] = $r;
    }
    // Tempo até o 1º atendimento (mediana, minutos) por grupo.
    $stmt = db()->prepare("SELECT a.{$col} AS id, TIMESTAMPDIFF(MINUTE, a.atribuido_em, a.primeiro_atendimento_em) AS m
        FROM lead_atribuicoes a JOIN leads l ON l.id = a.lead_id
        WHERE {$w} AND a.forma <> 'mudanca_equipe' AND a.atribuido_em BETWEEN :ini AND :fim AND a.primeiro_atendimento_em IS NOT NULL");
    $stmt->execute(array_diff_key($p, ['ini2' => 1, 'fim2' => 1]));
    $tempos = [];
    foreach ($stmt->fetchAll() as $t) {
        $tempos[(string) $t['id']][] = (int) $t['m'];
    }
    // Contratos validados do grupo (estrutura gravada no contrato).
    $contratos = [];
    if (modulo_tabela_existe('lead_contratos')) {
        [$wc, $pc] = motor_where_contratos($usuario, $f);
        $stmt = db()->prepare("SELECT c.{$col} AS id, n.nome, COUNT(*) AS contratos, SUM(c.valor_vgv) AS vgv, SUM(c.valor_vgv IS NOT NULL) AS com_valor
            FROM lead_contratos c JOIN leads l ON l.id = c.lead_id LEFT JOIN {$tabela} n ON n.id = c.{$col}
            WHERE {$wc} AND c.status = 'validado' AND c.assinado_em BETWEEN :ci AND :cf GROUP BY c.{$col}, n.nome");
        $stmt->execute($pc + ['ci' => substr($f['periodo']['ini'], 0, 10), 'cf' => substr($f['periodo']['fim'], 0, 10)]);
        foreach ($stmt->fetchAll() as $c) {
            $contratos[(string) $c['id']] = $c;
        }
    }
    $vazia = ['recebimentos' => 0, 'leads_recebidos' => 0, 'pela_roleta' => 0, 'por_redistribuicao' => 0, 'outras_formas' => 0, 'atendidos' => 0,
              'contato_efetivo' => 0, 'perdidos_inatividade' => 0, 'reconstruidas' => 0, 'taxa_atendimento' => null, 'taxa_contato_efetivo' => null];
    foreach ($contratos as $k => $c) {
        if (!isset($linhas[$k])) { // vendeu no período sem ter recebido lead no período
            $linhas[$k] = ['id' => $c['id'] === null ? null : (int) $c['id'], 'nome' => $c['nome'] ?? ('Sem ' . mb_strtolower($rotulo) . ' registrado(a)')] + $vazia;
        }
    }
    foreach ($linhas as $k => &$l) {
        $l['tempo_primeiro_atendimento_min'] = motor_mediana($tempos[$k] ?? []);
        $l['contratos_validados'] = (int) ($contratos[$k]['contratos'] ?? 0);
        $l['vgv'] = !empty($contratos[$k]['com_valor']) ? (float) $contratos[$k]['vgv'] : null;
        $l['vgv_contratos_com_valor'] = (int) ($contratos[$k]['com_valor'] ?? 0);
        if ($dim === 'corretor') {
            $l['usuario_id'] = $l['id'];
        }
    }
    unset($l);
    return array_values($linhas);
}

/** Compatibilidade: uma linha por corretor (ver relatorio_agrupado). */
function relatorio_corretores(array $usuario, array $f): array
{
    return relatorio_agrupado($usuario, $f, 'corretor');
}

/** Critérios de ranking: chave => [rótulo, menor_e_melhor, exige_base_minima]. */
function relatorio_criterios_ranking(): array
{
    return [
        'leads_recebidos' => ['Leads recebidos', false, false],
        'atendidos' => ['Leads atendidos', false, false],
        'taxa_atendimento' => ['Taxa de atendimento', false, true],
        'taxa_contato_efetivo' => ['Taxa de contato efetivo', false, true],
        'tempo_primeiro_atendimento_min' => ['Tempo até o 1º atendimento (menor é melhor)', true, true],
        'contratos_validados' => ['Contratos validados', false, false],
        'vgv' => ['VGV de contratos validados', false, false],
    ];
}

/**
 * Ordena as linhas pelo critério ESCOLHIDO (nunca um "índice" escondido).
 * Taxas e tempos só entram com pelo menos $baseMinima leads recebidos — com
 * 1 lead, 100% não é comparável a 40% em 50 leads. Empates dividem a posição.
 * Retorna ['ranking' => linhas com 'posicao', 'sem_base' => linhas fora do ranking].
 */
function relatorio_ranking(array $linhas, string $criterio, int $baseMinima = 5): array
{
    $c = relatorio_criterios_ranking()[$criterio] ?? null;
    if (!$c) {
        return ['ranking' => [], 'sem_base' => $linhas];
    }
    [, $menor, $exigeBase] = $c;
    $dentro = [];
    $fora = [];
    foreach ($linhas as $l) {
        $v = $l[$criterio] ?? null;
        if ($v === null || ($exigeBase && ($l['leads_recebidos'] ?? 0) < $baseMinima)) {
            $fora[] = $l;
        } else {
            $dentro[] = $l;
        }
    }
    usort($dentro, fn ($a, $b) => ($menor ? $a[$criterio] <=> $b[$criterio] : $b[$criterio] <=> $a[$criterio]) ?: strcmp((string) $a['nome'], (string) $b['nome']));
    $pos = 0;
    $ant = null;
    foreach ($dentro as $i => &$l) {
        if ($ant === null || $l[$criterio] != $ant) {
            $pos = $i + 1;
            $ant = $l[$criterio];
        }
        $l['posicao'] = $pos;
    }
    unset($l);
    return ['ranking' => $dentro, 'sem_base' => $fora];
}

// ===================================================================== Séries no tempo

function relatorio_granularidades(): array
{
    return ['dia' => 'Por dia', 'semana' => 'Por semana', 'mes' => 'Por mês'];
}

/** Granularidade padrão pelo tamanho do período. */
function relatorio_granularidade_padrao(array $periodo): string
{
    $dias = $periodo['dias'] ?? 30;
    return $dias <= 45 ? 'dia' : ($dias <= 200 ? 'semana' : 'mes');
}

/** Métricas de evolução: chave => rótulo. Cada uma pela data do PRÓPRIO evento. */
function relatorio_metricas_serie(): array
{
    return [
        'leads_recebidos' => 'Leads recebidos (data de entrada)',
        'recebimentos' => 'Leads entregues a corretores (data da atribuição)',
        'contatos_efetivos' => 'Contatos efetivos (data do contato)',
        'visitas_realizadas' => 'Visitas realizadas (data da visita)',
        'contratos_validados' => 'Contratos validados (data da assinatura)',
    ];
}

/**
 * Série no tempo de uma métrica, com todos os intervalos do período (zeros
 * explícitos). Retorna [['chave','rotulo','ini','total'], ...].
 */
function relatorio_serie(array $usuario, array $f, string $metrica, ?string $gran = null): array
{
    $gran = isset(relatorio_granularidades()[$gran ?? '']) ? $gran : relatorio_granularidade_padrao($f['periodo']);
    $baldes = relatorio_baldes($f['periodo'], $gran);
    if ($f['vazio'] || !empty($f['erro']) || !isset(relatorio_metricas_serie()[$metrica])) {
        return array_values($baldes);
    }
    $per = ['ini' => $f['periodo']['ini'], 'fim' => $f['periodo']['fim']];
    $h = historico_instalado();
    switch ($metrica) {
        case 'leads_recebidos':
            [$w, $p] = motor_where_leads($usuario, $f);
            $sql = "SELECT DATE(leads.criado_em) AS d, COUNT(*) AS n FROM leads WHERE {$w} AND leads.criado_em BETWEEN :ini AND :fim GROUP BY d";
            break;
        case 'recebimentos':
            if (!$h) {
                return array_values($baldes);
            }
            [$w, $p] = motor_where_atribuicoes($usuario, $f);
            $sql = "SELECT DATE(a.atribuido_em) AS d, COUNT(*) AS n FROM lead_atribuicoes a JOIN leads l ON l.id = a.lead_id
                    WHERE {$w} AND a.forma <> 'mudanca_equipe' AND a.atribuido_em BETWEEN :ini AND :fim GROUP BY d";
            break;
        case 'contatos_efetivos':
            if (!$h) {
                return array_values($baldes);
            }
            [$w, $p] = motor_where_atribuicoes($usuario, $f);
            $sql = "SELECT DATE(a.primeiro_contato_efetivo_em) AS d, COUNT(DISTINCT a.lead_id) AS n FROM lead_atribuicoes a JOIN leads l ON l.id = a.lead_id
                    WHERE {$w} AND a.primeiro_contato_efetivo_em BETWEEN :ini AND :fim GROUP BY d";
            break;
        case 'visitas_realizadas':
            [$w, $p] = motor_where_leads($usuario, $f);
            $sql = "SELECT DATE(ag.concluido_em) AS d, COUNT(*) AS n FROM agenda_compromissos ag JOIN leads ON leads.id = ag.lead_id
                    WHERE ag.tipo = 'visita' AND ag.status = 'realizado' AND {$w} AND ag.concluido_em BETWEEN :ini AND :fim GROUP BY d";
            break;
        default: // contratos_validados
            if (!modulo_tabela_existe('lead_contratos')) {
                return array_values($baldes);
            }
            [$w, $p] = motor_where_contratos($usuario, $f);
            $sql = "SELECT c.assinado_em AS d, COUNT(*) AS n FROM lead_contratos c JOIN leads l ON l.id = c.lead_id
                    WHERE {$w} AND c.status = 'validado' AND c.assinado_em BETWEEN :ini AND :fim GROUP BY d";
            $per = ['ini' => substr($per['ini'], 0, 10), 'fim' => substr($per['fim'], 0, 10)];
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($p + $per);
    foreach ($stmt->fetchAll() as $r) {
        $k = relatorio_balde_chave((string) $r['d'], $gran);
        if (isset($baldes[$k])) {
            $baldes[$k]['total'] += (int) $r['n'];
        }
    }
    return array_values($baldes);
}

function relatorio_balde_chave(string $data, string $gran): string
{
    $d = new DateTimeImmutable(substr($data, 0, 10));
    if ($gran === 'semana') {
        return $d->modify('monday this week')->format('Y-m-d');
    }
    return $gran === 'mes' ? $d->format('Y-m') : $d->format('Y-m-d');
}

/** Intervalos vazios do período (semana começa na segunda; o 1º e o último podem ser parciais). */
function relatorio_baldes(array $periodo, string $gran): array
{
    $ini = new DateTimeImmutable(substr($periodo['ini'] ?? date('Y-m-d'), 0, 10));
    $fim = new DateTimeImmutable(substr($periodo['fim'] ?? date('Y-m-d'), 0, 10));
    $meses = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    $b = [];
    for ($d = $ini; $d <= $fim; $d = $d->modify('+1 day')) {
        $k = relatorio_balde_chave($d->format('Y-m-d'), $gran);
        if (isset($b[$k])) {
            continue;
        }
        $rot = $gran === 'mes' ? $meses[(int) $d->format('n') - 1] . '/' . $d->format('y') : ($gran === 'semana' ? 'sem. ' . (new DateTimeImmutable($k))->format('d/m') : $d->format('d/m'));
        $b[$k] = ['chave' => $k, 'rotulo' => $rot, 'ini' => $gran === 'semana' ? max($k, $ini->format('Y-m-d')) : $d->format('Y-m-d'), 'total' => 0];
        if (count($b) > 400) {
            break;
        }
    }
    return $b;
}

/** Visitas de UM corretor (quem conduziu a visita), no escopo de agenda de quem vê. */
function relatorio_visitas_do_corretor(array $usuario, array $f, int $corretorId): array
{
    if ($f['vazio'] || !empty($f['erro'])) {
        return ['realizadas' => 0, 'ausentes' => 0, 'agendadas' => 0];
    }
    $s = rbac_agenda_scope($usuario);
    $stmt = db()->prepare("SELECT
            COALESCE(SUM(ag.status = 'realizado' AND COALESCE(ag.concluido_em BETWEEN :i1 AND :f1, 0)), 0) AS realizadas,
            COALESCE(SUM(ag.status = 'cliente_ausente' AND COALESCE(ag.concluido_em BETWEEN :i2 AND :f2, 0)), 0) AS ausentes,
            COALESCE(SUM(ag.criado_em BETWEEN :i3 AND :f3 AND ag.reagendado_de IS NULL), 0) AS agendadas
        FROM agenda_compromissos ag WHERE ag.tipo = 'visita' AND ag.usuario_id = :vc AND {$s['sql']}");
    $per = $f['periodo'];
    $stmt->execute($s['params'] + ['vc' => $corretorId, 'i1' => $per['ini'], 'f1' => $per['fim'], 'i2' => $per['ini'], 'f2' => $per['fim'], 'i3' => $per['ini'], 'f3' => $per['fim']]);
    return array_map('intval', $stmt->fetch());
}

/** Perdas por inatividade no período, pela estrutura de QUEM perdeu (encerramento da atribuição). */
function relatorio_perdas(array $usuario, array $f): array
{
    if ($f['vazio'] || !empty($f['erro']) || !historico_instalado()) {
        return ['perdidos_inatividade' => motor_res(0), 'redistribuidos' => motor_res(0), 'tempo_redistribuicao' => motor_res(null, '', '', ['unidade' => 'h'])];
    }
    [$w, $p] = motor_where_atribuicoes($usuario, $f);
    $p += ['ini' => $f['periodo']['ini'], 'fim' => $f['periodo']['fim']];
    $stmt = db()->prepare("SELECT a.lead_id, a.encerrada_em,
            (SELECT MIN(n.atribuido_em) FROM lead_atribuicoes n WHERE n.lead_id = a.lead_id AND n.atribuido_em >= a.encerrada_em AND n.id <> a.id) AS proxima
        FROM lead_atribuicoes a JOIN leads l ON l.id = a.lead_id
        WHERE {$w} AND a.motivo_encerramento = 'inatividade' AND a.encerrada_em BETWEEN :ini AND :fim");
    $stmt->execute($p);
    $rows = $stmt->fetchAll();
    $horas = array_map(fn ($r) => round((strtotime($r['proxima']) - strtotime($r['encerrada_em'])) / 3600, 1), array_filter($rows, fn ($r) => $r['proxima']));
    return [
        'perdidos_inatividade' => motor_res(count($rows), 'Atribuições encerradas por falta de feedback no período.'),
        'redistribuidos' => motor_res(count($horas), 'Dessas, quantas já foram entregues a outro corretor.'),
        'tempo_redistribuicao' => motor_res(motor_mediana($horas), 'Mediana, em horas, entre a perda e a nova atribuição.', '', ['unidade' => 'h']),
    ];
}

// ===================================================================== Visitas e contratos

function relatorio_visitas(array $usuario, array $f): array
{
    $vazio = $f['vazio'] || !empty($f['erro']);
    [$w, $p] = motor_where_leads($usuario, $f);
    $p += ['ini' => $f['periodo']['ini'], 'fim' => $f['periodo']['fim']];
    $conf = modulo_tabela_existe('lead_atribuicoes') ? "COALESCE(SUM(COALESCE(ag.confirmada_em BETWEEN :ini4 AND :fim4, 0)), 0)" : 'NULL';
    $stmt = db()->prepare("SELECT
            COALESCE(SUM(ag.criado_em BETWEEN :ini AND :fim AND ag.reagendado_de IS NULL), 0) AS agendadas,
            COALESCE(SUM(ag.status = 'realizado' AND COALESCE(ag.concluido_em BETWEEN :ini2 AND :fim2, 0)), 0) AS realizadas,
            COALESCE(SUM(ag.status = 'cliente_ausente' AND COALESCE(ag.concluido_em BETWEEN :ini3 AND :fim3, 0)), 0) AS ausentes,
            COALESCE(SUM(ag.status = 'cancelado' AND COALESCE(ag.concluido_em BETWEEN :ini5 AND :fim5, 0)), 0) AS canceladas,
            {$conf} AS confirmadas
        FROM agenda_compromissos ag JOIN leads ON leads.id = ag.lead_id
        WHERE ag.tipo = 'visita' AND {$w}");
    foreach (['2', '3', '4', '5'] as $s) {
        $p['ini' . $s] = $p['ini'];
        $p['fim' . $s] = $p['fim'];
    }
    if ($conf === 'NULL') {
        unset($p['ini4'], $p['fim4']);
    }
    if ($vazio) {
        $r = array_fill_keys(['agendadas', 'realizadas', 'ausentes', 'canceladas', 'confirmadas'], 0);
    } else {
        $stmt->execute($p);
        $r = $stmt->fetch();
    }
    $r = array_map(fn ($v) => $v === null ? null : (int) $v, $r);
    return [
        'visitas_agendadas' => motor_res($r['agendadas'], 'Visitas criadas no período (sem contar reagendamentos).'),
        'visitas_confirmadas' => motor_res($r['confirmadas'], 'Visitas que o cliente confirmou no período.', $conf === 'NULL' ? 'Requer a migração 013.' : ''),
        'visitas_realizadas' => motor_res($r['realizadas'], 'Visitas concluídas como realizadas no período.'),
        'visitas_ausentes' => motor_res($r['ausentes'], 'Cliente não compareceu.'),
        'visitas_canceladas' => motor_res($r['canceladas'], 'Visitas canceladas no período.'),
        'taxa_comparecimento' => motor_res(motor_taxa($r['realizadas'], ($r['realizadas'] ?? 0) + ($r['ausentes'] ?? 0)), 'Realizadas ÷ (realizadas + ausentes).', '', ['unidade' => '%']),
    ];
}

/**
 * Contratos pela data de assinatura. "Validados" é a venda formal; o sinal
 * do Kanban (entrada na etapa "Contrato assinado") vem separado, nunca somado.
 * Permissão: corretor/estrutura gravados no contrato (na data da assinatura).
 */
function relatorio_contratos(array $usuario, array $f): array
{
    $vazioR = ['contratos_validados' => motor_res(0), 'contratos_aguardando' => motor_res(0), 'distratos' => motor_res(0),
               'vgv' => motor_res(null, '', '', ['unidade' => 'R$']), 'contratos_pela_etapa' => motor_res(0)];
    if ($f['vazio'] || !empty($f['erro'])) {
        return $vazioR;
    }
    if (!modulo_tabela_existe('lead_contratos')) {
        return ['contratos_validados' => motor_res(null, '', 'Requer a migração 013.')] + $vazioR;
    }
    [$w, $p] = motor_where_contratos($usuario, $f);
    $ini = substr($f['periodo']['ini'], 0, 10);
    $fim = substr($f['periodo']['fim'], 0, 10);
    foreach (['1', '2', '3', '4', '5'] as $n) {
        $p['ini' . $n] = $ini;
        $p['fim' . $n] = $fim;
    }
    $stmt = db()->prepare("SELECT
            SUM(c.status = 'validado' AND c.assinado_em BETWEEN :ini1 AND :fim1) AS validados,
            SUM(c.status = 'registrado' AND c.assinado_em BETWEEN :ini2 AND :fim2) AS aguardando,
            SUM(c.status = 'distratado' AND c.distratado_em BETWEEN :ini3 AND :fim3) AS distratos,
            SUM(CASE WHEN c.status = 'validado' AND c.assinado_em BETWEEN :ini4 AND :fim4 THEN c.valor_vgv END) AS vgv,
            SUM(c.status = 'validado' AND c.assinado_em BETWEEN :ini5 AND :fim5 AND c.valor_vgv IS NOT NULL) AS com_valor
        FROM lead_contratos c JOIN leads l ON l.id = c.lead_id WHERE {$w}");
    $stmt->execute($p);
    $r = $stmt->fetch();
    $validados = (int) $r['validados'];
    $comValor = (int) $r['com_valor'];
    $vgvNota = $validados ? $comValor . ' de ' . $validados . ' contrato(s) validado(s) com valor informado.' : '';

    // Sinal do Kanban (não é venda validada): leads que entraram na etapa "Contrato assinado" no período.
    $pelaEtapa = null;
    if (historico_instalado()) {
        [$wl, $pl] = motor_where_leads($usuario, $f);
        $pelaEtapa = (int) motor_um("SELECT COUNT(DISTINCT h.lead_id) FROM lead_etapa_historico h JOIN leads ON leads.id = h.lead_id
            WHERE {$wl} AND h.para_categoria = 'contrato_assinado' AND h.ocorrido_em BETWEEN :ini AND :fim", $pl + ['ini' => $f['periodo']['ini'], 'fim' => $f['periodo']['fim']]);
    }
    return [
        'contratos_validados' => motor_res($validados, 'Contratos registrados e validados, pela data de assinatura.'),
        'contratos_aguardando' => motor_res((int) $r['aguardando'], 'Registrados e ainda não validados (não contam como venda).'),
        'distratos' => motor_res((int) $r['distratos'], 'Distratos registrados no período.'),
        'vgv' => motor_res($comValor ? (float) $r['vgv'] : null, 'Soma do valor dos contratos validados (VGV não é receita).', $comValor ? $vgvNota : 'Nenhum contrato validado com valor informado.', ['unidade' => 'R$']),
        'contratos_pela_etapa' => motor_res($pelaEtapa, 'Sinal do Kanban: leads movidos para "Contrato assinado". NÃO é venda validada.'),
    ];
}

// ===================================================================== Funil

/**
 * Movimentação do funil no período (lead_etapa_historico): transições por
 * origem (usuário/jornada/sistema), avanços e retrocessos pela ORDEM linear
 * das categorias (lead_etapas_funil), e tempo mediano em cada categoria para
 * permanências que terminaram no período.
 */
function relatorio_funil(array $usuario, array $f): array
{
    if ($f['vazio'] || !empty($f['erro']) || !historico_instalado()) {
        return ['transicoes' => 0, 'avancos' => 0, 'retrocessos' => 0, 'por_origem' => [], 'tempo_etapa_horas' => []];
    }
    [$w, $p] = motor_where_leads($usuario, $f);
    $stmt = db()->prepare("SELECT h.lead_id, h.de_categoria, h.para_categoria, h.origem, h.ocorrido_em FROM lead_etapa_historico h JOIN leads ON leads.id = h.lead_id
        WHERE {$w} AND h.ocorrido_em BETWEEN :ini AND :fim ORDER BY h.lead_id, h.ocorrido_em, h.id");
    $stmt->execute($p + ['ini' => $f['periodo']['ini'], 'fim' => $f['periodo']['fim']]);
    $ordem = array_flip(lead_etapas_funil());
    $res = ['transicoes' => 0, 'avancos' => 0, 'retrocessos' => 0, 'por_origem' => ['usuario' => 0, 'jornada' => 0, 'sistema' => 0]];
    foreach ($stmt->fetchAll() as $t) {
        $res['transicoes']++;
        $res['por_origem'][$t['origem']]++;
        if (isset($ordem[$t['de_categoria']], $ordem[$t['para_categoria']])) {
            if ($ordem[$t['para_categoria']] > $ordem[$t['de_categoria']]) {
                $res['avancos']++;
            } elseif ($ordem[$t['para_categoria']] < $ordem[$t['de_categoria']]) {
                $res['retrocessos']++;
            }
        }
    }
    // Permanência: entrada numa categoria (transição anterior) até a saída (transição seguinte, no período).
    $stmt = db()->prepare("SELECT h.lead_id, h.para_categoria, h.ocorrido_em,
            (SELECT MIN(n.ocorrido_em) FROM lead_etapa_historico n WHERE n.lead_id = h.lead_id AND (n.ocorrido_em > h.ocorrido_em OR (n.ocorrido_em = h.ocorrido_em AND n.id > h.id))) AS saiu
        FROM lead_etapa_historico h JOIN leads ON leads.id = h.lead_id WHERE {$w} AND h.ocorrido_em <= :fim");
    $stmt->execute($p + ['fim' => $f['periodo']['fim']]);
    $dur = [];
    foreach ($stmt->fetchAll() as $r) {
        if ($r['saiu'] && $r['saiu'] >= $f['periodo']['ini'] && $r['saiu'] <= $f['periodo']['fim']) {
            $dur[$r['para_categoria']][] = (strtotime($r['saiu']) - strtotime($r['ocorrido_em'])) / 3600;
        }
    }
    $res['tempo_etapa_horas'] = [];
    foreach ($dur as $cat => $v) {
        $res['tempo_etapa_horas'][$cat] = ['mediana' => round(motor_mediana($v), 1), 'permanencias' => count($v)];
    }
    return $res;
}

// ===================================================================== Visão executiva

/**
 * Indicadores principais do período e do período anterior (comparação só
 * quando a base anterior existe e não é zero — senão a variação fica null).
 */
function relatorio_executivo(array $usuario, array $f): array
{
    $atual = relatorio_leads($usuario, $f) + relatorio_visitas($usuario, $f) + relatorio_contratos($usuario, $f);
    $fa = $f;
    $fa['periodo'] = $f['periodo']['anterior'] ?? null;
    $anterior = $fa['periodo'] && empty($f['erro']) ? relatorio_leads($usuario, $fa) + relatorio_visitas($usuario, $fa) + relatorio_contratos($usuario, $fa) : [];
    foreach ($atual as $k => &$ind) {
        $ant = $anterior[$k]['valor'] ?? null;
        $ind['anterior'] = $ant;
        $ind['variacao'] = null;
        if ($ind['valor'] !== null && $ant !== null) {
            if (($ind['unidade'] ?? '') === '%') {
                $ind['variacao'] = round($ind['valor'] - $ant, 1);           // pontos percentuais
                $ind['variacao_unidade'] = 'p.p.';
            } elseif ($ant > 0) {
                $ind['variacao'] = round(($ind['valor'] - $ant) * 100 / $ant, 1);
                $ind['variacao_unidade'] = '%';
            }
        }
    }
    return ['periodo' => $f['periodo'], 'indicadores' => $atual, 'cobertura' => relatorio_cobertura_historico($usuario, $f)];
}
