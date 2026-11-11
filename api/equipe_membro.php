<?php
/**
 * POST /api/equipe_membro.php  { usuario_id, equipe_id }   (equipe_id vazio = sem equipe)
 * Coloca, move ou retira um usuário de uma equipe (usuarios.equipe_id).
 * Permissão: gerenciar_equipes.
 *
 * Os leads ABERTOS do usuário acompanham-no para a nova equipe (seção 5):
 * leads.equipe_id muda e cada um gera uma linha em lead_transferencias
 * (motivo mudanca_equipe), então o gerente/superintendência anteriores
 * deixam de vê-los e ficam só com o indicador agregado. Leads encerrados
 * (contrato assinado, sem interesse, perdido) ficam com a equipe em que
 * foram trabalhados, preservando o histórico de resultados.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/transferencia.php';

$usuario = api_iniciar('gerenciar_equipes');
$empresaId = (int) $usuario['empresa_ativa_id'];
$membroId = (int) ($_POST['usuario_id'] ?? 0);
$equipeId = (int) ($_POST['equipe_id'] ?? 0) ?: null;

if (!api_registro_da_empresa('usuarios', $membroId, $empresaId)) {
    api_erro('Usuário não encontrado.', 404);
}
if ($equipeId && !api_registro_da_empresa('equipes', $equipeId, $empresaId)) {
    api_erro('Equipe não encontrada.', 404);
}

$stmt = db()->prepare('SELECT equipe_id FROM usuarios WHERE id = :id');
$stmt->execute(['id' => $membroId]);
$anterior = $stmt->fetchColumn();
$anterior = $anterior !== null && $anterior !== false ? (int) $anterior : null;

if ($anterior === $equipeId) {
    api_responder(['ok' => true]);
}

$pdo = db();
$pdo->beginTransaction();
$pdo->prepare('UPDATE usuarios SET equipe_id = :equipe_id WHERE id = :id AND empresa_id = :empresa_id')
    ->execute(['equipe_id' => $equipeId, 'id' => $membroId, 'empresa_id' => $empresaId]);

$leadsMovidos = transferencia_acompanhar_mudanca_equipe($empresaId, $membroId, $usuario);
$pdo->commit();

auditoria_registrar($empresaId, (int) $usuario['id'], 'usuario.equipe_alterada', 'usuario', $membroId, [
    'equipe_anterior_id' => $anterior, 'equipe_nova_id' => $equipeId, 'leads_acompanharam' => $leadsMovidos,
]);
api_responder(['ok' => true, 'leads_movidos' => $leadsMovidos]);
