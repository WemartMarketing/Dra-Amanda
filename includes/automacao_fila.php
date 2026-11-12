<?php
/**
 * Fila persistente de tarefas da automação (tabela automacao_fila).
 *
 * Feita para hospedagem compartilhada: não há processo rodando o tempo
 * todo. O cron (cron/rotinas.php, a cada 15 min) e, quando fizer sentido,
 * o próprio webhook depois de responder à Meta, chamam fila_processar().
 *
 * Garantias:
 *   - chave_unica: a mesma tarefa (ex.: "passo X da execução Y") nunca
 *     entra duas vezes — fila_agendar() devolve null para a repetida;
 *   - reivindicação atômica: UPDATE … WHERE status = 'pendente' por
 *     linha; só quem mudou a linha (rowCount = 1) executa, então duas
 *     execuções simultâneas nunca processam a mesma tarefa;
 *   - tarefa "travada" (o PHP morreu no meio) volta para a fila depois de
 *     FILA_TRAVA_MINUTOS, contando a tentativa;
 *   - falha temporária → nova tentativa com espera crescente; ao atingir
 *     max_tentativas (ou falha definitiva) → status 'falhou', visível
 *     nos relatórios. Nunca há retentativa infinita.
 */
require_once __DIR__ . '/db.php';

const FILA_TRAVA_MINUTOS = 15;

/** Espera (minutos) antes da tentativa N+1. */
function fila_espera_minutos(int $tentativas): int
{
    return [1 => 1, 2 => 5, 3 => 15, 4 => 60][$tentativas] ?? 240;
}

/**
 * Agenda uma tarefa. Devolve o id, ou null se a chave_unica já existir
 * (tarefa repetida — é o comportamento esperado, não um erro).
 */
