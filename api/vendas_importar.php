<?php
/**
 * POST /api/vendas_importar.php — Inteligência de Vendas, importação.
 *   acao=enviar      (multipart: arquivo)          → guarda temporário ligado à sessão; devolve o token
 *   acao=mapear      token, col_<campo>             → ajusta o mapeamento de colunas
 *   acao=confirmar   token, situacoes[], atualizar_alteradas → importa; devolve o id do lote
 *   acao=reprocessar lote_id                        → reimporta o original guardado
 * Permissão importar_vendas. A empresa é SEMPRE a empresa ativa da sessão.
 * Nada aqui altera leads, Kanban ou automações.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/vendas/cruzamento.php';

$usuario = api_iniciar('importar_vendas');
$empresaId = (int) $usuario['empresa_ativa_id'];
if (!vendas_instalado()) {
    api_erro('Rode a migração 017 antes de importar vendas.', 409);
}
$acao = (string) ($_POST['acao'] ?? '');

$sessaoDoToken = function () use ($usuario, $empresaId): array {
    $token = (string) ($_POST['token'] ?? '');
    $s = $_SESSION['vendas_importacoes'][$token] ?? null;
    if (!preg_match('/^[a-f0-9]{32}$/', $token) || !$s || (int) $s['empresa_id'] !== $empresaId || (int) $s['usuario_id'] !== (int) $usuario['id']) {
        api_erro('Importação não encontrada ou expirada. Envie a planilha de novo.', 404);
    }
    $caminho = importacao_caminho($token, $s['ext']);
    if (!$caminho || !is_file($caminho)) {
        unset($_SESSION['vendas_importacoes'][$token]);
        api_erro('O arquivo temporário expirou (1 hora). Envie a planilha de novo.', 410);
    }
    return [$token, $s, $caminho];
};

// Passo 8 do fluxo: cruza as vendas do lote com o CRM logo após importar. Se falhar, a importação continua válida.
$cruzarLote = function (int $loteId) use ($empresaId): string {
    if (!vendas_cruzamento_instalado()) {
        return 'Cruzamento: rode a migração 018.';
    }
    try {
        $r = vendas_cruzar($empresaId, $loteId);
        return 'Cruzamento feito em ' . $r['vendas'] . ' venda(s).';
    } catch (Throwable $e) {
        error_log('[vendas_cruzar] ' . $e->getMessage());
        return 'O cruzamento não rodou agora; use "Recalcular cruzamento" na Conciliação.';
    }
};

switch ($acao) {
    case 'enviar':
        $arq = $_FILES['arquivo'] ?? null;
        if (!$arq || !is_array($arq) || ($arq['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            api_erro('Escolha a planilha (XLSX ou CSV).', 422);
        }
        if (in_array($arq['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int) $arq['size'] > IMPORTACAO_MAX_BYTES) {
            api_erro('Arquivo maior que 5 MB.', 413);
        }
        if ($arq['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($arq['tmp_name'])) {
            api_erro('Falha no envio do arquivo. Tente de novo.', 422);
        }
        $nome = mb_substr(basename(str_replace('\\', '/', (string) $arq['name'])), 0, 200);
        $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx'], true)) {
            api_erro('Formato não aceito. Envie XLSX ou CSV.', 422);
        }
        if ($ext === 'xlsx' && (string) file_get_contents($arq['tmp_name'], false, null, 0, 4) !== "PK\x03\x04") {
            api_erro('O arquivo não é um XLSX válido.', 422);
        }
        $sistema = (string) ($_POST['sistema'] ?? VENDAS_SISTEMA_HM);
        if (!isset(vendas_sistemas()[$sistema])) {
            api_erro('Sistema de origem inválido.', 422);
        }
        $token = bin2hex(random_bytes(16));
        $destino = importacao_caminho($token, $ext);
        if (!move_uploaded_file($arq['tmp_name'], $destino)) {
            api_erro('Não foi possível guardar o arquivo no servidor.', 500);
        }
        @chmod($destino, 0640);
        $leitura = vendas_ler_arquivo($destino, $ext);
        if (!$leitura['ok']) {
            @unlink($destino);
            api_erro($leitura['erro'], 422);
        }
        $_SESSION['vendas_importacoes'][$token] = [
            'ext' => $ext, 'nome' => $nome, 'sistema' => $sistema, 'empresa_id' => $empresaId, 'usuario_id' => (int) $usuario['id'], 'criado' => time(),
            'mapa' => vendas_mapear_cabecalho($leitura['cabecalho'])['mapa'],
        ];
        api_responder(['ok' => true, 'id' => $token, 'mensagem' => count($leitura['linhas']) . ' linha(s) encontrada(s). Confira a prévia.']);

    case 'mapear':
        [$token, $s, $caminho] = $sessaoDoToken();
        $leitura = vendas_ler_arquivo($caminho, $s['ext']);
        if (!$leitura['ok']) {
            api_erro($leitura['erro'], 422);
        }
        $entrada = [];
        foreach (array_keys(vendas_campos()) as $c) {
            $entrada[$c] = (string) ($_POST['col_' . $c] ?? '');
        }
        [$mapa, $erro] = vendas_mapa_validar($entrada, count($leitura['cabecalho']));
        if ($erro) {
            api_erro($erro, 422);
        }
        $_SESSION['vendas_importacoes'][$token]['mapa'] = $mapa;
        api_responder(['ok' => true, 'mensagem' => 'Colunas atualizadas. A prévia foi recalculada.']);

    case 'confirmar':
        [$token, $s, $caminho] = $sessaoDoToken();
        $leitura = vendas_ler_arquivo($caminho, $s['ext']);
        if (!$leitura['ok']) {
            api_erro($leitura['erro'], 422);
        }
        [$mapa, $erro] = vendas_mapa_validar(array_map('strval', $s['mapa']), count($leitura['cabecalho']));
        if ($erro) {
            api_erro($erro, 422);
        }
        $sits = array_values(array_unique(array_filter(array_map(fn ($x) => vendas_situacao_chave((string) $x), (array) ($_POST['situacoes'] ?? [])))));
        if (!$sits) {
            api_erro('Marque ao menos uma situação que representa venda concluída (ex.: "Vendido").', 422);
        }
        try {
            $loteId = vendas_importar($usuario, ['caminho' => $caminho, 'ext' => $s['ext'], 'nome' => $s['nome']], $mapa, $sits, !empty($_POST['atualizar_alteradas']));
        } catch (Throwable $e) {
            error_log('[vendas_importar] ' . $e->getMessage());
            api_erro('A importação falhou e nada foi gravado: ' . mb_substr($e->getMessage(), 0, 200), 500);
        }
        @unlink($caminho);
        unset($_SESSION['vendas_importacoes'][$token]);
        api_responder(['ok' => true, 'id' => $loteId, 'mensagem' => 'Importação concluída. ' . $cruzarLote($loteId)]);

    case 'reprocessar':
        $loteId = (int) ($_POST['lote_id'] ?? 0);
        if (!vendas_lote_carregar($empresaId, $loteId)) {
            api_erro('Lote não encontrado.', 404);
        }
        try {
            $novo = vendas_reprocessar($usuario, $loteId);
        } catch (Throwable $e) {
            api_erro(mb_substr($e->getMessage(), 0, 250), 422);
        }
        api_responder(['ok' => true, 'id' => $novo, 'mensagem' => 'Lote reprocessado. ' . $cruzarLote($novo)]);

    default:
        api_erro('Ação inválida.', 422);
}
