<?php
/**
 * Integração Meta Lead Ads (seção 10 do prompt mestre).
 *
 * Fluxo: a Meta avisa o webhook (webhook/meta.php) só com ids
 * (leadgen_id, page_id, form_id). O aviso é gravado em meta_eventos
 * ANTES de qualquer processamento, deduplicado por leadgen_id. Depois o
 * sistema busca os dados do cadastro na Graph API com o token da página
 * (guardado cifrado — includes/cripto.php), cria o lead e o envia para a
 * roleta. Falhas ficam registradas e são tentadas de novo com espera
 * crescente (5, 15, 60, 180 min; desiste após 6 tentativas, mas o Master
 * pode reprocessar). Mensagens de erro nunca contêm tokens.
 *
 * Nada aqui funciona sem as credenciais reais em includes/config.php
 * (META_APP_SECRET, META_VERIFY_TOKEN, APP_KEY) e o token de cada página
 * cadastrado pelo Master — não há simulação.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cripto.php';
require_once __DIR__ . '/leads.php';
require_once __DIR__ . '/funil.php';
require_once __DIR__ . '/auditoria.php';
require_once __DIR__ . '/roleta.php';

const META_MAX_TENTATIVAS = 6;

/** App secret e verify token da plataforma estão definidos em config.php? */
function meta_configurada(): bool
{
    return defined('META_APP_SECRET') && META_APP_SECRET !== '' && defined('META_VERIFY_TOKEN') && META_VERIFY_TOKEN !== '';
}

function meta_graph_base(): string
{
    $base = defined('META_GRAPH_BASE_URL') && META_GRAPH_BASE_URL ? META_GRAPH_BASE_URL : 'https://graph.facebook.com';
    // Só nos testes automatizados (TESTES_PERMITIDOS): aponta para o simulador local da Graph API.
    if (defined('TESTES_PERMITIDOS') && TESTES_PERMITIDOS === true && getenv('WEMART_GRAPH_BASE_URL')) {
        $base = getenv('WEMART_GRAPH_BASE_URL');
    }
    $versao = defined('META_GRAPH_VERSION') && META_GRAPH_VERSION ? META_GRAPH_VERSION : 'v21.0';
    return rtrim($base, '/') . '/' . trim($versao, '/');
}

/** Remove qualquer token de um texto e limita o tamanho (para gravar erro/log). */
function meta_texto_seguro(string $texto, array $segredos = [], int $max = 480): string
{
    foreach ($segredos as $s) {
        if ($s !== null && $s !== '') {
            $texto = str_replace($s, '***', $texto);
        }
    }
    $texto = preg_replace('/(access_token=)[^&\s"]+/i', '$1***', $texto);
    return mb_substr(trim($texto), 0, $max);
}

/**
 * Chamada à Graph API. Retorna ['ok', 'status', 'data', 'erro'].
 * Timeouts curtos e verificação TLS sempre ligada.
 */
function meta_graph(string $metodo, string $caminho, array $params, string $token): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'data' => [], 'erro' => 'Extensão cURL do PHP indisponível no servidor.'];
    }
    $url = meta_graph_base() . '/' . ltrim($caminho, '/');
    $params['access_token'] = $token;
    $ch = curl_init();
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'WeMartSales/1.0',
    ];
    if ($metodo === 'POST') {
        $opts[CURLOPT_URL] = $url;
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    } else {
        $opts[CURLOPT_URL] = $url . '?' . http_build_query($params);
    }
    curl_setopt_array($ch, $opts);
    $corpo = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);

    if ($corpo === false) {
        return ['ok' => false, 'status' => 0, 'data' => [], 'erro' => meta_texto_seguro('Falha de conexão com a Meta: ' . $erroCurl, [$token])];
    }
    $data = json_decode((string) $corpo, true);
    if (!is_array($data)) {
        return ['ok' => false, 'status' => $status, 'data' => [], 'erro' => 'Resposta inválida da Meta (HTTP ' . $status . ').'];
    }
    if ($status >= 400 || isset($data['error'])) {
        $msg = $data['error']['message'] ?? ('HTTP ' . $status);
        $cod = isset($data['error']['code']) ? ' [código ' . (int) $data['error']['code'] . ']' : '';
        return ['ok' => false, 'status' => $status, 'data' => $data, 'erro' => meta_texto_seguro('Meta recusou: ' . $msg . $cod, [$token])];
    }
    return ['ok' => true, 'status' => $status, 'data' => $data, 'erro' => null];
}

