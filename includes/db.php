<?php
/**
 * Conexão PDO única (singleton) com o MySQL/MariaDB.
 */
require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // O servidor MySQL guarda hora UTC por padrão; sem isso, NOW()/
        // CURDATE() no banco ficam 3h à frente do horário local usado pelo
        // PHP (date_default_timezone_set em config.php), fazendo contas de
        // "há quantos minutos" darem negativo. Alinha a sessão ao mesmo
        // fuso fixo usado pela aplicação.
        $pdo->exec("SET time_zone = '-03:00'");
    }
    return $pdo;
}
