<?php
/**
 * Retroalimenta o histórico estruturado (migração 013) com as fontes já
 * registradas no banco. Tudo o que for criado fica marcado como
 * "reconstruído". Pode ser executado mais de uma vez (não duplica).
 *
 *   php -q ferramentas/retroalimentar_historico.php --simular     (só mostra quantos seriam criados)
 *   php -q ferramentas/retroalimentar_historico.php
 *   php -q ferramentas/retroalimentar_historico.php --empresa=3
 *
 * Não apaga nem altera dados existentes (só preenche o histórico vazio e o
 * 1º contato efetivo do lead quando houver registro anterior).
 */
require_once __DIR__ . '/../includes/terminal.php';
terminal_exigir();
require_once __DIR__ . '/../includes/relatorios/retroalimentacao.php';

$opts = getopt('', ['empresa::', 'simular']);
$empresa = !empty($opts['empresa']) ? (int) $opts['empresa'] : null;
$simular = isset($opts['simular']);
try {
    $r = retroalimentar_historico($empresa, $simular);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . " Rode antes: sql/migration_013_relatorios_historico.sql\n");
    exit(1);
}
echo ($simular ? "SIMULAÇÃO (nada foi gravado)\n" : "Histórico retroalimentado\n");
echo "  Leads com histórico reconstruído: {$r['leads']}\n";
echo "  Atribuições reconstruídas:        {$r['atribuicoes']}\n";
echo "  Mudanças de etapa reconstruídas:  {$r['etapas']}\n";
echo "  Passagens pela fila:              {$r['fila']}\n";
echo "  Leads com 1º contato efetivo:     {$r['contato_efetivo_lead']}\n";
