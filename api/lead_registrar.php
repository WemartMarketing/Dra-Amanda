<?php
/**
 * POST /api/lead_registrar.php  { lead_id, tipo, texto? }
 * Registro rápido no histórico: ligacao / whatsapp (apertar o botão já
 * registra), contato e nota (observação).
 */
require_once __DIR__ . '/../includes/api.php';

$tipo = (string) ($_POST['tipo'] ?? '');
$permissoesPorTipo = ['ligacao' => 'visualizar_leads', 'whatsapp' => 'visualizar_leads', 'contato' => 'editar_leads', 'nota' => 'editar_leads'];
$usuario = api_iniciar($permissoesPorTipo[$tipo] ?? 'visualizar_leads');
if (!isset($permissoesPorTipo[$tipo])) {
    api_erro('Tipo de registro inválido.', 422);
}
$lead = api_lead($usuario, (int) ($_POST['lead_id'] ?? 0));
$texto = api_texto($_POST['texto'] ?? null, 500);

$descricao = [
    'ligacao'  => 'Ligação iniciada pelo sistema',
    'whatsapp' => 'Conversa no WhatsApp iniciada pelo sistema',
][$tipo] ?? $texto;
if (in_array($tipo, ['contato', 'nota'], true) && !$texto) {
    api_erro('Escreva o texto do registro.', 422);
}
if ($texto && in_array($tipo, ['ligacao', 'whatsapp'], true)) {
    $descricao .= ' — ' . $texto;
}

lead_registrar_evento((int) $lead['id'], (int) $usuario['id'], $tipo, $descricao);
if ($tipo !== 'nota') {
    db()->prepare('UPDATE leads SET ultimo_contato_em = NOW() WHERE id = :id')->execute(['id' => $lead['id']]);
}
api_responder(['ok' => true]);
