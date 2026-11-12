<?php
/**
 * Testes da FASE 2 — integração com a Cloud API do WhatsApp.
 *
 * Nada é enviado para a Meta: o teste sobe um SIMULADOR da Graph API
 * (tests/mock_graph.php) e o próprio sistema (para o webhook), ambos em
 * 127.0.0.1, com uma empresa fictícia apagada no final.
 *
 *   php tests/whatsapp_fase2_test.php
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../includes/whatsapp.php';
require_once __DIR__ . '/../includes/automacao.php';

// ------------------------------------------------------------------ servidores locais
$portaMock = 18000 + random_int(0, 900);
$portaApp = $portaMock + 1000;
$tmp = __DIR__ . '/tmp';
@mkdir($tmp, 0775, true);
$procs = [];
$subir = function (string $cmd) use (&$procs) {
    $procs[] = proc_open($cmd, [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
};
$subir(PHP_BINARY . " -S 127.0.0.1:$portaMock " . escapeshellarg(__DIR__ . '/mock_graph.php'));
$subir(PHP_BINARY . " -S 127.0.0.1:$portaApp -t " . escapeshellarg(dirname(__DIR__)));
register_shutdown_function(function () use (&$procs) {
    foreach ($procs as $p) {
        $st = proc_get_status($p);
        if ($st['running']) {
            posix_kill($st['pid'], SIGTERM);
        }
        proc_close($p);
    }
});
usleep(600000);
putenv("WEMART_GRAPH_BASE_URL=http://127.0.0.1:$portaMock");

function cenario(array $rotas): void
{
    $dir = __DIR__ . '/tmp';
    foreach (glob("$dir/mock_cont_*") as $f) {
        unlink($f);
    }
    @unlink("$dir/mock_log.jsonl");
    file_put_contents("$dir/mock_cenario.json", json_encode(['rotas' => $rotas]));
}
function mock_log(): array
{
    $f = __DIR__ . '/tmp/mock_log.jsonl';
    return is_file($f) ? array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", file_get_contents($f)))) : [];
}
function rota(string $metodo, string $regex, array ...$respostas): array
{
    return ['metodo' => $metodo, 'caminho' => $regex, 'respostas' => $respostas];
}
function ok200(array $corpo): array
{
    return ['status' => 200, 'corpo' => $corpo];
}
function erroMeta(int $http, int $code, string $msg = 'erro'): array
{
    return ['status' => $http, 'corpo' => ['error' => ['message' => $msg, 'code' => $code, 'error_data' => ['details' => $msg]]]];
}
/** POST assinado no webhook do sistema; devolve [http, corpo]. */
function webhook(array $payload, ?string $assinatura = 'certa'): array
{
    global $portaApp;
    $json = json_encode($payload);
    $cab = ['Content-Type: application/json'];
    if ($assinatura === 'certa') {
        $cab[] = 'X-Hub-Signature-256: sha256=' . hash_hmac('sha256', $json, META_APP_SECRET);
    } elseif ($assinatura !== null) {
        $cab[] = 'X-Hub-Signature-256: sha256=' . str_repeat('0', 64);
    }
    $ch = curl_init("http://127.0.0.1:$portaApp/webhook/whatsapp.php");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => $cab, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $corpo = curl_exec($ch);
    $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, (string) $corpo];
}
function webhook_get(string $query): array
{
    global $portaApp;
    $ch = curl_init("http://127.0.0.1:$portaApp/webhook/whatsapp.php?$query");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $corpo = curl_exec($ch);
    $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, (string) $corpo];
}
function msg_payload(string $pnid, string $from, string $wamid, array $mensagem, string $nome = 'Cliente Teste'): array
{
    return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => [
        'messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '5511900000000', 'phone_number_id' => $pnid],
        'contacts' => [['profile' => ['name' => $nome], 'wa_id' => $from]],
        'messages' => [$mensagem + ['from' => $from, 'id' => $wamid, 'timestamp' => (string) time()]],
    ]]]]]];
}
function status_payload(string $pnid, string $wamid, string $status, array $extra = []): array
{
    return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => [
        'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => $pnid],
        'statuses' => [$extra + ['id' => $wamid, 'status' => $status, 'timestamp' => (string) time(), 'recipient_id' => '5511988776655']],
    ]]]]]];
}
function valor(string $sql)
{
    return db()->query($sql)->fetchColumn();
}

