<?php
/**
 * POST /api/lead_excluir.php  { lead_id }
 * Exclusão lógica (LGPD): o lead some de todas as telas, relatórios e APIs
 * (rbac_leads_scope filtra excluido_em), mas o registro fica para
 * auditoria. Permissão: excluir_leads.
 */
require_once __DIR__ . '/../includes/api.php';

$usuario = api_iniciar('excluir_leads');
$lead = api_lead($usuario, (int) ($_POST['lead_id'] ?? 0));

db()->prepare('UPDATE leads SET excluido_em = NOW(), excluido_por = :por WHERE id = :id AND empresa_id = :empresa_id')
    ->execute(['por' => $usuario['id'], 'id' => $lead['id'], 'empresa_id' => $usuario['empresa_ativa_id']]);
db()->prepare("UPDATE agenda_compromissos SET status = 'cancelado' WHERE lead_id = :id AND status = 'agendado'")
    ->execute(['id' => $lead['id']]);
auditoria_registrar((int) $usuario['empresa_ativa_id'], (int) $usuario['id'], 'lead.excluido', 'lead', (int) $lead['id'], ['nome' => $lead['nome']]);
api_responder(['ok' => true]);
