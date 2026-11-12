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

// ------------------------------------------------------------ edição (Fase 6)

/** "09:30" / "09:30:00" → "09:30:00"; vazio → null; inválido → false. */
function roleta_hora_normalizar($valor)
{
    $valor = trim((string) $valor);
    if ($valor === '') {
        return null;
    }
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $valor)) {
        return false;
    }
    return substr($valor, 0, 5) . ':00';
}

/**
 * Valida a grade semanal vinda da tela. $dias[1..7] = [aberto, inicio, fim,
 * pausa_inicio, pausa_fim]. Devolve ['ok' => true, 'linhas' => [...]] ou
 * ['ok' => false, 'erro' => texto para a pessoa].
 */
function roleta_grade_validar(array $dias): array
{
    $rot = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];
    $linhas = [];
    foreach ($rot as $n => $nome) {
        $d = $dias[$n] ?? [];
        if (empty($d['aberto'])) {
            continue;
        }
        $ini = roleta_hora_normalizar($d['inicio'] ?? '');
        $fim = roleta_hora_normalizar($d['fim'] ?? '');
        $pIni = roleta_hora_normalizar($d['pausa_inicio'] ?? '');
        $pFim = roleta_hora_normalizar($d['pausa_fim'] ?? '');
        if (!$ini || !$fim) {
            return ['ok' => false, 'erro' => "$nome: informe o horário de abertura e de fechamento (HH:MM)."];
        }
        if ($ini === $fim) {
            return ['ok' => false, 'erro' => "$nome: abertura e fechamento não podem ser iguais."];
        }
        if ($pIni === false || $pFim === false || (($pIni === null) !== ($pFim === null))) {
            return ['ok' => false, 'erro' => "$nome: informe o início e o fim da pausa (ou deixe os dois vazios)."];
        }
        if ($pIni !== null) {
            // A pausa precisa caber dentro do expediente (aceita expediente que passa da
            // meia-noite): tudo em minutos contados a partir da abertura.
            $min = fn (string $h) => (int) substr($h, 0, 2) * 60 + (int) substr($h, 3, 2);
            $rel = fn (string $h) => ($min($h) - $min($ini) + 1440) % 1440;
            [$a, $b, $pa, $pb] = [0, $rel($fim), $rel($pIni), $rel($pFim)];
            if ($pb <= $pa) {
                return ['ok' => false, 'erro' => "$nome: o fim da pausa precisa ser depois do início."];
            }
            if ($pa <= $a || $pb >= $b) {
                return ['ok' => false, 'erro' => "$nome: a pausa precisa ficar dentro do horário de funcionamento."];
            }
        }
        $linhas[] = ['dia_semana' => $n, 'inicio' => $ini, 'fim' => $fim, 'pausa_inicio' => $pIni, 'pausa_fim' => $pFim];
    }
    if (!$linhas) {
        return ['ok' => false, 'erro' => 'Deixe pelo menos um dia aberto. Para parar a roleta, use "Roleta ativa" nas regras.'];
    }
    return ['ok' => true, 'linhas' => $linhas];
}

/**
 * Grava a grade (substitui a anterior) e mantém os campos antigos da roleta
 * (dias_semana, horario_inicio/fim) coerentes para as telas que os usam.
 */
