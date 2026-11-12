<?php
/**
 * Campanhas de remarketing pelo WhatsApp (Fase 7, seção 11).
 *
 * Só para quem AUTORIZOU receber ofertas (consentimento "marketing"
 * registrado, sem descadastro) e só com template de MARKETING aprovado pela
 * Meta. Nada é presumido: preencher formulário ou estar numa planilha não é
 * autorização. Fluxo:
 *   1. filtros (empreendimento, origem, etapa, datas, corretor, equipe,
 *      gerente, superintendência, sem atendimento/sem resposta há N dias,
 *      campanhas recentes, lista de telefones importada);
 *   2. prévia: quantos entram, quantos ficam de fora e por quê, custo
 *      estimado e limites — antes de criar;
 *   3. criação (rascunho) → confirmação explícita → envio em LOTES pela fila
 *      do cron (nunca tudo de uma vez), sempre pelas regras de envio
 *      (horário, limite de marketing por contato, descadastro na hora);
 *   4. pausar (nada sai até retomar) e cancelar (o que não saiu é cancelado).
 * O público respeita o recorte de leads de quem cria (rbac_leads_scope).
 */
require_once __DIR__ . '/whatsapp.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/funil.php';
require_once __DIR__ . '/auditoria.php';

const CAMPANHA_LOTE = 40;           // envios agendados por campanha a cada rodada do cron
const CAMPANHA_MAX_DESTINOS = 5000; // teto de contatos por campanha
const CAMPANHA_MAX_LISTA = 5000;    // teto de telefones numa planilha

function campanha_motivos_exclusao(): array
{
    return [
        'sem_telefone' => 'Telefone inválido para WhatsApp',
        'duplicado' => 'Telefone repetido (outro lead com o mesmo número)',
        'sem_autorizacao' => 'Não autorizou receber ofertas (marketing)',
        'descadastrado' => 'Pediu para não receber mensagens',
        'campanha_recente' => 'Recebeu campanha recentemente',
        'lead_encerrado' => 'Lead encerrado (contrato, perdido ou sem interesse)',
    ];
}

/** Normaliza os filtros vindos da tela (ids sempre conferidos contra a empresa). */
function campanha_filtros_normalizar(int $empresaId, array $in): array
{
    $ids = function (string $tabela, $valores) use ($empresaId): array {
        $valores = array_values(array_unique(array_filter(array_map('intval', (array) $valores))));
        if (!$valores) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($valores), '?'));
        $stmt = db()->prepare("SELECT id FROM {$tabela} WHERE empresa_id = ? AND id IN ($ph)");
        $stmt->execute(array_merge([$empresaId], $valores));
        $ok = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        // Pediu um filtro e nenhum id vale (outra empresa, apagado): o público fica VAZIO,
        // nunca "sem filtro" — um filtro inválido jamais pode alargar a campanha.
        return $ok ?: [0];
    };
    $data = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : null;
    $dias = fn ($v) => ($n = (int) $v) > 0 ? min($n, 3650) : null;
    $f = [
        'empreendimento_ids' => $ids('empreendimentos', $in['empreendimento_ids'] ?? []),
        'etapa_ids' => $ids('funil_etapas', $in['etapa_ids'] ?? []),
        'corretor_ids' => $ids('usuarios', $in['corretor_ids'] ?? []),
        'equipe_ids' => $ids('equipes', $in['equipe_ids'] ?? []),
        'gerente_ids' => $ids('usuarios', $in['gerente_ids'] ?? []),
        'superintendencia_ids' => $ids('superintendencias', $in['superintendencia_ids'] ?? []),
        'origens' => array_values(array_intersect((array) ($in['origens'] ?? []), array_keys(lead_origens()))),
        'cadastro_de' => $data($in['cadastro_de'] ?? null),
        'cadastro_ate' => $data($in['cadastro_ate'] ?? null),
        'sem_atendimento_dias' => $dias($in['sem_atendimento_dias'] ?? null),
        'sem_resposta_dias' => $dias($in['sem_resposta_dias'] ?? null),
        'excluir_campanha_dias' => isset($in['excluir_campanha_dias']) && $in['excluir_campanha_dias'] !== '' ? max(0, min(90, (int) $in['excluir_campanha_dias'])) : 7,
        'lista' => array_values(array_slice(array_unique(array_filter(array_map(fn ($t) => wa_normalizar_telefone((string) $t), (array) ($in['lista'] ?? [])))), 0, CAMPANHA_MAX_LISTA)),
    ];
    return $f;
}

