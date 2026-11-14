<?php
/**
 * Dados exportáveis da Central de Relatórios (Etapa 5).
 *
 * Cada relatório vira uma estrutura única (cabeçalho, indicadores, tabelas,
 * série, notas) montada pelas MESMAS funções que desenham a tela — Excel,
 * CSV e PDF saem daqui, então os números são sempre os da tela.
 *
 * Tipos de coluna: texto | int | dec1 (taxas e médias, 1 casa) | moeda |
 * data | datahora.
 */
require_once __DIR__ . '/especializados.php';
require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/indicadores.php';
require_once __DIR__ . '/../exportacao.php';

/** Níveis de exportação. */
function relexp_niveis(): array
{
    return [
        'resumido' => 'Relatório resumido (indicadores e tabelas principais)',
        'completo' => 'Relatório completo (tudo o que está na tela + definições)',
        'dados' => 'Exportação de dados (uma linha por lead do período)',
    ];
}

function relexp_formatos(): array
{
    return ['xlsx' => 'Excel (.xlsx)', 'csv' => 'CSV (separado por ;)', 'pdf' => 'PDF'];
}

/** Dimensões de filtro de cada relatório (as mesmas da tela). */
function relexp_dims(string $rel): array
{
    return [
        'executiva' => ['empreendimento', 'superintendencia', 'gerente', 'equipe', 'corretor', 'roleta', 'plantao', 'origem', 'canal'],
        'corretor' => ['corretor', 'empreendimento', 'origem', 'roleta'],
        'gestao' => ['empreendimento', 'superintendencia', 'gerente', 'equipe', 'roleta', 'origem'],
        'empreendimentos' => ['superintendencia', 'equipe', 'origem'],
        'funil' => ['empreendimento', 'superintendencia', 'gerente', 'equipe', 'corretor', 'origem', 'canal'],
        'plantoes' => ['roleta', 'plantao', 'corretor', 'empreendimento'],
        'origens' => ['empreendimento', 'superintendencia', 'equipe', 'origem', 'canal'],
        'visitas' => ['empreendimento', 'superintendencia', 'equipe', 'corretor'],
        'perdas' => ['empreendimento', 'superintendencia', 'gerente', 'equipe', 'corretor'],
    ][$rel] ?? [];
}

/** [[rótulo, nome]] dos filtros aplicados, com os nomes (não os ids). */
function relexp_filtros_descricao(array $pub, array $op, array $dims): array
{
    $listas = ['empreendimento' => ['Empreendimento', $op['empreendimentos']], 'superintendencia' => ['Superintendência', $op['superintendencias']],
        'gerente' => ['Gerente', $op['gerentes']], 'equipe' => ['Equipe', $op['equipes']], 'corretor' => ['Corretor', $op['corretores']],
        'roleta' => ['Roleta', $op['roletas']], 'plantao' => ['Plantão', $op['plantoes']], 'origem' => ['Origem', $op['origens']],
        'canal' => ['Grupo de origem', array_map(fn ($k, $v) => ['id' => $k, 'nome' => $v], array_keys(lead_origens()), lead_origens())]];
    $out = [];
    foreach ($dims as $d) {
        if (!isset($pub[$d], $listas[$d])) {
            continue;
        }
        $nome = 'não encontrado(a)';
        foreach ($listas[$d][1] as $it) {
            if ((string) $it['id'] === (string) $pub[$d]) {
                $nome = $it['nome'];
            }
        }
        $out[] = [$listas[$d][0], $nome];
    }
    return $out;
}

/** Indicador do motor → linha de resumo. */
function relexp_ind(string $nome, array $ind, string $dic = ''): array
{
    return ['nome' => $nome, 'valor' => $ind['valor'], 'unidade' => $ind['unidade'] ?? '', 'anterior' => $ind['anterior'] ?? null,
            'variacao' => $ind['variacao'] ?? null, 'variacao_unidade' => $ind['variacao_unidade'] ?? null, 'nota' => trim(($ind['base'] ?? '') . ' ' . ($ind['nota'] ?? '')), 'dic' => $dic];
}

function relexp_simples(string $nome, $valor, string $unidade = '', string $nota = '', string $dic = ''): array
{
    return ['nome' => $nome, 'valor' => $valor, 'unidade' => $unidade, 'anterior' => null, 'variacao' => null, 'variacao_unidade' => null, 'nota' => $nota, 'dic' => $dic];
}

/** Unidade do motor → tipo de coluna/valor exportado. */
function relexp_tipo_unidade(string $u): string
{
    return ['%' => 'dec1', 'R$' => 'moeda', 'min' => 'dec1', 'h' => 'dec1'][$u] ?? 'int';
}

/** Rótulo da unidade para nomes de coluna ("Tempo (min)"). */
function relexp_sufixo(string $u): string
{
    return ['%' => ' (%)', 'R$' => ' (R$)', 'min' => ' (min)', 'h' => ' (h)'][$u] ?? '';
}

