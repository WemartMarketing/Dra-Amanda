<?php
/**
 * POST /api/campanhas.php — campanhas de remarketing pelo WhatsApp (Fase 7).
 *   acao=lista      (multipart: arquivo CSV/XLSX)  → telefones normalizados + estatísticas (nada é gravado)
 *   acao=previa     { template_id, filtros (JSON) } → elegíveis, excluídos por motivo, custo, limites
 *   acao=criar      { nome, template_id, filtros (JSON), agendada_para? } → rascunho com destinatários
 *   acao=confirmar  { id, confirmacao (número de contatos digitado) }
 *   acao=pausar|retomar|cancelar { id }
 * Permissão: gerenciar_campanhas_wa. O público respeita o recorte de leads de quem cria.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/campanhas.php';
require_once __DIR__ . '/../includes/importacao.php';

$usuario = api_iniciar('gerenciar_campanhas_wa');
$empresaId = (int) $usuario['empresa_ativa_id'];
$acao = (string) ($_POST['acao'] ?? '');
$filtros = function (): array {
    $f = json_decode((string) ($_POST['filtros'] ?? '{}'), true);
    return is_array($f) ? $f : [];
};

switch ($acao) {
    case 'lista':
        $arq = $_FILES['arquivo'] ?? null;
        if (!$arq || ($arq['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($arq['tmp_name'])) {
            api_erro('Escolha a planilha (CSV ou XLSX).', 422);
        }
        if ((int) $arq['size'] > IMPORTACAO_MAX_BYTES) {
            api_erro('Arquivo maior que 5 MB.', 413);
        }
        $ext = strtolower(pathinfo((string) $arq['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx'], true)) {
            api_erro('Envie CSV ou XLSX.', 422);
        }
        if ($ext === 'xlsx' && (string) file_get_contents($arq['tmp_name'], false, null, 0, 4) !== "PK\x03\x04") {
            api_erro('O arquivo não é um XLSX válido.', 422);
        }
        [$tels, $est] = campanha_ler_lista($arq['tmp_name'], $ext);
        if ($tels === null) {
            api_erro($est['erro'], 422);
        }
        api_responder(['ok' => true, 'telefones' => $tels] + $est + ['mensagem' => $est['validos'] . ' telefone(s) lido(s).']);

    case 'previa':
        api_responder(campanha_previa($usuario, $filtros(), (int) ($_POST['template_id'] ?? 0)));

    case 'criar':
        $r = campanha_criar($usuario, (string) ($_POST['nome'] ?? ''), (int) ($_POST['template_id'] ?? 0), $filtros(), ($_POST['agendada_para'] ?? '') ?: null);
        if (!$r['ok']) {
            api_erro($r['erro'], 422);
        }
        api_responder(['ok' => true, 'id' => $r['id'], 'mensagem' => 'Campanha criada. Confira e confirme para começar.']);

    case 'confirmar':
    case 'pausar':
    case 'retomar':
    case 'cancelar':
        $r = campanha_acao($usuario, (int) ($_POST['id'] ?? 0), $acao, isset($_POST['confirmacao']) ? (int) $_POST['confirmacao'] : null);
        if (!$r['ok']) {
            api_erro($r['erro'], 422);
        }
        api_responder($r);
}
api_erro('Ação desconhecida.', 400);
