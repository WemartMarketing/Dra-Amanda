<?php
/**
 * POST /api/lead_transferir.php  { lead_ids[] (ou lead_id), destino_id, observacao? }
 * Transfere um ou vários leads para outro corretor do recorte do usuário.
 * Permissão: redistribuir_leads. Cada lead precisa estar no escopo de quem
 * transfere; o destino precisa estar em rbac_usuarios_atribuiveis().
 * A regra de visibilidade está descrita em includes/transferencia.php.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/transferencia.php';

$usuario = api_iniciar('redistribuir_leads');

$ids = $_POST['lead_ids'] ?? ($_POST['lead_id'] ?? []);
$ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
if (!$ids || count($ids) > 200) {
    api_erro('Selecione de 1 a 200 leads.', 422);
}
$destinoId = (int) ($_POST['destino_id'] ?? 0);
$permitidos = array_map('intval', array_column(rbac_usuarios_atribuiveis($usuario), 'id'));
if (!in_array($destinoId, $permitidos, true)) {
    api_erro('Corretor de destino não encontrado no seu recorte.', 404);
}
$observacao = api_texto($_POST['observacao'] ?? null, 500);

// Valida todos antes de alterar qualquer um (tudo ou nada).
$leads = [];
foreach ($ids as $leadId) {
    $leads[] = api_lead($usuario, $leadId);
}

$pdo = db();
$pdo->beginTransaction();
$transferidos = 0;
foreach ($leads as $lead) {
    if ((int) $lead['responsavel_id'] === $destinoId) {
        continue;
    }
    lead_transferir($lead, $destinoId, 'administrativa', $usuario, $observacao);
    $transferidos++;
}
$pdo->commit();

api_responder(['ok' => true, 'transferidos' => $transferidos, 'mensagem' => $transferidos === 1 ? '1 lead transferido.' : "{$transferidos} leads transferidos."]);
