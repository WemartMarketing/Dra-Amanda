<?php
/**
 * Inteligência de Vendas — Fase 1: leitura da planilha (65 colunas, datas
 * do Excel), normalização (telefone, e-mail, CPF, valores), mapeamento de
 * colunas (LGPD), regras de correspondência v1 e isolamento por empresa.
 * Só dados FICTÍCIOS (telefones 11 9xxxx, e-mails @teste.invalid).
 *
 *   php -q tests/vendas_fase1_test.php
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../includes/vendas/indice_crm.php';

const HOJE = '2026-11-18';

grupo('Datas da planilha');
$d = vendas_data('46249');
igual($d['valor'], '2026-08-15 00:00:00', 'serial do Excel 46249 = 15/08/2026');
igual($d['so_data'], true, 'serial inteiro = só data (sem hora)');
igual(vendas_data('46249.75')['valor'], '2026-08-15 18:00:00', 'fração do serial = hora');
igual(vendas_data('46249', true)['valor'], '2030-08-16 00:00:00', 'calendário 1904 desloca 1462 dias');
igual(vendas_data('25568', false, HOJE)['problema'], 'sentinela', '31/12/1969 (data zero de outro sistema) = sentinela');
igual(vendas_data('25568')['valor'], null, 'sentinela nunca vira data');
igual(vendas_data('09/12/2026', false, HOJE)['problema'], 'futura', 'data no futuro sinalizada');
igual(vendas_data('15/08/2026 14:30')['valor'], '2026-08-15 14:30:00', 'texto dd/mm/aaaa hh:mm');
igual(vendas_data('2026-08-15')['valor'], '2026-08-15 00:00:00', 'texto aaaa-mm-dd');
igual(vendas_data('31/02/2026')['problema'], 'ilegivel', '31/02 não existe');
igual(vendas_data('')['problema'], 'vazia', 'vazia');

grupo('Telefone (sem inventar números)');
igual(vendas_telefone('5511987654321')['e164'], '5511987654321', '55 + DDD + 9 dígitos');
igual(vendas_telefone('(11) 98765-4321')['e164'], '5511987654321', 'formatado sem 55 ganha 55');
igual(vendas_telefone('+55 11 98765-4321')['e164'], '5511987654321', 'com +55');
igual(vendas_telefone('011987654321')['e164'], '5511987654321', 'com 0 de discagem nacional');
igual(vendas_telefone('1133334444')['tipo'], 'fixo', 'fixo de 8 dígitos');
igual(vendas_telefone('987654321')['problema'], 'tamanho', 'sem DDD: não presume DDD');
igual(vendas_telefone('2098765432')['problema'], 'ddd', 'DDD 20 não existe');
igual(vendas_telefone('1187654321')['problema'], 'celular_sem_9', 'celular antigo sem 9: não completa sozinho');
igual(vendas_telefone('11887654321')['problema'], 'tamanho', '11 dígitos sem o 9 na frente: inválido');
igual(vendas_telefone('+1 415 555 0100')['problema'], 'estrangeiro', 'estrangeiro fica fora do cruzamento v1');
igual(vendas_telefone('0')['problema'], 'vazio', 'zero = vazio');
igual(vendas_telefone_variante('1187654321'), '5511987654321', 'variante com 9 só para apontar candidato');
igual(vendas_telefone_variante('11987654321'), null, 'número completo não tem variante');

grupo('E-mail, CPF e valores');
igual(vendas_email('  Fulano.Teste+promo@Teste.Invalid ')['email'], 'fulano.teste+promo@teste.invalid', 'minúsculas e sem espaços; mantém ponto e +alias');
igual(vendas_email('sem-arroba.com')['problema'], 'invalido', 'sem @ = inválido');
igual(vendas_email('a@b')['problema'], 'invalido', 'sem domínio = inválido');
igual(vendas_cpf('52998224725')['cpf'], '52998224725', 'CPF válido (fictício de exemplo)');
$cz = vendas_cpf('9876543229');   // 10 dígitos: zero à esquerda perdido no Excel
igual([$cz['cpf'], $cz['zeros_recuperados']], ['09876543229', true], 'CPF gravado como número recupera o zero e valida o dígito');
igual(vendas_cpf('9876543228')['problema'], 'invalido', 'dígito verificador errado = inválido');
igual(vendas_cpf('11111111111')['problema'], 'invalido', 'repetido = inválido');
igual(vendas_valor('316800'), 316800.0, '"316800"');
igual(vendas_valor('R$ 316.800,50'), 316800.5, '"R$ 316.800,50"');
igual(vendas_valor('316,800.50'), 316800.5, '"316,800.50"');
igual(vendas_valor('abc'), null, 'texto → null');

grupo('Mapeamento de colunas');
$cab = ['empreendimento', 'cliente', 'id_corretor', 'corretor', 'datahoravenda', 'valor', 'cpf', 'telefone', 'Celular', 'Email', 'midia', 'id',
        'Venda RM', 'Situação Proposta', 'datacadastro', 'data_nascimento', 'Renda', 'Fiador', 'Metragem', 'coluna nova qualquer', 'valor'];
$m = vendas_mapear_cabecalho($cab);
igual($m['mapa']['venda_rm'], 12, '"Venda RM" reconhecida');
igual($m['mapa']['situacao'], 13, '"Situação Proposta" reconhecida (acento)');
igual($m['mapa']['corretor_id_origem'], 2, '"id_corretor" ≠ "id" da venda');
igual($m['mapa']['venda_id_origem'], 11, '"id" = id da venda');
igual(array_values($m['minimizadas']), ['data_nascimento', 'Renda', 'Fiador'], 'dados pessoais desnecessários: não importados (LGPD), mas listados');
igual(array_values($m['extras']), ['Metragem', 'coluna nova qualquer'], 'colunas desconhecidas: listadas, não descartadas');
igual($m['duplicadas'], ['valor'], 'cabeçalho repetido avisado');

grupo('Leitor XLSX com mais de 50 colunas e datas numéricas');
$tmp = tempnam(sys_get_temp_dir(), 'wmv') . '.xlsx';
$cols = [];
for ($i = 0; $i < 65; $i++) {
    $cols[] = 'extra' . $i;
}
$cols[0] = 'empreendimento';
$cols[7] = 'datahoravenda';
$cols[30] = 'Celular';
$cols[31] = 'Email';
$cols[40] = 'id';
$cols[50] = 'datacadastro';
$cols[52] = 'Situação Proposta';
$cols[54] = 'Venda RM';
$cols[8] = 'valor';
$letra = function (int $i): string {
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
        $s = chr(65 + ($i - 1) % 26) . $s;
    }
    return $s;
};
$cel = function (int $c, int $r, $v) use ($letra) {
    $ref = $letra($c) . $r;
    return is_int($v) || is_float($v) ? "<c r=\"$ref\"><v>$v</v></c>" : "<c r=\"$ref\" t=\"inlineStr\"><is><t>" . htmlspecialchars((string) $v) . '</t></is></c>';
};
$linhaFix = array_fill(0, 65, '');
$linhaFix[0] = 'Residencial Fictício Ó';
$linhaFix[7] = 46249;
$linhaFix[30] = 5511900000001;
$linhaFix[31] = 'COMPRADOR1@TESTE.INVALID';
$linhaFix[40] = 900001;
$linhaFix[50] = 25568;
$linhaFix[52] = 'Vendido';
$linhaFix[54] = 700001;
$linhaFix[8] = 250000.5;
$xmlRow = fn (array $vals, int $r) => '<row r="' . $r . '">' . implode('', array_map(fn ($i) => $vals[$i] === '' ? '' : $cel($i, $r, $vals[$i]), array_keys($vals))) . '</row>';
$z = new ZipArchive();
$z->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>');
$z->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheets><sheet name="v" sheetId="1" r:id="rId1" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/></sheets></workbook>');
$z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
$z->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $xmlRow($cols, 1) . $xmlRow($linhaFix, 2) . '</sheetData></worksheet>');
$z->close();
try {
    $r50 = importacao_ler_xlsx($tmp);
    igual(count($r50['linhas'][0]), 50, 'importação de LEADS continua limitada a 50 colunas (sem mudança)');
    $r = importacao_ler_xlsx($tmp, 200);
    igual(count($r['linhas'][0]), 65, 'vendas: lê as 65 colunas');
    $mm = vendas_mapear_cabecalho($r['linhas'][0]);
    $v = vendas_normalizar_linha($r['linhas'][1], $mm['mapa'], vendas_xlsx_data1904($tmp), HOJE);
    igual([$v['venda_id_origem'], $v['venda_rm'], $v['situacao']], ['900001', '700001', 'Vendido'], 'id, Venda RM e situação (colunas > 50) lidos');
    igual($v['data_venda'], '2026-08-15 00:00:00', 'datahoravenda numérica convertida');
    igual($v['data_cadastro_origem'], null, 'datacadastro sentinela descartada');
    ok(isset($v['alertas']['data_cadastro']), '…e sinalizada como inconsistência');
    igual($v['telefones'], ['5511900000001'], 'celular numérico com 55');
    igual($v['email'], 'comprador1@teste.invalid', 'e-mail normalizado');
    igual($v['valor'], 250000.5, 'valor com centavos');
} finally {
    @unlink($tmp);
}

grupo('Linha: validações');
$mapa = vendas_mapear_cabecalho(['id', 'Venda RM', 'datahoravenda', 'datacadastro', 'empreendimento', 'Celular', 'Email', 'valor'])['mapa'];
$v = vendas_normalizar_linha(['', '', '46249', '46365', 'Emp', '1187654321', 'x@', '0'], $mapa, false, HOJE);
ok(isset($v['problemas']['sem_identificador']), 'sem id e sem Venda RM = problema (não dá para evitar duplicidade)');
ok(isset($v['alertas']['data_cadastro']) && str_contains($v['alertas']['data_cadastro'], 'futuro'), 'datacadastro futura (09/12/2026) = alerta, ignorada');
ok(isset($v['alertas']['sem_contato']), 'sem telefone e e-mail válidos = alerta');
ok(isset($v['alertas']['valor']), 'valor zero = alerta');
igual($v['telefones_brutos'], ['1187654321'], 'celular antigo guardado só para apontar variante');
$v2 = vendas_normalizar_linha(['1', '2', '46249', '46300', 'Emp', '11900000002', 'a@teste.invalid', '10'], $mapa, false, HOJE);
ok(isset($v2['alertas']['data_cadastro']) && str_contains($v2['alertas']['data_cadastro'], 'depois da venda'), 'datacadastro depois da venda = alerta');
igual($v2['data_cadastro_origem'], '2026-10-05 00:00:00', '…mas preservada como dado importado (não substitui a entrada no CRM)');

grupo('Regras de correspondência v1 (puras)');
$L = fn (?string $tel, ?string $email, ?string $dt) => ['tel' => $tel, 'email' => $email, 'primeira_data' => $dt];
$venda = '2026-09-10 00:00:00';
$r = vendas_classificar([10 => $L('5511900000010', 'a@teste.invalid', '2026-04-10 09:00:00')], [10 => $L('5511900000010', 'a@teste.invalid', '2026-04-10 09:00:00')], $venda, 'a@teste.invalid');
igual([$r['status'], $r['lead_ids'], $r['cronologia']], ['forte', [10], 'anterior'], '1. telefone e e-mail no mesmo cadastro = forte');
igual(vendas_dias_entre($r['primeira_data'], $venda), 153, '…153 dias de 10/04 a 10/09');
$r = vendas_classificar([11 => $L('5511900000011', null, '2026-05-01 10:00:00')], [], $venda, 'b@teste.invalid');
igual($r['status'], 'telefone', '2. só telefone = por telefone');
$r = vendas_classificar([], [12 => $L(null, 'c@teste.invalid', '2026-05-01 10:00:00')], $venda, 'c@teste.invalid');
igual($r['status'], 'email', '3. só e-mail = por e-mail');
$r = vendas_classificar([13 => $L('5511900000013', null, '2026-05-01')], [14 => $L('5511900000014', 'd@teste.invalid', '2026-05-01')], $venda, 'd@teste.invalid');
igual([$r['status'], $r['lead_ids']], ['ambigua', [13, 14]], '4. telefone num cadastro e e-mail em outro = ambígua');
$r = vendas_classificar([15 => $L('5511900000015', 'e@teste.invalid', '2026-04-01'), 16 => $L('5511900000015', 'e@teste.invalid', '2026-05-01'), 17 => $L('5511900000015', null, '2026-07-01')],
    [15 => $L('5511900000015', 'e@teste.invalid', '2026-04-01'), 16 => $L('5511900000015', 'e@teste.invalid', '2026-05-01')], $venda, 'e@teste.invalid');
igual([$r['status'], $r['lead_ids'], substr($r['primeira_data'], 0, 10)], ['forte', [15, 16, 17], '2026-04-01'], '5. vários leads da mesma pessoa: todos ligados; primeira entrada = a mais antiga');
$r = vendas_classificar([18 => $L('5511900000018', 'f@teste.invalid', '2026-04-01'), 19 => $L('5511900000018', 'g@teste.invalid', '2026-05-01')], [], $venda, 'h@teste.invalid');
igual($r['status'], 'ambigua', 'mesmo telefone em cadastros com e-mails diferentes = ambígua (vários candidatos)');
$r = vendas_classificar([], [], $venda, null);
igual($r['status'], 'sem', '13. nada coincide = sem correspondência');
$r = vendas_classificar([20 => $L('5511900000020', null, '2026-09-20 08:00:00')], [], $venda, null);
igual([$r['status'], $r['cronologia']], ['posterior', 'posterior'], '14. lead cadastrado depois da venda = posterior (não conta como conversão)');
$r = vendas_classificar([21 => $L('5511900000021', null, '2026-09-10 18:00:00')], [], $venda, null);
ok($r['status'] === 'telefone' && $r['cronologia'] === 'mesmo_dia' && $r['alertas'], '15. mesmo dia da venda (sem hora) = vínculo com alerta de cronologia');
$r = vendas_classificar([22 => $L('5511900000022', null, null)], [], $venda, null);
igual($r['cronologia'], 'desconhecida', 'lead sem data = cronologia desconhecida (não presume)');
$r = vendas_classificar([], [], $venda, null, [23 => $L(null, null, '2026-01-01')]);
igual($r['status'], 'ambigua', 'só a variante do 9º dígito coincide = ambígua (revisão humana)');
ok(!vendas_classificar([], [], $venda, null)['lead_ids'], 'nome nunca é usado (não há parâmetro de nome)');

grupo('Índice do CRM: contatos secundários, exclusão LGPD e isolamento entre empresas');
$empA = criar_empresa_teste('Vendas F1 A');
$empB = criar_empresa_teste('Vendas F1 B');
try {
    $pdo = db();
    $lead = function (int $emp, string $tel, ?string $email, string $criado, ?string $excluido = null) use ($pdo) {
        $pdo->prepare("INSERT INTO leads (empresa_id, nome, telefone, email, origem, etapa, criado_em, excluido_em) VALUES (:e, 'Pessoa Fictícia', :t, :m, 'manual', 'novo_lead', :c, :x)")
            ->execute(['e' => $emp, 't' => $tel, 'm' => $email, 'c' => $criado, 'x' => $excluido]);
        return (int) $pdo->lastInsertId();
    };
    $a1 = $lead($empA, '(11) 90000-0101', 'Joao.Ficticio@Teste.Invalid', '2026-04-10 09:00:00');
    $a2 = $lead($empA, '11900000101', null, '2026-05-02 10:00:00');          // mesma pessoa, outro empreendimento
    $a3 = $lead($empA, '1180000202', null, '2026-03-01 10:00:00');           // celular antigo sem 9
    $a4 = $lead($empA, '11900000303', 'apagado@teste.invalid', '2026-03-01', '2026-06-01 00:00:00');   // excluído (LGPD)
    $b1 = $lead($empB, '11900000101', 'joao.ficticio@teste.invalid', '2026-01-01 00:00:00');           // MESMO contato, outra empresa
    $a5 = $lead($empA, '11900000404', null, '2026-02-01 00:00:00');
    if (modulo_tabela_existe('whatsapp_contatos')) {
        $pdo->prepare('INSERT INTO whatsapp_contatos (empresa_id, telefone, lead_id) VALUES (:e, :t, :l)')->execute(['e' => $empA, 't' => '5511900000505', 'l' => $a5]);
    }
    $idxA = vendas_indice_crm($empA);
    $mapa = vendas_mapear_cabecalho(['id', 'datahoravenda', 'empreendimento', 'Celular', 'telefone', 'Email'])['mapa'];
    $cl = function (array $linha) use ($idxA, $mapa) {
        $v = vendas_normalizar_linha($linha, $mapa, false, HOJE);
        [$pt, $pe, $pv] = vendas_candidatos($idxA, $v);
        return vendas_classificar($pt, $pe, $v['data_venda'], $v['email'], $pv);
    };
    $r = $cl(['1', '46275', 'Emp', '5511900000101', '', 'joao.ficticio@teste.invalid']);   // 10/09/2026
    igual([$r['status'], $r['lead_ids']], ['forte', [$a1, $a2]], 'formatos diferentes do mesmo número + e-mail com maiúsculas = forte, com os 2 leads da pessoa');
    ok(!in_array($b1, $r['lead_ids'], true), '19. lead da OUTRA empresa com o mesmo contato nunca aparece');
    igual(vendas_dias_entre($r['primeira_data'], '2026-09-10'), 153, 'jornada a partir do 1º lead (10/04 → 10/09 = 153 dias)');
    $r2 = $cl(['2', '46275', 'Emp', '5511900000101', '', '']);   // 17. mesma pessoa, segunda unidade
    igual($r2['lead_ids'], [$a1, $a2], '17. mesma pessoa comprando 2ª unidade: liga aos mesmos leads (a venda é que é única)');
    igual($cl(['3', '46275', 'Emp', '11980000202', '', ''])['status'], 'ambigua', 'venda com 9 × CRM sem 9 = ambígua (não confirma sozinho)');
    igual($cl(['4', '46275', 'Emp', '5511900000303', '', 'apagado@teste.invalid'])['status'], 'sem', 'lead excluído (LGPD) não entra no cruzamento');
    if (modulo_tabela_existe('whatsapp_contatos')) {
        igual($cl(['5', '46275', 'Emp', '5511900000505', '', ''])['lead_ids'], [$a5], 'telefone do contato de WhatsApp ligado ao lead conta como contato secundário');
    }
    $idxB = vendas_indice_crm($empB);
    igual(array_keys($idxB['leads']), [$b1], 'índice da empresa B só tem os leads de B');
} finally {
    apagar_empresa_teste($empA);
    apagar_empresa_teste($empB);
}

grupo('Ferramenta de diagnóstico: só contagens');
$fonte = file_get_contents(__DIR__ . '/../ferramentas/analisar_vendas.php');
ok(!preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE)\s/i', $fonte), 'não grava nada no banco');
preg_match_all('/fwrite\(STDOUT[^;]*;/', $fonte, $saidas);
ok(!preg_match('/cliente_nome|cpf\'\]|email\'\]|telefones/', implode("\n", $saidas[0])), 'não imprime nome, CPF, e-mail nem telefone');

resumo_e_sair();
