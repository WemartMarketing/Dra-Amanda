<?php
/**
 * Inteligência de Vendas — Fase 6: exportações (Excel, CSV, PDF).
 *
 * Monta os dados no MESMO formato dos relatórios da Central ($d), então o
 * gerador de arquivos é o mesmo (xlsx real, CSV com fórmulas neutralizadas,
 * PDF próprio). Os números saem das mesmas funções das telas.
 *
 * Níveis:
 *   resumido  só agregados (nenhum nome de cliente);
 *   completo  inclui a lista de vendas com o NOME do cliente (exige ciência
 *             LGPD). Telefone, e-mail e CPF NUNCA são exportados.
 * Jornada: individual; respeita a carteira atual do usuário (vendas_jornada).
 */
require_once __DIR__ . '/painel.php';
require_once __DIR__ . '/../relatorios/arquivos.php';
require_once __DIR__ . '/../relatorios/exportar.php';
require_once __DIR__ . '/../exportacao.php';

function vendas_export_tipos(): array
{
    return ['painel' => 'Painel de vendas × CRM', 'conciliacao' => 'Conciliação de vendas', 'jornada' => 'Jornada do Cliente'];
}

/** Base comum do $d da Central. */
function vendas_export_base(array $usuario, string $chave, string $titulo, string $descricao, string $periodo, array $filtros, string $nivel): array
{
    $empresa = (string) db()->query('SELECT nome FROM empresas WHERE id = ' . (int) $usuario['empresa_ativa_id'])->fetchColumn();
    return ['ok' => true, 'chave' => $chave, 'titulo' => $titulo, 'descricao' => $descricao, 'empresa' => $empresa, 'periodo' => $periodo, 'comparado' => null,
            'filtros' => $filtros, 'vazio' => false, 'gerado_em' => date('d/m/Y H:i'), 'fuso' => date_default_timezone_get() . ' (UTC' . date('P') . ')',
            'usuario' => $usuario['nome'] ?? '', 'nivel' => $nivel, 'indicadores' => [], 'tabelas' => [], 'serie' => null, 'definicoes' => [],
            'notas' => ['Fonte: vendas importadas do sistema comercial cruzadas com o CRM (regras de correspondência ' . VENDAS_REGRAS_VERSAO . ').',
                        'VGV é o valor de venda das unidades, não receita nem lucro. Associação no tempo (anúncio, campanha) não é causa da compra.',
                        'Telefone, e-mail e CPF não são exportados.']];
}

/** Descrição legível dos filtros do painel. */
function vendas_export_filtros(array $f, int $empresaId): array
{
    $op = vendas_painel_opcoes($empresaId);
    $nome = fn (string $lista, ?int $id) => $id ? (array_column($op[$lista], 'nome', 'id')[$id] ?? '#' . $id) : null;
    return array_values(array_filter([
        $f['empreendimento'] ? ['Empreendimento comprado', $nome('empreendimentos', $f['empreendimento'])] : null,
        $f['equipe'] ? ['Equipe (na data da venda)', $nome('equipes', $f['equipe'])] : null,
        $f['corretor'] ? ['Corretor (na data da venda)', $nome('corretores', $f['corretor'])] : null,
        $f['origem'] ? ['Origem no CRM', $nome('origens', $f['origem'])] : null,
        $f['situacao'] ? ['Situação', vendas_situacoes()[$f['situacao']][0]] : null,
    ]));
}

