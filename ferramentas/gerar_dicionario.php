<?php
/**
 * Gera docs/RELATORIOS_DICIONARIO.md a partir de includes/relatorios/indicadores.php
 * (a fonte única das definições).   php -q ferramentas/gerar_dicionario.php
 */
require_once __DIR__ . '/../includes/terminal.php';
terminal_exigir();
require_once __DIR__ . '/../includes/relatorios/indicadores.php';

$md = relatorio_dicionario_markdown();
$ind = relatorio_indicadores();
file_put_contents(__DIR__ . '/../docs/RELATORIOS_DICIONARIO.md', $md);
fwrite(STDOUT, count($ind) . " indicadores escritos em docs/RELATORIOS_DICIONARIO.md\n");
