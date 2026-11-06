<?php
/**
 * Autenticação por sessão PHP (sem dependências externas).
 */
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Autentica por e-mail/senha. Retorna o usuário (array) em caso de
 * sucesso, ou null. Usuários inativos nunca autenticam.
 */
function auth_login(string $email, string $senha): ?array
{
    $stmt = db()->prepare('SELECT * FROM usuarios WHERE email = :email AND ativo = 1 LIMIT 1');
    $stmt->execute(['email' => $email]);
    $usuario = $stmt->fetch();

    if (!$usuario || !password_verify($senha, $usuario['senha_hash'])) {
        return null;
    }

    unset($usuario['senha_hash']);
    $_SESSION['usuario_id'] = (int) $usuario['id'];

    // master_wemart escolhe a empresa de trabalho; os demais cargos usam a
    // própria empresa fixa do cadastro.
    if ($usuario['cargo'] === 'master_wemart') {
        if (empty($_SESSION['empresa_ativa_id'])) {
            $primeira = db()->query('SELECT id FROM empresas WHERE ativa = 1 ORDER BY nome LIMIT 1')->fetch();
            $_SESSION['empresa_ativa_id'] = $primeira ? (int) $primeira['id'] : null;
        }
    } else {
        $_SESSION['empresa_ativa_id'] = $usuario['empresa_id'] !== null ? (int) $usuario['empresa_id'] : null;
    }

    db()->prepare('UPDATE usuarios SET ultimo_login_em = NOW() WHERE id = :id')->execute(['id' => $usuario['id']]);

    return $usuario;
}

function auth_logout(): void
{
    $_SESSION = [];
    session_destroy();
}

function auth_check(): bool
{
    return !empty($_SESSION['usuario_id']);
}

/**
 * Redireciona para o login se não houver sessão ativa. Chame no topo
 * de toda página protegida.
 */
function auth_require(): array
{
    if (!auth_check()) {
        header('Location: /login.php');
        exit;
    }
    return current_user();
}

/**
 * Usuário logado, com a empresa de trabalho atual resolvida
 * (empresa_ativa_id — relevante para master_wemart, que pode trocar
 * de empresa; para os demais cargos é sempre a própria empresa).
 */
function current_user(): ?array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    if (empty($_SESSION['usuario_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM usuarios WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $_SESSION['usuario_id']]);
    $usuario = $stmt->fetch();
    if (!$usuario) {
        return null;
    }
    unset($usuario['senha_hash']);
    $usuario['empresa_ativa_id'] = $_SESSION['empresa_ativa_id'] ?? $usuario['empresa_id'];
    $cache = $usuario;
    return $cache;
}

/** Troca a empresa de trabalho (somente master_wemart). */
function auth_trocar_empresa(int $empresaId): void
{
    $u = current_user();
    if (!$u || $u['cargo'] !== 'master_wemart') {
        return;
    }
    $stmt = db()->prepare('SELECT id FROM empresas WHERE id = :id AND ativa = 1');
    $stmt->execute(['id' => $empresaId]);
    if ($stmt->fetch()) {
        $_SESSION['empresa_ativa_id'] = $empresaId;
    }
}