/** Linhas da tabela de resultados (sem telefone, e-mail, CPF). */
function vendas_export_linhas_resultados(array $fatos, int $empresaId): array
{
    $op = vendas_painel_opcoes($empresaId);
    $emp = array_column($op['empreendimentos'], 'nome', 'id');
    $usr = array_column(db()->query("SELECT id, nome FROM usuarios WHERE empresa_id = $empresaId")->fetchAll(), 'nome', 'id');
    $sit = vendas_situacoes();
    $cls = vendas_classificacoes_comerciais();
    return array_map(fn ($x) => [
        'venda' => $x['chave'], 'rm' => $x['rm'], 'cliente' => $x['cliente'], 'data' => $x['data'], 'empreendimento' => $x['emp_comprado'], 'valor' => $x['valor'],
        'situacao' => $sit[$x['situacao']][0] ?? $x['situacao'], 'origem' => $x['origem'], 'emp_inicial' => $emp[$x['emp_inicial_id']] ?? null,
        'corretor_inicial' => $usr[$x['primeiro_corretor_id']] ?? null, 'corretor_venda' => $x['corretor_venda'], 'midia' => $x['midia'],
        'transferencias' => $x['encontrada'] ? $x['transferencias'] : null, 'oferta' => $x['oferta'] ? 'Sim' : null, 'remarketing' => $x['remarketing'] ?: null,
        'jornada' => $x['dias_jornada'], 'classificacao' => $cls[$x['classificacao']][0] ?? $x['classificacao'],
    ], $fatos);
}

function vendas_export_colunas_resultados(): array
{
    return ['venda' => ['Venda (id)', 'texto'], 'rm' => ['Venda RM', 'texto'], 'cliente' => ['Cliente', 'texto'], 'data' => ['Data da venda', 'data'],
            'empreendimento' => ['Empreendimento comprado', 'texto'], 'valor' => ['Valor da venda', 'moeda'], 'situacao' => ['Cruzamento', 'texto'],
            'origem' => ['Origem no CRM', 'texto'], 'emp_inicial' => ['Empreendimento inicial', 'texto'], 'corretor_inicial' => ['Corretor inicial', 'texto'],
            'corretor_venda' => ['Corretor da venda (planilha)', 'texto'], 'midia' => ['Mídia declarada na venda', 'texto'], 'transferencias' => ['Transferências', 'int'],
            'oferta' => ['Oferta ativa', 'texto'], 'remarketing' => ['Campanhas antes da venda', 'int'], 'jornada' => ['Jornada (dias)', 'int'], 'classificacao' => ['Classificação comercial', 'texto']];
}

