<?php
/**
 * Central de Relatórios — Etapa 5: exportações (Excel, CSV, PDF, dados,
 * fila em segundo plano). Confere que o arquivo traz os MESMOS números da
 * tela, a proteção contra fórmulas, a minimização (sem telefone/e-mail), as
 * permissões e o ciclo da fila (gerar, baixar só com token e dono, expirar).
 *
 *   php -q tests/relatorios_etapa5_test.php
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../includes/funcoes.php';
require_once __DIR__ . '/../includes/funil.php';
require_once __DIR__ . '/../includes/relatorios/fila_exportacao.php';
require_once __DIR__ . '/../includes/relatorios/pdf_relatorio.php';

function v(string $sql)
{
    return db()->query($sql)->fetchColumn();
}
/** Lê uma aba do .xlsx como texto XML. */
function xlsx_aba(string $arq, int $n): string
{
    $z = new ZipArchive();
    $z->open($arq);
    $x = (string) $z->getFromName('xl/worksheets/sheet' . $n . '.xml');
    $z->close();
    return $x;
}
/** Conteúdo (descomprimido) das páginas do PDF. */
function pdf_texto(string $arq): string
{
    $pdf = file_get_contents($arq);
    $out = '';
    preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $m);
    foreach ($m[1] as $s) {
        $out .= @gzuncompress($s) ?: $s;
    }
    return $out;
}

grupo('PDF: gerador próprio');
$p = new PdfSimples();
$p->fonte(false, 10);
igual(round($p->larguraTexto('0123456789'), 2), 55.6, 'Helvetica: 10 dígitos de 556 milésimos em corpo 10 = 55,6 pt');
igual(round($p->larguraTexto('á'), 2), round($p->larguraTexto('a'), 2), 'letra acentuada tem a largura da letra base');
$p->fonte(true, 10);
ok($p->larguraTexto('Bold') > (new PdfSimples())->larguraTexto('Bold', false, 10), 'negrito é mais largo');
igual(PdfSimples::cp1252('Visão → ação'), "Vis\xE3o -> a\xE7\xE3o", 'UTF-8 → Windows-1252 (seta vira ->)');
$p = new PdfSimples();
$p->fonte(false, 10);
$linhas = $p->quebrar('palavra palavra palavra palavra palavra palavra', 100);
ok(count($linhas) > 1 && max(array_map(fn ($l) => $p->larguraTexto($l), $linhas)) <= 100, 'quebra de linha respeita a largura');
ok($p->larguraTexto($p->caber(str_repeat('texto longo ', 20), 80)) <= 80, 'corte com reticências cabe na largura');

