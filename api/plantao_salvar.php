<?php
/**
 * POST /api/plantao_salvar.php (multipart)
 *   { id?, nome, endereco, cep, complemento, bairro, cidade, estado, latitude, longitude,
 *     raio_metros, horario_descricao, observacoes, ativo?, empreendimentos[], roletas[],
 *     usuarios[], foto (arquivo opcional), remover_foto? }
 * Permissão: gerenciar_plantoes. Sem exclusão física (roletas e histórico
 * apontam para o plantão); desativar com "ativo". Regras em includes/plantoes.php.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/plantoes.php';

$usuario = api_iniciar('gerenciar_plantoes');
if (!plantao_modulo_ok()) {
    api_erro('Atualização do banco pendente: rode a migração 012 (plantões).', 409);
}
$id = (int) ($_POST['id'] ?? 0) ?: null;

$foto = null;   // null = manter
$upload = empreendimento_salvar_imagem('foto', PLANTAO_FOTO_DIR);
if (!$upload['ok']) {
    api_erro($upload['erro'], 422);
}
if ($upload['arquivo']) {
    $foto = $upload['arquivo'];
} elseif (!empty($_POST['remover_foto'])) {
    $foto = '';
}
$r = plantao_salvar($usuario, $id, $_POST, $foto);
if (!$r['ok']) {
    if ($upload['arquivo']) {
        @unlink(PLANTAO_FOTO_DIR . '/' . $upload['arquivo']);
    }
    api_erro($r['erro'], $r['erro'] === 'Plantão não encontrado.' ? 404 : 422);
}
if ($foto !== null && $r['foto_anterior'] && $r['foto_anterior'] !== $foto && preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $r['foto_anterior'])) {
    @unlink(PLANTAO_FOTO_DIR . '/' . $r['foto_anterior']);
}
api_responder(['ok' => true, 'id' => $r['id'], 'mensagem' => 'Plantão salvo.']);
