<?php
/**
 * POST /api/whatsapp.php  { acao, ... } — configuração do WhatsApp da empresa.
 * Permissão whatsapp_configurar; todo id é conferido contra a empresa.
 * O token nunca volta para o navegador.
 *
 * acao:
 *   conta_salvar        { nome?, waba_id, phone_number_id, access_token? }
 *   conta_modo_teste    { }   cria/ativa a conta só em MODO DE TESTE (sem credenciais; nada é enviado)
 *   conta_testar        { }   GET /{phone_number_id}
 *   conta_assinar       { }   POST /{waba_id}/subscribed_apps
 *   conta_status        { status: ativa|pausada }
 *   conta_envio_real    { ligar: 0|1, confirmacao }   desligar/ligar o modo de teste
 *   config_salvar       { envio_inicio, envio_fim, envio_dias[], max_automaticas_24h, max_marketing_7d, palavras_descadastro, resposta_sem_corretor }
 *   templates_sincronizar
 *   template_salvar     { id, finalidade, var[nome]=campo, acao[i]=acao }
 *   teste_enviar        { telefone, template_id? | texto?, autorizo }
 *   evento_reprocessar  { id }
 *   book_enviar         { empreendimento_id, arquivo (PDF) }
 *   book_remover        { empreendimento_id }
 *   boas_vindas_template{ empreendimento_id, template_id? }
 *   jornada_status      { id, status: ativa|pausada }          (gerenciar_jornadas)
 *   jornada_config      { id, origens[], template_id? }        (gerenciar_jornadas)
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/whatsapp.php';
require_once __DIR__ . '/../includes/automacao.php';
require_once __DIR__ . '/../includes/jornadas.php';
require_once __DIR__ . '/../includes/arquivos.php';

$usuario = api_iniciar('whatsapp_configurar');
$empresaId = (int) $usuario['empresa_ativa_id'];
$uid = (int) $usuario['id'];
$acao = (string) ($_POST['acao'] ?? '');
$pdo = db();

function wa_api_conta(int $empresaId): array
{
    $conta = wa_conta_da_empresa($empresaId);
    if (!$conta) {
        api_erro('Cadastre o WhatsApp da empresa primeiro.', 404);
    }
    return $conta;
}

function wa_api_hora(?string $v): ?string
{
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $v) ? $v . ':00' : null;
}

switch ($acao) {
    case 'conta_salvar':
        if (!cripto_disponivel()) {
            api_erro('A chave de cifragem (APP_KEY) não está configurada no servidor. Sem ela o sistema não guarda tokens.', 409);
        }
        $atual = wa_conta_da_empresa($empresaId);
        $waba = preg_replace('/\D/', '', (string) ($_POST['waba_id'] ?? ''));
        $pnid = preg_replace('/\D/', '', (string) ($_POST['phone_number_id'] ?? ''));
        if (strlen($waba) < 5 || strlen($waba) > 40 || strlen($pnid) < 5 || strlen($pnid) > 40) {
            api_erro('Informe os IDs numéricos da conta do WhatsApp Business (WABA) e do número de telefone.', 422);
        }
        $token = trim((string) ($_POST['access_token'] ?? ''));
        if ($token !== '' && (strlen($token) < 20 || strlen($token) > 2000 || preg_match('/\s/', $token))) {
            api_erro('Token inválido. Cole o token de acesso permanente, sem espaços.', 422);
        }
        if ((!$atual || !$atual['access_token_cifrado']) && $token === '') {
            api_erro('Cole o token de acesso.', 422);
        }
        $stmt = $pdo->prepare('SELECT empresa_id FROM whatsapp_contas WHERE phone_number_id = :p AND id <> :id');
        $stmt->execute(['p' => $pnid, 'id' => $atual['id'] ?? 0]);
        if (($dono = $stmt->fetchColumn()) !== false) {
            api_erro((int) $dono === $empresaId ? 'Este número já está cadastrado.' : 'Este número já está conectado a outra conta do WeMart Sales.', 409);
        }
        $nome = api_texto($_POST['nome'] ?? null, 120) ?? 'WhatsApp principal';
        $mudouNumero = $atual && ($atual['phone_number_id'] !== $pnid || $atual['waba_id'] !== $waba);
        if ($atual) {
            $sets = 'nome = :n, waba_id = :w, phone_number_id = :p, atualizado_em = NOW()';
            $params = ['n' => $nome, 'w' => $waba, 'p' => $pnid, 'id' => $atual['id']];
            if ($token !== '' || $mudouNumero) {
                // Credencial nova: precisa testar de novo; volta para o modo de teste por segurança.
                $sets .= ", token_validado_em = NULL, webhook_assinado_em = NULL, modo_teste = 1, status = IF(status = 'pausada', 'pausada', 'rascunho')";
            }
            if ($token !== '') {
                $sets .= ', access_token_cifrado = :t';
                $params['t'] = cripto_cifrar($token);
            }
            $pdo->prepare("UPDATE whatsapp_contas SET {$sets} WHERE id = :id")->execute($params);
            $id = (int) $atual['id'];
        } else {
            $pdo->prepare("INSERT INTO whatsapp_contas (empresa_id, nome, waba_id, phone_number_id, access_token_cifrado, status, modo_teste) VALUES (:e, :n, :w, :p, :t, 'rascunho', 1)")
                ->execute(['e' => $empresaId, 'n' => $nome, 'w' => $waba, 'p' => $pnid, 't' => cripto_cifrar($token)]);
            $id = (int) $pdo->lastInsertId();
        }
        auditoria_registrar($empresaId, $uid, $atual ? 'whatsapp.conta_editada' : 'whatsapp.conta_conectada', 'whatsapp_conta', $id, ['waba_id' => $waba, 'phone_number_id' => $pnid, 'token_trocado' => $token !== '']);
        api_responder(['ok' => true, 'mensagem' => 'Dados salvos. Agora clique em “Testar conexão”.']);

    case 'conta_modo_teste':
        $atual = wa_conta_da_empresa($empresaId);
        if ($atual) {
            $pdo->prepare("UPDATE whatsapp_contas SET modo_teste = 1, status = 'ativa', atualizado_em = NOW() WHERE id = :id")->execute(['id' => $atual['id']]);
            $id = (int) $atual['id'];
        } else {
            $pdo->prepare("INSERT INTO whatsapp_contas (empresa_id, nome, status, modo_teste) VALUES (:e, 'WhatsApp (modo de teste)', 'ativa', 1)")->execute(['e' => $empresaId]);
            $id = (int) $pdo->lastInsertId();
        }
        auditoria_registrar($empresaId, $uid, 'whatsapp.modo_teste_ativado', 'whatsapp_conta', $id, []);
        api_responder(['ok' => true, 'mensagem' => 'Modo de teste ativo: as mensagens são registradas como simuladas e nada é enviado.']);

    case 'conta_testar':
        $conta = wa_api_conta($empresaId);
        $r = wa_conta_testar($conta);
        auditoria_registrar($empresaId, $uid, $r['ok'] ? 'whatsapp.conta_testada' : 'whatsapp.conta_teste_falhou', 'whatsapp_conta', (int) $conta['id'], $r['ok'] ? [] : ['erro' => $r['mensagem']]);
        $r['ok'] ? api_responder(['ok' => true, 'mensagem' => $r['mensagem']]) : api_erro('Falhou: ' . $r['mensagem'], 502);

    case 'conta_assinar':
        $conta = wa_api_conta($empresaId);
        $r = wa_conta_assinar($conta);
        if ($r['ok']) {
            auditoria_registrar($empresaId, $uid, 'whatsapp.webhook_assinado', 'whatsapp_conta', (int) $conta['id'], []);
        }
        $r['ok'] ? api_responder(['ok' => true, 'mensagem' => $r['mensagem']]) : api_erro('Falhou: ' . $r['mensagem'], 502);

    case 'conta_status':
        $conta = wa_api_conta($empresaId);
        $novo = ($_POST['status'] ?? '') === 'pausada' ? 'pausada' : 'ativa';
        if ($novo === 'ativa' && !(int) $conta['modo_teste'] && !$conta['token_validado_em']) {
            api_erro('Teste a conexão antes de retomar o envio real.', 409);
        }
        $pdo->prepare('UPDATE whatsapp_contas SET status = :s, atualizado_em = NOW() WHERE id = :id')->execute(['s' => $novo, 'id' => $conta['id']]);
        auditoria_registrar($empresaId, $uid, $novo === 'pausada' ? 'whatsapp.conta_pausada' : 'whatsapp.conta_retomada', 'whatsapp_conta', (int) $conta['id'], []);
        api_responder(['ok' => true, 'mensagem' => $novo === 'pausada' ? 'WhatsApp pausado: nenhuma mensagem sai até ser retomado.' : 'WhatsApp retomado.']);

    case 'conta_envio_real':
        $conta = wa_api_conta($empresaId);
        $ligar = ($_POST['ligar'] ?? '') === '1';
        if ($ligar) {
            if (trim((string) ($_POST['confirmacao'] ?? '')) !== 'ENVIAR DE VERDADE') {
                api_erro('Para ligar o envio real, digite ENVIAR DE VERDADE no campo de confirmação.', 422);
            }
            if (!$conta['phone_number_id'] || !$conta['access_token_cifrado'] || !$conta['token_validado_em'] || $conta['status'] !== 'ativa') {
                api_erro('Antes do envio real: salve o número e o token e faça o “Testar conexão” com sucesso.', 409);
            }
            if (!$conta['webhook_assinado_em']) {
                api_erro('Antes do envio real: clique em “Ativar recebimento” para receber respostas e confirmações de entrega.', 409);
            }
        }
        $pdo->prepare('UPDATE whatsapp_contas SET modo_teste = :m, atualizado_em = NOW() WHERE id = :id')->execute(['m' => $ligar ? 0 : 1, 'id' => $conta['id']]);
        auditoria_registrar($empresaId, $uid, $ligar ? 'whatsapp.envio_real_ligado' : 'whatsapp.envio_real_desligado', 'whatsapp_conta', (int) $conta['id'], []);
        api_responder(['ok' => true, 'mensagem' => $ligar ? 'Envio real ligado: as mensagens passam a sair pelo WhatsApp oficial.' : 'Modo de teste ligado: nada mais é enviado de verdade.']);

    case 'config_salvar':
        $ini = wa_api_hora($_POST['envio_inicio'] ?? null);
        $fim = wa_api_hora($_POST['envio_fim'] ?? null);
        if (!$ini || !$fim || $ini === $fim) {
            api_erro('Informe o horário de envio (início e fim diferentes).', 422);
        }
        $dias = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['envio_dias'] ?? [])), fn ($d) => $d >= 1 && $d <= 7)));
        if (!$dias) {
            api_erro('Escolha pelo menos um dia de envio.', 422);
        }
        sort($dias);
        $max24 = max(1, min(5, (int) ($_POST['max_automaticas_24h'] ?? 2)));
        $max7 = max(0, min(7, (int) ($_POST['max_marketing_7d'] ?? 2)));
        $palavras = implode(',', array_filter(array_map(fn ($p) => mb_strtoupper(trim($p)), explode(',', (string) ($_POST['palavras_descadastro'] ?? ''))), fn ($p) => $p !== '' && mb_strlen($p) <= 30));
        if ($palavras === '') {
            api_erro('Informe pelo menos uma palavra de descadastro (ex.: PARAR).', 422);
        }
        $resposta = trim((string) ($_POST['resposta_sem_corretor'] ?? ''));
        $resposta = $resposta === '' ? null : mb_substr($resposta, 0, 1000);
        $pdo->prepare('INSERT INTO whatsapp_config (empresa_id, max_automaticas_24h, max_marketing_7d, envio_inicio, envio_fim, envio_dias, palavras_descadastro, resposta_sem_corretor, atualizado_em)
                       VALUES (:e, :a, :m, :i, :f, :d, :p, :r, NOW())
                       ON DUPLICATE KEY UPDATE max_automaticas_24h = VALUES(max_automaticas_24h), max_marketing_7d = VALUES(max_marketing_7d), envio_inicio = VALUES(envio_inicio),
                           envio_fim = VALUES(envio_fim), envio_dias = VALUES(envio_dias), palavras_descadastro = VALUES(palavras_descadastro),
                           resposta_sem_corretor = VALUES(resposta_sem_corretor), atualizado_em = NOW()')
            ->execute(['e' => $empresaId, 'a' => $max24, 'm' => $max7, 'i' => $ini, 'f' => $fim, 'd' => implode(',', $dias), 'p' => mb_substr($palavras, 0, 255), 'r' => $resposta]);
        auditoria_registrar($empresaId, $uid, 'whatsapp.regras_salvas', 'empresa', $empresaId, ['max_24h' => $max24, 'max_marketing_7d' => $max7]);
        api_responder(['ok' => true, 'mensagem' => 'Regras de envio salvas.']);

    case 'templates_sincronizar':
        $conta = wa_api_conta($empresaId);
        $r = wa_templates_sincronizar($conta);
        if ($r['ok']) {
            auditoria_registrar($empresaId, $uid, 'whatsapp.templates_sincronizados', 'whatsapp_conta', (int) $conta['id'], ['novos' => $r['novos'], 'atualizados' => $r['atualizados']]);
        }
        $r['ok'] ? api_responder(['ok' => true, 'mensagem' => $r['mensagem']]) : api_erro('Falhou: ' . $r['mensagem'], 502);

    case 'template_salvar':
        $tpl = wa_template($empresaId, (int) ($_POST['id'] ?? 0));
        if (!$tpl) {
            api_erro('Template não encontrado.', 404);
        }
        $finalidades = ['boas_vindas', 'follow_up', 'book', 'reengajamento', 'remarketing', 'outro'];
        $finalidade = in_array($_POST['finalidade'] ?? '', $finalidades, true) ? $_POST['finalidade'] : 'outro';
        $campos = wa_campos_template();
        $mapa = [];
        foreach (wa_variaveis_do_texto($tpl['corpo']) as $var) {
            $campo = (string) ($_POST['var'][$var] ?? '');
            if ($campo !== '' && !isset($campos[$campo])) {
                api_erro('Dado inválido para a variável {{' . $var . '}}.', 422);
            }
            if ($campo !== '') {
                $mapa[$var] = $campo;
            }
        }
        $botoes = wa_template_botoes($tpl);
        $acoes = wa_acoes_botao();
        foreach ($botoes as $i => &$b) {
            if (($b['tipo'] ?? '') === 'resposta') {
                $a = (string) ($_POST['acao_botao'][$i] ?? '');
                $b['acao'] = isset($acoes[$a]) ? $a : '';
            }
        }
        unset($b);
        if ($finalidade === 'remarketing' && $tpl['categoria'] !== 'marketing') {
            api_erro('Remarketing precisa de um template da categoria Marketing aprovado pela Meta.', 422);
        }
        $pdo->prepare('UPDATE whatsapp_templates SET finalidade = :f, variaveis = :v, botoes = :b, atualizado_em = NOW() WHERE id = :id AND empresa_id = :e')
            ->execute(['f' => $finalidade, 'v' => $mapa ? json_encode($mapa, JSON_UNESCAPED_UNICODE) : null,
                       'b' => $botoes ? json_encode($botoes, JSON_UNESCAPED_UNICODE) : null, 'id' => $tpl['id'], 'e' => $empresaId]);
        auditoria_registrar($empresaId, $uid, 'whatsapp.template_configurado', 'whatsapp_template', (int) $tpl['id'], ['finalidade' => $finalidade]);
        $faltando = array_diff(wa_variaveis_do_texto($tpl['corpo']), array_keys($mapa));
        api_responder(['ok' => true, 'mensagem' => $faltando ? 'Salvo, mas ainda falta ligar ' . count($faltando) . ' variável(is): o template não pode ser usado até lá.' : 'Template configurado.']);

    case 'teste_enviar':
        $conta = wa_api_conta($empresaId);
        if (($_POST['autorizo'] ?? '') !== '1') {
            api_erro('Confirme que o número é seu (ou de quem autorizou) e que aceita receber a mensagem de teste.', 422);
        }
        $tel = wa_normalizar_telefone((string) ($_POST['telefone'] ?? ''));
        if (!$tel) {
            api_erro('Telefone inválido. Use DDD + número, ex.: (11) 98888-7777.', 422);
        }
        $contato = wa_contato_obter($empresaId, '+' . $tel);
        $templateId = (int) ($_POST['template_id'] ?? 0);
        if ($templateId) {
            $tpl = wa_template($empresaId, $templateId);
            if (!$tpl) {
                api_erro('Template não encontrado.', 404);
            }
            if ($tpl['categoria'] === 'marketing') {
                api_erro('Para o teste use um template de Utilidade (ex.: hello_world ou boas-vindas).', 422);
            }
            // O próprio responsável autoriza receber o teste: fica registrado como evidência.
            if (!wa_consentimento_atual($empresaId, (int) $contato['id'], 'atendimento')['concedido']) {
                wa_consentimento_registrar($empresaId, (int) $contato['id'], 'atendimento', 'concedido', 'manual',
                    'Envio de teste autorizado por ' . $usuario['nome'] . ' (usuário ' . $uid . ') para número informado como próprio.', $uid);
            }
            $envio = ['tipo' => 'template', 'template_id' => $templateId, 'dados' => wa_dados_ficticios(wa_dados_lead($empresaId, null)['empresa.nome'] ?: 'Empresa')];
        } else {
            $texto = trim((string) ($_POST['texto'] ?? ''));
            if ($texto === '') {
                api_erro('Escolha um template ou escreva um texto.', 422);
            }
            $envio = ['tipo' => 'texto', 'texto' => mb_substr($texto, 0, 1000)];
        }
        $r = wa_enfileirar($empresaId, (int) $contato['id'], $envio, ['usuario_id' => $uid, 'idempotencia' => 'teste:' . $uid . ':' . bin2hex(random_bytes(6))]);
        if (!$r['ok']) {
            api_erro('Não enviada: ' . ($r['motivo'] ?? 'bloqueada pelas regras.') . (!empty($r['erros']) ? ' ' . implode(' ', $r['erros']) : ''), 422);
        }
        fila_executar_chave('wa_enviar:' . $r['mensagem_id'], automacao_tratadores());
        $stmt = $pdo->prepare('SELECT status, erro_texto FROM whatsapp_mensagens WHERE id = :id AND empresa_id = :e');
        $stmt->execute(['id' => $r['mensagem_id'], 'e' => $empresaId]);
        $m = $stmt->fetch();
        auditoria_registrar($empresaId, $uid, 'whatsapp.teste_enviado', 'whatsapp_mensagem', (int) $r['mensagem_id'], ['status' => $m['status']]);
        $rot = wa_status_mensagem()[$m['status']][0] ?? $m['status'];
        if (in_array($m['status'], ['falhou', 'cancelada'], true)) {
            api_erro('Não enviada: ' . ($m['erro_texto'] ?: $rot), 502);
        }
        api_responder(['ok' => true, 'mensagem' => $m['status'] === 'simulada'
            ? 'Mensagem SIMULADA (modo de teste): registrada, mas nada foi enviado.'
            : ($m['status'] === 'na_fila' ? 'Na fila: nova tentativa em instantes (' . ($m['erro_texto'] ?: 'aguardando') . ').' : 'Mensagem enviada pela Meta. Acompanhe a entrega abaixo.')]);

    case 'evento_reprocessar':
        $stmt = $pdo->prepare("SELECT id FROM whatsapp_webhook_eventos WHERE id = :id AND empresa_id = :e AND status IN ('erro','pendente')");
        $stmt->execute(['id' => (int) ($_POST['id'] ?? 0), 'e' => $empresaId]);
        $id = (int) $stmt->fetchColumn();
        if (!$id) {
            api_erro('Aviso não encontrado ou já processado.', 404);
        }
        $s = wa_processar_evento($id);
        api_responder(['ok' => true, 'mensagem' => 'Reprocessado: ' . (wa_status_evento()[$s][0] ?? $s) . '.']);

    case 'book_enviar':
    case 'book_remover':
    case 'boas_vindas_template':
        $empId = (int) ($_POST['empreendimento_id'] ?? 0);
        if (!api_registro_da_empresa('empreendimentos', $empId, $empresaId)) {
            api_erro('Empreendimento não encontrado.', 404);
        }
        $stmt = $pdo->prepare('SELECT nome, book_arquivo FROM empreendimentos WHERE id = :id');
        $stmt->execute(['id' => $empId]);
        $emp = $stmt->fetch();
        if ($acao === 'boas_vindas_template') {
            $tid = (int) ($_POST['template_id'] ?? 0) ?: null;
            if ($tid) {
                $tpl = wa_template($empresaId, $tid);
                if (!$tpl || $tpl['status'] !== 'aprovado') {
                    api_erro('Escolha um template aprovado pela Meta.', 422);
                }
                $render = wa_regra_renderizar($tpl['corpo'], wa_template_variaveis($tpl), wa_dados_ficticios('x'));
                if (!$render['ok']) {
                    api_erro('Este template ainda tem variáveis sem dado ligado (aba Templates).', 422);
                }
                if (in_array('corretor.nome', wa_template_variaveis($tpl), true) || in_array('corretor.primeiro_nome', wa_template_variaveis($tpl), true)) {
                    api_erro('A boas-vindas sai antes de existir corretor (ex.: 23h40): use um template sem o nome do corretor.', 422);
                }
            }
            $pdo->prepare('UPDATE empreendimentos SET wa_template_boas_vindas_id = :t WHERE id = :id AND empresa_id = :e')->execute(['t' => $tid, 'id' => $empId, 'e' => $empresaId]);
            auditoria_registrar($empresaId, $uid, 'whatsapp.boas_vindas_template', 'empreendimento', $empId, ['template_id' => $tid]);
            api_responder(['ok' => true, 'mensagem' => $tid ? 'Mensagem de boas-vindas definida para ' . $emp['nome'] . '.' : 'Boas-vindas removida deste empreendimento.']);
        }
        $antigo = $emp['book_arquivo'] ? wa_arquivo_permitido($emp['book_arquivo']) : null;
        if ($acao === 'book_remover') {
            $pdo->prepare('UPDATE empreendimentos SET book_arquivo = NULL, book_nome = NULL, book_tamanho = NULL, book_atualizado_em = NOW() WHERE id = :id')->execute(['id' => $empId]);
            if ($antigo && str_contains($antigo, '/storage/books/')) {
                @unlink($antigo);
            }
            auditoria_registrar($empresaId, $uid, 'whatsapp.book_removido', 'empreendimento', $empId, []);
            api_responder(['ok' => true, 'mensagem' => 'Book removido.']);
        }
        if (empty($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['arquivo']['tmp_name'])) {
            api_erro('Escolha o arquivo PDF do book.', 422);
        }
        $f = $_FILES['arquivo'];
        if ($f['size'] > 15 * 1024 * 1024) {
            api_erro('O book precisa ter até 15 MB (o WhatsApp abre mais rápido arquivos leves).', 422);
        }
        if ((new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) !== 'application/pdf') {
            api_erro('O book precisa ser um arquivo PDF.', 422);
        }
        $dir = __DIR__ . '/../storage/books';
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
            api_erro('Pasta de books indisponível no servidor.', 500);
        }
        $nomeArq = bin2hex(random_bytes(16)) . '.pdf';
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $nomeArq)) {
            api_erro('Não foi possível salvar o arquivo.', 500);
        }
        @chmod($dir . '/' . $nomeArq, 0640);
        $original = mb_substr(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', basename((string) $f['name'])) ?: 'book.pdf', 0, 190);
        $pdo->prepare('UPDATE empreendimentos SET book_arquivo = :a, book_nome = :n, book_tamanho = :t, book_atualizado_em = NOW() WHERE id = :id')
            ->execute(['a' => 'storage/books/' . $nomeArq, 'n' => $original, 't' => (int) $f['size'], 'id' => $empId]);
        if ($antigo && str_contains($antigo, '/storage/books/')) {
            @unlink($antigo);
        }
        auditoria_registrar($empresaId, $uid, 'whatsapp.book_enviado', 'empreendimento', $empId, ['nome' => $original, 'bytes' => (int) $f['size']]);
        api_responder(['ok' => true, 'mensagem' => 'Book salvo: é ele que o cliente recebe ao tocar em “Quero receber o book”.']);

    case 'jornada_status':
    case 'jornada_config':
        if (!usuario_tem_permissao($usuario, 'gerenciar_jornadas')) {
            api_erro('Sua função não pode alterar jornadas.', 403);
        }
        $j = jornada_carregar($empresaId, (int) ($_POST['id'] ?? 0));
        if (!$j) {
            api_erro('Jornada não encontrada.', 404);
        }
        if ($acao === 'jornada_status') {
            $novo = ($_POST['status'] ?? '') === 'ativa' ? 'ativa' : 'pausada';
            if ($novo === 'ativa' && !wa_conta_da_empresa($empresaId)) {
                api_erro('Conecte o WhatsApp (ou ligue o modo de teste) antes de ativar a jornada.', 409);
            }
            $pdo->prepare('UPDATE jornadas SET status = :s, atualizado_em = NOW() WHERE id = :id')->execute(['s' => $novo, 'id' => $j['id']]);
            if ($novo === 'ativa') {
                // Leads que ficaram parados enquanto a jornada estava pausada continuam de onde pararam.
                $stmt = $pdo->prepare("SELECT id, passo_chave FROM jornada_execucoes WHERE jornada_id = :j AND status = 'pausada'");
                $stmt->execute(['j' => $j['id']]);
                foreach ($stmt->fetchAll() as $x) {
                    $pdo->prepare("UPDATE jornada_execucoes SET status = 'ativa' WHERE id = :id")->execute(['id' => $x['id']]);
                    fila_agendar($empresaId, 'jornada_passo', ['execucao_id' => (int) $x['id'], 'passo' => $x['passo_chave']],
                                 'jornada:' . $x['id'] . ':' . $x['passo_chave'] . ':retomada:' . time(), null, 'jornada_execucao', (int) $x['id']);
                }
            }
            auditoria_registrar($empresaId, $uid, $novo === 'ativa' ? 'jornada.ativada' : 'jornada.pausada', 'jornada', (int) $j['id'], []);
            api_responder(['ok' => true, 'mensagem' => $novo === 'ativa' ? 'Jornada ativada: os próximos leads já recebem as boas-vindas.' : 'Jornada pausada: nenhum lead novo entra e os que estavam nela ficam parados.']);
        }
        $origensValidas = array_keys(lead_origens());
        $origens = array_values(array_intersect((array) ($_POST['origens'] ?? []), $origensValidas));
        if (!$origens) {
            api_erro('Escolha pelo menos uma origem de lead.', 422);
        }
        $cfg = $j['gatilho_config'];
        $cfg['origens'] = $origens;
        $pdo->prepare('UPDATE jornadas SET gatilho_config = :g, atualizado_em = NOW() WHERE id = :id')->execute(['g' => json_encode($cfg), 'id' => $j['id']]);
        $tid = (int) ($_POST['template_id'] ?? 0) ?: null;
        if ($tid && (!($tpl = wa_template($empresaId, $tid)) || $tpl['status'] !== 'aprovado')) {
            api_erro('Escolha um template aprovado.', 422);
        }
        $passo = jornada_passo((int) $j['id'], 'boas_vindas');
        if ($passo) {
            $pc = $passo['config'];
            $pc['template_id'] = $tid;
            $pdo->prepare('UPDATE jornada_passos SET config = :c WHERE id = :id')->execute(['c' => json_encode($pc), 'id' => $passo['id']]);
        }
        auditoria_registrar($empresaId, $uid, 'jornada.configurada', 'jornada', (int) $j['id'], ['origens' => $origens, 'template_id' => $tid]);
        api_responder(['ok' => true, 'mensagem' => 'Configuração da jornada salva.']);

    default:
        api_erro('Ação desconhecida.', 400);
}
