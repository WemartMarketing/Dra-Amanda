<?php
/**
 * Helpers compartilhados sobre leads (rótulos, histórico, telefone,
 * tempo relativo, regra de atraso). A etapa configurável vive em
 * includes/funil.php.
 */

/** Ordem linear das categorias do funil usada pelo funil de conversão dos Relatórios. */
function lead_etapas_funil(): array
{
    return ['novo_lead', 'primeiro_contato', 'em_atendimento', 'visita_agendada', 'visita_realizada', 'contrato_assinado'];
}

/** Rótulo de uma categoria (quando não há o nome da etapa configurada à mão). */
function lead_etapa_rotulo(string $etapa): string
{
    return [
        'novo_lead'         => 'Tentando contato',
        'primeiro_contato'  => 'Primeiro contato',
        'em_atendimento'    => 'Em atendimento',
        'visita_agendada'   => 'Visita agendada',
        'visita_realizada'  => 'Visita realizada',
        'sem_interesse'     => 'Sem interesse',
        'contrato_assinado' => 'Contrato assinado',
        'perdido'           => 'Perdido',
    ][$etapa] ?? $etapa;
}

/** Tipos de evento que contam como atividade de atendimento (atualizam ultima_atividade_em). */
function lead_tipos_atividade(): array
{
    return ['contato', 'ligacao', 'whatsapp', 'visita', 'nota', 'agenda', 'mudanca_etapa'];
}

/** Rótulos e ícones dos tipos de evento do histórico. */
function lead_tipos_evento(): array
{
    return [
        'contato'        => ['rotulo' => 'Contato registrado', 'icon' => 'user'],
        'ligacao'        => ['rotulo' => 'Ligação',            'icon' => 'phone'],
        'whatsapp'       => ['rotulo' => 'WhatsApp',           'icon' => 'whatsapp'],
        'visita'         => ['rotulo' => 'Visita',             'icon' => 'building'],
        'nota'           => ['rotulo' => 'Observação',         'icon' => 'note'],
        'agenda'         => ['rotulo' => 'Agenda',             'icon' => 'calendar'],
        'mudanca_etapa'  => ['rotulo' => 'Etapa',              'icon' => 'kanban'],
        'atribuicao'     => ['rotulo' => 'Atribuição',         'icon' => 'target'],
        'transferencia'  => ['rotulo' => 'Transferência',      'icon' => 'users'],
        'redistribuicao' => ['rotulo' => 'Redistribuição',     'icon' => 'users'],
        'arquivo'        => ['rotulo' => 'Arquivo',            'icon' => 'file'],
        'sistema'        => ['rotulo' => 'Sistema',            'icon' => 'zap'],
        'recuperacao'    => ['rotulo' => 'Recuperação',        'icon' => 'alert'],
        'importacao'     => ['rotulo' => 'Importação',         'icon' => 'list'],
        'integracao'     => ['rotulo' => 'Meta Ads',           'icon' => 'zap'],
    ];
}

/**
 * Marca um FEEDBACK VÁLIDO do atendimento (leads.ultimo_feedback_em), o
 * relógio da recuperação automática por inatividade (seção 11).
 *
 * Só estas ações chamam aqui — elas comprovam atendimento real:
 *   - mudança de etapa (lead_mudar_etapa)
 *   - "Registrar atendimento" com o texto do que aconteceu preenchido
 *   - "Registrar contato" com texto (registro rápido do tipo contato)
 *   - compromisso da agenda marcado como realizado ou cliente ausente
 *     (inclui visita realizada)
 * e estas reiniciam o prazo para o novo dono:
 *   - lead atribuído a um corretor (cadastro, roleta, transferência,
 *     distribuição de lista de oferta ativa)
 *
 * NÃO contam (seção 11: "não considerar qualquer anotação"): observações,
 * envio de arquivos, apertar os botões de WhatsApp/Ligar, editar dados do
 * cadastro, apenas criar um compromisso na agenda, eventos de sistema.
 */
function lead_registrar_feedback(int $leadId): void
{
    db()->prepare('UPDATE leads SET ultimo_feedback_em = NOW() WHERE id = :id')->execute(['id' => $leadId]);
}

/** Grava uma linha no histórico (lead_eventos). Atividades de atendimento também atualizam ultima_atividade_em. */
function lead_registrar_evento(int $leadId, ?int $usuarioId, string $tipo, string $descricao): void
{
    db()->prepare('INSERT INTO lead_eventos (lead_id, usuario_id, tipo, descricao) VALUES (:lead_id, :usuario_id, :tipo, :descricao)')
        ->execute(['lead_id' => $leadId, 'usuario_id' => $usuarioId, 'tipo' => $tipo, 'descricao' => mb_substr($descricao, 0, 500)]);
    if (in_array($tipo, lead_tipos_atividade(), true)) {
        db()->prepare('UPDATE leads SET ultima_atividade_em = NOW() WHERE id = :id')->execute(['id' => $leadId]);
    }
}

/** Somente dígitos; remove o 55 do país quando vier junto. */
function lead_telefone_normalizado(?string $telefone): string
{
    $digitos = preg_replace('/\D/', '', (string) $telefone);
    if (strlen($digitos) > 11 && strpos($digitos, '55') === 0) {
        $digitos = substr($digitos, 2);
    }
    return $digitos;
}

/**
 * Expressão SQL que reduz a coluna de telefone a dígitos. Usa REPLACE em
 * cadeia (e não REGEXP_REPLACE) para funcionar também no MySQL 5.7, comum
 * em hospedagem compartilhada.
 */
