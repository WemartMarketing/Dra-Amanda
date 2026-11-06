<?php
/**
 * POST /api/usuario_salvar.php  { id?, nome, email, senha?, cargo, equipe_id?, ativo? }
 * Cria ou atualiza um usuário da própria empresa. Nunca permite definir
 * cargo 'master_wemart' por aqui (é um cargo de plataforma, fora do
 * escopo de gestão de uma empresa) nem vincular a outra empresa.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';

header('Content-Type: application/json; charset=utf-8');

if (!auth_check()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'Sessão expirada.']);
    exit;
}

$usuarioLogado = current_user();
if (!rbac_pode_gerenciar($usuarioLogado)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Seu cargo não pode gerenciar usuários.']);
    exit;
}

$empresaId = (int) $usuarioLogado['empresa_ativa_id'];
$id = (int) ($_POST['id'] ?? 0);
$nome = trim($_POST['nome'] ?? '');
$email = trim($_POST['email'] ?? '');
$senha = (string) ($_POST['senha'] ?? '');
$cargo = $_POST['cargo'] ?? '';
$equipeId = isset($_POST['equipe_id']) && $_POST['equipe_id'] !== '' ? (int) $_POST['equipe_id'] : null;
$ativo = !empty($_POST['ativo']) ? 1 : 0;

$cargosPermitidos = ['master_empresa', 'gp_lider', 'gerente_geral', 'gp_produto', 'superintendente', 'gerente', 'corretor'];
if ($nome === '' || $email === '' || !in_array($cargo, $cargosPermitidos, true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'erro' => 'Preencha nome, e-mail e cargo.']);
    exit;
}
if (!$id && $senha === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'erro' => 'Defina uma senha inicial para o novo usuário.']);
    exit;
}

if ($equipeId) {
    $stmt = db()->prepare('SELECT id FROM equipes WHERE id = :id AND empresa_id = :empresa_id');
    $stmt->execute(['id' => $equipeId, 'empresa_id' => $empresaId]);
    if (!$stmt->fetch()) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'erro' => 'Equipe inválida.']);
        exit;
    }
}

try {
    if ($id) {
        $stmt = db()->prepare('SELECT id FROM usuarios WHERE id = :id AND empresa_id = :empresa_id');
        $stmt->execute(['id' => $id, 'empresa_id' => $empresaId]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'erro' => 'Usuário não encontrado.']);
            exit;
        }
        if ($senha !== '') {
            db()->prepare('UPDATE usuarios SET nome = :nome, email = :email, senha_hash = :hash, cargo = :cargo, equipe_id = :equipe_id, ativo = :ativo WHERE id = :id')
                ->execute(['nome' => $nome, 'email' => $email, 'hash' => password_hash($senha, PASSWORD_DEFAULT), 'cargo' => $cargo, 'equipe_id' => $equipeId, 'ativo' => $ativo, 'id' => $id]);
        } else {
            db()->prepare('UPDATE usuarios SET nome = :nome, email = :email, cargo = :cargo, equipe_id = :equipe_id, ativo = :ativo WHERE id = :id')
                ->execute(['nome' => $nome, 'email' => $email, 'cargo' => $cargo, 'equipe_id' => $equipeId, 'ativo' => $ativo, 'id' => $id]);
        }
    } else {
        db()->prepare('INSERT INTO usuarios (empresa_id, equipe_id, nome, email, senha_hash, cargo, ativo) VALUES (:empresa_id, :equipe_id, :nome, :email, :hash, :cargo, 1)')
            ->execute(['empresa_id' => $empresaId, 'equipe_id' => $equipeId, 'nome' => $nome, 'email' => $email, 'hash' => password_hash($senha, PASSWORD_DEFAULT), 'cargo' => $cargo]);
    }
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'erro' => 'Já existe um usuário com este e-mail.']);
        exit;
    }
    throw $e;
}

echo json_encode(['ok' => true]);
