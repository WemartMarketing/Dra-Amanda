<?php
/**
 * Origem do lead — anúncio e criativo do Meta Ads.
 *
 * 1. Quando um lead chega pelo Lead Ads, os ids de origem (campanha,
 *    conjunto, anúncio, formulário, página, plataforma) são gravados NO LEAD
 *    e o anúncio ganha um registro em meta_anuncios (um por anúncio da
 *    empresa, reaproveitado por todos os leads dele). Nada disso pode impedir
 *    o cadastro ou a distribuição do lead.
 * 2. A sincronização busca na Marketing API o anúncio e o criativo: nomes,
 *    textos, CTA, mídia e o LINK OFICIAL de visualização — só URLs que a
 *    própria Meta devolve (preview_shareable_link, permalink do post no
 *    Facebook, permalink do Instagram), sempre validadas por domínio. Nunca
 *    montamos URL a partir do id do anúncio.
 * 3. A imagem é copiada para storage/criativos (cache autorizado): as URLs
 *    da Meta são temporárias. A cópia é servida só para quem pode ver o lead
 *    (api/criativo_midia.php), nunca pelo id do anúncio.
 * 4. Anúncio excluído/sem acesso: os dados já guardados continuam; a tela
 *    explica. Falhas ficam em meta_criativo_falhas e são tentadas de novo
 *    com espera crescente (limites da API respeitados).
 *
 * Token: o da Marketing API da empresa (meta_marketing, opcional) ou, sem
 * ele, o token da página que recebeu o lead. Nenhum corretor precisa de
 * acesso ao Gerenciador de Anúncios.
 */
require_once __DIR__ . '/meta.php';

const META_CRIATIVO_DIR = __DIR__ . '/../storage/criativos';
const META_CRIATIVO_MAX_BYTES = 5 * 1024 * 1024;
const META_CRIATIVO_VALIDADE_DIAS = 7;

function meta_plataformas(): array
{
    return ['fb' => 'Facebook', 'ig' => 'Instagram', 'msg' => 'Messenger', 'an' => 'Audience Network', 'wa' => 'WhatsApp'];
}

function meta_cta_rotulo(?string $cta): ?string
{
    if (!$cta) {
        return null;
    }
    $m = ['LEARN_MORE' => 'Saiba mais', 'SIGN_UP' => 'Cadastre-se', 'CONTACT_US' => 'Fale conosco', 'APPLY_NOW' => 'Inscreva-se', 'GET_QUOTE' => 'Pedir orçamento',
          'SEND_MESSAGE' => 'Enviar mensagem', 'WHATSAPP_MESSAGE' => 'Enviar mensagem pelo WhatsApp', 'CALL_NOW' => 'Ligar agora', 'SUBSCRIBE' => 'Assinar',
          'DOWNLOAD' => 'Baixar', 'GET_OFFER' => 'Obter oferta', 'BOOK_TRAVEL' => 'Reservar', 'BOOK_NOW' => 'Reservar agora', 'SHOP_NOW' => 'Comprar agora',
          'WATCH_MORE' => 'Assistir mais', 'NO_BUTTON' => 'Sem botão', 'REQUEST_TIME' => 'Agendar horário', 'SEE_MORE' => 'Ver mais', 'GET_DIRECTIONS' => 'Como chegar'];
    return $m[strtoupper($cta)] ?? ucfirst(mb_strtolower(str_replace('_', ' ', $cta)));
}

/**
 * Link externo aceito para "Ver anúncio original": https, sem usuário/senha,
 * domínio da Meta (Facebook, Instagram, fb.me). Devolve a URL ou null.
 */
