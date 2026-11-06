<?php
/**
 * POST /api/roleta_corretor.php  { roleta_id, usuario_id, acao: 'add'|'remover' }
 * Habilita/remove um corretor da roleta. Confere que a roleta e o
 * usuário pertencem à mesma empresa do gestor logado (nunca vincula
 * usuário de outra empresa), e que quem chama pode gerenciar.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';

header('Content-Type: application/json; charset=utf-8');

if (!auth_check()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'Sessão expirada.']);
    exit;
}

$usuario = current_user();
if (!rbac_pode_gerenciar($usuario)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Seu cargo não pode gerenciar roletas.']);
    exit;
}

$empresaId = (int) $usuario['empresa_ativa_id'];
$roletaId = (int) ($_POST['roleta_id'] ?? 0);
$usuarioId = (int) ($_POST['usuario_id'] ?? 0);
$acao = $_POST['acao'] ?? '';

if (!$roletaId || !$usuarioId || !in_array($acao, ['add', 'remover'], true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'erro' => 'Dados inválidos.']);
    exit;
}

$stmt = db()->prepare('SELECT id FROM roletas WHERE id = :id AND empresa_id = :empresa_id');
$stmt->execute(['id' => $roletaId, 'empresa_id' => $empresaId]);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'erro' => 'Roleta não encontrada.']);
    exit;
}

$stmt = db()->prepare("SELECT id FROM usuarios WHERE id = :id AND empresa_id = :empresa_id AND cargo = 'corretor'");
$stmt->execute(['id' => $usuarioId, 'empresa_id' => $empresaId]);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'erro' => 'Corretor não encontrado nesta empresa.']);
    exit;
}

if ($acao === 'add') {
    $stmt = db()->prepare('SELECT COALESCE(MAX(ordem), 0) + 1 FROM roleta_corretores WHERE roleta_id = :roleta_id');
    $stmt->execute(['roleta_id' => $roletaId]);
    $proximaOrdem = (int) $stmt->fetchColumn();
    db()->prepare('INSERT IGNORE INTO roleta_corretores (roleta_id, usuario_id, ordem) VALUES (:roleta_id, :usuario_id, :ordem)')
        ->execute(['roleta_id' => $roletaId, 'usuario_id' => $usuarioId, 'ordem' => $proximaOrdem]);
} else {
    db()->prepare('DELETE FROM roleta_corretores WHERE roleta_id = :roleta_id AND usuario_id = :usuario_id')
        ->execute(['roleta_id' => $roletaId, 'usuario_id' => $usuarioId]);
}

echo json_encode(['ok' => true]);
