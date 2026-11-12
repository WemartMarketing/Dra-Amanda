<?php
/**
 * Testes da FASE 5 — follow-ups e confirmação de atendimento: horas úteis,
 * lembrete, pergunta ao corretor, nova abordagem, paradas por resposta,
 * central de pendências. Empresa fictícia em MODO DE TESTE.
 *
 *   php tests/whatsapp_fase5_test.php
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../includes/automacao.php';
require_once __DIR__ . '/../includes/jornadas.php';
require_once __DIR__ . '/../includes/funcoes.php';
require_once __DIR__ . '/../includes/funil.php';

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
/** "Faz o tempo passar": a próxima tarefa agendada da execução fica para agora. */
function avancar(int $execId): void
{
    db()->exec("UPDATE automacao_fila SET executar_em = NOW() WHERE referencia_tipo = 'jornada_execucao' AND referencia_id = $execId AND status = 'pendente'");
    rodar();
}

$tz = new DateTimeZone('America/Sao_Paulo');
$t = fn (string $s) => new DateTimeImmutable($s, $tz);

grupo('Horas úteis (dentro do horário de envio)');
// 2026-11-16 é segunda-feira.
igual(jornada_somar_minutos_uteis($t('2026-11-16 23:40'), 120, '08:00:00', '21:00:00', '1,2,3,4,5,6,7')->format('Y-m-d H:i'), '2026-11-17 10:00', 'lead às 23h40 + 2 h úteis → amanhã 10h00 (não 1h40 da madrugada)');
igual(jornada_somar_minutos_uteis($t('2026-11-16 20:30'), 120, '08:00:00', '21:00:00', '1,2,3,4,5,6,7')->format('Y-m-d H:i'), '2026-11-17 09:30', '20h30 + 2 h úteis → 30 min hoje + 1h30 amanhã = 09h30');
igual(jornada_somar_minutos_uteis($t('2026-11-16 10:00'), 120, '08:00:00', '21:00:00', '1,2,3,4,5,6,7')->format('Y-m-d H:i'), '2026-11-16 12:00', '10h00 + 2 h úteis → 12h00');
igual(jornada_somar_minutos_uteis($t('2026-11-20 20:00'), 120, '08:00:00', '21:00:00', '1,2,3,4,5')->format('Y-m-d H:i'), '2026-11-23 09:00', 'sexta 20h00, envio só seg–sex → segunda 09h00');
igual(jornada_somar_minutos_uteis($t('2026-11-16 10:00'), 90, '21:00:00', '08:00:00', '1,2,3,4,5,6,7')->format('Y-m-d H:i'), '2026-11-16 11:30', 'horário que atravessa a meia-noite → conta tempo corrido');