function roleta_grade_salvar(int $empresaId, int $roletaId, array $linhas): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT id FROM roletas WHERE id = :r AND empresa_id = :e FOR UPDATE')->execute(['r' => $roletaId, 'e' => $empresaId]);
        $pdo->prepare('DELETE FROM roleta_horarios WHERE roleta_id = :r')->execute(['r' => $roletaId]);
        $ins = $pdo->prepare('INSERT INTO roleta_horarios (roleta_id, dia_semana, inicio, fim, pausa_inicio, pausa_fim) VALUES (:r, :d, :i, :f, :pi, :pf)');
        foreach ($linhas as $l) {
            $ins->execute(['r' => $roletaId, 'd' => $l['dia_semana'], 'i' => $l['inicio'], 'f' => $l['fim'], 'pi' => $l['pausa_inicio'], 'pf' => $l['pausa_fim']]);
        }
        $pdo->prepare('UPDATE roletas SET dias_semana = :d, horario_inicio = :i, horario_fim = :f WHERE id = :r AND empresa_id = :e')
            ->execute(['d' => implode(',', array_column($linhas, 'dia_semana')), 'i' => $linhas[0]['inicio'], 'f' => $linhas[0]['fim'], 'r' => $roletaId, 'e' => $empresaId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Grade para a tela: [1..7 => ['aberto', 'inicio', 'fim', 'pausa_inicio', 'pausa_fim']] (HH:MM). */
function roleta_grade_para_tela(array $roleta): array
{
    $linhas = roleta_horarios_linhas((int) $roleta['id']);
    $grade = [];
    $diasAntigos = array_map('intval', array_filter(explode(',', (string) ($roleta['dias_semana'] ?? '')), 'strlen'));
    for ($n = 1; $n <= 7; $n++) {
        $grade[$n] = ['aberto' => false, 'inicio' => '09:00', 'fim' => '18:00', 'pausa_inicio' => '', 'pausa_fim' => ''];
        if (!$linhas && in_array($n, $diasAntigos, true)) {
            $grade[$n] = ['aberto' => true, 'inicio' => substr((string) ($roleta['horario_inicio'] ?: '00:00'), 0, 5),
                          'fim' => substr((string) ($roleta['horario_fim'] ?: '23:59'), 0, 5), 'pausa_inicio' => '', 'pausa_fim' => ''];
        }
    }
    foreach ($linhas as $l) {
        $grade[(int) $l['dia_semana']] = ['aberto' => true, 'inicio' => substr($l['inicio'], 0, 5), 'fim' => substr($l['fim'], 0, 5),
            'pausa_inicio' => $l['pausa_inicio'] ? substr($l['pausa_inicio'], 0, 5) : '', 'pausa_fim' => $l['pausa_fim'] ? substr($l['pausa_fim'], 0, 5) : ''];
    }
    return $grade;
}

/**
 * Feriado / dia fechado / horário especial. $roletaId null = vale para todas
 * as roletas da empresa. Mesma data e mesmo alcance: a nova substitui.
 */
function roleta_excecao_salvar(int $empresaId, ?int $roletaId, string $data, string $tipo, ?string $inicio, ?string $fim, ?string $descricao): array
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $data, new DateTimeZone(ROLETA_FUSO));
    if (!$d || $d->format('Y-m-d') !== $data) {
        return ['ok' => false, 'erro' => 'Data inválida.'];
    }
    $hoje = new DateTimeImmutable('today', new DateTimeZone(ROLETA_FUSO));
    if ($d < $hoje) {
        return ['ok' => false, 'erro' => 'Escolha hoje ou uma data futura.'];
    }
    if ($d > $hoje->modify('+2 years')) {
        return ['ok' => false, 'erro' => 'Escolha uma data nos próximos 2 anos.'];
    }
    if (!in_array($tipo, ['fechado', 'horario_especial'], true)) {
        return ['ok' => false, 'erro' => 'Tipo inválido.'];
    }
    $ini = $fim2 = null;
    if ($tipo === 'horario_especial') {
        $ini = roleta_hora_normalizar($inicio);
        $fim2 = roleta_hora_normalizar($fim);
        if (!$ini || !$fim2 || $ini === $fim2) {
            return ['ok' => false, 'erro' => 'Informe o horário especial (abertura e fechamento diferentes).'];
        }
    }
    $pdo = db();
    if ($roletaId !== null) {
        $stmt = $pdo->prepare('SELECT 1 FROM roletas WHERE id = :r AND empresa_id = :e');
        $stmt->execute(['r' => $roletaId, 'e' => $empresaId]);
        if (!$stmt->fetchColumn()) {
            return ['ok' => false, 'erro' => 'Roleta não encontrada.'];
        }
    }
    $pdo->prepare('DELETE FROM roleta_excecoes WHERE empresa_id = :e AND roleta_id <=> :r AND data = :d')
        ->execute(['e' => $empresaId, 'r' => $roletaId, 'd' => $data]);
    $pdo->prepare('INSERT INTO roleta_excecoes (empresa_id, roleta_id, data, tipo, inicio, fim, descricao) VALUES (:e, :r, :d, :t, :i, :f, :ds)')
        ->execute(['e' => $empresaId, 'r' => $roletaId, 'd' => $data, 't' => $tipo, 'i' => $ini, 'f' => $fim2,
                   'ds' => $descricao !== null && trim($descricao) !== '' ? mb_substr(trim($descricao), 0, 190) : null]);
    return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
}

function roleta_excecao_remover(int $empresaId, int $id): bool
{
    $stmt = db()->prepare('DELETE FROM roleta_excecoes WHERE id = :id AND empresa_id = :e');
    $stmt->execute(['id' => $id, 'e' => $empresaId]);
    return $stmt->rowCount() === 1;
}

/** Exceções de hoje em diante que valem para a roleta (dela e da empresa toda). */
function roleta_excecoes_futuras(int $empresaId, int $roletaId): array
{
    $stmt = db()->prepare('SELECT x.*, r.nome AS roleta_nome FROM roleta_excecoes x LEFT JOIN roletas r ON r.id = x.roleta_id
                           WHERE x.empresa_id = :e AND (x.roleta_id = :r OR x.roleta_id IS NULL) AND x.data >= :hoje ORDER BY x.data, x.roleta_id IS NULL');
    $stmt->execute(['e' => $empresaId, 'r' => $roletaId, 'hoje' => (new DateTimeImmutable('today', new DateTimeZone(ROLETA_FUSO)))->format('Y-m-d')]);
    return $stmt->fetchAll();
}

/** Próximos dias da roleta, já com feriados e pausas: [['data', 'rotulo', 'texto', 'exc']]. */
function roleta_proximos_dias(array $roleta, int $quantos = 7): array
{
    $tz = new DateTimeZone(ROLETA_FUSO);
    $agora = new DateTimeImmutable('now', $tz);
    $horarios = roleta_horarios_linhas((int) $roleta['id']);
    $excecoes = roleta_excecoes_linhas((int) $roleta['empresa_id'], (int) $roleta['id'], $agora);
    $rot = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];
    $dias = [];
    for ($i = 0; $i < $quantos; $i++) {
        $d = $agora->modify("+$i day");
        $data = $d->format('Y-m-d');
        $ints = roleta_exp_intervalos_do_dia($roleta, $horarios, $excecoes, $data, $tz);
        $exc = null;
        foreach ($excecoes as $e) {
            if ($e['data'] === $data && ($exc === null || $e['roleta_id'] !== null)) {
                $exc = $e;
            }
        }
        $texto = $ints ? implode(' e ', array_map(fn ($p) => $p[0]->format('H:i') . '–' . $p[1]->format('H:i'), $ints)) : 'Fechado';
        $dias[] = ['data' => $data, 'rotulo' => ($i === 0 ? 'Hoje' : ($i === 1 ? 'Amanhã' : $rot[(int) $d->format('N')])) . ' ' . $d->format('d/m'),
                   'texto' => $texto, 'exc' => $exc ? ($exc['descricao'] ?: ($exc['tipo'] === 'fechado' ? 'Fechado' : 'Horário especial')) : null];
    }
    return $dias;
}
