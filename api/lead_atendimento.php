<?php
/**
 * POST /api/lead_atendimento.php — aba "Atendimento" da ficha: num só
 * envio o corretor muda a etapa, registra o que aconteceu e define o
 * próximo contato (que vira um retorno na agenda).
 *   { lead_id, etapa_id?, nota?, proximo_passo?, proximo_contato? (datetime-local) }
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/funil.php';
require_once __DIR__ . '/../includes/agenda.php';

$usuario = api_iniciar('editar_leads');
$lead = api_lead($usuario, (int) ($_POST['lead_id'] ?? 0));
$empresaId = (int) $usuario['empresa_ativa_id'];

$etapaId = (int) ($_POST['etapa_id'] ?? 0);
$nota = api_texto($_POST['nota'] ?? null, 500);
$proximoPasso = api_texto($_POST['proximo_passo'] ?? null);
$proximoContato = api_data_hora($_POST['proximo_contato'] ?? null);
if ($proximoContato === false) {
    api_erro('Data do próximo contato inválida.', 422);
}
if ($etapaId && !funil_etapa($empresaId, $etapaId)) {
    api_erro('Etapa inválida.', 422);
}
if (!$nota && !$proximoPasso && !$proximoContato && (!$etapaId || $etapaId === (int) $lead['etapa_id'])) {
    api_erro('Nada para salvar: escreva o que aconteceu, mude a etapa ou defina o próximo contato.', 422);
}

$pdo = db();
$pdo->beginTransaction();
if ($etapaId && !lead_mudar_etapa($lead, $etapaId, $usuario)) {
    $pdo->rollBack();
    api_erro('Etapa inválida ou inativa.', 422);
}
if ($nota) {
    lead_registrar_evento((int) $lead['id'], (int) $usuario['id'], 'contato', $nota);
    $pdo->prepare('UPDATE leads SET ultimo_contato_em = NOW() WHERE id = :id')->execute(['id' => $lead['id']]);
    lead_registrar_feedback((int) $lead['id']);
}
// Um contato registrado cumpre o retorno vencido; um novo próximo contato o substitui.
$novoRetorno = $proximoContato ? substr($proximoContato, 0, 10) : $lead['data_retorno'];
if (!$proximoContato && $nota && $lead['data_retorno'] && $lead['data_retorno'] <= date('Y-m-d')) {
    $novoRetorno = null;
}
$pdo->prepare('UPDATE leads SET proximo_passo = :passo, data_retorno = :retorno WHERE id = :id')
    ->execute(['passo' => $proximoPasso ?? $lead['proximo_passo'], 'retorno' => $novoRetorno, 'id' => $lead['id']]);
if ($proximoContato) {
    $responsavel = (int) ($lead['responsavel_id'] ?: $usuario['id']);
    agenda_criar($empresaId, (int) $lead['id'], $responsavel, 'retorno', ($proximoPasso ?: 'Retornar contato') . ' — ' . $lead['nome'], $proximoContato, null, (int) $usuario['id']);
    lead_registrar_evento((int) $lead['id'], (int) $usuario['id'], 'agenda', 'Próximo contato definido para ' . date('d/m/Y \à\s H\hi', strtotime($proximoContato)));
}
$pdo->commit();

api_responder(['ok' => true, 'mensagem' => 'Atendimento salvo.']);
