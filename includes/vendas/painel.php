<?php
/**
 * Inteligência de Vendas — Fase 5: painel e relatórios.
 *
 * vendas_fatos() monta, em poucas consultas em lote, um "fato" por venda
 * (vínculo, evidência, 1º lead, interesses, oferta ativa, remarketing,
 * transferências, responsáveis na data da venda, classificação comercial,
 * dias de jornada). Todos os indicadores e relatórios saem desses fatos —
 * uma fonte só, a mesma regra da Jornada (vendas_classificar_comercial).
 *
 * Só números agregados e dados da venda importada: nada de linha do tempo
 * individual aqui. Nada é gravado.
 */
require_once __DIR__ . '/jornada.php';
require_once __DIR__ . '/../relatorios/motor.php';

const VENDAS_FAIXAS_JORNADA = [[0, 30, 'até 30 dias'], [31, 90, '31 a 90 dias'], [91, 180, '91 a 180 dias'], [181, 365, '181 a 365 dias'], [366, PHP_INT_MAX, 'mais de 1 ano']];
/** Classificações que contam como "venda por outro canal com histórico anterior no CRM". */
const VENDAS_OUTRO_CANAL = ['outro_corretor_mesma_estrutura', 'outra_equipe', 'canal_externo'];

/** Filtros do painel a partir da URL (validados). */
function vendas_painel_filtros(int $empresaId, array $get): array
{
    $p = relatorio_periodo((string) ($get['periodo'] ?? 'ano'), $get['ini'] ?? null, $get['fim'] ?? null);
    $int = fn ($k) => max(0, (int) ($get[$k] ?? 0)) ?: null;
    $sits = array_keys(vendas_situacoes());
    $s = (string) ($get['situacao'] ?? '');
    return ['periodo' => $p, 'periodo_chave' => (string) ($get['periodo'] ?? 'ano'), 'empreendimento' => $int('empreendimento'), 'equipe' => $int('equipe'),
            'corretor' => $int('corretor'), 'origem' => $int('origem'), 'situacao' => in_array($s, $sits, true) ? $s : null, 'empresa_id' => $empresaId];
}

/** IN (...) seguro para inteiros. */
function vendas_in(array $ids): string
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    return $ids ? implode(',', $ids) : '0';
}

/**
 * Fatos por venda (só vendas que contam como venda: eh_venda = 1) do período/filtros.
 * Retorna ['fatos' => [...], 'filtros' => $f, 'erro' => null|string].
 */
