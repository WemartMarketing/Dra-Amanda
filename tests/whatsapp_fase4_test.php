<?php
/**
 * Testes da FASE 4 — jornada de novo lead: boas-vindas, book, botões,
 * autorização pelo formulário do Meta e o caso "lead às 23h40 sem corretor".
 * Empresa fictícia em MODO DE TESTE; o Meta Ads é um simulador local.
 *
 *   php tests/whatsapp_fase4_test.php
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../includes/automacao.php';
require_once __DIR__ . '/../includes/jornadas.php';
require_once __DIR__ . '/../includes/funcoes.php';
require_once __DIR__ . '/../includes/funil.php';
require_once __DIR__ . '/../includes/meta.php';

function v(string $sql)
{
    return db()->query($sql)->fetchColumn();
}
function rodar_fila(): void
{
    for ($i = 0; $i < 5; $i++) {
        $r = automacao_executar(100);
        if (!$r['executadas']) {
            break;
        }
    }
}

grupo('Autorização vinda do formulário do Meta (só "sim" claro conta)');
$fd = fn ($v) => [['name' => 'full_name', 'values' => ['Ana']], ['name' => 'aceito_whatsapp', 'values' => [$v]]];
igual(jornada_consentimento_do_formulario($fd('Sim'), 'aceito_whatsapp'), 'Sim', '"Sim" → autorizado');
igual(jornada_consentimento_do_formulario($fd('sim, aceito'), 'ACEITO_WHATSAPP'), 'sim, aceito', '"sim, aceito" → autorizado (nome da pergunta sem diferença de maiúsculas)');
igual(jornada_consentimento_do_formulario($fd('Não'), 'aceito_whatsapp'), null, '"Não" → não autorizado');
igual(jornada_consentimento_do_formulario($fd('Sim, mas não por WhatsApp'), 'aceito_whatsapp'), null, 'resposta ambígua com "não" → não autorizado');
igual(jornada_consentimento_do_formulario($fd('Sim'), 'outra_pergunta'), null, 'pergunta configurada não existe no formulário → não autorizado');
igual(jornada_consentimento_do_formulario($fd('Sim'), null), null, 'formulário sem pergunta configurada → não autorizado (preencher não basta)');

$emp = criar_empresa_teste('Jornada');
$portaMock = 18000 + random_int(0, 900);
$proc = null;
try {
    $pdo = db();
    $f = funcoes_seed_padrao($emp);
    funil_seed_padrao($emp);
    $pdo->prepare('INSERT INTO usuarios (empresa_id, funcao_id, nome, email, senha_hash, cargo) VALUES (:e, :f, :n, :m, :h, :c)')
        ->execute(['e' => $emp, 'f' => $f['corretor'], 'n' => 'Corretor Teste', 'm' => bin2hex(random_bytes(5)) . '@teste.invalid', 'h' => 'x', 'c' => 'corretor']);
    $corretor = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO whatsapp_contas (empresa_id, nome, waba_id, phone_number_id, status, modo_teste) VALUES (:e, 'Teste', 'W', :p, 'ativa', 1)")
        ->execute(['e' => $emp, 'p' => (string) random_int(10 ** 9, 10 ** 10)]);
    $conta = wa_conta_da_empresa($emp);
    $pdo->prepare("INSERT INTO whatsapp_config (empresa_id, resposta_sem_corretor) VALUES (:e, 'Recebemos sua mensagem! Um consultor vai te responder assim que o plantão abrir.')")->execute(['e' => $emp]);
    $tpl = function (string $nome, string $corpo, array $vars, array $botoes = []) use ($pdo, $emp, $conta) {
        $pdo->prepare("INSERT INTO whatsapp_templates (empresa_id, conta_id, nome, categoria, status, finalidade, corpo, variaveis, botoes) VALUES (:e, :c, :n, 'utility', 'aprovado', 'boas_vindas', :corpo, :v, :b)")
            ->execute(['e' => $emp, 'c' => $conta['id'], 'n' => $nome, 'corpo' => $corpo, 'v' => json_encode($vars), 'b' => $botoes ? json_encode($botoes, JSON_UNESCAPED_UNICODE) : null]);
        return (int) $pdo->lastInsertId();
    };
    $botoes = [['tipo' => 'resposta', 'texto' => 'Quero receber o book', 'acao' => 'pedir_book'], ['tipo' => 'resposta', 'texto' => 'Falar com consultor', 'acao' => 'falar_consultor'],
               ['tipo' => 'resposta', 'texto' => 'Agora não', 'acao' => 'agora_nao']];
    $tplE1 = $tpl('bv_smart', 'Olá, {{1}}! Recebemos seu interesse no {{2}}. Posso te enviar o book?', ['1' => 'lead.primeiro_nome', '2' => 'empreendimento.nome'], $botoes);
    $tplPadrao = $tpl('bv_geral', 'Olá, {{1}}! Obrigado pelo contato com a {{2}}.', ['1' => 'lead.primeiro_nome', '2' => 'empresa.nome'], $botoes);
    // Book do E1 (PDF fictício em storage/books).
    @mkdir(__DIR__ . '/../storage/books', 0750, true);
    $bookArq = 'teste_' . bin2hex(random_bytes(6)) . '.pdf';
    file_put_contents(__DIR__ . '/../storage/books/' . $bookArq, "%PDF-1.4\n% book de teste\n%%EOF\n");
    $pdo->prepare("INSERT INTO empreendimentos (empresa_id, nome, wa_template_boas_vindas_id, book_arquivo, book_nome) VALUES (:e, 'Smart Teste', :t, :b, 'Book Smart.pdf')")
        ->execute(['e' => $emp, 't' => $tplE1, 'b' => 'storage/books/' . $bookArq]);
    $E1 = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO empreendimentos (empresa_id, nome) VALUES (:e, 'Vila Sem Material')")->execute(['e' => $emp]);
    $E2 = (int) $pdo->lastInsertId();
    $etapa = funil_primeira_etapa($emp);
    $n = 0;
    $novoLead = function (?int $empId, string $origem = 'meta_ads', ?int $resp = null) use ($pdo, $emp, $etapa, &$n) {
        $n++;
        $tel = '(11) 9' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $pdo->prepare('INSERT INTO leads (empresa_id, empreendimento_id, responsavel_id, nome, telefone, origem, etapa_id, etapa) VALUES (:e, :emp, :r, :n, :t, :o, :et, :c)')
            ->execute(['e' => $emp, 'emp' => $empId, 'r' => $resp, 'n' => "Cliente Teste $n", 't' => $tel, 'o' => $origem, 'et' => $etapa['id'], 'c' => $etapa['categoria']]);
        return (int) $pdo->lastInsertId();
    };
    $formSim = ['nome' => 'Formulário Smart', 'wa_consentimento_campo' => 'aceito_whatsapp', 'wa_consentimento_texto' => 'Autorizo contato pelo WhatsApp.'];

    grupo('Jornada padrão: criada desligada');
    $jid = jornada_garantir_boas_vindas($emp);
    igual(jornada_garantir_boas_vindas($emp), $jid, 'só existe uma jornada de boas-vindas por empresa');
    igual(v("SELECT status FROM jornadas WHERE id = $jid"), 'rascunho', 'nasce desligada');
    $L0 = $novoLead($E1);
    igual(jornada_lead_chegou($emp, $L0, 'meta_ads', $fd('Sim'), $formSim), [], 'desligada → lead não entra');
    $pdo->exec("UPDATE jornadas SET status = 'ativa' WHERE id = $jid");

    grupo('Lead às 23h40, sem corretor: boas-vindas na hora');
    $L1 = $novoLead($E1);
    $ex = jornada_lead_chegou($emp, $L1, 'meta_ads', $fd('Sim'), $formSim);
    igual(count($ex), 1, 'lead do Meta com autorização entra na jornada');
    $ct1 = (int) v("SELECT id FROM whatsapp_contatos WHERE empresa_id = $emp AND lead_id = $L1");
    ok(wa_consentimento_atual($emp, $ct1, 'atendimento')['concedido'], 'autorização registrada a partir da resposta do formulário');
    ok(str_contains((string) wa_consentimento_atual($emp, $ct1, 'atendimento')['registro']['evidencia'], 'aceito_whatsapp'), 'evidência guarda a pergunta, a resposta e o aviso');
    igual(wa_consentimento_atual($emp, $ct1, 'marketing')['concedido'], false, 'autorização de MARKETING não é presumida');
    rodar_fila();
    $m = $pdo->query("SELECT m.* FROM whatsapp_mensagens m JOIN whatsapp_conversas cv ON cv.id = m.conversa_id WHERE cv.lead_id = $L1 AND m.direcao = 'saida'")->fetchAll();
    igual(count($m), 1, 'uma mensagem de boas-vindas');
    ok($m[0]['status'] === 'simulada' && (int) $m[0]['template_id'] === $tplE1, 'template do empreendimento (modo de teste: simulada)');
    ok(str_starts_with($m[0]['conteudo'], 'Olá, Cliente! Recebemos seu interesse no Smart Teste.'), 'variáveis preenchidas com os dados do lead');
    igual(v("SELECT responsavel_id FROM leads WHERE id = $L1"), null, 'saiu mesmo sem nenhum corretor responsável');
    $x1 = $pdo->query('SELECT * FROM jornada_execucoes WHERE id = ' . $ex[0])->fetch();
    ok($x1['status'] === 'concluida' && (int) $x1['mensagens_enviadas'] === 1, 'execução concluída com 1 mensagem');
    ok((int) v("SELECT COUNT(*) FROM lead_eventos WHERE lead_id = $L1 AND tipo = 'jornada'") >= 1, 'passos aparecem no histórico do lead (tipo Automação)');

    grupo('Nada em dobro');
    igual(jornada_lead_chegou($emp, $L1, 'meta_ads', $fd('Sim'), $formSim), [], 'mesmo lead de novo → não entra outra vez');
    rodar_fila();
    igual((int) v("SELECT COUNT(*) FROM whatsapp_mensagens m JOIN whatsapp_conversas cv ON cv.id = m.conversa_id WHERE cv.lead_id = $L1 AND m.direcao = 'saida'"), 1, 'continua 1 mensagem');
    $pdo->exec("UPDATE leads SET responsavel_id = $corretor WHERE id = $L1");
    igual(jornada_disparar('novo_lead', $emp, $L1), [], 'transferir para um corretor não recomeça as boas-vindas');
    $pdo->exec("UPDATE leads SET responsavel_id = NULL WHERE id = $L1");

    grupo('Sem autorização: não envia e explica');
    $L2 = $novoLead($E1);
    $ex = jornada_lead_chegou($emp, $L2, 'meta_ads', $fd('Não'), $formSim);
    rodar_fila();
    igual((int) v("SELECT COUNT(*) FROM whatsapp_mensagens m JOIN whatsapp_conversas cv ON cv.id = m.conversa_id WHERE cv.lead_id = $L2"), 0, 'respondeu "Não" → nenhuma mensagem');
    $x2 = $pdo->query('SELECT * FROM jornada_execucoes WHERE id = ' . $ex[0])->fetch();
    ok($x2['status'] === 'concluida' && str_contains($x2['motivo'], 'não autorizou'), 'jornada encerrada com o motivo');
    ok((int) v("SELECT COUNT(*) FROM lead_eventos WHERE lead_id = $L2 AND descricao LIKE '%não enviada%não autorizou%'") === 1, 'o corretor vê no histórico por que não saiu');

    grupo('Origem, empreendimento sem mensagem e mensagem padrão');
    igual(jornada_lead_chegou($emp, $novoLead($E1, 'manual'), 'manual'), [], 'cadastro manual não entra (origem não marcada)');
    $L3 = $novoLead($E2);
    $ex = jornada_lead_chegou($emp, $L3, 'meta_ads', $fd('Sim'), $formSim);
    rodar_fila();
    ok(str_contains((string) v('SELECT motivo FROM jornada_execucoes WHERE id = ' . $ex[0]), 'Nenhum template'), 'empreendimento sem mensagem e sem padrão → não envia, com o motivo');
    $passo = jornada_passo($jid, 'boas_vindas');
    $cfg = $passo['config'];
    $cfg['template_id'] = $tplPadrao;
    $pdo->prepare('UPDATE jornada_passos SET config = :c WHERE id = :id')->execute(['c' => json_encode($cfg), 'id' => $passo['id']]);
    $L4 = $novoLead($E2);
    jornada_lead_chegou($emp, $L4, 'meta_ads', $fd('Sim'), $formSim);
    rodar_fila();
    igual((int) v("SELECT m.template_id FROM whatsapp_mensagens m JOIN whatsapp_conversas cv ON cv.id = m.conversa_id WHERE cv.lead_id = $L4"), $tplPadrao, 'com mensagem padrão → usa a padrão');

    // Mensagem chegando do cliente (mesma rotina do webhook).
    $chega = function (int $leadId, array $mensagem) use ($emp, $conta, $pdo) {
        $tel = (string) v("SELECT telefone FROM whatsapp_contatos WHERE empresa_id = $emp AND lead_id = $leadId");
        return wa_evento_mensagem($emp, (int) $conta['id'], $mensagem + ['from' => $tel, 'id' => 'wamid.' . bin2hex(random_bytes(8)), 'timestamp' => (string) time()], 'Cliente');
    };
    $saidas = fn (int $leadId) => $pdo->query("SELECT m.tipo, m.conteudo, m.arquivo_ref, m.status FROM whatsapp_mensagens m JOIN whatsapp_conversas cv ON cv.id = m.conversa_id WHERE cv.lead_id = $leadId AND m.direcao = 'saida' ORDER BY m.id")->fetchAll();

    grupo('Botão "Quero receber o book"');
    $chega($L1, ['type' => 'button', 'button' => ['text' => 'Quero receber o book', 'payload' => 'Quero receber o book']]);
    $s = $saidas($L1);
    $book = end($s);
    ok($book['tipo'] === 'documento' && $book['arquivo_ref'] === 'storage/books/' . $bookArq, 'envia o PDF do book do empreendimento do lead');
    igual($book['status'], 'simulada', 'enviado na hora (modo de teste: simulado)');
    ok((int) v("SELECT COUNT(*) FROM lead_eventos WHERE lead_id = $L1 AND descricao LIKE 'Book do Smart Teste enviado%'") === 1, 'registrado no histórico');
    $chega($L1, ['type' => 'button', 'button' => ['text' => 'Quero receber o book', 'payload' => 'Quero receber o book']]);
    igual(count($saidas($L1)), 3, 'pedir de novo envia de novo (é o cliente pedindo), sem duplicar o mesmo pedido');

    grupo('Empreendimento sem book: não inventa material');
    $chega($L4, ['type' => 'button', 'button' => ['text' => 'Quero receber o book', 'payload' => 'Quero receber o book']]);
    $s = $saidas($L4);
    ok(end($s)['tipo'] === 'texto' && str_contains(end($s)['conteudo'], 'Um consultor vai te enviar o material'), 'sem book → avisa que um consultor vai enviar');
    ok((int) v("SELECT COUNT(*) FROM lead_eventos WHERE lead_id = $L4 AND descricao LIKE '%não há book cadastrado%'") === 1, 'corretor é avisado no histórico para enviar manualmente');

    grupo('Ninguém pode atender ainda: confirma o recebimento uma vez');
    $L5 = $novoLead($E1);
    jornada_lead_chegou($emp, $L5, 'meta_ads', $fd('Sim'), $formSim);
    rodar_fila();
    $chega($L5, ['type' => 'text', 'text' => ['body' => 'Tenho interesse. Qual é o valor da entrada?']]);
    $s = $saidas($L5);
    ok(end($s)['conteudo'] === 'Recebemos sua mensagem! Um consultor vai te responder assim que o plantão abrir.', 'lead sem corretor → resposta automática configurada (sem preço)');
    $chega($L5, ['type' => 'text', 'text' => ['body' => 'Alô?']]);
    igual(count($saidas($L5)), count($s), 'segunda mensagem logo depois → não repete a resposta automática');
    $L6 = $novoLead($E1, 'meta_ads', $corretor);
    jornada_lead_chegou($emp, $L6, 'meta_ads', $fd('Sim'), $formSim);
    rodar_fila();
    $antes = count($saidas($L6));
    $chega($L6, ['type' => 'text', 'text' => ['body' => 'Oi']]);
    igual(count($saidas($L6)), $antes, 'lead que já tem corretor → nada automático (o corretor responde)');

    grupo('"Agora não" e "Falar com consultor"');
    $chega($L1, ['type' => 'button', 'button' => ['text' => 'Agora não', 'payload' => 'Agora não']]);
    ok((int) v("SELECT COUNT(*) FROM lead_eventos WHERE lead_id = $L1 AND descricao LIKE 'Cliente respondeu \"agora não\"%'") === 1, '"Agora não" registrado no lead');
    $chega($L6, ['type' => 'button', 'button' => ['text' => 'Falar com consultor', 'payload' => 'Falar com consultor']]);
    ok((int) v("SELECT COUNT(*) FROM lead_eventos WHERE lead_id = $L6 AND descricao = 'Cliente pediu para falar com um consultor pelo WhatsApp.'") === 1, '"Falar com consultor" registrado para o corretor');

    grupo('Paradas: descadastro, lead encerrado, jornada pausada, limite');
    // Jornada de teste com espera, para ter execução em andamento.
    $pdo->prepare("INSERT INTO jornadas (empresa_id, nome, gatilho, gatilho_config, status, max_mensagens) VALUES (:e, 'Teste com espera', 'novo_lead', :g, 'ativa', 1)")
        ->execute(['e' => $emp, 'g' => json_encode(['origens' => ['indicacao']])]);
    $j2 = (int) $pdo->lastInsertId();
    $p = $pdo->prepare('INSERT INTO jornada_passos (jornada_id, chave, ordem, tipo, config, proximo_chave) VALUES (:j, :c, :o, :t, :cfg, :p)');
    $p->execute(['j' => $j2, 'c' => 'a', 'o' => 1, 't' => 'enviar_template', 'cfg' => json_encode(['template_id' => $tplPadrao, 'ignorar_horario' => true, 'se_bloqueado' => 'seguir']), 'p' => 'b']);
    $p->execute(['j' => $j2, 'c' => 'b', 'o' => 2, 't' => 'esperar', 'cfg' => json_encode(['minutos' => 120]), 'p' => 'c']);
    $p->execute(['j' => $j2, 'c' => 'c', 'o' => 3, 't' => 'enviar_template', 'cfg' => json_encode(['template_id' => $tplPadrao, 'ignorar_horario' => true]), 'p' => null]);
    $L7 = $novoLead(null, 'indicacao');
    $c7 = wa_contato_obter($emp, (string) v("SELECT telefone FROM leads WHERE id = $L7"), $L7);
    wa_consentimento_registrar($emp, (int) $c7['id'], 'atendimento', 'concedido', 'manual', 'Autorizou no plantão (teste)');
    $ex7 = jornada_disparar('novo_lead', $emp, $L7, ['origem' => 'indicacao'])[0];
    rodar_fila();
    $x7 = $pdo->query("SELECT * FROM jornada_execucoes WHERE id = $ex7")->fetch();
    ok($x7['status'] === 'aguardando' && $x7['passo_chave'] === 'c', 'enviou o 1º passo e está esperando 2 h para o próximo');
    igual((int) v("SELECT COUNT(*) FROM automacao_fila WHERE referencia_tipo = 'jornada_execucao' AND referencia_id = $ex7 AND status = 'pendente'"), 1, 'próximo passo agendado na fila');
    $pdo->exec("UPDATE automacao_fila SET executar_em = NOW() WHERE referencia_tipo = 'jornada_execucao' AND referencia_id = $ex7 AND status = 'pendente'");
    rodar_fila();
    ok(str_contains((string) v("SELECT motivo FROM jornada_execucoes WHERE id = $ex7"), 'Limite de mensagens'), 'teto de 1 mensagem por jornada → não manda a 2ª');

    $L8 = $novoLead(null, 'indicacao');
    $c8 = wa_contato_obter($emp, (string) v("SELECT telefone FROM leads WHERE id = $L8"), $L8);
    wa_consentimento_registrar($emp, (int) $c8['id'], 'atendimento', 'concedido', 'manual', 'teste');
    $pdo->exec("UPDATE jornadas SET max_mensagens = 3 WHERE id = $j2");
    $ex8 = jornada_disparar('novo_lead', $emp, $L8, ['origem' => 'indicacao'])[0];
    rodar_fila();
    $chega($L8, ['type' => 'text', 'text' => ['body' => 'PARAR']]);
    ok(v("SELECT status FROM jornada_execucoes WHERE id = $ex8") === 'cancelada', '"PARAR" → jornada cancelada');
    igual((int) v("SELECT COUNT(*) FROM automacao_fila WHERE referencia_tipo = 'jornada_execucao' AND referencia_id = $ex8 AND status = 'pendente'"), 0, 'e o passo agendado foi cancelado');

    $L9 = $novoLead(null, 'indicacao');
    $c9 = wa_contato_obter($emp, (string) v("SELECT telefone FROM leads WHERE id = $L9"), $L9);
    wa_consentimento_registrar($emp, (int) $c9['id'], 'atendimento', 'concedido', 'manual', 'teste');
    $ex9 = jornada_disparar('novo_lead', $emp, $L9, ['origem' => 'indicacao'])[0];
    rodar_fila();
    $pdo->exec("UPDATE leads SET etapa = 'perdido' WHERE id = $L9");
    $pdo->exec("UPDATE automacao_fila SET executar_em = NOW() WHERE referencia_tipo = 'jornada_execucao' AND referencia_id = $ex9 AND status = 'pendente'");
    rodar_fila();
    igual(v("SELECT motivo FROM jornada_execucoes WHERE id = $ex9"), 'Lead encerrado no funil', 'lead perdido no funil → jornada para');

    $L10 = $novoLead(null, 'indicacao');
    $ex10 = jornada_disparar('novo_lead', $emp, $L10, ['origem' => 'indicacao'])[0];
    $pdo->exec("UPDATE jornadas SET status = 'pausada' WHERE id = $j2");
    rodar_fila();
    igual(v("SELECT status FROM jornada_execucoes WHERE id = $ex10"), 'pausada', 'jornada pausada → lead fica parado no passo (não perde)');

    grupo('Meta Ads de ponta a ponta (simulador local da Graph API)');
    $dirTmp = __DIR__ . '/tmp';
    @mkdir($dirTmp, 0775, true);
    foreach (glob("$dirTmp/mock_cont_*") as $arq) {
        unlink($arq);
    }
    @unlink("$dirTmp/mock_log.jsonl");
    $leadgen = (string) random_int(10 ** 12, 10 ** 13);
    $formId = (string) random_int(10 ** 9, 10 ** 10);
    file_put_contents("$dirTmp/mock_cenario.json", json_encode(['rotas' => [['metodo' => 'GET', 'caminho' => '#^/' . $leadgen . '$#', 'respostas' => [['status' => 200, 'corpo' => [
        'form_id' => $formId, 'campaign_name' => 'Lançamento Smart',
        'field_data' => [['name' => 'full_name', 'values' => ['Joana Noturna']], ['name' => 'phone_number', 'values' => ['+5511977001234']],
                         ['name' => 'aceito_whatsapp', 'values' => ['Sim']]]]]]]]]));
    $proc = proc_open(PHP_BINARY . " -S 127.0.0.1:$portaMock " . escapeshellarg(__DIR__ . '/mock_graph.php'), [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
    usleep(500000);
    putenv("WEMART_GRAPH_BASE_URL=http://127.0.0.1:$portaMock");
    $pageId = (string) random_int(10 ** 9, 10 ** 10);
    $pdo->prepare('INSERT INTO meta_paginas (empresa_id, page_id, nome, access_token_cifrado, ativa) VALUES (:e, :p, :n, :t, 1)')
        ->execute(['e' => $emp, 'p' => $pageId, 'n' => 'Página teste', 't' => cripto_cifrar('TOKEN_PAGINA_TESTE_' . bin2hex(random_bytes(8)))]);
    $pdo->prepare('INSERT INTO meta_formularios (empresa_id, meta_pagina_id, form_id, nome, empreendimento_id, ativo, wa_consentimento_campo, wa_consentimento_texto)
                   VALUES (:e, :p, :f, :n, :emp, 1, :c, :t)')
        ->execute(['e' => $emp, 'p' => $pdo->lastInsertId(), 'f' => $formId, 'n' => 'Formulário Smart', 'emp' => $E1, 'c' => 'aceito_whatsapp', 't' => 'Autorizo contato pelo WhatsApp.']);
    $pdo->prepare("INSERT INTO meta_eventos (empresa_id, page_id, form_id, leadgen_id, payload, status) VALUES (:e, :p, :f, :l, '{}', 'pendente')")
        ->execute(['e' => $emp, 'p' => $pageId, 'f' => $formId, 'l' => $leadgen]);
    $evId = (int) $pdo->lastInsertId();
    igual(meta_processar_evento($evId), 'processado', 'aviso do Meta processado');
    $lead = $pdo->query("SELECT * FROM leads WHERE meta_leadgen_id = '$leadgen'")->fetch();
    ok($lead && $lead['nome'] === 'Joana Noturna' && (int) $lead['empreendimento_id'] === $E1, 'lead criado no empreendimento do formulário');
    $ctJ = (int) v("SELECT id FROM whatsapp_contatos WHERE empresa_id = $emp AND lead_id = {$lead['id']}");
    ok(wa_consentimento_atual($emp, $ctJ, 'atendimento')['registro']['origem'] === 'formulario_meta', 'autorização registrada com origem "formulário do Meta"');
    rodar_fila();
    $m = $pdo->query("SELECT m.status, m.template_id FROM whatsapp_mensagens m JOIN whatsapp_conversas cv ON cv.id = m.conversa_id WHERE cv.lead_id = {$lead['id']}")->fetch();
    ok($m && (int) $m['template_id'] === $tplE1 && $m['status'] === 'simulada', 'boas-vindas do empreendimento enviada (modo de teste) sem nenhum corretor envolvido');
    igual(v("SELECT responsavel_id FROM leads WHERE id = {$lead['id']}"), null, 'lead continua aguardando a roleta (a automação não distribui)');
} finally {
    if ($proc) {
        $st = proc_get_status($proc);
        if ($st['running']) {
            posix_kill($st['pid'], SIGTERM);
        }
        proc_close($proc);
    }
    if (!empty($bookArq)) {
        @unlink(__DIR__ . '/../storage/books/' . $bookArq);
    }
    db()->exec("DELETE FROM whatsapp_webhook_eventos WHERE empresa_id = $emp");
    db()->exec("DELETE FROM meta_eventos WHERE empresa_id = $emp");
    apagar_empresa_teste($emp);
    grupo('Limpeza');
    igual((int) db()->query("SELECT COUNT(*) FROM empresas WHERE nome LIKE 'Teste automatizado%'")->fetchColumn(), 0, 'nenhum dado de teste ficou no banco');
}
resumo_e_sair();
