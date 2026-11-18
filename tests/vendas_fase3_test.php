<?php
/**
 * Inteligência de Vendas — Fase 3: motor de cruzamento persistido, revisão
 * humana, equivalências, idempotência, isolamento e permissões.
 * Só dados FICTÍCIOS.
 *
 *   php -q tests/vendas_fase3_test.php
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../includes/funcoes.php';
require_once __DIR__ . '/../includes/vendas/cruzamento.php';
require_once __DIR__ . '/../includes/relatorios/fila_exportacao.php';

const HOJE = '2026-11-18';
function q(string $sql)
{
    return db()->query($sql)->fetchColumn();
}

grupo('Máscaras');
igual(vendas_mascarar_telefone('5511987654321'), '(11) 9••••-4321', 'telefone mascarado');
igual(vendas_mascarar_email('fulano@teste.invalid'), 'f•••@teste.invalid', 'e-mail mascarado');
igual(vendas_mascarar_telefone(null), '—', 'sem telefone');

$empA = criar_empresa_teste('Vendas F3 A');
$empB = criar_empresa_teste('Vendas F3 B');
$tmp = [];
try {
    $pdo = db();
    $fA = funcoes_seed_padrao($empA);
    funcoes_seed_padrao($empB);
    $usr = function (int $emp, int $funcao, string $cargo, ?int $equipe = null) use ($pdo) {
        $pdo->prepare('INSERT INTO usuarios (empresa_id, funcao_id, nome, email, senha_hash, cargo, equipe_id) VALUES (:e, :f, :n, :m, :h, :c, :q)')
            ->execute(['e' => $emp, 'f' => $funcao, 'n' => 'Usuário ' . $cargo . ' ' . bin2hex(random_bytes(2)), 'm' => bin2hex(random_bytes(5)) . '@teste.invalid', 'h' => 'x', 'c' => $cargo, 'q' => $equipe]);
        return (int) $pdo->lastInsertId();
    };
    $master = $usr($empA, $fA['master_empresa'], 'master_empresa');
    $gerente = $usr($empA, $fA['gerente'], 'gerente');
    $pdo->prepare("INSERT INTO equipes (empresa_id, nome, gerente_id) VALUES (:e, 'Equipe Fictícia', :g)")->execute(['e' => $empA, 'g' => $gerente]);
    $equipe = (int) $pdo->lastInsertId();
    $pdo->exec("UPDATE usuarios SET equipe_id = $equipe WHERE id = $gerente");
    $M = relexp_usuario($master, $empA);
    $G = relexp_usuario($gerente, $empA);
    $pdo->prepare("INSERT INTO empreendimentos (empresa_id, nome) VALUES (:e, 'Residencial Aurora'), (:e2, 'Vila Horizonte')")->execute(['e' => $empA, 'e2' => $empA]);
    $empAurora = (int) $pdo->lastInsertId();
    $empHoriz = $empAurora + 1;
    $pdo->prepare("INSERT INTO empreendimentos (empresa_id, nome) VALUES (:e, 'Torre Dupla'), (:e2, 'TORRE DUPLA')")->execute(['e' => $empA, 'e2' => $empA]);   // nome repetido no CRM
    $pdo->prepare("INSERT INTO empreendimentos (empresa_id, nome) VALUES (:e, 'Residencial Aurora')")->execute(['e' => $empB]);
    $empDeB = (int) $pdo->lastInsertId();

    $lead = function (int $emp, ?string $tel, ?string $email, string $criado, ?int $empreend = null, ?string $excluido = null, ?int $equipeId = null) use ($pdo) {
        $pdo->prepare("INSERT INTO leads (empresa_id, empreendimento_id, equipe_id, nome, telefone, email, origem, etapa, criado_em, excluido_em) VALUES (:e, :emp, :q, 'Lead Fictício', :t, :m, 'manual', 'novo_lead', :c, :x)")
            ->execute(['e' => $emp, 'emp' => $empreend, 'q' => $equipeId, 't' => $tel, 'm' => $email, 'c' => $criado, 'x' => $excluido]);
        return (int) $pdo->lastInsertId();
    };
    $L1 = $lead($empA, '(11) 90000-0001', 'Um@Teste.Invalid', '2026-04-10 09:00:00', $empAurora);           // forte
    $L2 = $lead($empA, '11900000002', null, '2026-05-01 10:00:00');                                         // só telefone
    $L3 = $lead($empA, '11900000099', 'tres@teste.invalid', '2026-05-01 10:00:00');                         // só e-mail
    $L4a = $lead($empA, '11900000004', null, '2026-05-01 10:00:00', null, null, $equipe);                   // conflito: telefone
    $L4b = $lead($empA, '11900000098', 'quatro@teste.invalid', '2026-05-02 10:00:00');                      // conflito: e-mail (fora da equipe do gerente)
    $L5a = $lead($empA, '11900000005', 'cinco@teste.invalid', '2026-04-10 08:00:00', $empAurora);           // mesma pessoa, 2 interesses
    $L5b = $lead($empA, '11900000005', 'cinco@teste.invalid', '2026-05-15 08:00:00', $empHoriz);
    $L7 = $lead($empA, '11900000007', null, '2026-09-20 10:00:00');                                         // posterior à venda
    $L8 = $lead($empA, '1180000008', null, '2026-03-01 10:00:00');                                          // celular antigo (sem 9)
    $L9 = $lead($empA, '11900000009', 'nove@teste.invalid', '2026-03-01 10:00:00', null, '2026-06-01');     // excluído (LGPD)
    $LB = $lead($empB, '11900000001', 'um@teste.invalid', '2026-01-01 10:00:00');                           // outra empresa, mesmo contato
    if (modulo_tabela_existe('whatsapp_contatos')) {
        $pdo->prepare('INSERT INTO whatsapp_contatos (empresa_id, telefone, lead_id) VALUES (:e, :t, :l)')->execute(['e' => $empA, 't' => '5511900000077', 'l' => $L2]);
    }
    $antesLeads = q("SELECT CONCAT(COUNT(*), '|', COALESCE(SUM(CRC32(CONCAT_WS('|', etapa, responsavel_id, empreendimento_id, atualizado_em))), 0)) FROM leads WHERE empresa_id = $empA");

    // Vendas fictícias (venda em 10/09/2026).
    $cab = ['id', 'Venda RM', 'Situação Proposta', 'datahoravenda', 'cliente', 'Celular', 'telefone', 'Email', 'empreendimento', 'id_corretor', 'corretor', 'midia', 'valor'];
    $v = fn (int $i, string $cel, string $email, string $emp = 'Residencial Aurora', string $tel2 = '') => [(string) (800000 + $i), (string) (600000 + $i), 'Vendido', '10/09/2026',
        'Comprador Fictício ' . $i, $cel, $tel2, $email, $emp, '5001', 'CIA_PESSOA EXEMPLO', 'Indicacao', '300000'];
    $linhas = [
        $v(1, '5511900000001', 'um@teste.invalid'),           // 1 forte
        $v(2, '5511900000002', 'outro2@teste.invalid'),       // 2 telefone (CRM sem e-mail)
        $v(3, '5511911111103', 'tres@teste.invalid'),         // 3 e-mail
        $v(4, '5511900000004', 'quatro@teste.invalid'),       // 4 ambígua (telefone → L4a, e-mail → L4b)
        $v(5, '5511900000005', 'cinco@teste.invalid', 'Vila Horizonte'),   // 5/6/7 vários leads, outro empreendimento
        $v(6, '5511922222206', 'ninguem@teste.invalid'),      // 13 sem correspondência
        $v(7, '5511900000007', ''),                           // 14 posterior
        $v(8, '5511980000008', ''),                           // variante do 9º dígito
        $v(9, '5511900000009', 'nove@teste.invalid'),         // lead excluído
        $v(10, '5511900000001', 'um@teste.invalid'),          // 17 mesma pessoa, 2ª unidade
        $v(11, '5511933333311', '', 'Hm Residêncial AURORA', '5511900000077'),   // contato secundário (WhatsApp) no 2º telefone
    ];
    $c = tempnam(sys_get_temp_dir(), 'wmv3') . '.csv';
    $tmp[] = $c;
    $fh = fopen($c, 'w');
    fputcsv($fh, $cab, ';', '"', '');
    foreach ($linhas as $l) {
        fputcsv($fh, $l, ';', '"', '');
    }
    fclose($fh);
    $leitura = vendas_ler_arquivo($c, 'csv');
    $lote = vendas_importar($M, ['caminho' => $c, 'ext' => 'csv', 'nome' => 'teste.csv'], vendas_mapear_cabecalho($leitura['cabecalho'])['mapa'], ['vendido'], true, null, HOJE);
    @unlink(vendas_arquivo_lote($lote, 'csv'));
    $venda = fn (int $i) => (int) q("SELECT id FROM vendas_externas WHERE empresa_id = $empA AND chave_origem = '" . (800000 + $i) . "'");
    $corr = fn (int $i) => vendas_correspondencia($venda($i));
    $leadsDe = function (int $i, ?string $papel = null) use ($venda) {
        $ls = array_filter(vendas_correspondencia_leads($venda($i)), fn ($l) => $papel === null || $l['papel'] === $papel);
        return array_values(array_map(fn ($l) => (int) $l['lead_id'], $ls));
    };

    grupo('Índice de contatos');
    $r = vendas_cruzar($empA);
    igual($r['vendas'], 11, 'todas as vendas da empresa cruzadas');
    igual((int) q("SELECT COUNT(*) FROM contato_identificadores WHERE empresa_id = $empA AND lead_id = $L9"), 0, 'lead excluído (LGPD) fora do índice');
    igual((int) q("SELECT COUNT(*) FROM contato_identificadores WHERE lead_id = $LB AND empresa_id = $empA"), 0, '19. lead da outra empresa fora do índice da A');
    igual((string) q("SELECT valor FROM contato_identificadores WHERE lead_id = $L8"), '5511980000008', 'celular antigo indexado só como variante (com 9)');
    igual((string) q("SELECT tipo FROM contato_identificadores WHERE lead_id = $L8"), 'telefone_variante', '…marcado como variante');

    grupo('Cenários do cruzamento');
    $c1 = $corr(1);
    igual([$c1['status'], (int) $c1['vinculada'], $leadsDe(1, 'vinculado')], ['forte', 1, [$L1]], '1. telefone e e-mail = forte, vinculada');
    igual(substr((string) $c1['primeira_entrada_em'], 0, 10), '2026-04-10', '…1ª entrada comprovada no CRM gravada');
    igual($c1['regras_versao'], VENDAS_REGRAS_VERSAO, '…versão das regras registrada');
    igual([$corr(2)['status'], $leadsDe(2)], ['telefone', [$L2]], '2. só telefone');
    igual([$corr(3)['status'], $leadsDe(3)], ['email', [$L3]], '3. só e-mail');
    $c4 = $corr(4);
    igual([$c4['status'], (int) $c4['vinculada'], $leadsDe(4, 'candidato')], ['ambigua', 0, [$L4a, $L4b]], '4. telefone e e-mail em cadastros diferentes = ambígua, só candidatos');
    igual([$corr(5)['status'], $leadsDe(5, 'vinculado')], ['forte', [$L5a, $L5b]], '5/6. vários leads da mesma pessoa (2 empreendimentos) ligados à venda');
    igual(substr((string) $corr(5)['primeira_entrada_em'], 0, 10), '2026-04-10', '…entrada = o lead mais antigo');
    igual([$corr(6)['status'], (int) $corr(6)['vinculada'], $leadsDe(6)], ['sem', 0, []], '13. sem correspondência');
    igual([$corr(7)['status'], $corr(7)['cronologia']], ['posterior', 'posterior'], '14. lead criado depois da venda = posterior');
    $ev8 = vendas_correspondencia_leads($venda(8));
    igual([$corr(8)['status'], $ev8[0]['evidencia'] ?? null, $ev8[0]['papel'] ?? null], ['ambigua', 'variante', 'candidato'], 'variante do 9º dígito: só candidato, para revisão');
    igual($corr(9)['status'], 'sem', 'lead excluído não vira correspondência');
    igual($leadsDe(10), [$L1], '17. mesma pessoa, 2ª unidade: mesma ligação, venda separada');
    igual([$corr(11)['status'], $leadsDe(11)], ['telefone', [$L2]], 'telefone do WhatsApp ligado ao lead (contato secundário) conta');

    grupo('Idempotência e histórico');
    $nCorr = (int) q("SELECT COUNT(*) FROM vendas_correspondencias WHERE empresa_id = $empA");
    $nLeads = (int) q("SELECT COUNT(*) FROM vendas_correspondencia_leads cl JOIN vendas_externas v ON v.id = cl.venda_id WHERE v.empresa_id = $empA");
    $nLog = (int) q("SELECT COUNT(*) FROM vendas_conciliacao_log g JOIN vendas_externas v ON v.id = g.venda_id WHERE v.empresa_id = $empA");
    igual($nLog, 11, 'uma linha de histórico por venda na 1ª vez');
    vendas_cruzar($empA);
    vendas_cruzar($empA, $lote);
    igual((int) q("SELECT COUNT(*) FROM vendas_correspondencias WHERE empresa_id = $empA"), $nCorr, 'rodar de novo não duplica correspondências');
    igual((int) q("SELECT COUNT(*) FROM vendas_correspondencia_leads cl JOIN vendas_externas v ON v.id = cl.venda_id WHERE v.empresa_id = $empA"), $nLeads, '…nem ligações com leads');
    igual((int) q("SELECT COUNT(*) FROM vendas_conciliacao_log g JOIN vendas_externas v ON v.id = g.venda_id WHERE v.empresa_id = $empA"), $nLog, '…nem histórico (nada mudou)');
    $L6 = $lead($empA, '11922222206', null, '2026-02-01 10:00:00');   // dado novo chega ao CRM
    vendas_cruzar($empA);
    igual([$corr(6)['status'], $leadsDe(6)], ['telefone', [$L6]], 'novo lead no CRM: recruzamento encontra a venda que estava "sem"');
    igual((int) q('SELECT COUNT(*) FROM vendas_conciliacao_log WHERE venda_id = ' . $venda(6)), 2, '…e o histórico registra a mudança (sem → telefone)');

    grupo('18. Revisão humana: permissões e escopo');
    ok(usuario_tem_permissao($M, 'revisar_correspondencias') && usuario_tem_permissao($M, 'ver_inteligencia_vendas'), 'Master revisa');
    ok(!usuario_tem_permissao($G, 'revisar_correspondencias') && !usuario_tem_permissao($G, 'ver_inteligencia_vendas'), 'gerente não revisa nem vê a conciliação');
    lanca(fn () => vendas_revisar($G, $venda(4), 'confirmar', [$L4b], 'gerente tentando lead de fora'), 'lead fora da carteira do revisor: recusado');
    lanca(fn () => vendas_revisar($M, $venda(4), 'confirmar', [$L4a], 'ok'), 'justificativa curta: recusada');
    lanca(fn () => vendas_revisar($M, $venda(4), 'confirmar', [$L1], 'lead que não é candidato'), 'lead que não é candidato da venda: recusado');
    $mB = relexp_usuario($usr($empB, (int) q("SELECT id FROM funcoes WHERE empresa_id = $empB AND nome = 'Master da empresa'"), 'master_empresa'), $empB);
    lanca(fn () => vendas_revisar($mB, $venda(4), 'confirmar', [$L4a], 'empresa errada tentando'), '19. Master de outra empresa não revisa venda da A');
    lanca(fn () => vendas_revisar($mB, $venda(2), 'rejeitar', [], 'empresa errada tentando rejeitar'), '19. …nem rejeita (sem precisar de lead)');
    igual($corr(2)['decisao'], 'automatica', '…e a venda da A fica como estava');

    grupo('Revisão humana: confirmar, rejeitar, reabrir');
    igual(vendas_revisar($M, $venda(4), 'confirmar', [$L4a], 'Cliente confirmou por telefone que é o mesmo cadastro.'), 'confirmada', 'confirmar um candidato');
    igual([(int) $corr(4)['vinculada'], $leadsDe(4, 'vinculado'), $leadsDe(4, 'descartado')], [1, [$L4a], [$L4b]], '…escolhido vinculado, outro descartado');
    igual(substr((string) $corr(4)['primeira_entrada_em'], 0, 10), '2026-05-01', '…entrada recalculada pelos leads escolhidos');
    vendas_cruzar($empA);
    igual([$corr(4)['decisao'], $leadsDe(4, 'vinculado')], ['confirmada', [$L4a]], 'recruzamento NÃO sobrescreve a decisão humana');
    ok(str_contains((string) $corr(4)['alertas'], 'difere da revisão humana'), '…mas avisa que o automático discorda');
    igual(vendas_revisar($M, $venda(2), 'rejeitar', [], 'Homônimo: telefone reaproveitado por outra pessoa.'), 'rejeitada', 'rejeitar um vínculo automático');
    igual([(int) $corr(2)['vinculada'], $leadsDe(2, 'descartado')], [0, [$L2]], '…sem vínculo, lead descartado');
    vendas_cruzar($empA);
    igual($corr(2)['decisao'], 'rejeitada', 'recruzamento mantém a rejeição');
    igual(vendas_revisar($M, $venda(2), 'reabrir', [], 'Revisão reaberta para conferir de novo.'), 'telefone', 'reabrir volta ao automático e recalcula');
    igual([$corr(2)['decisao'], $leadsDe(2, 'vinculado')], ['automatica', [$L2]], '…vínculo automático restaurado');
    igual((int) q('SELECT COUNT(*) FROM vendas_revisoes WHERE venda_id = ' . $venda(2)), 2, 'cada decisão fica em vendas_revisoes');
    $aud = (string) q("SELECT GROUP_CONCAT(detalhes_json) FROM auditoria WHERE empresa_id = $empA AND acao = 'vendas_revisar'");
    ok($aud !== '' && !str_contains($aud, '@teste.invalid') && !str_contains($aud, 'Comprador'), 'auditoria da revisão sem dados pessoais');

    grupo('Equivalências');
    igual(vendas_sugestao_empreendimento($empA, 'Hm Residêncial AURORA'), null, 'nome diferente ("Hm …"): sem sugestão automática');
    igual(vendas_sugestao_empreendimento($empA, 'Torre Dupla'), null, 'dois empreendimentos com o mesmo nome no CRM: sem sugestão (risco de erro)');
    igual(vendas_sugestao_empreendimento($empA, 'residencial  aurora'), $empAurora, 'mesmo nome sem caixa/acento: sugestão (não aplicada sozinha)');
    igual((int) q('SELECT COUNT(*) FROM vendas_externas WHERE empreendimento_id IS NOT NULL AND empresa_id = ' . $empA), 0, 'nada é ligado a empreendimento sem confirmação');
    lanca(fn () => vendas_equivalencia_salvar($M, 'empreendimento', 'Residencial Aurora', ['classificacao' => 'crm', 'empreendimento_id' => $empDeB]), '19. empreendimento de outra empresa: recusado');
    vendas_equivalencia_salvar($M, 'empreendimento', 'Residencial Aurora', ['classificacao' => 'crm', 'empreendimento_id' => $empAurora]);
    igual((int) q('SELECT empreendimento_id FROM vendas_externas WHERE id = ' . $venda(1)), $empAurora, 'equivalência confirmada liga as vendas ao empreendimento');
    vendas_equivalencia_salvar($M, 'empreendimento', 'Hm Residêncial AURORA', ['classificacao' => 'crm', 'empreendimento_id' => $empAurora]);
    igual((int) q('SELECT empreendimento_id FROM vendas_externas WHERE id = ' . $venda(11)), $empAurora, 'grafia diferente ligada por equivalência humana');
    vendas_equivalencia_salvar($M, 'empreendimento', 'Residencial Aurora', ['classificacao' => 'nao_existe']);
    igual(q('SELECT empreendimento_id FROM vendas_externas WHERE id = ' . $venda(1)), null, '"não existe no CRM" desfaz a ligação');
    lanca(fn () => vendas_equivalencia_salvar($M, 'corretor', 'CIA_PESSOA EXEMPLO', ['classificacao' => 'crm', 'usuario_id' => $mB['id'], 'valor_origem' => '5001']), 'corretor ligado a usuário de outra empresa: recusado');
    $pdo->prepare("INSERT INTO imobiliarias_parceiras (empresa_id, nome) VALUES (:e, 'Imobiliária Parceira Fictícia')")->execute(['e' => $empA]);
    $imob = (int) $pdo->lastInsertId();
    vendas_equivalencia_salvar($M, 'corretor', 'CIA_PESSOA EXEMPLO', ['classificacao' => 'externo', 'imobiliaria_id' => $imob, 'valor_origem' => '5001']);
    igual(q("SELECT CONCAT(classificacao, '|', imobiliaria_id) FROM vendas_equivalencias WHERE empresa_id = $empA AND tipo = 'corretor' AND valor_origem = '5001'"), 'externo|' . $imob, 'corretor classificado como externo (imobiliária parceira) por uma pessoa');
    igual(vendas_sugestao_corretor($empA, 'CIA_PESSOA EXEMPLO'), null, 'prefixo do nome não classifica ninguém sozinho');

    grupo('Nada muda no CRM');
    igual(q("SELECT CONCAT(COUNT(*), '|', COALESCE(SUM(CRC32(CONCAT_WS('|', etapa, responsavel_id, empreendimento_id, atualizado_em))), 0)) FROM leads WHERE empresa_id = $empA AND id <> $L6"),
        explode('|', $antesLeads)[0] . '|' . explode('|', $antesLeads)[1], 'leads (etapa, responsável, empreendimento) intocados pelo cruzamento e pela revisão');
    $fonte = file_get_contents(__DIR__ . '/../includes/vendas/cruzamento.php');
    ok(!preg_match('/(INSERT INTO|UPDATE)\s+(leads|lead_|whatsapp_|automacao_|jornada_)/i', $fonte), 'motor não escreve em leads, WhatsApp nem automações');
} finally {
    foreach ($tmp as $t) {
        @unlink($t);
    }
    apagar_empresa_teste($empA);
    apagar_empresa_teste($empB);
}
resumo_e_sair();
