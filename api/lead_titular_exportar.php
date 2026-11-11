<?php
/**
 * POST /api/lead_titular_exportar.php  { lead_id }
 * LGPD (pedido de acesso do titular): baixa, em JSON, tudo o que o sistema
 * guarda sobre UM lead — cadastro, histórico, agenda, tarefas, lista de
 * arquivos e transferências. Permissão excluir_leads (a mesma de quem
 * cuida dos pedidos de LGPD) + lead no escopo. Fica na auditoria.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/lgpd.php';

$usuario = api_iniciar('excluir_leads');
$lead = api_lead($usuario, (int) ($_POST['lead_id'] ?? 0));
$dados = lgpd_dados_titular($lead);
auditoria_registrar((int) $usuario['empresa_ativa_id'], (int) $usuario['id'], 'lead.dados_titular_exportados', 'lead', (int) $lead['id'], ['formato' => 'json']);

header_remove('Content-Type');
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="dados-titular-lead-' . (int) $lead['id'] . '-' . date('Y-m-d') . '.json"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');
echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
