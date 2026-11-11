<?php
/**
 * POST /api/usuario_toggle_ativo.php  { id }
 * Ativa/desativa um usuário da própria empresa (nunca apaga — leads e
 * histórico continuam referenciando o id). Impede desativar a si mesmo
 * por aqui (a tela já esconde esse botão na própria linha do usuário
 * logado; checado de novo para não depender só do HTML).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';

header('Content-Type: application/json; charset=utf-8');

if (!auth_check()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'Sessão expirada.']);
    exit;
}

$usuarioLogado = current_user();
if (!usuario_tem_permissao($usuarioLogado, 'administrar_usuarios')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Sua função não pode gerenciar usuários.']);
    exit;
}

if (!csrf_validar_requisicao()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Token de segurança inválido.']);
    exit;
}

$empresaId = (int) $usuarioLogado['empresa_ativa_id'];
$id = (int) ($_POST['id'] ?? 0);

if (!$id || $id === (int) $usuarioLogado['id']) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'erro' => 'Não é possível alterar o próprio acesso por aqui.']);
    exit;
}

$stmt = db()->prepare('SELECT ativo FROM usuarios WHERE id = :id AND empresa_id = :empresa_id');
$stmt->execute(['id' => $id, 'empresa_id' => $empresaId]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'erro' => 'Usuário não encontrado.']);
    exit;
}

$novoAtivo = $row['ativo'] ? 0 : 1;
db()->prepare('UPDATE usuarios SET ativo = :ativo WHERE id = :id')
    ->execute(['ativo' => $novoAtivo, 'id' => $id]);

auditoria_registrar($empresaId, (int) $usuarioLogado['id'], $novoAtivo ? 'usuario.ativado' : 'usuario.desativado', 'usuario', $id);

echo json_encode(['ok' => true]);