/**
 * Monta os dados de um relatório. $get: os mesmos parâmetros da URL da tela.
 * Retorna ['ok' => false, 'erro'] ou o conjunto completo.
 */
function relexp_dados(array $usuario, string $rel, array $get, string $nivel = 'completo'): array
{
    if (!relui_pode($usuario, $rel)) {
        return ['ok' => false, 'erro' => 'Você não tem acesso a este relatório.'];
    }
    [$entrada, $pub] = relui_entrada($get);
    if ($rel === 'corretor' && $usuario['escopo'] === 'propria_carteira') {
        $entrada['corretor'] = $pub['corretor'] = (string) $usuario['id'];
    }
    $f = relatorio_filtros($usuario, $entrada);
    if (!empty($f['erro'])) {
        return ['ok' => false, 'erro' => $f['erro']];
    }
    $op = relui_opcoes($usuario);
    $empresa = (string) db()->query('SELECT nome FROM empresas WHERE id = ' . (int) $usuario['empresa_ativa_id'])->fetchColumn();
    $d = [
        'ok' => true, 'chave' => $rel, 'titulo' => relui_relatorios()[$rel][0], 'descricao' => relui_relatorios()[$rel][1],
        'empresa' => $empresa, 'periodo' => $f['periodo']['rotulo'], 'comparado' => null,
        'filtros' => relexp_filtros_descricao($pub, $op, relexp_dims($rel)), 'vazio' => $f['vazio'],
        'gerado_em' => date('d/m/Y H:i'), 'fuso' => date_default_timezone_get() . ' (UTC' . date('P') . ')', 'usuario' => $usuario['nome'] ?? '',
        'nivel' => $nivel, 'indicadores' => [], 'tabelas' => [], 'serie' => null, 'notas' => [], 'pub' => $pub,
    ];
    if ($f['vazio']) {
        $d['notas'][] = 'Um dos filtros não existe ou não está ao seu alcance: por segurança o resultado está vazio.';
    }
    $cob = relatorio_cobertura_historico($usuario, $f);
    if (!$cob['instalado']) {
        $d['notas'][] = 'Histórico estruturado não instalado (migração 013): números por corretor e estrutura indisponíveis.';
    } elseif ($cob['reconstruidas'] > 0 && $cob['total'] > 0) {
        $d['notas'][] = round($cob['reconstruidas'] * 100 / $cob['total']) . '% das entregas do período foram reconstruídas de registros anteriores ao histórico estruturado. Atendimentos sem registro aparecem como "não atendido".';
    }
    $fn = 'relexp_' . $rel;
    $fn($usuario, $f, $pub, $op, $d);
    $d['notas'][] = 'Sem base de cálculo o valor aparece vazio ("—"), nunca 0% inventado. Investimento em anúncios não é registrado no sistema: custos e ROI não são calculados.';
    // Definições do dicionário dos indicadores usados.
    $dic = relatorio_indicadores();
    $d['definicoes'] = [];
    foreach ($d['indicadores'] as $i) {
        if ($i['dic'] && isset($dic[$i['dic']]) && !isset($d['definicoes'][$i['dic']])) {
            $d['definicoes'][$i['dic']] = [$dic[$i['dic']]['nome'], $dic[$i['dic']]['descricao'], $dic[$i['dic']]['formula']];
        }
    }
    $d['definicoes'] = array_values($d['definicoes']);
    if ($nivel === 'resumido') {
        $d['tabelas'] = array_slice($d['tabelas'], 0, 1);
    }
    return $d;
}

/** Série → tabela. */
function relexp_tabela_serie(array $serie, string $titulo, string $rotuloValor): array
{
    return ['titulo' => $titulo, 'colunas' => ['rotulo' => ['Intervalo', 'texto'], 'ini' => ['Início', 'data'], 'total' => [$rotuloValor, 'int']], 'linhas' => $serie, 'totais' => ['rotulo' => 'Total', 'total' => array_sum(array_column($serie, 'total'))]];
}

// ============================================================ relatórios

