<?php
/**
 * Consulta de leads para Kanban, lista "Leads" e Início — sempre dentro
 * de rbac_leads_scope() (empresa + escopo da função + não excluídos); os
 * filtros só restringem, nunca abrem exceção ao escopo.
 */
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/leads.php';
require_once __DIR__ . '/funil.php';

/** Lê os filtros da querystring, validando cada um. */
function leads_filtros_da_requisicao(array $get): array
{
    $periodos = [7, 30, 90];
    $f = [
        'q' => trim((string) ($get['q'] ?? '')),
        'empreendimento' => (int) ($get['empreendimento'] ?? 0) ?: null,
        'periodo' => in_array((int) ($get['periodo'] ?? 0), $periodos, true) ? (int) $get['periodo'] : null,
        'etapa' => (int) ($get['etapa'] ?? 0) ?: null,
        'prioridade' => array_key_exists($get['prioridade'] ?? '', lead_prioridades()) ? $get['prioridade'] : null,
        'responsavel' => (int) ($get['responsavel'] ?? 0) ?: null,
        'situacao' => in_array($get['situacao'] ?? '', ['abertos', 'atrasados', 'encerrados'], true) ? $get['situacao'] : null,
        // Lista de oferta ativa (importação). Só restringe — o escopo continua valendo.
        'lista' => (int) ($get['lista'] ?? 0) ?: null,
    ];
    $f['q'] = mb_substr($f['q'], 0, 80);
    return $f;
}

/** Monta WHERE + params a partir do escopo e dos filtros. */
function leads_where(array $usuario, array $f): array
{
    $scope = rbac_leads_scope($usuario);
    $sql = $scope['sql'];
    $params = $scope['params'];

    if ($f['q'] !== '') {
        $digitos = preg_replace('/\D/', '', $f['q']);
        $sql .= ' AND (leads.nome LIKE :f_q' . (strlen($digitos) >= 4 ? ' OR ' . lead_sql_telefone_digitos('leads.telefone') . ' LIKE :f_tel' : '') . ')';
        $params['f_q'] = '%' . addcslashes($f['q'], '%_\\') . '%';
        if (strlen($digitos) >= 4) {
            $params['f_tel'] = '%' . $digitos . '%';
        }
    }
    if ($f['empreendimento']) { $sql .= ' AND leads.empreendimento_id = :f_emp'; $params['f_emp'] = $f['empreendimento']; }
    if ($f['periodo']) { $sql .= ' AND leads.criado_em >= :f_desde'; $params['f_desde'] = date('Y-m-d', strtotime("-{$f['periodo']} days")); }
    if ($f['etapa']) { $sql .= ' AND leads.etapa_id = :f_etapa'; $params['f_etapa'] = $f['etapa']; }
    if ($f['prioridade']) { $sql .= ' AND leads.prioridade = :f_prio'; $params['f_prio'] = $f['prioridade']; }
    if ($f['responsavel']) { $sql .= ' AND leads.responsavel_id = :f_resp'; $params['f_resp'] = $f['responsavel']; }
    if (!empty($f['lista'])) { $sql .= ' AND leads.oferta_lista_id = :f_lista'; $params['f_lista'] = $f['lista']; }
    if ($f['situacao'] === 'abertos' || $f['situacao'] === 'atrasados') {
        $sql .= " AND leads.etapa NOT IN ('sem_interesse','contrato_assinado','perdido')";
    }
    if ($f['situacao'] === 'encerrados') {
        $sql .= " AND leads.etapa IN ('sem_interesse','contrato_assinado','perdido')";
    }
    if ($f['situacao'] === 'atrasados') {
        $sql .= " AND (leads.data_retorno < CURDATE()
                   OR EXISTS (SELECT 1 FROM agenda_compromissos ag WHERE ag.lead_id = leads.id AND ag.status = 'agendado' AND ag.inicio < NOW())
                   OR (leads.etapa IN ('novo_lead','primeiro_contato') AND COALESCE(leads.ultima_atividade_em, leads.criado_em) < DATE_SUB(NOW(), INTERVAL 48 HOUR)))";
    }
    return [$sql, $params];
}

