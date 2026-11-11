<?php
/**
 * POST /api/roleta_empreendimento.php  { roleta_id, empreendimento_id, acao: 'add'|'remover' }
 * Vincula/desvincula um empreendimento da roleta, com as mesmas
 * checagens de empresa e permissão de api/roleta_corretor.php.
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
if (!usuario_tem_permissao($usuario, 'gerenciar_roletas')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Sua função não pode gerenciar roletas.']);
    exit;
}

if (!csrf_validar_requisicao()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Token de segurança inválido.']);
    exit;
}

$empresaId = (int) $usuario['empresa_ativa_id'];
$roletaId = (int) ($_POST['roleta_id'] ?? 0);
$empreendimentoId = (int) ($_POST['empreendimento_id'] ?? 0);
$acao = $_POST['acao'] ?? '';

if (!$roletaId || !$empreendimentoId || !in_array($acao, ['add', 'remover'], true)) {
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

$stmt = db()->prepare('SELECT id FROM empreendimentos WHERE id = :id AND empresa_id = :empresa_id');
$stmt->execute(['id' => $empreendimentoId, 'empresa_id' => $empresaId]);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'erro' => 'Empreendimento não encontrado nesta empresa.']);
    exit;
}

if ($acao === 'add') {
    db()->prepare('INSERT IGNORE INTO roleta_empreendimentos (roleta_id, empreendimento_id) VALUES (:roleta_id, :empreendimento_id)')
        ->execute(['roleta_id' => $roletaId, 'empreendimento_id' => $empreendimentoId]);
} else {
    db()->prepare('DELETE FROM roleta_empreendimentos WHERE roleta_id = :roleta_id AND empreendimento_id = :empreendimento_id')
        ->execute(['roleta_id' => $roletaId, 'empreendimento_id' => $empreendimentoId]);
}

echo json_encode(['ok' => true]);