function relexp_executiva(array $u, array $f, array $pub, array $op, array &$d): void
{
    $ex = relatorio_executivo($u, $f);
    $I = $ex['indicadores'];
    $d['comparado'] = $ex['periodo']['anterior']['rotulo'] ?? null;
    foreach ([['leads_recebidos', 'Leads recebidos', 'leads_recebidos'], ['leads_unicos', 'Pessoas diferentes', 'leads_unicos'], ['leads_distribuidos', 'Chegaram a um corretor', 'leads_distribuidos'],
              ['contatos_efetivos', 'Leads com contato efetivo', 'contatos_efetivos'], ['taxa_contato_efetivo', 'Taxa de contato efetivo', 'taxa_contato_efetivo'],
              ['taxa_primeiro_contato', 'Taxa de atendimento', 'taxa_primeiro_contato'], ['tempo_primeiro_contato', 'Tempo até o 1º atendimento (mediana)', 'tempo_primeiro_contato'],
              ['leads_fora_horario', 'Chegaram fora do horário', 'leads_fora_horario'], ['visitas_agendadas', 'Visitas agendadas', 'visitas_agendadas'],
              ['visitas_confirmadas', 'Visitas confirmadas', 'visitas_confirmadas'], ['visitas_realizadas', 'Visitas realizadas', 'visitas_realizadas'],
              ['taxa_comparecimento', 'Comparecimento', 'taxa_comparecimento'], ['contratos_validados', 'Contratos validados', 'contratos_assinados'],
              ['contratos_aguardando', 'Contratos aguardando validação', ''], ['distratos', 'Distratos', ''], ['vgv', 'VGV validado', 'vgv'],
              ['contratos_pela_etapa', 'Sinal do Kanban: movidos para "Contrato assinado" (não é venda)', '']] as [$k, $n, $dk]) {
        $d['indicadores'][] = relexp_ind($n, $I[$k], $dk);
    }
    $pe = relatorio_perdas($u, $f);
    $d['indicadores'][] = relexp_ind('Perdidos por inatividade', $pe['perdidos_inatividade'], 'perdidos_inatividade');
    $d['indicadores'][] = relexp_ind('Tempo até a redistribuição (mediana)', $pe['tempo_redistribuicao'], 'tempo_redistribuicao');
    $gran = isset(relatorio_granularidades()[$pub['gran'] ?? '']) ? $pub['gran'] : relatorio_granularidade_padrao($f['periodo']);
    $serie = relatorio_serie($u, $f, 'leads_recebidos', $gran);
    $d['serie'] = ['titulo' => 'Leads recebidos ' . mb_strtolower(relatorio_granularidades()[$gran]), 'pontos' => $serie];
    $orig = relatorio_leads_por_origem($u, $f);
    $d['tabelas'][] = ['titulo' => 'Leads por origem', 'colunas' => ['origem' => ['Origem', 'texto'], 'leads' => ['Leads', 'int'], 'contato_efetivo' => ['Com contato efetivo', 'int']],
        'linhas' => $orig, 'totais' => ['origem' => 'Total', 'leads' => array_sum(array_column($orig, 'leads')), 'contato_efetivo' => array_sum(array_map('intval', array_column($orig, 'contato_efetivo')))]];
    $d['tabelas'][] = relexp_tabela_serie($serie, 'Evolução — leads recebidos', 'Leads');
    foreach (['recebimentos' => 'Entregas', 'contatos_efetivos' => 'Contatos efetivos', 'visitas_realizadas' => 'Visitas realizadas', 'contratos_validados' => 'Contratos validados'] as $m => $r) {
        $d['tabelas'][] = relexp_tabela_serie(relatorio_serie($u, $f, $m, $gran), 'Evolução — ' . mb_strtolower($r), $r);
    }
}

function relexp_corretor(array $u, array $f, array $pub, array $op, array &$d): void
{
    $cid = (int) ($f['dim']['corretor'][0] ?? 0);
    $alcance = $u['escopo'] === 'propria_carteira' || in_array($cid, array_map('intval', array_column($op['corretores'], 'id')), true);
    if (!$cid || !$alcance) {
        $d['notas'][] = $cid ? 'Corretor fora do seu alcance.' : 'Nenhum corretor escolhido.';
        return;
    }
    $nome = (string) db()->query('SELECT nome FROM usuarios WHERE id = ' . $cid)->fetchColumn();
    $d['titulo'] .= ' — ' . $nome;
    $L = (relatorio_agrupado($u, $f, 'corretor')[0] ?? []) + ['leads_recebidos' => 0, 'atendidos' => 0, 'taxa_atendimento' => null, 'contato_efetivo' => 0,
        'taxa_contato_efetivo' => null, 'tempo_primeiro_atendimento_min' => null, 'perdidos_inatividade' => 0, 'pela_roleta' => 0, 'por_redistribuicao' => 0, 'outras_formas' => 0];
    $vis = relatorio_visitas_do_corretor($u, $f, $cid);
    $con = relatorio_contratos($u, $f);
    $d['indicadores'] = [
        relexp_simples('Leads recebidos', $L['leads_recebidos'], '', $L['pela_roleta'] . ' pela roleta, ' . $L['por_redistribuicao'] . ' redistribuídos, ' . $L['outras_formas'] . ' outras formas', 'recebidos_corretor'),
        relexp_simples('Leads atendidos', $L['atendidos'], '', '', 'leads_com_atuacao'),
        relexp_simples('Taxa de atendimento', $L['taxa_atendimento'], '%', '', 'taxa_primeiro_contato'),
        relexp_simples('Leads com contato efetivo', $L['contato_efetivo'], '', '', 'contatos_efetivos'),
        relexp_simples('Taxa de contato efetivo', $L['taxa_contato_efetivo'], '%', '', 'taxa_contato_efetivo'),
        relexp_simples('Tempo até o 1º atendimento (mediana)', $L['tempo_primeiro_atendimento_min'], 'min', '', 'tempo_primeiro_contato'),
        relexp_simples('Visitas agendadas', $vis['agendadas'], '', '', 'visitas_agendadas'),
        relexp_simples('Visitas realizadas', $vis['realizadas'], '', $vis['ausentes'] . ' com cliente ausente', 'visitas_realizadas'),
        relexp_ind('Contratos validados', $con['contratos_validados'], 'contratos_assinados'),
        relexp_ind('VGV validado', $con['vgv'], 'vgv'),
        relexp_simples('Perdidos por inatividade', $L['perdidos_inatividade'], '', '', 'perdidos_corretor'),
    ];
    $gran = isset(relatorio_granularidades()[$pub['gran'] ?? '']) ? $pub['gran'] : relatorio_granularidade_padrao($f['periodo']);
    $serie = relatorio_serie($u, $f, 'recebimentos', $gran);
    $d['serie'] = ['titulo' => 'Leads recebidos ' . mb_strtolower(relatorio_granularidades()[$gran]), 'pontos' => $serie];
    $d['tabelas'][] = relexp_tabela_serie($serie, 'Evolução — leads recebidos', 'Leads');
    $d['tabelas'][] = relexp_tabela_serie(relatorio_serie($u, $f, 'contatos_efetivos', $gran), 'Evolução — contatos efetivos', 'Contatos efetivos');
    $d['tabelas'][] = relexp_tabela_serie(relatorio_serie($u, $f, 'contratos_validados', $gran), 'Evolução — contratos validados', 'Contratos');
}

