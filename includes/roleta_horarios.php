<?php
/**
 * Expediente das roletas: horário por dia da semana, pausa (ex.: almoço),
 * feriados/exceções e horário que atravessa a meia-noite.
 *
 * Fonte da verdade:
 *   1. roleta_excecoes da data (da roleta ou da empresa inteira) — "fechado"
 *      ou "horário especial";
 *   2. roleta_horarios do dia da semana (dia sem linha = fechado);
 *   3. sem nenhuma linha em roleta_horarios: o horário antigo da roleta
 *      (horario_inicio/fim + dias_semana), para não quebrar nada existente.
 *
 * As funções roleta_exp_* são puras (recebem as linhas) para serem testadas.
 */
require_once __DIR__ . '/db.php';

/**
 * Intervalos em que a roleta funciona no DIA $data (Y-m-d), já descontando
 * pausa. Um horário que atravessa a meia-noite termina no dia seguinte.
 *
 * @param array $horarios  linhas de roleta_horarios da roleta (podem ser [])
 * @param array $excecoes  linhas de roleta_excecoes que valem para a roleta
 * @return array<int, array{0: DateTimeImmutable, 1: DateTimeImmutable}>
 */
function roleta_exp_intervalos_do_dia(array $roleta, array $horarios, array $excecoes, string $data, DateTimeZone $tz): array
{
    $ini = $fim = $pIni = $pFim = null;

    // Exceção da própria roleta vence a da empresa inteira.
    $exc = null;
    foreach ($excecoes as $e) {
        if ($e['data'] === $data && ($exc === null || ($e['roleta_id'] !== null && $exc['roleta_id'] === null))) {
            $exc = $e;
        }
    }
    if ($exc) {
        if ($exc['tipo'] === 'fechado' || !$exc['inicio'] || !$exc['fim']) {
            return [];
        }
        [$ini, $fim] = [$exc['inicio'], $exc['fim']];
    } else {
        $dow = (int) (new DateTimeImmutable($data, $tz))->format('N');
        if ($horarios) {
            foreach ($horarios as $h) {
                if ((int) $h['dia_semana'] === $dow) {
                    [$ini, $fim, $pIni, $pFim] = [$h['inicio'], $h['fim'], $h['pausa_inicio'] ?? null, $h['pausa_fim'] ?? null];
                }
            }
            if ($ini === null) {
                return [];
            }
        } else {
            $dias = array_map('intval', array_filter(explode(',', (string) ($roleta['dias_semana'] ?? '1,2,3,4,5,6,7')), 'strlen'));
            if (!in_array($dow, $dias, true)) {
                return [];
            }
            if (empty($roleta['horario_inicio']) || empty($roleta['horario_fim'])) {
                $d0 = new DateTimeImmutable($data . ' 00:00:00', $tz);
                return [[$d0, $d0->modify('+1 day')]];
            }
            [$ini, $fim] = [$roleta['horario_inicio'], $roleta['horario_fim']];
        }
    }

    $a = new DateTimeImmutable("$data $ini", $tz);
    $b = new DateTimeImmutable("$data $fim", $tz);
    if ($b <= $a) {
        $b = $b->modify('+1 day');   // atravessa a meia-noite
    }
    if ($pIni && $pFim) {
        $pa = new DateTimeImmutable("$data $pIni", $tz);
        $pb = new DateTimeImmutable("$data $pFim", $tz);
        if ($pa < $a) {
            $pa = $pa->modify('+1 day');
            $pb = $pb->modify('+1 day');
        }
        if ($pb <= $pa) {
            $pb = $pb->modify('+1 day');
        }
        if ($pa > $a && $pb < $b) {
            return [[$a, $pa], [$pb, $b]];
        }
    }
    return [[$a, $b]];
}

/**
 * A roleta está funcionando em $agora? E quando abre de novo?
 * Olha o dia anterior também (expediente que passou da meia-noite).
 *
 * @return array{aberta: bool, fecha_em: ?DateTimeImmutable, proxima_abertura: ?DateTimeImmutable, motivo: string}
 */
function roleta_exp_situacao(array $roleta, array $horarios, array $excecoes, DateTimeImmutable $agora): array
{
    $tz = $agora->getTimezone();
    $hoje = $agora->format('Y-m-d');
    $ontem = $agora->modify('-1 day')->format('Y-m-d');
    foreach ([$ontem, $hoje] as $d) {
        foreach (roleta_exp_intervalos_do_dia($roleta, $horarios, $excecoes, $d, $tz) as [$a, $b]) {
            if ($agora >= $a && $agora < $b) {
                return ['aberta' => true, 'fecha_em' => $b, 'proxima_abertura' => null, 'motivo' => ''];
            }
        }
    }
    $proxima = null;
    for ($i = 0; $i <= 14 && $proxima === null; $i++) {
        $d = $agora->modify("+$i day")->format('Y-m-d');
        foreach (roleta_exp_intervalos_do_dia($roleta, $horarios, $excecoes, $d, $tz) as [$a]) {
            if ($a > $agora) {
                $proxima = $a;
                break;
            }
        }
    }
    $excHoje = null;
    foreach ($excecoes as $e) {
        if ($e['data'] === $hoje) {
            $excHoje = $e;
        }
    }
    if ($excHoje && $excHoje['tipo'] === 'fechado') {
        $motivo = 'Plantão fechado hoje' . ($excHoje['descricao'] ? ' (' . $excHoje['descricao'] . ')' : '') . '.';
    } else {
        $motivo = 'Fora do horário do plantão.';
    }
    if ($proxima) {
        $motivo .= ' Abre ' . roleta_exp_quando($proxima, $agora) . '.';
    }
    return ['aberta' => false, 'fecha_em' => null, 'proxima_abertura' => $proxima, 'motivo' => $motivo];
}

