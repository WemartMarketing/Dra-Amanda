<?php
/**
 * POST /api/empresa_criar.php  { empresa_nome, master_nome, master_email, master_senha }
 * Somente Superadmin WeMart. Cria a empresa, semeia as 7 funções
 * padrão (includes/funcoes.php — mesma fonte usada no backfill) e cria
 * o primeiro usuário já como Master da empresa.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/funcoes.php';
require_once __DIR__ . '/../includes/funil.php';

header('Content-Type: application/json; charset=utf-8');

if (!auth_check()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'Sessão expirada.']);
    exit;
}

$usuario = current_user();
if (empty($usuario['eh_superadmin'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Somente o Superadmin pode criar empresas.']);
    exit;
}

if (!csrf_validar_requisicao()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Token de segurança inválido.']);
    exit;
}

$empresaNome = trim($_POST['empresa_nome'] ?? '');
$masterNome = trim($_POST['master_nome'] ?? '');
$masterEmail = trim($_POST['master_email'] ?? '');
$masterSenha = (string) ($_POST['master_senha'] ?? '');

if ($empresaNome === '' || $masterNome === '' || $masterEmail === '' || $masterSenha === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'erro' => 'Preencha todos os campos.']);
    exit;
}

function gerarSlug(string $nome): string
{
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $nome), '-'));
    return $slug !== '' ? $slug : 'empresa';
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $slugBase = gerarSlug($empresaNome);
    $slug = $slugBase;
    $sufixo = 1;
    while (true) {
        $stmt = $pdo->prepare('SELECT id FROM empresas WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        if (!$stmt->fetch()) {
            break;
        }
        $slug = $slugBase . '-' . (++$sufixo);
    }

    $planoId = $pdo->query("SELECT id FROM planos WHERE nome = 'Padrão' LIMIT 1")->fetchColumn() ?: null;

    $pdo->prepare('INSERT INTO empresas (nome, slug, ativa, plano_id) VALUES (:nome, :slug, 1, :plano_id)')
        ->execute(['nome' => $empresaNome, 'slug' => $slug, 'plano_id' => $planoId]);
    $empresaId = (int) $pdo->lastInsertId();

    $mapaFuncoes = funcoes_seed_padrao($empresaId);
    funil_seed_padrao($empresaId);
    $funcaoMasterId = $mapaFuncoes['master_empresa'];

    $pdo->prepare('INSERT INTO usuarios (empresa_id, funcao_id, nome, email, senha_hash, cargo, ativo) VALUES (:empresa_id, :funcao_id, :nome, :email, :hash, :cargo, 1)')
        ->execute([
            'empresa_id' => $empresaId,
            'funcao_id' => $funcaoMasterId,
            'nome' => $masterNome,
            'email' => $masterEmail,
            'hash' => password_hash($masterSenha, PASSWORD_DEFAULT),
            'cargo' => 'master_empresa',
        ]);
    $masterUsuarioId = (int) $pdo->lastInsertId();

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    if ($e->getCode() === '23000') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'erro' => 'Já existe um usuário com este e-mail.']);
        exit;
    }
    throw $e;
}

auditoria_registrar($empresaId, (int) $usuario['id'], 'empresa.criada', 'empresa', $empresaId, ['nome' => $empresaNome, 'master_usuario_id' => $masterUsuarioId]);

echo json_encode(['ok' => true, 'empresa_id' => $empresaId]);
