<?php
/**
 * POST /api/lead_contrato.php — registro formal de contrato (migração 013).
 *   acao=registrar  { lead_id, assinado_em, valor_vgv?, unidade?, observacao?, mover_etapa? }   editar_leads
 *   acao=editar     { id, assinado_em, valor_vgv?, unidade?, observacao? }                    editar_leads (só "aguardando validação")
 *   acao=validar    { id }                                                                    validar_contratos
 *   acao=distratar  { id, distratado_em, motivo }                                             validar_contratos
 * O lead precisa estar no escopo de quem faz a ação (mesma regra da ficha).
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/contratos.php';

$usuario = api_iniciar(null);
if (!contratos_instalados()) {
    api_erro('O registro de contratos ainda não foi instalado (migração 013).', 503);
}
$empresaId = (int) $usuario['empresa_ativa_id'];
$acao = (string) ($_POST['acao'] ?? '');
$exigir = function (string $perm) use ($usuario) {
    if (!usuario_tem_permissao($usuario, $perm)) {
        api_erro('Sua função não tem permissão para esta ação.', 403);
    }
};

if ($acao === 'registrar') {
    $exigir('editar_leads');
    $lead = api_lead($usuario, (int) ($_POST['lead_id'] ?? 0));
    $r = contrato_registrar($usuario, $lead, $_POST, !empty($_POST['mover_etapa']));
    if (!$r['ok']) {
        api_erro($r['erro'], 422);
    }
    api_responder(['ok' => true, 'id' => $r['id'], 'mensagem' => 'Contrato registrado. Ele conta como venda validada depois da validação.' . ($r['movido'] ? ' Lead movido para "Contrato assinado".' : '')]);
}

$contrato = contrato_carregar($empresaId, (int) ($_POST['id'] ?? 0));
if (!$contrato) {
    api_erro('Contrato não encontrado.', 404);
}
api_lead($usuario, (int) $contrato['lead_id']); // escopo: só quem enxerga o lead

switch ($acao) {
    case 'editar':
        $exigir('editar_leads');
        $r = contrato_editar($usuario, $contrato, $_POST);
        $msg = 'Contrato corrigido.';
        break;
    case 'validar':
        $exigir('validar_contratos');
        $r = contrato_validar($usuario, $contrato);
        $msg = 'Contrato validado.';
        break;
    case 'distratar':
        $exigir('validar_contratos');
        $r = contrato_distratar($usuario, $contrato, (string) ($_POST['distratado_em'] ?? ''), (string) ($_POST['motivo'] ?? ''));
        $msg = 'Distrato registrado.';
        break;
    default:
        api_erro('Ação inválida.', 422);
}
if (!$r['ok']) {
    api_erro($r['erro'], 422);
}
api_responder(['ok' => true, 'mensagem' => $msg]);