/** Colunas padrão de agrupamento por estrutura. */
function relexp_colunas_agrupado(string $rotulo, bool $posicao = false): array
{
    return ($posicao ? ['posicao' => ['Posição no ranking', 'int']] : []) + ['nome' => [$rotulo, 'texto'], 'leads_recebidos' => ['Leads recebidos', 'int'], 'recebimentos' => ['Entregas', 'int'],
        'atendidos' => ['Atendidos', 'int'], 'taxa_atendimento' => ['Taxa de atendimento (%)', 'dec1'], 'contato_efetivo' => ['Contato efetivo', 'int'],
        'taxa_contato_efetivo' => ['Taxa de contato efetivo (%)', 'dec1'], 'tempo_primeiro_atendimento_min' => ['Tempo até o 1º atendimento (min, mediana)', 'dec1'],
        'perdidos_inatividade' => ['Perdidos (inatividade)', 'int'], 'contratos_validados' => ['Contratos validados', 'int'], 'vgv' => ['VGV validado (R$)', 'moeda'],
        'reconstruidas' => ['Entregas reconstruídas', 'int']];
}

function relexp_gestao(array $u, array $f, array $pub, array $op, array &$d): void
{
    $ags = array_map(fn ($a) => $a[1], relatorio_agrupamentos());
    $esc = $u['escopo'];
    if ($esc === 'equipe') {
        unset($ags['superintendencia'], $ags['gerente']);
    } elseif ($esc === 'superintendencia') {
        unset($ags['superintendencia']);
    }
    $padrao = $esc === 'equipe' ? 'corretor' : ($esc === 'superintendencia' ? 'equipe' : 'superintendencia');
    $por = isset($ags[$pub['agrupar'] ?? '']) ? $pub['agrupar'] : $padrao;
    $crit = isset(relatorio_criterios_ranking()[$pub['criterio'] ?? '']) ? $pub['criterio'] : 'taxa_contato_efetivo';
    $linhas = relatorio_agrupado($u, $f, $por);
    $rk = relatorio_ranking($linhas, $crit, 5);
    $pos = array_column($rk['ranking'], 'posicao', 'id');
    foreach ($linhas as &$l) {
        $l['posicao'] = $pos[$l['id']] ?? null;
    }
    unset($l);
    $t = relui_somar($linhas, ['leads_recebidos', 'recebimentos', 'atendidos', 'contato_efetivo', 'perdidos_inatividade', 'contratos_validados', 'vgv', 'reconstruidas']);
    $t['taxa_atendimento'] = motor_taxa($t['atendidos'], $t['leads_recebidos']);
    $t['taxa_contato_efetivo'] = motor_taxa($t['contato_efetivo'], $t['leads_recebidos']);
    $t['nome'] = 'Total';
    $d['indicadores'] = [
        relexp_simples('Leads recebidos (soma das estruturas)', $t['leads_recebidos'], '', 'Lead transferido conta para cada estrutura que o recebeu.', 'recebidos_corretor'),
        relexp_simples('Taxa de atendimento', $t['taxa_atendimento'], '%', 'Sobre a soma, não a média das linhas.', 'taxa_primeiro_contato'),
        relexp_simples('Taxa de contato efetivo', $t['taxa_contato_efetivo'], '%', '', 'taxa_contato_efetivo'),
        relexp_simples('Contratos validados', $t['contratos_validados'], '', '', 'contratos_assinados'),
        relexp_simples('VGV validado', $t['vgv'], 'R$', '', 'vgv'),
    ];
    $d['tabelas'][] = ['titulo' => 'Comparativo por ' . mb_strtolower($ags[$por]) . ' — ranking por ' . mb_strtolower(relatorio_criterios_ranking()[$crit][0]),
        'colunas' => relexp_colunas_agrupado($ags[$por], true), 'linhas' => $linhas, 'totais' => $t];
    $d['notas'][] = 'Ranking pelo critério "' . relatorio_criterios_ranking()[$crit][0] . '". Taxas e tempos só entram no ranking com pelo menos 5 leads recebidos'
        . ($rk['sem_base'] ? '; fora do ranking: ' . implode(', ', array_column($rk['sem_base'], 'nome')) : '') . '.';
    $d['notas'][] = 'Estrutura na data de cada entrega (não a carteira de hoje).';
}