$emp = criar_empresa_teste('Relatorios E5');
$tmp = [];
$arq = function (string $ext) use (&$tmp) {
    $t = tempnam(sys_get_temp_dir(), 'wmt') . '.' . $ext;
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
    $master = $uid('Master Teste', 'master_empresa');
    $gA = $uid('Gerente A', 'gerente');
    $pdo->prepare("INSERT INTO equipes (empresa_id, nome, gerente_id) VALUES (:e, 'Equipe A', :g)")->execute(['e' => $emp, 'g' => $gA]);
    $eqA = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO equipes (empresa_id, nome) VALUES (:e, 'Equipe B')")->execute(['e' => $emp]);
    $eqB = (int) $pdo->lastInsertId();
    $c1 = $uid('Corretor Um', 'corretor');
    $c2 = $uid('Corretor Dois', 'corretor');
    $pdo->exec("UPDATE usuarios SET equipe_id = $eqA WHERE id = $c1");
    $pdo->exec("UPDATE usuarios SET equipe_id = $eqB WHERE id = $c2");
    $M = relexp_usuario($master, $emp);
    $GA = relexp_usuario($gA, $emp);
    $GA['permissoes'][] = 'exportar_relatorios';
    $C1 = relexp_usuario($c1, $emp);
    $novo = function (string $nome, int $dono, string $quando, ?string $tel = '11999990000') use ($pdo, $emp, $etapa) {
        $eq = (int) v("SELECT equipe_id FROM usuarios WHERE id = $dono");
        $pdo->prepare('INSERT INTO leads (empresa_id, nome, telefone, email, origem, etapa_id, etapa, criado_em, ultima_atividade_em, responsavel_id, equipe_id, atribuido_em)
                       VALUES (:e, :n, :t, :m, \'manual\', :et, :cat, :q, :q2, :d, :eq, :q3)')
            ->execute(['e' => $emp, 'n' => $nome, 't' => $tel, 'm' => 'cliente@exemplo.invalid', 'et' => $etapa['id'], 'cat' => $etapa['categoria'], 'q' => $quando, 'q2' => $quando, 'd' => $dono, 'eq' => $eq, 'q3' => $quando]);
        $id = (int) $pdo->lastInsertId();
        historico_abrir_atribuicao($id, $dono, 'cadastro', ['em' => $quando]);
        return $id;
    };
    $L1 = $novo('=HYPERLINK("http://mal.invalid","clique")', $c1, '2026-03-02 10:00:00');
    $L2 = $novo('Cliente Dois', $c1, '2026-03-03 10:00:00');
    $L3 = $novo('Cliente Três', $c2, '2026-03-04 10:00:00');
    $L4 = $novo('Excluído', $c2, '2026-03-05 10:00:00');
    $pdo->exec("UPDATE leads SET excluido_em = NOW() WHERE id = $L4");
    $pdo->exec("UPDATE lead_atribuicoes SET primeiro_atendimento_em = DATE_ADD(atribuido_em, INTERVAL 1 HOUR), primeiro_contato_efetivo_em = DATE_ADD(atribuido_em, INTERVAL 2 HOUR) WHERE lead_id = $L2");
    $pdo->exec("UPDATE leads SET primeiro_contato_efetivo_em = '2026-03-03 12:00:00' WHERE id = $L2");
    $pdo->prepare("INSERT INTO lead_contratos (empresa_id, lead_id, usuario_id, equipe_id, assinado_em, valor_vgv, status) VALUES (:e, :l, :u, :eq, '2026-03-20', 312345.67, 'validado')")
        ->execute(['e' => $emp, 'l' => $L2, 'u' => $c1, 'eq' => $eqA]);
    $G = ['periodo' => 'personalizado', 'ini' => '2026-03-01', 'fim' => '2026-03-31'];
    $F = relatorio_filtros($M, $G);

    grupo('Mesmos números da tela');
    $d = relexp_dados($M, 'executiva', $G, 'completo');
    $ex = relatorio_executivo($M, $F)['indicadores'];
    $porNome = array_column($d['indicadores'], 'valor', 'nome');
    ok($porNome['Leads recebidos'] === $ex['leads_recebidos']['valor'] && $porNome['Leads recebidos'] === 3, 'executiva: leads recebidos = tela (3; o excluído não entra)');
    ok($porNome['VGV validado'] === $ex['vgv']['valor'] && $porNome['VGV validado'] === 312345.67, 'executiva: VGV = tela');
    igual($d['empresa'], (string) v("SELECT nome FROM empresas WHERE id = $emp"), 'cabeçalho: empresa');
    ok(str_contains($d['periodo'], '01/03/2026 a 31/03/2026') && $d['comparado'] === '29/01/2026 a 28/02/2026', 'cabeçalho: período e comparação');
    ok(str_contains($d['fuso'], 'America/Sao_Paulo'), 'cabeçalho: fuso');
    $dg = relexp_dados($M, 'gestao', $G + ['agrupar' => 'corretor', 'criterio' => 'leads_recebidos'], 'completo');
    $tela = relatorio_agrupado($M, $F, 'corretor');
    igual(array_column($dg['tabelas'][0]['linhas'], 'leads_recebidos', 'id'), array_column($tela, 'leads_recebidos', 'id'), 'gestão: tabela = tela');
    igual(array_column($dg['tabelas'][0]['linhas'], 'posicao', 'id'), [$c1 => 1, $c2 => 2], 'gestão: posição do ranking pelo critério escolhido');
    $dGA = relexp_dados($GA, 'gestao', $G + ['agrupar' => 'corretor'], 'completo');
    igual(array_column($dGA['tabelas'][0]['linhas'], 'id'), [$c1], 'gerente A exporta só a equipe A');
    igual(relexp_dados($M, 'gestao', $G + ['equipe' => $eqA], 'completo')['filtros'], [['Equipe', 'Equipe A (Gerente A)']], 'filtros com o NOME (não o id)');
    ok(!relexp_dados($C1, 'gestao', $G)['ok'] && !relexp_dados($M, 'executiva', ['periodo' => 'personalizado', 'ini' => '2026-02-30', 'fim' => '2026-03-01'])['ok'], 'sem acesso ou período inválido → sem arquivo');
    ok(count($d['tabelas']) > 1 && count(relexp_dados($M, 'executiva', $G, 'resumido')['tabelas']) === 1, 'resumido: só a tabela principal (o completo tem todas)');
    foreach (['corretor' => ['corretor' => (string) $c1], 'empreendimentos' => [], 'funil' => [], 'plantoes' => [], 'origens' => [], 'visitas' => [], 'perdas' => []] as $r => $g) {
        $x = relexp_dados($M, $r, $G + $g, 'completo');
        ok($x['ok'] && is_array($x['indicadores']) && is_array($x['tabelas']), "\"$r\": dados montados");
    }

    grupo('Excel (.xlsx)');
    $a = $arq('xlsx');
    $r = relarq_gerar($d, 'xlsx', $a);
    $z = new ZipArchive();
    ok($z->open($a) === true && $z->getFromName('xl/workbook.xml') !== false, 'arquivo .xlsx válido (zip OOXML)');
    preg_match_all('/<sheet name="([^"]+)"/', (string) $z->getFromName('xl/workbook.xml'), $m);
    $z->close();
    igual($m[1], ['Resumo', 'Detalhamento', 'Notas'], 'abas Resumo, Detalhamento e Notas');
    $resumo = xlsx_aba($a, 1);
    ok(str_contains($resumo, 'Período') && str_contains($resumo, '01/03/2026 a 31/03/2026') && str_contains($resumo, 'Fuso horário'), 'Resumo com período e fuso');
    ok(str_contains($resumo, '<v>312345.67</v>'), 'VGV gravado como NÚMERO (não texto)');
    igual($r['linhas'], array_sum(array_map(fn ($t) => count($t['linhas']), $d['tabelas'])), '"linhas" = linhas de dados das tabelas');

    grupo('Exportação de dados (uma linha por lead)');
    $dd = relexp_dados($M, 'executiva', $G, 'dados');
    igual(relexp_dados_contar($M, $G), 3, 'contagem: 3 leads (sem o excluído)');
    $a = $arq('xlsx');
    $r = relarq_gerar($dd, 'xlsx', $a, relexp_dados_linhas($M, $G));
    igual($r['linhas'], 3, '3 linhas de dados');
    $dados = xlsx_aba($a, 1);
    ok(str_contains($dados, '<t xml:space="preserve">&apos;=HYPERLINK') && !str_contains($dados, '<t xml:space="preserve">=HYPERLINK') && !str_contains($dados, '<f>'), 'nome que começa com "=" vira texto (apóstrofo): sem fórmula');
    ok(!str_contains($dados, '11999990000') && !str_contains($dados, 'cliente@exemplo'), 'sem telefone e sem e-mail (minimização)');
    ok(!str_contains($dados, 'Excluído'), 'lead excluído não sai');
    igual(array_keys(array_filter(relexp_colunas_dados(), fn ($c) => stripos($c[0], 'telefone') !== false || stripos($c[0], 'mail') !== false)), [], 'nenhuma coluna de contato');
    $a = $arq('xlsx');
    relarq_gerar($dd, 'xlsx', $a, relexp_dados_linhas($GA, $G));
    $dadosGA = xlsx_aba($a, 1);
    ok(str_contains($dadosGA, 'Cliente Dois') && !str_contains($dadosGA, 'Cliente Três'), 'gerente A: só os leads visíveis para ele');
    $c = $arq('csv');
    $r = relarq_gerar($dd, 'csv', $c, relexp_dados_linhas($M, $G));
    $csv = file_get_contents($c);
    ok(str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, ';') && str_contains($csv, "\r\n"), 'CSV: UTF-8 com BOM, ";" e CRLF');
    ok(str_contains($csv, '312345,67') && str_contains($csv, "'=HYPERLINK"), 'CSV: vírgula decimal e fórmula neutralizada');
    ok(str_contains($csv, '03/03/2026 10:00'), 'CSV: data no formato brasileiro');

    grupo('PDF');
    $pp = $arq('pdf');
    $r = relarq_gerar($dg, 'pdf', $pp);
    $bin = file_get_contents($pp);
    ok(str_starts_with($bin, '%PDF-1.4') && str_ends_with(trim($bin), '%%EOF') && preg_match('/startxref\n(\d+)/', $bin, $sx) && substr($bin, (int) $sx[1], 4) === 'xref', 'PDF bem formado (cabeçalho, xref no lugar, %%EOF)');
    $txt = pdf_texto($pp);
    ok(str_contains($txt, PdfSimples::cp1252('Equipes, gerentes e superintendências')) && str_contains($txt, 'Corretor Um'), 'título e nomes no PDF');
    ok(str_contains($txt, 'P' . PdfSimples::cp1252('á') . 'gina 1 de ' . $r['paginas']), 'numeração "Página X de Y"');
    igual(preg_match_all('/\/Type \/Page /', $bin), $r['paginas'], 'número de páginas consistente');
    $muitas = $dg;
    $muitas['tabelas'][0]['linhas'] = array_fill(0, 120, $dg['tabelas'][0]['linhas'][0]);
    $r2 = relarq_gerar($muitas, 'pdf', $pp);
    ok($r2['paginas'] >= 3 && substr_count(pdf_texto($pp), '(continua') >= 1, '120 linhas: várias páginas, com "(continuação)" e cabeçalho repetido');

    grupo('Permissão e formatos (API)');
    ok(!relexp_gerar_arquivo($C1, 'corretor', 'xlsx', 'completo', $G, $arq('xlsx'))['ok'], 'sem "exportar relatórios" → recusado');
    ok(!relexp_gerar_arquivo($M, 'executiva', 'pdf', 'dados', $G, $arq('pdf'))['ok'], 'PDF de dados → recusado');
    ok(!relexp_gerar_arquivo($M, 'executiva', 'docx', 'completo', $G, $arq('x'))['ok'], 'formato inexistente → recusado');
    $ok = relexp_gerar_arquivo($M, 'executiva', 'xlsx', 'completo', $G, $arq('xlsx'));
    ok($ok['ok'] && str_ends_with($ok['nome'], '.xlsx') && !preg_match('/[^a-z0-9\-.]/', $ok['nome']), 'nome de arquivo seguro');
    $ar = $arq('xlsx');
    relexp_gerar_arquivo($M, 'executiva', 'xlsx', 'resumido', $G, $ar);
    $det = xlsx_aba($ar, 2);
    ok(str_contains($det, 'Leads por origem') && !str_contains($det, 'Evolução — entregas'), 'arquivo "resumido" leva só a tabela principal');

    grupo('Fila em segundo plano');
    if (relexp_fila_instalada()) {
        $id = relexp_enfileirar($M, 'executiva', 'xlsx', 'dados', $G);
        $job = $pdo->query("SELECT * FROM relatorio_exportacoes WHERE id = $id")->fetch();
        ok($job['status'] === 'pendente' && $job['token_hash'] === hash('sha256', relexp_token($job)), 'pedido na fila; token só como hash');
        relexp_processar_fila(10);
        $job = $pdo->query("SELECT * FROM relatorio_exportacoes WHERE id = $id")->fetch();
        ok($job['status'] === 'pronto' && (int) $job['linhas'] === 3 && is_file(RELEXP_DIR . '/' . $job['arquivo']), 'gerado pelo cron: pronto, 3 linhas, arquivo em storage/exportacoes');
        ok(strtotime($job['expira_em']) > time() + 23 * 3600, 'expira em 24 h');
        ok(relexp_para_baixar($M, $id, relexp_token($job)) !== null, 'dono com o token: pode baixar');
        ok(relexp_para_baixar($M, $id, str_repeat('a', 64)) === null, 'token errado: não');
        ok(relexp_para_baixar($GA, $id, relexp_token($job)) === null, 'outro usuário (mesmo com o link): não');
        ok((int) v("SELECT COUNT(*) FROM auditoria WHERE empresa_id = $emp AND acao = 'relatorio.exportado' AND entidade_id IS NULL") >= 1, 'exportação registrada na auditoria');
        $pdo->exec("UPDATE relatorio_exportacoes SET expira_em = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = $id");
        relexp_processar_fila(1);
        $exp = $pdo->query("SELECT * FROM relatorio_exportacoes WHERE id = $id")->fetch();
        ok($exp['status'] === 'expirado' && $exp['arquivo'] === null && !is_file(RELEXP_DIR . '/' . $job['arquivo']), 'expirou: arquivo apagado');
        // Permissão retirada entre o pedido e a geração: não gera.
        $id2 = relexp_enfileirar($GA, 'executiva', 'xlsx', 'completo', $G);
        relexp_processar_fila(10);
        $j2 = $pdo->query("SELECT status, erro FROM relatorio_exportacoes WHERE id = $id2")->fetch();
        ok($j2['status'] === 'erro' && str_contains($j2['erro'], 'exportar'), 'quem perdeu a permissão de exportar não recebe o arquivo (o cron usa a permissão atual)');
        $id3 = relexp_enfileirar($M, 'executiva', 'xlsx', 'completo', $G);
        $pdo->exec("UPDATE relatorio_exportacoes SET status = 'gerando', iniciado_em = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE id = $id3");
        relexp_processar_fila(1);
        igual(v("SELECT status FROM relatorio_exportacoes WHERE id = $id3"), 'erro', 'geração travada há muito tempo → erro (não fica "gerando" para sempre)');
        igual(count(relexp_minhas($M)), 2, '"Minhas exportações": só as do próprio usuário');
    } else {
        ok(false, 'migração 015 não aplicada no banco de testes');
    }
} finally {
    foreach ($tmp as $t) {
        @unlink($t);
    }
    if (relexp_fila_instalada()) {
        foreach ($pdo->query("SELECT arquivo FROM relatorio_exportacoes WHERE empresa_id = $emp AND arquivo IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $x) {
            @unlink(RELEXP_DIR . '/' . basename($x));
        }
    }
    apagar_empresa_teste($emp);
}
resumo_e_sair();
