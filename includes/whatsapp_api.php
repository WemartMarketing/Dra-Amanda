<?php
/**
 * Cliente da WhatsApp Business Platform — Cloud API oficial (Graph API).
 *
 * Só monta requisições, chama a Meta e traduz erros. Quem decide SE uma
 * mensagem pode sair é includes/whatsapp_regras.php; quem registra é
 * includes/whatsapp.php. Contas em "modo de teste" nunca chegam aqui.
 *
 * Segurança: token sempre no cabeçalho Authorization (nunca na URL), TLS
 * verificado, timeouts curtos, e todo texto de erro passa por
 * meta_texto_seguro() para nunca gravar o token.
 *
 * Os códigos de erro tratados abaixo seguem a documentação pública da
 * Cloud API; confirme-os com a conta real (ver docs/WHATSAPP_JORNADAS.md).
 */
require_once __DIR__ . '/meta.php';

/** Base da Graph API. Em testes locais, WEMART_GRAPH_BASE_URL aponta para um simulador. */
function wa_graph_base(): string
{
    $teste = defined('TESTES_PERMITIDOS') && TESTES_PERMITIDOS === true ? getenv('WEMART_GRAPH_BASE_URL') : false;
    if ($teste) {
        $versao = defined('META_GRAPH_VERSION') && META_GRAPH_VERSION ? META_GRAPH_VERSION : 'v21.0';
        return rtrim($teste, '/') . '/' . trim($versao, '/');
    }
    return meta_graph_base();
}

/**
 * Requisição à Graph API. $corpo: array → JSON; ['__multipart' => [...]] → multipart.
 * @return array{ok: bool, status: int, data: array, erro: ?array}  erro = wa_api_classificar_erro(...)
 */
function wa_api(string $metodo, string $caminho, string $token, ?array $corpo = null, array $query = []): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'data' => [], 'erro' => wa_api_classificar_erro(0, null, 'Extensão cURL do PHP indisponível no servidor.')];
    }
    $url = wa_graph_base() . '/' . ltrim($caminho, '/') . ($query ? '?' . http_build_query($query) : '');
    $cabecalhos = ['Accept: application/json', 'Authorization: Bearer ' . $token];
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        CURLOPT_USERAGENT => 'WeMartSales/1.0',
        CURLOPT_CUSTOMREQUEST => $metodo,
    ];
    if ($corpo !== null) {
        if (isset($corpo['__multipart'])) {
            $opts[CURLOPT_POSTFIELDS] = $corpo['__multipart'];
        } else {
            $cabecalhos[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($corpo, JSON_UNESCAPED_UNICODE);
        }
    }
    $opts[CURLOPT_HTTPHEADER] = $cabecalhos;
    curl_setopt_array($ch, $opts);
    $resposta = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);

    if ($resposta === false) {
        return ['ok' => false, 'status' => 0, 'data' => [], 'erro' => wa_api_classificar_erro(0, null, meta_texto_seguro('Falha de conexão com a Meta: ' . $erroCurl, [$token]))];
    }
    $data = json_decode((string) $resposta, true);
    if (!is_array($data)) {
        return ['ok' => false, 'status' => $status, 'data' => [], 'erro' => wa_api_classificar_erro($status, null, 'Resposta inválida da Meta (HTTP ' . $status . ').')];
    }
    if ($status >= 400 || isset($data['error'])) {
        return ['ok' => false, 'status' => $status, 'data' => $data, 'erro' => wa_api_classificar_erro($status, $data['error'] ?? null, null, [$token])];
    }
    return ['ok' => true, 'status' => $status, 'data' => $data, 'erro' => null];
}

/**
 * Traduz o erro em: é temporário (tentar de novo)? Que efeito colateral aplicar?
 *
 * @return array{temporario: bool, codigo: string, mensagem: string, efeito: ?string}
 *   efeito: conta_erro | template_pausado | template_desativado | janela_fechada | opt_out_marketing | null
 */
