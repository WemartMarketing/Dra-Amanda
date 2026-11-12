<?php
/**
 * POST /api/roleta_horarios.php — expediente das roletas (Fase 6).
 *   acao=grade             { roleta_id, dias[1..7][aberto|inicio|fim|pausa_inicio|pausa_fim] }
 *   acao=excecao_salvar    { roleta_id, alcance=roleta|empresa, data, tipo=fechado|horario_especial, inicio?, fim?, descricao? }
 *   acao=excecao_remover   { id }
 * Só quem gerencia roletas. Depois de mudar o horário: quem ficou fora do
 * expediente sai da roleta e, se ela abriu, a fila de espera é processada.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/roleta.php';
require_once __DIR__ . '/../includes/auditoria.php';

$usuario = api_iniciar('gerenciar_roletas');
$empresaId = (int) $usuario['empresa_ativa_id'];
$uid = (int) $usuario['id'];
$acao = (string) ($_POST['acao'] ?? '');

$depois = function (?int $roletaId) use ($empresaId): string {
    roleta_fechar_expiradas($empresaId, $roletaId);
    $r = roleta_processar_fila($empresaId, $roletaId, 50);
    return $r['distribuidos'] ? ' ' . $r['distribuidos'] . ' lead(s) da fila de espera foram distribuídos.' : '';
};

if ($acao === 'excecao_remover') {
    $id = (int) ($_POST['id'] ?? 0);
    if (!roleta_excecao_remover($empresaId, $id)) {
        api_erro('Data não encontrada.', 404);
    }
    auditoria_registrar($empresaId, $uid, 'roleta.excecao_removida', 'roleta_excecao', $id, []);
    api_responder(['ok' => true, 'mensagem' => 'Data removida.' . $depois(null)]);
}

$roletaId = (int) ($_POST['roleta_id'] ?? 0);
if (!$roletaId || !api_registro_da_empresa('roletas', $roletaId, $empresaId)) {
    api_erro('Roleta não encontrada.', 404);
}

if ($acao === 'grade') {
    $v = roleta_grade_validar(is_array($_POST['dias'] ?? null) ? $_POST['dias'] : []);
    if (!$v['ok']) {
        api_erro($v['erro'], 422);
    }
    roleta_grade_salvar($empresaId, $roletaId, $v['linhas']);
    auditoria_registrar($empresaId, $uid, 'roleta.horarios', 'roleta', $roletaId, ['dias' => $v['linhas']]);
    api_responder(['ok' => true, 'mensagem' => 'Horários salvos.' . $depois($roletaId)]);
}

if ($acao === 'excecao_salvar') {
    $alcance = ($_POST['alcance'] ?? 'roleta') === 'empresa' ? null : $roletaId;
    $r = roleta_excecao_salvar($empresaId, $alcance, (string) ($_POST['data'] ?? ''), (string) ($_POST['tipo'] ?? ''),
        $_POST['inicio'] ?? null, $_POST['fim'] ?? null, api_texto($_POST['descricao'] ?? null));
    if (!$r['ok']) {
        api_erro($r['erro'], 422);
    }
    auditoria_registrar($empresaId, $uid, 'roleta.excecao_salva', 'roleta_excecao', $r['id'], ['roleta_id' => $alcance, 'data' => $_POST['data'] ?? null, 'tipo' => $_POST['tipo'] ?? null]);
    api_responder(['ok' => true, 'mensagem' => 'Data salva.' . $depois($alcance)]);
}

api_erro('Ação inválida.', 400);
