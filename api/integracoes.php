<?php
/**
 * POST /api/integracoes.php  { acao, ... } — Integrações (Meta Lead Ads).
 * Permissão gerenciar_integracoes; todo id é conferido contra a empresa.
 *
 * acao:
 *   pagina_salvar     { id?, page_id, nome?, access_token? }  (token obrigatório na criação)
 *   pagina_testar     { id }   GET /{page_id}?fields=name
 *   pagina_assinar    { id }   POST /{page_id}/subscribed_apps (subscribed_fields=leadgen)
 *   pagina_formularios{ id }   GET /{page_id}/leadgen_forms → grava/atualiza formulários
 *   pagina_ativar     { id, ativa }
 *   pagina_excluir    { id }
 *   form_salvar       { pagina_id, form_id, nome? }  (cadastro manual)
 *   form_mapear       { id, empreendimento_id?, ativo? }
 *   form_excluir      { id }
 *   evento_reprocessar{ id }
 *   form_consentimento{ id, campo?, texto? }  pergunta do formulário que autoriza contato pelo WhatsApp
 * O token da página nunca volta para o navegador.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/meta.php';

$usuario = api_iniciar('gerenciar_integracoes');
$empresaId = (int) $usuario['empresa_ativa_id'];
$uid = (int) $usuario['id'];
$acao = (string) ($_POST['acao'] ?? '');
$pdo = db();

function int_pagina(int $empresaId): array
{
    $pagina = meta_pagina($empresaId, (int) ($_POST['id'] ?? 0));
    if (!$pagina) {
        api_erro('Página não encontrada.', 404);
    }
    return $pagina;
}

function int_token(array $pagina): string
{
    $token = meta_pagina_token($pagina);
    if (!$token) {
        api_erro('Não foi possível ler o token desta página. Cadastre o token de novo.', 409);
    }
    return $token;
}

switch ($acao) {
    case 'pagina_salvar':
        if (!cripto_disponivel()) {
            api_erro('A chave de cifragem (APP_KEY) não está configurada no servidor. Sem ela o sistema não guarda tokens.', 409);
        }
        $id = (int) ($_POST['id'] ?? 0);
        $atual = $id ? meta_pagina($empresaId, $id) : null;
        if ($id && !$atual) {
            api_erro('Página não encontrada.', 404);
        }
        $pageId = preg_replace('/\D/', '', (string) ($_POST['page_id'] ?? ($atual['page_id'] ?? '')));
        if (strlen($pageId) < 5 || strlen($pageId) > 40) {
            api_erro('Informe o ID numérico da página do Facebook.', 422);
        }
        $nome = api_texto($_POST['nome'] ?? null);
        $token = trim((string) ($_POST['access_token'] ?? ''));
        if ($token !== '' && (strlen($token) < 20 || strlen($token) > 1000 || preg_match('/\s/', $token))) {
            api_erro('Token inválido. Cole o token de acesso da página, sem espaços.', 422);
        }
        if (!$atual && $token === '') {
            api_erro('Cole o token de acesso da página.', 422);
        }
        $stmt = $pdo->prepare('SELECT id, empresa_id FROM meta_paginas WHERE page_id = :p');
        $stmt->execute(['p' => $pageId]);
        $outra = $stmt->fetch();
        if ($outra && (int) $outra['id'] !== $id) {
            api_erro((int) $outra['empresa_id'] === $empresaId ? 'Esta página já está cadastrada.' : 'Esta página já está conectada a outra conta do WeMart Sales.', 409);
        }
        if ($atual) {
            $sets = 'page_id = :p, nome = COALESCE(:n, nome)';
            $params = ['p' => $pageId, 'n' => $nome, 'id' => $id, 'e' => $empresaId];
            if ($token !== '') {
                $sets .= ', access_token_cifrado = :t, token_validado_em = NULL';
                $params['t'] = cripto_cifrar($token);
            }
            $pdo->prepare("UPDATE meta_paginas SET {$sets} WHERE id = :id AND empresa_id = :e")->execute($params);
        } else {
            $pdo->prepare('INSERT INTO meta_paginas (empresa_id, page_id, nome, access_token_cifrado, ativa) VALUES (:e, :p, :n, :t, 1)')
                ->execute(['e' => $empresaId, 'p' => $pageId, 'n' => $nome, 't' => cripto_cifrar($token)]);
            $id = (int) $pdo->lastInsertId();
        }
        auditoria_registrar($empresaId, $uid, $atual ? 'meta.pagina_editada' : 'meta.pagina_conectada', 'meta_pagina', $id, ['page_id' => $pageId, 'token_trocado' => $token !== '']);
        api_responder(['ok' => true, 'id' => $id, 'mensagem' => 'Página salva. Agora clique em “Testar conexão”.']);

    case 'pagina_testar':
        $pagina = int_pagina($empresaId);
        $r = meta_graph('GET', $pagina['page_id'], ['fields' => 'id,name'], int_token($pagina));
        if (!$r['ok']) {
            auditoria_registrar($empresaId, $uid, 'meta.pagina_teste_falhou', 'meta_pagina', (int) $pagina['id'], ['erro' => $r['erro']]);
            api_erro('Falhou: ' . $r['erro'], 502);
        }
        $nome = api_texto($r['data']['name'] ?? null);
        $pdo->prepare('UPDATE meta_paginas SET token_validado_em = NOW(), nome = COALESCE(:n, nome) WHERE id = :id')->execute(['n' => $nome, 'id' => $pagina['id']]);
        auditoria_registrar($empresaId, $uid, 'meta.pagina_testada', 'meta_pagina', (int) $pagina['id'], []);
        api_responder(['ok' => true, 'mensagem' => 'Conexão OK com a página "' . ($nome ?? $pagina['page_id']) . '".']);

    case 'pagina_assinar':
        $pagina = int_pagina($empresaId);
        $r = meta_graph('POST', $pagina['page_id'] . '/subscribed_apps', ['subscribed_fields' => 'leadgen'], int_token($pagina));
        if (!$r['ok'] || empty($r['data']['success'])) {
            api_erro('Não foi possível assinar a página: ' . ($r['erro'] ?? 'a Meta não confirmou.'), 502);
        }
        auditoria_registrar($empresaId, $uid, 'meta.pagina_assinada', 'meta_pagina', (int) $pagina['id'], []);
        api_responder(['ok' => true, 'mensagem' => 'Página assinada: novos cadastros dos formulários passam a chegar aqui.']);

    case 'pagina_formularios':
        $pagina = int_pagina($empresaId);
        $r = meta_graph('GET', $pagina['page_id'] . '/leadgen_forms', ['fields' => 'id,name,status', 'limit' => 100], int_token($pagina));
        if (!$r['ok']) {
            api_erro('Não foi possível buscar os formulários: ' . $r['erro'], 502);
        }
        $novos = 0;
        $atualizados = 0;
        $outros = 0;
        foreach (array_slice((array) ($r['data']['data'] ?? []), 0, 200) as $f) {
            $formId = preg_replace('/\D/', '', (string) ($f['id'] ?? ''));
            if ($formId === '' || strlen($formId) > 40) {
                continue;
            }
            $nomeForm = mb_substr(trim((string) ($f['name'] ?? '')), 0, 190) ?: null;
            $stmt = $pdo->prepare('SELECT id, empresa_id FROM meta_formularios WHERE form_id = :f');
            $stmt->execute(['f' => $formId]);
            $ex = $stmt->fetch();
            if ($ex && (int) $ex['empresa_id'] !== $empresaId) {
                $outros++;
                continue;
            }
            if ($ex) {
                $pdo->prepare('UPDATE meta_formularios SET nome = COALESCE(:n, nome), meta_pagina_id = :p WHERE id = :id')->execute(['n' => $nomeForm, 'p' => $pagina['id'], 'id' => $ex['id']]);
                $atualizados++;
            } else {
                $pdo->prepare('INSERT INTO meta_formularios (empresa_id, meta_pagina_id, form_id, nome, ativo) VALUES (:e, :p, :f, :n, :a)')
                    ->execute(['e' => $empresaId, 'p' => $pagina['id'], 'f' => $formId, 'n' => $nomeForm, 'a' => strtoupper((string) ($f['status'] ?? 'ACTIVE')) === 'ACTIVE' ? 1 : 0]);
                $novos++;
            }
        }
        auditoria_registrar($empresaId, $uid, 'meta.formularios_buscados', 'meta_pagina', (int) $pagina['id'], ['novos' => $novos, 'atualizados' => $atualizados]);
        api_responder(['ok' => true, 'mensagem' => $novos . ' formulário(s) novo(s), ' . $atualizados . ' atualizado(s)' . ($outros ? ', ' . $outros . ' ignorado(s) (de outra conta)' : '') . '. Escolha o empreendimento de cada um.']);

    case 'pagina_ativar':
        $pagina = int_pagina($empresaId);
        $ativa = !empty($_POST['ativa']) ? 1 : 0;
        $pdo->prepare('UPDATE meta_paginas SET ativa = :a WHERE id = :id')->execute(['a' => $ativa, 'id' => $pagina['id']]);
        auditoria_registrar($empresaId, $uid, $ativa ? 'meta.pagina_ativada' : 'meta.pagina_desativada', 'meta_pagina', (int) $pagina['id'], []);
        api_responder(['ok' => true, 'mensagem' => $ativa ? 'Página ativada.' : 'Página desativada: novos avisos dela serão ignorados.']);

    case 'pagina_excluir':
        $pagina = int_pagina($empresaId);
        $pdo->prepare('DELETE FROM meta_paginas WHERE id = :id AND empresa_id = :e')->execute(['id' => $pagina['id'], 'e' => $empresaId]);
        auditoria_registrar($empresaId, $uid, 'meta.pagina_removida', 'meta_pagina', (int) $pagina['id'], ['page_id' => $pagina['page_id']]);
        api_responder(['ok' => true, 'mensagem' => 'Página removida (o token foi apagado). Os leads já recebidos continuam no sistema.']);

    case 'form_salvar':
        $pagina = meta_pagina($empresaId, (int) ($_POST['pagina_id'] ?? 0));
        if (!$pagina) {
            api_erro('Página não encontrada.', 404);
        }
        $formId = preg_replace('/\D/', '', (string) ($_POST['form_id'] ?? ''));
        if (strlen($formId) < 5 || strlen($formId) > 40) {
            api_erro('Informe o ID numérico do formulário.', 422);
        }
        $empId = (int) ($_POST['empreendimento_id'] ?? 0) ?: null;
        if ($empId && !api_registro_da_empresa('empreendimentos', $empId, $empresaId)) {
            api_erro('Empreendimento não encontrado.', 404);
        }
        $stmt = $pdo->prepare('SELECT empresa_id FROM meta_formularios WHERE form_id = :f');
        $stmt->execute(['f' => $formId]);
        if (($dono = $stmt->fetchColumn()) !== false) {
            api_erro((int) $dono === $empresaId ? 'Este formulário já está cadastrado.' : 'Este formulário pertence a outra conta.', 409);
        }
        $pdo->prepare('INSERT INTO meta_formularios (empresa_id, meta_pagina_id, form_id, nome, empreendimento_id, ativo) VALUES (:e, :p, :f, :n, :emp, 1)')
            ->execute(['e' => $empresaId, 'p' => $pagina['id'], 'f' => $formId, 'n' => api_texto($_POST['nome'] ?? null), 'emp' => $empId]);
        $id = (int) $pdo->lastInsertId();
        auditoria_registrar($empresaId, $uid, 'meta.formulario_cadastrado', 'meta_formulario', $id, ['form_id' => $formId, 'empreendimento_id' => $empId]);
        api_responder(['ok' => true, 'mensagem' => 'Formulário cadastrado.']);

    case 'form_mapear':
    case 'form_excluir':
        $stmt = $pdo->prepare('SELECT * FROM meta_formularios WHERE id = :id AND empresa_id = :e');
        $stmt->execute(['id' => (int) ($_POST['id'] ?? 0), 'e' => $empresaId]);
        $form = $stmt->fetch();
        if (!$form) {
            api_erro('Formulário não encontrado.', 404);
        }
        if ($acao === 'form_excluir') {
            $pdo->prepare('DELETE FROM meta_formularios WHERE id = :id')->execute(['id' => $form['id']]);
            auditoria_registrar($empresaId, $uid, 'meta.formulario_removido', 'meta_formulario', (int) $form['id'], ['form_id' => $form['form_id']]);
            api_responder(['ok' => true, 'mensagem' => 'Formulário removido. Novos leads dele entram sem empreendimento.']);
        }
        $empId = (int) ($_POST['empreendimento_id'] ?? 0) ?: null;
        if ($empId && !api_registro_da_empresa('empreendimentos', $empId, $empresaId)) {
            api_erro('Empreendimento não encontrado.', 404);
        }
        $ativo = isset($_POST['ativo']) ? (!empty($_POST['ativo']) ? 1 : 0) : (int) $form['ativo'];
        $pdo->prepare('UPDATE meta_formularios SET empreendimento_id = :emp, ativo = :a WHERE id = :id')->execute(['emp' => $empId, 'a' => $ativo, 'id' => $form['id']]);
        auditoria_registrar($empresaId, $uid, 'meta.formulario_mapeado', 'meta_formulario', (int) $form['id'], ['empreendimento_id' => $empId, 'ativo' => $ativo]);
        api_responder(['ok' => true, 'mensagem' => 'Formulário atualizado.']);

    case 'form_consentimento':
        $stmt = $pdo->prepare('SELECT id FROM meta_formularios WHERE id = :id AND empresa_id = :e');
        $stmt->execute(['id' => (int) ($_POST['id'] ?? 0), 'e' => $empresaId]);
        $fid = (int) $stmt->fetchColumn();
        if (!$fid) {
            api_erro('Formulário não encontrado.', 404);
        }
        $campo = mb_strtolower(trim((string) ($_POST['campo'] ?? '')));
        if ($campo !== '' && !preg_match('/^[\p{L}\p{N}_ ?.,:\-]{2,100}$/u', $campo)) {
            api_erro('Informe o nome da pergunta exatamente como aparece no formulário do Meta.', 422);
        }
        $texto = api_texto($_POST['texto'] ?? null, 500);
        if ($campo !== '' && !$texto) {
            api_erro('Cole o texto do aviso de autorização que o cliente vê no formulário (fica guardado como evidência).', 422);
        }
        $pdo->prepare('UPDATE meta_formularios SET wa_consentimento_campo = :c, wa_consentimento_texto = :t WHERE id = :id')
            ->execute(['c' => $campo !== '' ? $campo : null, 't' => $campo !== '' ? $texto : null, 'id' => $fid]);
        auditoria_registrar($empresaId, $uid, 'meta.form_consentimento', 'meta_formulario', $fid, ['campo' => $campo]);
        api_responder(['ok' => true, 'mensagem' => $campo !== '' ? 'Pronto: quem responder "Sim" nesta pergunta fica autorizado a receber WhatsApp.' : 'Autorização pelo formulário desligada.']);

    case 'evento_reprocessar':
        $stmt = $pdo->prepare('SELECT id, status FROM meta_eventos WHERE id = :id AND empresa_id = :e');
        $stmt->execute(['id' => (int) ($_POST['id'] ?? 0), 'e' => $empresaId]);
        $ev = $stmt->fetch();
        if (!$ev) {
            api_erro('Evento não encontrado.', 404);
        }
        if (!in_array($ev['status'], ['erro', 'pendente'], true)) {
            api_erro('Só eventos com erro ou pendentes podem ser reprocessados.', 409);
        }
        $pdo->prepare("UPDATE meta_eventos SET status = 'pendente', tentativas = 0, proxima_tentativa_em = NULL WHERE id = :id")->execute(['id' => $ev['id']]);
        auditoria_registrar($empresaId, $uid, 'meta.evento_reprocessado', 'meta_evento', (int) $ev['id'], []);
        $status = meta_processar_evento((int) $ev['id'], true);
        $stmt = $pdo->prepare('SELECT erro FROM meta_eventos WHERE id = :id');
        $stmt->execute(['id' => $ev['id']]);
        $msg = [
            'processado' => 'Lead criado e enviado para a roleta.',
            'duplicado' => 'Já existia: registrado no lead existente.',
            'ocupado' => 'O evento já está sendo processado. Atualize em instantes.',
        ][$status] ?? ('Ainda com erro: ' . $stmt->fetchColumn());
        api_responder(['ok' => $status !== 'erro', 'mensagem' => $msg, 'erro' => $status === 'erro' ? $msg : null], $status === 'erro' ? 502 : 200);

    default:
        api_erro('Ação inválida.', 422);
}
