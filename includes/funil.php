<?php
/**
 * Funil configurável (Etapa 3) — ver sql/migration_004_crm.sql.
 *
 * Cada empresa tem suas etapas (funil_etapas); cada etapa pertence a uma
 * `categoria` fixa. leads.etapa_id guarda a etapa escolhida e leads.etapa
 * guarda SEMPRE a categoria dessa etapa, para que relatórios, Início e
 * rotinas automáticas continuem lendo um vocabulário estável mesmo que o
 * Master renomeie, reordene ou crie etapas.
 *
 * lead_mudar_etapa() é o ÚNICO caminho que altera a etapa de um lead.
 */
require_once __DIR__ . '/leads.php';
require_once __DIR__ . '/auditoria.php';

/** Categorias fixas, na ordem natural do funil, com o rótulo de referência. */
function funil_categorias(): array
{
    return [
        'novo_lead'         => 'Tentando contato',
        'primeiro_contato'  => 'Primeiro contato',
        'em_atendimento'    => 'Em atendimento',
        'visita_agendada'   => 'Visita agendada',
        'visita_realizada'  => 'Visita realizada',
        'sem_interesse'     => 'Sem interesse',
        'contrato_assinado' => 'Contrato assinado',
        'perdido'           => 'Perdido por inatividade',
    ];
}

/** Categorias em que o lead está "fechado" (não precisa mais de atendimento). */
function funil_categorias_encerradas(): array
{
    return ['sem_interesse', 'contrato_assinado', 'perdido'];
}

/** As 8 etapas padrão (seção 7). Única fonte — migration 004 usa a mesma lista. */
function funil_definicao_padrao(): array
{
    return [
        ['nome' => 'Tentando contato',        'categoria' => 'novo_lead',         'ordem' => 10, 'cor' => '#64748B', 'exibir_kanban' => 1],
        ['nome' => 'Primeiro contato',        'categoria' => 'primeiro_contato',  'ordem' => 20, 'cor' => '#3B82F6', 'exibir_kanban' => 1],
        ['nome' => 'Em atendimento',          'categoria' => 'em_atendimento',    'ordem' => 30, 'cor' => '#635BFA', 'exibir_kanban' => 1],
        ['nome' => 'Visita agendada',         'categoria' => 'visita_agendada',   'ordem' => 40, 'cor' => '#F59E0B', 'exibir_kanban' => 1],
        ['nome' => 'Visita realizada',        'categoria' => 'visita_realizada',  'ordem' => 50, 'cor' => '#10B981', 'exibir_kanban' => 1],
        ['nome' => 'Sem interesse',           'categoria' => 'sem_interesse',     'ordem' => 60, 'cor' => '#9CA3AF', 'exibir_kanban' => 1],
        ['nome' => 'Contrato assinado',       'categoria' => 'contrato_assinado', 'ordem' => 70, 'cor' => '#189D72', 'exibir_kanban' => 1],
        ['nome' => 'Perdido por inatividade', 'categoria' => 'perdido',           'ordem' => 80, 'cor' => '#DC6070', 'exibir_kanban' => 0],
    ];
}

