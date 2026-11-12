<?php
/**
 * Testes da FASE 6 — recuperação, redistribuição e expediente das roletas:
 * grade por dia com pausa, feriados e datas especiais, fila quando a roleta
 * está fechada, pendências que acompanham o novo corretor, jornadas que não
 * recomeçam, recuperação integrada ao WhatsApp. Empresas fictícias; envios em
 * MODO DE TESTE, e o único envio "real" vai para o simulador local da Graph
 * API (tests/mock_graph.php) — nada sai para a Meta.
 *
 *   php tests/whatsapp_fase6_test.php
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../includes/automacao.php';
require_once __DIR__ . '/../includes/jornadas.php';
require_once __DIR__ . '/../includes/recuperacao.php';
require_once __DIR__ . '/../includes/roleta.php';
require_once __DIR__ . '/../includes/cripto.php';
require_once __DIR__ . '/../includes/funcoes.php';
require_once __DIR__ . '/../includes/funil.php';

// Simulador da Graph API (só para o envio "real" do corretor).
$portaMock = 19000 + random_int(0, 900);
@mkdir(__DIR__ . '/tmp', 0775, true);
$proc = proc_open(PHP_BINARY . " -S 127.0.0.1:$portaMock " . escapeshellarg(__DIR__ . '/mock_graph.php'), [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
register_shutdown_function(function () use ($proc) {
    $st = proc_get_status($proc);
    if ($st['running']) {
        posix_kill($st['pid'], SIGTERM);
    }
    proc_close($proc);
});
usleep(500000);
putenv("WEMART_GRAPH_BASE_URL=http://127.0.0.1:$portaMock");
file_put_contents(__DIR__ . '/tmp/mock_cenario.json', json_encode(['rotas' => [
    ['metodo' => 'POST', 'caminho' => '#/messages$#', 'respostas' => array_map(fn ($i) => ['status' => 200, 'corpo' => ['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '5511900000000']], 'messages' => [['id' => 'wamid.MOCKFASE6.' . $i . '.' . bin2hex(random_bytes(4))]]]], range(1, 5))],
]]));

function v(string $sql)
{
    return db()->query($sql)->fetchColumn();
}
function rodar(): void
{
    for ($i = 0; $i < 6; $i++) {
        if (!automacao_executar(100)['executadas']) {
            break;
        }
    }
}
function avancar(int $execId): void
{
    db()->exec("UPDATE automacao_fila SET executar_em = NOW() WHERE referencia_tipo = 'jornada_execucao' AND referencia_id = $execId AND status = 'pendente'");
    rodar();
}

// ------------------------------------------------------------------ grade (funções puras)
grupo('Horário por dia: validação da grade');
$g = fn (array $dias) => roleta_grade_validar($dias);
$r = $g([1 => ['aberto' => 1, 'inicio' => '09:30', 'fim' => '18:00', 'pausa_inicio' => '12:00', 'pausa_fim' => '13:00'], 6 => ['aberto' => 1, 'inicio' => '09:00', 'fim' => '13:00']]);
ok($r['ok'] && count($r['linhas']) === 2 && $r['linhas'][0]['pausa_inicio'] === '12:00:00', 'segunda com almoço + sábado meio período → aceito');
ok(!$g([])['ok'], 'nenhum dia aberto → recusado (pausar é pelo "Roleta ativa")');
ok(!$g([2 => ['aberto' => 1, 'inicio' => '09:00', 'fim' => '18:00', 'pausa_inicio' => '17:30', 'pausa_fim' => '19:00']])['ok'], 'pausa que passa do fechamento → recusada');
ok(!$g([2 => ['aberto' => 1, 'inicio' => '09:00', 'fim' => '18:00', 'pausa_inicio' => '12:00']])['ok'], 'pausa sem horário de fim → recusada');
ok(!$g([2 => ['aberto' => 1, 'inicio' => '09:00', 'fim' => '09:00']])['ok'], 'abertura igual ao fechamento → recusado');
ok(!$g([2 => ['aberto' => 1, 'inicio' => '25:00', 'fim' => '18:00']])['ok'], 'hora inválida → recusada');
ok($g([5 => ['aberto' => 1, 'inicio' => '18:00', 'fim' => '02:00', 'pausa_inicio' => '23:00', 'pausa_fim' => '23:30']])['ok'], 'plantão que passa da meia-noite com pausa → aceito');
ok(!$g([5 => ['aberto' => 1, 'inicio' => '18:00', 'fim' => '02:00', 'pausa_inicio' => '03:00', 'pausa_fim' => '04:00']])['ok'], 'pausa fora do plantão noturno → recusada');
igual(str_contains($g([3 => ['aberto' => 1, 'inicio' => '', 'fim' => '18:00']])['erro'] ?? '', 'Quarta'), true, 'mensagem de erro diz qual dia está errado');

$emp = criar_empresa_teste('Fase6');
$emp2 = criar_empresa_teste('Fase6 Outra');
try {
    $pdo = db();
    $tz = new DateTimeZone('America/Sao_Paulo');
    $hoje = (new DateTimeImmutable('today', $tz))->format('Y-m-d');
    $f = funcoes_seed_padrao($emp);
    funil_seed_padrao($emp);
    $etapa = funil_primeira_etapa($emp);
    $novoUsuario = function (string $nome) use ($pdo, $emp, $f) {
        $pdo->prepare('INSERT INTO usuarios (empresa_id, funcao_id, nome, email, senha_hash, cargo) VALUES (:e, :f, :n, :m, :h, :c)')
            ->execute(['e' => $emp, 'f' => $f['corretor'], 'n' => $nome, 'm' => bin2hex(random_bytes(5)) . '@teste.invalid', 'h' => 'x', 'c' => 'corretor']);
        $id = (int) $pdo->lastInsertId();
        return ['id' => $id, 'nome' => $nome, 'empresa_id' => $emp, 'empresa_ativa_id' => $emp, 'eh_superadmin' => false, 'escopo' => 'propria_carteira',
                'permissoes' => ['visualizar_leads', 'editar_leads', 'whatsapp_atender']];
    };
    $c1 = $novoUsuario('Corretora Um');
    $c2 = $novoUsuario('Corretor Dois');
    $c3 = $novoUsuario('Corretor Três');
    $pdo->prepare("INSERT INTO empreendimentos (empresa_id, nome) VALUES (:e, 'Residencial Teste')")->execute(['e' => $emp]);
    $empr = (int) $pdo->lastInsertId();

    // Roleta aberta o dia todo, sem exigir GPS (o teste não depende da hora).
    $pdo->prepare("INSERT INTO roletas (empresa_id, nome, exigir_presenca, ativa) VALUES (:e, 'Plantão Teste', 0, 1)")->execute(['e' => $emp]);
    $rol = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO roletas (empresa_id, nome, exigir_presenca, ativa) VALUES (:e, 'Plantão Dois', 0, 1)")->execute(['e' => $emp]);
    $rol2 = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO roleta_empreendimentos (roleta_id, empreendimento_id, prioridade) VALUES (:r, :e, 1)')->execute(['r' => $rol, 'e' => $empr]);
    $todoDia = [];
    for ($d = 1; $d <= 7; $d++) {
        $todoDia[$d] = ['aberto' => 1, 'inicio' => '00:00', 'fim' => '23:59'];
    }

    grupo('Horário por dia e feriados no banco');
    $comAlmoco = $todoDia;
    $comAlmoco[1] += ['pausa_inicio' => '12:00', 'pausa_fim' => '13:00'];
    unset($comAlmoco[7]);
    roleta_grade_salvar($emp, $rol, roleta_grade_validar($comAlmoco)['linhas']);
    igual((int) v("SELECT COUNT(*) FROM roleta_horarios WHERE roleta_id = $rol"), 6, 'grade gravada: 6 dias (domingo fechado)');
    igual(v("SELECT dias_semana FROM roletas WHERE id = $rol"), '1,2,3,4,5,6', 'dias da roleta (campos antigos) acompanham a grade');
    ok(roleta_horarios_personalizados($rol), 'com pausa → grade personalizada (a tela simples não sobrescreve)');
    igual(roleta_horarios_sincronizar_simples($rol, '08:00:00', '20:00:00', '1,2,3'), false, 'edição simples das regras não apaga a grade por dia');
    $tela = roleta_grade_para_tela(roleta_carregar($emp, $rol));
    ok($tela[1]['pausa_inicio'] === '12:00' && !$tela[7]['aberto'], 'tela mostra a pausa de segunda e o domingo fechado');
    roleta_grade_salvar($emp, $rol, roleta_grade_validar($todoDia)['linhas']);
    $pdo->exec("INSERT INTO roleta_corretores (roleta_id, usuario_id) VALUES ($rol, {$c1['id']}), ($rol, {$c2['id']})");

    $x = roleta_excecao_salvar($emp, $rol, $hoje, 'fechado', null, null, 'Feriado municipal');
    ok($x['ok'], 'feriado de hoje cadastrado');
    [$aberta, $msg] = roleta_no_horario(roleta_carregar($emp, $rol));
    ok(!$aberta && str_contains($msg, 'Feriado municipal'), 'roleta fechada hoje, com o motivo (' . $msg . ')');
    ok(roleta_no_horario(roleta_carregar($emp, $rol2))[0], 'o feriado só desta roleta não fecha a outra');
    $x = roleta_excecao_salvar($emp, $rol, $hoje, 'fechado', null, null, 'Feriado municipal (corrigido)');
    igual((int) v("SELECT COUNT(*) FROM roleta_excecoes WHERE empresa_id = $emp AND roleta_id = $rol AND data = '$hoje'"), 1, 'mesma data cadastrada de novo → substitui, não duplica');
    $dias = roleta_proximos_dias(roleta_carregar($emp, $rol), 7);
    ok($dias[0]['texto'] === 'Fechado' && $dias[0]['exc'] === 'Feriado municipal (corrigido)' && $dias[1]['texto'] !== 'Fechado', '"Próximos dias": hoje fechado com o feriado, amanhã aberto');
    $geral = roleta_excecao_salvar($emp, null, (new DateTimeImmutable('+1 day', $tz))->format('Y-m-d'), 'horario_especial', '10:00', '14:00', 'Véspera');
    $d2 = roleta_proximos_dias(roleta_carregar($emp, $rol2), 2);
    ok($geral['ok'] && $d2[1]['texto'] === '10:00–14:00', 'data especial "todas as roletas" vale também para a outra roleta');
    ok(!roleta_excecao_salvar($emp, $rol, '2020-01-01', 'fechado', null, null, null)['ok'], 'data no passado → recusada');
    ok(!roleta_excecao_salvar($emp, $rol, $hoje, 'horario_especial', '10:00', '', null)['ok'], 'horário especial sem fechamento → recusado');
    ok(!roleta_excecao_salvar($emp2, $rol, $hoje, 'fechado', null, null, null)['ok'], 'outra empresa não cadastra feriado na roleta desta');
    ok(!roleta_excecao_remover($emp2, (int) $x['id']), 'outra empresa não remove o feriado desta');
    roleta_excecao_remover($emp, (int) $geral['id']);

    // ------------------------------------------------------------------ WhatsApp
    $pdo->prepare("INSERT INTO whatsapp_contas (empresa_id, nome, phone_number_id, status, modo_teste) VALUES (:e, 'Teste', :p, 'ativa', 1)")
        ->execute(['e' => $emp, 'p' => (string) random_int(10 ** 9, 10 ** 10)]);
    $conta = wa_conta_da_empresa($emp);
    $pdo->prepare("INSERT INTO whatsapp_config (empresa_id, envio_inicio, envio_fim, max_automaticas_24h) VALUES (:e, '00:00:00', '23:59:00', 5)")->execute(['e' => $emp]);
    $tpl = function (string $nome, string $corpo) use ($pdo, $emp, $conta) {
        $pdo->prepare("INSERT INTO whatsapp_templates (empresa_id, conta_id, nome, categoria, status, corpo, variaveis) VALUES (:e, :c, :n, 'utility', 'aprovado', :b, :v)")
            ->execute(['e' => $emp, 'c' => $conta['id'], 'n' => $nome, 'b' => $corpo, 'v' => json_encode(['1' => 'lead.primeiro_nome'])]);
        return (int) $pdo->lastInsertId();
    };
    $tBoas = $tpl('boas_vindas', 'Olá, {{1}}! Obrigado pelo interesse.');
    $tLembrete = $tpl('lembrete', 'Oi, {{1}}! Ainda podemos te ajudar?');
    $tReeng = $tpl('nova_abordagem', '{{1}}, separamos novidades para você.');
    $jBoas = jornada_garantir_boas_vindas($emp);
    $p = jornada_passo($jBoas, jornada_primeiro_passo($jBoas));
    $jFol = jornada_garantir_follow_up($emp);
    foreach (['lembrete' => $tLembrete, 'reengajamento' => $tReeng] as $chave => $tid) {
        $p = jornada_passo($jFol, $chave);
        $p['config']['template_id'] = $tid;
        $pdo->prepare('UPDATE jornada_passos SET config = :c WHERE id = :id')->execute(['c' => json_encode($p['config']), 'id' => $p['id']]);
    }
    $pdo->exec("UPDATE jornadas SET status = 'ativa' WHERE id = $jFol");

    $n = 0;
    $novoLead = function (?int $resp, ?int $emprId = null) use ($pdo, $emp, $etapa, &$n) {
        $n++;
        $tel = '(11) 9' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $pdo->prepare("INSERT INTO leads (empresa_id, responsavel_id, empreendimento_id, nome, telefone, origem, etapa_id, etapa, atribuido_em, ultimo_feedback_em)
                       VALUES (:e, :r, :emp, :n, :t, 'meta_ads', :et, :c, NOW(), NOW())")
            ->execute(['e' => $emp, 'r' => $resp, 'emp' => $emprId, 'n' => "Cliente G$n Teste", 't' => $tel, 'et' => $etapa['id'], 'c' => $etapa['categoria']]);
        $id = (int) $pdo->lastInsertId();
        $ct = wa_contato_obter($emp, $tel, $id);
        wa_consentimento_registrar($emp, (int) $ct['id'], 'atendimento', 'concedido', 'formulario_meta', 'teste');
        return [$id, (int) $ct['id'], $tel];
    };
    $chega = function (string $tel, string $texto) use ($emp, $conta) {
        return wa_evento_mensagem($emp, (int) $conta['id'], ['from' => wa_normalizar_telefone($tel), 'id' => 'wamid.' . bin2hex(random_bytes(8)), 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => $texto]], 'Cliente');
    };
    $saidas = fn (int $leadId) => (int) v("SELECT COUNT(*) FROM whatsapp_mensagens m JOIN whatsapp_conversas cv ON cv.id = m.conversa_id WHERE cv.lead_id = $leadId AND m.direcao = 'saida'");
    $pendDe = fn (array $u, int $leadId) => array_values(array_filter(pendencias_do_usuario($u), fn ($p) => (int) $p['lead_id'] === $leadId));

    grupo('Roleta fechada: lead espera na fila e é entregue quando ela abre');
    [$LA, $cA, $telA] = $novoLead(null, $empr);
    $r = roleta_distribuir_lead($LA, 'meta_ads');
    igual($r['status'], 'fila', 'feriado → lead vai para a fila de espera (não some)');
    $chega($telA, 'Olá, quero saber o valor da entrada');
    igual((int) v("SELECT COUNT(*) FROM corretor_pendencias WHERE lead_id = $LA"), 0, 'cliente escreveu sem corretor → nenhuma pendência para ninguém (ainda)');
    roleta_excecao_remover($emp, (int) $x['id']);
    $pdo->exec("UPDATE roleta_corretores SET pausado = 1 WHERE roleta_id = $rol");
    roleta_processar_fila($emp);
    igual(v("SELECT responsavel_id FROM leads WHERE id = $LA"), null, 'roleta aberta mas corretores pausados → continua na fila (ninguém indisponível recebe)');
    $pdo->exec("UPDATE roleta_corretores SET pausado = 0 WHERE roleta_id = $rol");
    $r = roleta_processar_fila($emp);
    $dono = (int) v("SELECT responsavel_id FROM leads WHERE id = $LA");
    ok($r['distribuidos'] === 1 && in_array($dono, [$c1['id'], $c2['id']], true), 'roleta aberta + corretor disponível → lead distribuído pela fila');
    $u = $dono === $c1['id'] ? $c1 : $c2;
    $pA = $pendDe($u, $LA);
    ok(count($pA) === 1 && $pA[0]['tipo'] === 'responder_whatsapp', 'quem recebeu já encontra "Responder no WhatsApp" (o cliente estava esperando)');

    grupo('Transferência durante a automação');
    $pdo->exec("UPDATE jornadas SET status = 'ativa' WHERE id = $jBoas");
    $pdo->prepare('UPDATE jornada_passos SET config = JSON_SET(config, "$.template_id", :t) WHERE jornada_id = :j AND tipo = \'enviar_template\'')->execute(['t' => $tBoas, 'j' => $jBoas]);
    [$LB, $cB, $telB] = $novoLead($c1['id'], $empr);
    jornada_lead_chegou($emp, $LB, 'meta_ads');
    rodar();
    $xFol = (int) v("SELECT id FROM jornada_execucoes WHERE jornada_id = $jFol AND lead_id = $LB");
    $boasAntes = (int) v("SELECT COUNT(*) FROM jornada_execucoes WHERE jornada_id = $jBoas AND lead_id = $LB");
    ok($xFol > 0 && $boasAntes === 1, 'lead entrou nas duas jornadas (boas-vindas e follow-up)');
    avancar($xFol);   // lembrete
    lead_registrar_evento($LB, $c1['id'], 'ligacao', 'Liguei, não atendeu');
    pendencia_criar($emp, $c1['id'], $LB, 'responder_whatsapp', 'Cliente G mandou mensagem', 'teste:resp:' . $LB);
    pendencia_criar($emp, $c1['id'], $LB, 'confirmar_resposta', 'O cliente respondeu à sua tentativa?', 'teste:conf:' . $LB);
    $msgsAntes = $saidas($LB);
    lead_transferir($pdo->query("SELECT * FROM leads WHERE id = $LB")->fetch(), $c2['id'], 'administrativa', null, 'Teste');
    igual(count($pendDe($c1, $LB)), 0, 'corretor anterior não vê mais as pendências do lead');
    $p2 = $pendDe($c2, $LB);
    ok(count($p2) === 1 && $p2[0]['tipo'] === 'responder_whatsapp', 'novo corretor recebe "Responder no WhatsApp"');
    igual(v("SELECT status FROM corretor_pendencias WHERE chave_unica = 'teste:conf:$LB'"), 'cancelada', '"O cliente respondeu à SUA tentativa?" do anterior é cancelada (não faz sentido para o novo)');
    ok((int) v("SELECT COUNT(*) FROM jornada_logs WHERE execucao_id = $xFol AND evento = 'novo_corretor'") === 1, 'jornada registra a troca de corretor e continua');
    igual((int) v("SELECT COUNT(*) FROM jornada_execucoes WHERE jornada_id = $jBoas AND lead_id = $LB"), $boasAntes, 'boas-vindas NÃO recomeçam com o novo corretor');
    igual($saidas($LB), $msgsAntes, 'a transferência em si não dispara mensagem');
    jornada_lead_chegou($emp, $LB, 'meta_ads');
    rodar();
    igual($saidas($LB), $msgsAntes, 'mesmo se o lead "chegar de novo", nada é reenviado');
    avancar($xFol);   // confirmar (24 h)
    ok(str_contains((string) v("SELECT detalhe FROM jornada_logs WHERE execucao_id = $xFol AND passo_chave = 'confirmar' ORDER BY id DESC LIMIT 1"), 'Nenhuma tentativa'),
        'a ligação do corretor anterior não gera pergunta para o novo');
    lead_registrar_evento($LB, $c2['id'], 'ligacao', 'Liguei');
    ok(jornada_condicao($emp, $pdo->query("SELECT * FROM jornada_execucoes WHERE id = $xFol")->fetch(), 'houve_tentativa'), 'tentativa do NOVO corretor conta');
    avancar($xFol);   // reengajamento (48 h)
    $final = $saidas($LB);
    avancar($xFol);
    igual($saidas($LB), $final, 'limite de mensagens respeitado depois da transferência (nada além do previsto)');

    grupo('Atuação válida pelo WhatsApp (prazo da recuperação)');
    [$LC, $cC, $telC] = $novoLead($c1['id'], $empr);
    $pdo->exec("UPDATE leads SET ultimo_feedback_em = DATE_SUB(NOW(), INTERVAL 3 DAY) WHERE id = $LC");
    $chega($telC, 'Oi');
    $m = wa_enfileirar($emp, $cC, ['tipo' => 'texto', 'texto' => 'Oi! Posso te ligar?'], ['usuario_id' => $c1['id'], 'lead_id' => $LC]);
    fila_executar_chave('wa_enviar:' . $m['mensagem_id'], automacao_tratadores());
    ok((int) v("SELECT ultimo_feedback_em < DATE_SUB(NOW(), INTERVAL 1 DAY) FROM leads WHERE id = $LC") === 1, 'mensagem SIMULADA (modo de teste) não conta como atuação');
    // Envio "real" (para o simulador local) pela corretora responsável.
    $pdo->prepare("UPDATE whatsapp_contas SET modo_teste = 0, access_token_cifrado = :t, token_validado_em = NOW() WHERE id = :id")
        ->execute(['t' => cripto_cifrar('EAATESTE' . bin2hex(random_bytes(8))), 'id' => $conta['id']]);
    $m = wa_enfileirar($emp, $cC, ['tipo' => 'texto', 'texto' => 'Te ligo às 15h.'], ['usuario_id' => $c2['id'], 'lead_id' => $LC]);
    fila_executar_chave('wa_enviar:' . $m['mensagem_id'], automacao_tratadores());
    ok((int) v("SELECT ultimo_feedback_em < DATE_SUB(NOW(), INTERVAL 1 DAY) FROM leads WHERE id = $LC") === 1, 'mensagem de quem NÃO é o responsável não renova o prazo');
    $m = wa_enfileirar($emp, $cC, ['tipo' => 'texto', 'texto' => 'Combinado, até já.'], ['usuario_id' => $c1['id'], 'lead_id' => $LC]);
    fila_executar_chave('wa_enviar:' . $m['mensagem_id'], automacao_tratadores());
    igual(v("SELECT status FROM whatsapp_mensagens WHERE id = " . (int) $m['mensagem_id']), 'enviada', 'mensagem real enviada (simulador local)');
    ok((int) v("SELECT ultimo_feedback_em > DATE_SUB(NOW(), INTERVAL 1 MINUTE) FROM leads WHERE id = $LC") === 1, 'mensagem REAL da corretora responsável renova o prazo da recuperação');
    $pdo->prepare('UPDATE whatsapp_contas SET modo_teste = 1, access_token_cifrado = NULL WHERE id = :id')->execute(['id' => $conta['id']]);

    grupo('Recuperação integrada às jornadas e às pendências');
    $pdo->prepare("INSERT INTO recuperacao_regras (empresa_id, ativa, dias_sem_feedback, avisar_dias_antes, categorias, redistribuir_automaticamente)
                   VALUES (:e, 1, 4, 1, 'novo_lead,primeiro_contato,em_atendimento', 1)")->execute(['e' => $emp]);
    [$LD, $cD, $telD] = $novoLead($c1['id'], $empr);
    jornada_disparar('novo_lead', $emp, $LD, ['origem' => 'meta_ads']);
    rodar();
    $xD = (int) v("SELECT id FROM jornada_execucoes WHERE jornada_id = $jFol AND lead_id = $LD");
    pendencia_criar($emp, $c1['id'], $LD, 'responder_whatsapp', 'Cliente esperando', 'teste:resp:' . $LD);
    pendencia_criar($emp, $c1['id'], $LD, 'confirmar_resposta', 'O cliente respondeu?', 'teste:conf:' . $LD);
    $pdo->exec("UPDATE leads SET ultimo_feedback_em = DATE_SUB(NOW(), INTERVAL 5 DAY) WHERE id = $LD");
    [$LE] = $novoLead($c1['id'], $empr);   // feedback em dia: fica
    $pdo->exec("UPDATE leads SET ultimo_feedback_em = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = $LE");
    $res = recuperacao_executar($emp);
    $lD = $pdo->query("SELECT responsavel_id FROM leads WHERE id = $LD")->fetch();
    ok($res['perdidos'] === 1 && (int) $lD['responsavel_id'] === $c2['id'], '5 dias sem atuação → recuperado e redistribuído (para outro, nunca de volta para quem perdeu)');
    igual((int) v("SELECT responsavel_id FROM leads WHERE id = $LE"), $c1['id'], 'lead com atuação recente fica com a corretora');
    $pD = $pendDe($c2, $LD);
    ok(count($pD) === 1 && $pD[0]['tipo'] === 'responder_whatsapp', 'novo corretor recebe as atividades pendentes');
    igual(v("SELECT status FROM corretor_pendencias WHERE chave_unica = 'teste:conf:$LD'"), 'cancelada', 'pergunta sobre a tentativa da corretora anterior cancelada');
    ok((int) v("SELECT COUNT(*) FROM jornada_logs WHERE execucao_id = $xD AND evento = 'recuperacao'") === 1, 'jornada registra a recuperação');
    ok(in_array(v("SELECT status FROM jornada_execucoes WHERE id = $xD"), ['ativa', 'aguardando'], true), 'e a jornada continua (não recomeça nem para)');
    igual(count($pendDe($c1, $LD)), 0, 'corretora anterior perde o acesso às pendências do lead');

    // Corretor desativado: lead vai para recuperação sem esperar o prazo.
    [$LF] = $novoLead($c3['id'], $empr);
    $pdo->exec("UPDATE usuarios SET ativo = 0 WHERE id = {$c3['id']}");
    recuperacao_executar($emp);
    $lF = $pdo->query("SELECT responsavel_id FROM leads WHERE id = $LF")->fetch();
    ok($lF['responsavel_id'] !== null && (int) $lF['responsavel_id'] !== $c3['id'], 'corretor desativado → lead recuperado na hora e redistribuído');
    ok(str_contains((string) v("SELECT descricao FROM lead_eventos WHERE lead_id = $LF AND tipo = 'recuperacao'"), 'desativado'), 'histórico explica: corretor desativado');
    // Visita futura segura o lead mesmo sem feedback.
    [$LG] = $novoLead($c1['id'], $empr);
    $pdo->exec("UPDATE leads SET ultimo_feedback_em = DATE_SUB(NOW(), INTERVAL 6 DAY) WHERE id = $LG");
    $pdo->prepare("INSERT INTO agenda_compromissos (empresa_id, lead_id, usuario_id, tipo, titulo, inicio, status) VALUES (:e, :l, :u, 'visita', 'Visita', DATE_ADD(NOW(), INTERVAL 2 DAY), 'agendado')")
        ->execute(['e' => $emp, 'l' => $LG, 'u' => $c1['id']]);
    recuperacao_executar($emp);
    igual((int) v("SELECT responsavel_id FROM leads WHERE id = $LG"), $c1['id'], 'visita agendada → lead continua com a corretora');

    grupo('Isolamento entre empresas');
    $pdo->prepare("INSERT INTO leads (empresa_id, nome, telefone, etapa, ultimo_feedback_em) VALUES (:e, 'Lead da outra Teste', '11999990000', 'novo_lead', DATE_SUB(NOW(), INTERVAL 9 DAY))")->execute(['e' => $emp2]);
    $L2 = (int) $pdo->lastInsertId();
    recuperacao_executar($emp);
    igual((int) v("SELECT COUNT(*) FROM lead_eventos WHERE lead_id = $L2"), 0, 'recuperação de uma empresa não toca leads da outra');
    igual(pendencias_seguir_responsavel($L2), 0, 'lead sem responsável: nada a mover');
} finally {
    db()->exec("DELETE FROM whatsapp_webhook_eventos WHERE empresa_id IN ($emp, $emp2)");
    apagar_empresa_teste($emp);
    apagar_empresa_teste($emp2);
    foreach (glob(__DIR__ . '/tmp/mock_*') as $arq) {
        @unlink($arq);
    }
    grupo('Limpeza');
    igual((int) db()->query("SELECT COUNT(*) FROM empresas WHERE nome LIKE 'Teste automatizado%'")->fetchColumn(), 0, 'nenhum dado de teste ficou no banco');
}
resumo_e_sair();