/** "hoje às 09:30", "amanhã às 09:30", "seg 17/11 às 09:30". */
function roleta_exp_quando(DateTimeImmutable $quando, DateTimeImmutable $agora): string
{
    $h = $quando->format('H:i');
    if ($quando->format('Y-m-d') === $agora->format('Y-m-d')) {
        return "hoje às $h";
    }
    if ($quando->format('Y-m-d') === $agora->modify('+1 day')->format('Y-m-d')) {
        return "amanhã às $h";
    }
    $dias = [1 => 'seg', 2 => 'ter', 3 => 'qua', 4 => 'qui', 5 => 'sex', 6 => 'sáb', 7 => 'dom'];
    return $dias[(int) $quando->format('N')] . ' ' . $quando->format('d/m') . " às $h";
}

// ------------------------------------------------------------------ banco

function roleta_horarios_linhas(int $roletaId): array
{
    $stmt = db()->prepare('SELECT dia_semana, inicio, fim, pausa_inicio, pausa_fim FROM roleta_horarios WHERE roleta_id = :r ORDER BY dia_semana');
    $stmt->execute(['r' => $roletaId]);
    return $stmt->fetchAll();
}

/** Exceções entre ontem e daqui a 15 dias (da roleta ou da empresa toda). */
function roleta_excecoes_linhas(int $empresaId, int $roletaId, DateTimeImmutable $agora): array
{
    $stmt = db()->prepare('SELECT roleta_id, data, tipo, inicio, fim, descricao FROM roleta_excecoes
                           WHERE empresa_id = :e AND (roleta_id = :r OR roleta_id IS NULL) AND data BETWEEN :d1 AND :d2');
    $stmt->execute(['e' => $empresaId, 'r' => $roletaId, 'd1' => $agora->modify('-1 day')->format('Y-m-d'), 'd2' => $agora->modify('+15 day')->format('Y-m-d')]);
    $linhas = $stmt->fetchAll();
    foreach ($linhas as &$l) {
        $l['roleta_id'] = $l['roleta_id'] !== null ? (int) $l['roleta_id'] : null;
    }
    return $linhas;
}

function roleta_situacao(array $roleta, ?DateTimeImmutable $agora = null): array
{
    $agora = $agora ?? new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
    return roleta_exp_situacao($roleta, roleta_horarios_linhas((int) $roleta['id']), roleta_excecoes_linhas((int) $roleta['empresa_id'], (int) $roleta['id'], $agora), $agora);
}

/**
 * A tela atual de roletas edita só "início/fim + dias". Enquanto a grade por
 * dia não existir na interface, essa edição reescreve as linhas — mas só se
 * elas ainda forem "simples" (mesmo horário todo dia, sem pausa). Uma grade
 * personalizada nunca é sobrescrita por essa tela.
 */
function roleta_horarios_sincronizar_simples(int $roletaId, ?string $inicio, ?string $fim, string $dias): bool
{
    $atuais = roleta_horarios_linhas($roletaId);
    $simples = true;
    foreach ($atuais as $h) {
        if ($h['pausa_inicio'] || $h['pausa_fim'] || $h['inicio'] !== $atuais[0]['inicio'] || $h['fim'] !== $atuais[0]['fim']) {
            $simples = false;
        }
    }
    if (!$simples) {
        return false;
    }
    $pdo = db();
    $pdo->prepare('DELETE FROM roleta_horarios WHERE roleta_id = :r')->execute(['r' => $roletaId]);
    if ($inicio && $fim) {
        $ins = $pdo->prepare('INSERT INTO roleta_horarios (roleta_id, dia_semana, inicio, fim) VALUES (:r, :d, :i, :f)');
        foreach (array_filter(array_map('intval', explode(',', $dias)), fn ($d) => $d >= 1 && $d <= 7) as $d) {
            $ins->execute(['r' => $roletaId, 'd' => $d, 'i' => $inicio, 'f' => $fim]);
        }
    }
    return true;
}

/** A grade da roleta é personalizada (horários diferentes por dia ou com pausa)? */
function roleta_horarios_personalizados(int $roletaId): bool
{
    $atuais = roleta_horarios_linhas($roletaId);
    foreach ($atuais as $h) {
        if ($h['pausa_inicio'] || $h['inicio'] !== $atuais[0]['inicio'] || $h['fim'] !== $atuais[0]['fim']) {
            return true;
        }
    }
    return false;
}