/**
 * Monta o público: devolve ['elegiveis' => [[lead_id, contato_id, nome]], 'excluidos' => [motivo => n],
 * 'total' => n, 'lista_sem_lead' => n]. Não grava nada.
 */
function campanha_publico(array $usuario, array $f): array
{
    $empresaId = (int) $usuario['empresa_ativa_id'];
    $scope = rbac_leads_scope($usuario);
    $sql = 'SELECT leads.id, leads.nome, leads.telefone, leads.etapa FROM leads
            LEFT JOIN equipes eq ON eq.id = leads.equipe_id
            WHERE leads.empresa_id = :c_e AND ' . $scope['sql'];
    $p = $scope['params'] + ['c_e' => $empresaId];
    $in = function (string $col, array $vals, string $pref) use (&$sql, &$p) {
        if (!$vals) {
            return;
        }
        $ph = [];
        foreach (array_values($vals) as $i => $v) {
            $ph[] = ':' . $pref . $i;
            $p[$pref . $i] = $v;
        }
        $sql .= " AND $col IN (" . implode(',', $ph) . ')';
    };
    $in('leads.empreendimento_id', $f['empreendimento_ids'], 'c_emp');
    $in('leads.etapa_id', $f['etapa_ids'], 'c_et');
    $in('leads.responsavel_id', $f['corretor_ids'], 'c_cor');
    $in('leads.equipe_id', $f['equipe_ids'], 'c_eq');
    $in('eq.gerente_id', $f['gerente_ids'], 'c_ger');
    $in('eq.superintendencia_id', $f['superintendencia_ids'], 'c_sup');
    $in('leads.origem', $f['origens'], 'c_or');
    if ($f['cadastro_de']) {
        $sql .= ' AND leads.criado_em >= :c_de';
        $p['c_de'] = $f['cadastro_de'] . ' 00:00:00';
    }
    if ($f['cadastro_ate']) {
        $sql .= ' AND leads.criado_em <= :c_ate';
        $p['c_ate'] = $f['cadastro_ate'] . ' 23:59:59';
    }
    if ($f['sem_atendimento_dias']) {
        $sql .= ' AND COALESCE(leads.ultimo_feedback_em, leads.criado_em) < DATE_SUB(NOW(), INTERVAL :c_sa DAY)';
        $p['c_sa'] = $f['sem_atendimento_dias'];
    }
    $stmt = db()->prepare($sql . ' ORDER BY leads.id LIMIT 20000');
    $stmt->execute($p);
    $leads = $stmt->fetchAll();

    // Contatos de WhatsApp da empresa, pelo telefone normalizado.
    $stmt = db()->prepare('SELECT id, telefone, opt_out_em, ultima_entrada_em FROM whatsapp_contatos WHERE empresa_id = :e');
    $stmt->execute(['e' => $empresaId]);
    $contatos = [];
    foreach ($stmt->fetchAll() as $c) {
        $contatos[$c['telefone']] = $c;
    }
    // Último consentimento de marketing por contato.
    $stmt = db()->prepare("SELECT c.contato_id, c.acao FROM whatsapp_consentimentos c
                           JOIN (SELECT contato_id, MAX(id) AS mx FROM whatsapp_consentimentos WHERE empresa_id = :e AND finalidade = 'marketing' GROUP BY contato_id) u ON u.mx = c.id");
    $stmt->execute(['e' => $empresaId]);
    $mkt = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $recentes = [];
    if ($f['excluir_campanha_dias'] > 0) {
        $stmt = db()->prepare("SELECT DISTINCT cv.contato_id FROM whatsapp_mensagens m JOIN whatsapp_conversas cv ON cv.id = m.conversa_id
                               WHERE m.empresa_id = :e AND m.campanha_id IS NOT NULL AND m.status NOT IN ('cancelada','falhou')
                                 AND m.criado_em >= DATE_SUB(NOW(), INTERVAL :d DAY)");
        $stmt->execute(['e' => $empresaId, 'd' => $f['excluir_campanha_dias']]);
        $recentes = array_flip(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }
    $lista = $f['lista'] ? array_flip($f['lista']) : null;
    $excl = array_fill_keys(array_keys(campanha_motivos_exclusao()), 0);
    $eleg = [];
    $vistos = [];
    $naLista = [];
    $limiteResposta = $f['sem_resposta_dias'] ? time() - $f['sem_resposta_dias'] * 86400 : null;
    $total = 0;
    foreach ($leads as $l) {
        $tel = wa_normalizar_telefone((string) $l['telefone']);
        if ($lista !== null) {
            if (!$tel || !isset($lista[$tel])) {
                continue;
            }
            $naLista[$tel] = true;
        }
        $c = $tel ? ($contatos[$tel] ?? null) : null;
        // "Sem resposta há N dias": o cliente não escreveu nesse período (ou nunca).
        if ($limiteResposta && $c && $c['ultima_entrada_em'] && strtotime($c['ultima_entrada_em']) >= $limiteResposta) {
            continue;
        }
        $total++;
        if (!$tel) {
            $excl['sem_telefone']++;
        } elseif (isset($vistos[$tel])) {
            $excl['duplicado']++;
        } elseif (in_array($l['etapa'], funil_categorias_encerradas(), true)) {
            $excl['lead_encerrado']++;
        } elseif ($c && $c['opt_out_em']) {
            $excl['descadastrado']++;
        } elseif (!$c || ($mkt[$c['id']] ?? '') !== 'concedido') {
            $excl['sem_autorizacao']++;
        } elseif (isset($recentes[(int) $c['id']])) {
            $excl['campanha_recente']++;
        } else {
            $eleg[] = ['lead_id' => (int) $l['id'], 'contato_id' => (int) $c['id'], 'nome' => $l['nome']];
        }
        if ($tel) {
            $vistos[$tel] = true;
        }
    }
    return ['total' => $total, 'elegiveis' => array_slice($eleg, 0, CAMPANHA_MAX_DESTINOS), 'acima_do_teto' => max(0, count($eleg) - CAMPANHA_MAX_DESTINOS),
            'excluidos' => array_filter($excl), 'lista_sem_lead' => $lista !== null ? count($lista) - count($naLista) : null];
}

/** Templates que podem ser usados em campanha: MARKETING, aprovados, sem arquivo no cabeçalho. */
function campanha_templates(int $empresaId): array
{
    $stmt = db()->prepare("SELECT * FROM whatsapp_templates WHERE empresa_id = :e AND status = 'aprovado' AND categoria = 'marketing'
                           AND cabecalho_tipo IN ('nenhum','texto') ORDER BY nome");
    $stmt->execute(['e' => $empresaId]);
    return $stmt->fetchAll();
}

/** Prévia para a tela (antes de criar). */
function campanha_previa(array $usuario, array $filtros, int $templateId): array
{
    $empresaId = (int) $usuario['empresa_ativa_id'];
    $tpl = wa_template($empresaId, $templateId);
    $erros = [];
    if (!$tpl || $tpl['status'] !== 'aprovado' || $tpl['categoria'] !== 'marketing') {
        $erros[] = 'Escolha um template de MARKETING aprovado pela Meta.';
    }
    $f = campanha_filtros_normalizar($empresaId, $filtros);
    $pub = campanha_publico($usuario, $f);
    $n = count($pub['elegiveis']);
    $tarifa = wa_tarifa($empresaId, 'marketing');
    $cfg = wa_config($empresaId);
    $motivos = campanha_motivos_exclusao();
    $excluidos = [];
    foreach ($pub['excluidos'] as $k => $q) {
        $excluidos[] = ['motivo' => $motivos[$k] ?? $k, 'quantidade' => $q];
    }
    $conta = wa_conta_da_empresa($empresaId);
    return [
        'ok' => !$erros, 'erros' => $erros, 'filtros' => $f, 'total' => $pub['total'], 'elegiveis' => $n, 'excluidos' => $excluidos,
        'acima_do_teto' => $pub['acima_do_teto'], 'lista_sem_lead' => $pub['lista_sem_lead'],
        'custo_estimado' => $tarifa !== null ? round($tarifa * $n, 2) : null, 'tarifa' => $tarifa,
        'amostra' => array_map(fn ($e) => $e['nome'], array_slice($pub['elegiveis'], 0, 5)),
        'limites' => [
            'Envio só entre ' . substr($cfg['envio_inicio'], 0, 5) . ' e ' . substr($cfg['envio_fim'], 0, 5) . ' (fora disso, espera o próximo horário).',
            'No máximo ' . (int) $cfg['max_marketing_7d'] . ' mensagem(ns) de marketing por contato a cada 7 dias.',
            'Sai em lotes de ' . CAMPANHA_LOTE . ' a cada rodada da rotina automática (a cada 15 min) — não é instantâneo.',
            'Quem pedir para sair durante a campanha não recebe mais nada.',
        ],
        'modo_teste' => $conta && (int) $conta['modo_teste'] === 1,
        'template' => $tpl ? ['nome' => $tpl['nome'], 'corpo' => $tpl['corpo']] : null,
    ];
}

/** Cria a campanha em rascunho, com os destinatários elegíveis congelados. */
function campanha_criar(array $usuario, string $nome, int $templateId, array $filtros, ?string $agendadaPara = null): array
{
    $empresaId = (int) $usuario['empresa_ativa_id'];
    $nome = trim($nome);
    if (mb_strlen($nome) < 3) {
        return ['ok' => false, 'erro' => 'Dê um nome à campanha.'];
    }
    $conta = wa_conta_da_empresa($empresaId);
    if (!$conta) {
        return ['ok' => false, 'erro' => 'Conecte o WhatsApp da empresa primeiro.'];
    }
    $pv = campanha_previa($usuario, $filtros, $templateId);
    if (!$pv['ok']) {
        return ['ok' => false, 'erro' => implode(' ', $pv['erros'])];
    }
    if (!$pv['elegiveis']) {
        return ['ok' => false, 'erro' => 'Nenhum contato autorizado com esses filtros: nada a enviar.'];
    }
    $quando = null;
    if ($agendadaPara) {
        $quando = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $agendadaPara, new DateTimeZone('America/Sao_Paulo'));
        if (!$quando || $quando < new DateTimeImmutable('-5 minutes', new DateTimeZone('America/Sao_Paulo'))) {
            return ['ok' => false, 'erro' => 'Data de agendamento inválida (precisa ser no futuro).'];
        }
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO whatsapp_campanhas (empresa_id, conta_id, nome, template_id, filtros, status, total_elegiveis, custo_estimado, agendada_para, criado_por)
                       VALUES (:e, :c, :n, :t, :f, \'rascunho\', :tot, :custo, :ag, :u)')
            ->execute(['e' => $empresaId, 'c' => $conta['id'], 'n' => mb_substr($nome, 0, 120), 't' => $templateId,
                       'f' => json_encode(['filtros' => array_diff_key($pv['filtros'], ['lista' => 1]) + ['lista_qtd' => count($pv['filtros']['lista'])],
                                           'excluidos' => $pv['excluidos'], 'total' => $pv['total']]),
                       'tot' => $pv['elegiveis'], 'custo' => $pv['custo_estimado'], 'ag' => $quando ? $quando->format('Y-m-d H:i:s') : null, 'u' => $usuario['id']]);
        $id = (int) $pdo->lastInsertId();
        $pub = campanha_publico($usuario, $pv['filtros']);
        $ins = $pdo->prepare('INSERT IGNORE INTO whatsapp_campanha_destinatarios (campanha_id, contato_id, lead_id, status) VALUES (:c, :ct, :l, \'pendente\')');
        foreach ($pub['elegiveis'] as $d) {
            $ins->execute(['c' => $id, 'ct' => $d['contato_id'], 'l' => $d['lead_id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    auditoria_registrar($empresaId, (int) $usuario['id'], 'campanha.criada', 'whatsapp_campanha', $id, ['elegiveis' => $pv['elegiveis']]);
    return ['ok' => true, 'id' => $id];
}

function campanha_carregar(int $empresaId, int $id): ?array
{
    $stmt = db()->prepare('SELECT c.*, t.nome AS template_nome, t.corpo AS template_corpo, u.nome AS criado_por_nome, cf.nome AS confirmada_por_nome
                           FROM whatsapp_campanhas c LEFT JOIN whatsapp_templates t ON t.id = c.template_id
                           LEFT JOIN usuarios u ON u.id = c.criado_por LEFT JOIN usuarios cf ON cf.id = c.confirmada_por
                           WHERE c.id = :id AND c.empresa_id = :e');
    $stmt->execute(['id' => $id, 'e' => $empresaId]);
    $c = $stmt->fetch();
    if ($c) {
        $c['dados'] = json_decode((string) $c['filtros'], true) ?: [];
    }
    return $c ?: null;
}

/**
 * Ações de quem gerencia: confirmar (inicia ou agenda), pausar, retomar, cancelar.
 * Confirmar exige o número de contatos digitado pela pessoa (confirmação consciente).
 */
function campanha_acao(array $usuario, int $id, string $acao, ?int $confirmacaoQtd = null): array
{
    $empresaId = (int) $usuario['empresa_ativa_id'];
    $c = campanha_carregar($empresaId, $id);
    if (!$c) {
        return ['ok' => false, 'erro' => 'Campanha não encontrada.'];
    }
    $pdo = db();
    $set = function (string $status, string $extra = '') use ($pdo, $id, $empresaId) {
        $pdo->prepare("UPDATE whatsapp_campanhas SET status = :s, atualizado_em = NOW() $extra WHERE id = :id AND empresa_id = :e")
            ->execute(['s' => $status, 'id' => $id, 'e' => $empresaId]);
    };
    switch ($acao) {
        case 'confirmar':
            if ($c['status'] !== 'rascunho') {
                return ['ok' => false, 'erro' => 'Esta campanha já foi confirmada.'];
            }
            if ($confirmacaoQtd !== (int) $c['total_elegiveis']) {
                return ['ok' => false, 'erro' => 'Para confirmar, digite o número de contatos que vão receber (' . (int) $c['total_elegiveis'] . ').'];
            }
            $tpl = wa_template($empresaId, (int) $c['template_id']);
            if (!$tpl || $tpl['status'] !== 'aprovado') {
                return ['ok' => false, 'erro' => 'O template não está mais aprovado pela Meta.'];
            }
            $agendada = $c['agendada_para'] && strtotime($c['agendada_para']) > time();
            $pdo->prepare('UPDATE whatsapp_campanhas SET status = :s, confirmada_por = :u, confirmada_em = NOW(), atualizado_em = NOW() WHERE id = :id AND empresa_id = :e')
                ->execute(['s' => $agendada ? 'agendada' : 'enviando', 'u' => $usuario['id'], 'id' => $id, 'e' => $empresaId]);
            $msg = $agendada ? 'Campanha agendada para ' . date('d/m H:i', strtotime($c['agendada_para'])) . '.' : 'Campanha iniciada: os envios saem em lotes pela rotina automática.';
            break;
        case 'pausar':
            if (!in_array($c['status'], ['enviando', 'agendada'], true)) {
                return ['ok' => false, 'erro' => 'Só dá para pausar campanha em andamento ou agendada.'];
            }
            $set('pausada');
            $msg = 'Campanha pausada: nada mais sai até você retomar.';
            break;
        case 'retomar':
            if ($c['status'] !== 'pausada') {
                return ['ok' => false, 'erro' => 'A campanha não está pausada.'];
            }
            $set('enviando');
            // O que ficou adiado na fila durante a pausa volta a valer agora.
            $pdo->prepare("UPDATE automacao_fila f JOIN whatsapp_mensagens m ON f.chave_unica = CONCAT('wa_enviar:', m.id)
                           SET f.executar_em = NOW() WHERE m.empresa_id = :e AND m.campanha_id = :c AND m.status = 'na_fila' AND f.status = 'pendente'")
                ->execute(['e' => $empresaId, 'c' => $id]);
            $msg = 'Campanha retomada.';
            break;
        case 'cancelar':
            if (!in_array($c['status'], ['rascunho', 'agendada', 'enviando', 'pausada'], true)) {
                return ['ok' => false, 'erro' => 'Esta campanha já terminou.'];
            }
            $set('cancelada');
            $pdo->prepare("UPDATE whatsapp_campanha_destinatarios SET status = 'cancelada', motivo = 'Campanha cancelada', atualizado_em = NOW()
                           WHERE campanha_id = :c AND status = 'pendente'")->execute(['c' => $id]);
            $stmt = $pdo->prepare("SELECT id FROM whatsapp_mensagens WHERE empresa_id = :e AND campanha_id = :c AND status = 'na_fila'");
            $stmt->execute(['e' => $empresaId, 'c' => $id]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $mid) {
                $pdo->prepare("UPDATE whatsapp_mensagens SET status = 'cancelada', erro_texto = 'Campanha cancelada' WHERE id = :id AND status = 'na_fila'")->execute(['id' => $mid]);
                $pdo->prepare("UPDATE automacao_fila SET status = 'cancelada' WHERE chave_unica = :k AND status = 'pendente'")->execute(['k' => 'wa_enviar:' . $mid]);
            }
            $msg = 'Campanha cancelada: as mensagens que ainda não tinham saído foram canceladas.';
            break;
        default:
            return ['ok' => false, 'erro' => 'Ação inválida.'];
    }
    auditoria_registrar($empresaId, (int) $usuario['id'], 'campanha.' . $acao, 'whatsapp_campanha', $id, []);
    return ['ok' => true, 'mensagem' => $msg];
}

/**
 * Rotina (cron): começa as agendadas que venceram e agenda o próximo lote
 * de cada campanha em andamento. Cada destinatário vira UMA mensagem
 * (idempotência por campanha + contato); a regra de envio decide na hora.
 */
function campanhas_processar(int $lote = CAMPANHA_LOTE): array
{
    $pdo = db();
    $pdo->exec("UPDATE whatsapp_campanhas SET status = 'enviando', atualizado_em = NOW() WHERE status = 'agendada' AND agendada_para <= NOW()");
    $r = ['campanhas' => 0, 'agendadas' => 0, 'ignoradas' => 0, 'concluidas' => 0];
    foreach ($pdo->query("SELECT * FROM whatsapp_campanhas WHERE status = 'enviando' ORDER BY id")->fetchAll() as $c) {
        $r['campanhas']++;
        $empresaId = (int) $c['empresa_id'];
        $stmt = $pdo->prepare("SELECT * FROM whatsapp_campanha_destinatarios WHERE campanha_id = :c AND status = 'pendente' ORDER BY id LIMIT " . max(1, $lote));
        $stmt->execute(['c' => $c['id']]);
        $dest = $stmt->fetchAll();
        foreach ($dest as $d) {
            $res = wa_enfileirar($empresaId, (int) $d['contato_id'],
                ['tipo' => 'template', 'template_id' => (int) $c['template_id'], 'dados' => wa_dados_lead($empresaId, $d['lead_id'] ? (int) $d['lead_id'] : null)],
                ['campanha_id' => (int) $c['id'], 'idempotencia' => 'campanha:' . $c['id'] . ':' . $d['contato_id'], 'lead_id' => $d['lead_id'] ? (int) $d['lead_id'] : null]);
            if ($res['ok']) {
                $pdo->prepare("UPDATE whatsapp_campanha_destinatarios SET status = 'enviada', mensagem_id = :m, atualizado_em = NOW() WHERE id = :id")
                    ->execute(['m' => $res['mensagem_id'], 'id' => $d['id']]);
                $r['agendadas']++;
            } else {
                $pdo->prepare("UPDATE whatsapp_campanha_destinatarios SET status = 'ignorada', motivo = :m, atualizado_em = NOW() WHERE id = :id")
                    ->execute(['m' => mb_substr((string) ($res['motivo'] ?? 'Bloqueada pelas regras de envio'), 0, 190), 'id' => $d['id']]);
                $r['ignoradas']++;
            }
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_campanha_destinatarios WHERE campanha_id = :c AND status = 'pendente'");
        $stmt->execute(['c' => $c['id']]);
        if (!(int) $stmt->fetchColumn()) {
            $pdo->prepare("UPDATE whatsapp_campanhas SET status = 'concluida', atualizado_em = NOW() WHERE id = :id AND status = 'enviando'")->execute(['id' => $c['id']]);
            $r['concluidas']++;
        }
    }
    return $r;
}

/**
 * Resultados de uma campanha (status real vem da mensagem; "respondeu" =
 * o cliente escreveu em até 7 dias depois do envio; descadastro idem).
 */
function campanha_resultados(int $empresaId, int $id): array
{
    $stmt = db()->prepare("SELECT d.status AS d_status, m.status AS m_status, m.enviada_em, m.custo_estimado,
                (SELECT MIN(e.criado_em) FROM whatsapp_mensagens e WHERE e.conversa_id = m.conversa_id AND e.direcao = 'entrada'
                    AND e.criado_em >= m.criado_em AND e.criado_em < DATE_ADD(m.criado_em, INTERVAL 7 DAY)) AS respondeu_em,
                ct.opt_out_em
            FROM whatsapp_campanha_destinatarios d
            JOIN whatsapp_campanhas c ON c.id = d.campanha_id AND c.empresa_id = :e
            JOIN whatsapp_contatos ct ON ct.id = d.contato_id
            LEFT JOIN whatsapp_mensagens m ON m.id = d.mensagem_id
            WHERE d.campanha_id = :c");
    $stmt->execute(['e' => $empresaId, 'c' => $id]);
    $r = ['destinatarios' => 0, 'aguardando' => 0, 'na_fila' => 0, 'enviadas' => 0, 'simuladas' => 0, 'entregues' => 0, 'lidas' => 0, 'falharam' => 0,
          'nao_enviadas' => 0, 'canceladas' => 0, 'respostas' => 0, 'descadastros' => 0, 'custo' => 0.0];
    foreach ($stmt->fetchAll() as $l) {
        $r['destinatarios']++;
        if ($l['d_status'] === 'pendente') {
            $r['aguardando']++;
        } elseif ($l['d_status'] === 'ignorada') {
            $r['nao_enviadas']++;
        } elseif ($l['d_status'] === 'cancelada' || $l['m_status'] === 'cancelada') {
            $r['canceladas']++;
        } else {
            $ms = $l['m_status'];
            if ($ms === 'na_fila' || $ms === 'enviando') {
                $r['na_fila']++;
            } elseif ($ms === 'falhou') {
                $r['falharam']++;
            } elseif ($ms === 'simulada') {
                $r['simuladas']++;
            } else {
                $r['enviadas']++;
                if (in_array($ms, ['entregue', 'lida'], true)) {
                    $r['entregues']++;
                }
                if ($ms === 'lida') {
                    $r['lidas']++;
                }
                $r['custo'] += (float) $l['custo_estimado'];
            }
            if ($l['respondeu_em']) {
                $r['respostas']++;
            }
            if ($l['opt_out_em'] && $l['enviada_em'] && $l['opt_out_em'] >= $l['enviada_em']) {
                $r['descadastros']++;
            }
        }
    }
    $r['custo'] = round($r['custo'], 2);
    $r['custo_por_resposta'] = $r['respostas'] && $r['custo'] > 0 ? round($r['custo'] / $r['respostas'], 2) : null;
    return $r;
}

/** Lê os telefones de uma planilha (CSV/XLSX): [telefones normalizados, estatísticas] ou [null, ['erro' => …]]. */
function campanha_ler_lista(string $caminho, string $ext): array
{
    require_once __DIR__ . '/importacao.php';
    $lido = importacao_ler($caminho, $ext);
    if (!$lido['ok']) {
        return [null, ['erro' => $lido['erro']]];
    }
    $linhas = $lido['linhas'];
    $cab = array_map(fn ($c) => importacao_normalizar_rotulo((string) $c), $linhas[0] ?? []);
    $col = null;
    foreach ($cab as $i => $c) {
        if (preg_match('/(telefone|celular|whats|fone|phone)/', $c)) {
            $col = $i;
            break;
        }
    }
    $inicio = $col === null ? 0 : 1;
    $col = $col ?? 0;
    $tels = [];
    $invalidos = 0;
    $repetidos = 0;
    foreach (array_slice($linhas, $inicio) as $l) {
        $bruto = trim((string) ($l[$col] ?? ''));
        if ($bruto === '') {
            continue;
        }
        $t = wa_normalizar_telefone($bruto);
        if (!$t) {
            $invalidos++;
        } elseif (isset($tels[$t])) {
            $repetidos++;
        } else {
            $tels[$t] = true;
        }
    }
    return [array_slice(array_keys($tels), 0, CAMPANHA_MAX_LISTA), ['validos' => count($tels), 'invalidos' => $invalidos, 'repetidos' => $repetidos]];
}
