<?php
/**
 * POST /api/superintendencia_salvar.php
 *   { acao: 'salvar', id?, nome, superintendente_id? }
 *   { acao: 'excluir', id }   — só se não houver equipes nela
 * Permissão: gerenciar_equipes.
 */
require_once __DIR__ . '/../includes/api.php';

$usuario = api_iniciar('gerenciar_equipes');
$empresaId = (int) $usuario['empresa_ativa_id'];
$acao = $_POST['acao'] ?? 'salvar';
$id = (int) ($_POST['id'] ?? 0);

if ($id && !api_registro_da_empresa('superintendencias', $id, $empresaId)) {
    api_erro('Superintendência não encontrada.', 404);
}

if ($acao === 'excluir') {
    if (!$id) {
        api_erro('Superintendência não informada.', 422);
    }
    $stmt = db()->prepare('SELECT COUNT(*) FROM equipes WHERE superintendencia_id = :id');
    $stmt->execute(['id' => $id]);
    if ((int) $stmt->fetchColumn() > 0) {
        api_erro('Mova ou exclua as equipes desta superintendência antes de excluí-la.', 422);
    }
    db()->prepare('DELETE FROM superintendencias WHERE id = :id AND empresa_id = :empresa_id')->execute(['id' => $id, 'empresa_id' => $empresaId]);
    auditoria_registrar($empresaId, (int) $usuario['id'], 'superintendencia.excluida', 'superintendencia', $id);
    api_responder(['ok' => true]);
}

$nome = api_texto($_POST['nome'] ?? null);
if ($nome === null) {
    api_erro('Informe o nome da superintendência.', 422);
}

$superintendenteId = (int) ($_POST['superintendente_id'] ?? 0) ?: null;
if ($superintendenteId) {
    // Só usuários ativos desta empresa cuja função tem escopo "superintendencia".
    $stmt = db()->prepare("
        SELECT 1 FROM usuarios INNER JOIN funcoes ON funcoes.id = usuarios.funcao_id
        WHERE usuarios.id = :id AND usuarios.empresa_id = :empresa_id AND usuarios.ativo = 1 AND funcoes.escopo = 'superintendencia'
    ");
    $stmt->execute(['id' => $superintendenteId, 'empresa_id' => $empresaId]);
    if (!$stmt->fetchColumn()) {
        api_erro('Escolha um usuário com função de superintendente.', 422);
    }
}

if ($id) {
    db()->prepare('UPDATE superintendencias SET nome = :nome, superintendente_id = :sid WHERE id = :id AND empresa_id = :empresa_id')
        ->execute(['nome' => $nome, 'sid' => $superintendenteId, 'id' => $id, 'empresa_id' => $empresaId]);
    $evento = 'superintendencia.editada';
} else {
    db()->prepare('INSERT INTO superintendencias (empresa_id, nome, superintendente_id) VALUES (:empresa_id, :nome, :sid)')
        ->execute(['empresa_id' => $empresaId, 'nome' => $nome, 'sid' => $superintendenteId]);
    $id = (int) db()->lastInsertId();
    $evento = 'superintendencia.criada';
}

auditoria_registrar($empresaId, (int) $usuario['id'], $evento, 'superintendencia', $id, ['nome' => $nome, 'superintendente_id' => $superintendenteId]);
api_responder(['ok' => true, 'id' => $id]);
