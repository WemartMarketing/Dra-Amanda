<?php
/**
 * POST /api/importacao_enviar.php  (multipart: arquivo)
 * Passo 1 da importação: recebe a planilha, valida tamanho/formato/linhas
 * e guarda em storage/importacoes/ com um token aleatório ligado à sessão.
 * Permissão gerenciar_oferta_ativa.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/importacao.php';

$usuario = api_iniciar('gerenciar_oferta_ativa');
$arq = $_FILES['arquivo'] ?? null;
if (!$arq || !is_array($arq) || ($arq['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    api_erro('Escolha a planilha (CSV ou XLSX).', 422);
}
if (in_array($arq['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int) $arq['size'] > IMPORTACAO_MAX_BYTES) {
    api_erro('Arquivo maior que 5 MB.', 413);
}
if ($arq['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($arq['tmp_name'])) {
    api_erro('Falha no envio do arquivo. Tente de novo.', 422);
}
$nomeOriginal = mb_substr(basename(str_replace('\\', '/', (string) $arq['name'])), 0, 200);
$ext = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
if (!in_array($ext, ['csv', 'xlsx'], true)) {
    api_erro('Formato não aceito. Envie CSV ou XLSX (no Excel: Salvar como → CSV ou Pasta de Trabalho do Excel).', 422);
}
$inicio = (string) file_get_contents($arq['tmp_name'], false, null, 0, 4);
if ($ext === 'xlsx' && $inicio !== "PK\x03\x04") {
    api_erro('O arquivo não é um XLSX válido.', 422);
}

$token = bin2hex(random_bytes(16));
$destino = importacao_caminho($token, $ext);
if (!move_uploaded_file($arq['tmp_name'], $destino)) {
    api_erro('Não foi possível guardar o arquivo no servidor.', 500);
}
@chmod($destino, 0640);
$leitura = importacao_ler($destino, $ext);
if (!$leitura['ok']) {
    @unlink($destino);
    api_erro($leitura['erro'], 422);
}
$_SESSION['importacoes'][$token] = [
    'ext' => $ext, 'nome' => $nomeOriginal, 'empresa_id' => (int) $usuario['empresa_ativa_id'],
    'usuario_id' => (int) $usuario['id'], 'criado' => time(),
];
api_responder(['ok' => true, 'id' => $token, 'mensagem' => (count($leitura['linhas']) - 1) . ' linha(s) encontrada(s). Confira as colunas.']);