/** Painel (§14/§16). */
function vendas_export_painel(array $usuario, array $get, string $nivel): array
{
    $empresaId = (int) $usuario['empresa_ativa_id'];
    $f = vendas_painel_filtros($empresaId, $get);
    $r = vendas_fatos($f);
    if ($r['erro']) {
        return ['ok' => false, 'erro' => $r['erro']];
    }
    $fatos = $r['fatos'];
    $i = vendas_indicadores($fatos);
    $d = vendas_export_base($usuario, 'vendas_painel', 'Inteligência de Vendas — Painel', 'Vendas do sistema comercial × CRM', $f['periodo']['rotulo'], vendas_export_filtros($f, $empresaId), $nivel);
    $s = fn (string $n, $v, string $u = '', string $nota = '') => relexp_simples($n, $v, $u, $nota);
    $d['indicadores'] = [
        $s('Vendas importadas', $i['vendas'], '', 'Só situações marcadas como venda.'),
        $s('Já estavam no CRM (encontradas)', $i['encontradas'], '', 'Sem conflito ou confirmadas na revisão, com entrada no CRM até o dia da venda.'),
        $s('% encontradas', $i['pct_encontradas'], '%'),
        $s('Encontradas por telefone e e-mail', $i['por_ambos']), $s('Encontradas só por telefone', $i['por_telefone']), $s('Encontradas só por e-mail', $i['por_email']),
        $s('Confirmadas na revisão', $i['por_revisao']), $s('Ambíguas (aguardando revisão)', $i['ambiguas']), $s('Entraram no CRM depois da venda', $i['lead_posterior']),
        $s('Sem correspondência', $i['sem']), $s('Clientes com múltiplas oportunidades', $i['multiplas_oportunidades']),
        $s('Empreendimento diferente do 1º interesse', $i['empreendimento_diferente']), $s('Com oferta ativa antes da compra', $i['oferta_ativa']),
        $s('Com remarketing antes da compra', $i['remarketing'], '', 'Associação no tempo, não causa.'), $s('Com transferência/redistribuição antes da compra', $i['redistribuicao']),
        $s('Vendas por outro canal com histórico anterior no CRM', $i['outro_canal'], '', 'Investigação gerencial; não presume negligência nem direito à venda.'),
        $s('VGV total', $i['valor_total'], 'R$'), $s('VGV das vendas encontradas', $i['valor_encontradas'], 'R$'),
        $s('Jornada até a compra — mediana (dias)', $i['jornada_mediana']), $s('Jornada até a compra — média (dias)', $i['jornada_media']),
    ];
    $agr = fn (array $g, string $rot) => array_map(fn ($l) => ['chave' => $l['chave'], 'vendas' => $l['vendas'], 'encontradas' => $l['encontradas'], 'pct' => $l['pct'], 'valor' => $l['valor']], $g);
    $colAgr = fn (string $rot) => ['chave' => [$rot, 'texto'], 'vendas' => ['Vendas', 'int'], 'encontradas' => ['Encontradas no CRM', 'int'], 'pct' => ['% encontradas', 'dec1'], 'valor' => ['VGV', 'moeda']];
    $op = vendas_painel_opcoes($empresaId);
    $cls = vendas_classificacoes_comerciais();
    $d['tabelas'][] = ['titulo' => 'Por empreendimento comprado', 'colunas' => $colAgr('Empreendimento'), 'linhas' => $agr(vendas_agrupar($fatos, fn ($x) => $x['emp_comprado']), ''), 'totais' => null];
    $d['tabelas'][] = ['titulo' => 'Por mídia declarada na venda', 'colunas' => $colAgr('Mídia'), 'linhas' => $agr(vendas_agrupar($fatos, fn ($x) => $x['midia']), ''), 'totais' => null];
    $d['tabelas'][] = ['titulo' => 'Jornada até a compra — faixas', 'colunas' => ['faixa' => ['Faixa', 'texto'], 'vendas' => ['Vendas encontradas', 'int']], 'linhas' => vendas_faixas_jornada($fatos), 'totais' => null];
    $d['tabelas'][] = ['titulo' => 'Conversões entre empreendimentos (1º interesse → comprado)', 'colunas' => ['de' => ['1º interesse', 'texto'], 'para' => ['Comprou', 'texto'], 'vendas' => ['Vendas', 'int'], 'com_intermediarios' => ['Com outros interesses no meio', 'int'], 'valor' => ['VGV', 'moeda']],
                       'linhas' => vendas_movimentos($fatos, array_column($op['empreendimentos'], 'nome', 'id')), 'totais' => null];
    $d['tabelas'][] = ['titulo' => 'Classificação comercial (vendas encontradas)', 'colunas' => $colAgr('Classificação'),
                       'linhas' => $agr(vendas_agrupar(array_filter($fatos, fn ($x) => $x['encontrada']), fn ($x) => $cls[$x['classificacao']][0] ?? $x['classificacao']), ''), 'totais' => null];
    $d['tabelas'][] = ['titulo' => 'Produtividade comercial histórica', 'colunas' => ['nome' => ['Corretor', 'texto'], 'recebidos' => ['Leads recebidos', 'int'], 'atendidos' => ['Atendidos', 'int'], 'visitas' => ['Visitas realizadas', 'int'],
                       'transferidos' => ['Transferidos (saída)', 'int'], 'vendas_responsavel' => ['Vendas como responsável', 'int'], 'valor_responsavel' => ['VGV', 'moeda'], 'vendas_primeiro' => ['Vendas como 1º corretor', 'int']],
                       'linhas' => vendas_produtividade($empresaId, $fatos, $f['periodo']), 'totais' => null];
    if ($nivel === 'completo') {
        $d['tabelas'][] = ['titulo' => 'Resultados por venda', 'colunas' => vendas_export_colunas_resultados(), 'linhas' => vendas_export_linhas_resultados($fatos, $empresaId), 'totais' => null];
        $d['notas'][] = 'Contém o nome do cliente (dado pessoal). Uso restrito à análise interna; não use para campanhas.';
    } else {
        $d['notas'][] = 'Versão resumida: só números agregados, sem nomes de clientes.';
    }
    $d['notas'][] = 'Produtividade: leads, atendimentos, visitas e transferências contam pela data do próprio evento; vendas pela data da venda. Populações diferentes, sem taxa de conversão entre elas.';
    $d['pub'] = array_filter(['periodo' => $f['periodo_chave'], 'empreendimento' => $f['empreendimento'], 'equipe' => $f['equipe'], 'corretor' => $f['corretor'], 'origem' => $f['origem'], 'situacao' => $f['situacao']]);
    return $d;
}

