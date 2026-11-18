<?php
/**
 * Inteligência de Vendas — Fase 6: exportações (Excel, CSV, PDF),
 * proteção de dados nos arquivos, permissões e carteira, anonimização
 * (LGPD), verificação de integridade e varredura de segurança das telas e
 * APIs do módulo. Só dados FICTÍCIOS.
 *
 *   php -q tests/vendas_fase6_test.php
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../includes/funcoes.php';
require_once __DIR__ . '/../includes/funil.php';
require_once __DIR__ . '/../includes/vendas/exportar.php';
require_once __DIR__ . '/../includes/vendas/integridade.php';
require_once __DIR__ . '/../includes/relatorios/fila_exportacao.php';

const HOJE = '2026-11-18';
function q(string $sql)
{
    return db()->query($sql)->fetchColumn();
}
/** Texto legível de um arquivo gerado (xlsx: XML das planilhas; pdf: streams descompactados; csv: o próprio). */
function conteudo(string $arq, string $ext): string
{
    if ($ext === 'xlsx') {
        $z = new ZipArchive();
        $z->open($arq);
        $t = '';
        for ($i = 0; $i < $z->numFiles; $i++) {
            $t .= $z->getFromIndex($i);
        }
        $z->close();
        return $t;
    }
    $t = (string) file_get_contents($arq);
    if ($ext === 'pdf') {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $t, $m);
        foreach ($m[1] as $s) {
            $u = @gzuncompress($s);
            $t .= $u !== false ? $u : '';
        }
        $t = mb_convert_encoding($t, 'UTF-8', 'Windows-1252');
    }
    return $t;
}

