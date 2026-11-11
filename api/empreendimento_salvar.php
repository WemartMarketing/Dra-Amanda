<?php
/**
 * POST (multipart) /api/empreendimento_salvar.php
 *   { id?, nome, construtora, endereco, bairro, cidade, estado, status_comercial,
 *     descricao, ativo?, imagem (arquivo opcional), remover_imagem? }
 * Permissão: gerenciar_empreendimentos. Nunca exclui de fato (leads
 * referenciam o empreendimento) — "ativo = 0" tira das listas de seleção.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/estrutura.php';

$usuario = api_iniciar('gerenciar_empreendimentos');
$empresaId = (int) $usuario['empresa_ativa_id'];
$id = (int) ($_POST['id'] ?? 0);

$atual = null;
if ($id) {
    $stmt = db()->prepare('SELECT * FROM empreendimentos WHERE id = :id AND empresa_id = :empresa_id');
    $stmt->execute(['id' => $id, 'empresa_id' => $empresaId]);
    $atual = $stmt->fetch();
    if (!$atual) {
        api_erro('Empreendimento não encontrado.', 404);
    }
}

$nome = api_texto($_POST['nome'] ?? null);
if ($nome === null) {
    api_erro('Informe o nome do empreendimento.', 422);
}
$estado = strtoupper(trim((string) ($_POST['estado'] ?? '')));
if ($estado !== '' && !in_array($estado, estados_brasil(), true)) {
    api_erro('Estado inválido.', 422);
}
$status = (string) ($_POST['status_comercial'] ?? 'lancamento');
if (!array_key_exists($status, empreendimento_status_rotulos())) {
    api_erro('Status comercial inválido.', 422);
}

$dados = [
    'nome'             => $nome,
    'construtora'      => api_texto($_POST['construtora'] ?? null),
    'endereco'         => api_texto($_POST['endereco'] ?? null, 255),
    'bairro'           => api_texto($_POST['bairro'] ?? null, 120),
    'cidade'           => api_texto($_POST['cidade'] ?? null, 120),
    'estado'           => $estado !== '' ? $estado : null,
    'status_comercial' => $status,
    'descricao'        => api_texto($_POST['descricao'] ?? null, 5000),
    'ativo'            => $id ? (!empty($_POST['ativo']) ? 1 : 0) : 1,
];

$upload = empreendimento_salvar_imagem('imagem');
if (!$upload['ok']) {
    api_erro($upload['erro'], 422);
}

$imagemAnterior = $atual['imagem'] ?? null;
$imagemFinal = $imagemAnterior;
if ($upload['arquivo']) {
    $imagemFinal = $upload['arquivo'];
} elseif (!empty($_POST['remover_imagem'])) {
    $imagemFinal = null;
}
$dados['imagem'] = $imagemFinal;

if ($id) {
    $sets = implode(', ', array_map(fn ($c) => "{$c} = :{$c}", array_keys($dados)));
    db()->prepare("UPDATE empreendimentos SET {$sets} WHERE id = :id AND empresa_id = :empresa_id")
        ->execute($dados + ['id' => $id, 'empresa_id' => $empresaId]);
    $evento = 'empreendimento.editado';
} else {
    $colunas = implode(', ', array_keys($dados));
    $valores = implode(', ', array_map(fn ($c) => ":{$c}", array_keys($dados)));
    db()->prepare("INSERT INTO empreendimentos (empresa_id, {$colunas}) VALUES (:empresa_id, {$valores})")
        ->execute($dados + ['empresa_id' => $empresaId]);
    $id = (int) db()->lastInsertId();
    $evento = 'empreendimento.criado';
}

if ($imagemAnterior && $imagemAnterior !== $imagemFinal) {
    empreendimento_remover_imagem($imagemAnterior);
}

auditoria_registrar($empresaId, (int) $usuario['id'], $evento, 'empreendimento', $id, [
    'nome' => $nome, 'status_comercial' => $status, 'ativo' => $dados['ativo'], 'imagem_alterada' => $imagemAnterior !== $imagemFinal,
]);
api_responder(['ok' => true, 'id' => $id]);