function meta_url_oficial_valida(?string $url): ?string
{
    $url = trim((string) $url);
    if ($url === '' || strlen($url) > 1000 || preg_match('/[\s"<>\\\\]/', $url)) {
        return null;
    }
    $p = parse_url($url);
    if (!$p || ($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass']) || isset($p['port']) || empty($p['host'])) {
        return null;
    }
    $host = strtolower($p['host']);
    foreach (['facebook.com', 'fb.me', 'fb.com', 'instagram.com'] as $d) {
        if ($host === $d || str_ends_with($host, '.' . $d)) {
            return $url;
        }
    }
    return null;
}

/** Host de onde a imagem pode ser baixada (CDNs da Meta). Nos testes, também o simulador local. */
function meta_host_midia_permitido(string $url): bool
{
    $p = parse_url($url);
    $host = strtolower((string) ($p['host'] ?? ''));
    if (defined('TESTES_PERMITIDOS') && TESTES_PERMITIDOS === true && getenv('WEMART_GRAPH_BASE_URL')) {
        $teste = parse_url((string) getenv('WEMART_GRAPH_BASE_URL'));
        if ($host === strtolower((string) ($teste['host'] ?? '')) && (int) ($p['port'] ?? 0) === (int) ($teste['port'] ?? 0)) {
            return true;
        }
    }
    if (($p['scheme'] ?? '') !== 'https' || isset($p['user'])) {
        return false;
    }
    foreach (['fbcdn.net', 'facebook.com', 'fbsbx.com', 'cdninstagram.com'] as $d) {
        if ($host === $d || str_ends_with($host, '.' . $d)) {
            return true;
        }
    }
    return false;
}

function meta_marketing_token(int $empresaId): ?string
{
    if (!function_exists('cripto_decifrar')) {
        return null;
    }
    $stmt = db()->prepare('SELECT token_cifrado FROM meta_marketing WHERE empresa_id = :e');
    $stmt->execute(['e' => $empresaId]);
    $c = $stmt->fetchColumn();
    return $c ? cripto_decifrar($c) : null;
}

/** Token para ler o anúncio: o da Marketing API da empresa; sem ele, o da página do lead. */
function meta_token_para_anuncio(int $empresaId, ?string $pageId): array
{
    if ($t = meta_marketing_token($empresaId)) {
        return [$t, 'marketing'];
    }
    if ($pageId) {
        $stmt = db()->prepare('SELECT * FROM meta_paginas WHERE page_id = :p AND empresa_id = :e AND ativa = 1');
        $stmt->execute(['p' => $pageId, 'e' => $empresaId]);
        if (($pag = $stmt->fetch()) && ($t = meta_pagina_token($pag))) {
            return [$t, 'pagina'];
        }
    }
    return [null, null];
}

// ================================================================== captura no cadastro

/**
 * Lê os dados de origem do retorno do Lead Ads (+ aviso do webhook) e devolve
 * o que for válido. Ids só com dígitos; nomes limitados.
 */
function meta_origem_do_lead(array $leadgen, array $aviso): array
{
    $id = fn ($v) => ($d = preg_replace('/\D/', '', (string) $v)) !== '' && strlen($d) <= 40 ? $d : null;
    $txt = fn ($v) => ($t = mb_substr(trim((string) $v), 0, 255)) !== '' ? $t : null;
    $plat = strtolower((string) ($leadgen['platform'] ?? ''));
    $criado = null;
    if (!empty($leadgen['created_time']) && ($ts = strtotime((string) $leadgen['created_time']))) {
        $criado = date('Y-m-d H:i:s', $ts);
    } elseif (!empty($aviso['created_time'])) {
        $criado = date('Y-m-d H:i:s', (int) $aviso['created_time']);
    }
    $organico = !empty($leadgen['is_organic']);
    return [
        'ad_id' => $organico ? null : ($id($leadgen['ad_id'] ?? null) ?? $id($aviso['ad_id'] ?? null)),
        'ad_nome' => $txt($leadgen['ad_name'] ?? null),
        'adset_id' => $id($leadgen['adset_id'] ?? null), 'adset_nome' => $txt($leadgen['adset_name'] ?? null),
        'campanha_id' => $id($leadgen['campaign_id'] ?? null), 'campanha_nome' => $txt($leadgen['campaign_name'] ?? null),
        'form_id' => $id($leadgen['form_id'] ?? null) ?? $id($aviso['form_id'] ?? null),
        'page_id' => $id($aviso['page_id'] ?? null),
        'plataforma' => isset(meta_plataformas()[$plat]) ? $plat : null,
        'cadastro_em' => $criado, 'organico' => $organico,
    ];
}

/**
 * Grava a origem no lead e garante o registro do anúncio (sem chamar a API).
 * Devolve o id em meta_anuncios (ou null se o cadastro não veio de anúncio).
 */
function meta_registrar_origem(int $empresaId, int $leadId, array $o): ?int
{
    $pdo = db();
    $anuncioId = null;
    if ($o['ad_id']) {
        $pdo->prepare("INSERT INTO meta_anuncios (empresa_id, ad_id, nome, adset_id, adset_nome, campanha_id, campanha_nome, pagina_id, sync_status)
                       VALUES (:e, :ad, :n, :as, :asn, :c, :cn, :p, 'pendente')
                       ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id),
                           nome = COALESCE(nome, VALUES(nome)), adset_id = COALESCE(adset_id, VALUES(adset_id)), adset_nome = COALESCE(adset_nome, VALUES(adset_nome)),
                           campanha_id = COALESCE(campanha_id, VALUES(campanha_id)), campanha_nome = COALESCE(campanha_nome, VALUES(campanha_nome)),
                           pagina_id = COALESCE(pagina_id, VALUES(pagina_id))")
            ->execute(['e' => $empresaId, 'ad' => $o['ad_id'], 'n' => $o['ad_nome'], 'as' => $o['adset_id'], 'asn' => $o['adset_nome'],
                       'c' => $o['campanha_id'], 'cn' => $o['campanha_nome'], 'p' => $o['page_id']]);
        $anuncioId = (int) $pdo->lastInsertId();
    }
    $pdo->prepare('UPDATE leads SET meta_anuncio_id = :a, meta_ad_id = :ad, meta_adset_id = :as, meta_campanha_id = :c, meta_form_id = :f,
                          meta_page_id = :p, meta_plataforma = :pl, meta_cadastro_em = :ce WHERE id = :l AND empresa_id = :e')
        ->execute(['a' => $anuncioId, 'ad' => $o['ad_id'], 'as' => $o['adset_id'], 'c' => $o['campanha_id'], 'f' => $o['form_id'],
                   'p' => $o['page_id'], 'pl' => $o['plataforma'], 'ce' => $o['cadastro_em'], 'l' => $leadId, 'e' => $empresaId]);
    return $anuncioId;
}

// ================================================================== sincronização

function meta_criativo_falha(int $empresaId, ?int $anuncioId, string $etapa, string $erro): void
{
    db()->prepare('INSERT INTO meta_criativo_falhas (empresa_id, meta_anuncio_id, etapa, erro) VALUES (:e, :a, :et, :er)')
        ->execute(['e' => $empresaId, 'a' => $anuncioId, 'et' => $etapa, 'er' => mb_substr($erro, 0, 500)]);
}

/** Classifica o erro da Graph API: removido | sem_permissao | limite | erro. */
function meta_criativo_classificar_erro(array $r): string
{
    $err = $r['data']['error'] ?? [];
    $code = (int) ($err['code'] ?? 0);
    $sub = (int) ($err['error_subcode'] ?? 0);
    if ($code === 100 && ($sub === 33 || stripos((string) ($err['message'] ?? ''), 'does not exist') !== false)) {
        return 'removido';
    }
    if (in_array($code, [4, 17, 32, 613], true) || ($code >= 80000 && $code <= 80014)) {
        return 'limite';
    }
    if (in_array($code, [10, 190, 102, 2500], true) || ($code >= 200 && $code <= 299)) {
        return 'sem_permissao';
    }
    return 'erro';
}

/**
 * Busca o anúncio e o criativo na Marketing API e atualiza o cache.
 * Devolve o novo sync_status. Seguro para rodar mais de uma vez.
 */
function meta_criativo_sincronizar(int $anuncioId): string
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM meta_anuncios WHERE id = :id');
    $stmt->execute(['id' => $anuncioId]);
    $a = $stmt->fetch();
    if (!$a) {
        return 'erro';
    }
    $empresaId = (int) $a['empresa_id'];
    [$token] = meta_token_para_anuncio($empresaId, $a['pagina_id']);
    $agendar = function (string $status, ?string $erro, int $minutos) use ($pdo, $a): string {
        $pdo->prepare('UPDATE meta_anuncios SET sync_status = :s, sync_erro = :e, sync_tentativas = sync_tentativas + 1,
                              proxima_sync_em = DATE_ADD(NOW(), INTERVAL :m MINUTE)' . ($status === 'ok' || $status === 'parcial' ? ', sincronizado_em = NOW(), sync_tentativas = 0' : '') . ' WHERE id = :id')
            ->execute(['s' => $status, 'e' => $erro !== null ? mb_substr($erro, 0, 500) : null, 'm' => $minutos, 'id' => $a['id']]);
        return $status;
    };
    if (!$token) {
        meta_criativo_falha($empresaId, (int) $a['id'], 'anuncio', 'Sem token: cadastre o token da Marketing API ou o da página em Integrações.');
        return $agendar('sem_permissao', 'Sem token para consultar o anúncio.', 24 * 60);
    }
    $campos = 'name,effective_status,account_id,adset{id,name},campaign{id,name},preview_shareable_link,'
            . 'creative{id,title,body,call_to_action_type,image_url,thumbnail_url,video_id,object_type,effective_object_story_id,'
            . 'instagram_permalink_url,asset_feed_spec,object_story_spec}';
    $r = meta_graph('GET', $a['ad_id'], ['fields' => $campos, 'thumbnail_width' => 600, 'thumbnail_height' => 600], $token);
    if (!$r['ok']) {
        $tipo = meta_criativo_classificar_erro($r);
        meta_criativo_falha($empresaId, (int) $a['id'], 'anuncio', $r['erro'] ?? 'Erro');
        if ($tipo === 'removido') {
            $pdo->prepare('UPDATE meta_anuncios SET removido_na_meta = 1 WHERE id = :id')->execute(['id' => $a['id']]);
            return $agendar('removido', 'Anúncio excluído ou indisponível na Meta.', 30 * 24 * 60);
        }
        if ($tipo === 'sem_permissao') {
            return $agendar('sem_permissao', $r['erro'], 24 * 60);
        }
        if ($tipo === 'limite') {
            return $agendar('erro', 'Limite de chamadas da Meta: nova tentativa em 1 h.', 60);
        }
        $esperas = [30, 120, 720, 1440];
        return $agendar('erro', $r['erro'], $esperas[min((int) $a['sync_tentativas'], 3)]);
    }
    $d = $r['data'];
    $c = (array) ($d['creative'] ?? []);
    $oss = (array) ($c['object_story_spec'] ?? []);
    $link = (array) ($oss['link_data'] ?? []);
    $video = (array) ($oss['video_data'] ?? []);
    $afs = (array) ($c['asset_feed_spec'] ?? []);
    $primeiro = fn (array $l, string $k) => isset($l[0][$k]) ? (string) $l[0][$k] : null;
    $texto = $c['body'] ?? ($link['message'] ?? ($video['message'] ?? $primeiro((array) ($afs['bodies'] ?? []), 'text')));
    $titulo = $c['title'] ?? ($link['name'] ?? ($video['title'] ?? $primeiro((array) ($afs['titles'] ?? []), 'text')));
    $cta = $c['call_to_action_type'] ?? ($link['call_to_action']['type'] ?? ($video['call_to_action']['type'] ?? (isset($afs['call_to_action_types'][0]) ? (string) $afs['call_to_action_types'][0] : null)));
    $videoId = $c['video_id'] ?? ($video['video_id'] ?? null);
    $carrossel = !empty($link['child_attachments']);
    $tipoMidia = $videoId ? 'video' : ($carrossel ? 'carrossel' : (!empty($c['image_url']) || !empty($c['thumbnail_url']) || !empty($link['picture']) ? 'imagem' : 'desconhecida'));
    $dinamico = !empty($afs) ? 1 : 0;

    // Link oficial: só o que a Meta devolveu, validado.
    $linkOficial = null;
    $fonte = null;
    if ($u = meta_url_oficial_valida($d['preview_shareable_link'] ?? null)) {
        [$linkOficial, $fonte] = [$u, 'preview_shareable_link'];
    } elseif ($u = meta_url_oficial_valida($c['instagram_permalink_url'] ?? null)) {
        [$linkOficial, $fonte] = [$u, 'instagram'];
    } elseif (!empty($c['effective_object_story_id']) && preg_match('/^\d+_\d+$/', (string) $c['effective_object_story_id'])) {
        $p = meta_graph('GET', (string) $c['effective_object_story_id'], ['fields' => 'permalink_url'], $token);
        if ($p['ok'] && ($u = meta_url_oficial_valida($p['data']['permalink_url'] ?? null))) {
            [$linkOficial, $fonte] = [$u, 'post_facebook'];
        } elseif (!$p['ok']) {
            meta_criativo_falha($empresaId, (int) $a['id'], 'link', $p['erro'] ?? 'Erro');
        }
    }
    $id = fn ($v) => ($x = preg_replace('/\D/', '', (string) $v)) !== '' ? mb_substr($x, 0, 40) : null;
    $txt = fn ($v, $n = 255) => ($t = mb_substr(trim((string) $v), 0, $n)) !== '' ? $t : null;
    $pdo->prepare('UPDATE meta_anuncios SET nome = COALESCE(:n, nome), adset_id = COALESCE(:as, adset_id), adset_nome = COALESCE(:asn, adset_nome),
            campanha_id = COALESCE(:c, campanha_id), campanha_nome = COALESCE(:cn, campanha_nome), conta_anuncios_id = COALESCE(:acc, conta_anuncios_id),
            criativo_id = :cr, titulo = :ti, texto = :tx, cta = :cta, midia_tipo = :mt, video_id = :v, link_oficial = :lo, link_fonte = :lf,
            dinamico = :din, status_meta = :st, removido_na_meta = :rem WHERE id = :id')
        ->execute(['n' => $txt($d['name'] ?? null), 'as' => $id($d['adset']['id'] ?? null), 'asn' => $txt($d['adset']['name'] ?? null),
                   'c' => $id($d['campaign']['id'] ?? null), 'cn' => $txt($d['campaign']['name'] ?? null), 'acc' => $id($d['account_id'] ?? null),
                   'cr' => $id($c['id'] ?? null), 'ti' => $txt($titulo), 'tx' => $txt($texto, 5000), 'cta' => $txt($cta, 60), 'mt' => $tipoMidia,
                   'v' => $id($videoId), 'lo' => $linkOficial, 'lf' => $fonte, 'din' => $dinamico, 'st' => $txt($d['effective_status'] ?? null, 40),
                   'rem' => in_array($d['effective_status'] ?? '', ['DELETED', 'ARCHIVED'], true) ? 1 : 0, 'id' => $a['id']]);

    // Imagem: a URL da Meta expira — guardamos uma cópia local.
    $imgUrl = $c['image_url'] ?? ($c['thumbnail_url'] ?? ($link['picture'] ?? ($video['image_url'] ?? null)));
    $parcial = false;
    if ($imgUrl) {
        $res = meta_criativo_baixar_imagem($empresaId, $a, (string) $imgUrl);
        if (!$res['ok']) {
            $parcial = true;
            meta_criativo_falha($empresaId, (int) $a['id'], 'imagem', $res['erro']);
        }
    }
    return $agendar($parcial ? 'parcial' : 'ok', $parcial ? 'Imagem do criativo não pôde ser copiada.' : null, ($parcial ? 1 : META_CRIATIVO_VALIDADE_DIAS * 24) * 60);
}

/** Baixa a imagem (só de CDNs da Meta), confere que é imagem de verdade e guarda no cache local. */
function meta_criativo_baixar_imagem(int $empresaId, array $anuncio, string $url): array
{
    if (!meta_host_midia_permitido($url)) {
        return ['ok' => false, 'erro' => 'Endereço da imagem fora dos domínios da Meta: ignorado.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'erro' => 'cURL indisponível.'];
    }
    $dados = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_USERAGENT => 'WeMartSales/1.0',
        CURLOPT_WRITEFUNCTION => function ($ch, $parte) use (&$dados) {
            if (strlen($dados) + strlen($parte) > META_CRIATIVO_MAX_BYTES) {
                return 0;   // aborta: grande demais
            }
            $dados .= $parte;
            return strlen($parte);
        },
    ]);
    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$ok || $status !== 200 || $dados === '') {
        return ['ok' => false, 'erro' => 'Não foi possível baixar a imagem (HTTP ' . $status . ').'];
    }
    $info = @getimagesizefromstring($dados);
    $tipos = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($tipos[$info[2]])) {
        return ['ok' => false, 'erro' => 'O arquivo recebido não é uma imagem válida.'];
    }
    $dir = META_CRIATIVO_DIR . '/' . $empresaId;
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        return ['ok' => false, 'erro' => 'Sem permissão para gravar em storage/criativos.'];
    }
    $nome = $empresaId . '/' . preg_replace('/\D/', '', $anuncio['ad_id']) . '_' . substr(sha1($dados), 0, 12) . '.' . $tipos[$info[2]];
    if (file_put_contents(META_CRIATIVO_DIR . '/' . $nome, $dados) === false) {
        return ['ok' => false, 'erro' => 'Falha ao gravar a imagem.'];
    }
    if ($anuncio['imagem_arquivo'] && $anuncio['imagem_arquivo'] !== $nome) {
        @unlink(META_CRIATIVO_DIR . '/' . basename(dirname($anuncio['imagem_arquivo'])) . '/' . basename($anuncio['imagem_arquivo']));
    }
    db()->prepare('UPDATE meta_anuncios SET imagem_arquivo = :a, imagem_baixada_em = NOW() WHERE id = :id')->execute(['a' => $nome, 'id' => $anuncio['id']]);
    return ['ok' => true];
}

