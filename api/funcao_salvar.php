<?php
/**
 * POST /api/funcao_salvar.php  { id?, nome, escopo, permissoes[] }
 * Cria uma função nova (sem id) ou atualiza as permissões/escopo/nome
 * de uma existente da própria empresa — incluindo as 7 funções padrão
 * (editar permissões é sempre permitido, não precisa de confirmação
 * extra nesta etapa).
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

$usuario = current_user();
if (!usuario_tem_permissao($usuario, 'administrar_usuarios')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Sua função não pode administrar funções.']);
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
$escopo = $_POST['escopo'] ?? '';
$permissoesEnviadas = $_POST['permissoes'] ?? [];

$escoposValidos = array_keys(funcoes_rotulos_escopo());
$permissoesValidas = array_column(funcoes_catalogo_permissoes(), 'chave');
$permissoes = array_values(array_intersect($permissoesEnviadas, $permissoesValidas));

if ($nome === '' || !in_array($escopo, $escoposValidos, true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'erro' => 'Informe nome e escopo válidos.']);
    exit;
}

try {
    if ($id) {
        $stmt = db()->prepare('SELECT id FROM funcoes WHERE id = :id AND empresa_id = :empresa_id');
        $stmt->execute(['id' => $id, 'empresa_id' => $empresaId]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'erro' => 'Função não encontrada.']);
            exit;
        }
        db()->prepare('UPDATE funcoes SET nome = :nome, escopo = :escopo WHERE id = :id')
            ->execute(['nome' => $nome, 'escopo' => $escopo, 'id' => $id]);
        $acao = 'funcao.editada';
    } else {
        db()->prepare('INSERT INTO funcoes (empresa_id, nome, escopo, padrao_sistema) VALUES (:empresa_id, :nome, :escopo, 0)')
            ->execute(['empresa_id' => $empresaId, 'nome' => $nome, 'escopo' => $escopo]);
        $id = (int) db()->lastInsertId();
        $acao = 'funcao.criada';
    }
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'erro' => 'Já existe uma função com este nome.']);
        exit;
    }
    throw $e;
}

db()->prepare('DELETE FROM funcao_permissoes WHERE funcao_id = :id')->execute(['id' => $id]);
foreach ($permissoes as $permissao) {
    db()->prepare('INSERT INTO funcao_permissoes (funcao_id, permissao_chave) VALUES (:funcao_id, :permissao)')
        ->execute(['funcao_id' => $id, 'permissao' => $permissao]);
}

auditoria_registrar($empresaId, (int) $usuario['id'], $acao, 'funcao', $id, ['nome' => $nome, 'escopo' => $escopo, 'permissoes' => $permissoes]);

echo json_encode(['ok' => true, 'id' => $id]);
