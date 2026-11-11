<?php
/**
 * POST /api/roleta_empreendimento.php
 *   { roleta_id, empreendimento_id, acao: 'add'|'remover'|'prioridade', prioridade? }
 * Vincula/desvincula um empreendimento da roleta ou define a prioridade
 * dele nesta roleta (menor = tentada primeiro quando o empreendimento
 * está em mais de uma roleta — seção 13). Roleta e empreendimento
 * precisam ser da empresa do gestor.
 */
require_once __DIR__ . '/../includes/api.php';

$usuario = api_iniciar('gerenciar_roletas');
$empresaId = (int) $usuario['empresa_ativa_id'];
$roletaId = (int) ($_POST['roleta_id'] ?? 0);
$empreendimentoId = (int) ($_POST['empreendimento_id'] ?? 0);
$acao = (string) ($_POST['acao'] ?? '');

if (!$roletaId || !$empreendimentoId || !in_array($acao, ['add', 'remover', 'prioridade'], true)) {
    api_erro('Dados inválidos.', 422);
}
if (!api_registro_da_empresa('roletas', $roletaId, $empresaId)) {
    api_erro('Roleta não encontrada.', 404);
}
if (!api_registro_da_empresa('empreendimentos', $empreendimentoId, $empresaId)) {
    api_erro('Empreendimento não encontrado nesta empresa.', 404);
}

$prioridade = (int) ($_POST['prioridade'] ?? 100);
if ($prioridade < 1 || $prioridade > 999) {
    api_erro('A prioridade deve ser um número de 1 a 999 (menor = recebe primeiro).', 422);
}

if ($acao === 'add') {
    db()->prepare('INSERT IGNORE INTO roleta_empreendimentos (roleta_id, empreendimento_id, prioridade) VALUES (:r, :e, :p)')
        ->execute(['r' => $roletaId, 'e' => $empreendimentoId, 'p' => $prioridade]);
} elseif ($acao === 'prioridade') {
    db()->prepare('UPDATE roleta_empreendimentos SET prioridade = :p WHERE roleta_id = :r AND empreendimento_id = :e')
        ->execute(['p' => $prioridade, 'r' => $roletaId, 'e' => $empreendimentoId]);
} else {
    db()->prepare('DELETE FROM roleta_empreendimentos WHERE roleta_id = :r AND empreendimento_id = :e')
        ->execute(['r' => $roletaId, 'e' => $empreendimentoId]);
}
auditoria_registrar($empresaId, (int) $usuario['id'], 'roleta.empreendimento_' . $acao, 'roleta', $roletaId, ['empreendimento_id' => $empreendimentoId, 'prioridade' => $prioridade]);
api_responder(['ok' => true]);
