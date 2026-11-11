<?php
/**
 * POST /api/agenda_salvar.php — novo compromisso (visita, retorno ou tarefa).
 *   { tipo, titulo?, inicio (datetime-local), observacao?, lead_id?, usuario_id?, mover_etapa? }
 *
 * Responsável: o próprio usuário, ou alguém do seu recorte
 * (rbac_usuarios_scope) quando um gestor agenda para a equipe. Com lead:
 * o lead precisa estar no escopo; "mover_etapa" leva o lead para a etapa
 * de categoria visita_agendada (só para visitas, só com editar_leads).
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/agenda.php';
require_once __DIR__ . '/../includes/funil.php';

$usuario = api_iniciar(null);
$empresaId = (int) $usuario['empresa_ativa_id'];

$tipo = (string) ($_POST['tipo'] ?? '');
if (!array_key_exists($tipo, agenda_tipos())) {
    api_erro('Escolha o tipo: visita, retorno ou tarefa.', 422);
}
$inicio = api_data_hora($_POST['inicio'] ?? null);
if (!$inicio) {
    api_erro('Informe a data e o horário.', 422);
}
$observacao = api_texto($_POST['observacao'] ?? null, 500);

$lead = null;
$leadId = (int) ($_POST['lead_id'] ?? 0);
if ($leadId) {
    $lead = api_lead($usuario, $leadId);
}

$responsavelId = (int) $usuario['id'];
$pedido = (int) ($_POST['usuario_id'] ?? 0);
if ($pedido && $pedido !== $responsavelId) {
    $scope = rbac_usuarios_scope($usuario);
    $stmt = db()->prepare('SELECT 1 FROM usuarios u WHERE u.id = :id AND u.ativo = 1 AND ' . $scope['sql']);
    $stmt->execute(['id' => $pedido] + $scope['params']);
    if (!$stmt->fetchColumn()) {
        api_erro('Responsável fora da sua equipe.', 404);
    }
    $responsavelId = $pedido;
} elseif ($lead && !$pedido && $lead['responsavel_id']) {
    $responsavelId = (int) $lead['responsavel_id'];
}

$titulo = api_texto($_POST['titulo'] ?? null) ?? (agenda_tipos()[$tipo] . ($lead ? ' — ' . $lead['nome'] : ''));

$pdo = db();
$pdo->beginTransaction();
$id = agenda_criar($empresaId, $lead ? (int) $lead['id'] : null, $responsavelId, $tipo, $titulo, $inicio, $observacao, (int) $usuario['id']);
$moveu = null;
if ($lead) {
    lead_registrar_evento((int) $lead['id'], (int) $usuario['id'], 'agenda', agenda_tipos()[$tipo] . ' ' . agenda_flexao($tipo, 'agendad') . ' para ' . date('d/m/Y \à\s H\hi', strtotime($inicio)) . ($observacao ? ' — ' . $observacao : ''));
    if ($tipo === 'retorno') {
        $pdo->prepare('UPDATE leads SET data_retorno = :d WHERE id = :id')->execute(['d' => substr($inicio, 0, 10), 'id' => $lead['id']]);
    }
    if ($tipo === 'visita' && !empty($_POST['mover_etapa']) && usuario_tem_permissao($usuario, 'editar_leads')) {
        $etapa = funil_etapa_por_categoria($empresaId, 'visita_agendada');
        if ($etapa) {
            if (lead_mudar_etapa($lead, (int) $etapa['id'], $usuario, 'visita agendada pela agenda')) {
                $moveu = $etapa['nome'];
            }
        }
    }
}
auditoria_registrar($empresaId, (int) $usuario['id'], 'agenda.criado', 'agenda', $id, ['tipo' => $tipo, 'lead_id' => $lead['id'] ?? null, 'responsavel_id' => $responsavelId]);
$pdo->commit();

api_responder(['ok' => true, 'id' => $id, 'mensagem' => agenda_tipos()[$tipo] . ' ' . agenda_flexao($tipo, 'agendad') . '.' . ($moveu ? ' Lead movido para "' . $moveu . '".' : '')]);
