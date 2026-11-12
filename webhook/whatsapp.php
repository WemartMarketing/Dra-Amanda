<?php
/**
 * Webhook público do WhatsApp (Cloud API) — https://SEU-DOMINIO/webhook/whatsapp.php
 *
 * GET  → verificação no painel da Meta (hub.mode=subscribe + hub.verify_token
 *        igual a WA_VERIFY_TOKEN, ou META_VERIFY_TOKEN se não houver um próprio).
 * POST → mensagens recebidas, status (enviada/entregue/lida/falhou) e
 *        mudanças de template. A assinatura X-Hub-Signature-256 (HMAC-SHA256
 *        do corpo com META_APP_SECRET) é OBRIGATÓRIA; sem ela ou inválida →
 *        403 e nada é gravado. Sem sessão nem cookies.
 *
 * Cada aviso é gravado (deduplicado) em whatsapp_webhook_eventos e a Meta
 * recebe 200 na hora; o processamento acontece depois da resposta e, se
 * falhar, pelo cron/rotinas.php. Envios pendentes da fila também são
 * empurrados aqui para não esperar o próximo cron.
 */
require_once __DIR__ . '/../includes/whatsapp.php';
require_once __DIR__ . '/../includes/automacao.php';

header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (!wa_plataforma_configurada()) {
    http_response_code(403);
    exit('Integração não configurada.');
}

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($metodo === 'GET') {
    $modo = (string) ($_GET['hub_mode'] ?? '');
    $token = (string) ($_GET['hub_verify_token'] ?? '');
    $desafio = (string) ($_GET['hub_challenge'] ?? '');
    if ($modo === 'subscribe' && $token !== '' && hash_equals(wa_verify_token(), $token) && preg_match('/^[A-Za-z0-9_\-]{1,200}$/', $desafio)) {
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

$limite = 3 * 1024 * 1024;
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
    $novos = wa_webhook_registrar($dados);
} catch (Throwable $e) {
    // Banco fora do ar: 500 faz a Meta reenviar o aviso depois.
    error_log('[webhook/whatsapp] ' . $e->getMessage());
    http_response_code(500);
    exit('Erro temporário.');
}

http_response_code(200);
echo 'EVENT_RECEIVED';

if (!$novos) {
    exit;
}
ignore_user_abort(true);
$semFpm = !function_exists('fastcgi_finish_request');
if (!$semFpm) {
    fastcgi_finish_request();
}
$inicio = microtime(true);
foreach ($novos as $i => $id) {
    // Sem FPM a Meta fica esperando: processa poucos e deixa o resto para o cron.
    if ($semFpm && ($i >= 5 || microtime(true) - $inicio > 8)) {
        break;
    }
    try {
        wa_processar_evento($id);
    } catch (Throwable $e) {
        error_log('[webhook/whatsapp] evento ' . $id . ': ' . meta_texto_seguro($e->getMessage()));
    }
}
if (!$semFpm) {
    // Resposta já entregue: aproveita para enviar o que estiver vencido na fila.
    try {
        automacao_executar(20);
    } catch (Throwable $e) {
        error_log('[webhook/whatsapp] fila: ' . meta_texto_seguro($e->getMessage()));
    }
}
