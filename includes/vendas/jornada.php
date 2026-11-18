<?php
/**
 * Inteligência de Vendas — Fase 4: Jornada do Cliente.
 *
 * Monta, NA LEITURA, a linha do tempo de um comprador a partir do que já
 * está gravado no CRM (nada é copiado nem inventado):
 *   leads (entrada, origem, UTMs, anúncio), lead_atribuicoes, lead_transferencias,
 *   lead_etapa_historico, lead_eventos (contatos), agenda_compromissos (visitas),
 *   WhatsApp (mensagens, campanhas, jornadas automáticas), oferta ativa,
 *   lead_contratos e a venda importada.
 *
 * Cada evento tem natureza:
 *   confirmado  registrado pelo CRM no momento em que aconteceu;
 *   importado   veio do sistema comercial (a venda);
 *   inferido    reconstruído depois (retroalimentação), não observado na hora.
 *
 * Só entram os leads VINCULADOS à venda (automático sem conflito ou
 * confirmado na revisão) e, desses, só os que o usuário pode ver hoje
 * (rbac_leads_scope). Nada aqui grava no banco.
 */
require_once __DIR__ . '/cruzamento.php';
require_once __DIR__ . '/../historico.php';
require_once __DIR__ . '/../transferencia.php';
require_once __DIR__ . '/../funil.php';
require_once __DIR__ . '/../meta_criativos.php';

const VENDAS_INATIVIDADE_DIAS = 30;   // intervalo sem interação que conta como "período de inatividade"

function vendas_classificacoes_comerciais(): array
{
    return [
        'responsavel_crm' => ['Venda pelo responsável do CRM', 'pill-green'],
        'outro_corretor_mesma_estrutura' => ['Venda por outro corretor da mesma equipe', 'pill-blue'],
        'outra_equipe' => ['Venda por outra equipe', 'pill-blue'],
        'canal_externo' => ['Venda por canal externo identificado', 'pill-amber'],
        'nao_identificado' => ['Responsável pela venda não identificado', 'pill-muted'],
        'sem_historico' => ['Sem histórico do responsável na data da venda', 'pill-muted'],
        'pendente_revisao' => ['Correspondência pendente de revisão', 'pill-amber'],
        'nao_aplicavel' => ['Cliente não estava no CRM antes da venda', 'pill-muted'],
    ];
}

