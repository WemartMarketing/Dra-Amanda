<?php
/**
 * GET /api/whatsapp_midia.php?id=N — arquivo de uma mensagem do WhatsApp
 * (foto/documento recebido do cliente ou anexo enviado). Confere sessão,
 * permissão whatsapp_atender e o escopo da conversa (404 para tudo que não
 * puder ver). Mídia recebida é baixada da Meta só na primeira vez.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/whatsapp_conversas.php';

function midia_negar(int $status, string $msg): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    exit($msg);
}

if (!auth_check()) {
    midia_negar(401, 'Sessão expirada. Entre novamente.');
}
$usuario = current_user();
if (!wa_pode_atender($usuario)) {
    midia_negar(403, 'Sua função não atende pelo WhatsApp.');
}
$empresaId = (int) $usuario['empresa_ativa_id'];
$stmt = db()->prepare('SELECT * FROM whatsapp_mensagens WHERE id = :id AND empresa_id = :e');
$stmt->execute(['id' => (int) ($_GET['id'] ?? 0), 'e' => $empresaId]);
$msg = $stmt->fetch();
if (!$msg || !wa_conversa_carregar($usuario, (int) $msg['conversa_id'])) {
    midia_negar(404, 'Arquivo não encontrado.');
}
$conta = wa_conta_da_empresa($empresaId);
$r = $conta ? wa_midia_local($msg, $conta) : ['ok' => false, 'erro' => 'WhatsApp não conectado.'];
if (!$r['ok']) {
    midia_negar(404, $r['erro']);
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($r['caminho']) ?: 'application/octet-stream';
$seguros = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'audio/ogg', 'audio/mpeg', 'video/mp4'];
$ext = ['application/pdf' => '.pdf', 'image/jpeg' => '.jpg', 'image/png' => '.png', 'image/webp' => '.webp',
        'audio/ogg' => '.ogg', 'audio/mpeg' => '.mp3', 'video/mp4' => '.mp4'][$mime] ?? '.bin';
$nome = 'whatsapp-' . (int) $msg['id'] . $ext;
header('Content-Type: ' . (in_array($mime, $seguros, true) ? $mime : 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
// Imagem abre na própria aba; o resto é baixado.
header('Content-Disposition: ' . (str_starts_with($mime, 'image/') ? 'inline' : 'attachment') . '; filename="' . $nome . '"');
header('Content-Length: ' . filesize($r['caminho']));
header('Cache-Control: private, no-store');
readfile($r['caminho']);
