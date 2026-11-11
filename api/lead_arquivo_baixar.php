<?php
/**
 * GET /api/lead_arquivo_baixar.php?id=N — único caminho de download dos
 * arquivos de leads. Confere sessão, permissão acessar_arquivos_leads,
 * empresa e o escopo do lead (404 para tudo que não puder ver, sem
 * confirmar se o arquivo existe). Sempre como anexo, com nosniff.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/leads.php';
require_once __DIR__ . '/../includes/arquivos.php';

function negar(int $status, string $msg): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    exit($msg);
}

if (!auth_check()) {
    negar(401, 'Sessão expirada. Entre novamente.');
}
$usuario = current_user();
if (!empty($usuario['eh_superadmin']) || !usuario_tem_permissao($usuario, 'acessar_arquivos_leads')) {
    negar(403, 'Sua função não tem acesso a arquivos de leads.');
}
$stmt = db()->prepare('SELECT * FROM lead_arquivos WHERE id = :id AND empresa_id = :e AND excluido_em IS NULL');
$stmt->execute(['id' => (int) ($_GET['id'] ?? 0), 'e' => $usuario['empresa_ativa_id']]);
$arquivo = $stmt->fetch();
if (!$arquivo || !lead_carregar($usuario, (int) $arquivo['lead_id'])) {
    negar(404, 'Arquivo não encontrado.');
}
$caminho = lead_arquivo_caminho($arquivo['arquivo']);
if (!$caminho || !isset(lead_arquivo_tipos()[$arquivo['mime']])) {
    negar(404, 'Arquivo não encontrado.');
}

$nomeAscii = preg_replace('/[^A-Za-z0-9._-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $arquivo['nome_original']) ?: 'arquivo');
header('Content-Type: ' . $arquivo['mime']);
header('Content-Length: ' . filesize($caminho));
header('Content-Disposition: attachment; filename="' . $nomeAscii . '"; filename*=UTF-8\'\'' . rawurlencode($arquivo['nome_original']));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header("Content-Security-Policy: default-src 'none'; sandbox");
readfile($caminho);