/** Colunas extras de cada lead: nomes, próximo compromisso e se há compromisso vencido. */
function leads_select_colunas(): string
{
    return "leads.*, empreendimentos.nome AS empreendimento_nome, usuarios.nome AS responsavel_nome,
        funil_etapas.nome AS etapa_nome, funil_etapas.cor AS etapa_cor,
        (SELECT ag.id FROM agenda_compromissos ag WHERE ag.lead_id = leads.id AND ag.status = 'agendado' ORDER BY ag.inicio LIMIT 1) AS proximo_compromisso_id,
        EXISTS (SELECT 1 FROM agenda_compromissos ag2 WHERE ag2.lead_id = leads.id AND ag2.status = 'agendado' AND ag2.inicio < NOW()) AS compromisso_vencido";
}

function leads_from(): string
{
    return 'FROM leads
        LEFT JOIN empreendimentos ON empreendimentos.id = leads.empreendimento_id
        LEFT JOIN usuarios ON usuarios.id = leads.responsavel_id
        LEFT JOIN funil_etapas ON funil_etapas.id = leads.etapa_id';
}

/** Lista leads filtrados. $ordem: 'urgencia' | 'recentes'. */
function leads_listar(array $usuario, array $f, int $limite = 500, int $offset = 0, string $ordem = 'urgencia'): array
{
    [$where, $params] = leads_where($usuario, $f);
    $orderBy = $ordem === 'recentes'
        ? 'leads.criado_em DESC'
        : "(leads.prioridade = 'alta') DESC, compromisso_vencido DESC, (leads.data_retorno IS NOT NULL AND leads.data_retorno <= CURDATE()) DESC, COALESCE(leads.ultima_atividade_em, leads.criado_em) ASC";
    $stmt = db()->prepare('SELECT ' . leads_select_colunas() . ' ' . leads_from() . ' WHERE ' . $where . ' ORDER BY ' . $orderBy . ' LIMIT ' . (int) $limite . ' OFFSET ' . (int) $offset);
    $stmt->execute($params);
    $leads = $stmt->fetchAll();
    return leads_anexar_compromissos($leads);
}

function leads_contar(array $usuario, array $f): int
{
    [$where, $params] = leads_where($usuario, $f);
    $stmt = db()->prepare('SELECT COUNT(*) FROM leads WHERE ' . $where);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/** Carrega o próximo compromisso de cada lead (uma consulta só). */
function leads_anexar_compromissos(array $leads): array
{
    $ids = array_values(array_filter(array_map(fn ($l) => (int) $l['proximo_compromisso_id'], $leads)));
    $compromissos = [];
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("SELECT id, tipo, titulo, inicio FROM agenda_compromissos WHERE id IN ({$ph})");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $c) {
            $compromissos[(int) $c['id']] = $c;
        }
    }
    foreach ($leads as &$lead) {
        $lead['proximo_compromisso'] = $compromissos[(int) $lead['proximo_compromisso_id']] ?? null;
        $lead['atrasado'] = lead_esta_atrasado($lead);
    }
    return $leads;
}

/** Texto curto da próxima ação de um lead (card/lista). */
function lead_proxima_acao(array $lead): string
{
    if ($lead['proximo_compromisso']) {
        $tipos = ['visita' => 'Visita', 'retorno' => 'Retorno', 'tarefa' => 'Tarefa'];
        return ($tipos[$lead['proximo_compromisso']['tipo']] ?? 'Compromisso') . ' · ' . data_amigavel($lead['proximo_compromisso']['inicio']);
    }
    if (!empty($lead['proximo_passo'])) {
        return $lead['proximo_passo'] . (!empty($lead['data_retorno']) ? ' · ' . date('d/m', strtotime($lead['data_retorno'])) : '');
    }
    return in_array($lead['etapa'], ['sem_interesse', 'contrato_assinado', 'perdido'], true) ? 'Atendimento encerrado' : 'Definir próximo passo';
}

/** Responsáveis que o usuário pode usar como filtro (mesmo recorte de pessoas). */
function leads_responsaveis_filtro(array $usuario): array
{
    if (($usuario['escopo'] ?? '') === 'propria_carteira') {
        return [];
    }
    return rbac_usuarios_atribuiveis($usuario);
}

/** Nome da lista de oferta ativa do filtro (só se for da empresa do usuário). */
function leads_lista_oferta_nome(array $usuario, ?int $listaId): ?string
{
    if (!$listaId) {
        return null;
    }
    $stmt = db()->prepare('SELECT nome FROM oferta_listas WHERE id = :id AND empresa_id = :e');
    $stmt->execute(['id' => $listaId, 'e' => (int) $usuario['empresa_ativa_id']]);
    return $stmt->fetchColumn() ?: null;
}