// =================================================================== montagem e erros (sem rede)
grupo('Montagem das mensagens para a Cloud API');
$b = wa_api_corpo_template('5511988887777', 'boas_vindas', 'pt_BR', ['2' => 'HM Vendas', '1' => 'Ana']);
igual($b['template']['components'][0]['parameters'][0]['text'], 'Ana', 'variáveis numeradas vão na ordem {{1}}, {{2}} mesmo fora de ordem no array');
igual($b['template']['language']['code'], 'pt_BR', 'idioma do template');
$b = wa_api_corpo_template('5511988887777', 'boas_vindas', 'pt_BR', ['nome' => 'Ana']);
igual($b['template']['components'][0]['parameters'][0]['parameter_name'] ?? null, 'nome', 'variável nomeada leva parameter_name');
$b = wa_api_corpo_template('5511988887777', 'sem_variaveis', 'pt_BR', []);
ok(!isset($b['template']['components']), 'template sem variáveis não manda componentes vazios');
$b = wa_api_corpo_botoes('55', 'Quer o book?', [['id' => 'pedir_book', 'titulo' => 'Quero receber o book agora']]);
igual(mb_strlen($b['interactive']['action']['buttons'][0]['reply']['title']), 20, 'título de botão cortado em 20 caracteres (limite da Meta)');
igual(wa_api_corpo_texto('55', 'oi')['text']['preview_url'], false, 'texto livre sem pré-visualização de link');

grupo('Tradução dos erros da Meta');
$e = wa_api_classificar_erro(401, ['code' => 190, 'message' => 'expired']);
ok(!$e['temporario'] && $e['efeito'] === 'conta_erro', '190 (token expirado) → definitivo e marca a conta com erro');
ok(wa_api_classificar_erro(400, ['code' => 130429])['temporario'], '130429 (limite por segundo) → tentar de novo');
ok(wa_api_classificar_erro(400, ['code' => 131056])['temporario'], '131056 (muitas para o mesmo número) → tentar de novo');
$e = wa_api_classificar_erro(400, ['code' => 131047]);
ok(!$e['temporario'] && $e['efeito'] === 'janela_fechada', '131047 (fora das 24 h) → definitivo');
igual(wa_api_classificar_erro(400, ['code' => 131050])['efeito'], 'opt_out_marketing', '131050 → cliente saiu do marketing');
igual(wa_api_classificar_erro(400, ['code' => 132015])['efeito'], 'template_pausado', '132015 → template pausado');
ok(!wa_api_classificar_erro(400, ['code' => 999999])['temporario'], 'código desconhecido com 400 → não insiste');
ok(wa_api_classificar_erro(503, null)['temporario'], 'HTTP 503 sem corpo → tentar de novo');
ok(wa_api_classificar_erro(0, null, 'timeout')['temporario'], 'sem conexão → tentar de novo');
ok(!str_contains(wa_api_classificar_erro(400, ['code' => 100, 'message' => 'x access_token=SEGREDO123'], null, [])['mensagem'], 'SEGREDO123'), 'token nunca aparece na mensagem de erro');

grupo('Variantes do número brasileiro (com e sem o 9)');
igual(wa_variantes_telefone('5511988776655'), ['5511988776655', '551188776655'], 'celular com 9 → também sem 9');
igual(wa_variantes_telefone('551188776655'), ['551188776655', '5511988776655'], 'celular sem 9 → também com 9');
igual(wa_variantes_telefone('551133334444'), ['551133334444'], 'fixo não ganha variante');

