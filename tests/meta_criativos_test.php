<?php
/**
 * Testes — Origem do lead: anúncio e criativo do Meta Ads.
 * Tudo contra o simulador local da Graph API (tests/mock_graph.php): nenhuma
 * chamada sai para a Meta. Empresas fictícias, apagadas no final.
 *
 *   php tests/meta_criativos_test.php
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../includes/meta_criativos.php';
require_once __DIR__ . '/../includes/relatorio_anuncios.php';
require_once __DIR__ . '/../includes/transferencia.php';
require_once __DIR__ . '/../includes/funcoes.php';
require_once __DIR__ . '/../includes/funil.php';

$porta = 19900 + random_int(0, 90);
@mkdir(__DIR__ . '/tmp', 0775, true);
$proc = proc_open(PHP_BINARY . " -S 127.0.0.1:$porta " . escapeshellarg(__DIR__ . '/mock_graph.php'), [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
register_shutdown_function(function () use ($proc) {
    $st = proc_get_status($proc);
    if ($st['running']) {
        posix_kill($st['pid'], SIGTERM);
    }
    proc_close($proc);
});
usleep(500000);
$base = "http://127.0.0.1:$porta";
putenv("WEMART_GRAPH_BASE_URL=$base");

function cenario(array $rotas): void
{
    foreach (glob(__DIR__ . '/tmp/mock_cont_*') as $f) {
        unlink($f);
    }
    @unlink(__DIR__ . '/tmp/mock_log.jsonl');
    file_put_contents(__DIR__ . '/tmp/mock_cenario.json', json_encode(['rotas' => $rotas]));
}
function chamadas(string $regex): int
{
    $f = __DIR__ . '/tmp/mock_log.jsonl';
    $n = 0;
    foreach (is_file($f) ? array_filter(explode("\n", file_get_contents($f))) : [] as $l) {
        $j = json_decode($l, true);
        if (preg_match($regex, $j['caminho'])) {
            $n++;
        }
    }
    return $n;
}
function v(string $sql)
{
    return db()->query($sql)->fetchColumn();
}
$ok200 = fn (array $c) => ['status' => 200, 'corpo' => $c];
$erro = fn (int $http, int $code, int $sub = 0, string $msg = 'erro') => ['status' => $http, 'corpo' => ['error' => ['message' => $msg, 'code' => $code, 'error_subcode' => $sub]]];
$PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

grupo('Links oficiais e domínios (sem inventar URL)');
ok(meta_url_oficial_valida('https://fb.me/2aXyZ') !== null, 'link curto oficial da Meta (fb.me) aceito');
ok(meta_url_oficial_valida('https://www.facebook.com/123/posts/456') !== null, 'post do Facebook aceito');
ok(meta_url_oficial_valida('https://www.instagram.com/p/ABC123/') !== null, 'post do Instagram aceito');
ok(meta_url_oficial_valida('http://www.facebook.com/x') === null, 'sem https → recusado');
ok(meta_url_oficial_valida('https://facebook.com.golpe.com/x') === null, 'domínio que só "parece" Facebook → recusado');
ok(meta_url_oficial_valida('https://usuario:senha@facebook.com/x') === null, 'URL com usuário/senha → recusada');
ok(meta_url_oficial_valida('javascript:alert(1)') === null, 'javascript: → recusado');
ok(meta_url_oficial_valida('https://www.facebook.com/x" onmouseover="y') === null, 'aspas/espaços (injeção) → recusado');
ok(meta_host_midia_permitido('https://scontent.xx.fbcdn.net/v/t45/abc.jpg'), 'imagem do CDN da Meta permitida');
ok(!meta_host_midia_permitido('https://192.168.0.10/interno.jpg') && !meta_host_midia_permitido('https://exemplo.com/a.jpg'), 'imagem de outro servidor → não é baixada (evita uso do servidor como proxy)');
igual(meta_cta_rotulo('LEARN_MORE'), 'Saiba mais', 'CTA traduzido');
$o = meta_origem_do_lead(['ad_id' => '12<script>3', 'platform' => 'ig', 'campaign_name' => 'Lançamento'], ['page_id' => '99']);
ok($o['ad_id'] === '123' && $o['plataforma'] === 'ig' && $o['page_id'] === '99', 'ids só com dígitos; plataforma reconhecida');
ok(meta_origem_do_lead(['ad_id' => '555', 'is_organic' => true], [])['ad_id'] === null, 'cadastro orgânico não é atribuído a anúncio');

$emp = criar_empresa_teste('Criativos');
$emp2 = criar_empresa_teste('Criativos Outra');
try {
    $pdo = db();
    $f = funcoes_seed_padrao($emp);
    funil_seed_padrao($emp);
    $novoUsuario = function (string $nome, string $funcao, string $escopo) use ($pdo, $emp, $f) {
        $pdo->prepare('INSERT INTO usuarios (empresa_id, funcao_id, nome, email, senha_hash, cargo) VALUES (:e, :f, :n, :m, :h, :c)')
            ->execute(['e' => $emp, 'f' => $f[$funcao], 'n' => $nome, 'm' => bin2hex(random_bytes(5)) . '@teste.invalid', 'h' => 'x', 'c' => $funcao]);
        return ['id' => (int) $pdo->lastInsertId(), 'nome' => $nome, 'empresa_id' => $emp, 'empresa_ativa_id' => $emp, 'eh_superadmin' => false, 'escopo' => $escopo, 'permissoes' => ['visualizar_leads']];
    };
    $master = $novoUsuario('Master', 'master_empresa', 'toda_empresa');
    $c1 = $novoUsuario('Corretora Um', 'corretor', 'propria_carteira');
    $c2 = $novoUsuario('Corretor Dois', 'corretor', 'propria_carteira');
    $pageId = (string) random_int(10 ** 11, 10 ** 12);
    $pdo->prepare('INSERT INTO meta_paginas (empresa_id, page_id, nome, access_token_cifrado, ativa) VALUES (:e, :p, \'Página Teste\', :t, 1)')
        ->execute(['e' => $emp, 'p' => $pageId, 't' => cripto_cifrar('EAAPAGINA' . bin2hex(random_bytes(8)))]);
    $pagId = (int) $pdo->lastInsertId();
    $formId = (string) random_int(10 ** 11, 10 ** 12);
    $pdo->prepare("INSERT INTO meta_formularios (empresa_id, meta_pagina_id, form_id, nome, ativo) VALUES (:e, :p, :f, 'Formulário Lançamento', 1)")->execute(['e' => $emp, 'p' => $pagId, 'f' => $formId]);
    $adId = (string) random_int(10 ** 11, 10 ** 12);
    $evento = function (string $leadgen, ?string $ad) use ($pdo, $emp, $pageId, $formId) {
        $pdo->prepare("INSERT INTO meta_eventos (empresa_id, page_id, form_id, leadgen_id, payload, status) VALUES (:e, :p, :f, :l, :pl, 'pendente')")
            ->execute(['e' => $emp, 'p' => $pageId, 'f' => $formId, 'l' => $leadgen,
                       'pl' => json_encode(['leadgen_id' => $leadgen, 'page_id' => $pageId, 'form_id' => $formId, 'ad_id' => $ad, 'created_time' => time()])]);
        return (int) $pdo->lastInsertId();
    };
    $leadgenResp = fn (string $tel, ?string $ad = null) => $ok200([
        'created_time' => '2026-11-10T14:05:00+0000', 'form_id' => $formId, 'ad_id' => $ad ?? $adId, 'ad_name' => 'Vídeo decorado 2 dorm.',
        'adset_id' => '7001', 'adset_name' => 'Zona Oeste 25-45', 'campaign_id' => '6001', 'campaign_name' => 'Lançamento Smart — Novembro', 'platform' => 'ig',
        'field_data' => [['name' => 'full_name', 'values' => ['Cliente Criativo Teste']], ['name' => 'phone_number', 'values' => [$tel]]],
    ]);
    $tel = fn () => '+55119' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    $anuncioResp = fn (array $extra = []) => $ok200(array_replace_recursive([
        'id' => $adId, 'name' => 'Vídeo decorado 2 dorm.', 'effective_status' => 'ACTIVE', 'account_id' => '9001',
        'adset' => ['id' => '7001', 'name' => 'Zona Oeste 25-45'], 'campaign' => ['id' => '6001', 'name' => 'Lançamento Smart — Novembro'],
        'preview_shareable_link' => 'https://fb.me/1AbCdEfG',
        'creative' => ['id' => '8001', 'title' => 'Seu apê com varanda a 5 min do metrô', 'body' => "Entrada facilitada.\nAgende sua visita ao decorado!",
                       'call_to_action_type' => 'LEARN_MORE', 'image_url' => "$base/img/criativo.png"],
    ], $extra));
    $imagem = ['status' => 200, 'bruto_base64' => $PNG, 'tipo' => 'image/png'];

    grupo('Lead chega do Meta Ads: origem gravada sem atrasar o cadastro');
    cenario([
        ['metodo' => 'GET', 'caminho' => '#^/\d+$#', 'respostas' => [$leadgenResp($t1 = $tel())]],
    ]);
    $ev1 = $evento($lg1 = (string) random_int(10 ** 12, 10 ** 13), $adId);
    igual(meta_processar_evento($ev1), 'processado', 'lead criado normalmente');
    $L1 = (int) v("SELECT lead_id FROM meta_eventos WHERE id = $ev1");
    $l = $pdo->query("SELECT * FROM leads WHERE id = $L1")->fetch();
    ok($l['meta_ad_id'] === $adId && $l['meta_adset_id'] === '7001' && $l['meta_campanha_id'] === '6001' && $l['meta_form_id'] === $formId && $l['meta_page_id'] === $pageId, 'campanha, conjunto, anúncio, formulário e página gravados no lead');
    ok($l['meta_plataforma'] === 'ig' && $l['meta_cadastro_em'] !== null, 'plataforma (Instagram) e data do cadastro na Meta');
    $a = $pdo->query("SELECT * FROM meta_anuncios WHERE id = {$l['meta_anuncio_id']}")->fetch();
    ok($a['sync_status'] === 'pendente' && $a['campanha_nome'] === 'Lançamento Smart — Novembro' && $a['adset_nome'] === 'Zona Oeste 25-45', 'anúncio registrado com os nomes; criativo fica para a sincronização');
    igual(chamadas('#^/' . $adId . '$#'), 0, 'nenhuma chamada extra à Meta no recebimento do lead');

    grupo('Meta recusa os campos de origem: o lead entra mesmo assim');
    cenario([['metodo' => 'GET', 'caminho' => '#^/\d+$#', 'respostas' => [$erro(400, 100, 0, '(#100) Tried accessing nonexisting field (adset_name)'),
        $ok200(['created_time' => '2026-11-10T15:00:00+0000', 'form_id' => $formId, 'ad_id' => $adId, 'campaign_name' => 'Lançamento Smart — Novembro',
                'field_data' => [['name' => 'full_name', 'values' => ['Cliente Dois Teste']], ['name' => 'phone_number', 'values' => [$tel()]]]])]]]);
    $ev2 = $evento((string) random_int(10 ** 12, 10 ** 13), $adId);
    igual(meta_processar_evento($ev2), 'processado', 'lead criado com a busca simplificada');
    $L2 = (int) v("SELECT lead_id FROM meta_eventos WHERE id = $ev2");
    ok(v("SELECT meta_anuncio_id FROM leads WHERE id = $L2") == $l['meta_anuncio_id'], 'mesmo anúncio → o registro é reaproveitado (1 por anúncio)');
    igual((int) v("SELECT COUNT(*) FROM meta_anuncios WHERE empresa_id = $emp"), 1, 'nenhum registro duplicado');

    grupo('Sincronização do criativo');
    cenario([
        ['metodo' => 'GET', 'caminho' => '#^/' . $adId . '$#', 'respostas' => [$anuncioResp()]],
        ['metodo' => 'GET', 'caminho' => '#^/img/#', 'respostas' => [$imagem]],
    ]);
    $aid = (int) $l['meta_anuncio_id'];
    igual(meta_criativo_sincronizar($aid), 'ok', 'anúncio e criativo lidos da Marketing API');
    $a = $pdo->query("SELECT * FROM meta_anuncios WHERE id = $aid")->fetch();
    ok($a['titulo'] === 'Seu apê com varanda a 5 min do metrô' && str_contains($a['texto'], 'decorado') && $a['cta'] === 'LEARN_MORE' && $a['criativo_id'] === '8001' && $a['conta_anuncios_id'] === '9001', 'título, texto, CTA, criativo e conta guardados');
    ok($a['link_oficial'] === 'https://fb.me/1AbCdEfG' && $a['link_fonte'] === 'preview_shareable_link', 'link oficial vindo da própria Meta');
    ok($a['imagem_arquivo'] && is_file(META_CRIATIVO_DIR . '/' . $a['imagem_arquivo']), 'imagem copiada para o cache local (a URL da Meta é temporária)');
    ok(!str_contains((string) $a['imagem_arquivo'], 'http'), 'a URL temporária da Meta não é usada como permanente');
    $antes = chamadas('#^/' . $adId . '$#');
    meta_criativo_sincronizar_se_novo($emp, $L2);
    meta_criativos_processar(10);
    igual(chamadas('#^/' . $adId . '$#'), $antes, 'cache válido por 7 dias: nenhuma nova chamada à API');

    $cv = meta_criativo_do_lead($emp, $L1);
    ok($cv['campanha'] === 'Lançamento Smart — Novembro' && $cv['conjunto'] === 'Zona Oeste 25-45' && $cv['anuncio'] === 'Vídeo decorado 2 dorm.', 'ficha: campanha, conjunto e anúncio');
    ok($cv['plataforma'] === 'Instagram' && $cv['cta'] === 'Saiba mais' && $cv['formulario'] === 'Formulário Lançamento', 'ficha: plataforma, CTA e formulário');
    ok(str_starts_with((string) $cv['imagem'], '/api/criativo_midia.php?lead_id=' . $L1), 'a imagem é servida pelo LEAD (nunca pelo id do anúncio)');
    igual($cv['avisos'], [], 'sem avisos para um anúncio normal');

    grupo('Variações, links e mídia fora do padrão');
    $ad2 = (string) random_int(10 ** 11, 10 ** 12);
    $pdo->prepare("INSERT INTO meta_anuncios (empresa_id, ad_id, pagina_id) VALUES (:e, :a, :p)")->execute(['e' => $emp, 'a' => $ad2, 'p' => $pageId]);
    $aid2 = (int) $pdo->lastInsertId();
    cenario([
        ['metodo' => 'GET', 'caminho' => '#^/' . $ad2 . '$#', 'respostas' => [$ok200(['id' => $ad2, 'name' => 'Dinâmico', 'preview_shareable_link' => 'https://golpe.example/x',
            'creative' => ['id' => '8002', 'effective_object_story_id' => '111_222', 'image_url' => 'https://servidor-qualquer.example/a.jpg',
                           'asset_feed_spec' => ['bodies' => [['text' => 'Texto da variação 1']], 'titles' => [['text' => 'Título variação']], 'call_to_action_types' => ['SIGN_UP']]]])]],
        ['metodo' => 'GET', 'caminho' => '#^/111_222$#', 'respostas' => [$ok200(['permalink_url' => 'https://www.facebook.com/111/posts/222'])]],
    ]);
    igual(meta_criativo_sincronizar($aid2), 'parcial', 'imagem de domínio fora da Meta não é baixada → sincronização parcial');
    $a2 = $pdo->query("SELECT * FROM meta_anuncios WHERE id = $aid2")->fetch();
    ok($a2['link_oficial'] === 'https://www.facebook.com/111/posts/222' && $a2['link_fonte'] === 'post_facebook', 'link inválido da Meta descartado; usado o permalink oficial do post');
    ok((int) $a2['dinamico'] === 1 && $a2['texto'] === 'Texto da variação 1' && $a2['cta'] === 'SIGN_UP', 'criativo dinâmico: marcado, com texto de uma das variações');
    ok((int) v("SELECT COUNT(*) FROM meta_criativo_falhas WHERE meta_anuncio_id = $aid2 AND etapa = 'imagem'") === 1, 'falha da imagem registrada');
    $pdo->prepare('UPDATE leads SET meta_anuncio_id = :a, meta_ad_id = :ad WHERE id = :l')->execute(['a' => $aid2, 'ad' => $ad2, 'l' => $L2]);
    $cv2 = meta_criativo_do_lead($emp, $L2);
    ok($cv2['imagem'] === null && (bool) array_filter($cv2['avisos'], fn ($x) => str_contains($x, 'variações automáticas')), 'sem imagem → "Criativo indisponível"; aviso de que a prévia é uma versão do anúncio');
    cenario([['metodo' => 'GET', 'caminho' => '#^/' . $ad2 . '$#', 'respostas' => [$ok200(['id' => $ad2, 'creative' => ['id' => '8002', 'image_url' => "$base/img/falsa.png"]])]],
             ['metodo' => 'GET', 'caminho' => '#^/img/#', 'respostas' => [['status' => 200, 'bruto_base64' => base64_encode('<?php echo "x"; ?>'), 'tipo' => 'image/png']]]]);
    igual(meta_criativo_sincronizar($aid2), 'parcial', 'arquivo que diz ser imagem mas não é → recusado');

    grupo('Anúncio excluído, sem acesso e limites da API');
    $pdo->exec("UPDATE meta_anuncios SET proxima_sync_em = NULL WHERE id = $aid");
    cenario([['metodo' => 'GET', 'caminho' => '#^/' . $adId . '$#', 'respostas' => [$erro(400, 100, 33, 'Unsupported get request. Object with ID does not exist')]]]);
    igual(meta_criativo_sincronizar($aid), 'removido', 'anúncio excluído na Meta → marcado como removido');
    $a = $pdo->query("SELECT * FROM meta_anuncios WHERE id = $aid")->fetch();
    ok($a['nome'] === 'Vídeo decorado 2 dorm.' && $a['campanha_nome'] === 'Lançamento Smart — Novembro' && is_file(META_CRIATIVO_DIR . '/' . $a['imagem_arquivo']), 'os dados e a prévia guardados continuam (histórico preservado)');
    ok((bool) array_filter(meta_criativo_do_lead($emp, $L1)['avisos'], fn ($x) => str_contains($x, 'excluído')), 'ficha avisa que o anúncio foi excluído');
    $ad3 = (string) random_int(10 ** 11, 10 ** 12);
    $pdo->prepare("INSERT INTO meta_anuncios (empresa_id, ad_id, pagina_id) VALUES (:e, :a, :p)")->execute(['e' => $emp, 'a' => $ad3, 'p' => $pageId]);
    $aid3 = (int) $pdo->lastInsertId();
    cenario([['metodo' => 'GET', 'caminho' => '#^/' . $ad3 . '$#', 'respostas' => [$erro(403, 200, 0, '(#200) Ad account owner has NOT grant ads_management or ads_read permission'), $erro(400, 17, 0, 'User request limit reached')]]]);
    igual(meta_criativo_sincronizar($aid3), 'sem_permissao', 'sem permissão de leitura → "sem acesso"');
    $pdo->exec("UPDATE meta_anuncios SET proxima_sync_em = NULL WHERE id = $aid3");
    igual(meta_criativo_sincronizar($aid3), 'erro', 'limite de chamadas → erro temporário');
    ok((int) v("SELECT TIMESTAMPDIFF(MINUTE, NOW(), proxima_sync_em) FROM meta_anuncios WHERE id = $aid3") >= 55, 'limite respeitado: nova tentativa só depois de ~1 h');
    $chamadasAntes = chamadas('#^/' . $ad3 . '$#');
    meta_criativos_processar(10);
    igual(chamadas('#^/' . $ad3 . '$#'), $chamadasAntes, 'a rotina não insiste antes da hora');
    $pdo->exec("UPDATE meta_paginas SET ativa = 0 WHERE id = $pagId");
    $pdo->exec("UPDATE meta_anuncios SET proxima_sync_em = NULL WHERE id = $aid3");
    igual(meta_criativo_sincronizar($aid3), 'sem_permissao', 'sem token nenhum (página desligada, sem Marketing API) → não chama a Meta');
    $pdo->exec("UPDATE meta_paginas SET ativa = 1 WHERE id = $pagId");

    grupo('Token da Marketing API da empresa tem prioridade');
    $tokenMk = 'EAAMKT' . bin2hex(random_bytes(10));
    $pdo->prepare('INSERT INTO meta_marketing (empresa_id, token_cifrado, validado_em) VALUES (:e, :t, NOW())')->execute(['e' => $emp, 't' => cripto_cifrar($tokenMk)]);
    $pdo->exec("UPDATE meta_anuncios SET proxima_sync_em = NULL WHERE id = $aid3");
    cenario([['metodo' => 'GET', 'caminho' => '#^/' . $ad3 . '$#', 'respostas' => [$ok200(['id' => $ad3, 'name' => 'Com token de anúncios', 'creative' => ['id' => '8003']])]]]);
    meta_criativo_sincronizar($aid3);
    $log = array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents(__DIR__ . '/tmp/mock_log.jsonl'))));
    ok(str_contains($log[0]['query'] ?? '', $tokenMk) || str_contains($log[0]['auth'] ?? '', $tokenMk), 'usa o token da Marketing API da empresa');
    ok(!str_contains((string) v("SELECT GROUP_CONCAT(COALESCE(sync_erro,'')) FROM meta_anuncios WHERE empresa_id = $emp") . (string) v("SELECT GROUP_CONCAT(erro) FROM meta_criativo_falhas WHERE empresa_id = $emp"), 'EAA'), 'nenhum token aparece nos erros gravados');

    grupo('Permissões, empresas e transferência');
    $pdo->exec("UPDATE leads SET responsavel_id = {$c1['id']} WHERE id = $L1");
    ok(lead_carregar($c1, $L1) !== null && lead_carregar($c2, $L1) === null, 'só quem pode ver o lead chega à origem (corretor de outra carteira não)');
    igual(meta_criativo_do_lead($emp2, $L1), null, 'outra empresa não lê a origem nem com o id do lead');
    lead_transferir($pdo->query("SELECT * FROM leads WHERE id = $L1")->fetch(), $c2['id'], 'administrativa', $master);
    ok((int) v("SELECT meta_anuncio_id FROM leads WHERE id = $L1") === $aid && lead_carregar($c2, $L1) !== null && lead_carregar($c1, $L1) === null, 'transferência mantém a origem; o acesso passa para o novo corretor');
    ok(preg_match('#^\d+/\d+_[0-9a-f]{12}\.(jpg|png|webp|gif)$#', (string) v("SELECT imagem_arquivo FROM meta_anuncios WHERE id = $aid")) === 1, 'nome do arquivo em cache no formato esperado pelo endpoint (sem caminho vindo do usuário)');

    grupo('Relatório por campanha e anúncio (dados reais do CRM)');
    $pdo->prepare('UPDATE leads SET meta_anuncio_id = :a, meta_ad_id = :ad WHERE id = :l')->execute(['a' => $aid, 'ad' => $adId, 'l' => $L2]);
    $pdo->prepare("INSERT INTO leads (empresa_id, nome, telefone, origem, etapa, meta_anuncio_id, meta_ad_id) VALUES (:e, 'Outro anúncio Teste', '11999990001', 'meta_ads', 'novo_lead', :a, :ad)")
        ->execute(['e' => $emp, 'a' => $aid2, 'ad' => $ad2]);
    $pdo->exec("UPDATE leads SET primeiro_contato_em = NOW() WHERE id = $L1");
    $pdo->prepare("INSERT INTO agenda_compromissos (empresa_id, lead_id, usuario_id, tipo, titulo, inicio, status) VALUES (:e, :l, :u, 'visita', 'Visita', DATE_ADD(NOW(), INTERVAL 1 DAY), 'agendado')")->execute(['e' => $emp, 'l' => $L1, 'u' => $c2['id']]);
    $fil = dash_filtros($master, ['periodo' => '30d']);
    $camp = relanu_linhas($master, $fil, 'campanha');
    $lin = array_values(array_filter($camp, fn ($x) => $x['nome'] === 'Lançamento Smart — Novembro'))[0] ?? null;
    ok($lin && $lin['leads'] === 2 && $lin['contatados'] === 1 && $lin['visitas'] === 1 && $lin['contratos'] === 0, 'por campanha: leads, 1º contato, visitas e contratos');
    igual(round($lin['taxa_contato']), 50.0, 'taxa de 1º contato calculada (50%)');
    $anu = relanu_linhas($master, $fil, 'anuncio');
    ok(count($anu) === 2, 'por anúncio: um anúncio por linha');
    ok(!array_key_exists('investimento', $lin) && !array_key_exists('cpl', $lin), 'sem investimento/CPL inventados');
    igual(count(relanu_linhas($c1, $fil, 'campanha')), 0, 'corretor vê só a própria carteira no relatório');
} finally {
    foreach (glob(META_CRIATIVO_DIR . '/' . $emp . '/*') ?: [] as $arq) {
        @unlink($arq);
    }
    @rmdir(META_CRIATIVO_DIR . '/' . $emp);
    apagar_empresa_teste($emp);
    apagar_empresa_teste($emp2);
    foreach (glob(__DIR__ . '/tmp/mock_*') as $arq) {
        @unlink($arq);
    }
    grupo('Limpeza');
    igual((int) db()->query("SELECT COUNT(*) FROM empresas WHERE nome LIKE 'Teste automatizado%'")->fetchColumn(), 0, 'nenhum dado de teste ficou no banco');
}
resumo_e_sair();
