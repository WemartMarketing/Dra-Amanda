<?php
/**
 * POST /api/roleta_salvar.php  { id?, nome, raio_presenca_metros, horario_inicio, horario_fim, ativa }
 * Cria uma roleta nova (sem id) ou atualiza uma existente da própria
 * empresa. Apenas cargos de gestão (rbac_pode_gerenciar) — nunca corretor,
 * checado aqui de novo mesmo que a tela já esconda o formulário dele.
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
if (!rbac_pode_gerenciar($usuario)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Seu cargo não pode gerenciar roletas.']);
    exit;
}

$empresaId = (int) $usuario['empresa_ativa_id'];
$id = (int) ($_POST['id'] ?? 0);
$nome = trim($_POST['nome'] ?? '');
$raio = trim($_POST['raio_presenca_metros'] ?? '');
$raio = $raio === '' ? null : max(0, (int) $raio);
$horarioInicio = trim($_POST['horario_inicio'] ?? '') ?: null;
$horarioFim = trim($_POST['horario_fim'] ?? '') ?: null;
$ativa = !empty($_POST['ativa']) ? 1 : 0;

if ($nome === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'erro' => 'Informe um nome para a roleta.']);
    exit;
}

if ($id) {
    $stmt = db()->prepare('SELECT id FROM roletas WHERE id = :id AND empresa_id = :empresa_id');
    $stmt->execute(['id' => $id, 'empresa_id' => $empresaId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'erro' => 'Roleta não encontrada.']);
        exit;
    }
    db()->prepare('UPDATE roletas SET nome = :nome, raio_presenca_metros = :raio, horario_inicio = :hi, horario_fim = :hf, ativa = :ativa WHERE id = :id')
        ->execute(['nome' => $nome, 'raio' => $raio, 'hi' => $horarioInicio, 'hf' => $horarioFim, 'ativa' => $ativa, 'id' => $id]);
    echo json_encode(['ok' => true, 'id' => $id]);
} else {
    db()->prepare('INSERT INTO roletas (empresa_id, nome, ativa, raio_presenca_metros, horario_inicio, horario_fim) VALUES (:empresa_id, :nome, :ativa, :raio, :hi, :hf)')
        ->execute(['empresa_id' => $empresaId, 'nome' => $nome, 'ativa' => $ativa, 'raio' => $raio, 'hi' => $horarioInicio, 'hf' => $horarioFim]);
    echo json_encode(['ok' => true, 'id' => (int) db()->lastInsertId()]);
}
