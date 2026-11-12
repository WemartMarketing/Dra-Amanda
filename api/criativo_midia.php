<?php
/**
 * GET /api/criativo_midia.php?lead_id=N — imagem do criativo (cópia local)
 * do anúncio que gerou o lead. Mesmas verificações da ficha do lead; o
 * arquivo é localizado pelo lead, nunca por nome ou id vindo da requisição.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/leads.php';
require_once __DIR__ . '/../includes/meta_criativos.php';

function negar_midia(int $status): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    exit($status === 401 ? 'Sessão expirada.' : 'Não encontrado.');
}
if (!auth_check()) {
    negar_midia(401);
}
$usuario = current_user();
$leadId = (int) ($_GET['lead_id'] ?? 0);
if (!empty($usuario['eh_superadmin']) || !$leadId || !lead_carregar($usuario, $leadId) || !modulo_tabela_existe('meta_anuncios')) {
    negar_midia(404);
}
$stmt = db()->prepare('SELECT a.imagem_arquivo FROM leads l JOIN meta_anuncios a ON a.id = l.meta_anuncio_id AND a.empresa_id = l.empresa_id
                       WHERE l.id = :l AND l.empresa_id = :e');
$stmt->execute(['l' => $leadId, 'e' => (int) $usuario['empresa_ativa_id']]);
$rel = (string) $stmt->fetchColumn();
if (!preg_match('#^\d+/\d+_[0-9a-f]{12}\.(jpg|png|webp|gif)$#', $rel, $m)) {
    negar_midia(404);
}
$caminho = realpath(META_CRIATIVO_DIR . '/' . $rel);
$raiz = realpath(META_CRIATIVO_DIR);
if (!$caminho || !$raiz || !str_starts_with($caminho, $raiz . DIRECTORY_SEPARATOR) || !is_file($caminho)) {
    negar_midia(404);
}
$tipos = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
header('Content-Type: ' . $tipos[$m[1]]);
header('Content-Length: ' . filesize($caminho));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
header('Cache-Control: private, max-age=86400');
readfile($caminho);