/** Leads vinculados à venda que o usuário pode ver hoje. Retorna [visíveis (id => linha), ocultos (qtd)]. */
function vendas_jornada_leads(array $usuario, int $vendaId): array
{
    $st = db()->prepare("SELECT lead_id FROM vendas_correspondencia_leads WHERE venda_id = :v AND papel = 'vinculado'");
    $st->execute(['v' => $vendaId]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if (!$ids) {
        return [[], 0];
    }
    $sc = rbac_leads_scope($usuario);
    $params = $sc['params'];
    $marcas = [];
    foreach ($ids as $i => $lid) {
        $marcas[] = ':jl' . $i;
        $params['jl' . $i] = $lid;
    }
    $st = db()->prepare('SELECT leads.*, e.nome AS emp_nome, o.nome AS origem_nome, u.nome AS resp_nome, ol.nome AS oferta_nome, ol.criado_em AS oferta_em
                         FROM leads LEFT JOIN empreendimentos e ON e.id = leads.empreendimento_id
                         LEFT JOIN lead_origens o ON o.id = leads.origem_detalhe_id LEFT JOIN usuarios u ON u.id = leads.responsavel_id
                         LEFT JOIN oferta_listas ol ON ol.id = leads.oferta_lista_id
                         WHERE ' . $sc['sql'] . ' AND leads.id IN (' . implode(',', $marcas) . ')');
    $st->execute($params);
    $vis = [];
    foreach ($st->fetchAll() as $l) {
        $l['entrada_em'] = min(array_filter([$l['criado_em'], $l['meta_cadastro_em']]));
        $vis[(int) $l['id']] = $l;
    }
    uasort($vis, fn ($a, $b) => strcmp($a['entrada_em'], $b['entrada_em']) ?: $a['id'] <=> $b['id']);
    return [$vis, count($ids) - count($vis)];
}

/**
 * Jornada completa de uma venda. Retorna null se a venda não é da empresa.
 * Chaves: venda, corr, leads, ocultos, eventos, metricas, corretores, etapas,
 * empreendimentos, origens, classificacao, incompleto[], acesso ('ok'|'sem_vinculo'|'sem_permissao').
 */
function vendas_jornada(array $usuario, int $vendaId): ?array
{
    $empresaId = (int) $usuario['empresa_ativa_id'];
    $pdo = db();
    $st = $pdo->prepare('SELECT v.*, e.nome AS emp_crm FROM vendas_externas v LEFT JOIN empreendimentos e ON e.id = v.empreendimento_id WHERE v.id = :id AND v.empresa_id = :e');
    $st->execute(['id' => $vendaId, 'e' => $empresaId]);
    $venda = $st->fetch();
    if (!$venda) {
        return null;
    }
    $corr = vendas_correspondencia($vendaId);
    $J = ['venda' => $venda, 'corr' => $corr, 'leads' => [], 'ocultos' => 0, 'eventos' => [], 'metricas' => [], 'corretores' => [], 'etapas' => [],
          'empreendimentos' => [], 'origens' => [], 'classificacao' => null, 'incompleto' => [], 'acesso' => 'ok', 'responsaveis_na_venda' => [], 'anuncio' => null];
    if (!$corr || !(int) $corr['vinculada']) {
        $J['acesso'] = 'sem_vinculo';
        $J['classificacao'] = vendas_classificacao_comercial($J);
        return $J;
    }
    [$leads, $ocultos] = vendas_jornada_leads($usuario, $vendaId);
    $J['ocultos'] = $ocultos;
    if (!$leads) {
        $J['acesso'] = 'sem_permissao';
        return $J;
    }
    $J['leads'] = $leads;
    $ids = array_keys($leads);
    $in = implode(',', array_map('intval', $ids));
    $vendaDia = $venda['data_venda'] ? substr($venda['data_venda'], 0, 10) : null;
    $vendaFim = $vendaDia ? $vendaDia . ' 23:59:59' : null;
    $ev = [];
    $add = function (?string $em, string $tipo, string $titulo, ?string $detalhe = null, ?int $leadId = null, string $natureza = 'confirmado') use (&$ev) {
        if ($em) {
            $ev[] = ['em' => $em, 'tipo' => $tipo, 'titulo' => $titulo, 'detalhe' => $detalhe, 'lead_id' => $leadId, 'natureza' => $natureza];
        }
    };
    $q = function (string $sql) use ($pdo) {
        return $pdo->query($sql)->fetchAll();
    };
    $nomes = [];
    foreach ($q('SELECT id, nome, equipe_id FROM usuarios WHERE empresa_id = ' . $empresaId) as $u) {
        $nomes[(int) $u['id']] = $u;
    }
    $nomeU = fn ($id) => $id ? ($nomes[(int) $id]['nome'] ?? 'Usuário #' . (int) $id) : null;

    // ---------------- entrada, origem, anúncio, oferta ativa
    $canais = ['meta_ads' => 'Meta Ads', 'manual' => 'Cadastro manual', 'importacao' => 'Importação de planilha', 'indicacao' => 'Indicação', 'outro' => 'Outra origem'];
    foreach ($leads as $l) {
        $lid = (int) $l['id'];
        $origem = $l['origem_nome'] ?: ($canais[$l['origem']] ?? $l['origem']);
        $camp = trim((string) ($l['utm_campaign'] ?: $l['campanha']));
        $add($l['criado_em'], 'entrada', 'Cadastro no CRM' . ($l['emp_nome'] ? ' — interesse em ' . $l['emp_nome'] : ''),
            'Origem: ' . $origem . ($camp !== '' ? ' · campanha ' . $camp : ''), $lid);
        if ($l['meta_cadastro_em'] && $l['meta_cadastro_em'] !== $l['criado_em']) {
            $add($l['meta_cadastro_em'], 'anuncio', 'Formulário do anúncio preenchido', null, $lid);
        }
        if ($l['oferta_lista_id']) {
            $add($l['oferta_em'] ?: $l['criado_em'], 'oferta', 'Lead em lista de oferta ativa', $l['oferta_nome'] ? 'Lista "' . $l['oferta_nome'] . '"' : null, $lid);
        }
        $J['origens'][] = ['lead_id' => $lid, 'entrada_em' => $l['entrada_em'], 'origem' => $origem, 'canal' => $l['origem'], 'campanha' => $camp ?: null,
                           'utm' => array_filter(['source' => $l['utm_source'], 'medium' => $l['utm_medium'], 'campaign' => $l['utm_campaign'], 'content' => $l['utm_content'], 'term' => $l['utm_term']]),
                           'anuncio' => null, 'antes_da_venda' => !$vendaDia || substr($l['entrada_em'], 0, 10) <= $vendaDia];
        $J['empreendimentos'][] = ['lead_id' => $lid, 'entrada_em' => $l['entrada_em'], 'empreendimento_id' => $l['empreendimento_id'] ? (int) $l['empreendimento_id'] : null, 'nome' => $l['emp_nome']];
    }
    foreach ($J['origens'] as $k => $o) {
        if (modulo_tabela_existe('meta_anuncios')) {
            $c = meta_criativo_do_lead($empresaId, $o['lead_id']);
            $J['origens'][$k]['anuncio'] = $c;
            if ($c && !$c['tem_anuncio'] && $o['canal'] === 'meta_ads') {
                $J['incompleto']['anuncio'] = 'Lead do Meta Ads sem o anúncio de origem registrado (anterior à captura dos ids ou orgânico).';
            }
        }
    }
    $primeiraOrigem = $J['origens'][0];
    $J['anuncio'] = $primeiraOrigem['anuncio'];

    // ---------------- atribuições (responsáveis)
    $atrib = modulo_tabela_existe('lead_atribuicoes')
        ? $q("SELECT a.*, eq.nome AS equipe_nome, s.nome AS sup_nome FROM lead_atribuicoes a LEFT JOIN equipes eq ON eq.id = a.equipe_id
              LEFT JOIN superintendencias s ON s.id = a.superintendencia_id WHERE a.empresa_id = $empresaId AND a.lead_id IN ($in) ORDER BY a.atribuido_em, a.id")
        : [];
    $formas = historico_formas();
    $semAtrib = array_diff($ids, array_map(fn ($a) => (int) $a['lead_id'], $atrib));
    if ($semAtrib) {
        $J['incompleto']['atribuicoes'] = 'Sem histórico de distribuição para ' . count($semAtrib) . ' oportunidade(s): só o responsável atual é conhecido; os anteriores não são inventados.';
    }
    $gerentes = $sups = [];
    foreach ($atrib as $a) {
        $nat = (int) $a['reconstruido'] ? 'inferido' : 'confirmado';
        if ((int) $a['reconstruido']) {
            $J['incompleto']['reconstruido'] = 'Parte do histórico de responsáveis foi reconstruída depois (retroalimentação), não registrada na hora.';
        }
        $add($a['atribuido_em'], 'distribuicao', 'Lead com ' . $nomeU($a['usuario_id']), $formas[$a['forma']] ?? $a['forma'], (int) $a['lead_id'], $nat);
        if ($a['primeiro_atendimento_em']) {
            $add($a['primeiro_atendimento_em'], 'atendimento', 'Primeiro atendimento de ' . $nomeU($a['usuario_id']), null, (int) $a['lead_id'], $nat);
        }
        if ($a['primeiro_contato_efetivo_em']) {
            $add($a['primeiro_contato_efetivo_em'], 'contato_efetivo', 'Primeiro contato efetivo com ' . $nomeU($a['usuario_id']), $a['contato_efetivo_via'] ? 'via ' . $a['contato_efetivo_via'] : null, (int) $a['lead_id'], $nat);
        }
        $ini = $a['atribuido_em'];
        $fim = $a['encerrada_em'] ?: date('Y-m-d H:i:s');
        $fimVenda = $vendaFim && $fim > $vendaFim ? $vendaFim : $fim;
        $diasAteVenda = $vendaFim && $ini > $vendaFim ? 0 : max(0, (int) floor((strtotime($fimVenda) - strtotime($ini)) / 86400));
        $J['corretores'][] = ['lead_id' => (int) $a['lead_id'], 'usuario_id' => (int) $a['usuario_id'], 'nome' => $nomeU($a['usuario_id']), 'equipe_id' => $a['equipe_id'] ? (int) $a['equipe_id'] : null,
                              'equipe' => $a['equipe_nome'], 'gerente' => $nomeU($a['gerente_id']), 'superintendencia' => $a['sup_nome'], 'forma' => $formas[$a['forma']] ?? $a['forma'],
                              'de' => $ini, 'ate' => $a['encerrada_em'], 'dias_ate_venda' => $diasAteVenda, 'depois_da_venda' => $vendaFim && $ini > $vendaFim, 'reconstruido' => (bool) $a['reconstruido']];
        if ($a['gerente_id'] && (!$vendaFim || $ini <= $vendaFim)) {
            $gerentes[(int) $a['gerente_id']] = $nomeU($a['gerente_id']);
        }
        if ($a['sup_nome'] && (!$vendaFim || $ini <= $vendaFim)) {
            $sups[$a['sup_nome']] = true;
        }
        if ($vendaFim && $ini <= $vendaFim && (!$a['encerrada_em'] || $a['encerrada_em'] > $vendaFim)) {
            $J['responsaveis_na_venda'][] = ['lead_id' => (int) $a['lead_id'], 'usuario_id' => (int) $a['usuario_id'], 'nome' => $nomeU($a['usuario_id']), 'equipe_id' => $a['equipe_id'] ? (int) $a['equipe_id'] : null];
        }
    }

    // ---------------- transferências
    $transf = modulo_tabela_existe('lead_transferencias')
        ? $q("SELECT * FROM lead_transferencias WHERE empresa_id = $empresaId AND lead_id IN ($in) ORDER BY criado_em") : [];
    $motivos = transferencia_motivos();
    foreach ($transf as $t) {
        $add($t['criado_em'], 'transferencia', 'Transferido de ' . ($nomeU($t['de_usuario_id']) ?? 'sem responsável') . ' para ' . ($nomeU($t['para_usuario_id']) ?? '—'),
            $motivos[$t['motivo']] ?? $t['motivo'], (int) $t['lead_id']);
    }

    // ---------------- etapas do Kanban
    $cats = funil_categorias();
    $etapas = modulo_tabela_existe('lead_etapa_historico')
        ? $q("SELECT * FROM lead_etapa_historico WHERE empresa_id = $empresaId AND lead_id IN ($in) ORDER BY lead_id, ocorrido_em, id") : [];
    $porLead = [];
    foreach ($etapas as $h) {
        $porLead[(int) $h['lead_id']][] = $h;
        $add($h['ocorrido_em'], 'etapa', 'Etapa: ' . ($cats[$h['para_categoria']] ?? $h['para_categoria']), $h['origem'] === 'jornada' ? 'pela automação' : null, (int) $h['lead_id'], (int) $h['reconstruido'] ? 'inferido' : 'confirmado');
    }
    foreach ($leads as $lid => $l) {
        $hs = $porLead[$lid] ?? [];
        if (!$hs) {
            continue;
        }
        $tempos = [];
        $cursor = $l['criado_em'];
        $atual = $hs[0]['de_categoria'] ?: 'novo_lead';
        $limite = $vendaFim ?: date('Y-m-d H:i:s');
        foreach (array_merge($hs, [['ocorrido_em' => $limite, 'para_categoria' => null]]) as $h) {
            $ate = min($h['ocorrido_em'], $limite);
            if ($ate > $cursor) {
                $tempos[$atual] = ($tempos[$atual] ?? 0) + (strtotime($ate) - strtotime($cursor)) / 86400;
            }
            if ($h['ocorrido_em'] >= $limite) {
                break;
            }
            $cursor = $h['ocorrido_em'];
            $atual = $h['para_categoria'];
        }
        $J['etapas'][$lid] = array_map(fn ($d) => round($d, 1), $tempos);
    }
    if (!$etapas) {
        $J['incompleto']['etapas'] = 'Sem histórico de etapas do Kanban para estas oportunidades: o tempo em cada etapa não está disponível.';
    }

    // ---------------- contatos registrados (lead_eventos)
    $tiposContato = ['ligacao' => 'Ligação iniciada', 'whatsapp' => 'WhatsApp aberto pelo corretor', 'contato' => 'Contato registrado', 'visita' => 'Visita registrada'];
    foreach ($q("SELECT * FROM lead_eventos WHERE lead_id IN ($in) AND tipo IN ('ligacao','whatsapp','contato','visita') ORDER BY criado_em") as $e) {
        $add($e['criado_em'], $e['tipo'] === 'contato' ? 'contato' : 'tentativa', ($tiposContato[$e['tipo']] ?? $e['tipo']) . ($e['usuario_id'] ? ' — ' . $nomeU($e['usuario_id']) : ''),
            $e['tipo'] === 'contato' ? mb_strimwidth((string) $e['descricao'], 0, 160, '…') : null, (int) $e['lead_id']);
    }

    // ---------------- visitas
    $visitas = modulo_tabela_existe('agenda_compromissos')
        ? $q("SELECT * FROM agenda_compromissos WHERE empresa_id = $empresaId AND lead_id IN ($in) AND tipo = 'visita' ORDER BY inicio") : [];
    $stVis = ['agendado' => 'agendada', 'realizado' => 'realizada', 'cliente_ausente' => 'cliente ausente', 'reagendado' => 'reagendada', 'cancelado' => 'cancelada'];
    foreach ($visitas as $v) {
        $add($v['criado_em'], 'visita_agendada', 'Visita agendada para ' . date('d/m/Y H:i', strtotime($v['inicio'])), $nomeU($v['usuario_id']), (int) $v['lead_id']);
        if ($v['status'] !== 'agendado') {
            $add($v['status'] === 'realizado' ? ($v['concluido_em'] ?: $v['inicio']) : ($v['concluido_em'] ?: $v['inicio']), $v['status'] === 'realizado' ? 'visita' : 'visita_outra',
                'Visita ' . ($stVis[$v['status']] ?? $v['status']), $v['confirmada_em'] ? 'confirmada pela gestão' : null, (int) $v['lead_id']);
        }
    }

    // ---------------- WhatsApp: mensagens (agrupadas por dia), campanhas e jornadas automáticas
    $remarketing = 0;
    if (modulo_tabela_existe('whatsapp_mensagens')) {
        $msgs = $q("SELECT c.lead_id, DATE(m.criado_em) dia, MIN(m.criado_em) em, m.direcao,
                           SUM(m.direcao = 'entrada') entradas, SUM(m.direcao = 'saida' AND m.enviada_por IS NOT NULL) humanas,
                           SUM(m.direcao = 'saida' AND m.campanha_id IS NOT NULL) de_campanha, SUM(m.direcao = 'saida' AND m.jornada_execucao_id IS NOT NULL) de_jornada
                    FROM whatsapp_mensagens m JOIN whatsapp_conversas c ON c.id = m.conversa_id
                    WHERE m.empresa_id = $empresaId AND c.lead_id IN ($in) GROUP BY c.lead_id, DATE(m.criado_em), m.direcao ORDER BY em");
        foreach ($msgs as $m) {
            if ((int) $m['entradas']) {
                $add($m['em'], 'whatsapp_cliente', 'Cliente escreveu no WhatsApp', (int) $m['entradas'] . ' mensagem(ns)', (int) $m['lead_id']);
            }
            if ((int) $m['humanas']) {
                $add($m['em'], 'whatsapp_corretor', 'Corretor escreveu no WhatsApp', (int) $m['humanas'] . ' mensagem(ns)', (int) $m['lead_id']);
            }
        }
    }
    if (modulo_tabela_existe('whatsapp_campanha_destinatarios')) {
        foreach ($q("SELECT d.lead_id, d.status, d.atualizado_em, c.nome, m.enviada_em FROM whatsapp_campanha_destinatarios d JOIN whatsapp_campanhas c ON c.id = d.campanha_id
                     LEFT JOIN whatsapp_mensagens m ON m.id = d.mensagem_id
                     WHERE c.empresa_id = $empresaId AND d.lead_id IN ($in) AND d.status IN ('enviada','entregue','lida','respondida') ORDER BY d.atualizado_em") as $d) {
            $em = $d['enviada_em'] ?: $d['atualizado_em'];
            $add($em, 'remarketing', 'Recebeu a campanha "' . $d['nome'] . '"', $d['status'] === 'respondida' ? 'respondeu' : ($d['status'] === 'lida' ? 'leu' : null), (int) $d['lead_id']);
            if (!$vendaFim || $em <= $vendaFim) {
                $remarketing++;
            }
        }
    }
    $jornadasAuto = 0;
    if (modulo_tabela_existe('jornada_execucoes')) {
        foreach ($q("SELECT x.*, j.nome FROM jornada_execucoes x JOIN jornadas j ON j.id = x.jornada_id WHERE x.empresa_id = $empresaId AND x.lead_id IN ($in) ORDER BY x.iniciada_em") as $x) {
            $add($x['iniciada_em'], 'automacao', 'Entrou na jornada automática "' . $x['nome'] . '"', (int) $x['mensagens_enviadas'] ? (int) $x['mensagens_enviadas'] . ' mensagem(ns) enviada(s)' : null, (int) $x['lead_id']);
            if (!$vendaFim || $x['iniciada_em'] <= $vendaFim) {
                $jornadasAuto++;
            }
        }
    }

    // ---------------- contratos no CRM e a venda importada
    if (modulo_tabela_existe('lead_contratos')) {
        foreach ($q("SELECT * FROM lead_contratos WHERE empresa_id = $empresaId AND lead_id IN ($in)") as $c) {
            $add($c['assinado_em'] . ' 00:00:00', 'contrato', 'Contrato registrado no CRM' . ($c['status'] === 'distratado' ? ' (distratado)' : ''), $nomeU($c['usuario_id']), (int) $c['lead_id']);
        }
    }
    $add($venda['data_venda'], 'venda', 'Venda registrada no sistema comercial', trim(($venda['empreendimento_texto'] ?? '') . ' · ' . ($venda['corretor_texto'] ?? 'corretor não informado'), ' ·'), null, 'importado');

    // Ordena: no mesmo instante, a venda (só data) fica por último no dia.
    usort($ev, function ($a, $b) {
        $da = $a['tipo'] === 'venda' ? substr($a['em'], 0, 10) . ' 23:59:59' : $a['em'];
        $db = $b['tipo'] === 'venda' ? substr($b['em'], 0, 10) . ' 23:59:59' : $b['em'];
        return strcmp($da, $db);
    });
    foreach ($ev as &$e) {
        $e['depois_da_venda'] = $vendaFim && $e['tipo'] !== 'venda' && $e['em'] > $vendaFim;
    }
    unset($e);
    $J['eventos'] = $ev;
    $J['gerentes'] = array_values(array_filter($gerentes));
    $J['superintendencias'] = array_keys($sups);

    // ---------------- métricas (só eventos até a venda)
    $antes = array_filter($ev, fn ($e) => !$e['depois_da_venda'] && $e['tipo'] !== 'venda');
    $primeira = $corr['primeira_entrada_em'] ?: reset($leads)['entrada_em'];
    $minDe = function (array $tipos) use ($antes) {
        $d = array_map(fn ($e) => $e['em'], array_filter($antes, fn ($e) => in_array($e['tipo'], $tipos, true)));
        return $d ? min($d) : null;
    };
    $maxDe = function (array $tipos) use ($antes) {
        $d = array_map(fn ($e) => $e['em'], array_filter($antes, fn ($e) => in_array($e['tipo'], $tipos, true)));
        return $d ? max($d) : null;
    };
    $interacao = ['contato', 'contato_efetivo', 'whatsapp_cliente', 'whatsapp_corretor', 'visita', 'tentativa', 'atendimento'];
    $efetiva = ['contato', 'contato_efetivo', 'whatsapp_cliente', 'visita'];
    $primeiroAtend = $minDe(['atendimento', 'contato_efetivo', 'contato', 'whatsapp_corretor']);
    $primeiraVisita = $minDe(['visita']);
    $ultimaInter = $maxDe($interacao);
    $diasEfetivos = count(array_unique(array_map(fn ($e) => substr($e['em'], 0, 10), array_filter($antes, fn ($e) => in_array($e['tipo'], $efetiva, true)))));
    $respPorCorretor = [];
    foreach ($J['corretores'] as $c) {
        if (!$c['depois_da_venda']) {
            $respPorCorretor[$c['nome']] = ($respPorCorretor[$c['nome']] ?? 0) + $c['dias_ate_venda'];
        }
    }
    arsort($respPorCorretor);
    // Períodos de inatividade: intervalos > N dias entre interações (incluindo a entrada e a venda).
    $marcos = array_values(array_unique(array_merge([$primeira], array_map(fn ($e) => $e['em'], array_filter($antes, fn ($e) => in_array($e['tipo'], $interacao, true)))), SORT_STRING));
    sort($marcos);
    if ($vendaDia) {
        $marcos[] = $vendaDia . ' 00:00:00';
    }
    $inativos = [];
    for ($i = 1; $i < count($marcos); $i++) {
        $d = vendas_dias_entre($marcos[$i - 1], $marcos[$i]);
        if ($d !== null && $d > VENDAS_INATIVIDADE_DIAS) {
            $inativos[] = ['de' => $marcos[$i - 1], 'ate' => $marcos[$i], 'dias' => $d];
        }
    }
    $oferta = array_filter($leads, fn ($l) => $l['oferta_lista_id']) || array_filter($J['corretores'], fn ($c) => !$c['depois_da_venda'] && $c['forma'] === $formas['oferta_ativa']);
    $redistrib = count(array_filter($J['corretores'], fn ($c) => !$c['depois_da_venda'] && in_array($c['forma'], [$formas['redistribuicao']], true)));
    $J['metricas'] = [
        'primeira_entrada' => $primeira,
        'primeira_distribuicao' => $minDe(['distribuicao']),
        'primeiro_corretor' => $J['corretores'][0]['nome'] ?? null,
        'dias_jornada' => in_array($corr['cronologia'], ['anterior', 'mesmo_dia'], true) ? vendas_dias_entre($primeira, $venda['data_venda']) : null,
        'dias_ate_primeiro_atendimento' => $primeiroAtend ? vendas_dias_entre($primeira, $primeiroAtend) : null,
        'dias_primeiro_atendimento_ate_venda' => $primeiroAtend ? vendas_dias_entre($primeiroAtend, $venda['data_venda']) : null,
        'dias_primeira_visita_ate_venda' => $primeiraVisita ? vendas_dias_entre($primeiraVisita, $venda['data_venda']) : null,
        'dias_ultima_interacao_ate_venda' => $ultimaInter ? vendas_dias_entre($ultimaInter, $venda['data_venda']) : null,
        'dias_com_interacao_efetiva' => $diasEfetivos,
        'responsabilidade_por_corretor' => $respPorCorretor,
        'transferencias' => count(array_filter($ev, fn ($e) => $e['tipo'] === 'transferencia' && !$e['depois_da_venda'])),
        'redistribuicoes_inatividade' => $redistrib,
        'tentativas_contato' => count(array_filter($antes, fn ($e) => in_array($e['tipo'], ['tentativa', 'contato'], true))),
        'respostas_whatsapp' => array_sum(array_map(fn ($e) => (int) $e['detalhe'], array_filter($antes, fn ($e) => $e['tipo'] === 'whatsapp_cliente'))),
        'visitas_realizadas' => count(array_filter($antes, fn ($e) => $e['tipo'] === 'visita')),
        'campanhas_remarketing' => $remarketing,
        'jornadas_automaticas' => $jornadasAuto,
        'oferta_ativa' => $oferta ? 'sim' : 'sem_registro',
        'inatividade' => $inativos,
        'oportunidades' => count($leads),
    ];

    // ---------------- empreendimento de entrada × comprado
    $emps = $J['empreendimentos'];
    $primeiroEmp = null;
    foreach ($emps as $e) {
        if ($e['empreendimento_id']) {
            $primeiroEmp = $e;
            break;
        }
    }
    $J['movimento'] = [
        'primeiro' => $primeiroEmp,
        'intermediarios' => array_values(array_filter($emps, fn ($e) => $e['empreendimento_id'] && $e !== $primeiroEmp && (!$vendaDia || substr($e['entrada_em'], 0, 10) <= $vendaDia))),
        'comprado_id' => $venda['empreendimento_id'] ? (int) $venda['empreendimento_id'] : null,
        'comprado_nome' => $venda['emp_crm'] ?: $venda['empreendimento_texto'],
        'resultado' => !$venda['empreendimento_id'] ? 'sem_equivalencia' : (!$primeiroEmp ? 'sem_interesse' : ((int) $primeiroEmp['empreendimento_id'] === (int) $venda['empreendimento_id'] ? 'mesmo' : 'diferente')),
        'comprado_entre_interesses' => $venda['empreendimento_id'] && in_array((int) $venda['empreendimento_id'], array_column($emps, 'empreendimento_id'), true),
    ];
    $J['classificacao'] = vendas_classificacao_comercial($J);
    return $J;
}

/**
 * Classificação comercial PURA (mesma regra na Jornada e no Painel).
 * $equiv: linha de vendas_equivalencias do vendedor (ou null);
 * $resp: responsáveis do CRM na data da venda [[usuario_id, equipe_id], ...];
 * $equipeVendedor: equipe atual do usuário do CRM que é o vendedor (se houver).
 */
function vendas_classificar_comercial(string $situacao, bool $vinculada, ?string $cronologia, ?array $equiv, array $resp, ?int $equipeVendedor): string
{
    if ($situacao === 'ambigua') {
        return 'pendente_revisao';
    }
    if (!$vinculada || $cronologia === 'posterior') {
        return 'nao_aplicavel';
    }
    if (!$equiv || $equiv['classificacao'] === 'nao_identificado' || $equiv['classificacao'] === 'nao_existe') {
        return 'nao_identificado';
    }
    if ($equiv['classificacao'] === 'externo') {
        return 'canal_externo';
    }
    if (!$resp) {
        return 'sem_historico';
    }
    $u = (int) $equiv['usuario_id'];
    if (in_array($u, array_map('intval', array_column($resp, 'usuario_id')), true)) {
        return 'responsavel_crm';
    }
    return $equipeVendedor && in_array($equipeVendedor, array_map('intval', array_column($resp, 'equipe_id')), true) ? 'outro_corretor_mesma_estrutura' : 'outra_equipe';
}

/** Chave da equivalência de corretor de uma venda (id do sistema externo; sem id, o nome comparável). */
function vendas_chave_corretor(array $venda): string
{
    $id = $venda['corretor_id_origem'] ?? null;
    return $id !== null && $id !== '' ? (string) $id : 'nome:' . vendas_nome_comparavel((string) ($venda['corretor_texto'] ?? ''));
}

/** Classificação comercial de uma jornada (análise gerencial; não atribui crédito, comissão nem culpa). */
function vendas_classificacao_comercial(array $J): string
{
    $corr = $J['corr'];
    $venda = $J['venda'];
    $equiv = null;
    $equipeVendedor = null;
    if ($corr && (int) $corr['vinculada']) {
        $st = db()->prepare("SELECT * FROM vendas_equivalencias WHERE empresa_id = :e AND sistema_origem = :s AND tipo = 'corretor' AND valor_origem = :v");
        $st->execute(['e' => $venda['empresa_id'], 's' => $venda['sistema_origem'], 'v' => vendas_chave_corretor($venda)]);
        $equiv = $st->fetch() ?: null;
        if ($equiv && $equiv['usuario_id']) {
            $st = db()->prepare('SELECT equipe_id FROM usuarios WHERE id = :u');
            $st->execute(['u' => $equiv['usuario_id']]);
            $equipeVendedor = (int) $st->fetchColumn() ?: null;
        }
    }
    return vendas_classificar_comercial(vendas_situacao_efetiva($corr), (bool) ($corr['vinculada'] ?? false), $corr['cronologia'] ?? null, $equiv, $J['responsaveis_na_venda'], $equipeVendedor);
}
