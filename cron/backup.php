<?php
/**
 * Backup diário do banco — UMA linha de cron por dia (cPanel → Cron Jobs):
 *   15 3 * * * /usr/local/bin/php /home/USUARIO/caminho/cron/backup.php >/dev/null 2>&1
 *
 * Gera storage/backups/wemart-AAAA-MM-DD-HHMMSS.sql.gz (SQL puro,
 * comprimido) usando só PDO — não depende de mysqldump nem de exec(), que
 * a hospedagem compartilhada costuma bloquear. Mantém os 7 mais recentes.
 * A pasta storage/ é negada pelo .htaccess: o arquivo só sai pelo
 * Gerenciador de Arquivos do cPanel (ou SSH/FTP).
 *
 * Para restaurar num banco vazio:
 *   gunzip -c wemart-....sql.gz | mysql -u USUARIO -p BANCO
 * (ou importe o .sql.gz pelo phpMyAdmin, que aceita gzip).
 *
 * Só roda pela linha de comando (403 no navegador); GET_LOCK impede duas
 * execuções ao mesmo tempo. Opcional: php cron/backup.php /outra/pasta
 */
require_once __DIR__ . '/../includes/terminal.php';
terminal_exigir();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auditoria.php';

const BACKUP_MANTER = 7;
const BACKUP_LOTE = 1000;      // linhas lidas por SELECT
const BACKUP_POR_INSERT = 200; // linhas por INSERT no arquivo

$dir = $argv[1] ?? (__DIR__ . '/../storage/backups');
if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
    fwrite(STDERR, "Não foi possível criar a pasta de backup.\n");
    exit(1);
}
$dir = realpath($dir);

$pdo = db();
if ((int) $pdo->query("SELECT GET_LOCK('wemart_backup', 0)")->fetchColumn() !== 1) {
    fwrite(STDOUT, "Outro backup em andamento — nada a fazer.\n");
    exit(0);
}

