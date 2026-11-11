<?php
/**
 * POST /api/equipe_salvar.php
 *   { acao: 'salvar', id?, nome, superintendencia_id?, gerente_id?, empreendimentos[] }
 *   { acao: 'excluir', id }   — só se a equipe não tiver membros
 * Permissão: gerenciar_equipes.
 */
require_once __DIR__ . '/../includes/api.php';

$usuario = api_iniciar('gerenciar_equipes');
$empresaId = (int) $usuario['empresa_ativa_id'];
$acao = $_POST['acao'] ?? 'salvar';
$id = (int) ($_POST['id'] ?? 0);

if ($id && !api_registro_da_empresa('equipes', $id, $empresaId)) {
    api_erro('Equipe não encontrada.', 404);
}

if ($acao === 'excluir') {
    if (!$id) {
        api_erro('Equipe não informada.', 422);
    }
    $stmt = db()->prepare('SELECT COUNT(*) FROM usuarios WHERE equipe_id = :id AND ativo = 1');
    $stmt->execute(['id' => $id]);
    if ((int) $stmt->fetchColumn() > 0) {
        api_erro('Mova os corretores desta equipe antes de excluí-la.', 422);
    }
    // Leads guardam equipe_id (base do escopo "Sua equipe"); excluir a
    // equipe tiraria esses leads da visão do gerente sem registro.
    $stmt = db()->prepare('SELECT COUNT(*) FROM leads WHERE equipe_id = :id');
    $stmt->execute(['id' => $id]);
    if ((int) $stmt->fetchColumn() > 0) {
        api_erro('Esta equipe tem leads vinculados e não pode ser excluída. Renomeie ou deixe sem membros.', 422);
    }
    db()->prepare('DELETE FROM equipes WHERE id = :id AND empresa_id = :empresa_id')->execute(['id' => $id, 'empresa_id' => $empresaId]);
    auditoria_registrar($empresaId, (int) $usuario['id'], 'equipe.excluida', 'equipe', $id);
    api_responder(['ok' => true]);
}

$nome = api_texto($_POST['nome'] ?? null);
if ($nome === null) {
    api_erro('Informe o nome da equipe.', 422);
}

$superintendenciaId = (int) ($_POST['superintendencia_id'] ?? 0) ?: null;
if ($superintendenciaId && !api_registro_da_empresa('superintendencias', $superintendenciaId, $empresaId)) {
    api_erro('Superintendência inválida.', 422);
}

$gerenteId = (int) ($_POST['gerente_id'] ?? 0) ?: null;
if ($gerenteId) {
    $stmt = db()->prepare("
        SELECT 1 FROM usuarios INNER JOIN funcoes ON funcoes.id = usuarios.funcao_id
        WHERE usuarios.id = :id AND usuarios.empresa_id = :empresa_id AND usuarios.ativo = 1 AND funcoes.escopo = 'equipe'
    ");
    $stmt->execute(['id' => $gerenteId, 'empresa_id' => $empresaId]);
    if (!$stmt->fetchColumn()) {
        api_erro('Escolha um usuário com função de gerente.', 422);
    }
}

$empreendimentos = api_ids_da_empresa('empreendimentos', $_POST['empreendimentos'] ?? [], $empresaId);

db()->beginTransaction();
if ($id) {
    db()->prepare('UPDATE equipes SET nome = :nome, superintendencia_id = :sid, gerente_id = :gid WHERE id = :id AND empresa_id = :empresa_id')
        ->execute(['nome' => $nome, 'sid' => $superintendenciaId, 'gid' => $gerenteId, 'id' => $id, 'empresa_id' => $empresaId]);
    $evento = 'equipe.editada';
} else {
    db()->prepare('INSERT INTO equipes (empresa_id, superintendencia_id, nome, gerente_id) VALUES (:empresa_id, :sid, :nome, :gid)')
        ->execute(['empresa_id' => $empresaId, 'sid' => $superintendenciaId, 'nome' => $nome, 'gid' => $gerenteId]);
    $id = (int) db()->lastInsertId();
    $evento = 'equipe.criada';
}
db()->prepare('DELETE FROM equipe_empreendimentos WHERE equipe_id = :id')->execute(['id' => $id]);
$ins = db()->prepare('INSERT INTO equipe_empreendimentos (equipe_id, empreendimento_id) VALUES (:equipe_id, :emp_id)');
foreach ($empreendimentos as $empId) {
    $ins->execute(['equipe_id' => $id, 'emp_id' => $empId]);
}
db()->commit();

auditoria_registrar($empresaId, (int) $usuario['id'], $evento, 'equipe', $id, [
    'nome' => $nome, 'superintendencia_id' => $superintendenciaId, 'gerente_id' => $gerenteId, 'empreendimentos' => $empreendimentos,
]);
api_responder(['ok' => true, 'id' => $id]);