/** Rotina (cron): anúncios novos, com erro vencido ou com cache vencido. */
function meta_criativos_processar(int $limite = 30): array
{
    $stmt = db()->query("SELECT id FROM meta_anuncios WHERE (proxima_sync_em IS NULL OR proxima_sync_em <= NOW())
                         AND sync_tentativas < 8 ORDER BY sync_status = 'pendente' DESC, id LIMIT " . max(1, $limite));
    $r = ['tentados' => 0, 'ok' => 0, 'falhas' => 0];
    foreach (array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)) as $id) {
        $r['tentados']++;
        try {
            $s = meta_criativo_sincronizar($id);
        } catch (Throwable $e) {
            $s = 'erro';
            error_log('[criativos] ' . $e->getMessage());
        }
        $r[in_array($s, ['ok', 'parcial', 'removido'], true) ? 'ok' : 'falhas']++;
    }
    return $r;
}

// ================================================================== leitura para a tela

/**
 * Dados de origem de um lead JÁ AUTORIZADO pelo chamador (rbac). Busca por
 * lead + empresa: nunca pelo id do anúncio vindo da requisição.
 */
function meta_criativo_do_lead(int $empresaId, int $leadId): ?array
{
    $stmt = db()->prepare('SELECT l.id AS lead_id, l.origem, l.campanha, l.criado_em, l.meta_ad_id, l.meta_adset_id, l.meta_campanha_id, l.meta_form_id,
                                  l.meta_plataforma, l.meta_cadastro_em, f.nome AS form_nome, p.nome AS pagina_nome, a.*
                           FROM leads l
                           LEFT JOIN meta_anuncios a ON a.id = l.meta_anuncio_id AND a.empresa_id = l.empresa_id
                           LEFT JOIN meta_formularios f ON f.form_id = l.meta_form_id AND f.empresa_id = l.empresa_id
                           LEFT JOIN meta_paginas p ON p.page_id = l.meta_page_id AND p.empresa_id = l.empresa_id
                           WHERE l.id = :l AND l.empresa_id = :e AND l.excluido_em IS NULL');
    $stmt->execute(['l' => $leadId, 'e' => $empresaId]);
    $x = $stmt->fetch();
    if (!$x || ($x['origem'] !== 'meta_ads' && !$x['meta_ad_id'] && !$x['meta_form_id'])) {
        return null;
    }
    $temImagem = $x['imagem_arquivo'] && is_file(META_CRIATIVO_DIR . '/' . $x['imagem_arquivo']);
    $avisos = [];
    if ($x['removido_na_meta']) {
        $avisos[] = 'O anúncio foi excluído ou arquivado na Meta. Os dados abaixo são os que guardamos quando o lead chegou.';
    }
    if ($x['dinamico']) {
        $avisos[] = 'Este anúncio usa variações automáticas da Meta: a prévia mostra uma versão do anúncio, não necessariamente a que o cliente viu.';
    }
    if ($x['meta_ad_id'] && in_array($x['sync_status'], ['pendente', 'erro'], true) && !$x['criativo_id']) {
        $avisos[] = 'Buscando os detalhes do anúncio na Meta. Volte em alguns minutos.';
    }
    if ($x['sync_status'] === 'sem_permissao') {
        $avisos[] = 'A conexão da empresa com a Meta não tem acesso aos detalhes deste anúncio. Peça ao administrador para conferir o token em Integrações.';
    }
    $plat = $x['meta_plataforma'] ? (meta_plataformas()[$x['meta_plataforma']] ?? $x['meta_plataforma']) : null;
    return [
        'lead_id' => (int) $x['lead_id'],
        'tem_anuncio' => (bool) $x['meta_ad_id'],
        'campanha' => $x['campanha_nome'] ?: $x['campanha'],
        'conjunto' => $x['adset_nome'],
        'anuncio' => $x['nome'],
        'formulario' => $x['form_nome'],
        'pagina' => $x['pagina_nome'],
        'titulo' => $x['titulo'],
        'texto' => $x['texto'],
        'cta' => meta_cta_rotulo($x['cta']),
        'midia_tipo' => $x['midia_tipo'],
        'imagem' => $temImagem ? '/api/criativo_midia.php?lead_id=' . (int) $x['lead_id'] . '&v=' . substr(sha1((string) $x['imagem_arquivo']), 0, 8) : null,
        'link_oficial' => meta_url_oficial_valida($x['link_oficial']),
        'link_rotulo' => ['instagram' => 'Ver no Instagram', 'post_facebook' => 'Ver no Facebook'][$x['link_fonte'] ?? ''] ?? 'Ver anúncio original',
        'plataforma' => $plat,
        'cadastro_em' => $x['meta_cadastro_em'] ?: $x['criado_em'],
        'avisos' => $avisos,
    ];
}

/**
 * Lead recém-chegado cujo anúncio ainda não foi consultado: tenta uma vez na
 * hora (quem abriu a ficha não precisa esperar a rotina). Depois disso, só a
 * rotina — respeita as esperas e os limites da API.
 */
function meta_criativo_sincronizar_se_novo(int $empresaId, int $leadId): void
{
    try {
        $stmt = db()->prepare("SELECT a.id FROM leads l JOIN meta_anuncios a ON a.id = l.meta_anuncio_id AND a.empresa_id = l.empresa_id
                               WHERE l.id = :l AND l.empresa_id = :e AND a.sync_status = 'pendente' AND a.sync_tentativas = 0
                                 AND (a.proxima_sync_em IS NULL OR a.proxima_sync_em <= NOW())");
        $stmt->execute(['l' => $leadId, 'e' => $empresaId]);
        if ($id = $stmt->fetchColumn()) {
            meta_criativo_sincronizar((int) $id);
        }
    } catch (Throwable $e) {
        error_log('[criativos] sincronização sob demanda: ' . $e->getMessage());
    }
}
