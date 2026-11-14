<?php
/**
 * Central de Relatórios — Etapa 4: relatórios especializados (funil em
 * detalhe, plantões e roletas, origens/UTM, visitas e vendas, perdas e
 * redistribuições). Cenário montado com datas fixas e números conferidos à mão.
 *
 *   php -q tests/relatorios_etapa4_test.php
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../includes/funcoes.php';
require_once __DIR__ . '/../includes/funil.php';
require_once __DIR__ . '/../includes/relatorios/especializados.php';
require_once __DIR__ . '/../includes/relatorios/ui.php';

function v(string $sql)
{
    return db()->query($sql)->fetchColumn();
}

$emp = criar_empresa_teste('Relatorios E4');
try {
    $pdo = db();
    $f = funcoes_seed_padrao($emp);
    funil_seed_padrao($emp);
    $et = fn (string $cat) => funil_etapa_por_categoria($emp, $cat) ?: db()->query("SELECT * FROM funil_etapas WHERE empresa_id = $emp AND categoria = '$cat' LIMIT 1")->fetch();
    $uid = function (string $nome, string $cargo) use ($pdo, $emp, $f) {
        $pdo->prepare('INSERT INTO usuarios (empresa_id, funcao_id, nome, email, senha_hash, cargo) VALUES (:e, :f, :n, :m, :h, :c)')
            ->execute(['e' => $emp, 'f' => $f[$cargo], 'n' => $nome, 'm' => bin2hex(random_bytes(5)) . '@teste.invalid', 'h' => 'x', 'c' => $cargo]);
        return (int) $pdo->lastInsertId();
    };
    $u = fn (int $id, string $esc) => ['id' => $id, 'nome' => 'x', 'empresa_id' => $emp, 'empresa_ativa_id' => $emp, 'eh_superadmin' => false, 'escopo' => $esc,
        'permissoes' => ['visualizar_indicadores']];
    $master = $uid('Master', 'master_empresa');
    $gA = $uid('Gerente A', 'gerente');
    $gB = $uid('Gerente B', 'gerente');
    $pdo->prepare("INSERT INTO equipes (empresa_id, nome, gerente_id) VALUES (:e, 'Equipe A', :g)")->execute(['e' => $emp, 'g' => $gA]);
    $eqA = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO equipes (empresa_id, nome, gerente_id) VALUES (:e, 'Equipe B', :g)")->execute(['e' => $emp, 'g' => $gB]);
    $eqB = (int) $pdo->lastInsertId();
    $c1 = $uid('Corretor Um', 'corretor');
    $c3 = $uid('Corretor Tres', 'corretor');
    $pdo->exec("UPDATE usuarios SET equipe_id = $eqA WHERE id = $c1");
    $pdo->exec("UPDATE usuarios SET equipe_id = $eqB WHERE id = $c3");
    $M = $u($master, 'toda_empresa');
    $GA = $u($gA, 'equipe');
    $pdo->prepare("INSERT INTO empreendimentos (empresa_id, nome) VALUES (:e, 'Emp')")->execute(['e' => $emp]);
    $empr = (int) $pdo->lastInsertId();
    $plantaoId = null;
    if (modulo_tabela_existe('plantoes')) {
        $pdo->prepare("INSERT INTO plantoes (empresa_id, nome) VALUES (:e, 'Plantão Centro')")->execute(['e' => $emp]);
        $plantaoId = (int) $pdo->lastInsertId();
    }
    $pdo->prepare("INSERT INTO roletas (empresa_id, nome, plantao_id, ativa) VALUES (:e, 'Roleta Um', :p, 1), (:e2, 'Roleta Dois', NULL, 1)")->execute(['e' => $emp, 'p' => $plantaoId, 'e2' => $emp]);
    $r1 = (int) $pdo->lastInsertId();
    $r2 = $r1 + 1;
    $lead = function (string $cat, string $quando, ?int $dono = null, array $extra = []) use ($pdo, $emp, $empr, $et) {
        $e = $et($cat);
        $cols = array_merge(['empresa_id' => $emp, 'empreendimento_id' => $empr, 'nome' => 'L' . bin2hex(random_bytes(2)), 'origem' => 'manual', 'etapa_id' => $e['id'],
            'etapa' => $cat, 'criado_em' => $quando, 'ultima_atividade_em' => $quando, 'responsavel_id' => $dono, 'equipe_id' => $dono ? v("SELECT equipe_id FROM usuarios WHERE id = $dono") : null], $extra);
        $pdo->prepare('INSERT INTO leads (' . implode(',', array_keys($cols)) . ') VALUES (:' . implode(',:', array_keys($cols)) . ')')->execute($cols);
        return (int) $pdo->lastInsertId();
    };
    $atr = function (int $l, int $user, string $forma, string $em, array $x = []) use ($pdo, $emp, $empr) {
        $eq = (int) v("SELECT equipe_id FROM usuarios WHERE id = $user");
        $ger = (int) v("SELECT gerente_id FROM equipes WHERE id = $eq");
        $cols = array_merge(['empresa_id' => $emp, 'lead_id' => $l, 'usuario_id' => $user, 'equipe_id' => $eq, 'gerente_id' => $ger, 'empreendimento_id' => $empr,
            'forma' => $forma, 'atribuido_em' => $em], $x);
        $pdo->prepare('INSERT INTO lead_atribuicoes (' . implode(',', array_keys($cols)) . ') VALUES (:' . implode(',:', array_keys($cols)) . ')')->execute($cols);
        return (int) $pdo->lastInsertId();
    };
    $hist = function (int $l, ?string $de, string $para, string $origem, string $em, ?int $user = null) use ($pdo, $emp, $et) {
        $pdo->prepare('INSERT INTO lead_etapa_historico (empresa_id, lead_id, de_etapa_id, para_etapa_id, de_categoria, para_categoria, usuario_id, origem, ocorrido_em)
                       VALUES (:e, :l, :de, :para, :dc, :pc, :u, :o, :em)')
            ->execute(['e' => $emp, 'l' => $l, 'de' => $de ? $et($de)['id'] : null, 'para' => $et($para)['id'], 'dc' => $de, 'pc' => $para, 'u' => $user, 'o' => $origem, 'em' => $em]);
    };
    $F = relatorio_filtros($M, ['periodo' => 'personalizado', 'ini' => '2026-03-01', 'fim' => '2026-03-31']);

    // ------------------------------------------------------------- Funil
    $L1 = $lead('novo_lead', '2026-03-02 09:00:00', $c1);
    $L2 = $lead('visita_realizada', '2026-03-03 09:00:00', $c1);
    $L3 = $lead('perdido', '2026-03-04 09:00:00', null);
    $L4 = $lead('contrato_assinado', '2026-03-05 09:00:00', $c3);
    $lead('novo_lead', '2026-02-20 09:00:00', $c1); // fora do período
    $hist($L1, 'novo_lead', 'primeiro_contato', 'usuario', '2026-03-02 10:00:00', $c1);
    $hist($L1, 'primeiro_contato', 'novo_lead', 'usuario', '2026-03-02 16:00:00', $c1);
    $hist($L4, 'novo_lead', 'em_atendimento', 'jornada', '2026-03-06 09:00:00');
    grupo('Funil em detalhe');
    $d = relatorio_funil_detalhe($M, $F);
    igual($d['coorte'], 4, 'coorte: 4 leads entraram em março');
    igual(array_column($d['alcance'], 'total'), [4, 3, 2, 2, 2, 1], 'alcance: 4 → 3 → 2 → 2 → 2 → 1 (quem voltou de etapa ainda conta onde chegou; "perdido" só na entrada)');
    igual($d['alcance'][1]['pct_coorte'], 75.0, 'primeiro contato: 75% do total');
    igual($d['alcance'][5]['pct_anterior'], 50.0, 'contrato: 50% da fase anterior');
    ok($d['avancos'] === 2 && $d['retrocessos'] === 1 && $d['por_origem']['jornada'] === 1 && $d['por_origem']['usuario'] === 2, '2 avanços, 1 retrocesso; 1 por jornada, 2 por pessoas');
    $mz = array_column($d['matriz'], null, 'para');
    ok($mz['primeiro_contato']['usuario'] === 1 && $mz['em_atendimento']['jornada'] === 1, 'matriz de → para separa pessoa e jornada');
    igual($d['tempo_etapa_horas']['primeiro_contato'] ?? null, ['mediana' => 6.0, 'permanencias' => 1], 'ficou 6 h em "primeiro contato" (10h → 16h)');
    igual(array_sum(array_column($d['posicao'], 'total')), 4, 'onde estão hoje: soma = coorte');
    igual(relatorio_funil_detalhe($M, relatorio_filtros($M, ['periodo' => 'personalizado', 'ini' => '2026-03-01', 'fim' => '2026-03-31', 'etapa' => [999999]]))['coorte'], 0, 'filtro inválido → vazio');
    igual(relatorio_funil_detalhe($GA, $F)['coorte'], 2, 'gerente A: só os leads da equipe A hoje (L1, L2)');

    // ------------------------------------------------------------- Plantões
    grupo('Plantões e roletas');
    $a = $atr($L1, $c1, 'roleta', '2026-03-02 09:00:01', ['roleta_id' => $r1]);
    $atr($L2, $c1, 'roleta', '2026-03-03 09:00:01', ['roleta_id' => $r1]);
    $atr($L4, $c3, 'roleta', '2026-03-05 09:00:01', ['roleta_id' => $r1]);
    $atr($L2, $c1, 'redistribuicao', '2026-03-20 09:00:00', ['roleta_id' => $r1]);
    $atr($L1, $c1, 'roleta', '2026-02-20 09:00:01', ['roleta_id' => $r1]); // fora do período
    $pdo->exec("UPDATE leads SET chegada_roleta_id = $r1, chegada_roleta_aberta = 1 WHERE id IN ($L1, $L2)");
    $pdo->exec("UPDATE leads SET chegada_roleta_id = $r1, chegada_roleta_aberta = 0 WHERE id = $L4");
    $pdo->exec("INSERT INTO roleta_fila_historico (empresa_id, lead_id, roleta_id, origem, entrou_em, saiu_em, resultado) VALUES
        ($emp, $L1, $r1, 'manual', '2026-03-02 08:50:00', '2026-03-02 09:00:00', 'distribuido'),
        ($emp, $L2, $r1, 'manual', '2026-03-03 08:30:00', '2026-03-03 09:00:00', 'distribuido'),
        ($emp, $L3, $r1, 'manual', '2026-03-04 09:00:00', NULL, NULL),
        ($emp, $L4, $r1, 'manual', '2026-02-27 09:00:00', '2026-02-27 12:00:00', 'distribuido')");
    $pdo->exec("INSERT INTO roleta_sessoes (empresa_id, roleta_id, usuario_id, entrou_em, ultima_verificacao_em, saiu_em) VALUES
        ($emp, $r1, $c1, '2026-03-10 08:00:00', '2026-03-10 11:30:00', '2026-03-10 12:00:00'),
        ($emp, $r1, $c3, '2026-03-31 22:00:00', '2026-04-01 01:00:00', '2026-04-01 02:00:00'),
        ($emp, $r2, $c1, '2026-02-10 08:00:00', '2026-02-10 09:00:00', '2026-02-10 10:00:00')");
    $pl = relatorio_plantoes($M, $F);
    $R = array_column($pl['roletas'], null, 'id');
    ok($R[$r1]['entregas'] === 3 && $R[$r1]['redistribuicoes'] === 1, 'Roleta Um: 3 entregas + 1 redistribuição (fevereiro fora)');
    ok($R[$r1]['chegadas'] === 3 && $R[$r1]['fora_horario'] === 1, '3 chegadas, 1 fora do horário');
    ok($R[$r1]['fila_passagens'] === 3 && $R[$r1]['fila_aguardando'] === 1 && $R[$r1]['espera_mediana_min'] === 20.0, 'fila: 3 passagens, 1 aguardando, espera mediana 20 min (10 e 30)');
    ok($R[$r1]['horas_presenca'] === 6.0 && $R[$r1]['corretores_presentes'] === 2, 'presença recortada ao período: 4 h + 2 h (sessão que passa da meia-noite do dia 31)');
    ok($R[$r2]['horas_presenca'] === 0.0, 'presença de fevereiro não entra');
    if ($plantaoId) {
        $Fp = relatorio_filtros($M, ['periodo' => 'personalizado', 'ini' => '2026-03-01', 'fim' => '2026-03-31', 'plantao' => [$plantaoId]]);
        igual(array_column(relatorio_plantoes($M, $Fp)['roletas'], 'id'), [$r1], 'filtro de plantão: só a roleta dele');
    }
    $plA = relatorio_plantoes($GA, $F);
    $RA = array_column($plA['roletas'], null, 'id');
    ok($RA[$r1]['entregas'] === 2 && $RA[$r1]['corretores_presentes'] === 1 && count($plA['presenca']) === 1, 'gerente A: entregas e presença só da equipe A');

    // ------------------------------------------------------------- Origens
    grupo('Origens e UTMs');
    $pdo->exec("UPDATE leads SET utm_source = 'google', utm_campaign = 'lanc' WHERE id IN ($L1, $L2)");
    $pdo->exec("UPDATE leads SET utm_source = 'facebook' WHERE id = $L4");
    $pdo->exec("UPDATE leads SET primeiro_contato_efetivo_em = '2026-03-03 10:00:00' WHERE id = $L2");
    $pdo->prepare("INSERT INTO agenda_compromissos (empresa_id, lead_id, usuario_id, tipo, titulo, inicio, status, criado_por, criado_em, concluido_em, confirmada_em)
                   VALUES (:e, :l, :u, 'visita', 'V', '2026-03-10 10:00:00', 'realizado', :u2, '2026-03-08 10:00:00', '2026-03-10 11:00:00', '2026-03-09 10:00:00')")
        ->execute(['e' => $emp, 'l' => $L2, 'u' => $c1, 'u2' => $c1]);
    $pdo->prepare("INSERT INTO lead_contratos (empresa_id, lead_id, empreendimento_id, usuario_id, equipe_id, assinado_em, status) VALUES (:e, :l, :emp, :u, :eq, '2026-03-15', 'validado')")
        ->execute(['e' => $emp, 'l' => $L2, 'emp' => $empr, 'u' => $c1, 'eq' => $eqA]);
    $pdo->prepare("INSERT INTO lead_contratos (empresa_id, lead_id, empreendimento_id, usuario_id, equipe_id, assinado_em, status) VALUES (:e, :l, :emp, :u, :eq, '2026-03-20', 'registrado')")
        ->execute(['e' => $emp, 'l' => $L4, 'emp' => $empr, 'u' => $c3, 'eq' => $eqB]); // ainda não validado: não conta
    $o = array_column(relatorio_origens($M, $F, 'utm_source'), null, 'nome');
    ok($o['google']['leads'] === 2 && $o['google']['contato_efetivo'] === 1 && $o['google']['com_visita'] === 1 && $o['google']['com_contrato'] === 1, 'google: 2 leads, 1 contato, 1 visita, 1 contrato');
    igual($o['google']['taxa_contrato'], 50.0, 'google: 50% com contrato');
    ok($o['facebook']['leads'] === 1 && $o['facebook']['com_contrato'] === 0 && $o['(sem UTM)']['leads'] === 1, 'facebook 1 (contrato só registrado não conta); sem UTM 1');
    ok($o['google']['custo_por_lead'] === null, 'custo por lead: não disponível (sem investimento registrado)');
    igual(array_sum(array_column(relatorio_origens($M, $F, 'canal'), 'leads')), 4, 'por grupo: soma = coorte');
    igual(relatorio_origens($M, $F, 'cpf'), [], 'agrupamento inexistente → vazio');
    igual(array_sum(array_column(relatorio_origens($GA, $F, 'utm_source'), 'leads')), 2, 'gerente A: só os leads da equipe A');

    // ------------------------------------------------------------- Visitas
    grupo('Visitas e vendas');
    $pdo->prepare("INSERT INTO agenda_compromissos (empresa_id, lead_id, usuario_id, tipo, titulo, inicio, status, criado_por, criado_em, concluido_em) VALUES
        (:e, :l, :u, 'visita', 'V', '2026-03-12 10:00:00', 'cliente_ausente', :u2, '2026-03-11 10:00:00', '2026-03-12 11:00:00'),
        (:e2, :l2, :u3, 'visita', 'V', '2026-03-13 10:00:00', 'cancelado', :u4, '2026-03-11 10:00:00', '2026-03-12 09:00:00'),
        (:e3, :l3, :u5, 'visita', 'V', '2026-04-02 10:00:00', 'agendado', :u6, '2026-02-25 10:00:00', NULL)")
        ->execute(['e' => $emp, 'l' => $L1, 'u' => $c1, 'u2' => $c1, 'e2' => $emp, 'l2' => $L4, 'u3' => $c3, 'u4' => $c3, 'e3' => $emp, 'l3' => $L1, 'u5' => $c1, 'u6' => $c1]);
    $vc = array_column(relatorio_visitas_por($M, $F, 'corretor'), null, 'id');
    ok($vc[$c1]['agendadas'] === 2 && $vc[$c1]['confirmadas'] === 1 && $vc[$c1]['realizadas'] === 1 && $vc[$c1]['ausentes'] === 1, 'c1: 2 agendadas em março (a de fevereiro não), 1 confirmada, 1 realizada, 1 ausente');
    ok($vc[$c1]['comparecimento'] === 50.0 && $vc[$c1]['viraram_contrato'] === 1 && $vc[$c1]['taxa_contrato'] === 100.0, 'c1: comparecimento 50%, visita → contrato 100%');
    ok($vc[$c3]['canceladas'] === 1 && $vc[$c3]['comparecimento'] === null, 'c3: 1 cancelada; comparecimento sem base → "—"');
    igual(array_sum(array_column(relatorio_visitas_por($M, $F, 'empreendimento'), 'agendadas')), 3, 'por empreendimento: mesmo total (3)');
    igual(array_keys(array_column(relatorio_visitas_por($GA, $F, 'corretor'), null, 'id')), [$c1], 'gerente A: só as visitas da equipe A');
    igual(array_column(relatorio_visitas_por($M, relatorio_filtros($M, ['periodo' => 'personalizado', 'ini' => '2026-03-01', 'fim' => '2026-03-31', 'corretor' => [$c3]]), 'corretor'), 'id'), [$c3], 'filtro de corretor');

    // ------------------------------------------------------------- Perdas
    grupo('Perdas e redistribuições');
    $L5 = $lead('novo_lead', '2026-03-01 09:00:00', $c3);
    $L6 = $lead('novo_lead', '2026-03-01 09:00:00', null);
    $L7 = $lead('novo_lead', '2026-03-01 09:00:00', $c1);
    $atr($L5, $c1, 'roleta', '2026-03-01 09:00:00', ['encerrada_em' => '2026-03-05 10:00:00', 'motivo_encerramento' => 'inatividade']);
    $atr($L5, $c3, 'redistribuicao', '2026-03-05 12:00:00', ['primeiro_contato_efetivo_em' => '2026-03-05 13:00:00']);
    $atr($L6, $c3, 'cadastro', '2026-02-20 09:00:00', ['encerrada_em' => '2026-02-28 10:00:00', 'motivo_encerramento' => 'transferencia']); // antes da perda: não é "redistribuição"
    $atr($L6, $c1, 'roleta', '2026-03-01 09:00:00', ['encerrada_em' => '2026-03-07 10:00:00', 'motivo_encerramento' => 'inatividade']);
    $atr($L7, $c3, 'cadastro', '2026-03-01 09:00:00', ['encerrada_em' => '2026-03-08 10:00:00', 'motivo_encerramento' => 'transferencia']);
    $atr($L7, $c1, 'transferencia', '2026-03-08 10:00:00');
    $pp = array_column(relatorio_perdas_por($M, $F, 'equipe'), null, 'nome');
    ok($pp['Equipe A']['inatividade'] === 2 && $pp['Equipe A']['redistribuidos'] === 1 && $pp['Equipe A']['tempo_redistribuicao_h'] === 2.0, 'Equipe A: 2 perdidos, 1 redistribuído em 2 h');
    ok($pp['Equipe A']['contato_depois'] === 1 && $pp['Equipe A']['taxa_contato_depois'] === 100.0, 'o novo corretor teve contato efetivo (1 de 1)');
    ok($pp['Equipe B']['transferidos'] === 1 && $pp['Equipe B']['inatividade'] === 0, 'Equipe B: 1 transferido');
    $dest = array_column(relatorio_perdas_destino($M, $F, 'equipe'), 'total', 'rotulo');
    ksort($dest);
    igual($dest, ['Ainda sem corretor' => 1, 'Equipe B' => 1], 'destino: 1 para a Equipe B, 1 ainda sem corretor');
    $pe = relatorio_perdas($M, $F);
    igual($pe['perdidos_inatividade']['valor'], array_sum(array_column(relatorio_perdas_por($M, $F, 'corretor'), 'inatividade')), 'total do cartão = soma da tabela');
    igual(array_keys(array_column(relatorio_perdas_por($GB = $u($gB, 'equipe'), $F, 'equipe'), null, 'nome')), ['Equipe B'], 'gerente B: só o que a equipe B perdeu');
    $pdo->exec("UPDATE leads SET excluido_em = NOW() WHERE id = $L6");
    igual(array_column(relatorio_perdas_por($M, $F, 'equipe'), null, 'nome')['Equipe A']['inatividade'], 1, 'lead excluído (LGPD) sai da contagem');

    grupo('Telas: catálogo e permissão');
    foreach (['funil', 'plantoes', 'origens', 'visitas', 'perdas'] as $r) {
        ok(relui_pode($M, $r) && !relui_pode(array_merge($u($c1, 'propria_carteira'), ['permissoes' => []]), $r), "\"$r\": gestor abre; corretor não");
        ok(function_exists('relui_tela_' . $r) || (require_once __DIR__ . '/../includes/relatorios/telas_especializadas.php') && function_exists('relui_tela_' . $r), "\"$r\": tela existe");
    }
} finally {
    apagar_empresa_teste($emp);
}
resumo_e_sair();