function wa_api_classificar_erro(int $status, ?array $erro, ?string $textoLocal = null, array $segredos = []): array
{
    if ($erro === null) {
        // Sem resposta da Meta (rede/timeout) ou 5xx sem corpo: tentar de novo.
        return ['temporario' => $status === 0 || $status >= 500 || $status === 429, 'codigo' => $status ? 'http_' . $status : 'rede',
                'mensagem' => $textoLocal ?? ('Erro HTTP ' . $status . ' na Meta.'), 'efeito' => null];
    }
    $code = (int) ($erro['code'] ?? 0);
    $detalhe = (string) ($erro['error_data']['details'] ?? $erro['message'] ?? '');
    $base = meta_texto_seguro($detalhe, $segredos, 300);
    $tabela = [
        // código => [temporário, efeito, mensagem em português]
        0      => [false, 'conta_erro', 'Token de acesso inválido ou sem permissão.'],
        190    => [false, 'conta_erro', 'Token de acesso expirado ou inválido. Cadastre um novo token.'],
        10     => [false, 'conta_erro', 'O token não tem permissão para esta ação.'],
        200    => [false, 'conta_erro', 'Permissão negada pela Meta.'],
        4      => [true, null, 'Limite de chamadas do app atingido; nova tentativa mais tarde.'],
        80007  => [true, null, 'Limite de chamadas da conta atingido; nova tentativa mais tarde.'],
        130429 => [true, null, 'Limite de envio por segundo atingido; nova tentativa em instantes.'],
        131056 => [true, null, 'Muitas mensagens seguidas para o mesmo número; nova tentativa mais tarde.'],
        131048 => [true, null, 'A Meta limitou envios por suspeita de spam; nova tentativa mais tarde.'],
        1      => [true, null, 'Erro temporário na Meta.'],
        2      => [true, null, 'Serviço da Meta indisponível no momento.'],
        131000 => [true, null, 'Erro temporário na Meta.'],
        131016 => [true, null, 'Serviço do WhatsApp sobrecarregado no momento.'],
        131052 => [true, null, 'A Meta não conseguiu baixar o arquivo; nova tentativa.'],
        131047 => [false, 'janela_fechada', 'Mais de 24 horas desde a última mensagem do cliente: só é possível enviar template aprovado.'],
        131026 => [false, null, 'Mensagem não entregável: o número pode não ter WhatsApp ou não aceitar a mensagem.'],
        131049 => [false, null, 'A Meta optou por não entregar esta mensagem de marketing a este cliente agora.'],
        131050 => [false, 'opt_out_marketing', 'O cliente desativou o recebimento de mensagens de marketing.'],
        131051 => [false, null, 'Tipo de mensagem não suportado.'],
        131053 => [false, null, 'A Meta não aceitou o arquivo enviado.'],
        131008 => [false, null, 'Parâmetro obrigatório ausente na mensagem.'],
        131009 => [false, null, 'Valor de parâmetro inválido na mensagem.'],
        100    => [false, null, 'Parâmetro inválido na requisição.'],
        132000 => [false, null, 'Quantidade de variáveis diferente da cadastrada no template.'],
        132001 => [false, 'template_desativado', 'O template não existe na Meta (nome ou idioma).'],
        132005 => [false, null, 'Texto do template ficou longo demais com as variáveis.'],
        132007 => [false, null, 'O conteúdo viola a política da Meta.'],
        132012 => [false, null, 'Formato das variáveis diferente do cadastrado no template.'],
        132015 => [false, 'template_pausado', 'Template pausado pela Meta por baixa qualidade.'],
        132016 => [false, 'template_desativado', 'Template desativado pela Meta.'],
        131031 => [false, 'conta_erro', 'A conta do WhatsApp Business está bloqueada.'],
        131042 => [false, 'conta_erro', 'Problema de pagamento na conta do WhatsApp Business.'],
        133010 => [false, 'conta_erro', 'O número não está registrado na Cloud API.'],
        368    => [false, 'conta_erro', 'Conta temporariamente bloqueada por violação de política.'],
    ];
    if (isset($tabela[$code])) {
        [$temp, $efeito, $msg] = $tabela[$code];
    } else {
        $temp = $status >= 500;
        $efeito = null;
        $msg = 'A Meta recusou a mensagem.';
    }
    return ['temporario' => $temp, 'codigo' => (string) $code, 'mensagem' => $msg . ($base !== '' ? ' (' . $base . ')' : ''), 'efeito' => $efeito];
}

// ------------------------------------------------------------------ montagem das mensagens

/** Texto livre (só dentro da janela de 24 h). */
function wa_api_corpo_texto(string $para, string $texto): array
{
    return ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $para, 'type' => 'text',
            'text' => ['preview_url' => false, 'body' => mb_substr($texto, 0, 4096)]];
}

/**
 * Template aprovado. $parametros: variável → valor ({{1}} → "Ana" ou {{nome}} → "Ana").
 * Numeradas vão na ordem 1, 2, 3…; nomeadas levam parameter_name.
 * $cabecalhoDocumento: ['id' => media_id] ou ['link' => url], + 'filename'.
 */
function wa_api_corpo_template(string $para, string $nome, string $idioma, array $parametros, ?array $cabecalhoDocumento = null): array
{
    $componentes = [];
    if ($cabecalhoDocumento) {
        $componentes[] = ['type' => 'header', 'parameters' => [['type' => 'document', 'document' => $cabecalhoDocumento]]];
    }
    if ($parametros) {
        $numeradas = array_filter(array_keys($parametros), fn ($k) => ctype_digit((string) $k));
        $lista = [];
        if (count($numeradas) === count($parametros)) {
            ksort($parametros, SORT_NUMERIC);
            foreach ($parametros as $v) {
                $lista[] = ['type' => 'text', 'text' => (string) $v];
            }
        } else {
            foreach ($parametros as $k => $v) {
                $lista[] = ['type' => 'text', 'parameter_name' => (string) $k, 'text' => (string) $v];
            }
        }
        $componentes[] = ['type' => 'body', 'parameters' => $lista];
    }
    $tpl = ['name' => $nome, 'language' => ['code' => $idioma]];
    if ($componentes) {
        $tpl['components'] = $componentes;
    }
    return ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $para, 'type' => 'template', 'template' => $tpl];
}

