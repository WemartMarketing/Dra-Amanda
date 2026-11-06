<?php
/**
 * POST /api/lead_atualizar.php — formulário "Atualizar atendimento" da
 * ficha do lead. Atualiza a etapa/próximo passo/retorno e registra o
 * texto livre no histórico.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/leads.php';

$usuario = auth_require();
$leadId = (int) ($_POST['lead_id'] ?? 0);

if (!$leadId || !rbac_pode_ver_lead($usuario, $leadId)) {
    http_response_code(403);
    exit('Acesso negado.');
}

$etapasValidas = array_merge(lead_etapas_funil(), ['perdido']);
$novaEtapa = in_array($_POST['etapa'] ?? '', $etapasValidas, true) ? $_POST['etapa'] : null;
$nota = trim($_POST['nota'] ?? '');
$proximoPasso = trim($_POST['proximo_passo'] ?? '') ?: null;
$dataRetorno = trim($_POST['data_retorno'] ?? '') ?: null;

$stmt = db()->prepare('SELECT etapa FROM leads WHERE id = :id');
$stmt->execute(['id' => $leadId]);
$etapaAnterior = $stmt->fetchColumn();

db()->prepare('
    UPDATE leads SET
        etapa = COALESCE(:etapa, etapa),
        proximo_passo = :proximo_passo,
        data_retorno = :data_retorno,
        ultimo_contato_em = NOW()
    WHERE id = :id
')->execute([
    'etapa' => $novaEtapa,
    'proximo_passo' => $proximoPasso,
    'data_retorno' => $dataRetorno,
    'id' => $leadId,
]);

if ($nota !== '') {
    lead_registrar_evento($leadId, (int) $usuario['id'], 'contato', $nota);
}
if ($novaEtapa && $novaEtapa !== $etapaAnterior) {
    lead_registrar_evento($leadId, (int) $usuario['id'], 'mudanca_etapa', 'Etapa alterada de "' . lead_etapa_rotulo($etapaAnterior) . '" para "' . lead_etapa_rotulo($novaEtapa) . '"');
}

header('Location: /pages/lead.php?id=' . $leadId);
exit;
