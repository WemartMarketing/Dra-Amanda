<?php
/**
 * Testes da FASE 1 (estrutura) do módulo WhatsApp + Jornadas Inteligentes.
 * Nenhuma mensagem é enviada: esta fase não tem cliente da API da Meta.
 *
 *   php tests/whatsapp_fase1_test.php
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../includes/whatsapp_regras.php';
require_once __DIR__ . '/../includes/automacao_fila.php';
require_once __DIR__ . '/../includes/roleta_horarios.php';
require_once __DIR__ . '/../includes/roleta.php';

$tz = new DateTimeZone('America/Sao_Paulo');
$em = fn (string $s) => new DateTimeImmutable($s, $tz);

// =================================================================== regras puras
grupo('Telefone (normalização para o padrão do WhatsApp)');
igual(wa_normalizar_telefone('(11) 98888-7777'), '5511988887777', 'celular com DDD ganha o 55');
igual(wa_normalizar_telefone('011 98888-7777'), '5511988887777', 'zero antes do DDD é removido');
igual(wa_normalizar_telefone('+55 11 98888-7777'), '5511988887777', 'formato internacional brasileiro');
igual(wa_normalizar_telefone('5511988887777'), '5511988887777', 'já normalizado continua igual');
igual(wa_normalizar_telefone('(11) 3333-4444'), '551133334444', 'fixo com DDD');
igual(wa_normalizar_telefone('+1 415 555 2671'), '14155552671', 'número estrangeiro com + é mantido');
igual(wa_normalizar_telefone('123'), null, 'número curto é recusado');
igual(wa_normalizar_telefone('(00) 91234-5678'), null, 'DDD inválido é recusado');
igual(wa_normalizar_telefone(''), null, 'vazio é recusado');

grupo('Janela de atendimento de 24 horas');
$agora = $em('2026-11-12 10:00:00');
ok(wa_regra_janela_aberta('2026-11-11 11:00:00', $agora), 'cliente escreveu há 23 h → janela aberta');
ok(!wa_regra_janela_aberta('2026-11-11 09:00:00', $agora), 'cliente escreveu há 25 h → janela fechada');
ok(!wa_regra_janela_aberta('2026-11-11 10:00:00', $agora), 'exatamente 24 h → janela fechada');
ok(!wa_regra_janela_aberta(null, $agora), 'cliente nunca escreveu → janela fechada');

grupo('Descadastro por palavra-chave');
$palavras = 'PARAR,SAIR,DESCADASTRAR,STOP,CANCELAR';
ok(wa_regra_eh_descadastro('PARAR', $palavras), '"PARAR"');
ok(wa_regra_eh_descadastro('  sair. ', $palavras), '"sair." com espaços e ponto');
ok(wa_regra_eh_descadastro('Stop', $palavras), '"Stop"');
ok(wa_regra_eh_descadastro('Descadastrar!', $palavras), '"Descadastrar!"');
ok(!wa_regra_eh_descadastro('não quero parar de receber', $palavras), 'frase que contém "parar" NÃO descadastra');
ok(!wa_regra_eh_descadastro('Qual o valor da entrada?', $palavras), 'pergunta comum não descadastra');
ok(!wa_regra_eh_descadastro('', $palavras), 'mensagem vazia não descadastra');

grupo('Variáveis do template (validação antes de enviar)');
$dados = ['lead.primeiro_nome' => 'Ana', 'empresa.nome' => 'HM Vendas', 'empreendimento.nome' => "Vila\n das   Belezas"];
$r = wa_regra_renderizar('Olá, {{1}}! Aqui é a {{2}}. Interesse no {{3}}.', ['1' => 'lead.primeiro_nome', '2' => 'empresa.nome', '3' => 'empreendimento.nome'], $dados);
ok($r['ok'], 'todas as variáveis preenchidas → ok');
igual($r['texto'], 'Olá, Ana! Aqui é a HM Vendas. Interesse no Vila das Belezas.', 'prévia montada e quebra de linha/espaços do dado removidos');
$r = wa_regra_renderizar('Olá, {{nome}}!', ['nome' => 'lead.primeiro_nome'], $dados);
ok($r['ok'] && $r['texto'] === 'Olá, Ana!', 'variável nomeada {{nome}}');
$r = wa_regra_renderizar('Olá, {{1}} e {{2}}', ['1' => 'lead.primeiro_nome'], $dados);
ok(!$r['ok'] && str_contains(implode(' ', $r['erros']), '{{2}}'), 'variável sem ligação → bloqueia');
$r = wa_regra_renderizar('Olá, {{1}}', ['1' => 'lead.cpf'], $dados);
ok(!$r['ok'], 'dado fora da lista permitida (lead.cpf) → bloqueia');
$r = wa_regra_renderizar('Corretor: {{1}}', ['1' => 'corretor.nome'], $dados);
ok(!$r['ok'] && str_contains($r['erros'][0], 'corretor'), 'dado vazio (lead sem corretor) → bloqueia, não envia "{{1}}"');

grupo('Decisão de envio (consentimento, janela, template, frequência, horário)');
$base = [
    'conta_ativa' => true, 'tipo' => 'template', 'automatica' => true, 'janela_aberta' => false, 'opt_out' => false,
    'template_status' => 'aprovado', 'template_categoria' => 'utility', 'consentimento_atendimento' => true,
    'consentimento_marketing' => false, 'automaticas_24h' => 0, 'max_automaticas_24h' => 2, 'marketing_7d' => 0,
    'max_marketing_7d' => 2, 'no_horario_envio' => true, 'proximo_horario' => null,
];
$d = fn (array $mudar) => wa_regra_pode_enviar($mudar + $base);
igual($d([])['codigo'], 'ok', 'template de utilidade aprovado + consentimento → pode');
igual($d(['conta_ativa' => false])['codigo'], 'conta_inativa', 'WhatsApp desconectado → bloqueia');
igual($d(['template_status' => 'pendente'])['codigo'], 'template_nao_aprovado', 'template pendente → bloqueia');
igual($d(['template_status' => 'rejeitado'])['codigo'], 'template_nao_aprovado', 'template rejeitado → bloqueia');
igual($d(['consentimento_atendimento' => false])['codigo'], 'sem_consentimento', 'sem consentimento e sem janela → bloqueia');
igual($d(['consentimento_atendimento' => false, 'janela_aberta' => true])['codigo'], 'ok', 'sem consentimento formal, mas o cliente escreveu (janela aberta) → pode');
igual($d(['template_categoria' => 'marketing'])['codigo'], 'sem_consentimento_marketing', 'marketing sem autorização de marketing → bloqueia');
igual($d(['template_categoria' => 'marketing', 'consentimento_marketing' => true, 'marketing_7d' => 2])['codigo'], 'limite_marketing', 'marketing acima do limite semanal → bloqueia');
igual($d(['opt_out' => true])['codigo'], 'descadastrado', 'descadastrado → automação bloqueada');
igual($d(['opt_out' => true, 'automatica' => false, 'tipo' => 'livre', 'janela_aberta' => true, 'cliente_escreveu_depois_do_opt_out' => true])['codigo'], 'ok',
    'descadastrado que voltou a escrever → corretor pode responder manualmente');
igual($d(['opt_out' => true, 'automatica' => false, 'tipo' => 'livre', 'janela_aberta' => true, 'cliente_escreveu_depois_do_opt_out' => false])['codigo'], 'descadastrado',
    'descadastrado sem ter voltado a escrever → nem manual');
igual($d(['tipo' => 'livre', 'automatica' => false])['codigo'], 'janela_fechada', 'mensagem livre com janela fechada → exige template');
igual($d(['tipo' => 'livre', 'automatica' => false, 'janela_aberta' => true])['codigo'], 'ok', 'mensagem livre com janela aberta → pode');
igual($d(['automaticas_24h' => 2])['codigo'], 'limite_frequencia', 'automação acima do teto de 24 h → bloqueia');
igual($d(['automaticas_24h' => 5, 'automatica' => false])['codigo'], 'ok', 'teto de automação não limita o corretor');
$prox = $em('2026-11-13 08:00:00');
$res = $d(['no_horario_envio' => false, 'proximo_horario' => $prox]);
ok($res['codigo'] === 'fora_do_horario' && $res['adiar_para'] == $prox, 'follow-up fora do horário → adia para o próximo horário (não descarta)');
igual($d(['no_horario_envio' => false, 'ignorar_horario' => true])['codigo'], 'ok', 'boas-vindas imediatas (ignorar_horario) saem às 23h40');

grupo('Horário de envio automático');
[$okH, $p] = wa_regra_horario_envio('08:00:00', '21:00:00', '1,2,3,4,5,6,7', $em('2026-11-12 07:00:00'));
ok(!$okH && $p == $em('2026-11-12 08:00:00'), '07:00 → fora; próximo 08:00 do mesmo dia');
[$okH, $p] = wa_regra_horario_envio('08:00:00', '21:00:00', '1,2,3,4,5,6,7', $em('2026-11-12 23:40:00'));
ok(!$okH && $p == $em('2026-11-13 08:00:00'), '23:40 → fora; próximo 08:00 de amanhã');
[$okH] = wa_regra_horario_envio('08:00:00', '21:00:00', '1,2,3,4,5,6,7', $em('2026-11-12 12:00:00'));
ok($okH, '12:00 → dentro');
[$okH, $p] = wa_regra_horario_envio('08:00:00', '21:00:00', '1,2,3,4,5', $em('2026-11-14 10:00:00')); // sábado
ok(!$okH && $p == $em('2026-11-16 08:00:00'), 'sábado com envio só seg–sex → segunda 08:00');

grupo('Expediente das roletas (horário por dia, pausa, feriado, exceção)');
// 2026-11-16 é segunda-feira.
$roleta = ['id' => 1, 'empresa_id' => 1, 'dias_semana' => '1,2,3,4,5', 'horario_inicio' => '09:30:00', 'horario_fim' => '21:00:00'];
$seg_sex = [];
foreach ([1, 2, 3, 4, 5] as $dia) {
    $seg_sex[] = ['dia_semana' => $dia, 'inicio' => '09:30:00', 'fim' => '21:00:00', 'pausa_inicio' => null, 'pausa_fim' => null];
}
$s = roleta_exp_situacao($roleta, $seg_sex, [], $em('2026-11-16 10:00:00'));
ok($s['aberta'], 'segunda 10:00 → aberta');
$s = roleta_exp_situacao($roleta, $seg_sex, [], $em('2026-11-16 23:40:00'));
ok(!$s['aberta'] && $s['proxima_abertura'] == $em('2026-11-17 09:30:00'), 'segunda 23:40 → fechada; abre terça 09:30');
ok(str_contains($s['motivo'], 'amanhã às 09:30'), 'mensagem diz "amanhã às 09:30"');
$s = roleta_exp_situacao($roleta, $seg_sex, [], $em('2026-11-20 23:40:00')); // sexta
ok(!$s['aberta'] && $s['proxima_abertura'] == $em('2026-11-23 09:30:00'), 'sexta 23:40 → abre só segunda 09:30');
$comPausa = $seg_sex;
$comPausa[0] = ['dia_semana' => 1, 'inicio' => '09:30:00', 'fim' => '18:00:00', 'pausa_inicio' => '12:00:00', 'pausa_fim' => '13:00:00'];
$s = roleta_exp_situacao($roleta, $comPausa, [], $em('2026-11-16 12:30:00'));
ok(!$s['aberta'] && $s['proxima_abertura'] == $em('2026-11-16 13:00:00'), 'pausa de almoço 12–13 → fechada às 12:30, reabre 13:00');
ok(roleta_exp_situacao($roleta, $comPausa, [], $em('2026-11-16 13:05:00'))['aberta'], '13:05 → aberta de novo');
ok(!roleta_exp_situacao($roleta, $comPausa, [], $em('2026-11-16 18:30:00'))['aberta'], 'segunda com fim às 18:00 → 18:30 fechada (dia diferente dos outros)');
$noite = [['dia_semana' => 1, 'inicio' => '22:00:00', 'fim' => '02:00:00', 'pausa_inicio' => null, 'pausa_fim' => null]];
ok(roleta_exp_situacao($roleta, $noite, [], $em('2026-11-17 01:00:00'))['aberta'], 'plantão 22h–2h de segunda → terça 01:00 aberta');
ok(!roleta_exp_situacao($roleta, $noite, [], $em('2026-11-17 02:30:00'))['aberta'], 'terça 02:30 → fechada');
$feriado = [['roleta_id' => null, 'data' => '2026-11-20', 'tipo' => 'fechado', 'inicio' => null, 'fim' => null, 'descricao' => 'Consciência Negra']];
$s = roleta_exp_situacao($roleta, $seg_sex, $feriado, $em('2026-11-20 10:00:00'));
ok(!$s['aberta'] && str_contains($s['motivo'], 'Consciência Negra'), 'feriado da empresa → fechada, com o motivo');
$especial = [['roleta_id' => 1, 'data' => '2026-11-18', 'tipo' => 'horario_especial', 'inicio' => '10:00:00', 'fim' => '14:00:00', 'descricao' => 'Evento']];
ok(!roleta_exp_situacao($roleta, $seg_sex, $especial, $em('2026-11-18 09:45:00'))['aberta'], 'horário especial 10–14 → 09:45 fechada');
ok(roleta_exp_situacao($roleta, $seg_sex, $especial, $em('2026-11-18 10:30:00'))['aberta'], 'horário especial → 10:30 aberta');
ok(!roleta_exp_situacao($roleta, $seg_sex, $especial, $em('2026-11-18 15:00:00'))['aberta'], 'horário especial → 15:00 fechada');
$duas = [
    ['roleta_id' => null, 'data' => '2026-11-19', 'tipo' => 'fechado', 'inicio' => null, 'fim' => null, 'descricao' => 'Empresa'],
    ['roleta_id' => 1, 'data' => '2026-11-19', 'tipo' => 'horario_especial', 'inicio' => '09:00:00', 'fim' => '12:00:00', 'descricao' => 'Plantão especial'],
];
ok(roleta_exp_situacao($roleta, $seg_sex, $duas, $em('2026-11-19 10:00:00'))['aberta'], 'exceção da roleta vence o fechamento geral da empresa');
ok(roleta_exp_situacao($roleta, [], [], $em('2026-11-16 10:00:00'))['aberta'], 'sem grade por dia → usa o horário antigo (seg–sex 09:30–21:00)');
ok(!roleta_exp_situacao($roleta, [], [], $em('2026-11-15 10:00:00'))['aberta'], 'sem grade → domingo fechado pelo horário antigo');
ok(roleta_exp_situacao(['id' => 1, 'dias_semana' => '1,2,3,4,5,6,7', 'horario_inicio' => null, 'horario_fim' => null], [], [], $em('2026-11-15 03:00:00'))['aberta'], 'roleta sem horário → 24 h');

// =================================================================== banco
$empA = criar_empresa_teste('A');
$empB = criar_empresa_teste('B');
try {
    grupo('Fila persistente de tarefas');
    $id1 = fila_agendar($empA, 'teste_ok', ['x' => 1], 'teste:' . $empA . ':1');
    ok($id1 > 0, 'tarefa agendada');
    igual(fila_agendar($empA, 'teste_ok', ['x' => 1], 'teste:' . $empA . ':1'), null, 'mesma chave_unica → não entra de novo (sem duplicar)');
    $pegas = fila_reivindicar(10, ['teste_ok']);
    igual(count($pegas), 1, 'primeira execução pega a tarefa');
    igual(count(fila_reivindicar(10, ['teste_ok'])), 0, 'execução seguinte não pega a mesma tarefa');
    // Corrida real: as duas execuções leram a tarefa como pendente ao mesmo tempo.
    $idCorrida = fila_agendar($empA, 'teste_corrida', []);
    ok(fila_tomar($idCorrida, 'execucao_A_00000'), 'corrida: execução A toma a tarefa');
    ok(!fila_tomar($idCorrida, 'execucao_B_00000'), 'corrida: execução B, que leu a mesma tarefa, NÃO a toma');
    igual(db()->query("SELECT CONCAT(travada_por, '/', tentativas) FROM automacao_fila WHERE id = $idCorrida")->fetchColumn(), 'execucao_A_00000/1', 'tarefa fica com A e conta uma tentativa só');
    fila_concluir($pegas[0], 'feito');
    igual(db()->query("SELECT status FROM automacao_fila WHERE id = $id1")->fetchColumn(), 'concluida', 'concluída');

    $idF = fila_agendar($empA, 'teste_falha', [], null, null, 'wa_contato', 999, 3);
    $r = fila_processar(['teste_falha' => fn () => ['status' => 'falha', 'mensagem' => 'Meta fora do ar']], 10);
    $l = db()->query("SELECT status, tentativas, executar_em > NOW() AS futura FROM automacao_fila WHERE id = $idF")->fetch();
    ok($l['status'] === 'pendente' && (int) $l['tentativas'] === 1 && (int) $l['futura'] === 1, 'falha temporária → volta para a fila com espera (tentativa 1)');
    db()->exec("UPDATE automacao_fila SET executar_em = NOW() WHERE id = $idF");
    fila_processar(['teste_falha' => function () { throw new RuntimeException('conexão caiu'); }], 10);
    db()->exec("UPDATE automacao_fila SET executar_em = NOW() WHERE id = $idF");
    fila_processar(['teste_falha' => fn () => ['status' => 'falha', 'mensagem' => 'de novo']], 10);
    igual(db()->query("SELECT status FROM automacao_fila WHERE id = $idF")->fetchColumn(), 'falhou', 'atingiu max_tentativas (3) → falhou, sem retentar para sempre');

    $idD = fila_agendar($empA, 'teste_def', []);
    fila_processar(['teste_def' => fn () => ['status' => 'falha_definitiva', 'mensagem' => 'Template rejeitado']], 10);
    igual(db()->query("SELECT status FROM automacao_fila WHERE id = $idD")->fetchColumn(), 'falhou', 'falha definitiva → falhou na primeira');

    $idA = fila_agendar($empA, 'teste_adiar', []);
    $amanha = new DateTimeImmutable('+1 day');
    fila_processar(['teste_adiar' => fn () => ['status' => 'adiar', 'quando' => $amanha, 'mensagem' => 'Fora do horário']], 10);
    $l = db()->query("SELECT status, tentativas FROM automacao_fila WHERE id = $idA")->fetch();
    ok($l['status'] === 'pendente' && (int) $l['tentativas'] === 0, 'adiar → continua pendente e não gasta tentativa');

    $idT = fila_agendar($empA, 'teste_trava', []);
    fila_reivindicar(10, ['teste_trava']);
    db()->exec("UPDATE automacao_fila SET travada_em = DATE_SUB(NOW(), INTERVAL 30 MINUTE) WHERE id = $idT");
    ok(fila_recuperar_travadas() >= 1 && db()->query("SELECT status FROM automacao_fila WHERE id = $idT")->fetchColumn() === 'pendente',
        'tarefa travada (PHP caiu no meio) → volta para a fila');

    $idC = fila_agendar($empA, 'teste_cancel', [], null, new DateTimeImmutable('+1 hour'), 'jornada_execucao', 4242);
    igual(fila_cancelar_referencia('jornada_execucao', 4242), 1, 'cancelar tarefas pendentes de uma execução');
    igual(db()->query("SELECT status FROM automacao_fila WHERE id = $idC")->fetchColumn(), 'cancelada', 'tarefa cancelada não roda mais');
    $semTratador = fila_processar([], 10);
    igual($semTratador['executadas'], 0, 'sem tipos registrados, o cron não mexe em nada');

    grupo('Contatos e isolamento entre empresas');
    $c1 = wa_contato_obter($empA, '(11) 98888-7777');
    $c2 = wa_contato_obter($empA, '+55 11 98888-7777');
    ok($c1 && $c2 && $c1['id'] === $c2['id'], 'mesmo número em formatos diferentes → um único contato');
    $cB = wa_contato_obter($empB, '11988887777');
    ok($cB && $cB['id'] !== $c1['id'], 'mesmo número em OUTRA empresa → contato separado');
    igual(wa_contato($empB, (int) $c1['id']), null, 'empresa B não enxerga o contato da empresa A');
    igual(wa_contato_obter($empA, '123'), null, 'telefone inválido não vira contato');

    grupo('Consentimento (histórico LGPD)');
    $cid = (int) $c1['id'];
    ok(!wa_consentimento_atual($empA, $cid, 'atendimento')['concedido'], 'sem registro → NÃO há consentimento (não é presumido)');
    wa_consentimento_registrar($empA, $cid, 'atendimento', 'concedido', 'formulario_meta', 'Formulário 123: "Aceito contato pelo WhatsApp"');
    ok(wa_consentimento_atual($empA, $cid, 'atendimento')['concedido'], 'atendimento concedido');
    ok(!wa_consentimento_atual($empA, $cid, 'marketing')['concedido'], 'marketing continua NÃO concedido');
    igual(wa_consentimento_atual($empA, $cid, 'atendimento')['registro']['origem'], 'formulario_meta', 'origem da autorização registrada');
    ok(!wa_consentimento_atual($empB, $cid, 'atendimento')['concedido'], 'outra empresa não herda o consentimento');
    lanca(fn () => wa_consentimento_registrar($empB, $cid, 'marketing', 'concedido', 'manual'), 'registrar consentimento em contato de outra empresa → recusado');
    lanca(fn () => wa_consentimento_registrar($empA, $cid, 'qualquer', 'concedido', 'manual'), 'finalidade inválida → recusada');

    grupo('Decisão de envio com o banco');
    $sem = wa_verificar_envio($empA, $cid, ['tipo' => 'template', 'automatica' => true]);
    igual($sem['codigo'], 'conta_inativa', 'empresa sem WhatsApp conectado → nada sai');
    db()->prepare("INSERT INTO whatsapp_contas (empresa_id, phone_number_id, status, modo_teste) VALUES (:e, :p, 'ativa', 1)")
        ->execute(['e' => $empA, 'p' => 'teste-' . bin2hex(random_bytes(5))]);
    $contaA = wa_conta_da_empresa($empA);
    db()->prepare("INSERT INTO whatsapp_templates (empresa_id, conta_id, nome, categoria, status, finalidade, corpo, variaveis)
                   VALUES (:e, :c, 'boas_vindas_teste', 'utility', 'aprovado', 'boas_vindas', 'Olá, {{1}}!', '{\"1\":\"lead.primeiro_nome\"}')")
        ->execute(['e' => $empA, 'c' => $contaA['id']]);
    $tpl = db()->query("SELECT * FROM whatsapp_templates WHERE empresa_id = $empA")->fetch();
    igual(wa_verificar_envio($empA, $cid, ['tipo' => 'template', 'automatica' => true, 'template' => $tpl, 'ignorar_horario' => true])['codigo'], 'ok',
        'conta ativa + template aprovado + consentimento → pode');
    igual(wa_verificar_envio($empB, $cid, ['tipo' => 'template', 'template' => $tpl])['codigo'], 'contato_invalido', 'empresa B não envia para contato da A');
    igual(wa_verificar_envio($empA, $cid, ['tipo' => 'livre', 'automatica' => false])['codigo'], 'janela_fechada', 'mensagem livre sem o cliente ter escrito → exige template');

    grupo('Descadastramento');
    $cv = db()->prepare("INSERT INTO whatsapp_conversas (empresa_id, conta_id, contato_id) VALUES (:e, :c, :ct)");
    $cv->execute(['e' => $empA, 'c' => $contaA['id'], 'ct' => $cid]);
    $conversaId = (int) db()->lastInsertId();
    db()->prepare("INSERT INTO whatsapp_mensagens (empresa_id, conversa_id, direcao, tipo, status, conteudo) VALUES (:e, :cv, 'saida', 'template', 'na_fila', 'follow-up')")
        ->execute(['e' => $empA, 'cv' => $conversaId]);
    $tFila = fila_agendar($empA, 'wa_enviar', [], null, new DateTimeImmutable('+2 hours'), 'wa_contato', $cid);
    wa_descadastrar($empA, $cid, 'Cliente respondeu "PARAR"');
    $ct = wa_contato($empA, $cid);
    ok($ct['opt_out_em'] !== null, 'contato marcado como descadastrado');
    ok(!wa_consentimento_atual($empA, $cid, 'atendimento')['concedido'] && !wa_consentimento_atual($empA, $cid, 'marketing')['concedido'], 'as duas autorizações revogadas');
    igual((int) db()->query("SELECT COUNT(*) FROM whatsapp_consentimentos WHERE contato_id = $cid")->fetchColumn(), 3, 'histórico preservado (concedido + 2 revogações)');
    igual(db()->query("SELECT status FROM whatsapp_mensagens WHERE conversa_id = $conversaId")->fetchColumn(), 'cancelada', 'mensagem que estava na fila foi cancelada');
    igual(db()->query("SELECT status FROM automacao_fila WHERE id = $tFila")->fetchColumn(), 'cancelada', 'tarefa de envio pendente foi cancelada');
    igual(wa_verificar_envio($empA, $cid, ['tipo' => 'template', 'automatica' => true, 'template' => $tpl, 'ignorar_horario' => true])['codigo'], 'descadastrado',
        'depois do descadastro, nenhuma automação sai');

    grupo('Deduplicação de mensagens e webhooks');
    $ins = db()->prepare("INSERT IGNORE INTO whatsapp_mensagens (empresa_id, conversa_id, direcao, tipo, status, wamid) VALUES (:e, :cv, 'entrada', 'texto', 'recebida', 'wamid.TESTE1')");
    $ins->execute(['e' => $empA, 'cv' => $conversaId]);
    $ins->execute(['e' => $empA, 'cv' => $conversaId]);
    igual((int) db()->query("SELECT COUNT(*) FROM whatsapp_mensagens WHERE wamid = 'wamid.TESTE1'")->fetchColumn(), 1, 'mesmo wamid recebido duas vezes → gravado uma vez');
    $ev = db()->prepare("INSERT IGNORE INTO whatsapp_webhook_eventos (empresa_id, tipo, chave_unica, payload) VALUES (:e, 'status', 'wamid.TESTE1:delivered', '{}')");
    $ev->execute(['e' => $empA]);
    $ev->execute(['e' => $empA]);
    igual((int) db()->query("SELECT COUNT(*) FROM whatsapp_webhook_eventos WHERE chave_unica = 'wamid.TESTE1:delivered'")->fetchColumn(), 1, 'mesmo aviso de status repetido → um evento');
    db()->exec("DELETE FROM whatsapp_webhook_eventos WHERE chave_unica = 'wamid.TESTE1:delivered'");

    grupo('Jornada não recomeça na transferência');
    db()->prepare("INSERT INTO leads (empresa_id, nome, telefone) VALUES (:e, 'Lead Fictício', '11988887777')")->execute(['e' => $empA]);
    $leadId = (int) db()->lastInsertId();
    db()->prepare("INSERT INTO jornadas (empresa_id, nome, gatilho, status) VALUES (:e, 'Novo lead (teste)', 'novo_lead', 'ativa')")->execute(['e' => $empA]);
    $jornadaId = (int) db()->lastInsertId();
    $exec = db()->prepare('INSERT IGNORE INTO jornada_execucoes (empresa_id, jornada_id, lead_id) VALUES (:e, :j, :l)');
    $exec->execute(['e' => $empA, 'j' => $jornadaId, 'l' => $leadId]);
    $exec->execute(['e' => $empA, 'j' => $jornadaId, 'l' => $leadId]);
    igual((int) db()->query("SELECT COUNT(*) FROM jornada_execucoes WHERE lead_id = $leadId")->fetchColumn(), 1, 'segunda entrada do mesmo lead na mesma jornada é ignorada');

    grupo('Roleta: horário vindo do banco e sincronização com a tela atual');
    db()->prepare("INSERT INTO roletas (empresa_id, nome, horario_inicio, horario_fim, dias_semana) VALUES (:e, 'Roleta teste', '09:30:00', '21:00:00', '1,2,3,4,5')")
        ->execute(['e' => $empA]);
    $rid = (int) db()->lastInsertId();
    roleta_horarios_sincronizar_simples($rid, '09:30:00', '21:00:00', '1,2,3,4,5');
    igual(count(roleta_horarios_linhas($rid)), 5, 'tela atual (simples) gera 5 dias');
    $rol = roleta_carregar($empA, $rid);
    ok(roleta_no_horario($rol, new DateTime('2026-11-16 10:00:00', $tz))[0], 'roleta_no_horario (usada pela distribuição) → segunda 10:00 aberta');
    $fora = roleta_no_horario($rol, new DateTime('2026-11-16 23:40:00', $tz));
    ok(!$fora[0] && str_contains($fora[1], 'amanhã às 09:30'), 'segunda 23:40 → fechada, "abre amanhã às 09:30"');
    db()->prepare("INSERT INTO roleta_excecoes (empresa_id, roleta_id, data, tipo, descricao) VALUES (:e, NULL, '2026-11-17', 'fechado', 'Feriado teste')")->execute(['e' => $empA]);
    ok(!roleta_no_horario($rol, new DateTime('2026-11-17 10:00:00', $tz))[0], 'feriado cadastrado no banco fecha a roleta');
    db()->exec("UPDATE roleta_horarios SET pausa_inicio = '12:00:00', pausa_fim = '13:00:00' WHERE roleta_id = $rid AND dia_semana = 1");
    ok(!roleta_horarios_sincronizar_simples($rid, '08:00:00', '18:00:00', '1,2,3'), 'grade personalizada (com pausa) NÃO é sobrescrita pela tela simples');
    igual(count(roleta_horarios_linhas($rid)), 5, 'grade personalizada intacta');
    ok(str_contains(roleta_horario_texto($rol), 'pausa 12:00–13:00'), 'texto do horário mostra a grade por dia');
    $sessao = ['id' => 999999, 'roleta_id' => $rid, 'empresa_id' => $empA] + $rol;
    ok(roleta_no_horario($sessao, new DateTime('2026-11-16 10:00:00', $tz))[0], 'linha de sessão (id = sessão) usa o roleta_id certo');
} finally {
    apagar_empresa_teste($empA);
    apagar_empresa_teste($empB);
    $sobra = (int) db()->query("SELECT COUNT(*) FROM empresas WHERE nome LIKE 'Teste automatizado%'")->fetchColumn();
    grupo('Limpeza');
    igual($sobra, 0, 'nenhum dado de teste ficou no banco');
}
resumo_e_sair();