$empA = criar_empresa_teste('Vendas F6 A');
$empB = criar_empresa_teste('Vendas F6 B');
$tmp = [];
$novoTmp = function (string $ext) use (&$tmp) {
    $t = tempnam(sys_get_temp_dir(), 'wmv6') . '.' . $ext;
    $tmp[] = $t;
    return $t;
};
try {
    $pdo = db();
    $f = funcoes_seed_padrao($empA);
    funil_seed_padrao($empA);
    $fB = funcoes_seed_padrao($empB);
    $usr = function (int $emp, int $funcao, string $cargo, string $nome) use ($pdo) {
        $pdo->prepare('INSERT INTO usuarios (empresa_id, funcao_id, nome, email, senha_hash, cargo) VALUES (:e, :f, :n, :m, :h, :c)')
            ->execute(['e' => $emp, 'f' => $funcao, 'n' => $nome, 'm' => bin2hex(random_bytes(5)) . '@teste.invalid', 'h' => 'x', 'c' => $cargo]);
        return (int) $pdo->lastInsertId();
    };
    $M = relexp_usuario($usr($empA, $f['master_empresa'], 'master_empresa', 'Master F6'), $empA);
    $MB = relexp_usuario($usr($empB, $fB['master_empresa'], 'master_empresa', 'Master F6 B'), $empB);
    $gId = $usr($empA, $f['gerente'], 'gerente', 'Gerente F6');
    $pdo->prepare("INSERT INTO equipes (empresa_id, nome, gerente_id) VALUES (:e, 'Eq F6', :g), (:e2, 'Eq Outra', NULL)")->execute(['e' => $empA, 'g' => $gId, 'e2' => $empA]);
    $eqG = (int) $pdo->lastInsertId();
    $eqOutra = $eqG + 1;
    $G = relexp_usuario($gId, $empA);
    // Função "Gerente analista": vê a Inteligência de Vendas e exporta, mas com o escopo da equipe.
    $pdo->prepare("INSERT INTO funcoes (empresa_id, nome, escopo) VALUES (:e, 'Gerente analista', 'equipe')")->execute(['e' => $empA]);
    $fAn = (int) $pdo->lastInsertId();
    foreach (['visualizar_leads', 'visualizar_indicadores', 'exportar_relatorios', 'ver_inteligencia_vendas'] as $p) {
        $pdo->prepare('INSERT INTO funcao_permissoes (funcao_id, permissao_chave) VALUES (:f, :p)')->execute(['f' => $fAn, 'p' => $p]);
    }
    $anId = $usr($empA, $fAn, 'gerente', 'Analista F6');
    $pdo->prepare("UPDATE equipes SET gerente_id = :a WHERE id = :q")->execute(['a' => $anId, 'q' => $eqG]);
    $AN = relexp_usuario($anId, $empA);

    $lead = function (string $tel, ?string $email, string $criado, int $equipe) use ($pdo, $empA) {
        $pdo->prepare("INSERT INTO leads (empresa_id, equipe_id, nome, telefone, email, origem, etapa, criado_em) VALUES (:e, :q, 'Lead F6', :t, :m, 'manual', 'novo_lead', :c)")
            ->execute(['e' => $empA, 'q' => $equipe, 't' => $tel, 'm' => $email, 'c' => $criado]);
        return (int) $pdo->lastInsertId();
    };
    $L1 = $lead('11900000601', 'seis1@teste.invalid', '2026-06-01 10:00:00', $eqG);
    $lead('11900000602', 'seis2@teste.invalid', '2026-06-01 10:00:00', $eqOutra);
    $pdo->prepare("INSERT INTO leads (empresa_id, nome, telefone, origem, etapa, criado_em) VALUES (:e, 'Lead B', '11900000601', 'manual', 'novo_lead', '2026-01-01')")->execute(['e' => $empB]);

    $cab = ['id', 'Venda RM', 'Situação Proposta', 'datahoravenda', 'cliente', 'Celular', 'Email', 'cpf', 'empreendimento', 'id_corretor', 'corretor', 'midia', 'valor'];
    $linhas = [
        ['601', '1', 'Vendido', '10/09/2026', 'Comprador Seis Um', '5511900000601', 'seis1@teste.invalid', '52998224725', 'Emp F6', '7001', 'VEND', 'Indicacao', '300000'],
        ['602', '2', 'Vendido', '10/09/2026', 'Comprador Seis Dois', '5511900000602', 'seis2@teste.invalid', '52998224725', 'Emp F6', '7001', 'VEND', 'Indicacao', '200000'],
        ['603', '3', 'Vendido', '10/09/2026', '=HYPERLINK("http://exemplo.invalid","x")', '5511999990603', 'seis3@teste.invalid', '52998224725', 'Emp F6', '7001', 'VEND', 'Roleta', '100000'],
    ];
    $c = $novoTmp('csv');
    $fh = fopen($c, 'w');
    fputcsv($fh, $cab, ';', '"', '');
    foreach ($linhas as $l) {
        fputcsv($fh, $l, ';', '"', '');
    }
    fclose($fh);
    $leit = vendas_ler_arquivo($c, 'csv');
    $mapa = vendas_mapear_cabecalho($leit['cabecalho'])['mapa'];
    $lote = vendas_importar($M, ['caminho' => $c, 'ext' => 'csv', 'nome' => 't.csv'], $mapa, ['vendido'], true, null, HOJE);
    vendas_cruzar($empA);
    $venda = fn (string $k) => (int) q("SELECT id FROM vendas_externas WHERE empresa_id = $empA AND chave_origem = '$k'");
    $get = ['periodo' => 'personalizado', 'ini' => '2026-09-01', 'fim' => '2026-09-30'];

    grupo('20. Exportações: formatos e conteúdo');
    foreach (['xlsx', 'csv', 'pdf'] as $fmt) {
        foreach (['painel' => $get, 'conciliacao' => [], 'jornada' => ['venda' => $venda('601')]] as $tipo => $g) {
            $arq = $novoTmp($fmt);
            $r = vendas_export_gerar($M, $tipo, $fmt, 'completo', $g, $arq);
            $txt = $r['ok'] ? conteudo($arq, $fmt) : '';
            ok($r['ok'] && filesize($arq) > 200, "$tipo em $fmt gerado");
            ok(!preg_match('/5511\d{9}|11 ?9\d{4}-?\d{4}|teste\.invalid|52998224725|529\.982\.247-25/', $txt), "$tipo em $fmt: sem telefone, e-mail ou CPF");
        }
    }
    $arq = $novoTmp('xlsx');
    vendas_export_gerar($M, 'painel', 'xlsx', 'resumido', $get, $arq);
    ok(!str_contains(conteudo($arq, 'xlsx'), 'Comprador Seis'), 'painel RESUMIDO: nenhum nome de cliente');
    $arq = $novoTmp('xlsx');
    vendas_export_gerar($M, 'painel', 'xlsx', 'completo', $get, $arq);
    ok(str_contains(conteudo($arq, 'xlsx'), 'Comprador Seis Um'), 'painel COMPLETO: traz o nome (com ciência LGPD exigida pela API)');
    $arq = $novoTmp('csv');
    vendas_export_gerar($M, 'conciliacao', 'csv', 'completo', [], $arq);
    $csv = (string) file_get_contents($arq);
    ok(str_contains($csv, "'=HYPERLINK") && !preg_match('/(^|;)=HYPERLINK/m', $csv), 'CSV: fórmula no nome do cliente neutralizada');
    $arq = $novoTmp('pdf');
    $r = vendas_export_gerar($M, 'jornada', 'pdf', 'completo', ['venda' => $venda('601')], $arq);
    ok(str_starts_with((string) file_get_contents($arq, false, null, 0, 5), '%PDF-') && ($r['paginas'] ?? 0) >= 1, 'PDF válido da jornada');
    ok(str_contains(conteudo($arq, 'pdf'), 'Comprador Seis Um'), 'indicador de texto sai como texto no PDF (não vira 0)');
    lanca(fn () => throw new Exception(vendas_export_gerar($M, 'outro', 'xlsx', 'resumido', [], $novoTmp('x'))['ok'] ? '' : 'recusado'), 'tipo inválido recusado');
    igual(vendas_export_gerar($M, 'painel', 'docx', 'resumido', $get, $novoTmp('x'))['ok'], false, 'formato inválido recusado');

    grupo('18/20. Permissões e carteira nas exportações');
    igual(vendas_export_gerar($G, 'painel', 'xlsx', 'resumido', $get, $novoTmp('x'))['ok'], false, 'gerente sem a permissão: não exporta');
    igual(vendas_export_gerar($AN, 'jornada', 'xlsx', 'completo', ['venda' => $venda('602')], $novoTmp('x'))['ok'], false, 'jornada de cliente fora da carteira atual: recusada');
    $arq = $novoTmp('xlsx');
    igual(vendas_export_gerar($AN, 'jornada', 'xlsx', 'completo', ['venda' => $venda('601')], $arq)['ok'], true, 'jornada de cliente da carteira: permitida');
    igual(vendas_export_gerar($MB, 'jornada', 'xlsx', 'completo', ['venda' => $venda('601')], $novoTmp('x'))['ok'], false, '19. Master de outra empresa não exporta jornada da A');
    $arq = $novoTmp('csv');
    vendas_export_gerar($MB, 'conciliacao', 'csv', 'completo', [], $arq);
    ok(!str_contains((string) file_get_contents($arq), 'Comprador Seis'), '19. conciliação da B não traz vendas da A');
    $api = file_get_contents(__DIR__ . '/../api/vendas_exportar.php');
    ok(str_contains($api, "api_iniciar('exportar_relatorios')") && str_contains($api, "'ver_inteligencia_vendas'") && str_contains($api, "empty(\$_POST['ciente_lgpd'])"), 'API: sessão + CSRF + permissões + ciência LGPD');
    ok(str_contains($api, "auditoria_registrar") && !preg_match('/auditoria_registrar\([^;]*cliente/', $api), 'API: exportação auditada, sem dados pessoais no registro');

    grupo('LGPD: anonimização da venda');
    $v1 = $venda('601');
    lanca(fn () => vendas_anonimizar($M, $v1, 'x'), 'exige motivo');
    lanca(fn () => vendas_anonimizar($MB, $v1, 'Pedido do titular (teste)'), '19. outra empresa não anonimiza');
    vendas_anonimizar($M, $v1, 'Pedido do titular (teste fictício)');
    $row = $pdo->query("SELECT * FROM vendas_externas WHERE id = $v1")->fetch();
    igual([$row['cliente_nome'], $row['telefone1'], $row['email'], $row['cpf_hash'], $row['cpf_final']], [null, null, null, null, null], 'nome, telefone, e-mail e CPF apagados');
    ok($row['anonimizada_em'] !== null && (float) $row['valor'] === 300000.0, 'dados comerciais mantidos; marca de anonimização gravada');
    igual(vendas_correspondencia($v1)['status'], 'sem', 'vínculo com o CRM desfeito (dependia dos contatos)');
    $lote2 = vendas_importar($M, ['caminho' => $c, 'ext' => 'csv', 'nome' => 't.csv'], $mapa, ['vendido'], true, null, HOJE);
    $row = $pdo->query("SELECT cliente_nome, telefone1, email, cpf_hash FROM vendas_externas WHERE id = $v1")->fetch();
    igual(array_values($row), [null, null, null, null], 'reimportar o MESMO arquivo não devolve os dados pessoais');
    ok(!str_contains((string) q("SELECT GROUP_CONCAT(alteracoes) FROM vendas_externas_versoes WHERE venda_id = $v1"), 'Comprador'), '…e o histórico de versões não guarda o nome');
    $c2 = $novoTmp('csv');
    $fh = fopen($c2, 'w');
    fputcsv($fh, $cab, ';', '"', '');
    fputcsv($fh, array_replace($linhas[0], [12 => '310000']), ';', '"', '');   // mesmo id, valor mudou na origem
    fclose($fh);
    $lote3 = vendas_importar($M, ['caminho' => $c2, 'ext' => 'csv', 'nome' => 't2.csv'], $mapa, ['vendido'], true, null, HOJE);
    $row = $pdo->query("SELECT cliente_nome, telefone1, email, cpf_hash, valor FROM vendas_externas WHERE id = $v1")->fetch();
    igual(array_values($row), [null, null, null, null, '310000.00'], 'venda alterada na origem: atualiza o valor, mas NÃO devolve os dados pessoais');
    @unlink(vendas_arquivo_lote($lote3, 'csv'));
    igual(relui_valor('Sim'), 'Sim', 'relui_valor: texto continua texto (não vira 0)');
    $aud = (string) q("SELECT detalhes_json FROM auditoria WHERE empresa_id = $empA AND acao = 'vendas_anonimizar'");
    ok($aud !== '' && !str_contains($aud, 'Comprador'), 'anonimização auditada, sem o nome');
    @unlink(vendas_arquivo_lote($lote, 'csv'));
    @unlink(vendas_arquivo_lote($lote2, 'csv'));

    grupo('Integridade');
    $nivel = fn (string $rot) => array_values(array_filter(vendas_integridade($empA), fn ($i) => str_starts_with($i['rotulo'], $rot)))[0]['nivel'] ?? null;
    igual($nivel('Correspondências com lead de OUTRA empresa'), 'ok', 'sem vazamento entre empresas');
    igual($nivel('Vendas anonimizadas que ainda têm dado pessoal'), 'ok', 'anonimização completa');
    $leadB = (int) q("SELECT id FROM leads WHERE empresa_id = $empB LIMIT 1");
    $pdo->prepare("INSERT INTO vendas_correspondencia_leads (venda_id, lead_id, evidencia, papel) VALUES (:v, :l, 'telefone', 'vinculado')")->execute(['v' => $venda('602'), 'l' => $leadB]);
    igual($nivel('Correspondências com lead de OUTRA empresa'), 'critico', 'detecta vínculo com lead de outra empresa (inconsistência injetada)');
    $pdo->exec("UPDATE vendas_correspondencias SET decisao = 'confirmada' WHERE venda_id = " . $venda('603'));
    igual($nivel('Decisões humanas sem registro de revisão'), 'critico', 'detecta decisão humana sem justificativa');
    $pdo->exec("UPDATE vendas_externas SET cliente_nome = 'Volta indevida' WHERE id = $v1");
    igual($nivel('Vendas anonimizadas que ainda têm dado pessoal'), 'critico', 'detecta anonimização desfeita');
    $pdo->exec("UPDATE vendas_externas SET cliente_nome = NULL WHERE id = $v1");
    vendas_cruzar($empA);
    $pdo->exec("UPDATE vendas_correspondencias SET decisao = 'automatica' WHERE venda_id = " . $venda('603'));
    vendas_cruzar($empA);
    igual($nivel('Correspondências com lead de OUTRA empresa'), 'ok', 'recalcular o cruzamento corrige o vínculo injetado');

    grupo('Varredura de segurança do módulo');
    $raiz = __DIR__ . '/..';
    foreach (glob("$raiz/api/vendas_*.php") as $a) {
        $src = file_get_contents($a);
        ok(preg_match("/api_iniciar\\('(importar_vendas|revisar_correspondencias|exportar_relatorios)'\\)/", $src) === 1, basename($a) . ': sessão, CSRF e permissão (api_iniciar)');
        ok(!preg_match('/\$_(GET|POST)\[[^\]]+\]\s*\.\s*[\'"]|[\'"]\s*\.\s*\$_(GET|POST)\[/', $src) || !preg_match('/(query|prepare)\([^)]*\$_(GET|POST)/', $src), basename($a) . ': entrada do usuário nunca concatenada em SQL');
    }
    foreach (glob("$raiz/pages/vendas_*.php") as $p) {
        $src = file_get_contents($p);
        ok(str_contains($src, 'pagina_acesso_restrito') && str_contains($src, "eh_superadmin"), basename($p) . ': permissão verificada (superadmin fora dos dados da empresa)');
        ok(!preg_match('/<\?=\s*\$(?!pc|sp|np|cp|pp|p1|clsPill|pillS|pr\b)[a-zA-Z_]+\[[^\]]+\]\s*\?>/', $src), basename($p) . ': saídas passam por e() ou cast numérico');
    }
    $todo = '';
    foreach (array_merge(glob("$raiz/includes/vendas/*.php"), glob("$raiz/api/vendas_*.php")) as $a) {
        $todo .= file_get_contents($a);
    }
    ok(!preg_match('/(INSERT INTO|UPDATE)\s+(whatsapp_mensagens|whatsapp_campanha|automacao_fila|jornada_execucoes)/i', $todo), 'o módulo nunca dispara WhatsApp, campanha ou automação');
    ok(!preg_match('/error_log\([^;]*(cliente_nome|telefone|email|cpf)/i', $todo), 'logs de erro sem dados pessoais');
} finally {
    foreach ($tmp as $t) {
        @unlink($t);
    }
    apagar_empresa_teste($empA);
    apagar_empresa_teste($empB);
}
resumo_e_sair();
