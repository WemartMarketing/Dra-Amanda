<?php
/**
 * Autenticação por sessão PHP (sem dependências externas).
 *
 * O usuário carregado por current_user()/auth_login() traz, além dos
 * campos da tabela usuarios, os dados resolvidos da sua função:
 *   escopo        string|null  escopo de visibilidade de leads (ver
 *                              includes/rbac.php) — null para Superadmin,
 *                              que não tem escopo de leads nesta etapa.
 *   permissoes    string[]     chaves de permissao_chave que a função
 *                              do usuário possui (vazio para Superadmin,
 *                              que não passa por aqui — ver
 *                              usuario_tem_permissao() em rbac.php).
 *   eh_superadmin bool
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auditoria.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const LOGIN_MAX_TENTATIVAS = 5;
const LOGIN_JANELA_MINUTOS = 15;

/** Quantas tentativas de login falharam para este e-mail na janela recente. */
function auth_tentativas_recentes(string $email): int
{
    $stmt = db()->prepare('
        SELECT COUNT(*) FROM login_tentativas
        WHERE email = :email AND sucesso = 0 AND criado_em >= DATE_SUB(NOW(), INTERVAL ' . LOGIN_JANELA_MINUTOS . ' MINUTE)
    ');
    $stmt->execute(['email' => $email]);
    return (int) $stmt->fetchColumn();
}

function auth_registrar_tentativa(string $email, bool $sucesso): void
{
    db()->prepare('INSERT INTO login_tentativas (email, ip, sucesso) VALUES (:email, :ip, :sucesso)')
        ->execute(['email' => $email, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null, 'sucesso' => $sucesso ? 1 : 0]);
}

/**
 * Autentica por e-mail/senha. Retorna:
 *   ['ok' => true,  'usuario' => array]
 *   ['ok' => false, 'bloqueado' => true]               — muitas tentativas recentes
 *   ['ok' => false, 'bloqueado' => false]               — credenciais inválidas
 */
function auth_login(string $email, string $senha): array
{
    if (auth_tentativas_recentes($email) >= LOGIN_MAX_TENTATIVAS) {
        return ['ok' => false, 'bloqueado' => true];
    }

    $stmt = db()->prepare('SELECT * FROM usuarios WHERE email = :email AND ativo = 1 LIMIT 1');
    $stmt->execute(['email' => $email]);
    $usuario = $stmt->fetch();

    if (!$usuario || !password_verify($senha, $usuario['senha_hash'])) {
        auth_registrar_tentativa($email, false);
        auditoria_registrar($usuario['empresa_id'] ?? null, $usuario['id'] ?? null, 'login.falha', 'usuario', $usuario['id'] ?? null, ['email' => $email]);
        return ['ok' => false, 'bloqueado' => false];
    }

    auth_registrar_tentativa($email, true);

    unset($usuario['senha_hash']);
    // Novo ID de sessão a cada login (evita fixação de sessão).
    session_regenerate_id(true);
    $_SESSION['usuario_id'] = (int) $usuario['id'];
    $_SESSION['empresa_ativa_id'] = $usuario['empresa_id'] !== null ? (int) $usuario['empresa_id'] : null;

    db()->prepare('UPDATE usuarios SET ultimo_login_em = NOW() WHERE id = :id')->execute(['id' => $usuario['id']]);

    auditoria_registrar($usuario['empresa_id'] !== null ? (int) $usuario['empresa_id'] : null, (int) $usuario['id'], 'login.sucesso', 'usuario', (int) $usuario['id']);

    return ['ok' => true, 'usuario' => current_user()];
}

function auth_logout(): void
{
    $_SESSION = [];
    session_destroy();
}

function auth_check(): bool
{
    return !empty($_SESSION['usuario_id']) && current_user() !== null;
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
 * Usuário logado, com empresa_ativa_id, escopo, permissoes[] e
 * eh_superadmin já resolvidos a partir da função (join com funcoes +
 * funcao_permissoes) — única fonte de verdade para RBAC, ver
 * includes/rbac.php.
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
    $empresaAtiva = true;
    if ($usuario && empty($usuario['eh_superadmin']) && $usuario['empresa_id']) {
        $stmt = db()->prepare('SELECT ativa FROM empresas WHERE id = :id');
        $stmt->execute(['id' => $usuario['empresa_id']]);
        $empresaAtiva = (bool) $stmt->fetchColumn();
    }
    // Desativar o usuário (ou a empresa) encerra sessões já abertas na próxima requisição.
    if (!$usuario || empty($usuario['ativo']) || !$empresaAtiva) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        return null;
    }
    unset($usuario['senha_hash']);
    $usuario['empresa_ativa_id'] = $_SESSION['empresa_ativa_id'] ?? $usuario['empresa_id'];
    $usuario['eh_superadmin'] = !empty($usuario['eh_superadmin']);

    if ($usuario['eh_superadmin']) {
        $usuario['escopo'] = null;
        $usuario['permissoes'] = [];
        $usuario['funcao_nome'] = 'Superadmin WeMart';
    } else {
        $stmt = db()->prepare('SELECT nome, escopo FROM funcoes WHERE id = :id');
        $stmt->execute(['id' => $usuario['funcao_id']]);
        $funcao = $stmt->fetch();
        $usuario['escopo'] = $funcao['escopo'] ?? null;
        $usuario['funcao_nome'] = $funcao['nome'] ?? '—';

        $stmt = db()->prepare('SELECT permissao_chave FROM funcao_permissoes WHERE funcao_id = :id');
        $stmt->execute(['id' => $usuario['funcao_id']]);
        $usuario['permissoes'] = array_column($stmt->fetchAll(), 'permissao_chave');
    }

    $cache = $usuario;
    return $cache;
}
