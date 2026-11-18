<?php
/**
 * Inteligência de Vendas — Fase 5: painel e relatórios. Indicadores do §14,
 * filtros, relatórios do §16, coerência com a Jornada (mesma regra) e
 * isolamento entre empresas. Só dados FICTÍCIOS.
 *
 *   php -q tests/vendas_fase5_test.php
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../includes/funcoes.php';
require_once __DIR__ . '/../includes/funil.php';
require_once __DIR__ . '/../includes/vendas/painel.php';
require_once __DIR__ . '/../includes/relatorios/fila_exportacao.php';

const HOJE = '2026-11-18';
function q(string $sql)
{
    return db()->query($sql)->fetchColumn();
}

grupo('Classificação comercial (função pura, usada pela Jornada e pelo Painel)');
$eqU = fn (int $u) => ['classificacao' => 'crm', 'usuario_id' => $u];
$resp = [['usuario_id' => 10, 'equipe_id' => 1]];
igual(vendas_classificar_comercial('ambigua', false, null, null, [], null), 'pendente_revisao', 'ambígua → pendente de revisão');
igual(vendas_classificar_comercial('forte', true, 'posterior', $eqU(10), $resp, 1), 'nao_aplicavel', 'posterior → não se aplica');
igual(vendas_classificar_comercial('sem', false, null, $eqU(10), $resp, 1), 'nao_aplicavel', 'sem vínculo → não se aplica');
igual(vendas_classificar_comercial('forte', true, 'anterior', null, $resp, null), 'nao_identificado', 'sem equivalência → não identificado');
igual(vendas_classificar_comercial('forte', true, 'anterior', ['classificacao' => 'externo', 'usuario_id' => null], $resp, null), 'canal_externo', 'externo');
igual(vendas_classificar_comercial('forte', true, 'anterior', $eqU(10), [], 1), 'sem_historico', 'sem responsável na data');
igual(vendas_classificar_comercial('forte', true, 'anterior', $eqU(10), $resp, 1), 'responsavel_crm', 'mesmo usuário');
igual(vendas_classificar_comercial('forte', true, 'anterior', $eqU(11), $resp, 1), 'outro_corretor_mesma_estrutura', 'outro usuário, mesma equipe');
igual(vendas_classificar_comercial('forte', true, 'anterior', $eqU(11), $resp, 2), 'outra_equipe', 'outro usuário, outra equipe');
igual(vendas_media_mediana([10, 2, 30, null]), [14.0, 10], 'média e mediana ignoram vazios');
igual(vendas_media_mediana([1, 2, 3, 4]), [2.5, 2.5], 'mediana com quantidade par');

$empA = criar_empresa_teste('Vendas F5 A');
$empB = criar_empresa_teste('Vendas F5 B');
$tmp = [];
try {
    $pdo = db();
    $f = funcoes_seed_padrao($empA);
    funil_seed_padrao($empA);
    $fB = funcoes_seed_padrao($empB);
    $usr = function (int $emp, int $funcao, string $cargo, string $nome, ?int $eq = null) use ($pdo) {
        $pdo->prepare('INSERT INTO usuarios (empresa_id, funcao_id, nome, email, senha_hash, cargo, equipe_id) VALUES (:e, :f, :n, :m, :h, :c, :q)')
            ->execute(['e' => $emp, 'f' => $funcao, 'n' => $nome, 'm' => bin2hex(random_bytes(5)) . '@teste.invalid', 'h' => 'x', 'c' => $cargo, 'q' => $eq]);
        return (int) $pdo->lastInsertId();
    };
    $master = $usr($empA, $f['master_empresa'], 'master_empresa', 'Master Fictício');
    $pdo->prepare("INSERT INTO equipes (empresa_id, nome) VALUES (:e, 'Eq Um'), (:e2, 'Eq Dois')")->execute(['e' => $empA, 'e2' => $empA]);
    $eq1 = (int) $pdo->lastInsertId();
    $eq2 = $eq1 + 1;
    $cA = $usr($empA, $f['corretor'], 'corretor', 'Corretor A', $eq1);
    $cB = $usr($empA, $f['corretor'], 'corretor', 'Corretor B', $eq2);
    $M = relexp_usuario($master, $empA);
    $pdo->prepare("INSERT INTO empreendimentos (empresa_id, nome) VALUES (:e, 'Emp Um'), (:e2, 'Emp Dois')")->execute(['e' => $empA, 'e2' => $empA]);
    $e1 = (int) $pdo->lastInsertId();
    $e2 = $e1 + 1;
    $meta = (int) q("SELECT id FROM lead_origens WHERE empresa_id = $empA AND canal = 'meta_ads' ORDER BY id LIMIT 1") ?: null;
    $pdo->prepare("INSERT INTO oferta_listas (empresa_id, nome) VALUES (:e, 'Lista F5')")->execute(['e' => $empA]);
    $lista = (int) $pdo->lastInsertId();
    $lead = function (string $tel, ?string $email, string $criado, ?int $emp, array $extra = []) use ($pdo, $empA) {
        $c = array_merge(['empresa_id' => $empA, 'nome' => 'Lead F5', 'telefone' => $tel, 'email' => $email, 'criado_em' => $criado, 'empreendimento_id' => $emp, 'origem' => 'manual', 'etapa' => 'novo_lead'], $extra);
        $cols = array_keys($c);
        $pdo->prepare('INSERT INTO leads (' . implode(',', $cols) . ') VALUES (:' . implode(', :', $cols) . ')')->execute($c);
        return (int) $pdo->lastInsertId();
    };
    $atrib = function (int $l, int $u, ?int $eq, string $forma, string $de) use ($pdo, $empA) {
        $pdo->prepare('INSERT INTO lead_atribuicoes (empresa_id, lead_id, usuario_id, equipe_id, forma, atribuido_em, primeiro_atendimento_em) VALUES (:e, :l, :u, :q, :f, :de, :de2)')
            ->execute(['e' => $empA, 'l' => $l, 'u' => $u, 'q' => $eq, 'f' => $forma, 'de' => $de, 'de2' => $de]);
    };
    // Vendas em 10/09/2026. Cenários:
    // 1 forte (tel+email), jornada 100 d, Emp Um → comprou Emp Dois, origem Meta, com cA, remarketing antes.
    $L1 = $lead('11900000001', 'um@teste.invalid', '2026-06-02 10:00:00', $e1, ['origem' => 'meta_ads', 'origem_detalhe_id' => $meta, 'utm_campaign' => 'camp_f5']);
    $L1b = $lead('11900000001', 'um@teste.invalid', '2026-07-01 10:00:00', $e2);   // 2ª oportunidade
    $atrib($L1, $cA, $eq1, 'roleta', '2026-06-02 10:00:00');
    // 2 só telefone, jornada 10 d, comprou o mesmo, oferta ativa, com cB (eq2), transferência antes.
    $L2 = $lead('11900000002', null, '2026-08-31 10:00:00', $e1, ['oferta_lista_id' => $lista]);
    $atrib($L2, $cB, $eq2, 'oferta_ativa', '2026-08-31 10:00:00');
    $pdo->prepare("INSERT INTO lead_transferencias (empresa_id, lead_id, de_usuario_id, para_usuario_id, motivo, criado_em) VALUES (:e, :l, :de, :para, 'inatividade', '2026-09-01 10:00:00')")->execute(['e' => $empA, 'l' => $L2, 'de' => $cA, 'para' => $cB]);
    // 3 só e-mail, jornada 30 d.
    $L3 = $lead('11977770003', 'tres@teste.invalid', '2026-08-11 10:00:00', $e2);
    // 4 posterior; 5 ambígua; 6 sem; 7 distratado (não conta); 8 fora do período.
    $lead('11900000004', null, '2026-09-20 10:00:00', null);
    $lead('11900000005', null, '2026-05-01 10:00:00', null);
    $lead('11977770005', 'cinco@teste.invalid', '2026-05-01 10:00:00', null);
    // Eventos DEPOIS da venda / encerrados antes: não podem contar.
    $pdo->prepare("INSERT INTO empreendimentos (empresa_id, nome) VALUES (:e, 'Emp Três')")->execute(['e' => $empA]);
    $e3 = (int) $pdo->lastInsertId();
    $lead('11900000001', 'um@teste.invalid', '2026-09-25 10:00:00', $e3);                       // interesse depois da venda
    $pdo->prepare("INSERT INTO lead_atribuicoes (empresa_id, lead_id, usuario_id, equipe_id, forma, atribuido_em, encerrada_em) VALUES (:e, :l, :u, :q, 'roleta', '2026-07-01 10:00:00', '2026-08-01 10:00:00')")
        ->execute(['e' => $empA, 'l' => $L1b, 'u' => $cB, 'q' => $eq2]);                           // encerrada antes da venda
    $atrib($L3, $cA, $eq1, 'roleta', '2026-09-20 10:00:00');                                         // começou depois da venda
    $pdo->prepare("INSERT INTO lead_transferencias (empresa_id, lead_id, de_usuario_id, para_usuario_id, motivo, criado_em) VALUES (:e, :l, :de, :para, 'administrativa', '2026-09-20 10:00:00')")
        ->execute(['e' => $empA, 'l' => $L2, 'de' => $cB, 'para' => $cA]);                         // transferência depois da venda
    // Remarketing: antes da venda (L1) e depois (L3, não conta).
    $pdo->prepare('INSERT INTO whatsapp_contas (empresa_id) VALUES (:e)')->execute(['e' => $empA]);
    $conta = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO whatsapp_campanhas (empresa_id, conta_id, nome, status) VALUES (:e, :c, 'Camp F5', 'concluida')")->execute(['e' => $empA, 'c' => $conta]);
    $camp = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO whatsapp_contatos (empresa_id, telefone) VALUES (:e, :t)')->execute(['e' => $empA, 't' => '5511999999999']);
    $ct = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO whatsapp_contatos (empresa_id, telefone) VALUES (:e, :t)')->execute(['e' => $empA, 't' => '5511999999998']);
    $ct2 = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO whatsapp_campanha_destinatarios (campanha_id, contato_id, lead_id, status, atualizado_em) VALUES (:c, :ct, :l, 'entregue', '2026-08-20 10:00:00'), (:c2, :ct2, :l2, 'entregue', '2026-09-25 10:00:00')")
        ->execute(['c' => $camp, 'ct' => $ct, 'l' => $L1, 'c2' => $camp, 'ct2' => $ct2, 'l2' => $L3]);

    $cab = ['id', 'Venda RM', 'Situação Proposta', 'datahoravenda', 'cliente', 'Celular', 'Email', 'empreendimento', 'id_corretor', 'corretor', 'midia', 'valor'];
    $linhas = [
        ['1', '1', 'Vendido', '10/09/2026', 'Cliente F5 Um', '5511900000001', 'um@teste.invalid', 'Emp Dois', '7001', 'VENDEDOR_B', 'Canal de Parceria', '300000'],
        ['2', '2', 'Vendido', '10/09/2026', 'Cliente F5 Dois', '5511900000002', 'x2@teste.invalid', 'Emp Um', '7002', 'VENDEDOR_B', 'Indicacao', '200000'],
        ['3', '3', 'Vendido', '10/09/2026', 'Cliente F5 Tres', '5511911110003', 'tres@teste.invalid', 'Emp Dois', '7003', 'VENDEDOR_X', 'Indicacao', '100000'],
        ['4', '4', 'Vendido', '10/09/2026', 'Cliente F5 Quatro', '5511900000004', '', 'Emp Um', '7003', 'VENDEDOR_X', 'Roleta', '50000'],
        ['5', '5', 'Vendido', '10/09/2026', 'Cliente F5 Cinco', '5511900000005', 'cinco@teste.invalid', 'Emp Um', '7003', 'VENDEDOR_X', 'Roleta', '40000'],
        ['6', '6', 'Vendido', '10/09/2026', 'Cliente F5 Seis', '5511922220006', 'seis@teste.invalid', 'Emp Um', '7003', 'VENDEDOR_X', 'Roleta', '30000'],
        ['7', '7', 'Distratado', '10/09/2026', 'Cliente F5 Sete', '5511900000001', 'um@teste.invalid', 'Emp Um', '7003', 'VENDEDOR_X', 'Roleta', '999999'],
        ['8', '8', 'Vendido', '10/09/2025', 'Cliente F5 Oito', '5511900000001', 'um@teste.invalid', 'Emp Um', '7003', 'VENDEDOR_X', 'Roleta', '777777'],
    ];
    $c = tempnam(sys_get_temp_dir(), 'wmv5') . '.csv';
    $tmp[] = $c;
    $fh = fopen($c, 'w');
    fputcsv($fh, $cab, ';', '"', '');
    foreach ($linhas as $l) {
        fputcsv($fh, $l, ';', '"', '');
    }
    fclose($fh);
    $leitura = vendas_ler_arquivo($c, 'csv');
    $lote = vendas_importar($M, ['caminho' => $c, 'ext' => 'csv', 'nome' => 't.csv'], vendas_mapear_cabecalho($leitura['cabecalho'])['mapa'], ['vendido'], true, null, HOJE);
    @unlink(vendas_arquivo_lote($lote, 'csv'));
    foreach (['Emp Um' => $e1, 'Emp Dois' => $e2] as $t => $id) {
        vendas_equivalencia_salvar($M, 'empreendimento', $t, ['classificacao' => 'crm', 'empreendimento_id' => $id]);
    }
    vendas_equivalencia_salvar($M, 'corretor', 'VENDEDOR_B', ['classificacao' => 'crm', 'usuario_id' => $cB, 'valor_origem' => '7001']);   // venda 1: cB ≠ cA, outra equipe
    vendas_equivalencia_salvar($M, 'corretor', 'VENDEDOR_B', ['classificacao' => 'crm', 'usuario_id' => $cB, 'valor_origem' => '7002']);   // venda 2: cB = responsável
    vendas_cruzar($empA);
    $fil = fn (array $g = []) => vendas_painel_filtros($empA, array_merge(['periodo' => 'personalizado', 'ini' => '2026-09-01', 'fim' => '2026-09-30'], $g));
    $fatos = vendas_fatos($fil())['fatos'];
    $porChave = array_column($fatos, null, 'chave');
    $ind = vendas_indicadores($fatos);

    grupo('Indicadores principais (§14)');
    igual($ind['vendas'], 6, 'só vendas do período e que contam como venda (distratado e 2025 fora)');
    igual([$ind['encontradas'], $ind['por_ambos'], $ind['por_telefone'], $ind['por_email']], [3, 1, 1, 1], 'encontradas: 1 telefone+e-mail, 1 só telefone, 1 só e-mail');
    igual([$ind['ambiguas'], $ind['lead_posterior'], $ind['sem']], [1, 1, 1], '1 ambígua, 1 posterior, 1 sem correspondência');
    igual($ind['multiplas_oportunidades'], 1, 'cliente com múltiplas oportunidades');
    igual($ind['empreendimento_diferente'], 1, 'comprou empreendimento diferente do 1º interesse (só a venda 1: Emp Um → Emp Dois)');
    igual([$ind['oferta_ativa'], $ind['remarketing'], $ind['redistribuicao']], [1, 1, 1], 'oferta ativa, remarketing ANTES da venda (o de depois não conta) e redistribuição');
    igual($ind['outro_canal'], 1, 'venda por outro canal com histórico no CRM (venda 1: vendedor de outra equipe)');
    igual([$ind['valor_total'], $ind['valor_encontradas']], [720000.0, 600000.0], 'VGV total e das encontradas');
    igual([$ind['jornada_media'], $ind['jornada_mediana']], [46.7, 30], 'jornada: 100, 10 e 30 dias → média 46,7, mediana 30');
    igual($ind['pct_encontradas'], 50.0, '50% encontradas');

    grupo('Fatos por venda');
    igual([$porChave['1']['classificacao'], $porChave['2']['classificacao'], $porChave['3']['classificacao'], $porChave['5']['classificacao']],
        ['outra_equipe', 'responsavel_crm', 'nao_identificado', 'pendente_revisao'], 'classificação comercial por venda');
    igual([$porChave['1']['movimento'], $porChave['2']['movimento'], $porChave['3']['movimento']], ['diferente', 'mesmo', 'mesmo'], 'movimento entre empreendimentos');
    igual([$porChave['1']['origem'], $porChave['1']['campanha']], ['Meta Ads', 'camp_f5'], 'origem e campanha do 1º cadastro');
    igual($porChave['1']['interesses'], [$e1, $e2], 'interesses em ordem (1º e intermediário)');
    igual($porChave['4']['dias_jornada'], null, 'posterior: sem tempo de jornada');
    igual($porChave['1']['interesses'], [$e1, $e2], 'interesse registrado DEPOIS da venda não entra');
    igual(array_column($porChave['1']['responsaveis'], 'usuario_id'), [$cA], 'atribuição encerrada antes da venda não é "responsável na data"');
    igual([$porChave['3']['primeiro_corretor_id'], $porChave['3']['responsaveis']], [null, []], 'atribuição que começou depois da venda é ignorada');
    igual($porChave['2']['transferencias'], 1, 'transferência depois da venda não conta');
    igual($porChave['5']['n_leads'], 0, 'ambígua: candidatos não contam como oportunidades do cliente');

    grupo('Coerência com a Jornada (mesma regra, mesmo número)');
    foreach (['1', '2', '3'] as $k) {
        $J = vendas_jornada($M, $porChave[$k]['id']);
        igual([$J['classificacao'], $J['metricas']['dias_jornada'], $J['metricas']['oferta_ativa'] === 'sim', $J['metricas']['campanhas_remarketing']],
            [$porChave[$k]['classificacao'], $porChave[$k]['dias_jornada'], $porChave[$k]['oferta'], $porChave[$k]['remarketing']], "venda $k: painel = jornada");
    }

    grupo('Filtros');
    igual(count(vendas_fatos($fil(['empreendimento' => $e2]))['fatos']), 2, 'empreendimento comprado');
    igual(array_column(vendas_fatos($fil(['equipe' => $eq2]))['fatos'], 'chave'), ['2'], 'equipe do responsável na data da venda');
    igual(array_column(vendas_fatos($fil(['corretor' => $cA]))['fatos'], 'chave'), ['1'], 'corretor responsável na data da venda');
    if ($meta) {
        igual(array_column(vendas_fatos($fil(['origem' => $meta]))['fatos'], 'chave'), ['1'], 'origem no CRM');
    }
    igual(array_column(vendas_fatos($fil(['situacao' => 'ambigua']))['fatos'], 'chave'), ['5'], 'situação da conciliação');
    igual(count(vendas_fatos(vendas_painel_filtros($empA, ['periodo' => 'personalizado', 'ini' => '2025-01-01', 'fim' => '2025-12-31']))['fatos']), 1, 'período (venda de 2025)');
    igual(vendas_painel_filtros($empA, ['situacao' => 'invalida', 'corretor' => '-3'])['situacao'], null, 'filtro inválido é ignorado');

    grupo('Relatórios especializados (§16)');
    $faixas = array_column(vendas_faixas_jornada($fatos), 'vendas', 'faixa');
    igual([$faixas['até 30 dias'], $faixas['91 a 180 dias']], [2, 1], 'faixas de duração');
    $mov = vendas_movimentos($fatos, [$e1 => 'Emp Um', $e2 => 'Emp Dois']);
    igual(array_map(fn ($m) => $m['de'] . '>' . $m['para'] . ':' . $m['vendas'], $mov), ['Emp Dois>Emp Dois:1', 'Emp Um>Emp Dois:1', 'Emp Um>Emp Um:1'], 'movimentos origem → comprado (ordem estável)');
    $g = array_column(vendas_agrupar($fatos, fn ($x) => $x['midia']), null, 'chave');
    igual([$g['Indicacao']['vendas'], $g['Indicacao']['encontradas']], [2, 2], 'agrupamento por mídia declarada');
    $prod = array_column(vendas_produtividade($empA, $fatos, $fil()['periodo']), null, 'usuario_id');
    igual([$prod[$cA]['vendas_responsavel'], $prod[$cB]['vendas_responsavel'], $prod[$cB]['recebidos'], $prod[$cA]['transferidos']], [1, 1, 0, 1], 'produtividade: vendas como responsável, recebidos e transferidos no período');

    grupo('Confirmada na revisão, mas o lead entrou depois da venda');
    $l9a = $lead('11900000009', null, '2026-09-22 10:00:00', null);
    $lead('11977770009', 'nove@teste.invalid', '2026-09-23 10:00:00', null);
    $c9 = tempnam(sys_get_temp_dir(), 'wmv5') . '.csv';
    $tmp[] = $c9;
    $fh = fopen($c9, 'w');
    fputcsv($fh, $cab, ';', '"', '');
    fputcsv($fh, ['9', '9', 'Vendido', '10/09/2026', 'Cliente F5 Nove', '5511900000009', 'nove@teste.invalid', 'Emp Um', '7003', 'VENDEDOR_X', 'Roleta', '10000'], ';', '"', '');
    fclose($fh);
    $l9 = vendas_ler_arquivo($c9, 'csv');
    $lote9 = vendas_importar($M, ['caminho' => $c9, 'ext' => 'csv', 'nome' => 't9.csv'], vendas_mapear_cabecalho($l9['cabecalho'])['mapa'], ['vendido'], true, null, HOJE);
    @unlink(vendas_arquivo_lote($lote9, 'csv'));
    vendas_cruzar($empA, $lote9);
    $v9 = (int) q("SELECT id FROM vendas_externas WHERE empresa_id = $empA AND chave_origem = '9'");
    vendas_revisar($M, $v9, 'confirmar', [$l9a], 'Confirmado (teste fictício), mas entrou depois.');
    $f9 = array_column(vendas_fatos($fil())['fatos'], null, 'chave')['9'];
    igual([$f9['situacao'], $f9['encontrada'], $f9['posterior'], $f9['dias_jornada']], ['confirmada', false, true, null], 'confirmada com entrada depois da venda: não conta como "já estava no CRM"');

    grupo('19. Isolamento');
    igual(vendas_fatos(vendas_painel_filtros($empB, ['periodo' => 'personalizado', 'ini' => '2026-09-01', 'fim' => '2026-09-30']))['fatos'], [], 'outra empresa não vê nada');
    $fonte = file_get_contents(__DIR__ . '/../includes/vendas/painel.php');
    ok(!preg_match('/\b(INSERT|UPDATE|DELETE)\s/i', $fonte), 'o painel só lê');
} finally {
    foreach ($tmp as $t) {
        @unlink($t);
    }
    apagar_empresa_teste($empA);
    apagar_empresa_teste($empB);
}
resumo_e_sair();
