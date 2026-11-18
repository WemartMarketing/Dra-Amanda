<?php
/**
 * Inteligência de Vendas — Fase 4: Jornada do Cliente. Linha do tempo só
 * com eventos registrados, naturezas (confirmado/importado/inferido),
 * tempos separados, corretores, etapas, empreendimento de entrada ×
 * comprado, classificação comercial, histórico incompleto e regra de
 * acesso após transferência. Só dados FICTÍCIOS.
 *
 *   php -q tests/vendas_fase4_test.php
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../includes/funcoes.php';
require_once __DIR__ . '/../includes/funil.php';
require_once __DIR__ . '/../includes/vendas/jornada.php';
require_once __DIR__ . '/../includes/relatorios/fila_exportacao.php';

const HOJE = '2026-11-18';
function q(string $sql)
{
    return db()->query($sql)->fetchColumn();
}

$empA = criar_empresa_teste('Vendas F4 A');
$empB = criar_empresa_teste('Vendas F4 B');
$tmp = [];
try {
    $pdo = db();
    $f = funcoes_seed_padrao($empA);
    funil_seed_padrao($empA);
    $fB = funcoes_seed_padrao($empB);
    $usr = function (int $emp, int $funcao, string $cargo, string $nome) use ($pdo) {
        $pdo->prepare('INSERT INTO usuarios (empresa_id, funcao_id, nome, email, senha_hash, cargo) VALUES (:e, :f, :n, :m, :h, :c)')
            ->execute(['e' => $emp, 'f' => $funcao, 'n' => $nome, 'm' => bin2hex(random_bytes(5)) . '@teste.invalid', 'h' => 'x', 'c' => $cargo]);
        return (int) $pdo->lastInsertId();
    };
    $master = $usr($empA, $f['master_empresa'], 'master_empresa', 'Master Fictício');
    $g1 = $usr($empA, $f['gerente'], 'gerente', 'Gerente Um');
    $g2 = $usr($empA, $f['gerente'], 'gerente', 'Gerente Dois');
    $equipe = function (string $nome, int $gerente) use ($pdo, $empA) {
        $pdo->prepare('INSERT INTO equipes (empresa_id, nome, gerente_id) VALUES (:e, :n, :g)')->execute(['e' => $empA, 'n' => $nome, 'g' => $gerente]);
        return (int) $pdo->lastInsertId();
    };
    $eq1 = $equipe('Equipe Um', $g1);
    $eq2 = $equipe('Equipe Dois', $g2);
    $eq3 = $equipe('Equipe Três', $g2);
    $cA = $usr($empA, $f['corretor'], 'corretor', 'Pedro Fictício');
    $cB = $usr($empA, $f['corretor'], 'corretor', 'Bruna Fictícia');
    $cC = $usr($empA, $f['corretor'], 'corretor', 'Caio Fictício');
    $cD = $usr($empA, $f['corretor'], 'corretor', 'Dora Fictícia');
    $cE = $usr($empA, $f['corretor'], 'corretor', 'Edu Fictício');
    $pdo->exec("UPDATE usuarios SET equipe_id = $eq1 WHERE id IN ($cA, $cB, $cD)");
    $pdo->exec("UPDATE usuarios SET equipe_id = $eq2 WHERE id = $cC");
    $pdo->exec("UPDATE usuarios SET equipe_id = $eq3 WHERE id = $cE");
    $M = relexp_usuario($master, $empA);
    $G2 = relexp_usuario($g2, $empA);
    $CE = relexp_usuario($cE, $empA);
    $pdo->prepare("INSERT INTO empreendimentos (empresa_id, nome) VALUES (:e, 'Smart Marquês Fictício'), (:e2, 'Smart Freguesia Fictício')")->execute(['e' => $empA, 'e2' => $empA]);
    $empMarques = (int) $pdo->lastInsertId();
    $empFreg = $empMarques + 1;
    $pdo->prepare("INSERT INTO oferta_listas (empresa_id, nome, criado_em) VALUES (:e, 'Lista Fictícia de Maio', '2026-05-02 09:00:00')")->execute(['e' => $empA]);
    $lista = (int) $pdo->lastInsertId();

    // João (fictício): Marquês em abril (Meta Ads), Freguesia em maio (oferta ativa).
    $novoLead = function (array $c) use ($pdo, $empA) {
        $c += ['empresa_id' => $empA, 'nome' => 'João Fictício', 'origem' => 'manual', 'etapa' => 'novo_lead'];
        $cols = array_keys($c);
        $pdo->prepare('INSERT INTO leads (' . implode(',', $cols) . ') VALUES (:' . implode(', :', $cols) . ')')->execute($c);
        return (int) $pdo->lastInsertId();
    };
    $L1 = $novoLead(['telefone' => '11900000101', 'email' => 'joao@teste.invalid', 'origem' => 'meta_ads', 'empreendimento_id' => $empMarques, 'equipe_id' => $eq1,
                     'responsavel_id' => $cB, 'criado_em' => '2026-04-10 09:00:00', 'utm_campaign' => 'campanha_ficticia_abril']);
    $L2 = $novoLead(['telefone' => '11900000101', 'email' => 'joao@teste.invalid', 'origem' => 'importacao', 'empreendimento_id' => $empFreg, 'equipe_id' => $eq2,
                     'responsavel_id' => $cC, 'oferta_lista_id' => $lista, 'criado_em' => '2026-05-02 09:30:00']);
    $atrib = function (int $lead, int $u, ?int $eq, string $forma, string $de, ?string $ate = null, array $extra = []) use ($pdo, $empA) {
        $pdo->prepare('INSERT INTO lead_atribuicoes (empresa_id, lead_id, usuario_id, equipe_id, gerente_id, forma, atribuido_em, encerrada_em, primeiro_atendimento_em, primeiro_contato_efetivo_em, contato_efetivo_via, reconstruido)
                       VALUES (:e, :l, :u, :q, :g, :f, :de, :ate, :pa, :pc, :via, :r)')
            ->execute(['e' => $empA, 'l' => $lead, 'u' => $u, 'q' => $eq, 'g' => $extra['gerente'] ?? null, 'f' => $forma, 'de' => $de, 'ate' => $ate,
                       'pa' => $extra['pa'] ?? null, 'pc' => $extra['pc'] ?? null, 'via' => $extra['via'] ?? null, 'r' => $extra['r'] ?? 0]);
    };
    $atrib($L1, $cA, $eq1, 'roleta', '2026-04-10 09:01:00', '2026-05-08 10:00:00', ['gerente' => $g1, 'pa' => '2026-04-11 08:00:00', 'pc' => '2026-04-12 10:00:00', 'via' => 'whatsapp']);
    $atrib($L1, $cB, $eq1, 'redistribuicao', '2026-05-08 10:00:00', null, ['gerente' => $g1]);
    $atrib($L2, $cC, $eq2, 'oferta_ativa', '2026-05-02 10:00:00', null, ['gerente' => $g2, 'r' => 1]);
    $pdo->prepare("INSERT INTO lead_transferencias (empresa_id, lead_id, de_usuario_id, para_usuario_id, motivo, criado_em) VALUES (:e, :l, :de, :para, 'inatividade', '2026-05-08 10:00:00')")
        ->execute(['e' => $empA, 'l' => $L1, 'de' => $cA, 'para' => $cB]);
    $etapa = function (string $de, string $para, string $em) use ($pdo, $empA, $L1) {
        $id = fn (string $cat) => (int) q("SELECT id FROM funil_etapas WHERE empresa_id = $empA AND categoria = '$cat' ORDER BY ordem LIMIT 1");
        $pdo->prepare("INSERT INTO lead_etapa_historico (empresa_id, lead_id, de_etapa_id, para_etapa_id, de_categoria, para_categoria, origem, ocorrido_em) VALUES (:e, :l, :dei, :pai, :de, :para, 'usuario', :em)")
            ->execute(['e' => $empA, 'l' => $L1, 'dei' => $id($de), 'pai' => $id($para), 'de' => $de, 'para' => $para, 'em' => $em]);
    };
    $etapa('novo_lead', 'primeiro_contato', '2026-04-12 10:00:00');
    $etapa('primeiro_contato', 'visita_agendada', '2026-04-15 10:00:00');
    $etapa('visita_agendada', 'visita_realizada', '2026-04-20 10:00:00');
    $pdo->prepare("INSERT INTO lead_eventos (lead_id, usuario_id, tipo, descricao, criado_em) VALUES (:l, :u, 'ligacao', 'Ligação', '2026-04-11 08:00:00'), (:l2, :u2, 'contato', 'Cliente pediu tabela (fictício).', '2026-04-12 10:00:00')")
        ->execute(['l' => $L1, 'u' => $cA, 'l2' => $L1, 'u2' => $cA]);
    $pdo->prepare("INSERT INTO agenda_compromissos (empresa_id, lead_id, usuario_id, tipo, titulo, inicio, status, criado_em, concluido_em) VALUES (:e, :l, :u, 'visita', 'Visita fictícia', '2026-04-20 10:00:00', 'realizado', '2026-04-15 10:00:00', '2026-04-20 11:00:00')")
        ->execute(['e' => $empA, 'l' => $L1, 'u' => $cA]);
    // WhatsApp: resposta em abril, campanha em 15/06, resposta em 18/06; e uma mensagem DEPOIS da venda.
    $pdo->prepare('INSERT INTO whatsapp_contas (empresa_id) VALUES (:e)')->execute(['e' => $empA]);
    $conta = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO whatsapp_contatos (empresa_id, telefone, lead_id) VALUES (:e, :t, :l)')->execute(['e' => $empA, 't' => '5511900000101', 'l' => $L1]);
    $contato = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO whatsapp_conversas (empresa_id, conta_id, contato_id, lead_id) VALUES (:e, :c, :ct, :l)')->execute(['e' => $empA, 'c' => $conta, 'ct' => $contato, 'l' => $L1]);
    $conv = (int) $pdo->lastInsertId();
    $msg = function (string $dir, string $em, ?int $por = null, ?int $camp = null) use ($pdo, $empA, $conv) {
        $pdo->prepare("INSERT INTO whatsapp_mensagens (empresa_id, conversa_id, direcao, tipo, status, enviada_por, campanha_id, criado_em, enviada_em) VALUES (:e, :c, :d, 'texto', 'entregue', :p, :camp, :em, :em2)")
            ->execute(['e' => $empA, 'c' => $conv, 'd' => $dir, 'p' => $por, 'camp' => $camp, 'em' => $em, 'em2' => $em]);
        return (int) $pdo->lastInsertId();
    };
    $msg('entrada', '2026-04-12 09:00:00');
    $msg('saida', '2026-04-12 09:30:00', $cA);
    $pdo->prepare("INSERT INTO whatsapp_campanhas (empresa_id, conta_id, nome, status) VALUES (:e, :c, 'Remarketing Fictício de Junho', 'concluida')")->execute(['e' => $empA, 'c' => $conta]);
    $camp = (int) $pdo->lastInsertId();
    $mCamp = $msg('saida', '2026-06-15 10:00:00', null, $camp);
    $pdo->prepare("INSERT INTO whatsapp_campanha_destinatarios (campanha_id, contato_id, lead_id, status, mensagem_id, atualizado_em) VALUES (:c, :ct, :l, 'respondida', :m, '2026-06-18 12:00:00')")
        ->execute(['c' => $camp, 'ct' => $contato, 'l' => $L1, 'm' => $mCamp]);
    $msg('entrada', '2026-06-18 11:00:00');
    $msg('entrada', '2026-06-18 11:05:00');
    $msg('entrada', '2026-10-01 11:00:00');   // depois da venda
    $pdo->prepare("INSERT INTO whatsapp_campanhas (empresa_id, conta_id, nome, status) VALUES (:e, :c, 'Campanha Fictícia de Setembro', 'concluida')")->execute(['e' => $empA, 'c' => $conta]);
    $camp2 = (int) $pdo->lastInsertId();
    $mCamp2 = $msg('saida', '2026-09-20 10:00:00', null, $camp2);
    $pdo->prepare("INSERT INTO whatsapp_campanha_destinatarios (campanha_id, contato_id, lead_id, status, mensagem_id, atualizado_em) VALUES (:c, :ct, :l, 'entregue', :m, '2026-09-20 10:01:00')")
        ->execute(['c' => $camp2, 'ct' => $contato, 'l' => $L1, 'm' => $mCamp2]);
    $pdo->prepare("INSERT INTO jornadas (empresa_id, nome, gatilho) VALUES (:e, 'Boas-vindas Fictícia', 'novo_lead')")->execute(['e' => $empA]);
    $jor = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO jornada_execucoes (empresa_id, jornada_id, lead_id, status, mensagens_enviadas, iniciada_em) VALUES (:e, :j, :l, 'concluida', 1, '2026-05-03 09:00:00')")
        ->execute(['e' => $empA, 'j' => $jor, 'l' => $L2]);

    // Vendas (fictícias): 1 = do João (10/09); 2 = cliente sem CRM; 3 = cliente que entrou no CRM depois.
    $L3 = $novoLead(['telefone' => '11900000303', 'email' => null, 'criado_em' => '2026-09-25 10:00:00', 'equipe_id' => $eq1]);
    $cab = ['id', 'Venda RM', 'Situação Proposta', 'datahoravenda', 'cliente', 'Celular', 'Email', 'empreendimento', 'id_corretor', 'corretor', 'midia', 'valor'];
    $linhas = [['1', '91', 'Vendido', '10/09/2026', 'João Fictício', '5511900000101', 'joao@teste.invalid', 'HM Smart Freguesia do Ó (fictício)', '7001', 'CIA_VENDEDOR FICTICIO', 'Canal de Parceria', '320000'],
               ['2', '92', 'Vendido', '10/09/2026', 'Sem CRM Fictício', '5511999990002', 'semcrm@teste.invalid', 'HM Smart Freguesia do Ó (fictício)', '7002', 'CORRETOR_X', 'Indicacao', '300000'],
               ['3', '93', 'Vendido', '10/09/2026', 'Depois Fictício', '5511900000303', '', 'HM Smart Freguesia do Ó (fictício)', '7001', 'CIA_VENDEDOR FICTICIO', 'Roleta', '290000'],
               ['5', '95', 'Vendido', '10/09/2026', 'Ambíguo Fictício', '5511900000505', 'outro@teste.invalid', 'HM Smart Freguesia do Ó (fictício)', '7001', 'CIA_VENDEDOR FICTICIO', 'Roleta', '280000']];
    $LY = $novoLead(['telefone' => '11900000505', 'email' => null, 'criado_em' => '2026-02-01 10:00:00', 'equipe_id' => $eq1]);
    $LX = $novoLead(['telefone' => '11977770000', 'email' => 'outro@teste.invalid', 'criado_em' => '2026-02-02 10:00:00', 'equipe_id' => $eq1]);
    $c = tempnam(sys_get_temp_dir(), 'wmv4') . '.csv';
    $tmp[] = $c;
    $fh = fopen($c, 'w');
    fputcsv($fh, $cab, ';', '"', '');
    foreach ($linhas as $l) {
        fputcsv($fh, $l, ';', '"', '');
    }
    fclose($fh);
    $leitura = vendas_ler_arquivo($c, 'csv');
    $lote = vendas_importar($M, ['caminho' => $c, 'ext' => 'csv', 'nome' => 't.csv'], vendas_mapear_cabecalho($leitura['cabecalho'])['mapa'], ['vendido'], true, null, HOJE);
    @unlink(vendas_arquivo_lote($lote, 'csv'));
    vendas_equivalencia_salvar($M, 'empreendimento', 'HM Smart Freguesia do Ó (fictício)', ['classificacao' => 'crm', 'empreendimento_id' => $empFreg]);
    vendas_cruzar($empA);
    $venda = fn (string $k) => (int) q("SELECT id FROM vendas_externas WHERE empresa_id = $empA AND chave_origem = '$k'");
    $antesLeads = q("SELECT GROUP_CONCAT(CONCAT_WS('|', id, etapa, responsavel_id, atualizado_em) ORDER BY id) FROM leads WHERE empresa_id = $empA");

    $J = vendas_jornada($M, $venda('1'));
    grupo('Vínculo e acesso');
    igual([$J['acesso'], array_keys($J['leads'])], ['ok', [$L1, $L2]], '5. as duas oportunidades do João entram na jornada');
    igual(vendas_jornada($M, 999999999), null, 'venda inexistente');
    $mB = relexp_usuario($usr($empB, $fB['master_empresa'], 'master_empresa', 'Master B'), $empB);
    igual(vendas_jornada($mB, $venda('1')), null, '19. Master de outra empresa não abre a jornada');
    igual(vendas_jornada($M, $venda('2'))['acesso'], 'sem_vinculo', '13. venda sem correspondência: sem jornada (nada inventado)');

    grupo('Linha do tempo: só eventos registrados');
    $tipos = array_column($J['eventos'], 'tipo');
    $tem = fn (string $t) => in_array($t, $tipos, true);
    ok($tem('entrada') && $tem('distribuicao') && $tem('atendimento') && $tem('contato_efetivo') && $tem('tentativa') && $tem('contato'), 'entrada, distribuição, atendimento e contatos');
    ok($tem('visita_agendada') && $tem('visita') && $tem('transferencia') && $tem('etapa'), '8. visita, transferência e etapas');
    ok($tem('remarketing') && $tem('whatsapp_cliente') && $tem('whatsapp_corretor') && $tem('automacao') && $tem('oferta'), '9/10. oferta ativa, remarketing, WhatsApp e jornada automática');
    $ultimo = end($J['eventos']);
    $venEv = array_values(array_filter($J['eventos'], fn ($e) => $e['tipo'] === 'venda'))[0];
    igual([$venEv['natureza'], substr($venEv['em'], 0, 10)], ['importado', '2026-09-10'], 'venda marcada como dado importado');
    ok($ultimo['depois_da_venda'] && $ultimo['tipo'] === 'whatsapp_cliente', 'mensagem de outubro aparece separada como "depois da venda"');
    $datas = array_map(fn ($e) => $e['tipo'] === 'venda' ? substr($e['em'], 0, 10) . ' 23:59:59' : $e['em'], $J['eventos']);
    $ord = $datas;
    sort($ord);
    igual($datas, $ord, 'eventos em ordem cronológica');
    $inferidos = array_filter($J['eventos'], fn ($e) => $e['natureza'] === 'inferido');
    ok($inferidos && array_values($inferidos)[0]['lead_id'] === $L2, 'atribuição reconstruída aparece como "inferido"');
    ok(isset($J['incompleto']['reconstruido']), '…e o histórico é sinalizado como parcialmente reconstruído');
    ok(isset($J['incompleto']['anuncio']), 'lead do Meta Ads sem anúncio registrado: "não disponível", sem inventar anúncio');
    igual(count(array_filter($J['eventos'], fn ($e) => $e['tipo'] === 'whatsapp_cliente' && !$e['depois_da_venda'])), 2, 'mensagens do cliente agrupadas por dia (12/04 e 18/06)');

    grupo('Tempos (separados)');
    $m = $J['metricas'];
    igual($m['dias_jornada'], 153, 'jornada: 10/04 → 10/09 = 153 dias');
    igual($m['dias_ate_primeiro_atendimento'], 1, 'até o 1º atendimento humano: 1 dia');
    igual($m['dias_primeiro_atendimento_ate_venda'], 152, '1º atendimento → venda: 152 dias');
    igual($m['dias_primeira_visita_ate_venda'], 143, '1ª visita realizada (20/04) → venda: 143 dias');
    igual($m['dias_ultima_interacao_ate_venda'], 84, 'última interação (18/06) → venda: 84 dias');
    igual($m['dias_com_interacao_efetiva'], 3, 'dias com interação efetiva (12/04, 20/04 visita, 18/06): 3 — não é o tempo de jornada');
    igual([$m['responsabilidade_por_corretor']['Pedro Fictício'] ?? null, $m['responsabilidade_por_corretor']['Bruna Fictícia'] ?? null, $m['responsabilidade_por_corretor']['Caio Fictício'] ?? null], [28, 125, 131], '…Pedro 28, Bruna 125, Caio 131 dias');
    igual([$m['transferencias'], $m['redistribuicoes_inatividade'], $m['tentativas_contato']], [1, 1, 2], '8. 1 transferência, 1 redistribuição por inatividade, 2 tentativas/contatos');
    igual([$m['campanhas_remarketing'], $m['jornadas_automaticas'], $m['respostas_whatsapp'], $m['visitas_realizadas']], [1, 1, 3, 1], '10. 1 campanha antes da venda (a de setembro, depois, não conta), 1 jornada automática, 3 respostas, 1 visita');
    igual($m['oferta_ativa'], 'sim', '9. passou pela oferta ativa');
    igual(array_map(fn ($i) => $i['dias'], $m['inatividade']), [59, 84], 'inatividade > 30 dias: 20/04→18/06 (campanha não é interação humana) e 18/06→10/09');
    igual($m['primeiro_corretor'], 'Pedro Fictício', 'primeiro corretor do CRM');
    igual($J['etapas'][$L1], ['novo_lead' => 2.0, 'primeiro_contato' => 3.0, 'visita_agendada' => 5.0, 'visita_realizada' => 143.6], 'tempo em cada etapa do Kanban até a venda');
    ok(in_array('Gerente Um', $J['gerentes'], true) && in_array('Gerente Dois', $J['gerentes'], true), 'gestores envolvidos no período');

    grupo('6/7. Empreendimento de entrada × comprado');
    igual([$J['movimento']['primeiro']['empreendimento_id'], $J['movimento']['comprado_id'], $J['movimento']['resultado']], [$empMarques, $empFreg, 'diferente'], 'entrou pelo Marquês, comprou o Freguesia: diferente');
    igual(array_column($J['movimento']['intermediarios'], 'empreendimento_id'), [$empFreg], 'interesse intermediário listado (sem eleger um só "responsável")');
    ok($J['movimento']['comprado_entre_interesses'], 'o comprado estava entre os interesses registrados');

    grupo('11/12. Corretor da venda × CRM');
    igual(array_column($J['responsaveis_na_venda'], 'usuario_id'), [$cC, $cB], 'responsáveis no CRM na data da venda (por oportunidade)');
    igual($J['classificacao'], 'nao_identificado', '12. vendedor sem equivalência: "não identificado" (nunca presume externo pelo prefixo)');
    $eqCor = fn (string $alvo, ?int $id = null) => vendas_equivalencia_salvar($M, 'corretor', 'CIA_VENDEDOR FICTICIO', ['classificacao' => $alvo, 'usuario_id' => $alvo === 'crm' ? $id : null, 'imobiliaria_id' => null, 'valor_origem' => '7001']);
    $eqCor('crm', $cB);
    igual(vendas_jornada($M, $venda('1'))['classificacao'], 'responsavel_crm', 'vendedor = responsável no CRM na data');
    $eqCor('crm', $cD);
    igual(vendas_jornada($M, $venda('1'))['classificacao'], 'outro_corretor_mesma_estrutura', '11. outro corretor da mesma equipe');
    $eqCor('crm', $cE);
    igual(vendas_jornada($M, $venda('1'))['classificacao'], 'outra_equipe', 'outra equipe');
    $eqCor('externo');
    igual(vendas_jornada($M, $venda('1'))['classificacao'], 'canal_externo', 'canal externo, só por equivalência confirmada');
    igual(vendas_jornada($M, $venda('3'))['classificacao'], 'nao_aplicavel', '14. cliente que entrou no CRM depois da venda: não se aplica');
    igual(vendas_jornada($M, $venda('3'))['metricas']['dias_jornada'], null, '…e não tem "tempo de jornada"');

    grupo('Ambígua e lead descartado na revisão');
    $J5 = vendas_jornada($M, $venda('5'));
    igual([$J5['acesso'], $J5['classificacao']], ['sem_vinculo', 'pendente_revisao'], 'ambígua: sem jornada e "correspondência pendente de revisão"');
    vendas_revisar($M, $venda('5'), 'confirmar', [$LY], 'Confirmado com o corretor (teste fictício).');
    igual(array_keys(vendas_jornada($M, $venda('5'))['leads']), [$LY], 'lead descartado na revisão não entra na jornada');

    grupo('18. Acesso após transferência');
    $JG = vendas_jornada($G2, $venda('1'));
    igual([array_keys($JG['leads']), $JG['ocultos']], [[$L2], 1], 'gerente da equipe 2 vê só a oportunidade da sua equipe; a outra fica oculta');
    ok(!array_filter($JG['eventos'], fn ($e) => $e['lead_id'] === $L1), '…nenhum evento do lead da outra equipe aparece');
    igual(vendas_jornada($CE, $venda('1'))['acesso'], 'sem_permissao', 'corretor sem os leads na carteira: sem jornada individual');

    grupo('Histórico incompleto, sem inventar');
    $L4 = $novoLead(['telefone' => '11900000404', 'criado_em' => '2026-03-01 10:00:00', 'responsavel_id' => $cA]);
    $pdo->prepare("INSERT INTO vendas_externas (empresa_id, sistema_origem, chave_origem, primeiro_lote_id, ultimo_lote_id, data_venda, telefone1, empreendimento_texto, eh_venda, hash_conteudo)
                   VALUES (:e, 'hm_comercial', '4', :l, :l2, '2026-09-10 00:00:00', '5511900000404', 'X', 1, 'h')")->execute(['e' => $empA, 'l' => $lote, 'l2' => $lote]);
    vendas_cruzar($empA);
    $J4 = vendas_jornada($M, $venda('4'));
    ok(isset($J4['incompleto']['atribuicoes']) && $J4['metricas']['primeiro_corretor'] === null, 'sem histórico de distribuição: primeiro corretor "não disponível" (não usa o atual)');
    ok(isset($J4['incompleto']['etapas']) && !$J4['etapas'], 'sem histórico de etapas: tempo por etapa indisponível');
    igual($J4['metricas']['oferta_ativa'], 'sem_registro', 'sem registro de oferta ativa: não presume que não participou nem que participou');
    igual($J4['movimento']['resultado'], 'sem_equivalencia', 'empreendimento comprado sem equivalência: não compara');

    grupo('Nada é gravado');
    igual(q("SELECT GROUP_CONCAT(CONCAT_WS('|', id, etapa, responsavel_id, atualizado_em) ORDER BY id) FROM leads WHERE empresa_id = $empA AND id <> $L4"), $antesLeads, 'leads intocados');
    $fonte = file_get_contents(__DIR__ . '/../includes/vendas/jornada.php');
    ok(!preg_match('/\b(INSERT|UPDATE|DELETE)\s/i', $fonte), 'a Jornada só lê');
} finally {
    foreach ($tmp as $t) {
        @unlink($t);
    }
    apagar_empresa_teste($empA);
    apagar_empresa_teste($empB);
}
resumo_e_sair();
