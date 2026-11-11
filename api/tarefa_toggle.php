<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';

header('Content-Type: application/json; charset=utf-8');
$usuario = auth_require();

if (!csrf_validar_requisicao()) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

$dados = json_decode(file_get_contents('php://input'), true) ?: [];
$tarefaId = (int) ($dados['tarefa_id'] ?? 0);
$concluida = !empty($dados['concluida']) ? 1 : 0;

if (!$tarefaId) {
    http_response_code(422);
    echo json_encode(['ok' => false]);
    exit;
}

// Confirma que a tarefa pertence a um lead visível ao usuário (evita que
// alguém altere uma tarefa de um lead fora do seu escopo).
$stmt = db()->prepare('SELECT lead_id FROM lead_tarefas WHERE id = :id');
$stmt->execute(['id' => $tarefaId]);
$leadId = $stmt->fetchColumn();

if (!$leadId || !rbac_pode_ver_lead($usuario, (int) $leadId)) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

db()->prepare('UPDATE lead_tarefas SET concluida = :c WHERE id = :id')->execute(['c' => $concluida, 'id' => $tarefaId]);
echo json_encode(['ok' => true]);
