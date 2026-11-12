<?php
/**
 * Mantido por compatibilidade com crons já cadastrados (Etapa 4): agora
 * delega para cron/rotinas.php, que faz a fila da roleta e também as
 * rotinas da Etapa 5 (Meta, recuperação, limpeza). Use só UMA das duas
 * linhas no cron — a trava impede execução dupla de qualquer forma.
 */
require_once __DIR__ . '/../includes/terminal.php';
terminal_exigir();
require __DIR__ . '/rotinas.php';
