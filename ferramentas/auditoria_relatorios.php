<?php
/**
 * Auditoria dos dados para a Central de Relatórios — SÓ LEITURA.
 *
 *   php -q ferramentas/auditoria_relatorios.php                (todas as empresas ativas)
 *   php -q ferramentas/auditoria_relatorios.php --empresa=3
 *   php -q ferramentas/auditoria_relatorios.php --json > auditoria.json
 *
 * Mostra quanto histórico existe e onde há buracos (origem, atribuições,
 * transições do funil, contratos, visitas, roletas, marketing, WhatsApp).
 * Só contagens: nenhum nome, telefone ou e-mail é impresso. Não altera nada.
 */
require_once __DIR__ . '/../includes/terminal.php';
terminal_exigir();
require_once __DIR__ . '/../includes/relatorios/auditoria_dados.php';

$opts = getopt('', ['empresa::', 'json']);
$pdo = db();
if (!empty($opts['empresa'])) {
    $stmt = $pdo->prepare('SELECT id, nome FROM empresas WHERE id = :id');
    $stmt->execute(['id' => (int) $opts['empresa']]);
} else {
    $stmt = $pdo->query('SELECT id, nome FROM empresas WHERE ativa = 1 ORDER BY id');
}
$empresas = $stmt->fetchAll();
if (!$empresas) {
    fwrite(STDERR, "Nenhuma empresa encontrada.\n");
    exit(1);
}
$saida = ['gerado_em' => date('Y-m-d H:i:s'), 'fuso' => date_default_timezone_get(), 'empresas' => []];
foreach ($empresas as $e) {
    $saida['empresas'][] = ['id' => (int) $e['id'], 'nome' => $e['nome'], 'secoes' => relatorio_auditoria_dados((int) $e['id'])];
}
if (isset($opts['json'])) {
    fwrite(STDOUT, json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    exit(0);
}
$marca = ['ok' => '  ok  ', 'info' => '      ', 'alerta' => ' ATENÇÃO ', 'critico' => ' CRÍTICO '];
fwrite(STDOUT, "AUDITORIA DOS DADOS PARA RELATÓRIOS — gerada em " . date('d/m/Y H:i') . ' (' . $saida['fuso'] . ")\n");
foreach ($saida['empresas'] as $e) {
    fwrite(STDOUT, "\n==================== Empresa #{$e['id']}: {$e['nome']}\n");
    foreach ($e['secoes'] as $secao => $itens) {
        fwrite(STDOUT, "\n-- {$secao}\n");
        foreach ($itens as $it) {
            $linha = str_pad($marca[$it['nivel']] ?? '', 9) . $it['rotulo'] . ': ' . (is_int($it['valor']) ? number_format($it['valor'], 0, ',', '.') : $it['valor']);
            if ($it['nota'] !== '') {
                $linha .= '  — ' . $it['nota'];
            }
            fwrite(STDOUT, $linha . "\n");
        }
    }
}
fwrite(STDOUT, "\nNada foi alterado no banco. Envie este resultado para análise (não contém dados pessoais).\n");
