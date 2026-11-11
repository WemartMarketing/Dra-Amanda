<?php
/**
 * Agenda comercial (seção 14 do prompt mestre) — visitas, retornos e
 * tarefas, com ou sem lead. Visibilidade sempre por rbac_agenda_scope().
 */
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/leads.php';

function agenda_tipos(): array
{
    return ['visita' => 'Visita', 'retorno' => 'Retorno', 'tarefa' => 'Tarefa'];
}

/** "agendada"/"agendado" conforme o gênero do tipo (Visita, Tarefa / Retorno). */
function agenda_flexao(string $tipo, string $raiz): string
{
    return $raiz . ($tipo === 'retorno' ? 'o' : 'a');
}

function agenda_status(): array
{
    return [
        'agendado'       => 'Agendado',
        'realizado'      => 'Realizado',
        'cliente_ausente'=> 'Cliente ausente',
        'reagendado'     => 'Reagendado',
        'cancelado'      => 'Cancelado',
    ];
}

function agenda_status_pill(string $status): string
{
    return [
        'agendado' => 'pill-amber', 'realizado' => 'pill-green', 'cliente_ausente' => 'pill-red',
        'reagendado' => 'pill-muted', 'cancelado' => 'pill-muted',
    ][$status] ?? 'pill-muted';
}

function agenda_tipo_icon(string $tipo): string
{
    return ['visita' => 'building', 'retorno' => 'phone', 'tarefa' => 'check'][$tipo] ?? 'calendar';
}

/** Compromisso visível ao usuário (escopo da agenda), com nomes do lead e responsável. */
function agenda_carregar(array $usuario, int $id): ?array
{
    $scope = rbac_agenda_scope($usuario);
    $stmt = db()->prepare('
        SELECT ag.*, leads.nome AS lead_nome, leads.excluido_em AS lead_excluido_em, usuarios.nome AS usuario_nome
        FROM agenda_compromissos ag
        LEFT JOIN leads ON leads.id = ag.lead_id
        LEFT JOIN usuarios ON usuarios.id = ag.usuario_id
        WHERE ag.id = :id AND ' . $scope['sql']);
    $stmt->execute(['id' => $id] + $scope['params']);
    $row = $stmt->fetch();
    return $row && !$row['lead_excluido_em'] ? $row : null;
}

/** Cria um compromisso. Quem chama já validou permissões, lead e responsável. */
function agenda_criar(int $empresaId, ?int $leadId, int $responsavelId, string $tipo, string $titulo, string $inicio, ?string $observacao, int $criadoPor, ?int $reagendadoDe = null): int
{
    db()->prepare('
        INSERT INTO agenda_compromissos (empresa_id, lead_id, usuario_id, tipo, titulo, inicio, observacao, reagendado_de, criado_por)
        VALUES (:empresa_id, :lead_id, :usuario_id, :tipo, :titulo, :inicio, :observacao, :reagendado_de, :criado_por)
    ')->execute([
        'empresa_id' => $empresaId, 'lead_id' => $leadId, 'usuario_id' => $responsavelId, 'tipo' => $tipo,
        'titulo' => mb_substr($titulo, 0, 190), 'inicio' => $inicio, 'observacao' => $observacao,
        'reagendado_de' => $reagendadoDe, 'criado_por' => $criadoPor,
    ]);
    return (int) db()->lastInsertId();
}

/**
 * Lista compromissos visíveis. $filtros: de, ate (datas Y-m-d H:i:s),
 * status (array), lead_id, usuario_id, limite.
 */
function agenda_listar(array $usuario, array $filtros = []): array
{
    $scope = rbac_agenda_scope($usuario);
    $sql = 'SELECT ag.*, leads.nome AS lead_nome, leads.telefone AS lead_telefone, usuarios.nome AS usuario_nome
            FROM agenda_compromissos ag
            LEFT JOIN leads ON leads.id = ag.lead_id
            LEFT JOIN usuarios ON usuarios.id = ag.usuario_id
            WHERE ' . $scope['sql'] . ' AND (ag.lead_id IS NULL OR leads.excluido_em IS NULL)';
    $params = $scope['params'];
    if (!empty($filtros['de'])) { $sql .= ' AND ag.inicio >= :f_de'; $params['f_de'] = $filtros['de']; }
    if (!empty($filtros['ate'])) { $sql .= ' AND ag.inicio < :f_ate'; $params['f_ate'] = $filtros['ate']; }
    if (!empty($filtros['lead_id'])) { $sql .= ' AND ag.lead_id = :f_lead'; $params['f_lead'] = (int) $filtros['lead_id']; }
    if (!empty($filtros['usuario_id'])) { $sql .= ' AND ag.usuario_id = :f_usuario'; $params['f_usuario'] = (int) $filtros['usuario_id']; }
    if (!empty($filtros['status'])) {
        $ph = [];
        foreach (array_values((array) $filtros['status']) as $i => $st) { $ph[] = ":f_st{$i}"; $params["f_st{$i}"] = $st; }
        $sql .= ' AND ag.status IN (' . implode(',', $ph) . ')';
    }
    $sql .= ' ORDER BY ag.inicio ' . (!empty($filtros['desc']) ? 'DESC' : 'ASC');
    if (!empty($filtros['limite'])) { $sql .= ' LIMIT ' . (int) $filtros['limite']; }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** "segunda-feira, 11 de novembro" — sem depender de locale do servidor. */
function strftime_pt(int $ts): string
{
    $dias = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
    $meses = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    return $dias[(int) date('w', $ts)] . ', ' . (int) date('j', $ts) . ' de ' . $meses[(int) date('n', $ts) - 1];
}
