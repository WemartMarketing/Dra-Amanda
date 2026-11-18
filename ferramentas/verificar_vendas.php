<?php
/**
 * Inteligência de Vendas — verificação de integridade. SÓ LEITURA, só contagens.
 *
 *   php -q ferramentas/verificar_vendas.php               (todas as empresas ativas)
 *   php -q ferramentas/verificar_vendas.php --empresa=3
 *
 * Sai com código 2 se houver item crítico (útil em rotina de monitoramento).
 */
require_once __DIR__ . '/../includes/terminal.php';
terminal_exigir();
require_once __DIR__ . '/../includes/vendas/integridade.php';

$empresa = null;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--empresa=(\d+)$/', $a, $m)) {
        $empresa = (int) $m[1];
    }
}
$empresas = $empresa ? db()->query('SELECT id, nome FROM empresas WHERE id = ' . $empresa)->fetchAll() : db()->query('SELECT id, nome FROM empresas WHERE ativa = 1 ORDER BY id')->fetchAll();
$critico = false;
$marca = ['ok' => '  ok   ', 'info' => '       ', 'alerta' => 'ATENÇÃO', 'critico' => 'CRÍTICO'];
fwrite(STDOUT, 'INTELIGÊNCIA DE VENDAS — INTEGRIDADE (' . date('d/m/Y H:i') . ")\n");
foreach ($empresas as $e) {
    fwrite(STDOUT, "\n" . $e['id'] . ' — ' . $e['nome'] . "\n");
    foreach (vendas_integridade((int) $e['id']) as $i) {
        $critico = $critico || $i['nivel'] === 'critico';
        fwrite(STDOUT, '  [' . $marca[$i['nivel']] . '] ' . $i['rotulo'] . ': ' . $i['valor'] . ($i['nota'] ? ' — ' . $i['nota'] : '') . "\n");
    }
}
exit($critico ? 2 : 0);
