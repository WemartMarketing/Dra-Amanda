<?php
/**
 * Testes da FASE 7 — editor de jornadas (validação, salvar, duplicar,
 * status, simulação), novos blocos e gatilhos, campanhas de remarketing
 * (público, autorização, prévia, confirmação, lotes, pausa, cancelamento,
 * resultados) e relatórios de automação + exportação Excel.
 * Empresas fictícias em MODO DE TESTE: nada sai para a Meta.
 *
 *   php tests/whatsapp_fase7_test.php
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../includes/automacao.php';
require_once __DIR__ . '/../includes/jornadas_editor.php';
require_once __DIR__ . '/../includes/campanhas.php';
require_once __DIR__ . '/../includes/relatorio_automacao.php';
require_once __DIR__ . '/../includes/exportacao.php';
require_once __DIR__ . '/../includes/transferencia.php';
require_once __DIR__ . '/../includes/funcoes.php';
require_once __DIR__ . '/../includes/funil.php';

function v(string $sql)
{
    return db()->query($sql)->fetchColumn();
}
function rodar(): void
{
    for ($i = 0; $i < 8; $i++) {
        if (!automacao_executar(200)['executadas']) {
            break;
        }
    }
}
function avancar(int $execId): void
{
    db()->exec("UPDATE automacao_fila SET executar_em = NOW() WHERE referencia_tipo = 'jornada_execucao' AND referencia_id = $execId AND status = 'pendente'");
    rodar();
}

$emp = criar_empresa_teste('Fase7');
$emp2 = criar_empresa_teste('Fase7 Outra');
try {
    $pdo = db();
    $f = funcoes_seed_padrao($emp);
    funil_seed_padrao($emp);
    funcoes_seed_padrao($emp2);
    funil_seed_padrao($emp2);
    $etapa = funil_primeira_etapa($emp);
    $etapaAtend = funil_etapa_por_categoria($emp, 'em_atendimento');
    $etapaVisita = funil_etapa_por_categoria($emp, 'visita_agendada');
    $etapaPerdido = funil_etapa_por_categoria($emp, 'perdido');
    $novoUsuario = function (string $nome, string $funcao = 'corretor', string $escopo = 'propria_carteira') use ($pdo, $emp, $f) {
        $pdo->prepare('INSERT INTO usuarios (empresa_id, funcao_id, nome, email, senha_hash, cargo) VALUES (:e, :f, :n, :m, :h, :c)')
            ->execute(['e' => $emp, 'f' => $f[$funcao], 'n' => $nome, 'm' => bin2hex(random_bytes(5)) . '@teste.invalid', 'h' => 'x', 'c' => $funcao]);
        return ['id' => (int) $pdo->lastInsertId(), 'nome' => $nome, 'empresa_id' => $emp, 'empresa_ativa_id' => $emp, 'eh_superadmin' => false, 'escopo' => $escopo,
                'permissoes' => ['visualizar_leads', 'editar_leads', 'gerenciar_jornadas', 'gerenciar_campanhas_wa', 'visualizar_indicadores']];
    };
    $master = $novoUsuario('Master Teste', 'master_empresa', 'toda_empresa');
    $c1 = $novoUsuario('Corretora Um');
    $c2 = $novoUsuario('Corretor Dois');
    $pdo->prepare("INSERT INTO empreendimentos (empresa_id, nome) VALUES (:e, 'Residencial Sete')")->execute(['e' => $emp]);
    $empr = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO whatsapp_contas (empresa_id, nome, phone_number_id, status, modo_teste) VALUES (:e, 'Teste', :p, 'ativa', 1)")
        ->execute(['e' => $emp, 'p' => (string) random_int(10 ** 9, 10 ** 10)]);
    $conta = wa_conta_da_empresa($emp);
    $pdo->prepare("INSERT INTO whatsapp_config (empresa_id, envio_inicio, envio_fim, max_automaticas_24h, max_marketing_7d) VALUES (:e, '00:00:00', '23:59:00', 10, 3)")->execute(['e' => $emp]);
    $tpl = function (string $nome, string $cat, string $status = 'aprovado', ?int $empresa = null) use ($pdo, $emp, $conta) {
        $e = $empresa ?? $emp;
        $cid = $e === $emp ? $conta['id'] : null;
        if ($cid === null) {
            $pdo->prepare("INSERT INTO whatsapp_contas (empresa_id, nome, phone_number_id, status, modo_teste) VALUES (:e, 'Outra', :p, 'ativa', 1)")->execute(['e' => $e, 'p' => (string) random_int(10 ** 9, 10 ** 10)]);
            $cid = (int) $pdo->lastInsertId();
        }
        $pdo->prepare('INSERT INTO whatsapp_templates (empresa_id, conta_id, nome, categoria, status, corpo, variaveis) VALUES (:e, :c, :n, :cat, :s, :b, :v)')
            ->execute(['e' => $e, 'c' => $cid, 'n' => $nome, 'cat' => $cat, 's' => $status, 'b' => 'Oi, {{1}}! Novidades no Residencial Sete.', 'v' => json_encode(['1' => 'lead.primeiro_nome'])]);
        return (int) $pdo->lastInsertId();
    };
    $tUtil = $tpl('lembrete_util', 'utility');
    $tMkt = $tpl('oferta_mkt', 'marketing');
    $tPend = $tpl('em_analise', 'marketing', 'pendente');
    $tOutra = $tpl('da_outra', 'utility', 'aprovado', $emp2);

    $n = 0;
    $novoLead = function (?int $resp, array $consent = ['atendimento'], string $origem = 'meta_ads', ?array $et = null) use ($pdo, $emp, $etapa, $empr, &$n) {
        $n++;
        $et = $et ?? $etapa;
        $tel = '(11) 9' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $pdo->prepare("INSERT INTO leads (empresa_id, responsavel_id, empreendimento_id, nome, telefone, origem, etapa_id, etapa, atribuido_em, ultimo_feedback_em)
                       VALUES (:e, :r, :emp, :n, :t, :o, :et, :c, NOW(), NOW())")
            ->execute(['e' => $emp, 'r' => $resp, 'emp' => $empr, 'n' => "Cliente S$n Teste", 't' => $tel, 'o' => $origem, 'et' => $et['id'], 'c' => $et['categoria']]);
        $id = (int) $pdo->lastInsertId();
        $ct = wa_contato_obter($emp, $tel, $id);
        foreach ($consent as $fin) {
            wa_consentimento_registrar($emp, (int) $ct['id'], $fin, 'concedido', 'formulario_meta', 'teste');
        }
        return [$id, (int) $ct['id'], $tel];
    };
    $passo = fn (string $c, string $t, array $cfg = [], ?string $p = null, ?string $s = null, ?string $ti = null) => ['chave' => $c, 'tipo' => $t, 'titulo' => $ti ?? $c, 'config' => $cfg, 'proximo_chave' => $p, 'senao_chave' => $s];
    $defBase = fn (array $passos, array $extra = []) => $extra + ['nome' => 'Jornada de teste', 'descricao' => '', 'gatilho' => 'novo_lead',
        'gatilho_config' => ['origens' => ['meta_ads'], 'parar_quando_responder' => true], 'empreendimento_id' => null, 'max_mensagens' => 2, 'passos' => $passos];

    // ================================================================ editor
    grupo('Editor: validação da jornada');
    $val = fn (array $d) => jornada_validar_definicao($emp, $d);
    $okDef = $defBase([$passo('msg', 'enviar_template', ['template_id' => $tUtil]), $passo('espera', 'esperar', ['horas' => 24]), $passo('fim', 'encerrar')]);
    $r = $val($okDef);
    ok($r['ok'] && $r['def']['passos'][0]['proximo_chave'] === 'espera' && $r['def']['passos'][1]['proximo_chave'] === 'fim', 'jornada simples válida; "seguir para o bloco de baixo" vira ligação explícita');
    ok(!$val(['nome' => 'x'] + $okDef)['ok'], 'nome curto → recusado');
    ok(!$val(['gatilho' => 'qualquer'] + $okDef)['ok'], 'gatilho inválido → recusado');
    ok(!$val(['gatilho_config' => ['origens' => ['importacao']]] + $okDef)['ok'], 'lista importada como origem → recusado (use campanhas)');
    ok(!$val(['gatilho' => 'mudanca_etapa', 'gatilho_config' => []] + $okDef)['ok'], '"muda de etapa" sem etapa → recusado');
    $ciclo = $defBase([$passo('a', 'esperar', ['horas' => 1], 'b'), $passo('b', 'condicao', ['se' => ['cliente_respondeu']], 'fim', 'a'), $passo('fim', 'encerrar')]);
    $r = $val($ciclo);
    ok(!$r['ok'] && str_contains(implode(' ', $r['erros']), 'voltar'), 'fluxo que volta para um bloco anterior → recusado (sem laços)');
    ok(!$val($defBase([$passo('c', 'condicao', ['se' => ['cliente_respondeu']]), $passo('fim', 'encerrar')]))['ok'], 'condição sem caminho do "não" → recusada');
    ok(!$val($defBase([$passo('c', 'condicao', ['se' => []], 'fim', 'fim'), $passo('fim', 'encerrar')]))['ok'], 'condição sem regra → recusada');
    ok(!$val($defBase([$passo('m', 'enviar_template', ['template_id' => $tPend]), $passo('fim', 'encerrar')]))['ok'], 'template não aprovado → recusado');
    ok(!$val($defBase([$passo('m', 'enviar_template', ['template_id' => $tOutra]), $passo('fim', 'encerrar')]))['ok'], 'template de OUTRA empresa → recusado');
    ok(!$val($defBase([$passo('e', 'esperar', ['horas' => 9999]), $passo('fim', 'encerrar')]))['ok'], 'espera acima de 720 h → recusada');
    ok(!$val($defBase([$passo('et', 'atualizar_etapa', ['etapa_id' => $etapaPerdido['id']]), $passo('fim', 'encerrar')]))['ok'], 'automação não pode encerrar o lead (etapa "perdido") → recusado');
    ok(!$val($defBase([$passo('a', 'esperar', ['horas' => 1], 'zz'), $passo('fim', 'encerrar')]))['ok'], 'caminho para bloco inexistente → recusado');
    ok(!$val($defBase([$passo('A-B', 'esperar', ['horas' => 1])]))['ok'], 'identificador de bloco inválido → recusado');
    $r = $val($defBase([$passo('m1', 'enviar_template', ['template_id' => $tUtil]), $passo('m2', 'enviar_template', ['template_id' => $tUtil]), $passo('m3', 'enviar_template', ['template_id' => $tUtil]), $passo('fim', 'encerrar')]));
    ok($r['ok'] && str_contains(implode(' ', $r['avisos']), 'limite'), '3 envios com limite 2 → aceito, com aviso');
    $r = $val($defBase([$passo('a', 'esperar', ['horas' => 1], 'fim'), $passo('solto', 'criar_tarefa', ['titulo' => 'Ligar']), $passo('fim', 'encerrar')]));
    ok($r['ok'] && str_contains(implode(' ', $r['avisos']), 'nenhum caminho'), 'bloco que ninguém alcança → aviso');

    grupo('Editor: salvar, duplicar, ligar, pausar, desativar');
    $s = jornada_salvar_definicao($emp, $master['id'], null, $okDef);
    ok($s['ok'] && v("SELECT status FROM jornadas WHERE id = {$s['id']}") === 'rascunho', 'nova jornada salva DESLIGADA');
    $jid = $s['id'];
    igual((int) v("SELECT COUNT(*) FROM jornada_passos WHERE jornada_id = $jid"), 3, '3 blocos gravados');
    ok(!jornada_salvar_definicao($emp, $master['id'], null, $okDef)['ok'], 'nome repetido → recusado');
    ok(!jornada_salvar_definicao($emp2, $master['id'], $jid, $okDef)['ok'], 'outra empresa não edita esta jornada');
    $d = jornada_duplicar($emp, $master['id'], $jid);
    ok($d['ok'] && v("SELECT nome FROM jornadas WHERE id = {$d['id']}") === 'Jornada de teste (cópia)' && (int) v("SELECT COUNT(*) FROM jornada_passos WHERE jornada_id = {$d['id']}") === 3, 'duplicar → cópia desligada com os mesmos blocos');
    ok(jornada_mudar_status($emp, $master['id'], $jid, 'ativa')['ok'], 'ligar a jornada');
    ok(!jornada_salvar_definicao($emp, $master['id'], $jid, $okDef)['ok'], 'jornada LIGADA não tem blocos alterados (pause antes)');
    [$L1] = $novoLead($c1['id']);
    $x1 = jornada_disparar('novo_lead', $emp, $L1, ['origem' => 'meta_ads']);
    $x1 = (int) v("SELECT id FROM jornada_execucoes WHERE jornada_id = $jid AND lead_id = $L1");
    rodar();
    igual(v("SELECT passo_chave FROM jornada_execucoes WHERE id = $x1"), 'fim', 'lead esperando no bloco seguinte à espera');
    jornada_mudar_status($emp, $master['id'], $jid, 'pausada');
    $sem = $okDef;
    $sem['passos'] = [$passo('msg', 'enviar_template', ['template_id' => $tUtil]), $passo('fim2', 'encerrar')];
    $s2 = jornada_salvar_definicao($emp, $master['id'], $jid, $sem);
    ok($s2['ok'] && $s2['retirados'] === 1 && v("SELECT status FROM jornada_execucoes WHERE id = $x1") === 'cancelada', 'bloco removido com lead parado nele → o lead sai da jornada (não fica perdido)');
    igual((int) v("SELECT versao FROM jornadas WHERE id = $jid"), 2, 'versão da jornada aumenta a cada alteração');
    $bv = jornada_garantir_boas_vindas($emp);
    $defBv = jornada_definicao($emp, $bv);
    $defBv['nome'] = 'Renomeada';
    $defBv['passos'] = array_map(fn ($p) => ['config' => (array) $p['config']] + $p, $defBv['passos']);
    $defBv['gatilho_config'] = (array) $defBv['gatilho_config'];
    jornada_salvar_definicao($emp, $master['id'], $bv, $defBv);
    igual(v("SELECT nome FROM jornadas WHERE id = $bv"), JORNADA_BOAS_VINDAS, 'o nome das jornadas padrão não muda (são achadas pelo nome)');
    jornada_mudar_status($emp, $master['id'], $jid, 'ativa');
    [$L2] = $novoLead($c1['id']);
    jornada_disparar('novo_lead', $emp, $L2, ['origem' => 'meta_ads']);
    $x2 = (int) v("SELECT id FROM jornada_execucoes WHERE jornada_id = $jid AND lead_id = $L2");
    ok(jornada_mudar_status($emp, $master['id'], $jid, 'desativada')['ok'] && v("SELECT status FROM jornada_execucoes WHERE id = $x2") === 'cancelada', 'desativar → leads saem e o agendado é cancelado');
    igual((int) v("SELECT COUNT(*) FROM automacao_fila WHERE referencia_tipo = 'jornada_execucao' AND referencia_id = $x2 AND status = 'pendente'"), 0, 'nada pendente na fila');

    grupo('Simulação com dados fictícios (sem banco, sem envio)');
    $tpls = jornada_templates_simulacao($emp);
    $fluxo = $defBase([
        $passo('m1', 'enviar_template', ['template_id' => $tUtil], null, null, 'Lembrete'),
        $passo('e1', 'esperar', ['horas_uteis' => 2]),
        $passo('c1', 'condicao', ['se' => ['cliente_respondeu']], 'fim', 'p1'),
        $passo('p1', 'confirmar_atendimento', ['so_se_tentativa' => true]),
        $passo('m2', 'enviar_template', ['template_id' => $tMkt], null, null, 'Oferta'),
        $passo('fim', 'encerrar'),
    ]);
    $antes = (int) v('SELECT COUNT(*) FROM whatsapp_mensagens') + (int) v('SELECT COUNT(*) FROM jornada_execucoes') + (int) v('SELECT COUNT(*) FROM corretor_pendencias');
    $sim = jornada_simular($val($fluxo)['def'], ['consentimento' => 'marketing', 'cliente_responde' => 'nunca'], $tpls);
    igual($sim['mensagens'], 2, 'cliente não responde, autorizou ofertas → 2 mensagens');
    ok((bool) array_filter($sim['linhas'], fn ($l) => str_contains($l['texto'], 'respondeu à sua tentativa')), 'pergunta ao corretor aparece na linha do tempo');
    ok((bool) array_filter($sim['linhas'], fn ($l) => $l['quando'] === '+2 h úteis'), 'tempo acumulado mostrado ("+2 h úteis")');
    $sim = jornada_simular($val($fluxo)['def'], ['consentimento' => 'atendimento'], $tpls);
    ok($sim['mensagens'] === 1 && (bool) array_filter($sim['linhas'], fn ($l) => $l['classe'] === 'bloqueado' && str_contains($l['texto'], 'ofertas')), 'sem autorização de marketing → a oferta é bloqueada');
    $sim = jornada_simular($val($fluxo)['def'], ['consentimento' => 'nenhum'], $tpls);
    igual($sim['mensagens'], 0, 'sem nenhuma autorização → nada é enviado');
    $sim = jornada_simular($val($fluxo)['def'], ['consentimento' => 'marketing', 'cliente_responde' => 'apos_primeira_mensagem'], $tpls);
    ok($sim['mensagens'] === 1 && str_contains($sim['resumo'], 'respondeu'), 'cliente responde → a jornada para (atendimento humano assume)');
    $sim = jornada_simular($val($fluxo)['def'], ['consentimento' => 'marketing', 'corretor_confirma' => true], $tpls);
    ok($sim['mensagens'] === 1 && str_contains($sim['resumo'], 'Corretor confirmou'), 'corretor confirma "sim" → para');
    $sim = jornada_simular($val($defBase([$passo('a', 'enviar_template', ['template_id' => $tUtil]), $passo('b', 'enviar_template', ['template_id' => $tUtil]), $passo('c', 'enviar_template', ['template_id' => $tUtil]), $passo('fim', 'encerrar')]))['def'], [], $tpls);
    ok($sim['mensagens'] === 2 && str_contains($sim['resumo'], 'Limite'), 'limite de mensagens respeitado na simulação');
    $depois = (int) v('SELECT COUNT(*) FROM whatsapp_mensagens') + (int) v('SELECT COUNT(*) FROM jornada_execucoes') + (int) v('SELECT COUNT(*) FROM corretor_pendencias');
    igual($depois, $antes, 'simular não grava nada no banco');

    grupo('Novos blocos: mudar etapa e encaminhar para a roleta');
    $j3 = jornada_salvar_definicao($emp, $master['id'], null, $defBase([
        $passo('etp', 'atualizar_etapa', ['etapa_id' => $etapaAtend['id']]), $passo('rol', 'encaminhar_fila'), $passo('fim', 'encerrar'),
    ], ['nome' => 'Blocos novos']));
    jornada_mudar_status($emp, $master['id'], $j3['id'], 'ativa');
    [$L3] = $novoLead(null);
    $pdo->exec("UPDATE leads SET ultimo_feedback_em = '2020-01-01 00:00:00' WHERE id = $L3");
    jornada_disparar('novo_lead', $emp, $L3, ['origem' => 'meta_ads']);
    rodar();
    $l3 = $pdo->query("SELECT etapa_id, ultimo_feedback_em FROM leads WHERE id = $L3")->fetch();
    ok((int) $l3['etapa_id'] === (int) $etapaAtend['id'], 'bloco "Mudar etapa" moveu o lead no Kanban');
    igual($l3['ultimo_feedback_em'], '2020-01-01 00:00:00', 'movimento feito pela automação NÃO conta como atendimento do corretor');
    ok((int) v("SELECT COUNT(*) FROM roleta_fila WHERE lead_id = $L3 AND resolvido_em IS NULL") === 1, 'bloco "Encaminhar para a roleta": sem roleta/corretor → lead na fila de espera');
    jornada_mudar_status($emp, $master['id'], $j3['id'], 'desativada');

    grupo('Novos gatilhos: muda de etapa / transferido (uma vez por lead)');
    $j4 = jornada_salvar_definicao($emp, $master['id'], null, $defBase([$passo('t', 'criar_tarefa', ['titulo' => 'Confirmar visita com o cliente']), $passo('fim', 'encerrar')],
        ['nome' => 'Visita agendada', 'gatilho' => 'mudanca_etapa', 'gatilho_config' => ['etapa_id' => $etapaVisita['id']]]));
    ok($j4['ok'], 'jornada com gatilho "muda de etapa" salva');
    jornada_mudar_status($emp, $master['id'], $j4['id'], 'ativa');
    [$L4] = $novoLead($c1['id']);
    $lead4 = fn () => $pdo->query("SELECT * FROM leads WHERE id = $L4")->fetch();
    lead_mudar_etapa($lead4(), (int) $etapaAtend['id'], $c1);
    igual((int) v("SELECT COUNT(*) FROM jornada_execucoes WHERE jornada_id = {$j4['id']} AND lead_id = $L4"), 0, 'outra etapa → não dispara');
    lead_mudar_etapa($lead4(), (int) $etapaVisita['id'], $c1);
    rodar();
    ok((int) v("SELECT COUNT(*) FROM corretor_pendencias WHERE lead_id = $L4 AND tipo = 'tarefa_jornada'") === 1, 'lead foi para "Visita agendada" → tarefa para o corretor');
    lead_mudar_etapa($lead4(), (int) $etapaAtend['id'], $c1);
    lead_mudar_etapa($lead4(), (int) $etapaVisita['id'], $c1);
    igual((int) v("SELECT COUNT(*) FROM jornada_execucoes WHERE jornada_id = {$j4['id']} AND lead_id = $L4"), 1, 'voltar para a etapa não repete a jornada');
    $j5 = jornada_salvar_definicao($emp, $master['id'], null, $defBase([$passo('t', 'notificar_corretor', ['titulo' => 'Lead novo para você: veja o histórico']), $passo('fim', 'encerrar')],
        ['nome' => 'Lead transferido', 'gatilho' => 'lead_transferido', 'gatilho_config' => []]));
    jornada_mudar_status($emp, $master['id'], $j5['id'], 'ativa');
    lead_transferir($lead4(), $c2['id'], 'administrativa', $master);
    rodar();
    $p5 = array_values(array_filter(pendencias_do_usuario($c2), fn ($p) => (int) $p['lead_id'] === $L4 && str_contains($p['titulo'], 'Lead novo para você')));
    ok(count($p5) === 1, 'lead transferido → o NOVO corretor recebe o aviso');

    // ================================================================ campanhas
    grupo('Campanhas: quem recebe (só quem autorizou ofertas)');
    $pdo->exec("UPDATE jornadas SET status = 'desativada' WHERE empresa_id = $emp");
    [$A1, $cA1] = $novoLead($c1['id'], ['atendimento', 'marketing']);
    [$A2, $cA2] = $novoLead($c1['id'], ['atendimento', 'marketing']);
    [$A3, $cA3] = $novoLead($c2['id'], ['atendimento', 'marketing']);
    [$B1] = $novoLead($c1['id'], ['atendimento']);                          // só atendimento
    [$B2, $cB2] = $novoLead($c1['id'], ['atendimento', 'marketing']);
    wa_descadastrar($emp, $cB2, 'Não quero mais');                          // descadastrado
    [$B3] = $novoLead($c1['id'], ['marketing'], 'meta_ads', $etapaPerdido); // lead perdido
    $tA1 = v("SELECT telefone FROM leads WHERE id = $A1");
    $pdo->prepare("INSERT INTO leads (empresa_id, responsavel_id, nome, telefone, etapa) VALUES (:e, :r, 'Cliente Duplicado Teste', :t, 'novo_lead')")->execute(['e' => $emp, 'r' => $c1['id'], 't' => $tA1]);
    $pv = campanha_previa($master, ['empreendimento_ids' => [$empr], 'excluir_campanha_dias' => 7], $tMkt);
    $exc = array_column($pv['excluidos'], 'quantidade', 'motivo');
    $mot = campanha_motivos_exclusao();
    ok($pv['ok'] && $pv['elegiveis'] >= 3, 'prévia: os que autorizaram entram (' . $pv['elegiveis'] . ')');
    ok(($exc[$mot['sem_autorizacao']] ?? 0) >= 1, 'quem só autorizou atendimento fica de fora (marketing não é presumido)');
    ok(($exc[$mot['descadastrado']] ?? 0) >= 1, 'descadastrado fica de fora');
    ok(($exc[$mot['lead_encerrado']] ?? 0) >= 1, 'lead perdido fica de fora');
    $pvTodos = campanha_previa($master, [], $tMkt);
    ok((array_column($pvTodos['excluidos'], 'quantidade', 'motivo')[$mot['duplicado']] ?? 0) >= 1, 'mesmo telefone em dois leads → só uma mensagem (duplicado excluído)');
    ok(!campanha_previa($master, [], $tUtil)['ok'], 'template de utilidade não pode ser usado em campanha (só marketing)');
    ok(!campanha_previa($master, [], $tPend)['ok'], 'template não aprovado → recusado');
    $pvC1 = campanha_previa($c1, [], $tMkt);
    $pvC2 = campanha_previa(['escopo' => 'propria_carteira'] + $c2, [], $tMkt);
    ok($pvC2['elegiveis'] === 1 && $pvC1['elegiveis'] >= 2, 'o público respeita a carteira de quem cria (corretor só vê os seus)');
    $pvCor = campanha_previa($master, ['corretor_ids' => [$c2['id']]], $tMkt);
    igual($pvCor['elegiveis'], 1, 'filtro por corretor');
    ok($pv['custo_estimado'] === null || $pv['custo_estimado'] >= 0, 'custo estimado calculado (ou "sem tarifa")');
    ok(count($pv['limites']) >= 3, 'limites aplicáveis mostrados antes de criar');

    $csv = tempnam(sys_get_temp_dir(), 'camp');
    file_put_contents($csv, "nome;telefone\nA;" . $tA1 . "\nRepetido;" . $tA1 . "\nInvalido;123\nFora da base;(21) 99999-0000\n");
    [$tels, $est] = campanha_ler_lista($csv, 'csv');
    unlink($csv);
    ok(count($tels) === 2 && $est['repetidos'] === 1 && $est['invalidos'] === 1, 'planilha: telefones lidos, repetidos e inválidos identificados');
    $pvL = campanha_previa($master, ['lista' => $tels], $tMkt);
    ok($pvL['elegiveis'] === 1 && $pvL['lista_sem_lead'] === 1, 'planilha: só quem é lead com autorização recebe; número fora da base fica de fora');

    grupo('Campanhas: criar, confirmar, lotes, pausar, cancelar');
    ok(!campanha_criar($master, 'Teste', $tMkt, ['corretor_ids' => [999999]])['ok'], 'nenhum elegível → não cria');
    $cr = campanha_criar($master, 'Oferta de novembro', $tMkt, ['empreendimento_ids' => [$empr]]);
    ok($cr['ok'], 'campanha criada em rascunho');
    $cid = $cr['id'];
    $tot = (int) v("SELECT total_elegiveis FROM whatsapp_campanhas WHERE id = $cid");
    igual((int) v("SELECT COUNT(*) FROM whatsapp_campanha_destinatarios WHERE campanha_id = $cid"), $tot, 'destinatários congelados = elegíveis da prévia');
    campanhas_processar(10);
    igual((int) v("SELECT COUNT(*) FROM whatsapp_mensagens WHERE campanha_id = $cid"), 0, 'rascunho não envia nada');
    ok(!campanha_acao($master, $cid, 'confirmar', $tot + 1)['ok'], 'confirmação com número errado → recusada');
    ok(!campanha_acao(['empresa_ativa_id' => $emp2] + $master, $cid, 'confirmar', $tot)['ok'], 'outra empresa não confirma esta campanha');
    ok(campanha_acao($master, $cid, 'confirmar', $tot)['ok'] && v("SELECT status FROM whatsapp_campanhas WHERE id = $cid") === 'enviando', 'confirmada com o número certo → enviando');
    wa_descadastrar($emp, $cA3, 'Saiu depois de criada');
    $r = campanhas_processar(2);
    igual((int) v("SELECT COUNT(*) FROM whatsapp_mensagens WHERE campanha_id = $cid"), 2, 'lote de 2 por rodada (não dispara tudo de uma vez)');
    campanha_acao($master, $cid, 'pausar');
    campanhas_processar(2);
    igual((int) v("SELECT COUNT(*) FROM whatsapp_mensagens WHERE campanha_id = $cid"), 2, 'pausada → nenhum lote novo');
    rodar();
    igual((int) v("SELECT COUNT(*) FROM whatsapp_mensagens WHERE campanha_id = $cid AND status = 'na_fila'"), 2, 'pausada → o que estava na fila espera (não sai)');
    campanha_acao($master, $cid, 'retomar');
    rodar();
    igual((int) v("SELECT COUNT(*) FROM whatsapp_mensagens WHERE campanha_id = $cid AND status = 'simulada'"), 2, 'retomada → as da fila saem (simuladas no modo de teste)');
    for ($i = 0; $i < 5; $i++) {
        campanhas_processar(2);
        rodar();
    }
    igual(v("SELECT status FROM whatsapp_campanhas WHERE id = $cid"), 'concluida', 'todos processados → campanha concluída');
    igual(v("SELECT status FROM whatsapp_campanha_destinatarios WHERE campanha_id = $cid AND contato_id = $cA3"), 'ignorada', 'quem se descadastrou depois de criada NÃO recebe');
    $digitos = wa_normalizar_telefone((string) v("SELECT telefone FROM leads WHERE id = $A1"));
    wa_evento_mensagem($emp, (int) $conta['id'], ['from' => $digitos, 'id' => 'wamid.' . bin2hex(random_bytes(8)), 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => 'Quero saber mais!']], 'Cliente');
    $res = campanha_resultados($emp, $cid);
    ok($res['simuladas'] >= 2 && $res['respostas'] === 1 && $res['nao_enviadas'] >= 1, 'resultados: simuladas, respostas e não enviadas contadas');
    $cr2 = campanha_criar($master, 'Repetição', $tMkt, ['empreendimento_ids' => [$empr], 'excluir_campanha_dias' => 0]);
    $pv7 = campanha_previa($master, ['empreendimento_ids' => [$empr], 'excluir_campanha_dias' => 7], $tMkt);
    ok(($pv7['elegiveis'] ?? 99) === 0, 'quem recebeu campanha nos últimos 7 dias fica de fora da próxima');
    $cid2 = $cr2['id'];
    campanha_acao($master, $cid2, 'confirmar', (int) v("SELECT total_elegiveis FROM whatsapp_campanhas WHERE id = $cid2"));
    campanhas_processar(1);
    campanha_acao($master, $cid2, 'cancelar');
    ok((int) v("SELECT COUNT(*) FROM whatsapp_campanha_destinatarios WHERE campanha_id = $cid2 AND status = 'pendente'") === 0
       && (int) v("SELECT COUNT(*) FROM whatsapp_mensagens WHERE campanha_id = $cid2 AND status = 'na_fila'") === 0, 'cancelar → nada pendente e mensagens da fila canceladas');
    rodar();
    igual((int) v("SELECT COUNT(*) FROM whatsapp_mensagens WHERE campanha_id = $cid2 AND status IN ('simulada','enviada')"), 0, 'nenhuma mensagem da campanha cancelada saiu');
    ok(!campanha_acao($master, $cid2, 'retomar')['ok'], 'campanha cancelada não pode ser retomada');

    // ================================================================ relatórios
    grupo('Relatórios de automação e Excel');
    $fil = dash_filtros($master, ['periodo' => '30d']);
    $pdo->prepare("INSERT INTO agenda_compromissos (empresa_id, lead_id, usuario_id, tipo, titulo, inicio, status) VALUES (:e, :l, :u, 'visita', 'Visita', DATE_ADD(NOW(), INTERVAL 1 DAY), 'agendado')")
        ->execute(['e' => $emp, 'l' => $A1, 'u' => $c1['id']]);
    $rel = relatorio_automacao($master, $fil);
    $ind = $rel['indicadores'];
    ok($ind['leads'] >= 2 && $ind['responderam'] >= 1 && $ind['taxa_resposta'] > 0, 'leads com automação, respostas e taxa de resposta');
    ok($ind['visitas'] >= 1, 'visita agendada DEPOIS da automação contada');
    ok($ind['simuladas'] >= 2 && $ind['taxa_entrega'] === null, 'simuladas à parte: taxa de entrega só com mensagens reais');
    ok($ind['descadastros'] >= 2, 'pedidos de descadastro contados');
    ok(count($rel['por_campanha']) >= 2 && count($rel['por_empreendimento']) >= 1, 'quebras por campanha e por empreendimento');
    $relC2 = relatorio_automacao(['escopo' => 'propria_carteira'] + $c2, $fil);
    ok($relC2['indicadores']['leads'] < $ind['leads'], 'corretor vê só os números da própria carteira');
    $relOutra = relatorio_automacao(['empresa_ativa_id' => $emp2, 'escopo' => 'toda_empresa'] + $master, $fil);
    igual($relOutra['indicadores']['leads'], 0, 'outra empresa não vê nada desta');
    $arq = tempnam(sys_get_temp_dir(), 'xl');
    $linhas = exportacao_enviar('Automações por campanha', relaut_colunas('campanha'), $rel['por_campanha'], $arq, 'xlsx');
    $zip = new ZipArchive();
    ok($linhas >= 2 && $zip->open($arq) === true && str_contains((string) $zip->getFromName('xl/worksheets/sheet1.xml'), 'Oferta de novembro'), 'exportação Excel (.xlsx) com as linhas do relatório');
    $zip->close();
    unlink($arq);
} finally {
    db()->exec("DELETE FROM whatsapp_webhook_eventos WHERE empresa_id IN ($emp, $emp2)");
    apagar_empresa_teste($emp);
    apagar_empresa_teste($emp2);
    grupo('Limpeza');
    igual((int) db()->query("SELECT COUNT(*) FROM empresas WHERE nome LIKE 'Teste automatizado%'")->fetchColumn(), 0, 'nenhum dado de teste ficou no banco');
}
resumo_e_sair();