/** Documento (PDF) já enviado à Meta (media_id) — só dentro da janela de 24 h. */
function wa_api_corpo_documento(string $para, string $mediaId, string $nomeArquivo, ?string $legenda = null): array
{
    $doc = ['id' => $mediaId, 'filename' => mb_substr($nomeArquivo, 0, 240)];
    if ($legenda) {
        $doc['caption'] = mb_substr($legenda, 0, 1024);
    }
    return ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $para, 'type' => 'document', 'document' => $doc];
}

/** Botões de resposta rápida (até 3, título até 20 caracteres) — só dentro da janela. */
function wa_api_corpo_botoes(string $para, string $texto, array $botoes): array
{
    $lista = [];
    foreach (array_slice($botoes, 0, 3) as $b) {
        $lista[] = ['type' => 'reply', 'reply' => ['id' => mb_substr((string) $b['id'], 0, 256), 'title' => mb_substr((string) $b['titulo'], 0, 20)]];
    }
    return ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $para, 'type' => 'interactive',
            'interactive' => ['type' => 'button', 'body' => ['text' => mb_substr($texto, 0, 1024)], 'action' => ['buttons' => $lista]]];
}

// ------------------------------------------------------------------ chamadas

function wa_api_enviar(string $phoneNumberId, string $token, array $corpo): array
{
    $r = wa_api('POST', $phoneNumberId . '/messages', $token, $corpo);
    if ($r['ok']) {
        $wamid = $r['data']['messages'][0]['id'] ?? null;
        if (!$wamid) {
            return ['ok' => false, 'status' => $r['status'], 'data' => $r['data'],
                    'erro' => wa_api_classificar_erro($r['status'], null, 'A Meta não devolveu o identificador da mensagem.')];
        }
        $r['wamid'] = $wamid;
        $r['wa_id'] = $r['data']['contacts'][0]['wa_id'] ?? null;
    }
    return $r;
}

/** Envia um arquivo para a Meta e devolve o media_id (válido por tempo limitado na Meta). */
function wa_api_upload_midia(string $phoneNumberId, string $token, string $caminho, string $mime, string $nome): array
{
    if (!is_file($caminho) || !is_readable($caminho)) {
        return ['ok' => false, 'status' => 0, 'data' => [], 'erro' => wa_api_classificar_erro(0, null, 'Arquivo não encontrado no servidor.')];
    }
    $r = wa_api('POST', $phoneNumberId . '/media', $token, ['__multipart' => [
        'messaging_product' => 'whatsapp',
        'type' => $mime,
        'file' => new CURLFile($caminho, $mime, $nome),
    ]]);
    if ($r['ok']) {
        $r['media_id'] = $r['data']['id'] ?? null;
        if (!$r['media_id']) {
            $r['ok'] = false;
            $r['erro'] = wa_api_classificar_erro($r['status'], null, 'A Meta não devolveu o identificador do arquivo.');
        }
    }
    return $r;
}

/** Dados do número (nome verificado, número exibido, qualidade). */
function wa_api_numero(string $phoneNumberId, string $token): array
{
    return wa_api('GET', $phoneNumberId, $token, null, ['fields' => 'display_phone_number,verified_name,quality_rating']);
}

/** Inscreve o app da WeMart na conta (WABA) para receber os webhooks dela. */
function wa_api_assinar(string $wabaId, string $token): array
{
    return wa_api('POST', $wabaId . '/subscribed_apps', $token, []);
}

/** Lista todos os templates da conta (segue a paginação, até 20 páginas). */
function wa_api_templates(string $wabaId, string $token): array
{
    $todos = [];
    $after = null;
    for ($i = 0; $i < 20; $i++) {
        $q = ['fields' => 'id,name,language,status,category,components,rejected_reason', 'limit' => 100];
        if ($after) {
            $q['after'] = $after;
        }
        $r = wa_api('GET', $wabaId . '/message_templates', $token, null, $q);
        if (!$r['ok']) {
            return $r;
        }
        foreach ((array) ($r['data']['data'] ?? []) as $t) {
            $todos[] = $t;
        }
        $after = $r['data']['paging']['cursors']['after'] ?? null;
        if (!$after || empty($r['data']['paging']['next'])) {
            break;
        }
    }
    return ['ok' => true, 'status' => 200, 'data' => $todos, 'erro' => null];
}
