<?php
/**
 * Administração das Jornadas Inteligentes (Fase 7): definição completa de
 * uma jornada (gatilho + blocos conectados), validação, salvar, duplicar,
 * mudar status e SIMULAR com dados fictícios — sem tocar no banco nem
 * enviar nada.
 *
 * Formato da definição (o mesmo que o editor visual manda):
 *   nome, descricao, gatilho (novo_lead|mudanca_etapa|lead_transferido),
 *   gatilho_config {origens[], etapa_id?, parar_quando_responder, somente_categorias[]},
 *   empreendimento_id?, max_mensagens,
 *   passos: [{chave, tipo, titulo, config{}, proximo_chave, senao_chave}] (em ordem)
 *
 * Regras de segurança do fluxo: sem voltas (um bloco nunca leva a um bloco
 * já percorrido — cada passo roda no máximo uma vez por lead), limite de
 * blocos e de mensagens, só templates aprovados da própria empresa.
 */
require_once __DIR__ . '/jornadas.php';
require_once __DIR__ . '/funil.php';

const JORNADA_MAX_PASSOS = 30;

/** Blocos disponíveis no editor (os que o motor executa). */
function jornada_tipos_bloco(): array
{
    return [
        'enviar_template'       => ['Mensagem', 'Envia um template aprovado pelo WhatsApp'],
        'enviar_documento'      => ['Book', 'Envia o book (PDF) do empreendimento'],
        'esperar'               => ['Espera', 'Aguarda um tempo antes do próximo bloco'],
        'condicao'              => ['Condição', 'Segue por um caminho ou outro'],
        'confirmar_atendimento' => ['Pergunta ao corretor', '“O cliente respondeu à sua tentativa?”'],
        'criar_tarefa'          => ['Tarefa', 'Cria uma tarefa na central de pendências do corretor'],
        'notificar_corretor'    => ['Aviso ao corretor', 'Avisa o corretor na central de pendências'],
        'atualizar_etapa'       => ['Mudar etapa', 'Move o lead para uma etapa do Kanban'],
        'encaminhar_fila'       => ['Encaminhar para a roleta', 'Lead sem corretor vai para a roleta / fila de espera'],
        'encerrar'              => ['Fim', 'Encerra a jornada'],
    ];
}

function jornada_gatilhos(): array
{
    return ['novo_lead' => 'Lead novo chega', 'mudanca_etapa' => 'Lead muda de etapa', 'lead_transferido' => 'Lead é transferido para outro corretor'];
}

function jornada_regras_condicao(): array
{
    return [
        'cliente_respondeu' => 'o cliente respondeu no WhatsApp',
        'humano_respondeu'  => 'alguém respondeu o cliente pelo WhatsApp',
        'corretor_confirmou'=> 'o corretor confirmou que o cliente respondeu',
        'houve_tentativa'   => 'o corretor registrou tentativa de contato',
        'tem_responsavel'   => 'o lead tem corretor',
    ];
}

/** Definição de uma jornada salva (para o editor e para a simulação). */
function jornada_definicao(int $empresaId, int $id): ?array
{
    $j = jornada_carregar($empresaId, $id);
    if (!$j) {
        return null;
    }
    $stmt = db()->prepare('SELECT chave, tipo, titulo, config, proximo_chave, senao_chave FROM jornada_passos WHERE jornada_id = :j ORDER BY ordem, id');
    $stmt->execute(['j' => $id]);
    $passos = [];
    foreach ($stmt->fetchAll() as $p) {
        $p['config'] = json_decode((string) $p['config'], true) ?: new stdClass();
        $passos[] = $p;
    }
    return [
        'id' => (int) $j['id'], 'nome' => $j['nome'], 'descricao' => (string) $j['descricao'], 'status' => $j['status'],
        'gatilho' => $j['gatilho'], 'gatilho_config' => $j['gatilho_config'] ?: new stdClass(),
        'empreendimento_id' => $j['empreendimento_id'] ? (int) $j['empreendimento_id'] : null,
        'max_mensagens' => (int) $j['max_mensagens'], 'versao' => (int) $j['versao'], 'passos' => $passos,
    ];
}

/**
 * Valida e normaliza a definição. Devolve ['ok' => true, 'def' => ..., 'avisos' => [...]]
 * ou ['ok' => false, 'erros' => [...]] — mensagens para a pessoa, em português simples.
 */