function relexp_empreendimentos(array $u, array $f, array $pub, array $op, array &$d): void
{
    $nomes = array_column($op['empreendimentos'], 'nome', 'id');
    $sel = array_slice(array_values(array_unique(array_filter(array_map('intval', (array) ($pub['emp'] ?? [])), fn ($id) => isset($nomes[$id])))), 0, 6);
    if (count($sel) < 2) {
        $d['notas'][] = 'Escolha pelo menos 2 empreendimentos para comparar.';
        return;
    }
    [$entrada] = relui_entrada($pub);
    $res = [];
    foreach ($sel as $id) {
        $fi = relatorio_filtros($u, $entrada + ['empreendimento' => [$id]]);
        $res[$id] = relatorio_leads($u, $fi) + relatorio_visitas($u, $fi) + relatorio_contratos($u, $fi);
    }
    $cols = ['indicador' => ['Indicador', 'texto']];
    foreach ($sel as $id) {
        $cols['e' . $id] = [$nomes[$id], 'dec1'];
    }
    $linhas = [];
    foreach ([['leads_recebidos', 'Leads recebidos'], ['leads_distribuidos', 'Chegaram a um corretor'], ['taxa_primeiro_contato', 'Taxa de atendimento (%)'],
              ['taxa_contato_efetivo', 'Taxa de contato efetivo (%)'], ['tempo_primeiro_contato', 'Tempo até o 1º atendimento (min)'], ['visitas_agendadas', 'Visitas agendadas'],
              ['visitas_realizadas', 'Visitas realizadas'], ['taxa_comparecimento', 'Comparecimento (%)'], ['contratos_validados', 'Contratos validados'], ['vgv', 'VGV validado (R$)']] as [$k, $r]) {
        $l = ['indicador' => $r];
        foreach ($sel as $id) {
            $l['e' . $id] = $res[$id][$k]['valor'];
        }
        $linhas[] = $l;
    }
    $d['tabelas'][] = ['titulo' => 'Empreendimentos lado a lado', 'colunas' => $cols, 'linhas' => $linhas, 'totais' => null];
    $d['indicadores'][] = relexp_simples('Empreendimentos comparados', count($sel));
}

function relexp_funil(array $u, array $f, array $pub, array $op, array &$d): void
{
    $x = relatorio_funil_detalhe($u, $f);
    $d['indicadores'] = [relexp_simples('Leads do período', $x['coorte'], '', '', 'funil_posicao'), relexp_simples('Mudanças de etapa', $x['transicoes'], '', '', 'funil_movimentacao'),
        relexp_simples('Avanços', $x['avancos']), relexp_simples('Retrocessos', $x['retrocessos']), relexp_simples('Feitas por jornadas', $x['por_origem']['jornada'] ?? 0)];
    $d['tabelas'][] = ['titulo' => 'Até onde os leads do período chegaram', 'colunas' => ['rotulo' => ['Fase', 'texto'], 'total' => ['Leads', 'int'], 'pct_coorte' => ['% do total', 'dec1'],
        'pct_anterior' => ['% da fase anterior', 'dec1']], 'linhas' => $x['alcance'], 'totais' => null];
    $d['tabelas'][] = ['titulo' => 'Onde estão hoje', 'colunas' => ['nome' => ['Etapa atual', 'texto'], 'total' => ['Leads', 'int']], 'linhas' => $x['posicao'],
        'totais' => ['nome' => 'Total', 'total' => array_sum(array_column($x['posicao'], 'total'))]];
    $m = array_map(fn ($t) => $t + ['de_rot' => $t['de'] === '—' ? '(entrada)' : lead_etapa_rotulo($t['de']), 'para_rot' => lead_etapa_rotulo($t['para'])], $x['matriz']);
    $d['tabelas'][] = ['titulo' => 'Transições no período', 'colunas' => ['de_rot' => ['De', 'texto'], 'para_rot' => ['Para', 'texto'], 'usuario' => ['Por pessoas', 'int'],
        'jornada' => ['Por jornadas', 'int'], 'sistema' => ['Pelo sistema', 'int'], 'total' => ['Total', 'int']], 'linhas' => $m, 'totais' => null];
    $t = [];
    foreach ($x['tempo_etapa_horas'] as $cat => $v) {
        $t[] = ['etapa' => lead_etapa_rotulo($cat), 'mediana' => $v['mediana'], 'permanencias' => $v['permanencias']];
    }
    $d['tabelas'][] = ['titulo' => 'Tempo em cada etapa', 'colunas' => ['etapa' => ['Etapa', 'texto'], 'mediana' => ['Tempo mediano (h)', 'dec1'], 'permanencias' => ['Permanências concluídas', 'int']], 'linhas' => $t, 'totais' => null];
}

