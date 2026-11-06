<?php
/**
 * WeMart Sales — configuração do ambiente.
 *
 * Copie este arquivo para includes/config.php e preencha com as
 * credenciais reais do banco MySQL (cPanel → MySQL® Databases).
 * includes/config.php NUNCA deve ser commitado com segredos reais —
 * está listado no .gitignore.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'troque_pelo_nome_do_banco');
define('DB_USER', 'troque_pelo_usuario');
define('DB_PASS', 'troque_pela_senha');

// Nome da aplicação exibido na interface.
define('APP_NAME', 'WeMart Sales');

// Fuso horário usado para exibir datas/horas.
date_default_timezone_set('America/Sao_Paulo');