function jornada_validar_definicao(int $empresaId, array $d): array
{
    $erros = [];
    $avisos = [];
    $pdo = db();
    $nome = trim((string) ($d['nome'] ?? ''));
    if (mb_strlen($nome) < 3 || mb_strlen($nome) > 120) {
        $erros[] = 'Dê um nome à jornada (3 a 120 letras).';
    }
    $descricao = mb_substr(trim((string) ($d['descricao'] ?? '')), 0, 500);
    $gatilho = (string) ($d['gatilho'] ?? '');
    if (!isset(jornada_gatilhos()[$gatilho])) {
        $erros[] = 'Escolha quando a jornada começa.';
    }
    $gc = is_array($d['gatilho_config'] ?? null) ? $d['gatilho_config'] : [];
    $cfgG = ['parar_quando_responder' => !empty($gc['parar_quando_responder'])];
    $origens = array_values(array_intersect((array) ($gc['origens'] ?? []), array_keys(lead_origens())));
    if ($gatilho === 'novo_lead') {
        if (!$origens) {
            $erros[] = 'Escolha pelo menos uma origem de lead.';
        }
        if (in_array('importacao', $origens, true)) {
            $erros[] = 'Listas importadas não entram em jornadas automáticas: use uma campanha (com autorização de cada contato).';
        }
        $cfgG['origens'] = $origens;
    }
    if ($gatilho === 'mudanca_etapa') {
        $etapa = funil_etapa($empresaId, (int) ($gc['etapa_id'] ?? 0));
        if (!$etapa) {
            $erros[] = 'Escolha a etapa do Kanban que faz a jornada começar.';
        } else {
            $cfgG['etapa_id'] = (int) $etapa['id'];
        }
    }
    $cats = array_values(array_intersect((array) ($gc['somente_categorias'] ?? []), array_keys(funil_categorias())));
    if ($cats) {
        $cfgG['somente_categorias'] = $cats;
    }
    $empId = (int) ($d['empreendimento_id'] ?? 0) ?: null;
    if ($empId) {
        $stmt = $pdo->prepare('SELECT 1 FROM empreendimentos WHERE id = :id AND empresa_id = :e');
        $stmt->execute(['id' => $empId, 'e' => $empresaId]);
        if (!$stmt->fetchColumn()) {
            $erros[] = 'Empreendimento inválido.';
        }
    }
    $maxMsg = (int) ($d['max_mensagens'] ?? 3);
    if ($maxMsg < 1 || $maxMsg > 5) {
        $erros[] = 'O limite de mensagens por lead vai de 1 a 5.';
    }

    $tipos = jornada_tipos_bloco();
    $regras = jornada_regras_condicao();
    $passosIn = array_values(array_filter((array) ($d['passos'] ?? []), 'is_array'));
    if (!$passosIn) {
        $erros[] = 'Adicione pelo menos um bloco.';
    }
    if (count($passosIn) > JORNADA_MAX_PASSOS) {
        $erros[] = 'No máximo ' . JORNADA_MAX_PASSOS . ' blocos por jornada.';
    }
    $chaves = [];
    foreach ($passosIn as $i => $p) {
        $c = (string) ($p['chave'] ?? '');
        if (!preg_match('/^[a-z0-9_]{1,40}$/', $c) || isset($chaves[$c])) {
            $erros[] = 'Bloco ' . ($i + 1) . ': identificador inválido ou repetido.';
        }
        $chaves[$c] = $i;
    }
    $passos = [];
    foreach ($passosIn as $i => $p) {
        $n = $i + 1;
        $tipo = (string) ($p['tipo'] ?? '');
        if (!isset($tipos[$tipo])) {
            $erros[] = "Bloco $n: tipo desconhecido.";
            continue;
        }
        $rot = $tipos[$tipo][0];
        $titulo = mb_substr(trim((string) ($p['titulo'] ?? '')), 0, 120) ?: $rot;
        $cfg = is_array($p['config'] ?? null) ? $p['config'] : [];
        $novo = [];
        switch ($tipo) {
            case 'enviar_template':
                $novo['template'] = ($cfg['template'] ?? '') === 'do_empreendimento' ? 'do_empreendimento' : 'fixo';
                $tid = (int) ($cfg['template_id'] ?? 0) ?: null;
                if ($tid) {
                    $tpl = wa_template($empresaId, $tid);
                    if (!$tpl || $tpl['status'] !== 'aprovado') {
                        $erros[] = "Bloco $n ($titulo): escolha um template aprovado pela Meta.";
                    }
                } elseif ($novo['template'] === 'fixo') {
                    $avisos[] = "Bloco $n ($titulo): sem template escolhido — este passo será pulado.";
                }
                $novo['template_id'] = $tid;
                $novo['ignorar_horario'] = !empty($cfg['ignorar_horario']);
                $novo['se_bloqueado'] = ($cfg['se_bloqueado'] ?? '') === 'encerrar' ? 'encerrar' : 'seguir';
                $novo['se_sem_template'] = 'pular';
                break;
            case 'esperar':
                $modo = ($cfg['modo'] ?? (isset($cfg['horas_uteis']) ? 'horas_uteis' : 'horas')) === 'horas_uteis' ? 'horas_uteis' : 'horas';
                $h = (float) str_replace(',', '.', (string) ($cfg[$modo] ?? $cfg['valor'] ?? 0));
                if ($h < 0.25 || $h > 720) {
                    $erros[] = "Bloco $n ($titulo): a espera vai de 15 minutos (0,25 h) a 720 horas.";
                }
                $novo[$modo] = round($h, 2);
                break;
            case 'condicao':
                $se = array_values(array_intersect((array) ($cfg['se'] ?? []), array_keys($regras)));
                if (!$se) {
                    $erros[] = "Bloco $n ($titulo): escolha pelo menos uma condição.";
                }
                $novo['se'] = $se;
                break;
            case 'confirmar_atendimento':
                $novo['so_se_tentativa'] = !empty($cfg['so_se_tentativa']);
                break;
            case 'criar_tarefa':
            case 'notificar_corretor':
                $t = mb_substr(trim((string) ($cfg['titulo'] ?? '')), 0, 190);
                if (mb_strlen($t) < 3) {
                    $erros[] = "Bloco $n ($titulo): escreva o texto da tarefa/aviso para o corretor.";
                }
                $novo['titulo'] = $t;
                break;
            case 'atualizar_etapa':
                $et = funil_etapa($empresaId, (int) ($cfg['etapa_id'] ?? 0));
                if (!$et) {
                    $erros[] = "Bloco $n ($titulo): escolha a etapa do Kanban.";
                } elseif (in_array($et['categoria'], funil_categorias_encerradas(), true)) {
                    $erros[] = "Bloco $n ($titulo): a automação não encerra leads (sem interesse, contrato, perdido) — isso é decisão do corretor.";
                } else {
                    $novo['etapa_id'] = (int) $et['id'];
                }
                break;
            case 'encerrar':
                $m = mb_substr(trim((string) ($cfg['motivo'] ?? '')), 0, 120);
                if ($m !== '') {
                    $novo['motivo'] = $m;
                }
                break;
        }
        $prox = (string) ($p['proximo_chave'] ?? '');
        $senao = (string) ($p['senao_chave'] ?? '');
        // Sem destino escolhido: segue para o bloco de baixo.
        if ($prox === '' && $tipo !== 'encerrar' && isset($passosIn[$i + 1]['chave'])) {
            $prox = (string) $passosIn[$i + 1]['chave'];
        }
        foreach (['prox' => $prox, 'senao' => $senao] as $k => $alvo) {
            if ($alvo !== '' && !isset($chaves[$alvo])) {
                $erros[] = "Bloco $n ($titulo): o caminho aponta para um bloco que não existe.";
            }
            if ($alvo !== '' && $alvo === ($p['chave'] ?? null)) {
                $erros[] = "Bloco $n ($titulo): o bloco não pode apontar para ele mesmo.";
            }
        }
        if ($tipo === 'condicao' && ($prox === '' || $senao === '')) {
            $erros[] = "Bloco $n ($titulo): escolha para onde ir no \"sim\" e no \"não\".";
        }
        if ($tipo === 'encerrar') {
            $prox = '';
        }
        $passos[] = ['chave' => (string) $p['chave'], 'tipo' => $tipo, 'titulo' => $titulo, 'config' => $novo,
                     'proximo_chave' => $prox !== '' ? $prox : null, 'senao_chave' => $tipo === 'condicao' && $senao !== '' ? $senao : null];
    }

    if (!$erros && $passos) {
        // Sem voltas: busca em profundidade a partir do primeiro bloco.
        $mapa = array_column($passos, null, 'chave');
        $estado = [];
        $ciclo = false;
        $visitar = function (string $c) use (&$visitar, &$estado, &$ciclo, $mapa) {
            if ($ciclo || !isset($mapa[$c])) {
                return;
            }
            if (($estado[$c] ?? 0) === 1) {
                $ciclo = true;
                return;
            }
            if (($estado[$c] ?? 0) === 2) {
                return;
            }
            $estado[$c] = 1;
            foreach ([$mapa[$c]['proximo_chave'], $mapa[$c]['senao_chave']] as $prox) {
                if ($prox !== null) {
                    $visitar($prox);
                }
            }
            $estado[$c] = 2;
        };
        $visitar($passos[0]['chave']);
        if ($ciclo) {
            $erros[] = 'O fluxo não pode voltar para um bloco anterior (cada bloco acontece no máximo uma vez por lead).';
        }
        foreach ($passos as $i => $p) {
            if (!isset($estado[$p['chave']])) {
                $avisos[] = 'Bloco ' . ($i + 1) . ' (' . $p['titulo'] . '): nenhum caminho chega até ele.';
            }
        }
        $envios = count(array_filter($passos, fn ($p) => in_array($p['tipo'], ['enviar_template', 'enviar_documento'], true)));
        if ($envios > $maxMsg) {
            $avisos[] = "A jornada tem $envios blocos de envio, mas o limite é $maxMsg mensagem(ns) por lead: as excedentes não saem.";
        }
    }
    if ($erros) {
        return ['ok' => false, 'erros' => array_values(array_unique($erros))];
    }
    return ['ok' => true, 'avisos' => array_values(array_unique($avisos)), 'def' => [
        'nome' => $nome, 'descricao' => $descricao, 'gatilho' => $gatilho, 'gatilho_config' => $cfgG,
        'empreendimento_id' => $empId, 'max_mensagens' => $maxMsg, 'passos' => $passos,
    ]];
}