function relexp_plantoes(array $u, array $f, array $pub, array $op, array &$d): void
{
    $x = relatorio_plantoes($u, $f);
    $t = relui_somar($x['roletas'], ['entregas', 'redistribuicoes', 'chegadas', 'fora_horario', 'fila_passagens', 'fila_aguardando', 'horas_presenca']);
    $d['indicadores'] = [relexp_simples('Entregas pela roleta', $t['entregas'] ?? 0, '', '', 'roleta_distribuicoes'), relexp_simples('Chegadas registradas', $t['chegadas'] ?? 0),
        relexp_simples('Chegaram fora do horário', ($t['chegadas'] ?? 0) ? $t['fora_horario'] : null, '', '', 'leads_fora_horario'),
        relexp_simples('Passagens pela fila', $t['fila_passagens'] ?? 0, '', '', 'espera_distribuicao'), relexp_simples('Horas de presença', $t['horas_presenca'] ?? 0, 'h', '', 'presenca_corretor')];
    $d['tabelas'][] = ['titulo' => 'Por roleta', 'colunas' => ['nome' => ['Roleta', 'texto'], 'plantao' => ['Plantão', 'texto'], 'entregas' => ['Entregas', 'int'],
        'redistribuicoes' => ['Redistribuições', 'int'], 'chegadas' => ['Chegadas', 'int'], 'fora_horario' => ['Fora do horário', 'int'], 'fila_passagens' => ['Passagens pela fila', 'int'],
        'espera_mediana_min' => ['Espera mediana (min)', 'dec1'], 'fila_aguardando' => ['Aguardando', 'int'], 'corretores_presentes' => ['Corretores presentes', 'int'],
        'horas_presenca' => ['Horas de presença', 'dec1']], 'linhas' => $x['roletas'], 'totais' => $t + ['nome' => 'Total']];
    $d['tabelas'][] = ['titulo' => 'Presença por corretor', 'colunas' => ['nome' => ['Corretor', 'texto'], 'roleta' => ['Roleta', 'texto'], 'horas' => ['Horas de presença', 'dec1']],
        'linhas' => $x['presenca'], 'totais' => null];
}

function relexp_origens(array $u, array $f, array $pub, array $op, array &$d): void
{
    $ags = relatorio_origens_agrupamentos();
    $por = isset($ags[$pub['agrupar'] ?? '']) ? $pub['agrupar'] : 'origem';
    $l = relatorio_origens($u, $f, $por);
    $t = relui_somar($l, ['leads', 'distribuidos', 'contato_efetivo', 'com_visita', 'com_contrato']);
    $t['taxa_contato_efetivo'] = motor_taxa($t['contato_efetivo'], $t['distribuidos']);
    $t['taxa_visita'] = motor_taxa($t['com_visita'], $t['leads']);
    $t['taxa_contrato'] = motor_taxa($t['com_contrato'], $t['leads']);
    $t['nome'] = 'Total';
    $d['indicadores'] = [relexp_simples('Leads do período', $t['leads'] ?? 0, '', '', 'leads_origem'), relexp_simples('Com contrato validado', $t['com_contrato'], ''),
        relexp_simples('Custo por lead', null, 'R$', 'Não disponível: o investimento em anúncios não é registrado no sistema.', 'custos_por_resultado')];
    $d['tabelas'][] = ['titulo' => 'Leads do período por ' . $ags[$por], 'colunas' => ['nome' => [$ags[$por], 'texto'], 'leads' => ['Leads', 'int'], 'distribuidos' => ['Chegaram a corretor', 'int'],
        'contato_efetivo' => ['Contato efetivo', 'int'], 'taxa_contato_efetivo' => ['Taxa de contato efetivo (%)', 'dec1'], 'com_visita' => ['Com visita realizada', 'int'],
        'taxa_visita' => ['% com visita', 'dec1'], 'com_contrato' => ['Com contrato validado', 'int'], 'taxa_contrato' => ['% com contrato', 'dec1'], 'custo_por_lead' => ['Custo por lead (R$)', 'moeda']],
        'linhas' => $l, 'totais' => $l ? $t : null];
}

