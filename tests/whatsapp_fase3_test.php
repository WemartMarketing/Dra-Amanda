<?php
/**
 * Testes da FASE 3 — central de conversas: quem vê o quê, transferência,
 * abrir conversa pelo lead, não lidas, botão "Conversar no WhatsApp".
 * Empresa fictícia em modo de teste; nada é enviado para a Meta.
 *
 *   php tests/whatsapp_fase3_test.php
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../includes/whatsapp_conversas.php';
require_once __DIR__ . '/../includes/automacao.php';
require_once __DIR__ . '/../includes/funcoes.php';

/** Monta o usuário como o login monta (current_user). */
function usuario_teste(int $id): array
{
    $u = db()->query("SELECT * FROM usuarios WHERE id = $id")->fetch();
    $u['empresa_ativa_id'] = $u['empresa_id'];
    $u['eh_superadmin'] = !empty($u['eh_superadmin']);
    $f = db()->query('SELECT nome, escopo FROM funcoes WHERE id = ' . (int) $u['funcao_id'])->fetch();
    $u['escopo'] = $f['escopo'] ?? null;
    $u['funcao_nome'] = $f['nome'] ?? '';
    $u['permissoes'] = db()->query('SELECT permissao_chave FROM funcao_permissoes WHERE funcao_id = ' . (int) $u['funcao_id'])->fetchAll(PDO::FETCH_COLUMN);
    return $u;
}
function ids(array $lista): array
{
    $v = array_map(fn ($c) => (int) $c['id'], $lista);
    sort($v);
    return $v;
}

