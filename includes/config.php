<?php
/**
 * WeMart Sales — configuração local de desenvolvimento.
 *
 * Aponta para o banco MySQL/MariaDB já criado neste ambiente de
 * desenvolvimento (ver docs/README.md). Em produção, substitua por
 * uma cópia de includes/config.example.php com as credenciais reais
 * do cPanel — nunca reaproveite estas credenciais de dev.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'wemart_sales');
define('DB_USER', 'wemart');
define('DB_PASS', 'wemart_dev_pw');

define('APP_NAME', 'WeMart Sales');

date_default_timezone_set('America/Sao_Paulo');
