<?php
/**
 * POST /api/roleta_fila.php — fila de espera da roleta.
 *   { acao: 'processar', roleta_id? }   distribui os leads em espera (mais antigos primeiro)
 *   { acao: 'distribuir', lead_id }     tenta distribuir um lead específico agora
 * Permissão gerenciar_roletas ou redistribuir_leads; o lead precisa estar
 * no escopo de quem pede.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/roleta.php';

$usuario = api_iniciar(null);
if (!usuario_tem_permissao($usuario, 'gerenciar_roletas') && !usuario_tem_permissao($usuario, 'redistribuir_leads')) {
    api_erro('Sua função não pode distribuir leads.', 403);
}
$empresaId = (int) $usuario['empresa_ativa_id'];
$acao = (string) ($_POST['acao'] ?? '');

if ($acao === 'processar') {
    $roletaId = (int) ($_POST['roleta_id'] ?? 0) ?: null;
    if ($roletaId && !api_registro_da_empresa('roletas', $roletaId, $empresaId)) {
        api_erro('Roleta não encontrada.', 404);
    }
    $r = roleta_processar_fila($empresaId, $roletaId);
    auditoria_registrar($empresaId, (int) $usuario['id'], 'roleta.fila_processada', 'roleta', $roletaId, $r);
    $msg = $r['tentados'] === 0 ? 'Não há leads na fila.'
        : ($r['distribuidos'] . ' de ' . $r['tentados'] . ' lead(s) distribuído(s).' . ($r['distribuidos'] < $r['tentados'] ? ' Os demais seguem aguardando corretor disponível.' : ''));
    api_responder(['ok' => true, 'mensagem' => $msg] + $r);
}

if ($acao === 'distribuir') {
    $lead = api_lead($usuario, (int) ($_POST['lead_id'] ?? 0));
    if ($lead['responsavel_id']) {
        api_erro('Este lead já tem responsável.', 409);
    }
    $r = roleta_distribuir_lead((int) $lead['id'], 'reprocessamento', $usuario);
    $msg = $r['status'] === 'distribuido'
        ? 'Lead entregue a ' . $r['usuario_nome'] . ' pela roleta ' . $r['roleta_nome'] . '.'
        : ($r['status'] === 'fila' ? 'Ninguém disponível agora: o lead continua na fila. ' . $r['motivo'] . '.' : 'O lead já tinha responsável.');
    api_responder(['ok' => true, 'status' => $r['status'], 'mensagem' => $msg]);
}

api_erro('Ação inválida.', 422);