/** Página da empresa (ou null). */
function meta_pagina(int $empresaId, int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM meta_paginas WHERE id = :id AND empresa_id = :e');
    $stmt->execute(['id' => $id, 'e' => $empresaId]);
    return $stmt->fetch() ?: null;
}

function meta_pagina_token(array $pagina): ?string
{
    return cripto_decifrar($pagina['access_token_cifrado'] ?? null);
}

/** URL pública do webhook, sempre https, a partir do host atual. */
function meta_webhook_url(): string
{
    $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'seu-dominio.com.br'));
    return 'https://' . $host . '/webhook/meta.php';
}

/**
 * Grava os avisos de um corpo de webhook já com assinatura conferida.
 * Retorna os ids de meta_eventos NOVOS que devem ser processados.
 */
function meta_registrar_webhook(array $corpo): array
{
    $novos = [];
    if (($corpo['object'] ?? '') !== 'page' || !is_array($corpo['entry'] ?? null)) {
        return $novos;
    }
    $pdo = db();
    $buscaPagina = $pdo->prepare('SELECT empresa_id FROM meta_paginas WHERE page_id = :p AND ativa = 1');
    $inserir = $pdo->prepare('INSERT IGNORE INTO meta_eventos (empresa_id, page_id, form_id, leadgen_id, payload, status)
                              VALUES (:e, :p, :f, :l, :payload, :s)');
    foreach (array_slice($corpo['entry'], 0, 100) as $entry) {
        foreach (array_slice((array) ($entry['changes'] ?? []), 0, 100) as $change) {
            if (($change['field'] ?? '') !== 'leadgen' || !is_array($change['value'] ?? null)) {
                continue;
            }
            $v = $change['value'];
            $leadgenId = preg_replace('/\D/', '', (string) ($v['leadgen_id'] ?? ''));
            $pageId = preg_replace('/\D/', '', (string) ($v['page_id'] ?? ($entry['id'] ?? '')));
            $formId = preg_replace('/\D/', '', (string) ($v['form_id'] ?? ''));
            if ($leadgenId === '' || strlen($leadgenId) > 40 || strlen($pageId) > 40 || strlen($formId) > 40) {
                continue;
            }
            $buscaPagina->execute(['p' => $pageId]);
            $empresaId = $buscaPagina->fetchColumn();
            // Só ids e metadados — a Meta não envia dados pessoais no aviso.
            $payload = json_encode([
                'leadgen_id' => $leadgenId, 'page_id' => $pageId, 'form_id' => $formId,
                'ad_id' => isset($v['ad_id']) ? preg_replace('/\D/', '', (string) $v['ad_id']) : null,
                'created_time' => isset($v['created_time']) ? (int) $v['created_time'] : null,
            ]);
            $inserir->execute([
                'e' => $empresaId ? (int) $empresaId : null, 'p' => $pageId ?: null, 'f' => $formId ?: null, 'l' => $leadgenId,
                'payload' => $payload, 's' => $empresaId ? 'pendente' : 'ignorado',
            ]);
            if ($inserir->rowCount() === 1 && $empresaId) {
                $novos[] = (int) $pdo->lastInsertId();
            }
        }
    }
    return $novos;
}

/** Espera antes da próxima tentativa (minutos) — null = desistir. */
function meta_backoff_minutos(int $tentativas): ?int
{
    if ($tentativas >= META_MAX_TENTATIVAS) {
        return null;
    }
    return [1 => 5, 2 => 15, 3 => 60][$tentativas] ?? 180;
}

/**
 * Converte field_data da Meta em campos do lead.
 * Nomes de campo padrão: full_name / first_name + last_name, phone_number,
 * email. Os demais (perguntas personalizadas) vão para interesse/observação.
 */
function meta_mapear_campos(array $fieldData): array
{
    $valores = [];
    foreach ($fieldData as $campo) {
        $nome = mb_strtolower(trim((string) ($campo['name'] ?? '')));
        $v = $campo['values'] ?? [];
        $valor = trim(is_array($v) ? implode(', ', array_map('strval', $v)) : (string) $v);
        if ($nome !== '' && $valor !== '') {
            $valores[$nome] = mb_substr($valor, 0, 300);
        }
    }
    $nome = $valores['full_name'] ?? trim(($valores['first_name'] ?? '') . ' ' . ($valores['last_name'] ?? ''));
    $telefone = $valores['phone_number'] ?? ($valores['phone'] ?? ($valores['telefone'] ?? ''));
    $email = $valores['email'] ?? '';
    $interesse = null;
    $extras = [];
    foreach ($valores as $k => $v) {
        if (in_array($k, ['full_name', 'first_name', 'last_name', 'phone_number', 'phone', 'telefone', 'email'], true)) {
            continue;
        }
        if ($interesse === null && preg_match('/interesse|imovel|imóvel|produto|empreendimento|tipo|quartos|bairro/u', $k)) {
            $interesse = mb_substr($v, 0, 190);
            continue;
        }
        $extras[] = str_replace('_', ' ', $k) . ': ' . $v;
    }
    $digitos = lead_telefone_normalizado($telefone);
    return [
        'nome' => mb_substr($nome !== '' ? $nome : 'Lead do Meta Ads', 0, 190),
        'telefone' => (strlen($digitos) >= 10 && strlen($digitos) <= 11) ? $digitos : '',
        'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_substr($email, 0, 190) : null,
        'interesse' => $interesse,
        'observacao' => $extras ? mb_substr(implode(' · ', $extras), 0, 500) : null,
    ];
}

/**
 * Processa um evento (pendente ou com erro vencido). Retorna o status final.
 * Reserva o evento com um "lease" de 2 minutos para que duas execuções
 * simultâneas (webhook + cron) nunca processem o mesmo aviso.
 */
function meta_processar_evento(int $eventoId, bool $forcar = false): string
{
    $pdo = db();
    $reserva = $pdo->prepare("UPDATE meta_eventos SET proxima_tentativa_em = DATE_ADD(NOW(), INTERVAL 2 MINUTE)
        WHERE id = :id AND empresa_id IS NOT NULL AND status IN ('pendente','erro')
          AND (:forcar = 1 OR proxima_tentativa_em IS NULL OR proxima_tentativa_em <= NOW())
          AND (:forcar2 = 1 OR tentativas < :max)");
    $reserva->execute(['id' => $eventoId, 'forcar' => $forcar ? 1 : 0, 'forcar2' => $forcar ? 1 : 0, 'max' => META_MAX_TENTATIVAS]);
    if ($reserva->rowCount() !== 1) {
        return 'ocupado';
    }
    $stmt = $pdo->prepare('SELECT * FROM meta_eventos WHERE id = :id');
    $stmt->execute(['id' => $eventoId]);
    $ev = $stmt->fetch();
    $empresaId = (int) $ev['empresa_id'];
    $token = null;

    $falhar = function (string $erro) use ($pdo, $ev, &$token): string {
        $tent = (int) $ev['tentativas'] + 1;
        $espera = meta_backoff_minutos($tent);
        $pdo->prepare('UPDATE meta_eventos SET status = \'erro\', tentativas = :t, erro = :erro,
                proxima_tentativa_em = ' . ($espera === null ? 'NULL' : 'DATE_ADD(NOW(), INTERVAL ' . (int) $espera . ' MINUTE)') . '
            WHERE id = :id')->execute(['t' => $tent, 'erro' => meta_texto_seguro($erro, [$token]), 'id' => $ev['id']]);
        return 'erro';
    };
    $concluir = function (string $status, ?int $leadId, ?string $nota = null) use ($pdo, $ev): string {
        $pdo->prepare('UPDATE meta_eventos SET status = :s, lead_id = :l, erro = :n, processado_em = NOW(), proxima_tentativa_em = NULL, tentativas = tentativas + 1 WHERE id = :id')
            ->execute(['s' => $status, 'l' => $leadId, 'n' => $nota, 'id' => $ev['id']]);
        return $status;
    };

    // Já virou lead antes (aviso repetido com outro id interno não acontece pelo UNIQUE, mas o lead pode ter vindo por reprocessamento).
    $stmt = $pdo->prepare('SELECT id FROM leads WHERE meta_leadgen_id = :l');
    $stmt->execute(['l' => $ev['leadgen_id']]);
    if ($existente = $stmt->fetchColumn()) {
        return $concluir('duplicado', (int) $existente, 'Este cadastro do Meta já tinha virado lead.');
    }

    $stmt = $pdo->prepare('SELECT * FROM meta_paginas WHERE page_id = :p AND empresa_id = :e');
    $stmt->execute(['p' => $ev['page_id'], 'e' => $empresaId]);
    $pagina = $stmt->fetch();
    if (!$pagina || !(int) $pagina['ativa']) {
        return $falhar('Página não está mais conectada ou foi desativada.');
    }
    $token = meta_pagina_token($pagina);
    if (!$token) {
        return $falhar('Token da página ausente ou ilegível (confira APP_KEY e cadastre o token de novo).');
    }
    // Origem completa (campanha, conjunto, anúncio, plataforma). Se a Meta recusar
    // algum desses campos, busca só o essencial — a origem nunca impede o cadastro.
    $r = meta_graph('GET', $ev['leadgen_id'], ['fields' => 'created_time,field_data,form_id,ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name,platform,is_organic'], $token);
    if (!$r['ok'] && (int) ($r['data']['error']['code'] ?? 0) === 100) {
        $r = meta_graph('GET', $ev['leadgen_id'], ['fields' => 'created_time,field_data,form_id,ad_id,campaign_name'], $token);
    }
    if (!$r['ok']) {
        return $falhar($r['erro']);
    }
    $campos = meta_mapear_campos((array) ($r['data']['field_data'] ?? []));
    $formId = preg_replace('/\D/', '', (string) ($r['data']['form_id'] ?? $ev['form_id']));
    $campanha = mb_substr(trim((string) ($r['data']['campaign_name'] ?? '')), 0, 120);

    $stmt = $pdo->prepare('SELECT f.nome, f.empreendimento_id, f.ativo, f.wa_consentimento_campo, f.wa_consentimento_texto FROM meta_formularios f WHERE f.form_id = :f AND f.empresa_id = :e');
    $stmt->execute(['f' => $formId, 'e' => $empresaId]);
    $form = $stmt->fetch() ?: null;
    $empreendimentoId = $form && (int) $form['ativo'] && $form['empreendimento_id'] ? (int) $form['empreendimento_id'] : null;
    $origemTexto = 'formulário ' . ($form['nome'] ?? $formId ?: 'sem id') . ($campanha !== '' ? ', campanha ' . $campanha : '');

    // Mesmo telefone já cadastrado na empresa: registra no lead existente, sem criar outro nem contar como feedback.
    if ($campos['telefone'] !== '') {
        $stmt = $pdo->prepare('SELECT id FROM leads WHERE empresa_id = :e AND excluido_em IS NULL
            AND ' . lead_sql_telefone_digitos('telefone') . " IN (:t, CONCAT('55', :t2)) ORDER BY id LIMIT 1");
        $stmt->execute(['e' => $empresaId, 't' => $campos['telefone'], 't2' => $campos['telefone']]);
        if ($dupId = $stmt->fetchColumn()) {
            lead_registrar_evento((int) $dupId, null, 'integracao', 'Novo cadastro pelo Meta Ads (' . $origemTexto . ') com o mesmo telefone — nenhum lead novo foi criado');
            return $concluir('duplicado', (int) $dupId, 'Telefone já cadastrado na empresa.');
        }
    }

    $etapa = funil_primeira_etapa($empresaId);
    if (!$etapa) {
        return $falhar('O funil da empresa não tem etapas ativas.');
    }
    try {
        $pdo->prepare("INSERT INTO leads (empresa_id, empreendimento_id, equipe_id, responsavel_id, nome, telefone, email, interesse, origem, campanha, meta_leadgen_id,
                etapa_id, etapa, prioridade, ultima_atividade_em)
            VALUES (:e, :emp, NULL, NULL, :nome, :tel, :email, :int, 'meta_ads', :camp, :lg, :etapa_id, :etapa, 'normal', NOW())")
            ->execute([
                'e' => $empresaId, 'emp' => $empreendimentoId, 'nome' => $campos['nome'],
                'tel' => $campos['telefone'] !== '' ? lead_formatar_telefone($campos['telefone']) : null,
                'email' => $campos['email'], 'int' => $campos['interesse'], 'camp' => $campanha !== '' ? $campanha : null, 'lg' => $ev['leadgen_id'],
                'etapa_id' => $etapa['id'], 'etapa' => $etapa['categoria'],
            ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            $stmt = $pdo->prepare('SELECT id FROM leads WHERE meta_leadgen_id = :l');
            $stmt->execute(['l' => $ev['leadgen_id']]);
            return $concluir('duplicado', ($id = $stmt->fetchColumn()) ? (int) $id : null, 'Este cadastro do Meta já tinha virado lead.');
        }
        return $falhar('Erro ao gravar o lead no banco.');
    }
    $leadId = (int) $pdo->lastInsertId();
    require_once __DIR__ . '/origens.php';
    if ($origemId = origem_id_do_canal($empresaId, 'meta_ads')) {
        $pdo->prepare('UPDATE leads SET origem_detalhe_id = :o WHERE id = :l')->execute(['o' => $origemId, 'l' => $leadId]);
    }
    // Anúncio que gerou o lead (ids e nomes; o criativo é buscado depois, pela rotina).
    try {
        require_once __DIR__ . '/meta_criativos.php';
        meta_registrar_origem($empresaId, $leadId, meta_origem_do_lead((array) $r['data'], json_decode((string) $ev['payload'], true) ?: []));
    } catch (Throwable $e) {
        error_log('[meta] origem do anúncio não registrada para o lead ' . $leadId . ': ' . $e->getMessage());
    }
    lead_registrar_evento($leadId, null, 'integracao', 'Lead recebido do Meta Ads — ' . $origemTexto);
    if ($campos['observacao']) {
        lead_registrar_evento($leadId, null, 'nota', 'Respostas do formulário: ' . $campos['observacao']);
    }
    auditoria_registrar($empresaId, null, 'meta.lead_recebido', 'lead', $leadId, ['meta_evento_id' => (int) $ev['id'], 'form_id' => $formId]);
    $concluir('processado', $leadId);

    roleta_distribuir_lead($leadId, 'meta_ads', null);
    // Jornadas (WhatsApp): autorização do formulário + boas-vindas. Falha aqui nunca desfaz o lead.
    require_once __DIR__ . '/jornadas.php';
    jornada_lead_chegou($empresaId, $leadId, 'meta_ads', (array) ($r['data']['field_data'] ?? []), $form);
    return 'processado';
}

/** Processa eventos pendentes e erros cuja espera venceu (cron). */
function meta_processar_pendentes(int $limite = 50): array
{
    $stmt = db()->prepare("SELECT id FROM meta_eventos
        WHERE empresa_id IS NOT NULL AND status IN ('pendente','erro') AND tentativas < :max
          AND (proxima_tentativa_em IS NULL OR proxima_tentativa_em <= NOW())
        ORDER BY id LIMIT " . max(1, $limite));
    $stmt->execute(['max' => META_MAX_TENTATIVAS]);
    $res = ['tentados' => 0, 'processados' => 0, 'erros' => 0, 'duplicados' => 0];
    foreach (array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)) as $id) {
        $s = meta_processar_evento($id);
        if ($s === 'ocupado') {
            continue;
        }
        $res['tentados']++;
        if ($s === 'processado') { $res['processados']++; }
        elseif ($s === 'duplicado') { $res['duplicados']++; }
        else { $res['erros']++; }
    }
    return $res;
}

function meta_status_eventos(): array
{
    return [
        'pendente' => ['Pendente', 'pill-amber'],
        'processado' => ['Lead criado', 'pill-green'],
        'duplicado' => ['Duplicado', 'pill-muted'],
        'erro' => ['Erro', 'pill-red'],
        'ignorado' => ['Ignorado', 'pill-muted'],
    ];
}
