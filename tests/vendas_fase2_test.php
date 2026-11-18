<?php
/**
 * Inteligência de Vendas — Fase 2: importação. Prévia = gravação, chave da
 * venda, arquivo repetido, atualização controlada com histórico, conflitos
 * de identificador, situações, LGPD (CPF codificado, colunas não gravadas),
 * isolamento entre empresas, permissões, reprocessamento, descarte do
 * arquivo e garantia de que leads/Kanban não são tocados.
 * Só dados FICTÍCIOS.
 *
 *   php -q tests/vendas_fase2_test.php
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../includes/funcoes.php';
require_once __DIR__ . '/../includes/vendas/importacao.php';
require_once __DIR__ . '/../includes/relatorios/fila_exportacao.php';

const HOJE = '2026-11-18';
$CAB = ['id', 'Venda RM', 'Situação Proposta', 'datahoravenda', 'datacadastro', 'cliente', 'Celular', 'Email', 'cpf', 'empreendimento', 'unidade',
        'id_corretor', 'corretor', 'midia', 'valor', 'Renda', 'Fiador', 'Metragem'];
$linha = fn (int $i, array $mudar = []) => array_values(array_merge(array_combine($CAB, [
    (string) (900000 + $i), (string) (700000 + $i), 'Vendido', '15/09/2026', '31/12/1969', 'Comprador Fictício ' . $i, '55119000000' . sprintf('%02d', $i),
    'comprador' . $i . '@teste.invalid', '52998224725', 'Residencial Fictício', (string) (100 + $i), '5001', 'CORRETOR_EXEMPLO', 'Indicacao', '250000,00', '9999', 'Fiador Fictício', '42,5',
]), $mudar));
$tmp = [];
$csv = function (array $linhas) use ($CAB, &$tmp): array {
    $c = tempnam(sys_get_temp_dir(), 'wmv2') . '.csv';
    $tmp[] = $c;
    $fh = fopen($c, 'w');
    fputcsv($fh, $CAB, ';', '"', '');
    foreach ($linhas as $l) {
        fputcsv($fh, $l, ';', '"', '');
    }
    fclose($fh);
    return ['caminho' => $c, 'ext' => 'csv', 'nome' => 'vendas_teste.csv'];
};
$ler = fn (array $o) => vendas_ler_arquivo($o['caminho'], $o['ext']);
$mapaDe = fn (array $leitura) => vendas_mapear_cabecalho($leitura['cabecalho'])['mapa'];
function q(string $sql)
{
    return db()->query($sql)->fetchColumn();
}

grupo('Mapeamento obrigatório');
[$m, $erro] = vendas_mapa_validar(['data_venda' => '3'], 18);
ok($erro && str_contains($erro, 'Empreendimento'), 'sem empreendimento: recusa');
[$m, $erro] = vendas_mapa_validar(['data_venda' => '3', 'empreendimento' => '9'], 18);
ok($erro && str_contains($erro, 'identificador'), 'sem id e sem Venda RM: recusa (não daria para evitar duplicidade)');
[$m, $erro] = vendas_mapa_validar(['venda_id_origem' => '0', 'data_venda' => '3', 'empreendimento' => '3'], 18);
ok($erro && str_contains($erro, 'mesma coluna'), 'mesma coluna em dois campos: recusa');
[$m, $erro] = vendas_mapa_validar(['venda_id_origem' => '0', 'data_venda' => '99', 'empreendimento' => '9'], 18);
ok($erro && str_contains($erro, 'inválida'), 'índice fora do cabeçalho: recusa');

$empA = criar_empresa_teste('Vendas F2 A');
$empB = criar_empresa_teste('Vendas F2 B');
try {
    $pdo = db();
    $fA = funcoes_seed_padrao($empA);
    $fB = funcoes_seed_padrao($empB);
    $novoUsuario = function (int $emp, int $funcao, string $cargo) use ($pdo) {
        $pdo->prepare('INSERT INTO usuarios (empresa_id, funcao_id, nome, email, senha_hash, cargo) VALUES (:e, :f, :n, :m, :h, :c)')
            ->execute(['e' => $emp, 'f' => $funcao, 'n' => 'Usuário ' . $cargo, 'm' => bin2hex(random_bytes(5)) . '@teste.invalid', 'h' => 'x', 'c' => $cargo]);
        return relexp_usuario((int) $pdo->lastInsertId(), $emp);
    };
    $MA = $novoUsuario($empA, $fA['master_empresa'], 'master_empresa');
    $MB = $novoUsuario($empB, $fB['master_empresa'], 'master_empresa');
    $GA = $novoUsuario($empA, $fA['gerente'], 'gerente');
    $CA = $novoUsuario($empA, $fA['corretor'], 'corretor');

    grupo('18. Permissões');
    ok(usuario_tem_permissao($MA, 'importar_vendas') && usuario_tem_permissao($MA, 'ver_inteligencia_vendas'), 'Master importa e vê');
    ok(!usuario_tem_permissao($GA, 'importar_vendas') && !usuario_tem_permissao($CA, 'importar_vendas'), 'gerente e corretor não importam');
    $fonteApi = file_get_contents(__DIR__ . '/../api/vendas_importar.php');
    ok(str_contains($fonteApi, "api_iniciar('importar_vendas')"), 'API exige a permissão (e sessão + CSRF, via api_iniciar)');
    ok(str_contains($fonteApi, "(int) \$s['empresa_id'] !== \$empresaId"), 'API: token da importação preso à empresa e ao usuário da sessão');

    // Leads e Kanban: fotografia antes (a importação não pode tocar).
    $pdo->prepare("INSERT INTO leads (empresa_id, nome, telefone, email, origem, etapa, criado_em) VALUES (:e, 'Lead Fictício', '11900000001', 'comprador1@teste.invalid', 'manual', 'novo_lead', '2026-04-01')")->execute(['e' => $empA]);
    $antesLeads = q("SELECT CONCAT(COUNT(*), '|', COALESCE(SUM(CRC32(CONCAT_WS('|', etapa, responsavel_id, atualizado_em))), 0)) FROM leads WHERE empresa_id = $empA");
    $antesEventos = (int) q('SELECT COUNT(*) FROM lead_eventos');

    grupo('Prévia = o que será gravado');
    $o1 = $csv([$linha(1), $linha(2), $linha(3, ['Situação Proposta' => 'Distratado']), $linha(1), $linha(4, ['id' => '', 'Venda RM' => '']),
                $linha(5, ['Venda RM' => '700002']), $linha(6, ['datahoravenda' => '25/12/2026']), $linha(7, ['datacadastro' => '20/09/2026', 'Celular' => '1187654321'])]);
    $l1 = $ler($o1);
    $an = vendas_analisar($empA, VENDAS_SISTEMA_HM, $l1, $mapaDe($l1), ['vendido'], true, HOJE);
    $c = $an['contagens'];
    igual([$c['incluida'], $c['ignorada'], $c['erro']], [4, 1, 3], '4 novas, 1 repetida no arquivo, 3 recusadas');
    igual($an['linhas'][5]['resultado'], 'ignorada', 'mesmo id repetido (linha 5) = ignorada; só a 1ª vale');
    ok(str_contains($an['linhas'][6]['motivo'], 'Sem "id"'), 'sem id e sem Venda RM = recusada');
    ok(str_contains($an['linhas'][7]['motivo'], 'Venda RM 700002'), 'Venda RM de outra venda no mesmo arquivo = recusada (conflito)');
    ok(str_contains($an['linhas'][8]['motivo'], 'futuro'), 'data da venda no futuro = recusada');
    ok($an['linhas'][9]['alertas'] && $an['linhas'][9]['resultado'] === 'incluida', '15. datacadastro depois da venda e telefone sem 9: entra COM alerta');
    igual($an['situacoes'], ['vendido' => 3, 'distratado' => 1], 'situações contadas só nas linhas que ficam gravadas');
    igual((int) q("SELECT COUNT(*) FROM vendas_externas WHERE empresa_id = $empA"), 0, 'a prévia não grava nada');

    grupo('Importação');
    $lote1 = vendas_importar($MA, $o1, $mapaDe($l1), ['vendido'], true, null, HOJE);
    $L1 = vendas_lote_carregar($empA, $lote1);
    igual([(int) $L1['incluidas'], (int) $L1['ignoradas'], (int) $L1['com_erro'], $L1['status']], [4, 1, 3, 'concluido'], 'lote: mesmas contagens da prévia');
    igual((int) q("SELECT COUNT(*) FROM vendas_lote_linhas WHERE lote_id = $lote1"), 8, 'uma linha de resultado por linha da planilha');
    igual((int) q("SELECT COUNT(*) FROM vendas_externas WHERE empresa_id = $empA"), 4, '4 vendas gravadas');
    igual((int) q("SELECT eh_venda FROM vendas_externas WHERE empresa_id = $empA AND chave_origem = '900003'"), 0, 'situação "Distratado" gravada, mas não conta como venda');
    $v1 = $pdo->query("SELECT * FROM vendas_externas WHERE empresa_id = $empA AND chave_origem = '900001'")->fetch();
    igual([$v1['telefone1'], $v1['email'], $v1['valor'], substr($v1['data_venda'], 0, 10)], ['5511900000001', 'comprador1@teste.invalid', '250000.00', '2026-09-15'], 'campos normalizados');
    igual($v1['data_cadastro_origem'], null, 'datacadastro sentinela não é gravada como data');
    ok(strlen($v1['cpf_hash']) === 64 && $v1['cpf_final'] === '25', '20. CPF só codificado (HMAC) + 2 últimos dígitos');
    $tudo = json_encode($pdo->query("SELECT * FROM vendas_externas WHERE empresa_id = $empA")->fetchAll());
    ok(!str_contains($tudo, '52998224725'), '20. CPF em claro não aparece em nenhuma coluna');
    ok(!str_contains($tudo, 'Fiador Fictício') && !str_contains($tudo, '9999'), '20. colunas pessoais sem uso (renda, fiador) não são gravadas');
    igual(json_decode($v1['extras'], true), ['Metragem' => '42,5'], 'coluna desconhecida guardada como informação extra');
    $linhasLote = json_encode($pdo->query("SELECT * FROM vendas_lote_linhas WHERE lote_id = $lote1")->fetchAll());
    ok(!str_contains($linhasLote, 'Comprador Fictício') && !str_contains($linhasLote, '@teste.invalid'), 'resultado por linha sem nome nem e-mail');
    $aud = $pdo->query("SELECT * FROM auditoria WHERE empresa_id = $empA AND acao = 'vendas_importar'")->fetch();
    ok($aud && !str_contains((string) $aud['detalhes_json'], '@teste.invalid'), 'auditoria registrada, sem dados pessoais');
    ok((int) $L1['arquivo_guardado'] === 1 && is_file(vendas_arquivo_lote($lote1, 'csv')), 'original guardado (protegido) para reprocessar');
    igual(substr($L1['descartar_arquivo_em'], 0, 10), date('Y-m-d', strtotime('+' . VENDAS_RETENCAO_DIAS . ' days')), 'descarte marcado para daqui a ' . VENDAS_RETENCAO_DIAS . ' dias');

    grupo('16. Mesmo arquivo de novo');
    ok((int) (vendas_lote_mesmo_arquivo($empA, hash_file('sha256', $o1['caminho']))['id'] ?? 0) === $lote1, 'reconhece que o arquivo já foi importado');
    $lote2 = vendas_importar($MA, $o1, $mapaDe($l1), ['vendido'], true, null, HOJE);
    $L2 = vendas_lote_carregar($empA, $lote2);
    igual([(int) $L2['incluidas'], (int) $L2['atualizadas'], (int) $L2['sem_alteracao']], [0, 0, 4], 'nada novo, nada alterado: 4 "sem alteração"');
    igual((int) q("SELECT COUNT(*) FROM vendas_externas WHERE empresa_id = $empA"), 4, 'nenhuma venda duplicada');
    igual((int) q("SELECT ultimo_lote_id FROM vendas_externas WHERE empresa_id = $empA AND chave_origem = '900001'"), $lote2, 'venda marca o último lote em que apareceu');

    grupo('Alteração na origem: atualização controlada');
    $o3 = $csv([$linha(1, ['valor' => '262000,00', 'Email' => 'novo1@teste.invalid']), $linha(2), $linha(3, ['Situação Proposta' => 'Distratado']), $linha(5), $linha(7, ['datacadastro' => '20/09/2026', 'Celular' => '1187654321'])]);
    $l3 = $ler($o3);
    $an3 = vendas_analisar($empA, VENDAS_SISTEMA_HM, $l3, $mapaDe($l3), ['vendido'], false, HOJE);
    igual($an3['linhas'][2]['resultado'], 'ignorada', 'sem autorização: alterada fica ignorada');
    $lote3 = vendas_importar($MA, $o3, $mapaDe($l3), ['vendido'], false, null, HOJE);
    igual((string) q("SELECT valor FROM vendas_externas WHERE empresa_id = $empA AND chave_origem = '900001'"), '250000.00', '…e o valor antigo é mantido');
    $lote4 = vendas_importar($MA, $o3, $mapaDe($l3), ['vendido'], true, null, HOJE);
    $L4 = vendas_lote_carregar($empA, $lote4);
    igual((int) vendas_lote_carregar($empA, $lote3)['incluidas'], 1, 'linha nova (id 900005) entra mesmo sem autorizar atualizações');
    igual([(int) $L4['atualizadas'], (int) $L4['incluidas'], (int) $L4['sem_alteracao']], [1, 0, 4], 'com autorização: 1 atualizada, 4 iguais');
    igual((string) q("SELECT valor FROM vendas_externas WHERE empresa_id = $empA AND chave_origem = '900001'"), '262000.00', 'valor atualizado');
    $ver = json_decode((string) q("SELECT alteracoes FROM vendas_externas_versoes WHERE lote_id = $lote4"), true);
    igual([$ver['valor'][0] ?? null, (float) ($ver['valor'][1] ?? 0)], ['250000.00', 262000.0], 'histórico guarda antes e depois do valor');
    igual($ver['email'] ?? null, 'alterado', 'dado pessoal no histórico: só "alterado", sem o valor antigo');
    igual((int) q("SELECT COUNT(*) FROM vendas_externas_versoes v JOIN vendas_externas e ON e.id = v.venda_id WHERE e.empresa_id = $empA"), 1, 'uma versão por alteração real');

    grupo('Conflito com venda já gravada');
    $o5 = $csv([$linha(8, ['Venda RM' => '700001'])]);
    $l5 = $ler($o5);
    $an5 = vendas_analisar($empA, VENDAS_SISTEMA_HM, $l5, $mapaDe($l5), ['vendido'], true, HOJE);
    ok($an5['linhas'][2]['resultado'] === 'erro' && str_contains($an5['linhas'][2]['motivo'], 'já pertence à venda de id 900001'), 'Venda RM de outra venda já importada = recusada');
    $o6 = $csv([$linha(2, ['id' => '', 'valor' => '1,00'])]);
    $l6 = $ler($o6);
    $an6 = vendas_analisar($empA, VENDAS_SISTEMA_HM, $l6, $mapaDe($l6), ['vendido'], true, HOJE);
    igual([$an6['linhas'][2]['resultado'], $an6['linhas'][2]['chave']], ['atualizada', '900002'], 'sem id, mas com Venda RM conhecida: encontra a venda certa');

    grupo('17. Mesmo cliente, duas unidades');
    $o7 = $csv([$linha(10), $linha(11, ['Celular' => '5511900000010', 'Email' => 'comprador10@teste.invalid'])]);
    $l7 = $ler($o7);
    vendas_importar($MA, $o7, $mapaDe($l7), ['vendido'], true, null, HOJE);
    igual((int) q("SELECT COUNT(*) FROM vendas_externas WHERE empresa_id = $empA AND email = 'comprador10@teste.invalid'"), 2, 'mesma pessoa: duas vendas (a chave é a venda, não a pessoa)');

    grupo('19. Isolamento entre empresas');
    $anB = vendas_analisar($empB, VENDAS_SISTEMA_HM, $l1, $mapaDe($l1), ['vendido'], true, HOJE);
    igual($anB['contagens']['incluida'], 4, 'empresa B não enxerga as vendas da A: os mesmos ids são novos para B');
    $loteB = vendas_importar($MB, $o1, $mapaDe($l1), ['vendido'], true, null, HOJE);
    igual((int) q("SELECT COUNT(*) FROM vendas_externas WHERE empresa_id = $empB"), 4, 'B tem as suas 4');
    ok(q("SELECT cpf_hash FROM vendas_externas WHERE empresa_id = $empB AND chave_origem = '900002'") !== q("SELECT cpf_hash FROM vendas_externas WHERE empresa_id = $empA AND chave_origem = '900002'"), 'CPF codificado com chave por empresa (não dá para cruzar entre clientes do SaaS)');
    igual(vendas_lote_carregar($empA, $loteB), null, 'lote da B não abre na A');
    ok(!in_array($loteB, array_map(fn ($l) => (int) $l['id'], vendas_lotes_listar($empA)), true), 'histórico da A não lista lotes da B');
    lanca(fn () => vendas_reprocessar($MA, $loteB), 'A não reprocessa lote da B');

    grupo('Reprocessar e descartar o original');
    $lr = vendas_reprocessar($MA, $lote1);
    $LR = vendas_lote_carregar($empA, $lr);
    igual([(int) $LR['reprocessa_lote_id'], (int) $LR['incluidas']], [$lote1, 0], 'reprocessamento referencia o lote original e não duplica');
    $pdo->exec("UPDATE vendas_lotes SET descartar_arquivo_em = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = $lote1");
    ok(vendas_descartar_arquivos() >= 1 && !is_file(vendas_arquivo_lote($lote1, 'csv')), 'cron apaga o original vencido');
    igual((int) q("SELECT arquivo_guardado FROM vendas_lotes WHERE id = $lote1"), 0, '…e marca o lote');
    lanca(fn () => vendas_reprocessar($MA, $lote1), 'sem o original, reprocessar avisa (não inventa)');
    igual((int) q("SELECT COUNT(*) FROM vendas_externas WHERE empresa_id = $empA"), 7, 'dados tratados continuam após o descarte');

    grupo('Falha no meio = nada gravado');
    $o8 = $csv([$linha(20), $linha(21)]);
    $l8 = $ler($o8);
    $antes = (int) q("SELECT COUNT(*) FROM vendas_externas WHERE empresa_id = $empA");
    $pdo->exec('CREATE TRIGGER teste_vendas_falha BEFORE INSERT ON vendas_lote_linhas FOR EACH ROW BEGIN IF NEW.linha_num = 3 THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'falha simulada\'; END IF; END');
    try {
        lanca(fn () => vendas_importar($MA, $o8, $mapaDe($l8), ['vendido'], true, null, HOJE), 'erro no meio da gravação é propagado');
    } finally {
        $pdo->exec('DROP TRIGGER IF EXISTS teste_vendas_falha');
    }
    igual((int) q("SELECT COUNT(*) FROM vendas_externas WHERE empresa_id = $empA"), $antes, 'transação: nenhuma venda parcial gravada');
    igual((string) q("SELECT status FROM vendas_lotes WHERE empresa_id = $empA ORDER BY id DESC LIMIT 1"), 'falhou', 'lote marcado como "falhou", com o motivo');

    grupo('Leads, Kanban e automações intocados');
    igual(q("SELECT CONCAT(COUNT(*), '|', COALESCE(SUM(CRC32(CONCAT_WS('|', etapa, responsavel_id, atualizado_em))), 0)) FROM leads WHERE empresa_id = $empA"), $antesLeads, 'nenhum lead criado ou alterado (etapa, responsável)');
    igual((int) q('SELECT COUNT(*) FROM lead_eventos'), $antesEventos, 'nenhum evento de lead gerado');
    $fonte = file_get_contents(__DIR__ . '/../includes/vendas/importacao.php');
    ok(!preg_match('/(INSERT INTO|UPDATE)\s+(leads|lead_|whatsapp_|automacao_|jornada_)/i', $fonte), 'serviço de importação não escreve em leads, WhatsApp nem automações');
} finally {
    foreach ($tmp as $t) {
        @unlink($t);
    }
    foreach ([$empA, $empB] as $e) {
        foreach (db()->query("SELECT id, arquivo_ext FROM vendas_lotes WHERE empresa_id = $e")->fetchAll() as $l) {
            @unlink((string) vendas_arquivo_lote((int) $l['id'], $l['arquivo_ext']));
        }
    }
    apagar_empresa_teste($empA);
    apagar_empresa_teste($empB);
}
resumo_e_sair();
