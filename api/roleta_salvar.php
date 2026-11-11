<?php
/**
 * POST /api/roleta_salvar.php  { id?, nome, plantao_id?, horario_inicio, horario_fim, ativa }
 * Endereço, coordenadas e raio de presença vêm do plantão (Etapa 2); as
 * colunas antigas de raio/coordenadas da própria roleta não são mais
 * alteradas por aqui.
 * Cria uma roleta nova (sem id) ou atualiza uma existente da própria
 * empresa. Exige a permissão gerenciar_roletas, checada aqui de novo
 * mesmo que a tela já esconda o formulário de quem não a tem.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';

header('Content-Type: application/json; charset=utf-8');

if (!auth_check()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'Sessão expirada.']);
    exit;
}

$usuario = current_user();
if (!usuario_tem_permissao($usuario, 'gerenciar_roletas')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Sua função não pode gerenciar roletas.']);
    exit;
}

if (!csrf_validar_requisicao()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Token de segurança inválido.']);
    exit;
}

$empresaId = (int) $usuario['empresa_ativa_id'];
$id = (int) ($_POST['id'] ?? 0);
$nome = trim($_POST['nome'] ?? '');
$plantaoId = (int) ($_POST['plantao_id'] ?? 0) ?: null;
$horarioInicio = trim($_POST['horario_inicio'] ?? '') ?: null;
$horarioFim = trim($_POST['horario_fim'] ?? '') ?: null;
$ativa = !empty($_POST['ativa']) ? 1 : 0;

if ($nome === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'erro' => 'Informe um nome para a roleta.']);
    exit;
}

if ($plantaoId) {
    $stmt = db()->prepare('SELECT 1 FROM plantoes WHERE id = :id AND empresa_id = :empresa_id');
    $stmt->execute(['id' => $plantaoId, 'empresa_id' => $empresaId]);
    if (!$stmt->fetchColumn()) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'erro' => 'Plantão inválido.']);
        exit;
    }
}

if ($id) {
    $stmt = db()->prepare('SELECT id FROM roletas WHERE id = :id AND empresa_id = :empresa_id');
    $stmt->execute(['id' => $id, 'empresa_id' => $empresaId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'erro' => 'Roleta não encontrada.']);
        exit;
    }
    db()->prepare('UPDATE roletas SET nome = :nome, plantao_id = :plantao_id, horario_inicio = :hi, horario_fim = :hf, ativa = :ativa WHERE id = :id AND empresa_id = :empresa_id')
        ->execute(['nome' => $nome, 'plantao_id' => $plantaoId, 'hi' => $horarioInicio, 'hf' => $horarioFim, 'ativa' => $ativa, 'id' => $id, 'empresa_id' => $empresaId]);
    auditoria_registrar($empresaId, (int) $usuario['id'], 'roleta.editada', 'roleta', $id, ['nome' => $nome, 'plantao_id' => $plantaoId, 'ativa' => $ativa]);
    echo json_encode(['ok' => true, 'id' => $id]);
} else {
    db()->prepare('INSERT INTO roletas (empresa_id, plantao_id, nome, ativa, horario_inicio, horario_fim) VALUES (:empresa_id, :plantao_id, :nome, :ativa, :hi, :hf)')
        ->execute(['empresa_id' => $empresaId, 'plantao_id' => $plantaoId, 'nome' => $nome, 'ativa' => $ativa, 'hi' => $horarioInicio, 'hf' => $horarioFim]);
    $novoId = (int) db()->lastInsertId();
    auditoria_registrar($empresaId, (int) $usuario['id'], 'roleta.criada', 'roleta', $novoId, ['nome' => $nome, 'plantao_id' => $plantaoId]);
    echo json_encode(['ok' => true, 'id' => $novoId]);
}
