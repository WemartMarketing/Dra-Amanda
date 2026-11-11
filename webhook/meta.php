<?php
/**
 * Webhook público da Meta (Lead Ads) — https://SEU-DOMINIO/webhook/meta.php
 *
 * GET  → verificação da assinatura do webhook no painel da Meta
 *        (hub.mode=subscribe + hub.verify_token igual a META_VERIFY_TOKEN).
 * POST → avisos de novos cadastros. A assinatura X-Hub-Signature-256
 *        (HMAC-SHA256 do corpo com META_APP_SECRET) é OBRIGATÓRIA; sem ela
 *        ou inválida → 403 e nada é gravado. Não usa sessão nem cookies.
 *
 * Os avisos são gravados em meta_eventos e a Meta recebe 200 na hora; o
 * processamento (buscar os dados na Graph API, criar o lead, roleta)
 * acontece depois da resposta ou, se falhar, pelo cron/rotinas.php.
 */
require_once __DIR__ . '/../includes/meta.php';

header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (!meta_configurada()) {
    http_response_code(403);
    exit('Integração não configurada.');
}

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($metodo === 'GET') {
    // O PHP troca "." por "_" nos nomes: hub.mode → hub_mode.
    $modo = (string) ($_GET['hub_mode'] ?? '');
    $token = (string) ($_GET['hub_verify_token'] ?? '');
    $desafio = (string) ($_GET['hub_challenge'] ?? '');
    if ($modo === 'subscribe' && $token !== '' && hash_equals(META_VERIFY_TOKEN, $token) && preg_match('/^[A-Za-z0-9_\-]{1,200}$/', $desafio)) {
        http_response_code(200);
        exit($desafio);
    }
    http_response_code(403);
    exit('Verificação recusada.');
}

if ($metodo !== 'POST') {
    http_response_code(405);
    exit('Método não permitido.');
}

$limite = 1024 * 1024;
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $limite) {
    http_response_code(413);
    exit('Corpo grande demais.');
}
$corpo = (string) file_get_contents('php://input', false, null, 0, $limite + 1);
if (strlen($corpo) > $limite) {
    http_response_code(413);
    exit('Corpo grande demais.');
}

$assinatura = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
$esperada = 'sha256=' . hash_hmac('sha256', $corpo, META_APP_SECRET);
if ($assinatura === '' || !hash_equals($esperada, $assinatura)) {
    http_response_code(403);
    exit('Assinatura inválida.');
}

$dados = json_decode($corpo, true);
if (!is_array($dados)) {
    http_response_code(400);
    exit('JSON inválido.');
}

try {
    $novos = meta_registrar_webhook($dados);
} catch (Throwable $e) {
    // Banco fora do ar: 500 faz a Meta reenviar o aviso depois.
    error_log('[webhook/meta] ' . $e->getMessage());
    http_response_code(500);
    exit('Erro temporário.');
}

http_response_code(200);
echo 'EVENT_RECEIVED';

if (!$novos) {
    exit;
}
// Responde à Meta antes de processar (PHP-FPM); sem FPM, processa poucos
// avisos dentro de um tempo curto e deixa o resto para o cron.
ignore_user_abort(true);
$semFpm = !function_exists('fastcgi_finish_request');
if (!$semFpm) {
    fastcgi_finish_request();
}
$inicio = microtime(true);
foreach ($novos as $i => $id) {
    if ($semFpm && ($i >= 3 || microtime(true) - $inicio > 8)) {
        break;
    }
    try {
        meta_processar_evento($id);
    } catch (Throwable $e) {
        error_log('[webhook/meta] evento ' . $id . ': ' . meta_texto_seguro($e->getMessage()));
    }
}
