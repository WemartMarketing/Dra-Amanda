<?php
/**
 * POST /api/lead_anonimizar.php  { lead_id, confirmacao: "ANONIMIZAR" }
 * LGPD: anonimiza o lead de forma IRREVERSÍVEL (ver includes/lgpd.php).
 * Permissão excluir_leads + lead dentro do escopo; exige digitar a
 * palavra ANONIMIZAR. Fica registrado na auditoria (sem dados pessoais).
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/lgpd.php';

$usuario = api_iniciar('excluir_leads');
$lead = api_lead($usuario, (int) ($_POST['lead_id'] ?? 0));
if (mb_strtoupper(trim((string) ($_POST['confirmacao'] ?? ''))) !== 'ANONIMIZAR') {
    api_erro('Para confirmar, digite ANONIMIZAR no campo indicado.', 422);
}
if (strpos($lead['nome'], 'Lead anonimizado #') === 0 && !$lead['telefone'] && !$lead['email']) {
    api_erro('Este lead já foi anonimizado.', 409);
}
$res = lgpd_anonimizar_lead($lead, $usuario);
api_responder(['ok' => true, 'mensagem' => 'Dados pessoais anonimizados. ' . $res['arquivos'] . ' arquivo(s) apagado(s).']);
