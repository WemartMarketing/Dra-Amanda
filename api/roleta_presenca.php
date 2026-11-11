<?php
/**
 * POST /api/roleta_presenca.php — check-in do corretor LOGADO na roleta.
 *   { roleta_id, acao: 'verificar'|'entrar'|'renovar'|'sair', latitude, longitude, precisao }
 * O usuário vem sempre da sessão (nunca do formulário); só funciona para
 * roletas às quais ele está vinculado. Quem decide se está dentro do raio
 * é o servidor (includes/roleta.php), a partir das coordenadas.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/roleta.php';

$usuario = api_iniciar('participar_roleta');
$roletaId = (int) ($_POST['roleta_id'] ?? 0);
$acao = (string) ($_POST['acao'] ?? '');
if (!$roletaId || !in_array($acao, ['verificar', 'entrar', 'renovar', 'sair'], true)) {
    api_erro('Dados inválidos.', 422);
}

$res = roleta_registrar_presenca($usuario, $roletaId, $acao, $_POST['latitude'] ?? null, $_POST['longitude'] ?? null, $_POST['precisao'] ?? null);
if (!$res['ok']) {
    api_erro($res['erro'], $res['status']);
}

$roleta = roleta_carregar((int) $usuario['empresa_ativa_id'], $roletaId);
$sessao = $res['sessao'];
$naRoleta = $sessao && roleta_sessao_valida($sessao, $roleta);
$posicao = null;
if ($naRoleta) {
    $fila = array_map('intval', array_column(roleta_elegiveis($roleta), 'usuario_id'));
    $p = array_search((int) $usuario['id'], $fila, true);
    $posicao = $p === false ? null : $p + 1;
}
$av = $res['avaliacao'];
api_responder([
    'ok' => true,
    'pode' => $av ? $av['pode'] : false,
    'codigo' => $av['codigo'] ?? 'saiu',
    'mensagem' => $av['mensagem'] ?? 'Você saiu da roleta.',
    'distancia' => $av && $av['distancia'] !== null ? (int) round($av['distancia']) : null,
    'na_roleta' => $naRoleta,
    'desde' => $naRoleta ? date('H:i', strtotime($sessao['entrou_em'])) : null,
    'posicao' => $posicao,
    'recebidos_hoje' => roleta_recebidos_hoje($roletaId, (int) $usuario['id'])[(int) $usuario['id']] ?? 0,
    'intervalo_min' => (int) $roleta['intervalo_verificacao_min'],
    'validade_min' => (int) $roleta['validade_presenca_min'],
    'distribuidos' => $res['distribuidos'],
]);