/** Conciliação: contagem por situação (resumido) + lista de vendas (completo). */
function vendas_export_conciliacao(array $usuario, array $get, string $nivel): array
{
    $empresaId = (int) $usuario['empresa_ativa_id'];
    $loteF = max(0, (int) ($get['lote'] ?? 0));
    $where = 'v.empresa_id = :e' . ($loteF ? ' AND v.ultimo_lote_id = :l' : '');
    $st = db()->prepare("SELECT v.chave_origem, v.venda_rm, v.cliente_nome, v.data_venda, v.empreendimento_texto, v.valor, v.situacao, v.eh_venda, v.midia_texto,
                                c.status, c.decisao, c.cronologia, c.primeira_entrada_em, c.regras_versao,
                                (SELECT COUNT(*) FROM vendas_correspondencia_leads cl WHERE cl.venda_id = v.id AND cl.papel = 'vinculado') AS n_leads
                         FROM vendas_externas v LEFT JOIN vendas_correspondencias c ON c.venda_id = v.id WHERE $where ORDER BY v.data_venda DESC, v.id DESC");
    $st->execute($loteF ? ['e' => $empresaId, 'l' => $loteF] : ['e' => $empresaId]);
    $vendas = $st->fetchAll();
    $sit = vendas_situacoes() + ['pendente' => ['Não cruzada']];
    $d = vendas_export_base($usuario, 'vendas_conciliacao', 'Inteligência de Vendas — Conciliação', 'Cada venda importada e o resultado do cruzamento', 'Todas as vendas importadas',
        $loteF ? [['Lote', '#' . $loteF]] : [], $nivel);
    $cont = [];
    $linhas = [];
    foreach ($vendas as $v) {
        $s = $v['status'] === null ? 'pendente' : vendas_situacao_efetiva($v);
        $cont[$s] = ($cont[$s] ?? 0) + 1;
        $linhas[] = ['venda' => $v['chave_origem'], 'rm' => $v['venda_rm'], 'cliente' => $v['cliente_nome'], 'data' => $v['data_venda'], 'empreendimento' => $v['empreendimento_texto'],
                     'valor' => $v['valor'], 'situacao_venda' => $v['situacao'] . ((int) $v['eh_venda'] ? '' : ' (não conta)'), 'cruzamento' => $sit[$s][0] ?? $s,
                     'leads' => (int) $v['n_leads'] ?: null, 'entrada' => $v['primeira_entrada_em'],
                     'dias' => in_array($s, ['forte', 'telefone', 'email', 'confirmada'], true) && in_array($v['cronologia'], ['anterior', 'mesmo_dia'], true) ? vendas_dias_entre($v['primeira_entrada_em'], $v['data_venda']) : null,
                     'regras' => $v['regras_versao']];
    }
    $d['indicadores'][] = relexp_simples('Vendas importadas', count($vendas));
    foreach ($sit as $k => [$rot]) {
        if (!empty($cont[$k])) {
            $d['indicadores'][] = relexp_simples($rot, $cont[$k]);
        }
    }
    $d['tabelas'][] = ['titulo' => 'Vendas por situação do cruzamento', 'colunas' => ['s' => ['Situação', 'texto'], 'n' => ['Vendas', 'int']],
                       'linhas' => array_map(fn ($k) => ['s' => $sit[$k][0] ?? $k, 'n' => $cont[$k]], array_keys($cont)), 'totais' => ['s' => 'Total', 'n' => count($vendas)]];
    if ($nivel === 'completo') {
        $d['tabelas'][] = ['titulo' => 'Vendas', 'colunas' => ['venda' => ['Venda (id)', 'texto'], 'rm' => ['Venda RM', 'texto'], 'cliente' => ['Cliente', 'texto'], 'data' => ['Data da venda', 'data'],
                           'empreendimento' => ['Empreendimento (planilha)', 'texto'], 'valor' => ['Valor', 'moeda'], 'situacao_venda' => ['Situação da proposta', 'texto'], 'cruzamento' => ['Cruzamento', 'texto'],
                           'leads' => ['Leads vinculados', 'int'], 'entrada' => ['1ª entrada no CRM', 'data'], 'dias' => ['Dias até a compra', 'int'], 'regras' => ['Regras', 'texto']], 'linhas' => $linhas, 'totais' => null];
        $d['notas'][] = 'Contém o nome do cliente (dado pessoal). Uso restrito à análise interna.';
    }
    $d['pub'] = array_filter(['lote' => $loteF]);
    return $d;
}

/** Jornada de UMA venda (só com acesso pela carteira atual). */
function vendas_export_jornada(array $usuario, int $vendaId): array
{
    $J = vendas_jornada($usuario, $vendaId);
    if (!$J) {
        return ['ok' => false, 'erro' => 'Venda não encontrada.'];
    }
    if ($J['acesso'] !== 'ok') {
        return ['ok' => false, 'erro' => $J['acesso'] === 'sem_permissao' ? 'Os leads deste cliente não estão na sua carteira atual.' : 'Esta venda não tem jornada no CRM.'];
    }
    $v = $J['venda'];
    $m = $J['metricas'];
    $d = vendas_export_base($usuario, 'vendas_jornada', 'Jornada do Cliente — venda ' . $v['chave_origem'], 'Da 1ª entrada no CRM até a venda', 'Venda em ' . date('d/m/Y', strtotime($v['data_venda'])), [], 'completo');
    $s = fn (string $n, $val, string $u = '', string $nota = '') => relexp_simples($n, $val, $u, $nota);
    $cls = vendas_classificacoes_comerciais();
    $d['indicadores'] = [
        $s('Cliente', $v['cliente_nome']), $s('Empreendimento comprado', $J['movimento']['comprado_nome']), $s('Valor da venda', $v['valor'], 'R$'),
        $s('1ª entrada no CRM', $m['primeira_entrada'] ? date('d/m/Y', strtotime($m['primeira_entrada'])) : null), $s('Jornada (dias)', $m['dias_jornada']),
        $s('Até o 1º atendimento humano (dias)', $m['dias_ate_primeiro_atendimento']), $s('1ª visita → venda (dias)', $m['dias_primeira_visita_ate_venda']),
        $s('Última interação → venda (dias)', $m['dias_ultima_interacao_ate_venda']), $s('Dias com interação efetiva', $m['dias_com_interacao_efetiva']),
        $s('Transferências', $m['transferencias']), $s('Tentativas e contatos', $m['tentativas_contato']), $s('Campanhas de remarketing antes da venda', $m['campanhas_remarketing'], '', 'Associação no tempo.'),
        $s('Oferta ativa', $m['oferta_ativa'] === 'sim' ? 'Sim' : 'Sem registro'), $s('Primeiro corretor no CRM', $m['primeiro_corretor'] ?? 'Não disponível'),
        $s('Corretor da venda (planilha)', $v['corretor_texto']), $s('Mídia declarada na venda', $v['midia_texto']),
        $s('Classificação comercial', $cls[$J['classificacao']][0] ?? $J['classificacao'], '', 'Análise gerencial; não atribui crédito nem culpa.'),
        $s('Empreendimento de entrada × comprado', ['mesmo' => 'Mesmo', 'diferente' => 'Diferente', 'sem_equivalencia' => 'Sem equivalência', 'sem_interesse' => 'Interesse não registrado'][$J['movimento']['resultado']]),
    ];
    $d['tabelas'][] = ['titulo' => 'Quem atendeu', 'colunas' => ['nome' => ['Corretor', 'texto'], 'equipe' => ['Equipe', 'texto'], 'gerente' => ['Gerente', 'texto'], 'forma' => ['Como recebeu', 'texto'],
                       'de' => ['De', 'data'], 'ate' => ['Até', 'data'], 'dias_ate_venda' => ['Dias até a venda', 'int']], 'linhas' => $J['corretores'], 'totais' => null];
    $nat = ['confirmado' => 'Registrado', 'importado' => 'Importado', 'inferido' => 'Reconstruído'];
    $d['tabelas'][] = ['titulo' => 'Linha do tempo', 'colunas' => ['em' => ['Quando', 'datahora'], 'titulo' => ['Evento', 'texto'], 'detalhe' => ['Detalhe', 'texto'], 'op' => ['Oportunidade', 'texto'], 'natureza' => ['Natureza', 'texto'], 'quando' => ['Momento', 'texto']],
                       'linhas' => array_map(fn ($e) => ['em' => $e['em'], 'titulo' => $e['titulo'], 'detalhe' => $e['detalhe'], 'op' => $e['lead_id'] ? ($J['leads'][$e['lead_id']]['emp_nome'] ?? 'Lead #' . $e['lead_id']) : null,
                                                          'natureza' => $nat[$e['natureza']] ?? $e['natureza'], 'quando' => $e['depois_da_venda'] ? 'Depois da venda' : null], $J['eventos']), 'totais' => null];
    foreach ($J['incompleto'] as $t) {
        $d['notas'][] = 'Histórico incompleto: ' . $t;
    }
    if ($J['ocultos']) {
        $d['notas'][] = $J['ocultos'] . ' oportunidade(s) do cliente fora da carteira de quem exportou não foram incluídas.';
    }
    $d['notas'][] = 'Contém dados pessoais do cliente. Uso restrito à análise interna; a importação de vendas não autoriza comunicações.';
    $d['pub'] = ['venda' => (int) $v['id']];
    return $d;
}

/**
 * Gera o arquivo. Retorna ['ok','nome','mime','extensao','linhas','pub'] ou ['ok' => false, 'erro'].
 * Valida permissões: ver_inteligencia_vendas + exportar_relatorios.
 */
function vendas_export_gerar(array $usuario, string $tipo, string $formato, string $nivel, array $get, string $destino): array
{
    if (!usuario_tem_permissao($usuario, 'ver_inteligencia_vendas') || !usuario_tem_permissao($usuario, 'exportar_relatorios')) {
        return ['ok' => false, 'erro' => 'Sua função não pode exportar a Inteligência de Vendas.'];
    }
    if (!isset(vendas_export_tipos()[$tipo]) || !in_array($formato, ['xlsx', 'csv', 'pdf'], true) || !in_array($nivel, ['resumido', 'completo'], true)) {
        return ['ok' => false, 'erro' => 'Opção de exportação inválida.'];
    }
    $d = match ($tipo) {
        'painel' => vendas_export_painel($usuario, $get, $nivel),
        'conciliacao' => vendas_export_conciliacao($usuario, $get, $nivel),
        'jornada' => vendas_export_jornada($usuario, (int) ($get['venda'] ?? 0)),
    };
    if (!$d['ok']) {
        return $d;
    }
    // CSV: uma tabela só — a mais detalhada (a última).
    if ($formato === 'csv' && $d['tabelas']) {
        $d['tabelas'] = [end($d['tabelas'])];
    }
    $r = relarq_gerar($d, $formato, $destino);
    $nome = exportacao_nome_arquivo($d['titulo'] . ($nivel === 'resumido' && $tipo !== 'jornada' ? ' resumo' : '')) . '.' . $r['extensao'];
    return ['ok' => true, 'nome' => $nome, 'pub' => $d['pub'], 'titulo' => $d['titulo']] + $r;
}