function lead_sql_telefone_digitos(string $coluna): string
{
    $expr = "COALESCE({$coluna}, '')";
    foreach (['(', ')', ' ', '-', '+', '.', '/'] as $c) {
        $expr = "REPLACE({$expr}, '{$c}', '')";
    }
    return $expr;
}

/** Link do WhatsApp (sempre com DDI 55), ou null sem telefone. */
function lead_link_whatsapp(?string $telefone): ?string
{
    $digitos = lead_telefone_normalizado($telefone);
    return strlen($digitos) >= 10 ? 'https://wa.me/55' . $digitos : null;
}

/** Formata um telefone brasileiro (10 ou 11 dígitos) para exibição. */
function lead_formatar_telefone(?string $telefone): string
{
    if (!$telefone) {
        return '—';
    }
    $digitos = lead_telefone_normalizado($telefone);
    if (strlen($digitos) === 11) {
        return sprintf('(%s) %s-%s', substr($digitos, 0, 2), substr($digitos, 2, 5), substr($digitos, 7));
    }
    if (strlen($digitos) === 10) {
        return sprintf('(%s) %s-%s', substr($digitos, 0, 2), substr($digitos, 2, 4), substr($digitos, 6));
    }
    return $telefone;
}

/** "Há 5 min" / "Há 3 h" / "Há 2 d". */
function tempo_relativo(?string $data): string
{
    if (!$data) {
        return '—';
    }
    $min = max(0, (int) round((time() - strtotime($data)) / 60));
    if ($min < 2) return 'Agora há pouco';
    if ($min < 60) return "Há {$min} min";
    $h = (int) round($min / 60);
    if ($h < 24) return "Há {$h} h";
    return 'Há ' . (int) round($h / 24) . ' d';
}

/** "Hoje, 14h30" / "Amanhã, 09h00" / "12/11, 10h00". */
function data_amigavel(string $dataHora): string
{
    $ts = strtotime($dataHora);
    $hora = date('H\hi', $ts);
    $dia = date('Y-m-d', $ts);
    if ($dia === date('Y-m-d')) return "Hoje, {$hora}";
    if ($dia === date('Y-m-d', strtotime('+1 day'))) return "Amanhã, {$hora}";
    if ($dia === date('Y-m-d', strtotime('-1 day'))) return "Ontem, {$hora}";
    return date('d/m', $ts) . ", {$hora}";
}

/**
 * Regra de atraso (documentada em docs/README.md): um lead aberto está
 * ATRASADO quando
 *   (a) tem retorno (data_retorno) ou compromisso agendado vencido, ou
 *   (b) está em "Tentando contato"/"Primeiro contato" sem nenhuma
 *       atividade há mais de 48 horas.
 */
function lead_esta_atrasado(array $lead, ?string $proximoCompromisso = null): bool
{
    if (in_array($lead['etapa'] ?? '', ['sem_interesse', 'contrato_assinado', 'perdido'], true)) {
        return false;
    }
    if (!empty($lead['data_retorno']) && strtotime($lead['data_retorno']) < strtotime(date('Y-m-d'))) {
        return true;
    }
    if (!empty($lead['compromisso_vencido'])) {
        return true;
    }
    if (in_array($lead['etapa'] ?? '', ['novo_lead', 'primeiro_contato'], true)) {
        $ultima = $lead['ultima_atividade_em'] ?? $lead['criado_em'] ?? null;
        return $ultima && (time() - strtotime($ultima)) > 48 * 3600;
    }
    return false;
}

/** Rótulos de origem e prioridade. */
function lead_origens(): array
{
    return ['manual' => 'Manual', 'meta_ads' => 'Meta Ads', 'indicacao' => 'Indicação', 'importacao' => 'Importação', 'outro' => 'Outro'];
}

function lead_prioridades(): array
{
    return ['alta' => 'Alta', 'normal' => 'Normal', 'baixa' => 'Baixa'];
}

function lead_prioridade_pill(string $prioridade): string
{
    return ['alta' => 'pill-red', 'normal' => 'pill-muted', 'baixa' => 'pill-muted'][$prioridade] ?? 'pill-muted';
}

/** Iniciais para avatar. */
function lead_iniciais(string $nome): string
{
    $partes = preg_split('/\s+/', trim($nome));
    $ultima = count($partes) > 1 ? end($partes) : '';
    return mb_strtoupper(mb_substr($partes[0] ?? '', 0, 1) . mb_substr($ultima, 0, 1));
}

/**
 * Carrega um lead visível ao usuário (escopo da função + empresa + não
 * excluído), já com nomes de empreendimento, responsável e etapa.
 * Retorna null se não existe OU se está fora do escopo — quem chama
 * responde 404/403 sem diferenciar, para não confirmar a existência.
 */
function lead_carregar(array $usuario, int $leadId): ?array
{
    if ($leadId <= 0 || !empty($usuario['eh_superadmin'])) {
        return null;
    }
    $scope = rbac_leads_scope($usuario);
    $stmt = db()->prepare('
        SELECT leads.*, empreendimentos.nome AS empreendimento_nome, usuarios.nome AS responsavel_nome,
               funil_etapas.nome AS etapa_nome, funil_etapas.cor AS etapa_cor, equipes.nome AS equipe_nome
        FROM leads
        LEFT JOIN empreendimentos ON empreendimentos.id = leads.empreendimento_id
        LEFT JOIN usuarios ON usuarios.id = leads.responsavel_id
        LEFT JOIN funil_etapas ON funil_etapas.id = leads.etapa_id
        LEFT JOIN equipes ON equipes.id = leads.equipe_id
        WHERE leads.id = :lead_id AND ' . $scope['sql'] . ' LIMIT 1');
    $stmt->execute(['lead_id' => $leadId] + $scope['params']);
    return $stmt->fetch() ?: null;
}
