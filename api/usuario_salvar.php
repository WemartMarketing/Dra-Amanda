<?php
/**
 * POST /api/usuario_salvar.php  { id?, nome, email, senha?, funcao_id, equipe_id?, ativo? }
 * Cria ou atualiza um usuário da própria empresa. Nunca cria/edita um
 * Superadmin por aqui (fora do escopo de gestão de uma empresa — ver
 * api/empresa_criar.php) nem vincula a outra empresa.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/funcoes.php';

header('Content-Type: application/json; charset=utf-8');

if (!auth_check()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'Sessão expirada.']);
    exit;
}

$usuarioLogado = current_user();
if (!usuario_tem_permissao($usuarioLogado, 'administrar_usuarios')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Sua função não pode gerenciar usuários.']);
    exit;
}

if (!csrf_validar_requisicao()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Token de segurança inválido.']);
    exit;
}

$empresaId = (int) $usuarioLogado['empresa_ativa_id'];
$id = (int) ($_POST['id'] ?? 0);
$nome = trim($_POST['nome'] ?? '');
$email = trim($_POST['email'] ?? '');
$senha = (string) ($_POST['senha'] ?? '');
$funcaoId = (int) ($_POST['funcao_id'] ?? 0);
$equipeId = isset($_POST['equipe_id']) && $_POST['equipe_id'] !== '' ? (int) $_POST['equipe_id'] : null;
$ativo = !empty($_POST['ativo']) ? 1 : 0;

if ($nome === '' || $email === '' || !$funcaoId) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'erro' => 'Preencha nome, e-mail e função.']);
    exit;
}
if (!$id && $senha === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'erro' => 'Defina uma senha inicial para o novo usuário.']);
    exit;
}

$stmt = db()->prepare('SELECT escopo FROM funcoes WHERE id = :id AND empresa_id = :empresa_id');
$stmt->execute(['id' => $funcaoId, 'empresa_id' => $empresaId]);
$escopoFuncao = $stmt->fetchColumn();
if ($escopoFuncao === false) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'erro' => 'Função inválida.']);
    exit;
}
$cargoLegado = escopo_cargo_legado($escopoFuncao); // só mantém a coluna legada preenchida — nunca lida pelo RBAC

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
            db()->prepare('UPDATE usuarios SET nome = :nome, email = :email, senha_hash = :hash, cargo = :cargo, funcao_id = :funcao_id, equipe_id = :equipe_id, ativo = :ativo WHERE id = :id')
                ->execute(['nome' => $nome, 'email' => $email, 'hash' => password_hash($senha, PASSWORD_DEFAULT), 'cargo' => $cargoLegado, 'funcao_id' => $funcaoId, 'equipe_id' => $equipeId, 'ativo' => $ativo, 'id' => $id]);
        } else {
            db()->prepare('UPDATE usuarios SET nome = :nome, email = :email, cargo = :cargo, funcao_id = :funcao_id, equipe_id = :equipe_id, ativo = :ativo WHERE id = :id')
                ->execute(['nome' => $nome, 'email' => $email, 'cargo' => $cargoLegado, 'funcao_id' => $funcaoId, 'equipe_id' => $equipeId, 'ativo' => $ativo, 'id' => $id]);
        }
        $acao = 'usuario.editado';
    } else {
        db()->prepare('INSERT INTO usuarios (empresa_id, equipe_id, nome, email, senha_hash, cargo, funcao_id, ativo) VALUES (:empresa_id, :equipe_id, :nome, :email, :hash, :cargo, :funcao_id, 1)')
            ->execute(['empresa_id' => $empresaId, 'equipe_id' => $equipeId, 'nome' => $nome, 'email' => $email, 'hash' => password_hash($senha, PASSWORD_DEFAULT), 'cargo' => $cargoLegado, 'funcao_id' => $funcaoId]);
        $id = (int) db()->lastInsertId();
        $acao = 'usuario.criado';
    }
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'erro' => 'Já existe um usuário com este e-mail.']);
        exit;
    }
    throw $e;
}

auditoria_registrar($empresaId, (int) $usuarioLogado['id'], $acao, 'usuario', $id, ['funcao_id' => $funcaoId, 'ativo' => $ativo]);

echo json_encode(['ok' => true]);
