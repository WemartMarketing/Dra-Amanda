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

/**
 * O módulo de WhatsApp/Jornadas já foi instalado neste banco (migrações
 * 008–010)? Enquanto não, o CRM funciona normalmente, só sem as partes do
 * WhatsApp — em vez de cair com erro 500 depois de um `git pull` feito antes
 * das migrações. Consulta uma vez por requisição.
 */
function modulo_whatsapp_instalado(): bool
{
    static $ok = null;
    if ($ok === null) {
        $stmt = db()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
                             AND TABLE_NAME IN ('whatsapp_contas','corretor_pendencias','jornada_execucoes','roleta_horarios','roleta_excecoes')");
        $ok = (int) $stmt->fetchColumn() === 5;
        if (!$ok) {
            error_log('[WeMart] Módulo WhatsApp ainda não instalado neste banco: rode sql/migration_008, 009 e 010 (ver docs/DEPLOY_HOSTGATOR.md).');
        }
    }
    return $ok;
}

/** A tabela existe neste banco? (para recursos cuja migração pode ainda não ter rodado) */
function modulo_tabela_existe(string $tabela): bool
{
    static $cache = [];
    if (!isset($cache[$tabela])) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
        $stmt->execute(['t' => $tabela]);
        $cache[$tabela] = (int) $stmt->fetchColumn() === 1;
    }
    return $cache[$tabela];
}

/**
 * URL de um arquivo estático com versão (data de modificação): depois de um
 * `git pull` o navegador baixa o CSS/JS novo em vez de usar o do cache.
 */
function asset(string $caminho): string
{
    $arquivo = __DIR__ . '/..' . $caminho;
    return $caminho . (is_file($arquivo) ? '?v=' . filemtime($arquivo) : '');
}
