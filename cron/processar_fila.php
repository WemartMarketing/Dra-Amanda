<?php
/**
 * Rotina da roleta — roda só pela linha de comando (cron do cPanel):
 *   *\/15 * * * * /usr/local/bin/php /home/USUARIO/caminho/cron/processar_fila.php >/dev/null 2>&1
 * 1. fecha presenças vencidas, fora do horário ou de roletas pausadas;
 * 2. tenta distribuir os leads da fila de espera (mais antigos primeiro).
 * GET_LOCK impede duas execuções ao mesmo tempo.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Acesso negado: esta rotina só roda pelo cron do servidor.\n");
}

require_once __DIR__ . '/../includes/roleta.php';

$pdo = db();
if ((int) $pdo->query("SELECT GET_LOCK('wemart_fila', 0)")->fetchColumn() !== 1) {
    fwrite(STDOUT, "Outra execução em andamento — nada a fazer.\n");
    exit(0);
}
try {
    $fechadas = roleta_fechar_expiradas();
    $r = roleta_processar_fila(null, null, 500);
    fwrite(STDOUT, sprintf("[%s] presenças encerradas: %d · fila: %d tentado(s), %d distribuído(s)\n",
        date('Y-m-d H:i:s'), $fechadas, $r['tentados'], $r['distribuidos']));
} finally {
    $pdo->query("SELECT RELEASE_LOCK('wemart_fila')");
}
