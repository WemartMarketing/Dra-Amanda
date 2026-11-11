<?php
/**
 * Proteção CSRF — token por sessão, validado em toda escrita (POST de
 * formulário ou fetch/JSON) antes de qualquer outra lógica do endpoint.
 *
 * Uso:
 *   - Em formulários HTML: <?= csrf_field() ?> dentro de cada <form>.
 *   - Em fetch() que não vem de um <form> (FormData já carrega o campo
 *     automaticamente se csrf_field() estiver no form): inclua
 *     `csrf_token: <valor da tag <meta name="csrf-token">>` no corpo
 *     JSON/URLSearchParams enviado (ver assets/js/app.js, csrfToken()).
 *   - Em cada api/*.php: csrf_validar_requisicao() logo após conferir
 *     a sessão, antes de tocar no banco.
 */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

/**
 * Aceita o token em $_POST, no header X-CSRF-Token, ou num corpo JSON
 * (lido de php://input — só é consumido aqui quando $_POST/header não
 * trouxerem nada, então não interfere com endpoints que também fazem
 * seu próprio json_decode do corpo).
 */
function csrf_validar_requisicao(): bool
{
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!$token) {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $json = json_decode($raw, true);
            $token = $json['csrf_token'] ?? null;
        }
    }
    return is_string($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}
