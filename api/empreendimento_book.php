<?php
/**
 * GET /api/empreendimento_book.php?id=N — abre o book (PDF) do empreendimento
 * para a equipe conferir o que o cliente recebe. Só da própria empresa e só
 * para quem atende ou configura o WhatsApp.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/whatsapp_conversas.php';

function book_negar(int $status, string $msg): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    exit($msg);
}

if (!auth_check()) {
    book_negar(401, 'Sessão expirada. Entre novamente.');
}
$usuario = current_user();
if (!wa_pode_atender($usuario) && !usuario_tem_permissao($usuario, 'whatsapp_configurar')) {
    book_negar(403, 'Sem acesso ao book.');
}
$stmt = db()->prepare('SELECT book_arquivo, book_nome FROM empreendimentos WHERE id = :id AND empresa_id = :e');
$stmt->execute(['id' => (int) ($_GET['id'] ?? 0), 'e' => (int) $usuario['empresa_ativa_id']]);
$emp = $stmt->fetch();
$caminho = $emp && $emp['book_arquivo'] ? wa_arquivo_permitido($emp['book_arquivo']) : null;
if (!$caminho) {
    book_negar(404, 'Book não encontrado.');
}
$nome = preg_replace('/[^A-Za-z0-9._-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $emp['book_nome']) ?: 'book.pdf');
header('Content-Type: application/pdf');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . $nome . '"');
header('Content-Length: ' . filesize($caminho));
header('Cache-Control: private, no-store');
readfile($caminho);