$emp = criar_empresa_teste('Follow-up');
try {
    $pdo = db();
    $f = funcoes_seed_padrao($emp);
    funil_seed_padrao($emp);
    $etapa = funil_primeira_etapa($emp);
    $etapaAtend = funil_etapa_por_categoria($emp, 'em_atendimento');
    $novoUsuario = function (string $nome) use ($pdo, $emp, $f) {
        $pdo->prepare('INSERT INTO usuarios (empresa_id, funcao_id, nome, email, senha_hash, cargo) VALUES (:e, :f, :n, :m, :h, :c)')
            ->execute(['e' => $emp, 'f' => $f['corretor'], 'n' => $nome, 'm' => bin2hex(random_bytes(5)) . '@teste.invalid', 'h' => 'x', 'c' => 'corretor']);
        $id = (int) $pdo->lastInsertId();
        return ['id' => $id, 'nome' => $nome, 'empresa_id' => $emp, 'empresa_ativa_id' => $emp, 'eh_superadmin' => false, 'escopo' => 'propria_carteira',
                'permissoes' => ['visualizar_leads', 'editar_leads', 'whatsapp_atender']];
    };
    $c1 = $novoUsuario('Corretor Um');
    $c2 = $novoUsuario('Corretor Dois');
    $pdo->prepare("INSERT INTO whatsapp_contas (empresa_id, nome, phone_number_id, status, modo_teste) VALUES (:e, 'Teste', :p, 'ativa', 1)")
        ->execute(['e' => $emp, 'p' => (string) random_int(10 ** 9, 10 ** 10)]);
    $conta = wa_conta_da_empresa($emp);
    // Envio liberado o dia todo (o teste não depende da hora em que roda).
    $pdo->prepare("INSERT INTO whatsapp_config (empresa_id, envio_inicio, envio_fim, max_automaticas_24h) VALUES (:e, '00:00:00', '23:59:00', 5)")->execute(['e' => $emp]);
    $tpl = function (string $nome, string $corpo) use ($pdo, $emp, $conta) {
        $pdo->prepare("INSERT INTO whatsapp_templates (empresa_id, conta_id, nome, categoria, status, corpo, variaveis) VALUES (:e, :c, :n, 'utility', 'aprovado', :b, :v)")
            ->execute(['e' => $emp, 'c' => $conta['id'], 'n' => $nome, 'b' => $corpo, 'v' => json_encode(['1' => 'lead.primeiro_nome'])]);
        return (int) $pdo->lastInsertId();
    };
    $tLembrete = $tpl('lembrete', 'Oi, {{1}}! Ainda podemos te ajudar?');
    $tReeng = $tpl('nova_abordagem', '{{1}}, separamos novidades para você. Quer conversar?');
    $jid = jornada_garantir_follow_up($emp);
    igual(jornada_garantir_follow_up($emp), $jid, 'jornada de follow-up única por empresa');
    igual(v("SELECT status FROM jornadas WHERE id = $jid"), 'rascunho', 'nasce desligada');
    foreach (['lembrete' => $tLembrete, 'reengajamento' => $tReeng] as $chave => $tid) {
        $p = jornada_passo($jid, $chave);
        $p['config']['template_id'] = $tid;
        $pdo->prepare('UPDATE jornada_passos SET config = :c WHERE id = :id')->execute(['c' => json_encode($p['config']), 'id' => $p['id']]);
    }
    $pdo->exec("UPDATE jornadas SET status = 'ativa' WHERE id = $jid");

    $n = 0;
    $novoLead = function (?int $resp) use ($pdo, $emp, $etapa, &$n) {
        $n++;
        $tel = '(11) 9' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $pdo->prepare("INSERT INTO leads (empresa_id, responsavel_id, nome, telefone, origem, etapa_id, etapa, atribuido_em) VALUES (:e, :r, :n, :t, 'meta_ads', :et, :c, NOW())")
            ->execute(['e' => $emp, 'r' => $resp, 'n' => "Cliente F$n Teste", 't' => $tel, 'et' => $etapa['id'], 'c' => $etapa['categoria']]);
        $id = (int) $pdo->lastInsertId();
        $ct = wa_contato_obter($emp, $tel, $id);
        wa_consentimento_registrar($emp, (int) $ct['id'], 'atendimento', 'concedido', 'formulario_meta', 'teste');
        return [$id, (int) $ct['id'], $tel];
    };
    $saidas = fn (int $leadId) => $pdo->query("SELECT m.template_id, m.status, m.enviada_por FROM whatsapp_mensagens m JOIN whatsapp_conversas cv ON cv.id = m.conversa_id
                                               WHERE cv.lead_id = $leadId AND m.direcao = 'saida' ORDER BY m.id")->fetchAll();
    $chega = function (string $tel, string $texto) use ($emp, $conta) {
        $digitos = wa_normalizar_telefone($tel);
        return wa_evento_mensagem($emp, (int) $conta['id'], ['from' => $digitos, 'id' => 'wamid.' . bin2hex(random_bytes(8)), 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => $texto]], 'Cliente');
    };

    grupo('Sem resposta: lembrete → pergunta ao corretor → nova abordagem');
    [$LA, $cA, $telA] = $novoLead($c1['id']);
    $xA = jornada_disparar('novo_lead', $emp, $LA, ['origem' => 'meta_ads'])[0];
    rodar();
    $x = $pdo->query("SELECT * FROM jornada_execucoes WHERE id = $xA")->fetch();
    ok($x['status'] === 'aguardando' && $x['passo_chave'] === 'checa_1', 'primeiro passo: esperando 2 horas úteis');
    igual(count($saidas($LA)), 0, 'nada enviado ainda');
    avancar($xA);
    $s = $saidas($LA);
    ok(count($s) === 1 && (int) $s[0]['template_id'] === $tLembrete, 'depois de 2 h úteis sem resposta → lembrete');
    // O corretor tentou ligar (registro de uma PESSOA no lead).
    lead_registrar_evento($LA, $c1['id'], 'ligacao', 'Ligação sem atender');
    avancar($xA);
    $pend = pendencias_do_usuario($c1);
    ok(count($pend) === 1 && $pend[0]['tipo'] === 'confirmar_resposta' && str_contains($pend[0]['titulo'], 'respondeu à sua tentativa'), 'após 24 h: corretor recebe "O cliente respondeu à sua tentativa de contato?"');
    igual(pendencias_contar($c1), 1, 'contador da central (sino) = 1');
    avancar($xA);
    $s = $saidas($LA);
    ok(count($s) === 2 && (int) $s[1]['template_id'] === $tReeng, 'após 48 h sem resposta → nova abordagem com OUTRO template');
    igual(v("SELECT status FROM jornada_execucoes WHERE id = $xA"), 'concluida', 'jornada concluída (sem insistir além disso)');
    igual(count($saidas($LA)), 2, 'no total: 2 mensagens, nunca mais');

    grupo('Pergunta ao corretor só quando faz sentido');
    [$LB, $cB] = $novoLead($c1['id']);
    $xB = jornada_disparar('novo_lead', $emp, $LB, ['origem' => 'meta_ads'])[0];
    rodar();
    avancar($xB);   // lembrete
    avancar($xB);   // confirmar
    ok(str_contains((string) v("SELECT detalhe FROM jornada_logs WHERE execucao_id = $xB AND passo_chave = 'confirmar'"), 'Nenhuma tentativa'), 'sem tentativa registrada pelo corretor → não pergunta');
    igual(count(array_filter(pendencias_do_usuario($c1), fn ($p) => (int) $p['lead_id'] === $LB)), 0, 'nenhuma pendência para este lead');
    [$LC] = $novoLead(null);
    $xC = jornada_disparar('novo_lead', $emp, $LC, ['origem' => 'meta_ads'])[0];
    rodar();
    avancar($xC);
    avancar($xC);
    ok(str_contains((string) v("SELECT detalhe FROM jornada_logs WHERE execucao_id = $xC AND passo_chave = 'confirmar'"), 'sem corretor'), 'lead ainda sem corretor → ninguém para perguntar');

    grupo('Cliente respondeu: os lembretes param e o corretor é avisado');
    [$LD, $cD, $telD] = $novoLead($c1['id']);
    $xD = jornada_disparar('novo_lead', $emp, $LD, ['origem' => 'meta_ads'])[0];
    rodar();
    $chega($telD, 'Oi! Tenho interesse sim.');
    $x = $pdo->query("SELECT * FROM jornada_execucoes WHERE id = $xD")->fetch();
    ok($x['status'] === 'concluida' && $x['motivo'] === 'Cliente respondeu pelo WhatsApp', 'resposta do cliente encerra o follow-up');
    igual((int) v("SELECT COUNT(*) FROM automacao_fila WHERE referencia_tipo = 'jornada_execucao' AND referencia_id = $xD AND status = 'pendente'"), 0, 'passos agendados cancelados');
    avancar($xD);
    igual(count($saidas($LD)), 0, 'nenhum lembrete sai depois da resposta');
    $p = array_values(array_filter(pendencias_do_usuario($c1), fn ($p) => (int) $p['lead_id'] === $LD));
    ok(count($p) === 1 && $p[0]['tipo'] === 'responder_whatsapp', 'corretor recebe a pendência "mandou mensagem no WhatsApp"');
    $chega($telD, 'Pode me ligar?');
    igual(count(array_filter(pendencias_do_usuario($c1), fn ($p) => (int) $p['lead_id'] === $LD)), 1, 'segunda mensagem não duplica a pendência');
    $r = wa_enfileirar($emp, $cD, ['tipo' => 'texto', 'texto' => 'Claro! Te ligo em 5 minutos.'], ['usuario_id' => $c1['id'], 'lead_id' => $LD]);
    fila_executar_chave('wa_enviar:' . $r['mensagem_id'], automacao_tratadores());
    igual(count(array_filter(pendencias_do_usuario($c1), fn ($p) => (int) $p['lead_id'] === $LD)), 0, 'corretor respondeu pelo chat → pendência resolvida sozinha');

    grupo('Alguém já respondeu pelo WhatsApp: sem lembrete automático');
    [$LE, $cE] = $novoLead($c1['id']);
    $xE = jornada_disparar('novo_lead', $emp, $LE, ['origem' => 'meta_ads'])[0];
    rodar();
    $r = wa_enfileirar($emp, $cE, ['tipo' => 'template', 'template_id' => $tLembrete, 'dados' => wa_dados_lead($emp, $LE)], ['usuario_id' => $c1['id'], 'lead_id' => $LE]);
    fila_executar_chave('wa_enviar:' . $r['mensagem_id'], automacao_tratadores());
    avancar($xE);
    ok(v("SELECT status FROM jornada_execucoes WHERE id = $xE") === 'concluida' && count($saidas($LE)) === 1, 'o corretor já mandou mensagem → condição "sim" → fim, sem lembrete');

    grupo('Respostas do corretor na central de pendências');
    [$LF, $cF, $telF] = $novoLead($c1['id']);
    $xF = jornada_disparar('novo_lead', $emp, $LF, ['origem' => 'meta_ads'])[0];
    rodar();
    avancar($xF);
    lead_registrar_evento($LF, $c1['id'], 'ligacao', 'Liguei');
    avancar($xF);
    $pF = array_values(array_filter(pendencias_do_usuario($c1), fn ($p) => (int) $p['lead_id'] === $LF))[0];
    $antes = count($saidas($LF));
    igual(pendencia_responder($c1, (int) $pF['id'], 'adiar', '', null, 2)['ok'], true, '"Lembrar mais tarde" aceito');
    igual(count(array_filter(pendencias_do_usuario($c1), fn ($p) => (int) $p['lead_id'] === $LF)), 0, 'adiada some da lista até a hora do lembrete');
    $pdo->exec('UPDATE corretor_pendencias SET lembrar_em = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ' . (int) $pF['id']);
    igual(count(array_filter(pendencias_do_usuario($c1), fn ($p) => (int) $p['lead_id'] === $LF)), 1, 'na hora do lembrete, volta para a lista');
    $r = pendencia_responder($c1, (int) $pF['id'], 'nao_respondeu');
    ok($r['ok'] && str_contains($r['mensagem'], 'Nenhuma mensagem é enviada'), '"Não respondeu" registrado');
    igual(count($saidas($LF)), $antes, '"Não respondeu" NÃO dispara mensagem ao cliente');
    ok((int) v("SELECT COUNT(*) FROM lead_eventos WHERE lead_id = $LF AND descricao LIKE 'Tentativa de contato sem resposta%'") === 1, 'registro no histórico do lead');

    [$LG, $cG] = $novoLead($c1['id']);
    $xG = jornada_disparar('novo_lead', $emp, $LG, ['origem' => 'meta_ads'])[0];
    rodar();
    avancar($xG);
    lead_registrar_evento($LG, $c1['id'], 'ligacao', 'Liguei');
    avancar($xG);
    $pG = array_values(array_filter(pendencias_do_usuario($c1), fn ($p) => (int) $p['lead_id'] === $LG))[0];
    igual(pendencia_responder($c1, (int) $pG['id'], 'respondeu', '')['ok'], false, '"Sim, respondeu" pede o que o cliente disse');
    $pdo->exec("UPDATE leads SET ultimo_feedback_em = DATE_SUB(NOW(), INTERVAL 3 DAY) WHERE id = $LG");
    $r = pendencia_responder($c1, (int) $pG['id'], 'respondeu', 'Atendeu, quer visitar no sábado', (int) $etapaAtend['id']);
    ok($r['ok'], '"Sim, respondeu" com o resultado e a nova etapa');
    $lg = $pdo->query("SELECT etapa_id, ultimo_feedback_em > DATE_SUB(NOW(), INTERVAL 1 MINUTE) AS fb FROM leads WHERE id = $LG")->fetch();
    ok((int) $lg['etapa_id'] === (int) $etapaAtend['id'] && (int) $lg['fb'] === 1, 'etapa atualizada no Kanban e conta como atendimento (prazo da recuperação renovado)');
    ok(v("SELECT status FROM jornada_execucoes WHERE id = $xG") === 'concluida', 'follow-up encerrado: o corretor confirmou a resposta');

    grupo('Lead avançou no funil ou foi transferido');
    [$LH, $cH] = $novoLead($c1['id']);
    $xH = jornada_disparar('novo_lead', $emp, $LH, ['origem' => 'meta_ads'])[0];
    rodar();
    $pdo->exec("UPDATE leads SET etapa = 'em_atendimento' WHERE id = $LH");
    avancar($xH);
    igual(v("SELECT motivo FROM jornada_execucoes WHERE id = $xH"), 'Lead avançou no funil', 'lead em atendimento → follow-up de "sem resposta" para');
    igual(count($saidas($LH)), 0, 'nenhum lembrete');
    [$LI, $cI] = $novoLead($c1['id']);
    $xI = jornada_disparar('novo_lead', $emp, $LI, ['origem' => 'meta_ads'])[0];
    rodar();
    avancar($xI);
    lead_registrar_evento($LI, $c1['id'], 'ligacao', 'Liguei');
    avancar($xI);
    $pI = array_values(array_filter(pendencias_do_usuario($c1), fn ($p) => (int) $p['lead_id'] === $LI))[0];
    $pdo->exec("UPDATE leads SET responsavel_id = {$c2['id']} WHERE id = $LI");
    igual(count(array_filter(pendencias_do_usuario($c1), fn ($p) => (int) $p['lead_id'] === $LI)), 0, 'lead transferido → some da central do corretor anterior');
    igual(pendencia_responder($c1, (int) $pI['id'], 'nao_respondeu')['ok'], false, 'e ele não consegue mais responder por ela');
    igual(pendencia_responder($c2, (int) $pI['id'], 'nao_respondeu')['ok'], false, 'o novo corretor não responde pendência que não é dele');

    grupo('Regras de envio continuam valendo');
    $p = jornada_passo($jid, 'lembrete');
    $p['config']['template_id'] = null;
    $pdo->prepare('UPDATE jornada_passos SET config = :c WHERE id = :id')->execute(['c' => json_encode($p['config']), 'id' => $p['id']]);
    [$LJ] = $novoLead($c1['id']);
    $xJ = jornada_disparar('novo_lead', $emp, $LJ, ['origem' => 'meta_ads'])[0];
    rodar();
    avancar($xJ);
    ok(count($saidas($LJ)) === 0 && v("SELECT evento FROM jornada_logs WHERE execucao_id = $xJ AND passo_chave = 'lembrete'") === 'pulado', 'sem template de lembrete → passo pulado, sem enviar nada');
    igual((int) v("SELECT COUNT(*) FROM lead_eventos WHERE lead_id = $LJ AND tipo = 'jornada'"), 0, 'e sem poluir o histórico do lead');
    $p['config']['template_id'] = $tLembrete;
    $pdo->prepare('UPDATE jornada_passos SET config = :c WHERE id = :id')->execute(['c' => json_encode($p['config']), 'id' => $p['id']]);
    // Fora do horário de envio: o lembrete fica agendado para o próximo horário, não sai na hora.
    $agora = new DateTimeImmutable('now', $tz);
    $ini = $agora->modify('+3 hours')->format('H:i:00');
    $fim = $agora->modify('+4 hours')->format('H:i:00');
    if ($ini < $fim) {
        $pdo->prepare('UPDATE whatsapp_config SET envio_inicio = :i, envio_fim = :f WHERE empresa_id = :e')->execute(['i' => $ini, 'f' => $fim, 'e' => $emp]);
        [$LK] = $novoLead($c1['id']);
        $xK = jornada_disparar('novo_lead', $emp, $LK, ['origem' => 'meta_ads'])[0];
        rodar();
        $pdo->exec("UPDATE automacao_fila SET executar_em = NOW() WHERE referencia_tipo = 'jornada_execucao' AND referencia_id = $xK AND status = 'pendente'");
        rodar();
        $s = $saidas($LK);
        $agendada = (int) v("SELECT COUNT(*) FROM automacao_fila WHERE tipo = 'wa_enviar' AND empresa_id = $emp AND status = 'pendente' AND executar_em > NOW()");
        ok(count($s) <= 1 && ($s ? $s[0]['status'] === 'na_fila' : true) && $agendada >= 1, 'fora do horário de envio → lembrete fica agendado para o próximo horário');
    } else {
        ok(true, 'fora do horário de envio (verificação pulada perto da meia-noite)');
    }
} finally {
    db()->exec("DELETE FROM whatsapp_webhook_eventos WHERE empresa_id = $emp");
    apagar_empresa_teste($emp);
    grupo('Limpeza');
    igual((int) db()->query("SELECT COUNT(*) FROM empresas WHERE nome LIKE 'Teste automatizado%'")->fetchColumn(), 0, 'nenhum dado de teste ficou no banco');
}
resumo_e_sair();
