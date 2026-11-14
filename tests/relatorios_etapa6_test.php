<?php
/**
 * Central de Relatórios — Etapa 6: investimento e custos (rateio por dia,
 * planilha tudo-ou-nada, regras de quando o custo pode ser mostrado),
 * modelos prontos e personalizados (PDF/Excel compostos, permissões) e o
 * painel de qualidade.
 *
 *   php -q tests/relatorios_etapa6_test.php
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../includes/funcoes.php';
require_once __DIR__ . '/../includes/funil.php';
require_once __DIR__ . '/../includes/investimentos.php';
require_once __DIR__ . '/../includes/relatorios/modelos.php';
require_once __DIR__ . '/../includes/relatorios/fila_exportacao.php';
require_once __DIR__ . '/../includes/relatorios/auditoria_dados.php';

function v(string $sql)
{
    return db()->query($sql)->fetchColumn();
}

grupo('Valor digitado');
igual(investimento_valor_texto('12.500,00'), 12500.0, '"12.500,00"');
igual(investimento_valor_texto('R$ 1.234,56'), 1234.56, '"R$ 1.234,56"');
igual(investimento_valor_texto('3000'), 3000.0, '"3000"');
igual(investimento_valor_texto('abc'), null, 'texto → inválido');

$emp = criar_empresa_teste('Relatorios E6');
$tmp = [];
$arq = function (string $ext) use (&$tmp) {
    $t = tempnam(sys_get_temp_dir(), 'wm6') . '.' . $ext;
    $tmp[] = $t;
    return $t;
};
try {
    $pdo = db();
    $f = funcoes_seed_padrao($emp);
    funil_seed_padrao($emp);
    $etapa = funil_primeira_etapa($emp);
    $uid = function (string $nome, string $cargo) use ($pdo, $emp, $f) {
        $pdo->prepare('INSERT INTO usuarios (empresa_id, funcao_id, nome, email, senha_hash, cargo) VALUES (:e, :f, :n, :m, :h, :c)')
            ->execute(['e' => $emp, 'f' => $f[$cargo], 'n' => $nome, 'm' => bin2hex(random_bytes(5)) . '@teste.invalid', 'h' => 'x', 'c' => $cargo]);
        return (int) $pdo->lastInsertId();
    };
    $master = $uid('Master', 'master_empresa');
    $gA = $uid('Gerente A', 'gerente');
    $pdo->prepare("INSERT INTO equipes (empresa_id, nome, gerente_id) VALUES (:e, 'Equipe A', :g)")->execute(['e' => $emp, 'g' => $gA]);
    $eqA = (int) $pdo->lastInsertId();
    $c1 = $uid('Corretor', 'corretor');
    $pdo->exec("UPDATE usuarios SET equipe_id = $eqA WHERE id = $c1");
    $M = relexp_usuario($master, $emp);
    $GA = relexp_usuario($gA, $emp);
    $C1 = relexp_usuario($c1, $emp);
    ok(in_array('gerenciar_investimentos', $M['permissoes'], true) && !in_array('gerenciar_investimentos', $GA['permissoes'], true), 'permissão nova: Master tem, gerente não');
    $pdo->prepare("INSERT INTO empreendimentos (empresa_id, nome) VALUES (:e, 'Emp Um'), (:e2, 'Emp Dois')")->execute(['e' => $emp, 'e2' => $emp]);
    $emp1 = (int) $pdo->lastInsertId();
    $emp2 = $emp1 + 1;
    $meta = origem_id_do_canal($emp, 'meta_ads');
    $google = (int) v("SELECT id FROM lead_origens WHERE empresa_id = $emp AND nome = 'Google Ads'");
    $lead = function (int $origem, int $empr, string $quando, bool $contrato = false) use ($pdo, $emp, $etapa, $c1, $eqA) {
        $pdo->prepare('INSERT INTO leads (empresa_id, empreendimento_id, nome, origem, origem_detalhe_id, etapa_id, etapa, criado_em, ultima_atividade_em, responsavel_id, equipe_id)
                       VALUES (:e, :emp, :n, :o, :od, :et, :cat, :q, :q2, :r, :eq)')
            ->execute(['e' => $emp, 'emp' => $empr, 'n' => 'L', 'o' => 'outro', 'od' => $origem, 'et' => $etapa['id'], 'cat' => $etapa['categoria'], 'q' => $quando, 'q2' => $quando, 'r' => $c1, 'eq' => $eqA]);
        $id = (int) $pdo->lastInsertId();
        historico_abrir_atribuicao($id, $c1, 'cadastro', ['em' => $quando]);
        if ($contrato) {
            $pdo->exec("INSERT INTO lead_contratos (empresa_id, lead_id, usuario_id, equipe_id, assinado_em, status) VALUES ($emp, $id, $c1, $eqA, DATE('$quando'), 'validado')");
        }
        return $id;
    };
    // Junho: 4 leads Meta (Emp Um 3, Emp Dois 1; 1 contrato), 2 leads Google, 1 Meta fora do período.
    $lead($meta, $emp1, '2026-06-02 10:00:00', true);
    $lead($meta, $emp1, '2026-06-05 10:00:00');
    $lead($meta, $emp1, '2026-06-09 10:00:00');
    $lead($meta, $emp2, '2026-06-08 10:00:00');
    $lead($google, $emp1, '2026-06-03 10:00:00');
    $lead($google, $emp1, '2026-06-04 10:00:00');
    $lead($meta, $emp1, '2026-07-15 10:00:00');
    $G = ['periodo' => 'personalizado', 'ini' => '2026-06-01', 'fim' => '2026-06-10'];
    $F = relatorio_filtros($M, $G);

    grupo('Lançamentos');
    ok(!investimento_salvar($M, ['data_inicio' => '2026-06-30', 'data_fim' => '2026-06-01', 'origem_detalhe_id' => $meta, 'valor' => '100'])['ok'], 'fim antes do início → recusado');
    ok(!investimento_salvar($M, ['data_inicio' => '2026-06-01', 'data_fim' => '2026-06-30', 'origem_detalhe_id' => 999999, 'valor' => '100'])['ok'], 'origem inexistente → recusado');
    ok(!investimento_salvar($M, ['data_inicio' => '2026-06-01', 'data_fim' => '2026-06-30', 'origem_detalhe_id' => $meta, 'valor' => '0'])['ok'], 'valor zero → recusado');
    ok(!investimento_salvar($M, ['data_inicio' => '2025-01-01', 'data_fim' => '2026-06-30', 'origem_detalhe_id' => $meta, 'valor' => '100'])['ok'], 'período maior que 1 ano → recusado');
    igual(relatorio_custos($M, $F)['investimento'], null, 'sem lançamento: investimento "não disponível" (null, não zero)');
    $r = investimento_salvar($M, ['data_inicio' => '2026-06-01', 'data_fim' => '2026-06-30', 'origem_detalhe_id' => $meta, 'valor' => '3.000,00', 'descricao' => 'Meta junho']);
    ok($r['ok'], 'Meta: R$ 3.000 de 01 a 30/06 (todos os empreendimentos)');
    investimento_salvar($M, ['data_inicio' => '2026-06-01', 'data_fim' => '2026-06-10', 'origem_detalhe_id' => $google, 'empreendimento_id' => $emp1, 'valor' => '500']);

    grupo('Rateio por dia');
    igual(investimento_no_periodo($emp, '2026-06-01', '2026-06-10', [$meta])['valor'], 1000.0, 'Meta em 01–10/06: 10 de 30 dias = R$ 1.000');
    igual(investimento_no_periodo($emp, '2026-05-20', '2026-06-01', [$meta])['valor'], 100.0, 'só 1 dia dentro do período = R$ 100');
    igual(investimento_no_periodo($emp, '2026-07-01', '2026-07-31', [$meta])['valor'], null, 'período sem lançamento → null');
    igual(investimento_no_periodo($emp, '2026-06-01', '2026-06-10')['valor'], 1500.0, 'todas as origens: 1.000 + 500');
    igual(investimento_no_periodo($emp, '2026-06-01', '2026-06-10', [$meta], [$emp1])['valor'], null, 'filtro de empreendimento: lançamento "todos" não entra (não pode ser dividido)');
    igual(investimento_no_periodo($emp, '2026-06-01', '2026-06-10', [$google], [$emp1])['valor'], 500.0, 'lançamento do empreendimento entra');

    grupo('Custos por resultado');
    $c = relatorio_custos($M, $F);
    ok($c['disponivel'] && $c['investimento'] === 1500.0 && $c['resultados']['leads'] === 6, 'investimento 1.500 sobre os 6 leads das origens investidas no período');
    igual($c['cpl'], 250.0, 'custo por lead = 1.500 ÷ 6 = R$ 250');
    igual($c['custo_contrato'], 1500.0, 'custo por contrato = 1.500 ÷ 1');
    igual($c['custo_visita'], null, 'sem visita → custo por visita "—"');
    igual($c['roi'], null, 'ROI nunca calculado');
    $o = array_column(relatorio_origens($M, $F, 'origem'), null, 'nome');
    ok($o['Meta Ads']['investimento'] === 1000.0 && $o['Meta Ads']['custo_por_lead'] === 250.0 && $o['Meta Ads']['custo_por_contrato'] === 1000.0, 'por origem: Meta 1.000 ÷ 4 leads = 250; ÷ 1 contrato = 1.000');
    ok($o['Google Ads']['investimento'] === 500.0 && $o['Google Ads']['custo_por_lead'] === 250.0 && $o['Google Ads']['custo_por_contrato'] === null, 'Google: 500 ÷ 2 = 250; sem contrato → "—"');
    $Fe = relatorio_filtros($M, $G + ['empreendimento' => $emp1]);
    $ce = relatorio_custos($M, $Fe);
    ok($ce['investimento'] === 500.0 && $ce['resultados']['leads'] === 2 && $ce['cpl'] === 250.0, 'filtro Emp Um: só o lançamento do Google para o Emp Um (500 ÷ 2 leads Google)');
    ok(!relatorio_custos($GA, relatorio_filtros($GA, $G))['disponivel'], 'gerente (recorte de equipe): custos não disponíveis');
    ok(!relatorio_custos($M, relatorio_filtros($M, $G + ['equipe' => $eqA]))['disponivel'], 'filtro de equipe: custos não disponíveis');
    $oGA = relatorio_origens($GA, relatorio_filtros($GA, $G), 'origem');
    ok($oGA && !array_filter(array_column($oGA, 'custo_por_lead')), 'gerente: tabela de origens sem custos');
    ok(relatorio_custos($M, relatorio_filtros($M, $G + ['canal' => 'meta_ads']))['investimento'] === 1000.0, 'filtro de grupo (Meta Ads): só o investimento do grupo');

    grupo('Planilha (tudo ou nada)');
    $antes = (int) v("SELECT COUNT(*) FROM marketing_investimentos WHERE empresa_id = $emp");
    $r = investimento_importar_csv($M, "data_inicio;data_fim;origem;empreendimento;campanha;valor\n01/07/2026;31/07/2026;Meta Ads;;Julho;1.550,00\n01/07/2026;31/07/2026;TV Aberta;;x;10\n");
    ok(!$r['ok'] && str_contains(implode(' ', $r['erros']), 'Linha 3') && (int) v("SELECT COUNT(*) FROM marketing_investimentos WHERE empresa_id = $emp") === $antes, 'uma linha errada (linha 3): nada importado, erro aponta a linha');
    $r = investimento_importar_csv($M, "\xEF\xBB\xBFdata_inicio;data_fim;origem;empreendimento;campanha;valor\n01/07/2026;31/07/2026;Meta Ads;Emp Um;Julho;1.550,00\n01/07/2026;31/07/2026;Google Ads;;Busca;310\n");
    ok($r['ok'] && $r['importados'] === 2, 'planilha válida: 2 lançamentos');
    igual(investimento_no_periodo($emp, '2026-07-01', '2026-07-31', [$meta], [$emp1])['valor'], 1550.0, 'valor e empreendimento da planilha corretos');
    ok(!investimento_importar_csv($M, "data;valor\n1;2")['ok'], 'cabeçalho sem as colunas obrigatórias → recusado');
    $um = (int) v("SELECT MAX(id) FROM marketing_investimentos WHERE empresa_id = $emp");
    ok(investimento_excluir($M, $um) && !investimento_excluir($M, $um), 'exclusão (lógica) só uma vez');
    igual(investimento_no_periodo($emp, '2026-07-01', '2026-07-31', [$google])['valor'], null, 'excluído não entra no cálculo');

    grupo('Modelos prontos');
    foreach (relmod_prontos() as $k => [$nome, , $secoes]) {
        $a = $arq('pdf');
        $r = relmod_gerar($M, 'p:' . $k, 'pdf', $G, $a);
        ok($r['ok'] && $r['secoes'] === count($secoes) && str_starts_with((string) file_get_contents($a, false, null, 0, 8), '%PDF'), "\"$nome\": PDF com " . count($secoes) . ' seções');
    }
    $x = $arq('xlsx');
    $r = relmod_gerar($M, 'p:executivo_mensal', 'xlsx', $G, $x);
    $z = new ZipArchive();
    $z->open($x);
    preg_match_all('/<sheet name="([^"]+)"/', (string) $z->getFromName('xl/workbook.xml'), $m);
    $aba2 = (string) $z->getFromName('xl/worksheets/sheet3.xml');
    $z->close();
    ok($r['ok'] && $m[1][0] === 'Capa' && end($m[1]) === 'Notas' && count($m[1]) === 6, 'Excel do Executivo Mensal: Capa + 4 seções + Notas');
    ok(str_contains($aba2, '1500'), 'aba de Origens traz o investimento lançado (1.500)');
    $d = relmod_dados($M, relmod_carregar($M, 'p:executivo_mensal'), $G);
    $tela = relatorio_executivo($M, $F)['indicadores']['leads_recebidos']['valor'];
    igual(array_column($d['secoes'][0]['indicadores'], 'valor', 'nome')['Leads recebidos'], $tela, 'seção do modelo = número da tela');
    $soVer = $GA;
    $soVer['permissoes'] = ['visualizar_indicadores'];
    ok(!relmod_gerar($soVer, 'p:executivo_mensal', 'pdf', $G, $arq('pdf'))['ok'], 'vê indicadores mas não pode exportar → recusado');
    $soExp = $GA;
    $soExp['permissoes'] = ['exportar_relatorios'];
    $r = relmod_gerar($soExp, 'p:executivo_mensal', 'pdf', $G, $arq('pdf'));
    ok(!$r['ok'] && str_contains($r['erro'], 'Nenhuma seção'), 'pode exportar mas não vê nenhuma seção → nenhuma seção gerada (cada seção é conferida)');
    $GAx = $GA;
    $GAx['permissoes'][] = 'exportar_relatorios';
    $GAx['permissoes'][] = 'visualizar_indicadores';
    ok(relmod_gerar($GAx, 'p:produtividade', 'pdf', $G, $arq('pdf'))['ok'], 'gerente gera com o próprio recorte');
    ok(!relmod_gerar($M, 'p:inexistente', 'pdf', $G, $arq('pdf'))['ok'] && !relmod_gerar($M, 'p:perdas', 'docx', $G, $arq('x'))['ok'], 'modelo ou formato inválido → recusado');

    grupo('Modelos personalizados');
    if (relmod_instalados()) {
        ok(relmod_salvar($M, 'Reunião de segunda', ['executiva', 'funil', 'corretor', 'xyz'], 'periodo=mes_anterior&evil=1')['ok'], 'modelo salvo');
        $meus = relmod_meus($M);
        ok(count($meus) === 1 && $meus[0]['secoes'] === 'executiva,funil' && !str_contains($meus[0]['filtros'], 'evil'), 'só seções válidas (corretor e "xyz" fora) e filtros conhecidos');
        ok(!relmod_salvar($M, 'X', ['executiva'], '')['ok'] && !relmod_salvar($M, 'Vazio', [], '')['ok'], 'nome curto ou sem seções → recusado');
        igual(relmod_meus($GA), [], 'outro usuário não vê');
        ok(relmod_carregar($GA, 'u:' . $meus[0]['id']) === null, 'nem consegue usar pelo id');
        $r = relmod_gerar($M, 'u:' . $meus[0]['id'], 'pdf', $G, $arq('pdf'));
        ok($r['ok'] && $r['secoes'] === 2, 'gera o personalizado (2 seções), com o período escolhido na hora');
        ok(!relmod_excluir($GA, (int) $meus[0]['id']) && relmod_excluir($M, (int) $meus[0]['id']), 'só o dono exclui');
    } else {
        ok(false, 'migração 016 não aplicada');
    }

    grupo('Painel de qualidade');
    $q = relatorio_auditoria_dados($emp);
    $mk = array_column($q['Marketing'], null, 'rotulo');
    ok(isset($mk['Lançamentos de investimento']) && $mk['Lançamentos de investimento']['valor'] >= 3 && $mk['Lançamentos de investimento']['nivel'] === 'ok', 'lançamentos contados (nível ok)');
    $json = json_encode($q, JSON_UNESCAPED_UNICODE);
    ok(!str_contains($json, '@teste.invalid'), 'painel sem dados pessoais');
} finally {
    foreach ($tmp as $t) {
        @unlink($t);
    }
    apagar_empresa_teste($emp);
}
resumo_e_sair();