$final = $dir . '/wemart-' . date('Y-m-d-His') . '.sql.gz';
$tmp = $final . '.parcial';
try {
    $gz = gzopen($tmp, 'wb6');
    if (!$gz) {
        throw new RuntimeException('Não foi possível gravar o arquivo de backup.');
    }
    $w = fn (string $s) => gzwrite($gz, $s);
    $versao = $pdo->query('SELECT VERSION()')->fetchColumn();
    $w("-- WeMart Sales — backup do banco " . DB_NAME . "\n-- Gerado em " . date('Y-m-d H:i:s') . " (servidor {$versao})\n\n");
    $w("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET time_zone = '-03:00';\n\n");

    $tabelas = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    $resumo = [];
    foreach ($tabelas as [$tabela]) {
        $t = '`' . str_replace('`', '``', $tabela) . '`';
        $create = $pdo->query("SHOW CREATE TABLE {$t}")->fetch(PDO::FETCH_NUM)[1];
        $w("-- --------------------------------------------------------\n-- Tabela {$tabela}\nDROP TABLE IF EXISTS {$t};\n{$create};\n\n");

        $colunas = $pdo->query("SHOW COLUMNS FROM {$t}")->fetchAll();
        $binario = $numerico = [];
        foreach ($colunas as $c) {
            $tipo = strtolower($c['Type']);
            $binario[$c['Field']] = (bool) preg_match('/blob|binary/', $tipo);
            $numerico[$c['Field']] = (bool) preg_match('/^(tinyint|smallint|mediumint|int|bigint|decimal|float|double)/', $tipo);
        }
        $nomesCol = implode(', ', array_map(fn ($c) => '`' . str_replace('`', '``', $c['Field']) . '`', $colunas));
        $pk = array_column($pdo->query("SHOW KEYS FROM {$t} WHERE Key_name = 'PRIMARY'")->fetchAll(), 'Column_name');
        $chave = count($pk) === 1 && $numerico[$pk[0]] ? $pk[0] : null;

        $total = 0;
        $ultimo = null;
        $offset = 0;
        $pend = [];
        $despejar = function () use (&$pend, $w, $t, $nomesCol) {
            if ($pend) {
                $w("INSERT INTO {$t} ({$nomesCol}) VALUES\n" . implode(",\n", $pend) . ";\n");
                $pend = [];
            }
        };
        while (true) {
            // Lotes por chave primária (rápido e estável); sem chave numérica única, por OFFSET.
            if ($chave) {
                $ck = '`' . $chave . '`';
                $stmt = $ultimo === null
                    ? $pdo->query("SELECT * FROM {$t} ORDER BY {$ck} LIMIT " . BACKUP_LOTE)
                    : $pdo->query("SELECT * FROM {$t} WHERE {$ck} > " . $pdo->quote((string) $ultimo) . " ORDER BY {$ck} LIMIT " . BACKUP_LOTE);
            } else {
                $ordem = $pk ? ' ORDER BY ' . implode(', ', array_map(fn ($c) => '`' . $c . '`', $pk)) : '';
                $stmt = $pdo->query("SELECT * FROM {$t}{$ordem} LIMIT " . BACKUP_LOTE . ' OFFSET ' . $offset);
            }
            $linhas = $stmt->fetchAll();
            if (!$linhas) {
                break;
            }
            foreach ($linhas as $l) {
                $vals = [];
                foreach ($l as $col => $v) {
                    if ($v === null) {
                        $vals[] = 'NULL';
                    } elseif ($binario[$col]) {
                        $vals[] = $v === '' ? "''" : '0x' . bin2hex($v);
                    } elseif ($numerico[$col] && is_numeric($v)) {
                        $vals[] = (string) $v;
                    } else {
                        $vals[] = $pdo->quote((string) $v);
                    }
                }
                $pend[] = '(' . implode(', ', $vals) . ')';
                if (count($pend) >= BACKUP_POR_INSERT) {
                    $despejar();
                }
                $total++;
            }
            if ($chave) {
                $ultimo = end($linhas)[$chave];
            }
            $offset += count($linhas);
            if (count($linhas) < BACKUP_LOTE) {
                break;
            }
        }
        $despejar();
        $w("\n");
        $resumo[$tabela] = $total;
    }
    $w("SET UNIQUE_CHECKS = 1;\nSET FOREIGN_KEY_CHECKS = 1;\n-- Fim do backup\n");
    gzclose($gz);
    if (!rename($tmp, $final)) {
        throw new RuntimeException('Não foi possível finalizar o arquivo de backup.');
    }
    chmod($final, 0640);

    // Retenção: só os BACKUP_MANTER mais recentes.
    $arquivos = glob($dir . '/wemart-*.sql.gz') ?: [];
    rsort($arquivos, SORT_STRING);
    $apagados = 0;
    foreach (array_slice($arquivos, BACKUP_MANTER) as $velho) {
        if (@unlink($velho)) {
            $apagados++;
        }
    }
    $tamanho = filesize($final);
    auditoria_registrar(null, null, 'backup.gerado', 'sistema', null, ['arquivo' => basename($final), 'bytes' => $tamanho, 'tabelas' => count($resumo), 'linhas' => array_sum($resumo)]);
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] backup ' . basename($final) . ' — ' . count($resumo) . ' tabelas, '
        . array_sum($resumo) . ' linhas, ' . round($tamanho / 1024) . " KB; {$apagados} antigo(s) apagado(s)\n");
} catch (Throwable $e) {
    if (isset($gz) && is_resource($gz)) {
        gzclose($gz);
    }
    @unlink($tmp);
    fwrite(STDERR, 'Backup falhou: ' . $e->getMessage() . "\n");
    error_log('[cron/backup] ' . $e->getMessage());
    exit(1);
} finally {
    $pdo->query("SELECT RELEASE_LOCK('wemart_backup')");
}