function relexp_visitas(array $u, array $f, array $pub, array $op, array &$d): void
{
    $por = in_array($pub['agrupar'] ?? '', ['corretor', 'empreendimento'], true) ? $pub['agrupar'] : 'corretor';
    $l = relatorio_visitas_por($u, $f, $por);
    $t = relui_somar($l, ['agendadas', 'confirmadas', 'realizadas', 'ausentes', 'canceladas', 'reagendadas', 'viraram_contrato']);
    $t['comparecimento'] = motor_taxa($t['realizadas'], ($t['realizadas'] ?? 0) + ($t['ausentes'] ?? 0));
    $t['taxa_contrato'] = $t['viraram_contrato'] === null ? null : motor_taxa($t['viraram_contrato'], $t['realizadas']);
    $t['nome'] = 'Total';
    $d['indicadores'] = [relexp_simples('Visitas agendadas', $t['agendadas'] ?? 0, '', '', 'visitas_agendadas'), relexp_simples('Visitas confirmadas', $t['confirmadas'], '', '', 'visitas_confirmadas'),
        relexp_simples('Visitas realizadas', $t['realizadas'] ?? 0, '', '', 'visitas_realizadas'), relexp_simples('Comparecimento', $t['comparecimento'], '%', '', 'taxa_comparecimento'),
        relexp_simples('Visita → contrato', $t['taxa_contrato'], '%', '', 'taxa_visita_contrato')];
    $d['tabelas'][] = ['titulo' => 'Visitas por ' . ($por === 'corretor' ? 'corretor' : 'empreendimento'), 'colunas' => ['nome' => [$por === 'corretor' ? 'Corretor' : 'Empreendimento', 'texto'],
        'agendadas' => ['Agendadas', 'int'], 'confirmadas' => ['Confirmadas', 'int'], 'realizadas' => ['Realizadas', 'int'], 'ausentes' => ['Cliente ausente', 'int'], 'canceladas' => ['Canceladas', 'int'],
        'reagendadas' => ['Reagendadas', 'int'], 'comparecimento' => ['Comparecimento (%)', 'dec1'], 'viraram_contrato' => ['Viraram contrato', 'int'], 'taxa_contrato' => ['Visita → contrato (%)', 'dec1']],
        'linhas' => $l, 'totais' => $l ? $t : null];
}

function relexp_perdas(array $u, array $f, array $pub, array $op, array &$d): void
{
    $ags = array_map(fn ($a) => $a[1], relatorio_agrupamentos());
    $por = isset($ags[$pub['agrupar'] ?? '']) ? $pub['agrupar'] : ($u['escopo'] === 'equipe' ? 'corretor' : 'equipe');
    $pe = relatorio_perdas($u, $f);
    $l = relatorio_perdas_por($u, $f, $por);
    $t = relui_somar($l, ['inatividade', 'transferidos', 'total', 'redistribuidos', 'contato_depois', 'contrato_depois']);
    $t['taxa_contato_depois'] = motor_taxa($t['contato_depois'], $t['redistribuidos']);
    $t['tempo_redistribuicao_h'] = $pe['tempo_redistribuicao']['valor'];
    $t['nome'] = 'Total';
    $d['indicadores'] = [relexp_ind('Perdidos por inatividade', $pe['perdidos_inatividade'], 'perdidos_inatividade'), relexp_ind('Já redistribuídos', $pe['redistribuidos'], 'redistribuidos'),
        relexp_ind('Tempo até a redistribuição (mediana)', $pe['tempo_redistribuicao'], 'tempo_redistribuicao'), relexp_simples('Transferidos', $t['transferidos'] ?? 0, '', '', 'transferencias_motivo')];
    $d['tabelas'][] = ['titulo' => 'Por ' . mb_strtolower($ags[$por]) . ' que perdeu', 'colunas' => ['nome' => [$ags[$por], 'texto'], 'inatividade' => ['Perdidos (inatividade)', 'int'],
        'redistribuidos' => ['Redistribuídos', 'int'], 'tempo_redistribuicao_h' => ['Tempo até redistribuir (h, mediana)', 'dec1'], 'contato_depois' => ['Contato depois', 'int'],
        'taxa_contato_depois' => ['% contato depois', 'dec1'], 'contrato_depois' => ['Contrato depois', 'int'], 'transferidos' => ['Transferidos', 'int'], 'total' => ['Total de saídas', 'int']],
        'linhas' => $l, 'totais' => $l ? $t : null];
    $dest = relatorio_perdas_destino($u, $f, $por === 'corretor' ? 'equipe' : $por);
    $d['tabelas'][] = ['titulo' => 'Para onde foram os perdidos por inatividade', 'colunas' => ['rotulo' => ['Destino', 'texto'], 'total' => ['Leads', 'int']], 'linhas' => $dest, 'totais' => null];
}

// ============================================================ dados (uma linha por lead)

