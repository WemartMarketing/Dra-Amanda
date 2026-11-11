<?php
/**
 * Mantido por compatibilidade com crons já cadastrados (Etapa 4): agora
 * delega para cron/rotinas.php, que faz a fila da roleta e também as
 * rotinas da Etapa 5 (Meta, recuperação, limpeza). Use só UMA das duas
 * linhas no cron — a trava impede execução dupla de qualquer forma.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Acesso negado: esta rotina só roda pelo cron do servidor.\n");
}
require __DIR__ . '/rotinas.php';