// =================================================================== com banco + servidores
$empA = criar_empresa_teste('WA-A');
$empB = criar_empresa_teste('WA-B');
$pnA = (string) random_int(100000000, 999999999);
$pnB = (string) random_int(100000000, 999999999);
$TOKEN = 'EAATESTE' . bin2hex(random_bytes(16));
try {
    $pdo = db();
    $pdo->prepare("INSERT INTO whatsapp_contas (empresa_id, nome, waba_id, phone_number_id, access_token_cifrado, status, modo_teste, token_validado_em, webhook_assinado_em)
                   VALUES (:e, 'Conta A', '111222333', :p, :t, 'ativa', 0, NOW(), NOW())")->execute(['e' => $empA, 'p' => $pnA, 't' => cripto_cifrar($TOKEN)]);
    $contaA = wa_conta_da_empresa($empA);
    $pdo->prepare("INSERT INTO whatsapp_contas (empresa_id, nome, phone_number_id, status, modo_teste) VALUES (:e, 'Conta B', :p, 'ativa', 1)")->execute(['e' => $empB, 'p' => $pnB]);
    $pdo->prepare("INSERT INTO leads (empresa_id, nome, telefone) VALUES (:e, 'Ana Souza Teste', '(11) 98877-6655')")->execute(['e' => $empA]);
    $leadA = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO leads (empresa_id, nome, telefone) VALUES (:e, 'Ana da Empresa B', '11 98877-6655')")->execute(['e' => $empB]);
    $leadB = (int) $pdo->lastInsertId();

    grupo('Segredo do token');
    ok(!str_contains((string) $contaA['access_token_cifrado'], $TOKEN), 'token guardado cifrado no banco');
    igual(wa_conta_token($contaA), $TOKEN, 'token decifrado só no servidor');

    grupo('Webhook: verificação e assinatura');
    [$st, $c] = webhook_get('hub.mode=subscribe&hub.verify_token=' . urlencode(wa_verify_token()) . '&hub.challenge=12345');
    ok($st === 200 && $c === '12345', 'verificação com o token certo devolve o desafio');
    [$st] = webhook_get('hub.mode=subscribe&hub.verify_token=errado&hub.challenge=12345');
    igual($st, 403, 'token de verificação errado → 403');
    $p = msg_payload($pnA, '5511988776655', 'wamid.A1', ['type' => 'text', 'text' => ['body' => 'Tenho interesse. Qual é o valor da entrada?']]);
    [$st] = webhook($p, null);
    igual($st, 403, 'sem assinatura → 403');
    [$st] = webhook($p, 'errada');
    igual($st, 403, 'assinatura errada → 403');
    igual((int) valor("SELECT COUNT(*) FROM whatsapp_webhook_eventos WHERE chave_unica = 'msg:wamid.A1'"), 0, 'nada gravado com assinatura inválida');

    grupo('Webhook: mensagem recebida');
    [$st, $c] = webhook($p);
    ok($st === 200 && $c === 'EVENT_RECEIVED', 'assinatura certa → 200');
    $contato = db()->query("SELECT * FROM whatsapp_contatos WHERE empresa_id = $empA AND telefone = '5511988776655'")->fetch();
    ok($contato && (int) $contato['lead_id'] === $leadA, 'contato criado e ligado ao lead pelo telefone "(11) 98877-6655"');
    igual($contato['nome_perfil'], 'Cliente Teste', 'nome do perfil do WhatsApp guardado');
    $conv = db()->query("SELECT * FROM whatsapp_conversas WHERE empresa_id = $empA AND contato_id = {$contato['id']}")->fetch();
    ok($conv && (int) $conv['nao_lidas'] === 1 && (int) $conv['lead_id'] === $leadA, 'conversa ligada ao lead com 1 não lida');
    igual(valor("SELECT conteudo FROM whatsapp_mensagens WHERE wamid = 'wamid.A1'"), 'Tenho interesse. Qual é o valor da entrada?', 'texto da mensagem registrado');
    ok(wa_regra_janela_aberta(valor("SELECT ultima_entrada_em FROM whatsapp_contatos WHERE id = {$contato['id']}")), 'janela de 24 h aberta pela mensagem do cliente');
    ok((int) valor("SELECT COUNT(*) FROM lead_eventos WHERE lead_id = $leadA AND descricao LIKE 'Cliente enviou pelo WhatsApp%'") === 1, 'mensagem aparece no histórico do lead');
    igual(valor("SELECT ultima_atividade_em FROM leads WHERE id = $leadA"), null, 'mensagem do cliente NÃO conta como atividade do corretor');
    igual((int) valor("SELECT COUNT(*) FROM whatsapp_contatos WHERE empresa_id = $empB"), 0, 'empresa B (mesmo telefone de lead) não recebeu nada');

    grupo('Webhook: aviso repetido (a Meta reenvia)');
    webhook($p);
    webhook($p);
    igual((int) valor("SELECT COUNT(*) FROM whatsapp_mensagens WHERE wamid = 'wamid.A1'"), 1, 'mesma mensagem 3 vezes → registrada 1 vez');
    igual((int) valor("SELECT nao_lidas FROM whatsapp_conversas WHERE id = {$conv['id']}"), 1, 'não lidas não infla com repetição');

    grupo('Webhook: número antigo sem o 9 e resposta de botão');
    webhook(msg_payload($pnA, '551188776655', 'wamid.A2', ['type' => 'button', 'button' => ['text' => 'Quero receber o book', 'payload' => 'Quero receber o book']]));
    igual((int) valor("SELECT COUNT(*) FROM whatsapp_contatos WHERE empresa_id = $empA"), 1, 'mesmo cliente com wa_id sem o 9 → mesmo contato');
    $m2 = db()->query("SELECT tipo, botao_payload FROM whatsapp_mensagens WHERE wamid = 'wamid.A2'")->fetch();
    ok($m2['tipo'] === 'botao' && $m2['botao_payload'] === 'Quero receber o book', 'toque no botão registrado com o payload');
    webhook(msg_payload($pnA, '5511988776655', 'wamid.A3', ['type' => 'reaction', 'reaction' => ['message_id' => 'wamid.X', 'emoji' => '👍']]));
    igual((int) valor("SELECT nao_lidas FROM whatsapp_conversas WHERE id = {$conv['id']}"), 2, 'reação (👍) não conta como não lida');

    grupo('Webhook: número desconhecido e isolamento');
    webhook(msg_payload('999999999999', '5511977776666', 'wamid.X9', ['type' => 'text', 'text' => ['body' => 'oi']]));
    igual(valor("SELECT status FROM whatsapp_webhook_eventos WHERE chave_unica = 'msg:wamid.X9'"), 'ignorado', 'número que não é de nenhuma empresa → ignorado');
    igual((int) valor("SELECT COUNT(*) FROM whatsapp_mensagens WHERE wamid = 'wamid.X9'"), 0, 'nenhuma mensagem criada para número desconhecido');
    webhook(msg_payload($pnB, '5511988776655', 'wamid.B1', ['type' => 'text', 'text' => ['body' => 'oi B']]));
    $cB = db()->query("SELECT * FROM whatsapp_contatos WHERE empresa_id = $empB")->fetch();
    ok($cB && (int) $cB['lead_id'] === $leadB, 'mensagem para o número da empresa B vai para o lead da B');
    ok((int) $cB['id'] !== (int) $contato['id'], 'contatos separados por empresa');

    grupo('Descadastro pela palavra PARAR');
    $pdo->prepare("INSERT INTO leads (empresa_id, nome, telefone) VALUES (:e, 'Carlos Teste', '11 97777-1111')")->execute(['e' => $empA]);
    webhook(msg_payload($pnA, '5511977771111', 'wamid.P1', ['type' => 'text', 'text' => ['body' => 'Parar']]));
    $cp = db()->query("SELECT * FROM whatsapp_contatos WHERE empresa_id = $empA AND telefone = '5511977771111'")->fetch();
    ok($cp['opt_out_em'] !== null, '"Parar" → contato descadastrado');
    webhook(msg_payload($pnA, '5511977771111', 'wamid.P2', ['type' => 'text', 'text' => ['body' => 'Não quero parar de receber, me liga']]));
    igual(valor("SELECT conteudo FROM whatsapp_mensagens WHERE wamid = 'wamid.P2'"), 'Não quero parar de receber, me liga', 'frase com "parar" é só registrada (não é descadastro novo)');

    // ------------------------------------------------------------------ envio
    wa_consentimento_registrar($empA, (int) $contato['id'], 'atendimento', 'concedido', 'formulario_meta', 'Formulário de teste');
    $pdo->prepare("INSERT INTO whatsapp_templates (empresa_id, conta_id, nome, idioma, categoria, status, finalidade, corpo, variaveis)
                   VALUES (:e, :c, 'boas_vindas_v1', 'pt_BR', 'utility', 'aprovado', 'boas_vindas', 'Olá, {{1}}! Aqui é a {{2}}.', :v)")
        ->execute(['e' => $empA, 'c' => $contaA['id'], 'v' => json_encode(['1' => 'lead.primeiro_nome', '2' => 'empresa.nome'])]);
    $tplId = (int) $pdo->lastInsertId();
    $dados = wa_dados_lead($empA, $leadA);

    grupo('Envio real (contra o simulador da Meta)');
    cenario([rota('POST', '#/messages$#', ok200(['messaging_product' => 'whatsapp', 'contacts' => [['input' => '5511988776655', 'wa_id' => '5511988776655']], 'messages' => [['id' => 'wamid.OUT1']]]))]);
    $r = wa_enfileirar($empA, (int) $contato['id'], ['tipo' => 'template', 'template_id' => $tplId, 'dados' => $dados], ['ignorar_horario' => true, 'idempotencia' => 'teste:boasvindas:' . $leadA]);
    ok($r['ok'], 'template de boas-vindas entrou na fila');
    igual(valor("SELECT status FROM whatsapp_mensagens WHERE id = {$r['mensagem_id']}"), 'na_fila', 'antes do cron: na fila (nada enviado ainda)');
    igual(count(mock_log()), 0, 'nada chegou na Meta só por enfileirar');
    $r2 = wa_enfileirar($empA, (int) $contato['id'], ['tipo' => 'template', 'template_id' => $tplId, 'dados' => $dados], ['ignorar_horario' => true, 'idempotencia' => 'teste:boasvindas:' . $leadA]);
    ok($r2['ok'] && !empty($r2['duplicada']) && $r2['mensagem_id'] === $r['mensagem_id'], 'mesma chave de idempotência → não cria segunda mensagem');
    fila_executar_chave('wa_enviar:' . $r['mensagem_id'], automacao_tratadores());
    $log = mock_log();
    igual(count($log), 1, 'uma chamada à Meta');
    igual($log[0]['caminho'], '/' . $pnA . '/messages', 'POST /{phone_number_id}/messages');
    igual($log[0]['auth'], 'Bearer ' . $TOKEN, 'token no cabeçalho Authorization');
    ok(!str_contains($log[0]['query'], $TOKEN), 'token nunca vai na URL');
    igual($log[0]['corpo']['template']['name'] ?? null, 'boas_vindas_v1', 'nome do template');
    igual(array_column($log[0]['corpo']['template']['components'][0]['parameters'] ?? [], 'text'), ['Ana', $dados['empresa.nome']], 'variáveis preenchidas com o primeiro nome e a empresa');
    igual($log[0]['corpo']['to'], '5511988776655', 'destino = wa_id do cliente');
    $m = db()->query("SELECT * FROM whatsapp_mensagens WHERE id = {$r['mensagem_id']}")->fetch();
    ok($m['status'] === 'enviada' && $m['wamid'] === 'wamid.OUT1', 'mensagem marcada como enviada com o wamid da Meta');
    ok((int) valor("SELECT COUNT(*) FROM lead_eventos WHERE lead_id = $leadA AND descricao LIKE 'Mensagem automática enviada%'") === 1, 'envio aparece no histórico do lead');

    grupo('Status de entrega pelo webhook (inclusive fora de ordem)');
    webhook(status_payload($pnA, 'wamid.OUT1', 'read'));
    $m = db()->query("SELECT * FROM whatsapp_mensagens WHERE id = {$r['mensagem_id']}")->fetch();
    ok($m['status'] === 'lida' && $m['lida_em'] && $m['entregue_em'], '"lida" chegou antes de "entregue" → lida, com os dois horários');
    webhook(status_payload($pnA, 'wamid.OUT1', 'delivered'));
    igual(valor("SELECT status FROM whatsapp_mensagens WHERE id = {$r['mensagem_id']}"), 'lida', '"entregue" atrasado não volta o status');
    webhook(status_payload($pnA, 'wamid.OUT1', 'sent', ['pricing' => ['billable' => true, 'category' => 'utility']]));
    igual((int) valor("SELECT cobravel FROM whatsapp_mensagens WHERE id = {$r['mensagem_id']}"), 1, 'cobrança informada pela Meta registrada');

    grupo('Status antes do envio ser gravado (a Meta foi mais rápida)');
    webhook(status_payload($pnA, 'wamid.CORRIDA', 'delivered'));
    igual(valor("SELECT status FROM whatsapp_webhook_eventos WHERE chave_unica = 'st:wamid.CORRIDA:delivered'"), 'erro', 'mensagem ainda sem wamid → aviso guardado para nova tentativa');
    $pdo->prepare("INSERT INTO whatsapp_mensagens (empresa_id, conversa_id, direcao, tipo, status, wamid, enviada_por) VALUES (:e, :c, 'saida', 'texto', 'enviada', 'wamid.CORRIDA', 1)")
        ->execute(['e' => $empA, 'c' => $conv['id']]);
    $pdo->exec("UPDATE whatsapp_webhook_eventos SET proxima_tentativa_em = NOW() WHERE chave_unica = 'st:wamid.CORRIDA:delivered'");
    wa_processar_pendentes();
    igual(valor("SELECT status FROM whatsapp_mensagens WHERE wamid = 'wamid.CORRIDA'"), 'entregue', 'na nova tentativa o status é aplicado');

    grupo('Erros da Meta no envio');
    $enviar = function (array $envio, array $op = []) use ($empA, $contato) {
        $r = wa_enfileirar($empA, (int) $contato['id'], $envio, $op + ['usuario_id' => 1]);
        if ($r['ok']) {
            fila_executar_chave('wa_enviar:' . $r['mensagem_id'], automacao_tratadores());
        }
        return $r;
    };
    cenario([rota('POST', '#/messages$#', erroMeta(400, 130429, 'Rate limit'), ok200(['messages' => [['id' => 'wamid.OUT2']]]))]);
    $r = $enviar(['tipo' => 'texto', 'texto' => 'Olá! Já te respondo.']);
    $m = db()->query("SELECT status, erro_texto FROM whatsapp_mensagens WHERE id = {$r['mensagem_id']}")->fetch();
    ok($m['status'] === 'na_fila' && str_contains((string) $m['erro_texto'], 'Limite'), 'limite de envio → volta para a fila com o motivo');
    $f = db()->query("SELECT status, tentativas, executar_em > NOW() AS futura FROM automacao_fila WHERE chave_unica = 'wa_enviar:{$r['mensagem_id']}'")->fetch();
    ok($f['status'] === 'pendente' && (int) $f['futura'] === 1, 'tarefa reagendada com espera');
    $pdo->exec("UPDATE automacao_fila SET executar_em = NOW() WHERE chave_unica = 'wa_enviar:{$r['mensagem_id']}'");
    fila_executar_chave('wa_enviar:' . $r['mensagem_id'], automacao_tratadores());
    igual(valor("SELECT status FROM whatsapp_mensagens WHERE id = {$r['mensagem_id']}"), 'enviada', 'nova tentativa → enviada');

    cenario([rota('POST', '#/messages$#', erroMeta(400, 131047, 'Re-engagement message'))]);
    $pdo->exec("UPDATE whatsapp_contatos SET ultima_entrada_em = NOW() WHERE id = {$contato['id']}");
    $r = $enviar(['tipo' => 'texto', 'texto' => 'mensagem fora da janela segundo a Meta']);
    $m = db()->query("SELECT status, erro_codigo FROM whatsapp_mensagens WHERE id = {$r['mensagem_id']}")->fetch();
    ok($m['status'] === 'falhou' && $m['erro_codigo'] === '131047', 'janela fechada segundo a Meta → falhou sem insistir');
    igual(count(mock_log()), 1, 'uma única chamada (não insiste em erro definitivo)');

    cenario([rota('POST', '#/messages$#', erroMeta(400, 132015, 'Template paused'))]);
    $enviar(['tipo' => 'template', 'template_id' => $tplId, 'dados' => $dados]);
    igual(valor("SELECT status FROM whatsapp_templates WHERE id = $tplId"), 'pausado', 'template pausado pela Meta → marcado como pausado');
    $r = wa_enfileirar($empA, (int) $contato['id'], ['tipo' => 'template', 'template_id' => $tplId, 'dados' => $dados], ['ignorar_horario' => true]);
    ok(!$r['ok'] && $r['codigo'] === 'template_nao_aprovado', 'template pausado não entra mais na fila');
    $pdo->exec("UPDATE whatsapp_templates SET status = 'aprovado' WHERE id = $tplId");

    cenario([rota('POST', '#/messages$#', erroMeta(401, 190, 'Error validating access token'))]);
    $enviar(['tipo' => 'texto', 'texto' => 'teste token']);
    $ca = wa_conta($empA, (int) $contaA['id']);
    ok($ca['status'] === 'erro' && str_contains((string) $ca['ultimo_erro'], 'Token'), 'token expirado → conta marcada com erro e motivo');
    $r = wa_enfileirar($empA, (int) $contato['id'], ['tipo' => 'texto', 'texto' => 'depois do erro'], ['usuario_id' => 1]);
    igual($r['codigo'] ?? null, 'conta_inativa', 'com a conta em erro, nada mais sai');
    $pdo->exec("UPDATE whatsapp_contas SET status = 'ativa', ultimo_erro = NULL WHERE id = {$contaA['id']}");

    grupo('Meta fora do ar');
    cenario([]);
    putenv('WEMART_GRAPH_BASE_URL=http://127.0.0.1:1');   // porta fechada
    $r = $enviar(['tipo' => 'texto', 'texto' => 'sem conexão']);
    igual(valor("SELECT status FROM whatsapp_mensagens WHERE id = {$r['mensagem_id']}"), 'na_fila', 'sem conexão → mensagem continua na fila para nova tentativa');
    putenv("WEMART_GRAPH_BASE_URL=http://127.0.0.1:$portaMock");

    grupo('Regras conferidas de novo na hora do envio');
    cenario([rota('POST', '#/messages$#', ok200(['messages' => [['id' => 'wamid.NAO']]]))]);
    $r = wa_enfileirar($empA, (int) $contato['id'], ['tipo' => 'template', 'template_id' => $tplId, 'dados' => $dados], ['ignorar_horario' => true]);
    ok($r['ok'], 'agendada com consentimento' . ($r['ok'] ? '' : ' — motivo: ' . ($r['codigo'] ?? '') . ' ' . ($r['motivo'] ?? '')));
    // Entre agendar e enviar: a janela fechou e o cliente revogou a autorização.
    $pdo->exec("UPDATE whatsapp_contatos SET ultima_entrada_em = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE id = {$contato['id']}");
    wa_consentimento_registrar($empA, (int) $contato['id'], 'atendimento', 'revogado', 'manual', 'Cliente pediu por telefone para não receber');
    fila_executar_chave('wa_enviar:' . $r['mensagem_id'], automacao_tratadores());
    $m = db()->query("SELECT status, erro_codigo FROM whatsapp_mensagens WHERE id = {$r['mensagem_id']}")->fetch();
    ok($m['status'] === 'cancelada' && $m['erro_codigo'] === 'sem_consentimento', 'autorização revogada depois de agendar → conferida no envio e não enviada');
    igual(count(mock_log()), 0, 'nenhuma chamada à Meta');
    wa_consentimento_registrar($empA, (int) $contato['id'], 'atendimento', 'concedido', 'manual', 'Cliente voltou a autorizar');
    $pdo->prepare("INSERT INTO whatsapp_mensagens (empresa_id, conversa_id, direcao, tipo, status, categoria) VALUES (:e, :c, 'saida', 'template', 'enviada', 'utility')")
        ->execute(['e' => $empA, 'c' => $conv['id']]);   // 2ª automática nas últimas 24 h
    $r = wa_enfileirar($empA, (int) $contato['id'], ['tipo' => 'template', 'template_id' => $tplId, 'dados' => $dados], ['ignorar_horario' => true]);
    igual($r['codigo'] ?? null, 'limite_frequencia', '3ª mensagem automática em 24 h → bloqueada pelo teto');
    $r = wa_enfileirar($empA, (int) $contato['id'], ['tipo' => 'template', 'template_id' => $tplId, 'dados' => $dados], ['usuario_id' => 1]);
    ok($r['ok'], 'o corretor ainda pode enviar (o teto vale só para automação)');

    grupo('Modo de teste: nada sai');
    wa_consentimento_registrar($empB, (int) $cB['id'], 'atendimento', 'concedido', 'manual', 'teste');
    $pdo->prepare("INSERT INTO whatsapp_templates (empresa_id, conta_id, nome, categoria, status, corpo) VALUES (:e, :c, 'oi_b', 'utility', 'aprovado', 'Oi!')")
        ->execute(['e' => $empB, 'c' => wa_conta_da_empresa($empB)['id']]);
    $tplB = (int) $pdo->lastInsertId();
    cenario([rota('POST', '#/messages$#', ok200(['messages' => [['id' => 'wamid.NUNCA']]]))]);
    $r = wa_enfileirar($empB, (int) $cB['id'], ['tipo' => 'template', 'template_id' => $tplB, 'dados' => []], ['ignorar_horario' => true]);
    fila_executar_chave('wa_enviar:' . $r['mensagem_id'], automacao_tratadores());
    $m = db()->query("SELECT status, wamid FROM whatsapp_mensagens WHERE id = {$r['mensagem_id']}")->fetch();
    ok($m['status'] === 'simulada' && str_starts_with($m['wamid'], 'simulado.'), 'conta em modo de teste → mensagem "simulada"');
    igual(count(mock_log()), 0, 'nenhuma chamada à Meta em modo de teste');
    ok((int) valor("SELECT COUNT(*) FROM lead_eventos WHERE lead_id = $leadB AND descricao LIKE '%SIMULADA%'") === 1, 'histórico do lead deixa claro que foi simulada');
    $r = wa_enfileirar($empB, (int) $contato['id'], ['tipo' => 'texto', 'texto' => 'x'], ['usuario_id' => 1]);
    igual($r['codigo'] ?? null, 'contato_invalido', 'empresa B não envia para contato da empresa A');

    grupo('Templates: sincronização com paginação');
    cenario([rota('GET', '#/111222333/message_templates$#',
        ok200(['data' => [
            ['id' => 'T1', 'name' => 'boas_vindas_v1', 'language' => 'pt_BR', 'status' => 'APPROVED', 'category' => 'UTILITY',
             'components' => [['type' => 'BODY', 'text' => 'Olá, {{1}}! Aqui é a {{2}}.'], ['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Quero receber o book'], ['type' => 'QUICK_REPLY', 'text' => 'Agora não']]]]],
        ], 'paging' => ['cursors' => ['after' => 'CURSOR2'], 'next' => 'https://graph/next']]),
        ok200(['data' => [
            ['id' => 'T2', 'name' => 'promo_lancamento', 'language' => 'pt_BR', 'status' => 'REJECTED', 'category' => 'MARKETING', 'rejected_reason' => 'PROMOTIONAL',
             'components' => [['type' => 'HEADER', 'format' => 'DOCUMENT'], ['type' => 'BODY', 'text' => 'Novidade!'], ['type' => 'FOOTER', 'text' => 'Responda PARAR para sair']]],
        ], 'paging' => ['cursors' => ['after' => 'FIM']]]))]);
    $pdo->exec("UPDATE whatsapp_templates SET botoes = '[{\"tipo\":\"resposta\",\"texto\":\"Quero receber o book\",\"acao\":\"pedir_book\"}]' WHERE id = $tplId");
    $s = wa_templates_sincronizar(wa_conta($empA, (int) $contaA['id']));
    ok($s['ok'] && $s['novos'] === 1 && $s['atualizados'] === 1, 'duas páginas lidas: 1 novo e 1 atualizado');
    igual(mock_log()[1]['query'] !== '' && str_contains(mock_log()[1]['query'], 'after=CURSOR2'), true, 'segunda página pedida com o cursor');
    $t1 = wa_template($empA, $tplId);
    igual(wa_template_variaveis($t1), ['1' => 'lead.primeiro_nome', '2' => 'empresa.nome'], 'ligação das variáveis feita no WeMart é preservada');
    igual(wa_template_botoes($t1)[0]['acao'], 'pedir_book', 'ação escolhida para o botão é preservada');
    igual(count(wa_template_botoes($t1)), 2, 'botão novo trazido da Meta');
    $t2 = db()->query("SELECT * FROM whatsapp_templates WHERE empresa_id = $empA AND nome = 'promo_lancamento'")->fetch();
    ok($t2['status'] === 'rejeitado' && $t2['categoria'] === 'marketing' && $t2['cabecalho_tipo'] === 'documento' && $t2['motivo_rejeicao'] === 'PROMOTIONAL', 'rejeitado, marketing, cabeçalho de documento e motivo');
    cenario([rota('GET', '#/message_templates$#', ok200(['data' => [['id' => 'T1', 'name' => 'boas_vindas_v1', 'language' => 'pt_BR', 'status' => 'APPROVED', 'category' => 'UTILITY', 'components' => [['type' => 'BODY', 'text' => 'Olá, {{1}}! Aqui é a {{2}}.']]]]]))]);
    $s = wa_templates_sincronizar(wa_conta($empA, (int) $contaA['id']));
    igual(valor("SELECT status FROM whatsapp_templates WHERE id = {$t2['id']}"), 'desativado', 'template apagado na Meta → desativado aqui');

    grupo('Templates: aviso de mudança de status pelo webhook');
    webhook(['object' => 'whatsapp_business_account', 'entry' => [['id' => '111222333', 'changes' => [['field' => 'message_template_status_update',
        'value' => ['event' => 'PAUSED', 'message_template_id' => 'T1', 'message_template_name' => 'boas_vindas_v1', 'message_template_language' => 'pt_BR', 'reason' => 'LOW_QUALITY']]]]]]);
    igual(valor("SELECT status FROM whatsapp_templates WHERE id = $tplId"), 'pausado', 'Meta pausou o template → pausado aqui na hora');

    grupo('Testar conexão e ativar recebimento');
    cenario([rota('GET', '#^/' . $pnA . '$#', ok200(['display_phone_number' => '+55 11 90000-0000', 'verified_name' => 'Imobiliária Teste', 'quality_rating' => 'GREEN'])),
             rota('POST', '#/111222333/subscribed_apps$#', ok200(['success' => true]))]);
    $pdo->exec("UPDATE whatsapp_contas SET status = 'rascunho', token_validado_em = NULL, webhook_assinado_em = NULL WHERE id = {$contaA['id']}");
    $t = wa_conta_testar(wa_conta($empA, (int) $contaA['id']));
    $ca = wa_conta($empA, (int) $contaA['id']);
    ok($t['ok'] && $ca['status'] === 'ativa' && $ca['qualidade'] === 'verde' && $ca['nome_verificado'] === 'Imobiliária Teste', 'conexão OK: ativa, qualidade alta e nome verificado');
    ok(str_contains(mock_log()[0]['query'], 'fields=') && !str_contains(mock_log()[0]['query'], $TOKEN), 'consulta com campos, token fora da URL');
    $a = wa_conta_assinar($ca);
    ok($a['ok'] && wa_conta($empA, (int) $contaA['id'])['webhook_assinado_em'], 'recebimento ativado (app inscrito na WABA)');
    cenario([rota('GET', '#^/' . $pnA . '$#', erroMeta(401, 190, 'expired'))]);
    $t = wa_conta_testar(wa_conta($empA, (int) $contaA['id']));
    ok(!$t['ok'] && wa_conta($empA, (int) $contaA['id'])['status'] === 'erro', 'token inválido no teste → conta com erro e mensagem clara');

    grupo('Cron');
    $saida = shell_exec(PHP_BINARY . ' ' . escapeshellarg(dirname(__DIR__) . '/cron/rotinas.php') . ' 2>&1');
    ok(str_contains((string) $saida, 'whatsapp:') && str_contains((string) $saida, 'automação:') && !str_contains((string) $saida, 'ERRO'), 'cron executa as etapas whatsapp e automação sem erro');
} finally {
    db()->exec("DELETE FROM whatsapp_webhook_eventos WHERE empresa_id IN ($empA, $empB) OR (empresa_id IS NULL AND phone_number_id = '999999999999')");
    apagar_empresa_teste($empA);
    apagar_empresa_teste($empB);
    grupo('Limpeza');
    igual((int) valor("SELECT COUNT(*) FROM empresas WHERE nome LIKE 'Teste automatizado%'"), 0, 'nenhum dado de teste ficou no banco');
}
resumo_e_sair();
