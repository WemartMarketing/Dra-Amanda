<?php
/**
 * POST /api/agenda_status.php  { id, acao, novo_inicio?, observacao?, mover_etapa? }
 *   acao: realizado | cliente_ausente | cancelar | reagendar
 * Só compromissos dentro de rbac_agenda_scope(). Reagendar cria um novo
 * compromisso (reagendado_de = original) e marca o original como
 * "reagendado". Visita realizada pode levar o lead para a etapa de
 * categoria visita_realizada.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/agenda.php';
require_once __DIR__ . '/../includes/funil.php';

$usuario = api_iniciar(null);
$empresaId = (int) $usuario['empresa_ativa_id'];
$compromisso = agenda_carregar($usuario, (int) ($_POST['id'] ?? 0));
if (!$compromisso) {
    api_erro('Compromisso não encontrado.', 404);
}
if ($compromisso['status'] !== 'agendado') {
    api_erro('Este compromisso já foi concluído.', 409);
}
$acao = (string) ($_POST['acao'] ?? '');
$observacao = api_texto($_POST['observacao'] ?? null, 500);
$leadId = $compromisso['lead_id'] ? (int) $compromisso['lead_id'] : null;
$lead = $leadId ? api_lead($usuario, $leadId) : null;
$tipoRotulo = agenda_tipos()[$compromisso['tipo']];

$pdo = db();
$pdo->beginTransaction();
$mensagem = '';
switch ($acao) {
    case 'realizado':
    case 'cliente_ausente':
    case 'cancelar':
        $status = $acao === 'cancelar' ? 'cancelado' : $acao;
        $pdo->prepare('UPDATE agenda_compromissos SET status = :status, concluido_em = NOW(), observacao = COALESCE(:obs, observacao) WHERE id = :id')
            ->execute(['status' => $status, 'obs' => $observacao, 'id' => $compromisso['id']]);
        $mensagem = $tipoRotulo . ': ' . mb_strtolower(agenda_status()[$status]) . '.';
        if ($lead) {
            $tipoEvento = $compromisso['tipo'] === 'visita' && $status !== 'cancelado' ? 'visita' : 'agenda';
            lead_registrar_evento($leadId, (int) $usuario['id'], $tipoEvento, $tipoRotulo . ' de ' . date('d/m H\hi', strtotime($compromisso['inicio'])) . ': ' . mb_strtolower(agenda_status()[$status]) . ($observacao ? ' — ' . $observacao : ''));
            if ($status === 'realizado' || $status === 'cliente_ausente') {
                lead_registrar_feedback($leadId);
            }
            if ($status === 'realizado' && $compromisso['tipo'] === 'visita' && !empty($_POST['mover_etapa']) && usuario_tem_permissao($usuario, 'editar_leads')) {
                $etapa = funil_etapa_por_categoria($empresaId, 'visita_realizada');
                if ($etapa && lead_mudar_etapa($lead, (int) $etapa['id'], $usuario, 'visita realizada')) {
                    $mensagem .= ' Lead movido para "' . $etapa['nome'] . '".';
                }
            }
        }
        break;

    case 'reagendar':
        $novoInicio = api_data_hora($_POST['novo_inicio'] ?? null);
        if (!$novoInicio) {
            $pdo->rollBack();
            api_erro('Informe a nova data e horário.', 422);
        }
        $pdo->prepare("UPDATE agenda_compromissos SET status = 'reagendado', concluido_em = NOW() WHERE id = :id")->execute(['id' => $compromisso['id']]);
        $novoId = agenda_criar($empresaId, $leadId, (int) $compromisso['usuario_id'], $compromisso['tipo'], $compromisso['titulo'], $novoInicio, $observacao ?? $compromisso['observacao'], (int) $usuario['id'], (int) $compromisso['id']);
        if ($lead) {
            lead_registrar_evento($leadId, (int) $usuario['id'], 'agenda', $tipoRotulo . ' ' . agenda_flexao($compromisso['tipo'], 'reagendad') . ' para ' . date('d/m/Y \à\s H\hi', strtotime($novoInicio)));
            if ($compromisso['tipo'] === 'retorno') {
                $pdo->prepare('UPDATE leads SET data_retorno = :d WHERE id = :id')->execute(['d' => substr($novoInicio, 0, 10), 'id' => $leadId]);
            }
        }
        $mensagem = $tipoRotulo . ' ' . agenda_flexao($compromisso['tipo'], 'reagendad') . ' para ' . date('d/m \à\s H\hi', strtotime($novoInicio)) . '.';
        break;

    default:
        $pdo->rollBack();
        api_erro('Ação inválida.', 422);
}
auditoria_registrar($empresaId, (int) $usuario['id'], 'agenda.' . $acao, 'agenda', (int) $compromisso['id'], ['lead_id' => $leadId]);
$pdo->commit();
api_responder(['ok' => true, 'mensagem' => $mensagem]);