/**
 * Cria ($id null) ou substitui a definição. Jornada LIGADA não é editada
 * (pause antes): os leads no meio dela não podem ter os passos trocados
 * por baixo. Leads parados num bloco que deixou de existir saem da jornada.
 */
function jornada_salvar_definicao(int $empresaId, int $usuarioId, ?int $id, array $entrada): array
{
    $v = jornada_validar_definicao($empresaId, $entrada);
    if (!$v['ok']) {
        return $v;
    }
    $d = $v['def'];
    $pdo = db();
    if ($id) {
        $atual = jornada_carregar($empresaId, $id);
        if (!$atual) {
            return ['ok' => false, 'erros' => ['Jornada não encontrada.']];
        }
        if ($atual['status'] === 'ativa') {
            return ['ok' => false, 'erros' => ['Pause a jornada antes de mudar os blocos. Os leads que estão nela ficam parados e continuam depois.']];
        }
        // As jornadas padrão são achadas pelo nome: o nome delas não muda.
        if (in_array($atual['nome'], [JORNADA_BOAS_VINDAS, JORNADA_FOLLOW_UP], true)) {
            $d['nome'] = $atual['nome'];
        }
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM jornadas WHERE empresa_id = :e AND nome = :n AND id <> :id');
    $stmt->execute(['e' => $empresaId, 'n' => $d['nome'], 'id' => (int) $id]);
    if ($stmt->fetchColumn()) {
        return ['ok' => false, 'erros' => ['Já existe uma jornada com este nome.']];
    }
    $pdo->beginTransaction();
    try {
        $dados = ['n' => $d['nome'], 'd' => $d['descricao'] ?: null, 'g' => $d['gatilho'], 'gc' => json_encode($d['gatilho_config']),
                  'emp' => $d['empreendimento_id'], 'm' => $d['max_mensagens']];
        if ($id) {
            $pdo->prepare('UPDATE jornadas SET nome = :n, descricao = :d, gatilho = :g, gatilho_config = :gc, empreendimento_id = :emp, max_mensagens = :m,
                                  versao = versao + 1, atualizado_em = NOW() WHERE id = :id AND empresa_id = :e')
                ->execute($dados + ['id' => $id, 'e' => $empresaId]);
            $pdo->prepare('DELETE FROM jornada_passos WHERE jornada_id = :j')->execute(['j' => $id]);
        } else {
            $pdo->prepare("INSERT INTO jornadas (empresa_id, nome, descricao, gatilho, gatilho_config, empreendimento_id, max_mensagens, status, criado_por)
                           VALUES (:e, :n, :d, :g, :gc, :emp, :m, 'rascunho', :u)")
                ->execute($dados + ['e' => $empresaId, 'u' => $usuarioId]);
            $id = (int) $pdo->lastInsertId();
        }
        $ins = $pdo->prepare('INSERT INTO jornada_passos (jornada_id, chave, ordem, tipo, titulo, config, proximo_chave, senao_chave) VALUES (:j, :c, :o, :t, :ti, :cfg, :p, :s)');
        foreach ($d['passos'] as $i => $p) {
            $ins->execute(['j' => $id, 'c' => $p['chave'], 'o' => $i + 1, 't' => $p['tipo'], 'ti' => $p['titulo'],
                           'cfg' => $p['config'] ? json_encode($p['config']) : null, 'p' => $p['proximo_chave'], 's' => $p['senao_chave']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $chaves = array_column($d['passos'], 'chave');
    $stmt = $pdo->prepare("SELECT id, passo_chave FROM jornada_execucoes WHERE jornada_id = :j AND status IN ('ativa','aguardando','pausada')");
    $stmt->execute(['j' => $id]);
    $removidos = 0;
    foreach ($stmt->fetchAll() as $x) {
        if ($x['passo_chave'] !== null && !in_array($x['passo_chave'], $chaves, true)) {
            jornada_finalizar($empresaId, (int) $x['id'], 'cancelada', 'Jornada alterada: o bloco em que o lead estava foi removido');
            $removidos++;
        }
    }
    auditoria_registrar($empresaId, $usuarioId, 'jornada.salva', 'jornada', $id, ['blocos' => count($d['passos']), 'leads_retirados' => $removidos]);
    return ['ok' => true, 'id' => $id, 'avisos' => $v['avisos'], 'retirados' => $removidos];
}

function jornada_duplicar(int $empresaId, int $usuarioId, int $id): array
{
    $def = jornada_definicao($empresaId, $id);
    if (!$def) {
        return ['ok' => false, 'erros' => ['Jornada não encontrada.']];
    }
    $base = mb_substr($def['nome'], 0, 105) . ' (cópia)';
    $nome = $base;
    for ($i = 2; $i < 50; $i++) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM jornadas WHERE empresa_id = :e AND nome = :n');
        $stmt->execute(['e' => $empresaId, 'n' => $nome]);
        if (!$stmt->fetchColumn()) {
            break;
        }
        $nome = $base . ' ' . $i;
    }
    $def['nome'] = $nome;
    $def['passos'] = array_map(fn ($p) => ['config' => (array) $p['config']] + $p, $def['passos']);
    $def['gatilho_config'] = (array) $def['gatilho_config'];
    return jornada_salvar_definicao($empresaId, $usuarioId, null, $def);
}

/**
 * ativa | pausada | desativada. Ligar exige definição válida e WhatsApp
 * cadastrado; religar retoma os leads que ficaram parados. Desativar tira
 * todos os leads da jornada (e cancela o que estava agendado).
 */
function jornada_mudar_status(int $empresaId, int $usuarioId, int $id, string $novo): array
{
    $j = jornada_carregar($empresaId, $id);
    if (!$j) {
        return ['ok' => false, 'erro' => 'Jornada não encontrada.'];
    }
    if (!in_array($novo, ['ativa', 'pausada', 'desativada'], true)) {
        return ['ok' => false, 'erro' => 'Situação inválida.'];
    }
    $pdo = db();
    if ($novo === 'ativa') {
        if (!wa_conta_da_empresa($empresaId)) {
            return ['ok' => false, 'erro' => 'Conecte o WhatsApp (ou ligue o modo de teste) antes de ligar a jornada.'];
        }
        $def = jornada_definicao($empresaId, $id);
        $def['passos'] = array_map(fn ($p) => ['config' => (array) $p['config']] + $p, $def['passos']);
        $def['gatilho_config'] = (array) $def['gatilho_config'];
        $v = jornada_validar_definicao($empresaId, $def);
        if (!$v['ok']) {
            return ['ok' => false, 'erro' => 'Corrija a jornada antes de ligar: ' . implode(' ', $v['erros'])];
        }
    }
    $pdo->prepare('UPDATE jornadas SET status = :s, atualizado_em = NOW() WHERE id = :id AND empresa_id = :e')->execute(['s' => $novo, 'id' => $id, 'e' => $empresaId]);
    if ($novo === 'ativa') {
        $stmt = $pdo->prepare("SELECT id, passo_chave FROM jornada_execucoes WHERE jornada_id = :j AND status = 'pausada'");
        $stmt->execute(['j' => $id]);
        foreach ($stmt->fetchAll() as $x) {
            $pdo->prepare("UPDATE jornada_execucoes SET status = 'ativa' WHERE id = :id")->execute(['id' => $x['id']]);
            fila_agendar($empresaId, 'jornada_passo', ['execucao_id' => (int) $x['id'], 'passo' => $x['passo_chave']],
                         'jornada:' . $x['id'] . ':' . $x['passo_chave'] . ':retomada:' . time(), null, 'jornada_execucao', (int) $x['id']);
        }
    } elseif ($novo === 'desativada') {
        $stmt = $pdo->prepare("SELECT id FROM jornada_execucoes WHERE jornada_id = :j AND status IN ('ativa','aguardando','pausada')");
        $stmt->execute(['j' => $id]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $xid) {
            jornada_finalizar($empresaId, (int) $xid, 'cancelada', 'Jornada desativada');
        }
    }
    auditoria_registrar($empresaId, $usuarioId, 'jornada.' . $novo, 'jornada', $id, []);
    $msg = ['ativa' => 'Jornada ligada.', 'pausada' => 'Jornada pausada: nenhum lead novo entra e os que estavam nela ficam parados.',
            'desativada' => 'Jornada desativada: os leads saíram dela e nada mais é enviado.'][$novo];
    return ['ok' => true, 'mensagem' => $msg];
}

// ================================================================== simulação

/** Cenário padrão da simulação (dados fictícios). */
function jornada_cenario_padrao(): array
{
    return ['consentimento' => 'atendimento', 'tem_corretor' => true, 'cliente_responde' => 'nunca', 'corretor_tenta' => true,
            'corretor_confirma' => false, 'empreendimento_tem_template' => true, 'tem_book' => true];
}

/**
 * Percorre a definição com um cenário fictício e devolve a linha do tempo,
 * sem banco e sem envio. $templates = [id => ['nome', 'categoria', 'status']].
 * Retorna ['linhas' => [['quando', 'bloco', 'texto', 'classe']], 'mensagens' => n, 'resumo' => texto]
 */
function jornada_simular(array $def, array $cenario, array $templates): array
{
    $c = $cenario + jornada_cenario_padrao();
    $mapa = [];
    foreach ($def['passos'] as $p) {
        $mapa[$p['chave']] = $p;
    }
    $linhas = [];
    $msgs = 0;
    $max = max(1, (int) ($def['max_mensagens'] ?? 3));
    $respondeu = false;
    $confirmou = false;
    $parar = !empty($def['gatilho_config']['parar_quando_responder']);
    $horas = 0.0;
    $horasUteis = 0.0;
    $quando = function () use (&$horas, &$horasUteis): string {
        $p = [];
        if ($horasUteis > 0) {
            $p[] = jornada_sim_horas($horasUteis) . ' úteis';
        }
        if ($horas > 0) {
            $p[] = jornada_sim_horas($horas);
        }
        return $p ? '+' . implode(' + ', $p) : 'Na hora';
    };
    $linhas[] = ['quando' => 'Início', 'bloco' => jornada_gatilhos()[$def['gatilho']] ?? 'Gatilho', 'texto' => 'O lead entra na jornada (uma vez só por lead).', 'classe' => 'ok'];
    $chave = $def['passos'][0]['chave'] ?? null;
    $vistos = [];
    $fim = 'Último bloco executado.';
    while ($chave !== null) {
        if (isset($vistos[$chave]) || !isset($mapa[$chave])) {
            $fim = 'Fluxo interrompido (bloco repetido ou inexistente).';
            break;
        }
        $vistos[$chave] = true;
        $p = $mapa[$chave];
        $cfg = (array) ($p['config'] ?? []);
        $seguir = $p['proximo_chave'] ?? null;
        $t = $p['titulo'] ?: (jornada_tipos_bloco()[$p['tipo']][0] ?? $p['tipo']);
        $linha = ['quando' => $quando(), 'bloco' => $t, 'texto' => '', 'classe' => 'ok'];
        switch ($p['tipo']) {
            case 'enviar_template':
            case 'enviar_documento':
                if ($msgs >= $max) {
                    $linha['texto'] = "Limite de $max mensagem(ns) por lead atingido: a jornada termina aqui.";
                    $linha['classe'] = 'fim';
                    $linhas[] = $linha;
                    $fim = 'Limite de mensagens.';
                    break 2;
                }
                if ($p['tipo'] === 'enviar_documento') {
                    $tpl = null;
                    $okEnvio = !empty($c['tem_book']) && ($respondeu || $c['consentimento'] !== 'nenhum');
                    $linha['texto'] = empty($c['tem_book']) ? 'Empreendimento sem book: nada é enviado.' : ($okEnvio ? 'Envia o book (PDF).' : 'Não envia: o cliente não autorizou contato.');
                } else {
                    $tid = (int) ($cfg['template_id'] ?? 0);
                    $tpl = $tid ? ($templates[$tid] ?? null) : null;
                    $usaEmp = ($cfg['template'] ?? '') === 'do_empreendimento' && !empty($c['empreendimento_tem_template']);
                    if (!$tpl && !$usaEmp) {
                        $linha['texto'] = 'Sem template escolhido: passo pulado, nada é enviado.';
                        $linha['classe'] = 'pulado';
                        break;
                    }
                    $cat = $usaEmp ? 'utility' : ($tpl['categoria'] ?? 'utility');
                    $nomeT = $usaEmp ? 'mensagem do empreendimento' : '“' . ($tpl['nome'] ?? '?') . '”';
                    if (!$usaEmp && ($tpl['status'] ?? '') !== 'aprovado') {
                        $okEnvio = false;
                        $linha['texto'] = "Não envia $nomeT: template não aprovado pela Meta.";
                    } elseif ($cat === 'marketing' && $c['consentimento'] !== 'marketing') {
                        $okEnvio = false;
                        $linha['texto'] = "Não envia $nomeT: o cliente não autorizou receber ofertas (marketing).";
                    } elseif ($cat !== 'marketing' && $c['consentimento'] === 'nenhum' && !$respondeu) {
                        $okEnvio = false;
                        $linha['texto'] = "Não envia $nomeT: o cliente não autorizou contato pelo WhatsApp.";
                    } else {
                        $okEnvio = true;
                        $linha['texto'] = "Envia $nomeT" . (empty($cfg['ignorar_horario']) ? ' (dentro do horário de envio)' : ' (a qualquer hora)') . '.';
                    }
                }
                if (!$okEnvio) {
                    $linha['classe'] = 'bloqueado';
                    if (($cfg['se_bloqueado'] ?? 'encerrar') === 'encerrar' && $p['tipo'] === 'enviar_template') {
                        $linhas[] = $linha;
                        $linhas[] = ['quando' => $quando(), 'bloco' => 'Fim', 'texto' => 'Envio bloqueado: a jornada termina.', 'classe' => 'fim'];
                        $fim = 'Envio bloqueado.';
                        break 2;
                    }
                    break;
                }
                $msgs++;
                $linhas[] = $linha;
                if ($c['cliente_responde'] === 'apos_primeira_mensagem' && !$respondeu) {
                    $respondeu = true;
                    $linhas[] = ['quando' => $quando(), 'bloco' => 'Cliente', 'texto' => 'O cliente responde no WhatsApp. O corretor recebe “Responder no WhatsApp”.', 'classe' => 'info'];
                    if ($parar) {
                        $linhas[] = ['quando' => $quando(), 'bloco' => 'Fim', 'texto' => 'A jornada para: o cliente respondeu (atendimento humano assume).', 'classe' => 'fim'];
                        $fim = 'Cliente respondeu.';
                        break 2;
                    }
                }
                $chave = $seguir;
                        continue 2;
            case 'esperar':
                if (!empty($cfg['horas_uteis'])) {
                    $horasUteis += (float) $cfg['horas_uteis'];
                    $linha['texto'] = 'Espera ' . jornada_sim_horas((float) $cfg['horas_uteis']) . ' úteis (só conta dentro do horário de envio).';
                } else {
                    $h = !empty($cfg['horas']) ? (float) $cfg['horas'] : ((int) ($cfg['minutos'] ?? 60)) / 60;
                    $horas += $h;
                    $linha['texto'] = 'Espera ' . jornada_sim_horas($h) . '.';
                }
                $linha['classe'] = 'espera';
                break;
            case 'condicao':
                $sim = null;
                foreach ((array) ($cfg['se'] ?? []) as $r) {
                    $v = ['cliente_respondeu' => $respondeu, 'humano_respondeu' => $respondeu && !empty($c['tem_corretor']), 'corretor_confirmou' => $confirmou,
                          'houve_tentativa' => !empty($c['tem_corretor']) && !empty($c['corretor_tenta']), 'tem_responsavel' => !empty($c['tem_corretor'])][$r] ?? false;
                    if ($v) {
                        $sim = $r;
                        break;
                    }
                }
                $alvo = $sim ? ($p['proximo_chave'] ?? null) : ($p['senao_chave'] ?? null);
                $linha['texto'] = ($sim ? 'Sim — ' . (jornada_regras_condicao()[$sim] ?? $sim) : 'Não') . ' → ' . ($alvo && isset($mapa[$alvo]) ? ($mapa[$alvo]['titulo'] ?: $alvo) : 'fim') . '.';
                $linha['classe'] = 'condicao';
                $seguir = $alvo;
                break;
            case 'confirmar_atendimento':
                if (empty($c['tem_corretor'])) {
                    $linha['texto'] = 'Lead sem corretor: ninguém para perguntar (pulado).';
                    $linha['classe'] = 'pulado';
                } elseif ($respondeu) {
                    $linha['texto'] = 'O cliente já respondeu: não pergunta.';
                    $linha['classe'] = 'pulado';
                } elseif (!empty($cfg['so_se_tentativa']) && empty($c['corretor_tenta'])) {
                    $linha['texto'] = 'O corretor não registrou tentativa: não pergunta.';
                    $linha['classe'] = 'pulado';
                } else {
                    $linha['texto'] = 'O corretor vê na central: “O cliente respondeu à sua tentativa de contato?”';
                    if (!empty($c['corretor_confirma'])) {
                        $confirmou = true;
                        $linhas[] = $linha;
                        $linhas[] = ['quando' => $quando(), 'bloco' => 'Corretor', 'texto' => 'Responde “Sim, respondeu”.' . ($parar ? ' A jornada para.' : ''), 'classe' => $parar ? 'fim' : 'info'];
                        if ($parar) {
                            $fim = 'Corretor confirmou o atendimento.';
                            break 2;
                        }
                        $chave = $seguir;
                        continue 2;
                    }
                }
                break;
            case 'criar_tarefa':
            case 'notificar_corretor':
                $linha['texto'] = !empty($c['tem_corretor']) ? 'Pendência para o corretor: “' . ($cfg['titulo'] ?? $t) . '”.' : 'Lead sem corretor: pulado.';
                $linha['classe'] = !empty($c['tem_corretor']) ? 'ok' : 'pulado';
                break;
            case 'atualizar_etapa':
                $linha['texto'] = 'Move o lead no Kanban (não conta como atendimento do corretor).';
                break;
            case 'encaminhar_fila':
                $linha['texto'] = !empty($c['tem_corretor']) ? 'O lead já tem corretor: nada muda.' : 'Sem corretor: vai para a roleta (ou espera na fila até alguém disponível).';
                break;
            case 'encerrar':
                $linha['texto'] = 'Fim da jornada.';
                $linha['classe'] = 'fim';
                $linhas[] = $linha;
                $fim = 'Fim.';
                break 2;
        }
        $linhas[] = $linha;
        $chave = $seguir;
    }
    return ['linhas' => $linhas, 'mensagens' => $msgs,
            'resumo' => "$msgs mensagem(ns) enviada(s) neste cenário. $fim"];
}

function jornada_sim_horas(float $h): string
{
    if ($h < 1) {
        return (int) round($h * 60) . ' min';
    }
    $txt = rtrim(rtrim(number_format($h, 2, ',', ''), '0'), ',') . ' h';
    return $h >= 48 && fmod($h, 24) == 0 ? ($h / 24) . ' dias' : $txt;
}

/** Templates da empresa no formato da simulação. */
function jornada_templates_simulacao(int $empresaId): array
{
    $stmt = db()->prepare('SELECT id, nome, categoria, status FROM whatsapp_templates WHERE empresa_id = :e');
    $stmt->execute(['e' => $empresaId]);
    $r = [];
    foreach ($stmt->fetchAll() as $t) {
        $r[(int) $t['id']] = $t;
    }
    return $r;
}