/** Colunas da exportação de dados. */
function relexp_colunas_dados(): array
{
    return ['id' => ['ID do lead', 'int'], 'nome' => ['Nome', 'texto'], 'criado_em' => ['Entrada', 'datahora'], 'origem_nome' => ['Origem', 'texto'], 'canal' => ['Grupo de origem', 'texto'],
        'utm_source' => ['utm_source', 'texto'], 'utm_medium' => ['utm_medium', 'texto'], 'utm_campaign' => ['utm_campaign', 'texto'], 'campanha' => ['Campanha', 'texto'],
        'empreendimento' => ['Empreendimento', 'texto'], 'etapa_atual' => ['Etapa atual', 'texto'], 'corretor_atual' => ['Corretor atual', 'texto'], 'equipe_atual' => ['Equipe atual', 'texto'],
        'primeira_entrega' => ['1ª entrega a corretor', 'datahora'], 'primeiro_atendimento' => ['1º atendimento', 'datahora'], 'primeiro_contato_efetivo' => ['1º contato efetivo', 'datahora'],
        'visita_realizada' => ['Visita realizada em', 'datahora'], 'contrato_assinado' => ['Contrato validado (assinatura)', 'data'], 'vgv' => ['VGV validado (R$)', 'moeda']];
}

/** Quantos leads a exportação de dados terá (para decidir entre baixar já ou preparar em segundo plano). */
function relexp_dados_contar(array $usuario, array $get): int
{
    [$entrada] = relui_entrada($get);
    $f = relatorio_filtros($usuario, $entrada);
    if ($f['vazio'] || !empty($f['erro'])) {
        return 0;
    }
    [$w, $p] = motor_where_leads($usuario, $f);
    return (int) motor_um("SELECT COUNT(*) FROM leads WHERE {$w} AND leads.criado_em BETWEEN :ini AND :fim", $p + ['ini' => $f['periodo']['ini'], 'fim' => $f['periodo']['fim']]);
}

/**
 * Uma linha por lead que entrou no período, no escopo de leads de hoje e com
 * os filtros da tela. Gera as linhas aos poucos (não carrega tudo na memória).
 * Sem telefone e sem e-mail (minimização — LGPD).
 */
function relexp_dados_linhas(array $usuario, array $get): Generator
{
    [$entrada] = relui_entrada($get);
    $f = relatorio_filtros($usuario, $entrada);
    if ($f['vazio'] || !empty($f['erro'])) {
        return;
    }
    [$w, $p] = motor_where_leads($usuario, $f);
    $h = historico_instalado();
    $temO = modulo_tabela_existe('lead_origens');
    $temC = modulo_tabela_existe('lead_contratos');
    $sql = "SELECT leads.id, leads.nome, leads.criado_em, " . ($temO ? 'o.nome' : 'NULL') . " AS origem_nome, leads.origem AS canal,
            " . ($temO ? 'leads.utm_source, leads.utm_medium, leads.utm_campaign' : 'NULL AS utm_source, NULL AS utm_medium, NULL AS utm_campaign') . ", leads.campanha,
            emp.nome AS empreendimento, fe.nome AS etapa_atual, u.nome AS corretor_atual, eq.nome AS equipe_atual,
            " . ($h ? '(SELECT MIN(a.atribuido_em) FROM lead_atribuicoes a WHERE a.lead_id = leads.id)' : 'leads.atribuido_em') . " AS primeira_entrega,
            " . ($h ? '(SELECT MIN(a.primeiro_atendimento_em) FROM lead_atribuicoes a WHERE a.lead_id = leads.id)' : 'leads.primeiro_contato_em') . " AS primeiro_atendimento,
            " . ($h ? 'leads.primeiro_contato_efetivo_em' : 'NULL') . " AS primeiro_contato_efetivo,
            (SELECT MIN(ag.concluido_em) FROM agenda_compromissos ag WHERE ag.lead_id = leads.id AND ag.tipo = 'visita' AND ag.status = 'realizado') AS visita_realizada,
            " . ($temC ? "(SELECT MIN(c.assinado_em) FROM lead_contratos c WHERE c.lead_id = leads.id AND c.status = 'validado')" : 'NULL') . " AS contrato_assinado,
            " . ($temC ? "(SELECT SUM(c.valor_vgv) FROM lead_contratos c WHERE c.lead_id = leads.id AND c.status = 'validado')" : 'NULL') . " AS vgv
        FROM leads
        " . ($temO ? 'LEFT JOIN lead_origens o ON o.id = leads.origem_detalhe_id' : '') . "
        LEFT JOIN empreendimentos emp ON emp.id = leads.empreendimento_id
        LEFT JOIN funil_etapas fe ON fe.id = leads.etapa_id
        LEFT JOIN usuarios u ON u.id = leads.responsavel_id
        LEFT JOIN equipes eq ON eq.id = leads.equipe_id
        WHERE {$w} AND leads.criado_em BETWEEN :ini AND :fim ORDER BY leads.criado_em, leads.id LIMIT " . exportacao_limite();
    $stmt = db()->prepare($sql);
    $stmt->execute($p + ['ini' => $f['periodo']['ini'], 'fim' => $f['periodo']['fim']]);
    while ($r = $stmt->fetch()) {
        $r['canal'] = lead_origens()[$r['canal']] ?? $r['canal'];
        yield $r;
    }
}
