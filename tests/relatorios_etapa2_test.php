<?php
/**
 * Central de Relatórios — Etapa 2: histórico estruturado, contratos,
 * origens, retroalimentação e motor. Todos os números conferidos por
 * consultas independentes ou por contagem do cenário montado.
 *
 *   php -q tests/relatorios_etapa2_test.php
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../includes/funcoes.php';
require_once __DIR__ . '/../includes/funil.php';
require_once __DIR__ . '/../includes/roleta.php';
require_once __DIR__ . '/../includes/recuperacao.php';
require_once __DIR__ . '/../includes/contratos.php';
require_once __DIR__ . '/../includes/origens.php';
require_once __DIR__ . '/../includes/relatorios/motor.php';
require_once __DIR__ . '/../includes/relatorios/retroalimentacao.php';

function v(string $sql)
{
    return db()->query($sql)->fetchColumn();
}

grupo('Períodos');
$agora = new DateTimeImmutable('2026-11-14 10:00:00');
$p = relatorio_periodo('mes_anterior', null, null, $agora);
ok($p['ini'] === '2026-10-01 00:00:00' && $p['fim'] === '2026-10-31 23:59:59', 'mês anterior = outubro inteiro');
ok($p['anterior']['ini'] === '2026-09-01 00:00:00' && $p['anterior']['fim'] === '2026-09-30 23:59:59', 'comparação do mês anterior = setembro inteiro');
$p = relatorio_periodo('trimestre', null, null, $agora);
ok($p['ini'] === '2026-10-01 00:00:00' && $p['fim'] === '2026-11-14 23:59:59' && $p['anterior']['ini'] === '2026-07-01 00:00:00' && $p['anterior']['fim'] === '2026-09-30 23:59:59', 'trimestre: 01/10 até hoje; anterior = 3º trimestre');
$p = relatorio_periodo('7d', null, null, $agora);
ok($p['ini'] === '2026-11-08 00:00:00' && $p['dias'] === 7 && $p['anterior']['ini'] === '2026-11-01 00:00:00' && $p['anterior']['fim'] === '2026-11-07 23:59:59', '7 dias + 7 dias anteriores');
$p = relatorio_periodo('ontem', null, null, $agora);
ok($p['ini'] === '2026-11-13 00:00:00' && $p['fim'] === '2026-11-13 23:59:59', 'ontem');
$p = relatorio_periodo('ano', null, null, $agora);
ok($p['ini'] === '2026-01-01 00:00:00' && $p['anterior']['ini'] === '2025-01-01 00:00:00' && $p['anterior']['fim'] === '2025-12-31 23:59:59', 'ano + ano anterior inteiro');
ok(isset(relatorio_periodo('personalizado', '2026-02-30', '2026-03-01')['erro']), 'data inexistente (30/02) → erro');
ok(isset(relatorio_periodo('personalizado', '2026-05-10', '2026-05-01')['erro']), 'início depois do fim → erro');
$p = relatorio_periodo('personalizado', '2026-03-01', '2026-03-10');
ok($p['dias'] === 10 && $p['anterior']['ini'] === '2026-02-19 00:00:00' && $p['anterior']['fim'] === '2026-02-28 23:59:59', 'personalizado de 10 dias → 10 dias anteriores');

grupo('Valores de contrato');
igual(contrato_valor('450.000,00'), 450000.0, '"450.000,00"');
igual(contrato_valor('450000'), 450000.0, '"450000"');
igual(contrato_valor('450.000'), 450000.0, '"450.000" (milhar)');
igual(contrato_valor('R$ 1.234.567,89'), 1234567.89, '"R$ 1.234.567,89"');
igual(contrato_valor('300000.50'), 300000.5, '"300000.50" (ponto decimal)');
igual(contrato_valor(''), null, 'vazio → sem valor');
igual(contrato_valor('abc'), false, 'texto → inválido');
igual(contrato_valor('0'), false, 'zero → inválido');

$emp = criar_empresa_teste('Relatorios E2');
$emp2 = criar_empresa_teste('Relatorios E2 Outra');
try {
    $pdo = db();
    $f = funcoes_seed_padrao($emp);
    funcoes_seed_padrao($emp2);
    funil_seed_padrao($emp);
    funil_seed_padrao($emp2);
    $etapa1 = funil_primeira_etapa($emp);
    $uid = function (string $nome, string $cargo) use ($pdo, $emp, $f) {
        $pdo->prepare('INSERT INTO usuarios (empresa_id, funcao_id, nome, email, senha_hash, cargo) VALUES (:e, :f, :n, :m, :h, :c)')
            ->execute(['e' => $emp, 'f' => $f[$cargo], 'n' => $nome, 'm' => bin2hex(random_bytes(5)) . '@teste.invalid', 'h' => 'x', 'c' => $cargo === 'master_empresa' ? 'master_empresa' : $cargo]);
        return (int) $pdo->lastInsertId();
    };
    $u = function (int $id, string $escopo, array $perms = []) use ($emp) {
        $nome = db()->query("SELECT nome FROM usuarios WHERE id = $id")->fetchColumn();
        return ['id' => $id, 'nome' => $nome, 'empresa_id' => $emp, 'empresa_ativa_id' => $emp, 'eh_superadmin' => false, 'escopo' => $escopo, 'permissoes' => $perms];
    };
    // Estrutura: S1 → equipe A (gerente gA); S2 → equipe B (gerente gB).
    $master = $uid('Master Teste', 'master_empresa');
    $gA = $uid('Gerente A', 'gerente');
    $gB = $uid('Gerente B', 'gerente');
    $sup1 = $uid('Super Um', 'superintendente');
    $pdo->prepare("INSERT INTO superintendencias (empresa_id, nome, superintendente_id) VALUES (:e, 'S1', :s)")->execute(['e' => $emp, 's' => $sup1]);
    $s1 = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO superintendencias (empresa_id, nome) VALUES (:e, 'S2')")->execute(['e' => $emp]);
    $s2 = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO equipes (empresa_id, nome, gerente_id, superintendencia_id) VALUES (:e, 'Equipe A', :g, :s)")->execute(['e' => $emp, 'g' => $gA, 's' => $s1]);
    $eqA = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO equipes (empresa_id, nome, gerente_id, superintendencia_id) VALUES (:e, 'Equipe B', :g, :s)")->execute(['e' => $emp, 'g' => $gB, 's' => $s2]);
    $eqB = (int) $pdo->lastInsertId();
    $c1 = $uid('Corretor Um', 'corretor');
    $c2 = $uid('Corretor Dois', 'corretor');
    $c3 = $uid('Corretor Tres', 'corretor');
    $pdo->exec("UPDATE usuarios SET equipe_id = $eqA WHERE id IN ($c1, $c2)");
    $pdo->exec("UPDATE usuarios SET equipe_id = $eqB WHERE id = $c3");
    $M = $u($master, 'toda_empresa', ['editar_leads', 'validar_contratos', 'configurar_funil']);
    $GA = $u($gA, 'equipe');
    $GB = $u($gB, 'equipe');
    $SU1 = $u($sup1, 'superintendencia');
    $C1 = $u($c1, 'propria_carteira', ['editar_leads']);
    $C2 = $u($c2, 'propria_carteira', ['editar_leads']);

    $pdo->prepare("INSERT INTO empreendimentos (empresa_id, nome) VALUES (:e, 'Residencial E2')")->execute(['e' => $emp]);
    $empr = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO roletas (empresa_id, nome, exigir_presenca, ativa, horario_inicio, horario_fim, dias_semana) VALUES (:e, 'Roleta E2', 0, 1, '00:00:00', '23:59:59', '1,2,3,4,5,6,7')")->execute(['e' => $emp]);
    $rol = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO roleta_empreendimentos (roleta_id, empreendimento_id, prioridade) VALUES (:r, :e, 1)')->execute(['r' => $rol, 'e' => $empr]);
    $pdo->exec("INSERT INTO roleta_corretores (roleta_id, usuario_id) VALUES ($rol, $c1), ($rol, $c2)");
    $novoLead = function (string $nome, ?string $tel, string $origem = 'manual') use ($pdo, $emp, $empr, $etapa1) {
        $pdo->prepare('INSERT INTO leads (empresa_id, empreendimento_id, nome, telefone, origem, etapa_id, etapa, ultima_atividade_em) VALUES (:e, :emp, :n, :t, :o, :et, :cat, NOW())')
            ->execute(['e' => $emp, 'emp' => $empr, 'n' => $nome, 't' => $tel, 'o' => $origem, 'et' => $etapa1['id'], 'cat' => $etapa1['categoria']]);
        $id = (int) $pdo->lastInsertId();
        if ($oid = origem_id_do_canal($emp, $origem)) {
            $pdo->exec("UPDATE leads SET origem_detalhe_id = $oid WHERE id = $id");
        }
        return $id;
    };
    $lead = fn (int $id) => db()->query("SELECT * FROM leads WHERE id = $id")->fetch();

    grupo('Origens configuráveis');
    igual((int) v("SELECT COUNT(*) FROM lead_origens WHERE empresa_id = $emp"), 0, 'empresa criada depois da migração começa sem origens');
    igual(count(origens_listar($emp)), 12, 'e ganha as 12 origens padrão na primeira consulta');
    origens_listar($emp);
    igual((int) v("SELECT COUNT(*) FROM lead_origens WHERE empresa_id = $emp"), 12, 'consultar de novo não duplica');
    $meta = origem_id_do_canal($emp, 'meta_ads');
    igual(v("SELECT nome FROM lead_origens WHERE id = $meta"), 'Meta Ads', 'canal meta_ads → origem "Meta Ads"');
    $r = origem_salvar($emp, null, 'Feirão de imóveis', 'manual', true);
    ok($r['ok'], 'nova origem criada');
    $feirao = $r['id'];
    ok(!origem_salvar($emp, null, 'Feirão  de  imóveis', 'outro', true)['ok'], 'nome repetido (espaços a mais) → recusado');
    ok(!origem_salvar($emp, $meta, 'Facebook', 'meta_ads', true)['ok'], 'origem do sistema não pode ser alterada');
    ok(!origem_salvar($emp, null, 'X', 'outro', true)['ok'], 'nome curto demais → recusado');
    ok(!origem_salvar($emp, null, 'Rádio', 'televisao', true)['ok'], 'grupo inexistente → recusado');
    ok(origem_carregar($emp2, $feirao) === null, 'origem de outra empresa não é encontrada');

    grupo('Atribuições: roleta, transferência e estrutura na data');
    $L1 = $novoLead('Cliente Um', '(11) 98888-0001');
    $L2 = $novoLead('Cliente Dois', '11988880002');
    $L3 = $novoLead('Cliente Tres', '+55 11 98888-0001'); // mesma pessoa do L1 (com DDI)
    $pdo->exec("UPDATE leads SET origem_detalhe_id = $feirao WHERE id = $L3");
    foreach ([$L1, $L2, $L3] as $l) {
        $res = roleta_distribuir_lead($l, 'manual', null);
        ok($res['status'] === 'distribuido', "lead $l distribuído pela roleta");
    }
    igual((int) v("SELECT COUNT(*) FROM lead_atribuicoes WHERE empresa_id = $emp AND forma = 'roleta' AND reconstruido = 0"), 3, '3 atribuições "roleta" gravadas ao vivo');
    igual((int) v("SELECT COUNT(*) FROM lead_atribuicoes WHERE empresa_id = $emp AND roleta_id = $rol AND equipe_id = $eqA AND superintendencia_id = $s1 AND gerente_id = $gA"), 3, 'estrutura da data gravada (equipe A / gerente A / S1)');
    igual((int) v("SELECT COUNT(*) FROM leads WHERE id IN ($L1,$L2,$L3) AND chegada_roleta_aberta = 1 AND chegada_roleta_id = $rol"), 3, 'chegada com a roleta aberta registrada');
    $dono1 = (int) $lead($L1)['responsavel_id'];
    $D1 = $u($dono1, 'propria_carteira', ['editar_leads']);

    // Atuação + contato efetivo do primeiro dono (mudança de etapa feita por ele).
    $etPC = funil_etapa_por_categoria($emp, 'primeiro_contato');
    ok(lead_mudar_etapa($lead($L1), (int) $etPC['id'], $D1), 'dono move L1 para "Primeiro contato"');
    $a1 = $pdo->query("SELECT * FROM lead_atribuicoes WHERE lead_id = $L1")->fetch();
    ok($a1['primeiro_atendimento_em'] !== null && $a1['primeiro_contato_efetivo_em'] !== null && $a1['contato_efetivo_via'] === 'etapa', 'atribuição ganha 1º atendimento e contato efetivo (via etapa)');
    ok($lead($L1)['primeiro_contato_efetivo_em'] !== null, 'lead ganha o 1º contato efetivo');
    igual((int) v("SELECT COUNT(*) FROM lead_etapa_historico WHERE lead_id = $L1 AND origem = 'usuario' AND para_categoria = 'primeiro_contato' AND usuario_id = $dono1 AND responsavel_id = $dono1"), 1, 'mudança de etapa no histórico estruturado (usuário e dono)');

    // Transferência para c3 (equipe B).
    lead_transferir($lead($L1), $c3, 'administrativa', $M, 'teste');
    $abertas = $pdo->query("SELECT usuario_id, forma, equipe_id FROM lead_atribuicoes WHERE lead_id = $L1 AND encerrada_em IS NULL")->fetchAll();
    ok(count($abertas) === 1 && (int) $abertas[0]['usuario_id'] === $c3 && $abertas[0]['forma'] === 'transferencia' && (int) $abertas[0]['equipe_id'] === $eqB, 'uma só atribuição aberta: c3, transferência, equipe B');
    $a1 = $pdo->query("SELECT * FROM lead_atribuicoes WHERE id = {$a1['id']}")->fetch();
    ok($a1['encerrada_em'] !== null && $a1['motivo_encerramento'] === 'transferencia' && $a1['primeiro_atendimento_em'] !== null, 'atribuição anterior encerrada, MAS o atendimento do 1º corretor continua registrado');
    ok($lead($L1)['primeiro_contato_em'] === null, '(legado) leads.primeiro_contato_em zerado para o novo dono — por isso o histórico existe');
    ok(v("SELECT transferencia_id FROM lead_atribuicoes WHERE lead_id = $L1 AND usuario_id = $c3") == v("SELECT MAX(id) FROM lead_transferencias WHERE lead_id = $L1"), 'atribuição aponta para a linha da transferência');

    // Atuação do novo dono não altera a do anterior.
    lead_registrar_feedback($L1);
    igual((int) v("SELECT COUNT(*) FROM lead_atribuicoes WHERE lead_id = $L1 AND primeiro_atendimento_em IS NOT NULL"), 2, 'feedback do novo dono marca só a atribuição dele');
    // Contato via WhatsApp não é atendimento do corretor.
    historico_registrar_contato_efetivo($L2, 'whatsapp');
    $a2 = $pdo->query("SELECT * FROM lead_atribuicoes WHERE lead_id = $L2")->fetch();
    ok($a2['primeiro_contato_efetivo_em'] !== null && $a2['contato_efetivo_via'] === 'whatsapp' && $a2['primeiro_atendimento_em'] === null, 'cliente escreveu no WhatsApp → contato efetivo, sem marcar atendimento do corretor');
    // Mudança feita por jornada não é contato.
    historico_registrar_etapa($emp, $L3, $etapa1, funil_etapa_por_categoria($emp, 'em_atendimento'), null, 'jornada');
    igual(v("SELECT primeiro_contato_efetivo_em FROM leads WHERE id = $L3"), null, 'etapa movida por jornada NÃO vira contato efetivo');
    igual((int) v("SELECT COUNT(*) FROM lead_etapa_historico WHERE lead_id = $L3 AND origem = 'jornada'"), 1, 'mas fica no histórico com origem "jornada"');

    // Dono gravado fora do fluxo (dado antigo): a atuação não pode ir para a atribuição de outra pessoa.
    $LX = $novoLead('Cliente X', '11988880009');
    roleta_distribuir_lead($LX, 'manual', null);
    $pdo->exec("UPDATE leads SET responsavel_id = $c3 WHERE id = $LX");
    lead_registrar_feedback($LX);
    igual(v("SELECT primeiro_atendimento_em FROM lead_atribuicoes WHERE lead_id = $LX"), null, 'atuação de quem não é o dono da atribuição aberta não é gravada nela');
    $pdo->exec("DELETE FROM leads WHERE id = $LX");

    grupo('Mudança de equipe não é recebimento');
    $donoL2 = (int) $lead($L2)['responsavel_id'];
    lead_registrar_feedback($L2);
    $pdo->exec("UPDATE usuarios SET equipe_id = $eqB WHERE id = $donoL2");
    lead_transferir($lead($L2), $donoL2, 'mudanca_equipe', $M);
    $ab = $pdo->query("SELECT * FROM lead_atribuicoes WHERE lead_id = $L2 AND encerrada_em IS NULL")->fetch();
    ok($ab['forma'] === 'mudanca_equipe' && (int) $ab['equipe_id'] === $eqB && $ab['primeiro_atendimento_em'] !== null && $ab['contato_efetivo_via'] === 'whatsapp', 'nova linha "mudança de equipe" herda atendimento e contato do mesmo corretor');
    $pdo->exec("UPDATE usuarios SET equipe_id = $eqA WHERE id = $donoL2");

    grupo('Perda por inatividade e redistribuição');
    $L4 = $novoLead('Cliente Quatro', '11988880004');
    roleta_distribuir_lead($L4, 'manual', null);
    $perdedor = (int) $lead($L4)['responsavel_id'];
    $pdo->exec("UPDATE leads SET ultimo_feedback_em = DATE_SUB(NOW(), INTERVAL 10 DAY), atribuido_em = DATE_SUB(NOW(), INTERVAL 10 DAY) WHERE id = $L4");
    $regra = recuperacao_regra($emp);
    $regra['ativa'] = 1;
    $regra['redistribuir_automaticamente'] = 1;
    $regra['dias_sem_feedback'] = 4;
    $res = recuperacao_perder_lead($L4, $regra);
    ok($res !== null, 'lead perdido por inatividade (' . var_export($res, true) . ')');
    igual(v("SELECT motivo_encerramento FROM lead_atribuicoes WHERE lead_id = $L4 AND usuario_id = $perdedor ORDER BY id LIMIT 1"), 'inatividade', 'atribuição do corretor que perdeu: encerrada por inatividade');
    $nova = $pdo->query("SELECT * FROM lead_atribuicoes WHERE lead_id = $L4 AND encerrada_em IS NULL")->fetch();
    ok($nova && $nova['forma'] === 'redistribuicao' && (int) $nova['usuario_id'] !== $perdedor, 'redistribuído para OUTRO corretor, forma "redistribuição"');
    igual((int) v("SELECT COUNT(*) FROM roleta_fila_historico WHERE lead_id = $L4 AND saiu_em IS NOT NULL AND resultado = 'distribuido'"), 1, 'passagem pela fila registrada (entrou e saiu)');

    grupo('Motor: leads (coorte) com permissões');
    $F = relatorio_filtros($M, ['periodo' => 'hoje']);
    $r = relatorio_leads($M, $F);
    igual($r['leads_recebidos']['valor'], 4, 'master: 4 leads recebidos hoje');
    igual($r['leads_unicos']['valor'], 3, 'únicos = 3 (L1 e L3 têm o mesmo telefone, com e sem +55)');
    igual($r['leads_distribuidos']['valor'], 4, '4 distribuídos');
    igual($r['leads_com_atuacao']['valor'], 2, '2 com atuação (L1, L2)');
    igual($r['contatos_efetivos']['valor'], 2, '2 com contato efetivo (L1 etapa, L2 WhatsApp)');
    igual($r['taxa_contato_efetivo']['valor'], 50.0, 'taxa de contato efetivo 50%');
    $indep = (int) v("SELECT COUNT(*) FROM leads WHERE empresa_id = $emp AND excluido_em IS NULL AND DATE(criado_em) = CURDATE()");
    igual($r['leads_recebidos']['valor'], $indep, 'conferência independente: recebidos = COUNT direto');
    $rGA = relatorio_leads($GA, $F);
    igual($rGA['leads_recebidos']['valor'], (int) v("SELECT COUNT(*) FROM leads WHERE empresa_id = $emp AND equipe_id = $eqA AND excluido_em IS NULL"), 'gerente A (coorte): só os leads que estão hoje na equipe A');
    $rC = relatorio_leads(['id' => $c3] + $C1, $F);
    igual($rC['leads_recebidos']['valor'], (int) v("SELECT COUNT(*) FROM leads WHERE responsavel_id = $c3 AND excluido_em IS NULL"), 'corretor: só a própria carteira');
    $ori = relatorio_leads_por_origem($M, $F);
    $porNome = array_column($ori, 'leads', 'origem');
    ok(($porNome['Feirão de imóveis'] ?? 0) == 1 && ($porNome['Cadastro manual (não detalhado)'] ?? 0) == 3, 'leads por origem detalhada: Feirão 1, Cadastro manual 3');

    grupo('Motor: corretores pelo histórico (não pela carteira)');
    $linhas = array_column(relatorio_corretores($M, $F), null, 'usuario_id');
    igual($linhas[$dono1]['leads_recebidos'] >= 1 && $linhas[$dono1]['atendidos'] >= 1, true, 'quem perdeu L1 por transferência continua com o lead RECEBIDO e ATENDIDO');
    igual($linhas[$c3]['leads_recebidos'], 1, 'c3 recebeu 1 (transferência)');
    $totalRec = array_sum(array_column($linhas, 'recebimentos'));
    igual($totalRec, (int) v("SELECT COUNT(*) FROM lead_atribuicoes WHERE empresa_id = $emp AND forma <> 'mudanca_equipe'"), 'conferência: soma dos recebimentos = atribuições (sem mudança de equipe)');
    igual(isset($linhas[$donoL2]) ? $linhas[$donoL2]['recebimentos'] : -1, (int) v("SELECT COUNT(*) FROM lead_atribuicoes WHERE usuario_id = $donoL2 AND forma <> 'mudanca_equipe'"), 'mudança de equipe não soma recebimento ao corretor');
    igual($linhas[$perdedor]['perdidos_inatividade'], 1, 'perdido por inatividade aparece na linha de quem perdeu');
    // Gerente A continua vendo o que a equipe A recebeu, mesmo com L1 hoje na equipe B.
    $lGA = array_column(relatorio_corretores($GA, $F), null, 'usuario_id');
    ok(isset($lGA[$dono1]) && !isset($lGA[$c3]), 'gerente A: vê o corretor da equipe A (histórico) e não vê c3 (equipe B)');
    ok(array_sum(array_column($lGA, 'recebimentos')) === (int) v("SELECT COUNT(*) FROM lead_atribuicoes WHERE empresa_id = $emp AND equipe_id = $eqA AND forma <> 'mudanca_equipe'"), 'gerente A: soma = atribuições com equipe A na data');
    $lGB = array_column(relatorio_corretores($GB, $F), null, 'usuario_id');
    ok(isset($lGB[$c3]) && !isset($lGB[$dono1]), 'gerente B: vê c3, não vê a equipe A');
    $lS = relatorio_corretores($SU1, $F);
    ok(count($lS) >= 1 && !in_array($c3, array_column($lS, 'usuario_id')), 'superintendente S1: só a S1');
    $lC = relatorio_corretores($C2, $F);
    ok(count($lC) <= 1 && (!$lC || (int) $lC[0]['usuario_id'] === $c2), 'corretor: só a própria linha');
    $perdas = relatorio_perdas($M, $F);
    ok($perdas['perdidos_inatividade']['valor'] === 1 && $perdas['redistribuidos']['valor'] === 1, 'perdas: 1 perdido, 1 redistribuído');

    grupo('Filtros inválidos e isolamento');
    $Fx = relatorio_filtros($M, ['periodo' => 'hoje', 'equipe' => [999999]]);
    ok($Fx['vazio'] && relatorio_leads($M, $Fx)['leads_recebidos']['valor'] === 0 && relatorio_corretores($M, $Fx) === [], 'equipe inexistente → resultado vazio (nunca "tudo")');
    $eqOutra = (int) v("SELECT MIN(id) FROM equipes WHERE empresa_id <> $emp") ?: 0;
    if ($eqOutra) {
        ok(relatorio_filtros($M, ['periodo' => 'hoje', 'equipe' => [$eqOutra]])['vazio'], 'equipe de OUTRA empresa → vazio');
    }
    ok(relatorio_filtros($M, ['periodo' => 'hoje', 'canal' => ['tv']])['vazio'], 'grupo de origem inválido → vazio');
    ok(relatorio_filtros($M, ['periodo' => 'hoje', 'corretor' => ['abc']])['vazio'], 'id não numérico → vazio');
    $Fe = relatorio_filtros($M, ['periodo' => 'hoje', 'equipe' => [$eqB]]);
    igual(relatorio_leads($M, $Fe)['leads_recebidos']['valor'], (int) v("SELECT COUNT(DISTINCT lead_id) FROM lead_atribuicoes WHERE empresa_id = $emp AND equipe_id = $eqB"), 'filtro equipe B (coorte) = leads que passaram pela equipe B');
    $Fc = relatorio_filtros($M, ['periodo' => 'hoje', 'corretor' => [$c3]]);
    igual(array_column(relatorio_corretores($M, $Fc), 'usuario_id'), [$c3], 'filtro de corretor no relatório de corretores');
    $Fo = relatorio_filtros($M, ['periodo' => 'hoje', 'origem' => [$feirao]]);
    igual(relatorio_leads($M, $Fo)['leads_recebidos']['valor'], 1, 'filtro de origem detalhada');
    $out = ['id' => 1, 'nome' => 'x', 'empresa_id' => $emp2, 'empresa_ativa_id' => $emp2, 'escopo' => 'toda_empresa', 'permissoes' => []];
    $F2 = relatorio_filtros($out, ['periodo' => 'hoje']);
    ok(relatorio_leads($out, $F2)['leads_recebidos']['valor'] === 0 && relatorio_corretores($out, $F2) === [], 'outra empresa: nada vaza');
    $Fy = relatorio_filtros($M, ['periodo' => 'personalizado', 'ini' => '2026-02-31', 'fim' => '2026-03-01']);
    ok($Fy['erro'] && relatorio_leads($M, $Fy)['leads_recebidos']['valor'] === 0, 'período inválido → erro, sem números');

    grupo('Contratos');
    $L5 = $novoLead('Cliente Cinco', '11988880005');
    roleta_distribuir_lead($L5, 'manual', null);
    $amanha = (new DateTimeImmutable('+1 day'))->format('Y-m-d');
    ok(!contrato_registrar($M, $lead($L5), ['assinado_em' => $amanha])['ok'], 'data no futuro → recusado');
    ok(!contrato_registrar($M, $lead($L5), ['assinado_em' => date('Y-m-d'), 'valor_vgv' => 'abc'])['ok'], 'valor inválido → recusado');
    $semDono = $novoLead('Sem dono', null);
    ok(!contrato_registrar($M, $lead($semDono), ['assinado_em' => date('Y-m-d')])['ok'], 'lead sem corretor → recusado');
    $r = contrato_registrar($M, $lead($L5), ['assinado_em' => date('Y-m-d'), 'valor_vgv' => '450.000,00', 'unidade' => 'Apto 12']);
    ok($r['ok'] && $r['movido'], 'contrato registrado e lead movido para "Contrato assinado"');
    $ct = contrato_carregar($emp, $r['id']);
    ok($ct['status'] === 'registrado' && (int) $ct['usuario_id'] === (int) $lead($L5)['responsavel_id'] && (int) $ct['equipe_id'] === $eqA, 'corretor e estrutura do lead na data gravados');
    $FC = relatorio_filtros($M, ['periodo' => 'hoje']);
    $k = relatorio_contratos($M, $FC);
    ok($k['contratos_validados']['valor'] === 0 && $k['contratos_aguardando']['valor'] === 1 && $k['vgv']['valor'] === null, 'antes da validação: 0 vendas, 1 aguardando, sem VGV');
    igual($k['contratos_pela_etapa']['valor'], 1, 'sinal do Kanban contado à parte (1)');
    ok(contrato_editar($M, $ct, ['assinado_em' => date('Y-m-d'), 'valor_vgv' => '500.000,00', 'unidade' => 'Apto 12'])['ok'], 'correção enquanto aguarda validação');
    ok(contrato_validar($M, contrato_carregar($emp, $r['id']))['ok'], 'validado');
    ok(!contrato_validar($M, contrato_carregar($emp, $r['id']))['ok'], 'validar de novo → recusado');
    ok(!contrato_editar($M, contrato_carregar($emp, $r['id']), ['assinado_em' => date('Y-m-d')])['ok'], 'contrato validado não pode ser corrigido');
    $k = relatorio_contratos($M, $FC);
    ok($k['contratos_validados']['valor'] === 1 && $k['vgv']['valor'] === 500000.0, 'depois da validação: 1 venda, VGV R$ 500.000');
    ok(str_contains($k['vgv']['nota'], '1 de 1'), 'nota do VGV diz quantos contratos têm valor');
    $kGB = relatorio_contratos($GB, $FC);
    igual($kGB['contratos_validados']['valor'], 0, 'gerente B não vê o contrato da equipe A');
    $kR = relatorio_contratos($M, relatorio_filtros($M, ['periodo' => 'hoje', 'roleta' => [$rol]]));
    igual($kR['contratos_validados']['valor'], 1, 'filtro de roleta vale pela atribuição que levou o lead ao corretor');
    lead_transferir($lead($L5), $c3, 'administrativa', $M);
    $kGA = relatorio_contratos($GA, $FC);
    igual($kGA['contratos_validados']['valor'], 1, 'lead transferido depois: a venda continua da equipe A (estrutura na assinatura)');
    ok(!contrato_distratar($M, contrato_carregar($emp, $r['id']), date('Y-m-d'), 'x')['ok'], 'distrato sem motivo → recusado');
    ok(contrato_distratar($M, contrato_carregar($emp, $r['id']), date('Y-m-d'), 'crédito não aprovado')['ok'], 'distrato registrado');
    $k = relatorio_contratos($M, $FC);
    ok($k['contratos_validados']['valor'] === 0 && $k['distratos']['valor'] === 1 && (int) v("SELECT COUNT(*) FROM lead_contratos WHERE id = {$r['id']}") === 1, 'distratado: sai das vendas, entra nos distratos, registro mantido');

    grupo('Visitas (confirmada × realizada)');
    $ag = function (string $status, ?string $conf) use ($pdo, $emp, $L1, $c3) {
        $pdo->prepare("INSERT INTO agenda_compromissos (empresa_id, lead_id, usuario_id, tipo, titulo, inicio, status, criado_por, concluido_em, confirmada_em)
                       VALUES (:e, :l, :u, 'visita', 'Visita', NOW(), :s, :u2, :c, :cf)")
            ->execute(['e' => $emp, 'l' => $L1, 'u' => $c3, 'u2' => $c3, 's' => $status, 'c' => $status === 'agendado' ? null : date('Y-m-d H:i:s'), 'cf' => $conf]);
    };
    $ag('agendado', date('Y-m-d H:i:s'));
    $ag('realizado', date('Y-m-d H:i:s'));
    $ag('cliente_ausente', null);
    $ag('realizado', null);
    $vi = relatorio_visitas($M, $FC);
    ok($vi['visitas_agendadas']['valor'] === 4 && $vi['visitas_confirmadas']['valor'] === 2 && $vi['visitas_realizadas']['valor'] === 2 && $vi['visitas_ausentes']['valor'] === 1, 'agendadas 4, confirmadas 2, realizadas 2, ausentes 1');
    igual($vi['taxa_comparecimento']['valor'], 66.7, 'comparecimento = 2 ÷ 3');

    grupo('Executivo: comparação só com base válida');
    $ex = relatorio_executivo($M, relatorio_filtros($M, ['periodo' => 'hoje']));
    ok($ex['indicadores']['leads_recebidos']['anterior'] === 0 && $ex['indicadores']['leads_recebidos']['variacao'] === null, 'ontem = 0 → variação "—" (não +∞%)');
    ok($ex['cobertura']['instalado'] && $ex['cobertura']['reconstruidas'] === 0, 'cobertura: histórico ao vivo, nada reconstruído');

    grupo('Funil (movimentação e tempo por etapa)');
    $fu = relatorio_funil($M, $FC);
    ok($fu['transicoes'] === (int) v("SELECT COUNT(*) FROM lead_etapa_historico WHERE empresa_id = $emp"), 'transições = linhas do histórico');
    ok($fu['por_origem']['jornada'] === 1 && $fu['avancos'] >= 2, 'origem jornada separada; avanços contados');

    grupo('Lead excluído some de tudo');
    $antes = relatorio_leads($M, $FC)['leads_recebidos']['valor'];
    $pdo->exec("UPDATE leads SET excluido_em = NOW() WHERE id = $semDono");
    igual(relatorio_leads($M, $FC)['leads_recebidos']['valor'], $antes - 1, 'excluído não conta');

    grupo('Retroalimentação (só fontes registradas)');
    // Lead "antigo": roleta → transferência → etapa, gravados só nas tabelas antigas.
    $LA = $novoLead('Antigo', '11977770001');
    $pdo->exec("UPDATE leads SET responsavel_id = $c3, equipe_id = $eqB, criado_em = '2026-01-05 09:00:00', atribuido_em = '2026-01-10 10:00:00' WHERE id = $LA");
    $pdo->exec("INSERT INTO roleta_distribuicoes (empresa_id, roleta_id, lead_id, usuario_id, origem, distribuido_em) VALUES ($emp, $rol, $LA, $c1, 'manual', '2026-01-05 09:00:01')");
    $pdo->exec("INSERT INTO lead_transferencias (empresa_id, lead_id, empreendimento_id, de_usuario_id, de_equipe_id, de_gerente_id, de_superintendencia_id, para_usuario_id, para_equipe_id, para_gerente_id, para_superintendencia_id, motivo, criado_em)
                VALUES ($emp, $LA, $empr, $c1, $eqA, $gA, $s1, $c3, $eqB, $gB, $s2, 'administrativa', '2026-01-10 10:00:00')");
    $pdo->exec("INSERT INTO lead_eventos (lead_id, usuario_id, tipo, descricao, criado_em) VALUES ($LA, $c1, 'contato', 'Falou com o cliente', '2026-01-06 11:00:00')");
    $pcId = (int) $etPC['id'];
    $pdo->exec("INSERT INTO auditoria (empresa_id, usuario_id, acao, entidade, entidade_id, detalhes_json, criado_em) VALUES ($emp, $c1, 'lead.etapa_alterada', 'lead', $LA,
                '{\"de_etapa_id\":{$etapa1['id']},\"para_etapa_id\":$pcId,\"de\":\"novo_lead\",\"para\":\"primeiro_contato\"}', '2026-01-06 11:00:00')");
    $pdo->exec("DELETE FROM lead_atribuicoes WHERE lead_id = $LA");
    $sim = retroalimentar_historico($emp, true);
    igual((int) v("SELECT COUNT(*) FROM lead_atribuicoes WHERE lead_id = $LA"), 0, 'simulação não grava nada');
    ok($sim['atribuicoes'] >= 2, 'simulação conta o que seria criado');
    $res = retroalimentar_historico($emp);
    $hist = $pdo->query("SELECT * FROM lead_atribuicoes WHERE lead_id = $LA ORDER BY atribuido_em")->fetchAll();
    ok(count($hist) === 2 && $hist[0]['forma'] === 'roleta' && $hist[1]['forma'] === 'transferencia', 'reconstruído: roleta (c1) → transferência (c3)');
    ok((int) $hist[0]['reconstruido'] === 1 && (int) $hist[1]['reconstruido'] === 1, 'marcadas como reconstruídas');
    ok($hist[0]['encerrada_em'] === '2026-01-10 10:00:00' && $hist[0]['motivo_encerramento'] === 'transferencia', 'a 1ª termina na transferência');
    ok((int) $hist[0]['equipe_id'] === $eqA && (int) $hist[1]['equipe_id'] === $eqB, 'estrutura da data: equipe A (do "de" da transferência) e B (do "para")');
    ok($hist[0]['primeiro_atendimento_em'] === '2026-01-06 11:00:00' && $hist[0]['primeiro_contato_efetivo_em'] === '2026-01-06 11:00:00' && $hist[0]['contato_efetivo_via'] === 'etapa', 'atendimento e contato efetivo do c1 reconstruídos pelos registros');
    ok($hist[1]['primeiro_atendimento_em'] === null, 'c3 sem registro de atuação → fica vazio (nada por suposição)');
    igual((int) v("SELECT COUNT(*) FROM lead_etapa_historico WHERE lead_id = $LA AND reconstruido = 1 AND origem = 'usuario' AND responsavel_id = $c1"), 1, 'etapa reconstruída da auditoria, com o dono da época');
    igual(v("SELECT primeiro_contato_efetivo_em FROM leads WHERE id = $LA"), '2026-01-06 11:00:00', '1º contato efetivo do lead preenchido');
    igual((int) v("SELECT COUNT(*) FROM lead_atribuicoes WHERE empresa_id = $emp AND reconstruido = 1 AND lead_id <> $LA"), 0, 'leads com histórico ao vivo não ganham linhas reconstruídas');
    // Só a entrega da roleta registrada e o dono atual: a estrutura da atribuição aberta vem da equipe atual do lead.
    $LB = $novoLead('Antigo B', '11977770002');
    $pdo->exec("UPDATE leads SET responsavel_id = $c1, equipe_id = $eqA, atribuido_em = '2026-01-07 08:00:00' WHERE id = $LB");
    $pdo->exec("INSERT INTO roleta_distribuicoes (empresa_id, roleta_id, lead_id, usuario_id, origem, distribuido_em) VALUES ($emp, $rol, $LB, $c1, 'manual', '2026-01-07 08:00:00')");
    $pdo->exec("DELETE FROM lead_atribuicoes WHERE lead_id = $LB");
    retroalimentar_historico($emp);
    $hb = $pdo->query("SELECT * FROM lead_atribuicoes WHERE lead_id = $LB")->fetchAll();
    ok(count($hb) === 1 && $hb[0]['encerrada_em'] === null && (int) $hb[0]['equipe_id'] === $eqA && (int) $hb[0]['gerente_id'] === $gA, 'atribuição aberta reconstruída com a equipe atual do lead (dono atual)');
    // Dono atual sem nenhuma fonte: forma "não identificada".
    $LC = $novoLead('Antigo C', '11977770003');
    $pdo->exec("UPDATE leads SET responsavel_id = $c2, equipe_id = $eqA, atribuido_em = '2026-01-08 08:00:00' WHERE id = $LC");
    $pdo->exec("DELETE FROM lead_atribuicoes WHERE lead_id = $LC");
    retroalimentar_historico($emp);
    igual(v("SELECT forma FROM lead_atribuicoes WHERE lead_id = $LC"), 'desconhecida', 'dono sem fonte registrada → forma "não identificada", com a data de atribuição do lead');
    $de = retroalimentar_historico($emp);
    ok($de['atribuicoes'] === 0 && $de['etapas'] === 0 && $de['fila'] === 0, 'segunda execução: nada novo (idempotente)');
    $FJ = relatorio_filtros($M, ['periodo' => 'personalizado', 'ini' => '2026-01-01', 'fim' => '2026-01-31']);
    $cob = relatorio_cobertura_historico($M, $FJ);
    ok($cob['reconstruidas'] === 4 && $cob['total'] === 4, 'cobertura de janeiro: 4 de 4 atribuições reconstruídas (a tela vai avisar)');
    $lj = array_column(relatorio_corretores($GA, $FJ), null, 'usuario_id');
    ok(isset($lj[$c1]) && $lj[$c1]['atendidos'] === 1, 'gerente A vê em janeiro o lead que o c1 recebeu e atendeu (hoje na equipe B)');
} finally {
    apagar_empresa_teste($emp);
    apagar_empresa_teste($emp2);
}
igual((int) v("SELECT COUNT(*) FROM lead_atribuicoes WHERE empresa_id IN ($emp, $emp2)") + (int) v("SELECT COUNT(*) FROM lead_origens WHERE empresa_id IN ($emp, $emp2)"), 0, 'limpeza: nada da empresa de teste ficou');
resumo_e_sair();