/** Cria as etapas padrão para uma empresa (idempotente pelo nome). */
function funil_seed_padrao(int $empresaId): void
{
    $stmt = db()->prepare('
        INSERT IGNORE INTO funil_etapas (empresa_id, nome, categoria, ordem, cor, exibir_kanban, padrao_sistema)
        VALUES (:empresa_id, :nome, :categoria, :ordem, :cor, :exibir_kanban, 1)
    ');
    foreach (funil_definicao_padrao() as $etapa) {
        $stmt->execute(['empresa_id' => $empresaId] + $etapa);
    }
}

/**
 * Etapas da empresa em ordem. $filtro: 'todas' | 'ativas' | 'kanban'
 * (ativas e visíveis no Kanban).
 */
function funil_etapas(int $empresaId, string $filtro = 'ativas'): array
{
    $sql = 'SELECT * FROM funil_etapas WHERE empresa_id = :empresa_id';
    if ($filtro === 'ativas' || $filtro === 'kanban') {
        $sql .= ' AND ativa = 1';
    }
    if ($filtro === 'kanban') {
        $sql .= ' AND exibir_kanban = 1';
    }
    $stmt = db()->prepare($sql . ' ORDER BY ordem, id');
    $stmt->execute(['empresa_id' => $empresaId]);
    return $stmt->fetchAll();
}

/** Uma etapa, somente se for da empresa. */
function funil_etapa(int $empresaId, int $etapaId): ?array
{
    $stmt = db()->prepare('SELECT * FROM funil_etapas WHERE id = :id AND empresa_id = :empresa_id');
    $stmt->execute(['id' => $etapaId, 'empresa_id' => $empresaId]);
    return $stmt->fetch() ?: null;
}

/** Primeira etapa ativa (em ordem) de uma categoria — usada por automações. */
function funil_etapa_por_categoria(int $empresaId, string $categoria): ?array
{
    $stmt = db()->prepare('SELECT * FROM funil_etapas WHERE empresa_id = :empresa_id AND categoria = :categoria AND ativa = 1 ORDER BY ordem, id LIMIT 1');
    $stmt->execute(['empresa_id' => $empresaId, 'categoria' => $categoria]);
    return $stmt->fetch() ?: null;
}

/** Etapa de entrada de um lead novo: a primeira etapa ativa do funil. */
function funil_primeira_etapa(int $empresaId): ?array
{
    $etapas = funil_etapas($empresaId, 'kanban') ?: funil_etapas($empresaId, 'ativas');
    return $etapas[0] ?? null;
}

/** [etapa_id => etapa] para lookup rápido em listas. */
function funil_mapa(int $empresaId): array
{
    $mapa = [];
    foreach (funil_etapas($empresaId, 'todas') as $etapa) {
        $mapa[(int) $etapa['id']] = $etapa;
    }
    return $mapa;
}

/**
 * Muda a etapa de um lead. Valida que a etapa é da mesma empresa do lead
 * e está ativa, grava etapa_id + etapa (= categoria), marca atividade,
 * registra histórico e auditoria. Retorna false se a etapa é inválida;
 * true se mudou ou se já estava nela.
 *
 * $lead precisa ter id, empresa_id, etapa_id.
 */
function lead_mudar_etapa(array $lead, int $etapaId, array $usuario, string $complemento = ''): bool
{
    $empresaId = (int) $lead['empresa_id'];
    $nova = funil_etapa($empresaId, $etapaId);
    if (!$nova || !(int) $nova['ativa']) {
        return false;
    }
    if ((int) $lead['etapa_id'] === (int) $nova['id']) {
        return true;
    }
    $anterior = $lead['etapa_id'] ? funil_etapa($empresaId, (int) $lead['etapa_id']) : null;

    db()->prepare('
        UPDATE leads SET etapa_id = :etapa_id, etapa = :categoria, ultima_atividade_em = NOW(),
            contrato_assinado_em = CASE WHEN :cat2 = \'contrato_assinado\' THEN COALESCE(contrato_assinado_em, NOW()) ELSE NULL END
        WHERE id = :id AND empresa_id = :empresa_id
    ')->execute([
        'etapa_id' => $nova['id'],
        'categoria' => $nova['categoria'],
        'cat2' => $nova['categoria'],
        'id' => $lead['id'],
        'empresa_id' => $empresaId,
    ]);

    lead_registrar_feedback((int) $lead['id']);

    $usuarioId = isset($usuario['id']) ? (int) $usuario['id'] : null;
    $descricao = 'Etapa alterada de "' . ($anterior['nome'] ?? lead_etapa_rotulo((string) ($lead['etapa'] ?? ''))) . '" para "' . $nova['nome'] . '"';
    if ($complemento !== '') {
        $descricao .= ' — ' . $complemento;
    }
    lead_registrar_evento((int) $lead['id'], $usuarioId, 'mudanca_etapa', $descricao);
    auditoria_registrar($empresaId, $usuarioId, 'lead.etapa_alterada', 'lead', (int) $lead['id'], [
        'de_etapa_id' => $anterior['id'] ?? null, 'para_etapa_id' => (int) $nova['id'],
        'de' => $anterior['categoria'] ?? ($lead['etapa'] ?? null), 'para' => $nova['categoria'],
    ]);
    return true;
}