$emp = criar_empresa_teste('Conversas');
$emp2 = criar_empresa_teste('Outra');
try {
    $pdo = db();
    $f = funcoes_seed_padrao($emp);
    $f2 = funcoes_seed_padrao($emp2);
    $novoUsuario = function (int $empresa, int $funcao, string $nome, string $cargo) use ($pdo) {
        $pdo->prepare('INSERT INTO usuarios (empresa_id, funcao_id, nome, email, senha_hash, cargo) VALUES (:e, :f, :n, :m, :h, :c)')
            ->execute(['e' => $empresa, 'f' => $funcao, 'n' => $nome, 'm' => bin2hex(random_bytes(5)) . '@teste.invalid', 'h' => 'x', 'c' => $cargo]);
        return (int) $pdo->lastInsertId();
    };
    $master = $novoUsuario($emp, $f['master_empresa'], 'Master Teste', 'master_empresa');
    $gerente = $novoUsuario($emp, $f['gerente'], 'Gerente Teste', 'gerente');
    $cor1 = $novoUsuario($emp, $f['corretor'], 'Corretor Um', 'corretor');
    $cor2 = $novoUsuario($emp, $f['corretor'], 'Corretor Dois', 'corretor');
    $corOutra = $novoUsuario($emp2, $f2['corretor'], 'Corretor Outra Empresa', 'corretor');
    $pdo->prepare('INSERT INTO equipes (empresa_id, nome, gerente_id) VALUES (:e, :n, :g)')->execute(['e' => $emp, 'n' => 'Equipe A', 'g' => $gerente]);
    $eqA = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO equipes (empresa_id, nome) VALUES (:e, :n)')->execute(['e' => $emp, 'n' => 'Equipe B']);
    $eqB = (int) $pdo->lastInsertId();
    $lead = function (string $nome, ?string $tel, ?int $resp, ?int $eq, int $empresa = 0) use ($pdo, $emp) {
        $pdo->prepare('INSERT INTO leads (empresa_id, nome, telefone, responsavel_id, equipe_id) VALUES (:e, :n, :t, :r, :q)')
            ->execute(['e' => $empresa ?: $emp, 'n' => $nome, 't' => $tel, 'r' => $resp, 'q' => $eq]);
        return (int) $pdo->lastInsertId();
    };
    $L1 = $lead('Cliente do Um', '(11) 91111-1111', $cor1, $eqA);
    $L2 = $lead('Cliente do Dois', '(11) 92222-2222', $cor2, $eqB);
    $L3 = $lead('Cliente sem telefone', null, $cor1, $eqA);
    $L4 = $lead('Cliente que já escreveu', '(11) 94444-4444', $cor1, $eqA);
    $pdo->prepare("INSERT INTO whatsapp_contas (empresa_id, nome, phone_number_id, status, modo_teste) VALUES (:e, 'Teste', :p, 'ativa', 1)")
        ->execute(['e' => $emp, 'p' => (string) random_int(10 ** 9, 10 ** 10)]);
    $conta = wa_conta_da_empresa($emp);
    $uMaster = usuario_teste($master);
    $uGer = usuario_teste($gerente);
    $u1 = usuario_teste($cor1);
    $u2 = usuario_teste($cor2);
    $uOutra = usuario_teste($corOutra);

    // Mensagens chegando (mesma rotina do webhook).
    $chega = function (string $de, string $texto) use ($emp, $conta) {
        $id = 'wamid.' . bin2hex(random_bytes(8));
        return wa_evento_mensagem($emp, (int) $conta['id'], ['from' => $de, 'id' => $id, 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => $texto]], 'Perfil');
    };
    $chega('5511911111111', 'Oi, quero saber do apartamento');
    $chega('5511922222222', 'Bom dia');
    $chega('5511955555555', 'Número que não é lead');
    $cv = fn (string $tel) => (int) $pdo->query("SELECT cv.id FROM whatsapp_conversas cv JOIN whatsapp_contatos ct ON ct.id = cv.contato_id WHERE cv.empresa_id = $emp AND ct.telefone = '$tel'")->fetchColumn();
    $C1 = $cv('5511911111111');
    $C2 = $cv('5511922222222');
    $C5 = $cv('5511955555555');

    grupo('Quem vê cada conversa');
    igual(ids(wa_conversas_listar($u1)), [$C1], 'corretor 1 vê só a conversa do lead dele');
    igual(ids(wa_conversas_listar($u2)), [$C2], 'corretor 2 vê só a conversa do lead dele');
    igual(ids(wa_conversas_listar($uGer)), [$C1], 'gerente da Equipe A vê só as conversas da equipe dele');
    igual(ids(wa_conversas_listar($uMaster)), array_values(array_unique([$C1, $C2, $C5])), 'Master vê todas, inclusive a de número sem lead');
    igual(wa_conversa_carregar($u1, $C2), null, 'corretor 1 não abre a conversa do corretor 2 (nem pelo número)');
    igual(wa_conversa_carregar($u1, $C5), null, 'corretor não vê conversa sem lead');
    igual(wa_conversas_listar($uOutra), [], 'usuário de outra empresa não vê nada');
    igual(wa_conversa_carregar($uOutra, $C1), null, 'nem abrindo pelo número da conversa');
    ok(wa_conversa_carregar($uMaster, $C5) !== null, 'Master abre a conversa sem lead');

    grupo('Filtros e busca');
    igual(ids(wa_conversas_listar($uMaster, '', 'sem_lead')), [$C5], 'filtro "Sem lead"');
    igual(ids(wa_conversas_listar($uMaster, '', 'aguardando')), ids(wa_conversas_listar($uMaster)), 'todas aguardando resposta (só o cliente escreveu)');
    igual(ids(wa_conversas_listar($uMaster, 'Cliente do Dois')), [$C2], 'busca pelo nome do lead');
    igual(ids(wa_conversas_listar($uMaster, '9555')), [$C5], 'busca pelos dígitos do telefone');

    grupo('Não lidas (selo do menu)');
    igual(wa_conversas_nao_lidas($u1), 1, 'corretor 1: 1 conversa com mensagem nova');
    igual(wa_conversas_nao_lidas($uMaster), 3, 'Master: 3 conversas com mensagem nova');
    wa_conversa_marcar_lida($emp, $C1);
    igual(wa_conversas_nao_lidas($u1), 0, 'abrir a conversa zera as não lidas');
    $chega('5511911111111', 'Qual é o valor da entrada?');
    igual(wa_conversas_nao_lidas($u1), 1, 'nova mensagem do cliente volta a acender o selo');

    grupo('Mensagens: carga inicial e só as novas');
    $todas = wa_conversa_mensagens($emp, $C1);
    igual(count($todas), 2, 'duas mensagens na conversa 1');
    $ultima = (int) end($todas)['id'];
    igual(wa_conversa_mensagens($emp, $C1, $ultima), [], 'polling sem novidade devolve vazio');
    $chega('5511911111111', 'Pode me ligar?');
    igual(count(wa_conversa_mensagens($emp, $C1, $ultima)), 1, 'polling devolve só a mensagem nova');
    igual(wa_conversa_mensagens($emp2, $C1), [], 'outra empresa não lê as mensagens pelo id');

    grupo('Resposta do corretor (modo de teste)');
    $contato1 = (int) $pdo->query("SELECT contato_id FROM whatsapp_conversas WHERE id = $C1")->fetchColumn();
    $r = wa_enfileirar($emp, $contato1, ['tipo' => 'texto', 'texto' => 'Oi! Já te ligo.'], ['usuario_id' => $cor1, 'lead_id' => $L1]);
    fila_executar_chave('wa_enviar:' . $r['mensagem_id'], automacao_tratadores());
    $m = $pdo->query("SELECT status, enviada_por FROM whatsapp_mensagens WHERE id = {$r['mensagem_id']}")->fetch();
    ok($m['status'] === 'simulada' && (int) $m['enviada_por'] === $cor1, 'resposta registrada com o nome de quem enviou (simulada em modo de teste)');
    ok($pdo->query("SELECT humano_assumiu_em FROM whatsapp_conversas WHERE id = $C1")->fetchColumn() !== null, 'conversa marcada como "atendimento humano assumiu"');
    igual(ids(wa_conversas_listar($u1, '', 'aguardando')), [], 'depois de responder, sai do filtro "Aguardando você"');

    grupo('Transferência do lead leva a conversa junto');
    $antes = count(wa_conversa_mensagens($emp, $C1));
    $pdo->exec("UPDATE leads SET responsavel_id = $cor2, equipe_id = $eqB WHERE id = $L1");
    igual(wa_conversa_carregar($u1, $C1), null, 'corretor anterior perde o acesso na hora');
    ok(wa_conversa_carregar($u2, $C1) !== null, 'novo corretor passa a ver a conversa');
    igual(count(wa_conversa_mensagens($emp, $C1)), $antes, 'histórico completo preservado para o novo corretor');
    igual(wa_conversa_carregar($uGer, $C1), null, 'gerente da equipe anterior também perde o acesso');
    $pdo->exec("UPDATE leads SET responsavel_id = $cor1, equipe_id = $eqA WHERE id = $L1");

    grupo('Botão "Conversar no WhatsApp" na ficha/cards');
    $r = wa_conversa_do_lead($u1, $L4);
    ok($r['ok'] && $r['conversa_id'] > 0, 'lead sem conversa → conversa criada para iniciar o contato');
    $r2 = wa_conversa_do_lead($u1, $L4);
    igual($r2['conversa_id'], $r['conversa_id'], 'segundo clique abre a mesma conversa');
    igual(wa_conversa_do_lead($u1, $L2)['ok'], false, 'lead de outro corretor → recusado');
    igual(wa_conversa_do_lead($u1, $L3)['erro'] ?? null, 'O lead não tem um telefone válido para WhatsApp.', 'lead sem telefone → mensagem clara');
    // Cliente que escreveu ANTES de virar lead, de um número antigo sem o 9.
    $chega('551193333333', 'Vi o anúncio');
    $L5 = $lead('Cliente novo', '(11) 99333-3333', $cor1, $eqA);
    $cAntes = $cv('551193333333');
    ok(wa_conversa_carregar($u1, $cAntes) === null, 'antes: conversa sem lead (corretor não vê)');
    $r = wa_conversa_do_lead($u1, $L5);
    igual($r['conversa_id'], $cAntes, 'ao abrir pelo lead, reaproveita a conversa do número (com/sem o 9) e liga ao lead');
    ok(wa_conversa_carregar($u1, $cAntes) !== null, 'depois: o corretor vê o que o cliente escreveu antes');

    grupo('Link do botão');
    $l = wa_link_conversar($u1, ['id' => $L1, 'telefone' => '(11) 91111-1111']);
    ok($l['interno'] && $l['href'] === '/pages/conversas.php?lead=' . $L1, 'empresa com WhatsApp ligado → abre a conversa dentro do WeMart');
    ok(!str_contains(wa_link_atributos($l, $L1), 'target='), 'link interno não abre outra aba');
    $l = wa_link_conversar($uOutra, ['id' => 1, 'telefone' => '(11) 91111-1111']);
    ok(!$l['interno'] && str_starts_with($l['href'], 'https://wa.me/55'), 'empresa sem WhatsApp conectado → continua o wa.me de antes');
    $semAtender = $u1;
    $semAtender['permissoes'] = array_values(array_diff($u1['permissoes'], ['whatsapp_atender']));
    ok(!wa_link_conversar($semAtender, ['id' => $L1, 'telefone' => '11911111111'])['interno'], 'função sem "conversar pelo WhatsApp" → wa.me');

    grupo('Templates para o chat');
    $pdo->prepare("INSERT INTO whatsapp_templates (empresa_id, conta_id, nome, categoria, status, corpo, variaveis) VALUES (:e, :c, 'retomar', 'utility', 'aprovado', 'Oi, {{1}}! Ainda tem interesse no {{2}}?', :v)")
        ->execute(['e' => $emp, 'c' => $conta['id'], 'v' => json_encode(['1' => 'lead.primeiro_nome', '2' => 'empreendimento.nome'])]);
    $pdo->prepare("INSERT INTO whatsapp_templates (empresa_id, conta_id, nome, categoria, status, corpo) VALUES (:e, :c, 'pendente_x', 'utility', 'pendente', 'x')")
        ->execute(['e' => $emp, 'c' => $conta['id']]);
    $t = wa_templates_para_chat($emp, wa_dados_lead($emp, $L1));
    igual(count($t), 1, 'só templates aprovados aparecem');
    ok(!$t[0]['pronto'], 'lead sem empreendimento → template não fica "pronto" (não manda {{2}} vazio)');
    $pdo->prepare('INSERT INTO empreendimentos (empresa_id, nome) VALUES (:e, :n)')->execute(['e' => $emp, 'n' => 'Vila Teste']);
    $pdo->exec('UPDATE leads SET empreendimento_id = ' . (int) $pdo->lastInsertId() . " WHERE id = $L1");
    $t = wa_templates_para_chat($emp, wa_dados_lead($emp, $L1));
    ok($t[0]['pronto'] && $t[0]['previa'] === 'Oi, Cliente! Ainda tem interesse no Vila Teste?', 'com o empreendimento, prévia com os dados reais do lead');

    grupo('Arquivos');
    igual(wa_arquivo_permitido('storage/../includes/config.php'), null, 'caminho com ".." é recusado');
    igual(wa_arquivo_permitido('includes/config.php'), null, 'arquivo fora de storage/ e uploads/ é recusado');
    $msgSemArquivo = $pdo->query("SELECT * FROM whatsapp_mensagens WHERE conversa_id = $C1 AND direcao = 'entrada' LIMIT 1")->fetch();
    igual(wa_midia_local($msgSemArquivo, $conta)['ok'], false, 'mensagem sem arquivo → nada a baixar');
    $msgSemArquivo['midia_id_meta'] = 'MIDIA123';
    ok(str_contains(wa_midia_local($msgSemArquivo, $conta)['erro'], 'modo de teste'), 'em modo de teste não baixa nada da Meta');
} finally {
    db()->exec("DELETE FROM whatsapp_webhook_eventos WHERE empresa_id IN ($emp, $emp2)");
    apagar_empresa_teste($emp);
    apagar_empresa_teste($emp2);
    grupo('Limpeza');
    igual((int) db()->query("SELECT COUNT(*) FROM empresas WHERE nome LIKE 'Teste automatizado%'")->fetchColumn(), 0, 'nenhum dado de teste ficou no banco');
}
resumo_e_sair();
