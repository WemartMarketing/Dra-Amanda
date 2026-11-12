<?php
/**
 * Mini executor de testes (sem dependências). Uso:
 *   php tests/whatsapp_fase1_test.php
 *
 * Só roda com define('TESTES_PERMITIDOS', true) no includes/config.php —
 * NUNCA ligue isso no servidor de produção. Os testes criam uma empresa
 * fictícia "Teste automatizado …" e apagam tudo dela no final.
 */
require_once __DIR__ . '/../includes/terminal.php';
terminal_exigir();
require_once __DIR__ . '/../includes/db.php';

if (!defined('TESTES_PERMITIDOS') || TESTES_PERMITIDOS !== true) {
    fwrite(STDERR, "Testes bloqueados: defina TESTES_PERMITIDOS = true no includes/config.php (só em ambiente de testes).\n");
    exit(2);
}

$GLOBALS['__t'] = ['ok' => 0, 'falhas' => [], 'grupo' => ''];

function grupo(string $nome): void
{
    $GLOBALS['__t']['grupo'] = $nome;
    fwrite(STDOUT, "\n■ $nome\n");
}

function ok($cond, string $descricao): void
{
    if ($cond) {
        $GLOBALS['__t']['ok']++;
        fwrite(STDOUT, "  ✔ $descricao\n");
    } else {
        $GLOBALS['__t']['falhas'][] = $GLOBALS['__t']['grupo'] . ' → ' . $descricao;
        fwrite(STDOUT, "  ✘ $descricao\n");
    }
}

function igual($obtido, $esperado, string $descricao): void
{
    ok($obtido === $esperado, $descricao . ($obtido === $esperado ? '' : ' (obtido: ' . var_export($obtido, true) . ', esperado: ' . var_export($esperado, true) . ')'));
}

function lanca(callable $fn, string $descricao): void
{
    try {
        $fn();
        ok(false, $descricao . ' (não lançou exceção)');
    } catch (Throwable $e) {
        ok(true, $descricao);
    }
}

function resumo_e_sair(): void
{
    $t = $GLOBALS['__t'];
    fwrite(STDOUT, "\n" . str_repeat('─', 50) . "\n" . $t['ok'] . ' verificação(ões) ok, ' . count($t['falhas']) . " falha(s)\n");
    foreach ($t['falhas'] as $f) {
        fwrite(STDOUT, "  ✘ $f\n");
    }
    exit($t['falhas'] ? 1 : 0);
}

/** Empresa fictícia isolada; devolve o id. */
function criar_empresa_teste(string $sufixo = ''): int
{
    $nome = 'Teste automatizado ' . $sufixo . ' ' . bin2hex(random_bytes(3));
    db()->prepare('INSERT INTO empresas (nome, slug, ativa) VALUES (:n, :s, 1)')->execute(['n' => $nome, 's' => 'teste-' . bin2hex(random_bytes(6))]);
    return (int) db()->lastInsertId();
}

function apagar_empresa_teste(int $empresaId): void
{
    db()->prepare('DELETE FROM auditoria WHERE empresa_id = :e')->execute(['e' => $empresaId]);
    db()->prepare("DELETE FROM empresas WHERE id = :e AND nome LIKE 'Teste automatizado%'")->execute(['e' => $empresaId]);
}
