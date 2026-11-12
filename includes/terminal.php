<?php
/**
 * Scripts de terminal (cron/, ferramentas/) — funciona tanto com o PHP de
 * linha de comando (SAPI "cli") quanto com o "php" de muitas hospedagens
 * compartilhadas, como a HostGator, que é o binário CGI ("cgi-fcgi") e não
 * define STDIN/STDOUT/STDERR. Pelo navegador, responde 404 e para.
 */
function terminal_exigir(): void
{
    $cgiNoTerminal = strncmp(PHP_SAPI, 'cgi', 3) === 0 && !isset($_SERVER['REQUEST_METHOD']) && !isset($_SERVER['GATEWAY_INTERFACE']);
    if (PHP_SAPI !== 'cli' && !$cgiNoTerminal) {
        http_response_code(404);
        exit;
    }
    if (!defined('STDIN')) {
        define('STDIN', fopen('php://stdin', 'r'));
    }
    if (!defined('STDOUT')) {
        define('STDOUT', fopen('php://stdout', 'w'));
    }
    if (!defined('STDERR')) {
        define('STDERR', fopen('php://stderr', 'w'));
    }
    @set_time_limit(0);
    ini_set('html_errors', '0');
    ini_set('display_errors', 'stderr');
}
