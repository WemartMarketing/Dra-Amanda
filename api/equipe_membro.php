<?php
/**
 * POST /api/equipe_membro.php  { usuario_id, equipe_id }   (equipe_id vazio = sem equipe)
 * Coloca, move ou retira um usuário de uma equipe (usuarios.equipe_id).
 * Permissão: gerenciar_equipes.
 *
 * Etapa 2 só altera a estrutura. As regras de visibilidade de leads na
 * transferência (gerente anterior perde acesso etc. — seção 5) chegam na
 * Etapa 3; hoje os leads continuam com equipe_id/responsavel_id próprios.
 */
require_once __DIR__ . '/../includes/api.php';

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

db()->prepare('UPDATE usuarios SET equipe_id = :equipe_id WHERE id = :id AND empresa_id = :empresa_id')
    ->execute(['equipe_id' => $equipeId, 'id' => $membroId, 'empresa_id' => $empresaId]);

auditoria_registrar($empresaId, (int) $usuario['id'], 'usuario.equipe_alterada', 'usuario', $membroId, [
    'equipe_anterior_id' => $anterior, 'equipe_nova_id' => $equipeId,
]);
api_responder(['ok' => true]);
