<?php
/**
 * POST /api/lead_mover_etapa.php  { lead_id, etapa }
 * Move um lead para outra etapa do funil, respeitando o escopo RBAC do
 * usuário logado, e registra o evento no histórico.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/leads.php';

header('Content-Type: application/json; charset=utf-8');

if (!auth_check()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'Sessão expirada.']);
    exit;
}

$usuario = current_user();
$dados = json_decode(file_get_contents('php://input'), true) ?: [];
$leadId = (int) ($dados['lead_id'] ?? 0);
$novaEtapa = (string) ($dados['etapa'] ?? '');

$etapasValidas = array_merge(lead_etapas_funil(), ['perdido']);
if (!$leadId || !in_array($novaEtapa, $etapasValidas, true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'erro' => 'Dados inválidos.']);
    exit;
}

if (!rbac_pode_ver_lead($usuario, $leadId)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Você não tem acesso a este lead.']);
    exit;
}

$stmt = db()->prepare('SELECT etapa, nome FROM leads WHERE id = :id');
$stmt->execute(['id' => $leadId]);
$lead = $stmt->fetch();
if (!$lead) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'erro' => 'Lead não encontrado.']);
    exit;
}

if ($lead['etapa'] !== $novaEtapa) {
    db()->prepare('UPDATE leads SET etapa = :etapa WHERE id = :id')->execute(['etapa' => $novaEtapa, 'id' => $leadId]);
    lead_registrar_evento(
        $leadId,
        (int) $usuario['id'],
        'mudanca_etapa',
        'Etapa alterada de "' . lead_etapa_rotulo($lead['etapa']) . '" para "' . lead_etapa_rotulo($novaEtapa) . '"'
    );
}

echo json_encode(['ok' => true]);
