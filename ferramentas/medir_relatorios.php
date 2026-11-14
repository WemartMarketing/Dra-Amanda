<?php
/**
 * Mede o tempo dos relatórios da Central no banco REAL — só leitura.
 *
 *   php -q ferramentas/medir_relatorios.php                 (empresa 1, últimos 12 meses)
 *   php -q ferramentas/medir_relatorios.php --empresa=3
 *
 * Usa um usuário Master ativo da empresa (a visão mais pesada: empresa
 * inteira). Não imprime nenhum dado de cliente, só tempos e contagens.
 * Referência (120 mil leads/ano, servidor de testes): todas as telas abaixo
 * de 3 s e até 60 MB. Se aqui passar de ~8 s, é hora das agregações diárias
 * (ver docs/RELATORIOS_ETAPA6.md).
 */
require_once __DIR__ . '/../includes/terminal.php';
terminal_exigir();
require_once __DIR__ . '/../includes/relatorios/fila_exportacao.php';
require_once __DIR__ . '/../includes/investimentos.php';

$opts = getopt('', ['empresa::']);
$emp = (int) ($opts['empresa'] ?? 1);
$mid = (int) db()->query("SELECT u.id FROM usuarios u JOIN funcoes f ON f.id = u.funcao_id WHERE u.empresa_id = $emp AND u.ativo = 1 AND f.escopo = 'toda_empresa' ORDER BY u.id LIMIT 1")->fetchColumn();
$u = $mid ? relexp_usuario($mid, $emp) : null;
if (!$u) {
    fwrite(STDERR, "Nenhum usuário com visão da empresa toda na empresa $emp.\n");
    exit(1);
}
$G = ['periodo' => 'personalizado', 'ini' => date('Y-m-d', strtotime('-1 year')), 'fim' => date('Y-m-d')];
$f = relatorio_filtros($u, $G);
echo 'Empresa ' . $emp . ' · ' . $f['periodo']['rotulo'] . ' · leads no período: ' . relexp_dados_contar($u, $G) . "\n";
$t = function (string $nome, callable $fn) {
    $a = microtime(true);
    $fn();
    printf("  %s %6.2f s\n", $nome . str_repeat(" ", max(1, 34 - mb_strlen($nome))), microtime(true) - $a);
};
$t('Visão executiva', fn () => relatorio_executivo($u, $f));
$t('Evolução diária', fn () => relatorio_serie($u, $f, 'leads_recebidos', 'dia'));
$t('Gestão por corretor', fn () => relatorio_agrupado($u, $f, 'corretor'));
$t('Funil em detalhe', fn () => relatorio_funil_detalhe($u, $f));
$t('Plantões e roletas', fn () => relatorio_plantoes($u, $f));
$t('Origens', fn () => relatorio_origens($u, $f, 'origem'));
$t('Visitas por corretor', fn () => relatorio_visitas_por($u, $f, 'corretor'));
$t('Perdas por equipe', fn () => relatorio_perdas_por($u, $f, 'equipe'));
$t('Custos', fn () => relatorio_custos($u, $f));
printf("  Memória máxima: %.0f MB (limite do PHP: %s)\n", memory_get_peak_usage(true) / 1048576, ini_get('memory_limit'));
echo "Nada foi alterado no banco.\n";