function vendas_fatos(array $f): array
{
    $empresaId = (int) $f['empresa_id'];
    if (!vendas_cruzamento_instalado()) {
        return ['fatos' => [], 'filtros' => $f, 'erro' => 'Rode as migrações 017 e 018.'];
    }
    if (!empty($f['periodo']['erro'])) {
        return ['fatos' => [], 'filtros' => $f, 'erro' => $f['periodo']['erro']];
    }
    $pdo = db();
    $where = 'v.empresa_id = :e AND v.eh_venda = 1 AND v.data_venda BETWEEN :ini AND :fim';
    $par = ['e' => $empresaId, 'ini' => $f['periodo']['ini'], 'fim' => $f['periodo']['fim']];
    if ($f['empreendimento']) {
        $where .= ' AND v.empreendimento_id = :emp';
        $par['emp'] = $f['empreendimento'];
    }
    $st = $pdo->prepare("SELECT v.id, v.chave_origem, v.venda_rm, v.data_venda, v.valor, v.cliente_nome, v.empreendimento_id, v.empreendimento_texto, v.midia_texto,
                                v.corretor_texto, v.corretor_id_origem, v.empresa_id, v.sistema_origem, e.nome AS emp_crm,
                                c.status, c.decisao, c.vinculada, c.cronologia, c.primeira_entrada_em
                         FROM vendas_externas v LEFT JOIN vendas_correspondencias c ON c.venda_id = v.id LEFT JOIN empreendimentos e ON e.id = v.empreendimento_id
                         WHERE $where ORDER BY v.data_venda DESC, v.id DESC");
    $st->execute($par);
    $vendas = $st->fetchAll();
    if (!$vendas) {
        return ['fatos' => [], 'filtros' => $f, 'erro' => null];
    }
    $vIds = vendas_in(array_column($vendas, 'id'));
    $q = fn (string $sql) => $pdo->query($sql)->fetchAll();

    $links = [];
    foreach ($q("SELECT venda_id, lead_id, evidencia FROM vendas_correspondencia_leads WHERE papel = 'vinculado' AND venda_id IN ($vIds)") as $l) {
        $links[(int) $l['venda_id']][(int) $l['lead_id']] = $l['evidencia'];
    }
    $leadIds = [];
    foreach ($links as $ls) {
        $leadIds = array_merge($leadIds, array_keys($ls));
    }
    $lIn = vendas_in($leadIds);
    $leads = [];
    foreach ($q("SELECT l.id, l.empreendimento_id, l.criado_em, l.meta_cadastro_em, l.origem, l.origem_detalhe_id, l.oferta_lista_id, l.meta_anuncio_id,
                        l.utm_campaign, l.campanha, o.nome AS origem_nome
                 FROM leads l LEFT JOIN lead_origens o ON o.id = l.origem_detalhe_id WHERE l.empresa_id = $empresaId AND l.excluido_em IS NULL AND l.id IN ($lIn)") as $l) {
        $l['entrada_em'] = min(array_filter([$l['criado_em'], $l['meta_cadastro_em']]));
        $leads[(int) $l['id']] = $l;
    }
    $atrib = $transf = $camp = [];
    if (modulo_tabela_existe('lead_atribuicoes')) {
        foreach ($q("SELECT lead_id, usuario_id, equipe_id, forma, atribuido_em, encerrada_em FROM lead_atribuicoes WHERE empresa_id = $empresaId AND lead_id IN ($lIn) ORDER BY atribuido_em, id") as $a) {
            $atrib[(int) $a['lead_id']][] = $a;
        }
    }
    if (modulo_tabela_existe('lead_transferencias')) {
        foreach ($q("SELECT lead_id, motivo, criado_em FROM lead_transferencias WHERE empresa_id = $empresaId AND lead_id IN ($lIn)") as $t) {
            $transf[(int) $t['lead_id']][] = $t;
        }
    }
    if (modulo_tabela_existe('whatsapp_campanha_destinatarios')) {
        foreach ($q("SELECT d.lead_id, COALESCE(m.enviada_em, d.atualizado_em) em FROM whatsapp_campanha_destinatarios d JOIN whatsapp_campanhas c ON c.id = d.campanha_id
                     LEFT JOIN whatsapp_mensagens m ON m.id = d.mensagem_id
                     WHERE c.empresa_id = $empresaId AND d.lead_id IN ($lIn) AND d.status IN ('enviada','entregue','lida','respondida')") as $c) {
            $camp[(int) $c['lead_id']][] = $c['em'];
        }
    }
    $anuncios = [];
    $anIds = array_filter(array_column($leads, 'meta_anuncio_id'));
    if ($anIds && modulo_tabela_existe('meta_anuncios')) {
        foreach ($q('SELECT id, nome, campanha_nome, adset_nome FROM meta_anuncios WHERE empresa_id = ' . $empresaId . ' AND id IN (' . vendas_in($anIds) . ')') as $a) {
            $anuncios[(int) $a['id']] = $a;
        }
    }
    $equivs = [];
    foreach ($q("SELECT * FROM vendas_equivalencias WHERE empresa_id = $empresaId AND tipo = 'corretor'") as $x) {
        $equivs[$x['sistema_origem']][$x['valor_origem']] = $x;
    }
    $equipeDe = [];
    foreach ($q("SELECT id, equipe_id FROM usuarios WHERE empresa_id = $empresaId") as $u) {
        $equipeDe[(int) $u['id']] = $u['equipe_id'] ? (int) $u['equipe_id'] : null;
    }
    $canais = ['meta_ads' => 'Meta Ads', 'manual' => 'Cadastro manual', 'importacao' => 'Importação de planilha', 'indicacao' => 'Indicação', 'outro' => 'Outra origem'];

    $fatos = [];
    foreach ($vendas as $v) {
        $vid = (int) $v['id'];
        $sit = vendas_situacao_efetiva($v['status'] !== null ? $v : null);
        $vinc = (bool) (int) $v['vinculada'];
        $vendaFim = substr($v['data_venda'], 0, 10) . ' 23:59:59';
        $meus = array_intersect_key($leads, $links[$vid] ?? []);
        uasort($meus, fn ($a, $b) => strcmp($a['entrada_em'], $b['entrada_em']) ?: $a['id'] <=> $b['id']);
        $antes = array_filter($meus, fn ($l) => $l['entrada_em'] <= $vendaFim);
        $primeiro = $meus ? reset($meus) : null;
        $ev = array_values($links[$vid] ?? []);
        $evid = in_array('telefone_email', $ev, true) || (in_array('telefone', $ev, true) && in_array('email', $ev, true)) ? 'ambos'
            : (in_array('telefone', $ev, true) ? 'telefone' : (in_array('email', $ev, true) ? 'email' : ($ev ? 'manual' : null)));
        $resp = [];
        $primeiroCorretor = null;
        $redistrib = false;
        $nTransf = 0;
        $remark = [];
        $oferta = false;
        foreach ($meus as $lid => $l) {
            if ($l['oferta_lista_id']) {
                $oferta = true;
            }
            foreach ($atrib[$lid] ?? [] as $a) {
                if ($a['atribuido_em'] > $vendaFim) {
                    continue;
                }
                if ($primeiroCorretor === null || $a['atribuido_em'] < $primeiroCorretor[1]) {
                    $primeiroCorretor = [(int) $a['usuario_id'], $a['atribuido_em']];
                }
                $oferta = $oferta || $a['forma'] === 'oferta_ativa';
                $redistrib = $redistrib || $a['forma'] === 'redistribuicao';
                if (!$a['encerrada_em'] || $a['encerrada_em'] > $vendaFim) {
                    $resp[] = ['usuario_id' => (int) $a['usuario_id'], 'equipe_id' => $a['equipe_id'] ? (int) $a['equipe_id'] : null];
                }
            }
            foreach ($transf[$lid] ?? [] as $t) {
                if ($t['criado_em'] <= $vendaFim) {
                    $nTransf++;
                    $redistrib = $redistrib || $t['motivo'] === 'inatividade';
                }
            }
            foreach ($camp[$lid] ?? [] as $em) {
                if ($em && $em <= $vendaFim) {
                    $remark[] = $em;
                }
            }
        }
        $equiv = $equivs[$v['sistema_origem']][vendas_chave_corretor($v)] ?? null;
        $classe = vendas_classificar_comercial($sit, $vinc, $v['cronologia'], $equiv, $resp, $equiv && $equiv['usuario_id'] ? ($equipeDe[(int) $equiv['usuario_id']] ?? null) : null);
        $emps = array_values(array_unique(array_filter(array_map(fn ($l) => $l['empreendimento_id'] ? (int) $l['empreendimento_id'] : null, $antes))));
        $empComprado = $v['empreendimento_id'] ? (int) $v['empreendimento_id'] : null;
        $encontrada = $vinc && in_array($sit, ['forte', 'telefone', 'email', 'confirmada'], true) && in_array($v['cronologia'], ['anterior', 'mesmo_dia'], true);
        $an = $primeiro && $primeiro['meta_anuncio_id'] ? ($anuncios[(int) $primeiro['meta_anuncio_id']] ?? null) : null;
        $fatos[] = [
            'id' => $vid, 'chave' => $v['chave_origem'], 'rm' => $v['venda_rm'], 'data' => $v['data_venda'], 'valor' => (float) $v['valor'], 'cliente' => $v['cliente_nome'],
            'emp_comprado_id' => $empComprado, 'emp_comprado' => $v['emp_crm'] ?: $v['empreendimento_texto'], 'midia' => $v['midia_texto'], 'corretor_venda' => $v['corretor_texto'],
            'situacao' => $sit, 'vinculada' => $vinc, 'cronologia' => $v['cronologia'], 'encontrada' => $encontrada, 'posterior' => $vinc && $v['cronologia'] === 'posterior',
            'evidencia' => $evid, 'n_leads' => count($meus),
            'primeira_entrada' => $v['primeira_entrada_em'],
            'dias_jornada' => $encontrada ? vendas_dias_entre($v['primeira_entrada_em'], $v['data_venda']) : null,
            'origem_id' => $primeiro && $primeiro['origem_detalhe_id'] ? (int) $primeiro['origem_detalhe_id'] : null,
            'origem' => $primeiro ? ($primeiro['origem_nome'] ?: ($canais[$primeiro['origem']] ?? $primeiro['origem'])) : null,
            'campanha' => $an['campanha_nome'] ?? ($primeiro ? (trim((string) ($primeiro['utm_campaign'] ?: $primeiro['campanha'])) ?: null) : null),
            'anuncio' => $an['nome'] ?? null, 'anuncio_id' => $an ? (int) $an['id'] : null,
            'emp_inicial_id' => $emps[0] ?? null, 'interesses' => $emps,
            'movimento' => !$encontrada ? null : (!$empComprado ? 'sem_equivalencia' : (!$emps ? 'sem_interesse' : ($emps[0] === $empComprado ? 'mesmo' : 'diferente'))),
            'oferta' => $encontrada && $oferta, 'remarketing' => $encontrada ? count($remark) : 0, 'ultima_campanha' => $remark ? max($remark) : null,
            'transferencias' => $nTransf, 'redistribuicao' => $encontrada && ($redistrib || $nTransf > 0),
            'responsaveis' => $resp, 'primeiro_corretor_id' => $primeiroCorretor[0] ?? null, 'classificacao' => $classe,
            'outro_canal' => $encontrada && in_array($classe, VENDAS_OUTRO_CANAL, true),
        ];
    }
    // Filtros que dependem dos fatos.
    $fatos = array_values(array_filter($fatos, function ($x) use ($f) {
        if ($f['situacao'] && $x['situacao'] !== $f['situacao']) {
            return false;
        }
        if ($f['origem'] && $x['origem_id'] !== $f['origem']) {
            return false;
        }
        if ($f['corretor'] && !in_array($f['corretor'], array_column($x['responsaveis'], 'usuario_id'), true)) {
            return false;
        }
        if ($f['equipe'] && !in_array($f['equipe'], array_column($x['responsaveis'], 'equipe_id'), true)) {
            return false;
        }
        return true;
    }));
    return ['fatos' => $fatos, 'filtros' => $f, 'erro' => null];
}

/** Média e mediana de uma lista de números (null se vazia). */
function vendas_media_mediana(array $n): array
{
    $n = array_values(array_filter($n, fn ($x) => $x !== null));
    if (!$n) {
        return [null, null];
    }
    sort($n);
    $c = count($n);
    return [round(array_sum($n) / $c, 1), $c % 2 ? $n[intdiv($c, 2)] : ($n[$c / 2 - 1] + $n[$c / 2]) / 2];
}

/** Indicadores principais (§14). */
function vendas_indicadores(array $fatos): array
{
    $c = fn (callable $p) => count(array_filter($fatos, $p));
    $soma = fn (callable $p) => array_sum(array_map(fn ($x) => $x['valor'], array_filter($fatos, $p)));
    $enc = fn ($x) => $x['encontrada'];
    [$media, $mediana] = vendas_media_mediana(array_column(array_filter($fatos, $enc), 'dias_jornada'));
    return [
        'vendas' => count($fatos),
        'encontradas' => $c($enc),
        'sem' => $c(fn ($x) => in_array($x['situacao'], ['sem', 'rejeitada', 'pendente'], true)),
        'ambiguas' => $c(fn ($x) => $x['situacao'] === 'ambigua'),
        'por_telefone' => $c(fn ($x) => $x['encontrada'] && $x['evidencia'] === 'telefone'),
        'por_email' => $c(fn ($x) => $x['encontrada'] && $x['evidencia'] === 'email'),
        'por_ambos' => $c(fn ($x) => $x['encontrada'] && $x['evidencia'] === 'ambos'),
        'por_revisao' => $c(fn ($x) => $x['encontrada'] && $x['situacao'] === 'confirmada'),
        'multiplas_oportunidades' => $c(fn ($x) => $x['encontrada'] && $x['n_leads'] > 1),
        'lead_anterior' => $c($enc),
        'lead_posterior' => $c(fn ($x) => $x['posterior']),
        'empreendimento_diferente' => $c(fn ($x) => $x['movimento'] === 'diferente'),
        'oferta_ativa' => $c(fn ($x) => $x['oferta']),
        'remarketing' => $c(fn ($x) => $x['remarketing'] > 0),
        'redistribuicao' => $c(fn ($x) => $x['redistribuicao']),
        'outro_canal' => $c(fn ($x) => $x['outro_canal']),
        'valor_total' => $soma(fn ($x) => true),
        'valor_encontradas' => $soma($enc),
        'jornada_media' => $media,
        'jornada_mediana' => $mediana,
        'pct_encontradas' => $fatos ? round(100 * $c($enc) / count($fatos), 1) : null,
    ];
}

/** Agrupa fatos por uma chave; devolve linhas [chave, vendas, encontradas, valor, valor_encontradas] ordenadas por vendas. */
function vendas_agrupar(array $fatos, callable $chave): array
{
    $g = [];
    foreach ($fatos as $x) {
        $k = $chave($x) ?? '(não informado)';
        $g[$k] ??= ['chave' => $k, 'vendas' => 0, 'encontradas' => 0, 'valor' => 0.0, 'valor_encontradas' => 0.0, 'dias' => []];
        $g[$k]['vendas']++;
        $g[$k]['valor'] += $x['valor'];
        if ($x['encontrada']) {
            $g[$k]['encontradas']++;
            $g[$k]['valor_encontradas'] += $x['valor'];
            $g[$k]['dias'][] = $x['dias_jornada'];
        }
    }
    foreach ($g as &$l) {
        $l['pct'] = $l['vendas'] ? round(100 * $l['encontradas'] / $l['vendas'], 1) : null;
        [$l['dias_media'], $l['dias_mediana']] = vendas_media_mediana($l['dias']);
        unset($l['dias']);
    }
    unset($l);
    usort($g, fn ($a, $b) => $b['vendas'] <=> $a['vendas'] ?: strcmp((string) $a['chave'], (string) $b['chave']));
    return $g;
}

/** Faixas de duração da jornada (só vendas encontradas). */
function vendas_faixas_jornada(array $fatos): array
{
    $out = [];
    foreach (VENDAS_FAIXAS_JORNADA as [$de, $ate, $rot]) {
        $out[] = ['faixa' => $rot, 'vendas' => count(array_filter($fatos, fn ($x) => $x['encontrada'] && $x['dias_jornada'] !== null && $x['dias_jornada'] >= $de && $x['dias_jornada'] <= $ate))];
    }
    return $out;
}

/** Movimentos entre empreendimentos (1º interesse → comprado), só vendas encontradas com os dois lados conhecidos. */
function vendas_movimentos(array $fatos, array $nomesEmp): array
{
    $m = [];
    foreach ($fatos as $x) {
        if (!in_array($x['movimento'], ['mesmo', 'diferente'], true)) {
            continue;
        }
        $k = $x['emp_inicial_id'] . '>' . $x['emp_comprado_id'];
        $m[$k] ??= ['de' => $nomesEmp[$x['emp_inicial_id']] ?? '#' . $x['emp_inicial_id'], 'para' => $nomesEmp[$x['emp_comprado_id']] ?? '#' . $x['emp_comprado_id'],
                    'mesmo' => $x['movimento'] === 'mesmo', 'vendas' => 0, 'valor' => 0.0, 'com_intermediarios' => 0];
        $m[$k]['vendas']++;
        $m[$k]['valor'] += $x['valor'];
        $m[$k]['com_intermediarios'] += count($x['interesses']) > 1 ? 1 : 0;
    }
    usort($m, fn ($a, $b) => $b['vendas'] <=> $a['vendas'] ?: strcmp($a['de'] . '>' . $a['para'], $b['de'] . '>' . $b['para']));
    return $m;
}

/**
 * Produtividade comercial histórica por corretor do CRM: o que ele recebeu e
 * fez no período (CRM) ao lado das vendas da planilha em que era o
 * responsável na data da venda. Populações diferentes: sem "taxa".
 */
function vendas_produtividade(int $empresaId, array $fatos, array $periodo): array
{
    $pdo = db();
    $p = ['e' => $empresaId, 'ini' => $periodo['ini'], 'fim' => $periodo['fim']];
    $lin = [];
    $nome = [];
    foreach ($pdo->query("SELECT id, nome FROM usuarios WHERE empresa_id = $empresaId")->fetchAll() as $u) {
        $nome[(int) $u['id']] = $u['nome'];
    }
    $base = function (int $u) use (&$lin, $nome) {
        $lin[$u] ??= ['usuario_id' => $u, 'nome' => $nome[$u] ?? '#' . $u, 'recebidos' => 0, 'atendidos' => 0, 'visitas' => 0, 'transferidos' => 0,
                      'vendas_responsavel' => 0, 'valor_responsavel' => 0.0, 'vendas_primeiro' => 0];
    };
    if (modulo_tabela_existe('lead_atribuicoes')) {
        $st = $pdo->prepare('SELECT usuario_id, COUNT(*) r, SUM(primeiro_atendimento_em IS NOT NULL) a FROM lead_atribuicoes WHERE empresa_id = :e AND atribuido_em BETWEEN :ini AND :fim GROUP BY usuario_id');
        $st->execute($p);
        foreach ($st->fetchAll() as $r) {
            $base((int) $r['usuario_id']);
            $lin[(int) $r['usuario_id']]['recebidos'] = (int) $r['r'];
            $lin[(int) $r['usuario_id']]['atendidos'] = (int) $r['a'];
        }
    }
    if (modulo_tabela_existe('agenda_compromissos')) {
        $st = $pdo->prepare("SELECT usuario_id, COUNT(*) n FROM agenda_compromissos WHERE empresa_id = :e AND tipo = 'visita' AND status = 'realizado' AND inicio BETWEEN :ini AND :fim GROUP BY usuario_id");
        $st->execute($p);
        foreach ($st->fetchAll() as $r) {
            $base((int) $r['usuario_id']);
            $lin[(int) $r['usuario_id']]['visitas'] = (int) $r['n'];
        }
    }
    if (modulo_tabela_existe('lead_transferencias')) {
        $st = $pdo->prepare('SELECT de_usuario_id u, COUNT(*) n FROM lead_transferencias WHERE empresa_id = :e AND de_usuario_id IS NOT NULL AND criado_em BETWEEN :ini AND :fim GROUP BY de_usuario_id');
        $st->execute($p);
        foreach ($st->fetchAll() as $r) {
            $base((int) $r['u']);
            $lin[(int) $r['u']]['transferidos'] = (int) $r['n'];
        }
    }
    foreach ($fatos as $x) {
        if (!$x['encontrada']) {
            continue;
        }
        foreach (array_unique(array_column($x['responsaveis'], 'usuario_id')) as $u) {
            $base($u);
            $lin[$u]['vendas_responsavel']++;
            $lin[$u]['valor_responsavel'] += $x['valor'];
        }
        if ($x['primeiro_corretor_id']) {
            $base($x['primeiro_corretor_id']);
            $lin[$x['primeiro_corretor_id']]['vendas_primeiro']++;
        }
    }
    usort($lin, fn ($a, $b) => $b['vendas_responsavel'] <=> $a['vendas_responsavel'] ?: $b['recebidos'] <=> $a['recebidos']);
    return $lin;
}

/** Opções dos filtros (empreendimentos, equipes, corretores, origens) da empresa. */
function vendas_painel_opcoes(int $empresaId): array
{
    $q = fn (string $sql) => db()->query($sql)->fetchAll();
    return [
        'empreendimentos' => $q("SELECT id, nome FROM empreendimentos WHERE empresa_id = $empresaId ORDER BY nome"),
        'equipes' => $q("SELECT id, nome FROM equipes WHERE empresa_id = $empresaId ORDER BY nome"),
        'corretores' => $q("SELECT id, nome FROM usuarios WHERE empresa_id = $empresaId AND ativo = 1 ORDER BY nome"),
        'origens' => $q("SELECT id, nome FROM lead_origens WHERE empresa_id = $empresaId ORDER BY ordem, nome"),
    ];
}