function fila_agendar(int $empresaId, string $tipo, array $payload = [], ?string $chaveUnica = null, ?DateTimeInterface $quando = null,
                      ?string $refTipo = null, ?int $refId = null, int $maxTentativas = 5): ?int
{
    $stmt = db()->prepare('INSERT IGNORE INTO automacao_fila (empresa_id, tipo, referencia_tipo, referencia_id, chave_unica, payload, executar_em, max_tentativas)
                           VALUES (:e, :t, :rt, :ri, :c, :p, :q, :m)');
    $stmt->execute([
        'e' => $empresaId,
        't' => $tipo,
        'rt' => $refTipo,
        'ri' => $refId,
        'c' => $chaveUnica,
        'p' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
        'q' => ($quando ?? new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
        'm' => max(1, $maxTentativas),
    ]);
    return $stmt->rowCount() === 1 ? (int) db()->lastInsertId() : null;
}

/** Cancela tarefas ainda não executadas de uma referência (ex.: execução de jornada encerrada). */
function fila_cancelar_referencia(string $refTipo, int $refId, string $motivo = 'cancelada'): int
{
    $stmt = db()->prepare("UPDATE automacao_fila SET status = 'cancelada', resultado = :m, concluida_em = NOW()
                           WHERE referencia_tipo = :rt AND referencia_id = :ri AND status = 'pendente'");
    $stmt->execute(['m' => mb_substr($motivo, 0, 500), 'rt' => $refTipo, 'ri' => $refId]);
    return $stmt->rowCount();
}

/** Devolve à fila tarefas presas em 'processando' (o PHP caiu no meio). */
function fila_recuperar_travadas(int $minutos = FILA_TRAVA_MINUTOS): int
{
    $pdo = db();
    $falhou = $pdo->prepare("UPDATE automacao_fila SET status = 'falhou', resultado = 'Interrompida várias vezes; limite de tentativas atingido.', concluida_em = NOW(), travada_por = NULL
                             WHERE status = 'processando' AND travada_em < DATE_SUB(NOW(), INTERVAL :m MINUTE) AND tentativas >= max_tentativas");
    $falhou->execute(['m' => $minutos]);
    $volta = $pdo->prepare("UPDATE automacao_fila SET status = 'pendente', travada_por = NULL, travada_em = NULL, executar_em = NOW(),
                                   resultado = 'Retomada após interrupção.'
                            WHERE status = 'processando' AND travada_em < DATE_SUB(NOW(), INTERVAL :m MINUTE)");
    $volta->execute(['m' => $minutos]);
    return $falhou->rowCount() + $volta->rowCount();
}

/**
 * Toma UMA tarefa para quem tem o $token. Atômico: se duas execuções leram
 * a mesma tarefa como pendente, só a primeira muda a linha (rowCount = 1);
 * a outra recebe false e pula.
 */
function fila_tomar(int $id, string $token): bool
{
    $stmt = db()->prepare("UPDATE automacao_fila SET status = 'processando', travada_em = NOW(), travada_por = :tk, tentativas = tentativas + 1
                           WHERE id = :id AND status = 'pendente'");
    $stmt->execute(['tk' => $token, 'id' => $id]);
    return $stmt->rowCount() === 1;
}

/**
 * Reivindica até $limite tarefas vencidas. Cada linha é tomada com um
 * UPDATE condicional próprio: se outra execução pegou antes, rowCount = 0
 * e ela é simplesmente pulada.
 *
 * @param string[]|null $tipos só estes tipos (null = todos)
 */
function fila_reivindicar(int $limite = 50, ?array $tipos = null): array
{
    $pdo = db();
    $token = bin2hex(random_bytes(8));
    $where = "status = 'pendente' AND executar_em <= NOW()";
    $params = [];
    if ($tipos) {
        $ph = [];
        foreach (array_values($tipos) as $i => $t) {
            $ph[] = ':t' . $i;
            $params['t' . $i] = $t;
        }
        $where .= ' AND tipo IN (' . implode(',', $ph) . ')';
    }
    $stmt = $pdo->prepare("SELECT id FROM automacao_fila WHERE $where ORDER BY executar_em, id LIMIT " . max(1, (int) $limite));
    $stmt->execute($params);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $pegas = [];
    foreach ($ids as $id) {
        if (fila_tomar((int) $id, $token)) {
            $pegas[] = (int) $id;
        }
    }
    if (!$pegas) {
        return [];
    }
    $linhas = $pdo->query('SELECT * FROM automacao_fila WHERE id IN (' . implode(',', $pegas) . ') ORDER BY executar_em, id')->fetchAll();
    foreach ($linhas as &$l) {
        $l['payload'] = $l['payload'] !== null ? (json_decode($l['payload'], true) ?: []) : [];
    }
    return $linhas;
}

function fila_concluir(array $tarefa, string $resultado = 'ok'): void
{
    db()->prepare("UPDATE automacao_fila SET status = 'concluida', resultado = :r, concluida_em = NOW(), travada_por = NULL
                   WHERE id = :id AND travada_por = :tk")
        ->execute(['r' => mb_substr($resultado, 0, 500), 'id' => $tarefa['id'], 'tk' => $tarefa['travada_por']]);
}

/**
 * Registra a falha. $definitiva = true (ex.: template rejeitado, contato
 * descadastrado) encerra sem nova tentativa.
 */
function fila_falhar(array $tarefa, string $erro, bool $definitiva = false): string
{
    $tentativas = (int) $tarefa['tentativas'];
    if ($definitiva || $tentativas >= (int) $tarefa['max_tentativas']) {
        db()->prepare("UPDATE automacao_fila SET status = 'falhou', resultado = :r, concluida_em = NOW(), travada_por = NULL
                       WHERE id = :id AND travada_por = :tk")
            ->execute(['r' => mb_substr($erro, 0, 500), 'id' => $tarefa['id'], 'tk' => $tarefa['travada_por']]);
        return 'falhou';
    }
    db()->prepare("UPDATE automacao_fila SET status = 'pendente', resultado = :r, travada_por = NULL, travada_em = NULL,
                          executar_em = DATE_ADD(NOW(), INTERVAL :min MINUTE)
                   WHERE id = :id AND travada_por = :tk")
        ->execute(['r' => mb_substr($erro, 0, 500), 'min' => fila_espera_minutos($tentativas), 'id' => $tarefa['id'], 'tk' => $tarefa['travada_por']]);
    return 'reagendada';
}

/** Adia sem contar como falha (ex.: fora do horário de envio). */
function fila_adiar(array $tarefa, DateTimeInterface $quando, string $motivo): void
{
    db()->prepare("UPDATE automacao_fila SET status = 'pendente', resultado = :r, travada_por = NULL, travada_em = NULL,
                          executar_em = :q, tentativas = GREATEST(tentativas - 1, 0)
                   WHERE id = :id AND travada_por = :tk")
        ->execute(['r' => mb_substr($motivo, 0, 500), 'q' => $quando->format('Y-m-d H:i:s'), 'id' => $tarefa['id'], 'tk' => $tarefa['travada_por']]);
}

/**
 * Executa as tarefas vencidas. $tratadores = ['tipo' => callable(array $tarefa): array].
 * O tratador devolve ['status' => 'ok'|'falha'|'falha_definitiva'|'adiar', 'mensagem' => …, 'quando' => DateTimeInterface].
 * Exceção no tratador conta como falha temporária. Tipo sem tratador → falha definitiva.
 */
function fila_processar(array $tratadores, int $limite = 50): array
{
    $r = ['executadas' => 0, 'ok' => 0, 'reagendadas' => 0, 'falhas' => 0, 'adiadas' => 0];
    if (!$tratadores) {
        return $r; // nenhum tipo registrado: não reivindica nada
    }
    foreach (fila_reivindicar($limite, array_keys($tratadores)) as $tarefa) {
        $r['executadas']++;
        $r[fila_executar_tarefa($tarefa, $tratadores)]++;
    }
    return $r;
}

/**
 * Executa uma tarefa JÁ reivindicada. Devolve a chave do contador:
 * ok | reagendadas | falhas | adiadas.
 */
function fila_executar_tarefa(array $tarefa, array $tratadores): string
{
    $fn = $tratadores[$tarefa['tipo']] ?? null;
    if (!$fn) {
        fila_falhar($tarefa, 'Tipo de tarefa desconhecido: ' . $tarefa['tipo'], true);
        return 'falhas';
    }
    try {
        $res = $fn($tarefa);
    } catch (Throwable $e) {
        error_log('[automacao_fila] tarefa ' . $tarefa['id'] . ': ' . $e->getMessage());
        $res = ['status' => 'falha', 'mensagem' => 'Erro interno ao executar a tarefa.'];
    }
    $status = $res['status'] ?? 'falha';
    $msg = (string) ($res['mensagem'] ?? '');
    if ($status === 'ok') {
        fila_concluir($tarefa, $msg !== '' ? $msg : 'ok');
        return 'ok';
    }
    if ($status === 'adiar' && ($res['quando'] ?? null) instanceof DateTimeInterface) {
        fila_adiar($tarefa, $res['quando'], $msg);
        return 'adiadas';
    }
    return fila_falhar($tarefa, $msg !== '' ? $msg : 'Falha sem detalhe.', $status === 'falha_definitiva') === 'falhou' ? 'falhas' : 'reagendadas';
}

/**
 * Executa AGORA uma tarefa específica pela chave_unica (ex.: envio de teste
 * pela tela), sem esperar o cron e sem mexer nas outras. Respeita a mesma
 * reivindicação atômica: se o cron já pegou, não executa de novo.
 */
function fila_executar_chave(string $chaveUnica, array $tratadores): ?string
{
    $stmt = db()->prepare("SELECT id FROM automacao_fila WHERE chave_unica = :c AND status = 'pendente' AND executar_em <= NOW()");
    $stmt->execute(['c' => $chaveUnica]);
    $id = (int) $stmt->fetchColumn();
    $token = bin2hex(random_bytes(8));
    if (!$id || !fila_tomar($id, $token)) {
        return null;
    }
    $tarefa = db()->query('SELECT * FROM automacao_fila WHERE id = ' . $id)->fetch();
    $tarefa['payload'] = $tarefa['payload'] !== null ? (json_decode($tarefa['payload'], true) ?: []) : [];
    return fila_executar_tarefa($tarefa, $tratadores);
}

/** Resumo para o painel/Superadmin. */
function fila_resumo(?int $empresaId = null): array
{
    $sql = 'SELECT status, COUNT(*) AS n FROM automacao_fila' . ($empresaId ? ' WHERE empresa_id = :e' : '') . ' GROUP BY status';
    $stmt = db()->prepare($sql);
    $stmt->execute($empresaId ? ['e' => $empresaId] : []);
    $out = ['pendente' => 0, 'processando' => 0, 'concluida' => 0, 'falhou' => 0, 'cancelada' => 0];
    foreach ($stmt->fetchAll() as $l) {
        $out[$l['status']] = (int) $l['n'];
    }
    return $out;
}
