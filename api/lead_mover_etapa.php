<?php
/**
 * POST /api/lead_mover_etapa.php  { lead_id, etapa_id }
 * Move um lead para outra etapa do funil (Kanban: arrastar ou botão
 * "Mover etapa"). Sempre pelo caminho central lead_mudar_etapa().
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/funil.php';

$usuario = api_iniciar('editar_leads');
$lead = api_lead($usuario, (int) ($_POST['lead_id'] ?? 0));
$etapaId = (int) ($_POST['etapa_id'] ?? 0);

if (!lead_mudar_etapa($lead, $etapaId, $usuario)) {
    api_erro('Etapa inválida.', 422);
}
api_responder(['ok' => true]);
