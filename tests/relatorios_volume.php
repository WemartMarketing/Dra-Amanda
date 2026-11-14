<?php
/**
 * Teste de VOLUME da Central de Relatórios (dados fictícios, empresa de teste
 * apagada no final). Não faz parte da bateria rápida: leva alguns minutos.
 *
 *   php -q tests/relatorios_volume.php            (120 mil leads)
 *   php -q tests/relatorios_volume.php 30000
 *
 * Mede cada relatório como Master (empresa toda, 1 ano) e falha se algum
 * passar de 10 s ou se a memória passar de 128 MB.
 */
require __DIR__ . '/lib.php';
require_once __DIR__ . '/../includes/funcoes.php'; require_once __DIR__ . '/../includes/funil.php'; require_once __DIR__ . '/../includes/origens.php';
require_once __DIR__ . '/../includes/relatorios/fila_exportacao.php'; require_once __DIR__ . '/../includes/investimentos.php';
$N = (int) ($argv[1] ?? 120000);
$pdo = db();
$emp = criar_empresa_teste('Volume');
try {
$f = funcoes_seed_padrao($emp); funil_seed_padrao($emp); origens_listar($emp);
$pdo->exec("INSERT INTO usuarios (empresa_id, funcao_id, nome, email, senha_hash, cargo) VALUES ($emp, {$f['master_empresa']}, 'Master Vol', 'vol" . bin2hex(random_bytes(4)) . "@t.invalid', 'x', 'master_empresa')");
$master = (int) $pdo->lastInsertId();
$eq = []; $us = []; $sup = [];
for ($s = 1; $s <= 3; $s++) { $pdo->exec("INSERT INTO superintendencias (empresa_id, nome) VALUES ($emp, 'S$s')"); $sup[] = (int) $pdo->lastInsertId(); }
for ($e = 1; $e <= 12; $e++) { $pdo->exec("INSERT INTO equipes (empresa_id, nome, superintendencia_id) VALUES ($emp, 'Eq$e', " . $sup[$e % 3] . ")"); $eq[] = (int) $pdo->lastInsertId(); }
for ($c = 1; $c <= 120; $c++) { $pdo->exec("INSERT INTO usuarios (empresa_id, funcao_id, nome, email, senha_hash, cargo, equipe_id) VALUES ($emp, {$f['corretor']}, 'Corretor $c', 'v$c" . bin2hex(random_bytes(3)) . "@t.invalid', 'x', 'corretor', " . $eq[$c % 12] . ")"); $us[] = (int) $pdo->lastInsertId(); }
for ($m = 1; $m <= 8; $m++) { $pdo->exec("INSERT INTO empreendimentos (empresa_id, nome) VALUES ($emp, 'Emp$m')"); }
$emps = $pdo->query("SELECT id FROM empreendimentos WHERE empresa_id = $emp")->fetchAll(PDO::FETCH_COLUMN);
$ets = $pdo->query("SELECT id, categoria FROM funil_etapas WHERE empresa_id = $emp")->fetchAll();
$origs = $pdo->query("SELECT id FROM lead_origens WHERE empresa_id = $emp")->fetchAll(PDO::FETCH_COLUMN);
$t0 = microtime(true);
$pdo->beginTransaction();
$ins = []; $lote = 0;
mt_srand(42);
for ($i = 0; $i < $N; $i++) {
    $dia = date('Y-m-d H:i:s', strtotime('2025-11-14') + mt_rand(0, 365 * 86400));
    $u = $us[mt_rand(0, 119)]; $e = $ets[mt_rand(0, count($ets) - 1)];
    $ins[] = sprintf("(%d,%d,'Lead %d','119%08d','manual',%d,%d,'%s','%s','%s','%s',%d,%d)", $emp, $emps[mt_rand(0, 7)], $i, $i, $origs[mt_rand(0, count($origs) - 1)], $e['id'], $e['categoria'], $dia, $dia, $dia, $u, $eq[array_search($u, $us) % 12]);
    if (count($ins) === 2000 || $i === $N - 1) {
        $pdo->exec('INSERT INTO leads (empresa_id, empreendimento_id, nome, telefone, origem, origem_detalhe_id, etapa_id, etapa, criado_em, ultima_atividade_em, atribuido_em, responsavel_id, equipe_id) VALUES ' . implode(',', $ins));
        $ins = [];
    }
}
$pdo->commit();
// Atribuições (uma por lead + 15% transferidos), histórico de etapa (2 por lead), visitas (20%), contratos (2%).
$pdo->exec("INSERT INTO lead_atribuicoes (empresa_id, lead_id, usuario_id, equipe_id, superintendencia_id, empreendimento_id, forma, roleta_id, atribuido_em, primeiro_atendimento_em, primeiro_contato_efetivo_em, contato_efetivo_via)
  SELECT l.empresa_id, l.id, l.responsavel_id, l.equipe_id, q.superintendencia_id, l.empreendimento_id, 'roleta', NULL, l.criado_em,
         IF(l.id % 3 <> 0, DATE_ADD(l.criado_em, INTERVAL (l.id % 600) MINUTE), NULL), IF(l.id % 4 = 0, DATE_ADD(l.criado_em, INTERVAL 2 HOUR), NULL), IF(l.id % 4 = 0, 'etapa', NULL)
  FROM leads l JOIN equipes q ON q.id = l.equipe_id WHERE l.empresa_id = $emp");
$pdo->exec("UPDATE leads SET primeiro_contato_efetivo_em = IF(id % 4 = 0, DATE_ADD(criado_em, INTERVAL 2 HOUR), NULL) WHERE empresa_id = $emp");
$pdo->exec("UPDATE lead_atribuicoes SET encerrada_em = DATE_ADD(atribuido_em, INTERVAL 5 DAY), motivo_encerramento = 'inatividade' WHERE empresa_id = $emp AND lead_id % 7 = 0");
$pdo->exec("INSERT INTO lead_atribuicoes (empresa_id, lead_id, usuario_id, equipe_id, empreendimento_id, forma, atribuido_em)
  SELECT a.empresa_id, a.lead_id, a.usuario_id, a.equipe_id, a.empreendimento_id, 'redistribuicao', DATE_ADD(a.encerrada_em, INTERVAL 3 HOUR) FROM lead_atribuicoes a WHERE a.empresa_id = $emp AND a.motivo_encerramento = 'inatividade'");
$e1 = $ets[0]['id']; $e2 = $ets[1]['id'];
foreach ([1, 2] as $k) {
    $pdo->exec("INSERT INTO lead_etapa_historico (empresa_id, lead_id, de_etapa_id, para_etapa_id, de_categoria, para_categoria, usuario_id, origem, ocorrido_em)
      SELECT empresa_id, id, $e1, $e2, 'novo_lead', IF($k = 1, 'primeiro_contato', 'em_atendimento'), responsavel_id, 'usuario', DATE_ADD(criado_em, INTERVAL $k DAY) FROM leads WHERE empresa_id = $emp");
}
$pdo->exec("INSERT INTO agenda_compromissos (empresa_id, lead_id, usuario_id, tipo, titulo, inicio, status, criado_por, criado_em, concluido_em)
  SELECT empresa_id, id, responsavel_id, 'visita', 'V', DATE_ADD(criado_em, INTERVAL 3 DAY), IF(id % 2 = 0, 'realizado', 'cliente_ausente'), responsavel_id, DATE_ADD(criado_em, INTERVAL 1 DAY), DATE_ADD(criado_em, INTERVAL 3 DAY) FROM leads WHERE empresa_id = $emp AND id % 5 = 0");
$pdo->exec("INSERT INTO lead_contratos (empresa_id, lead_id, empreendimento_id, usuario_id, equipe_id, assinado_em, valor_vgv, status)
  SELECT empresa_id, id, empreendimento_id, responsavel_id, equipe_id, DATE(DATE_ADD(criado_em, INTERVAL 10 DAY)), 400000, 'validado' FROM leads WHERE empresa_id = $emp AND id % 50 = 0");
grupo("Volume: $N leads gerados em " . round(microtime(true) - $t0, 1) . ' s');
$u = relexp_usuario($master, $emp);
$G = ['periodo' => 'personalizado', 'ini' => '2025-11-14', 'fim' => '2026-11-14'];
$f = relatorio_filtros($u, $G);
$m0 = memory_get_peak_usage(true);
foreach ([
    'Visão executiva' => fn () => relatorio_executivo($u, $f),
    'Evolução diária' => fn () => relatorio_serie($u, $f, 'leads_recebidos', 'dia'),
    'Gestão por corretor' => fn () => relatorio_agrupado($u, $f, 'corretor'),
    'Funil em detalhe' => fn () => relatorio_funil_detalhe($u, $f),
    'Origens' => fn () => relatorio_origens($u, $f, 'origem'),
    'Visitas por corretor' => fn () => relatorio_visitas_por($u, $f, 'corretor'),
    'Perdas por equipe' => fn () => relatorio_perdas_por($u, $f, 'equipe'),
] as $nome => $fn) {
    $a = microtime(true);
    $fn();
    $s = microtime(true) - $a;
    ok($s < 10, sprintf('%s: %.2f s', $nome, $s));
}
$soma = array_sum(array_column(relatorio_agrupado($u, $f, 'corretor'), 'recebimentos'));
igual($soma, (int) $pdo->query("SELECT COUNT(*) FROM lead_atribuicoes WHERE empresa_id = $emp AND forma <> 'mudanca_equipe' AND atribuido_em BETWEEN '2025-11-14' AND '2026-11-14 23:59:59'")->fetchColumn(), 'conferência: soma por corretor = atribuições do período');
ok(memory_get_peak_usage(true) < 128 * 1048576, sprintf('memória máxima %.0f MB (< 128 MB)', memory_get_peak_usage(true) / 1048576));
} finally {
    $pdo->exec("DELETE FROM lead_etapa_historico WHERE empresa_id = $emp");
    $pdo->exec("DELETE FROM agenda_compromissos WHERE empresa_id = $emp");
    apagar_empresa_teste($emp);
}
resumo_e_sair();
