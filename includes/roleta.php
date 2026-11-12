<?php
/**
 * Roleta de distribuição (seção 9 do prompt mestre) — único lugar com as
 * regras de presença, elegibilidade, rodízio e fila. Páginas, endpoints,
 * o cron e (na Etapa 5) o webhook do Meta Ads chamam estas funções.
 *
 * Presença: o servidor decide sozinho se o corretor está dentro do raio
 * (distância calculada aqui, nunca um "dentro do raio" vindo do navegador).
 * A sessão vale enquanto o navegador renova a verificação dentro de
 * `validade_presenca_min`; sem renovação ela expira sozinha.
 *
 * Distribuição: rodízio por "quem está há mais tempo sem receber"
 * (roleta_corretores.ultimo_lead_em), serializado com SELECT … FOR UPDATE
 * na linha do lead e na da roleta — dois pedidos simultâneos nunca
 * entregam o mesmo lead duas vezes nem pulam a vez de alguém.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/leads.php';
require_once __DIR__ . '/auditoria.php';
require_once __DIR__ . '/transferencia.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/roleta_horarios.php';

const ROLETA_FUSO = 'America/Sao_Paulo';

function roleta_agora(): DateTime
{
    return new DateTime('now', new DateTimeZone(ROLETA_FUSO));
}

/** Dias ISO (1 = segunda … 7 = domingo). */
function roleta_dias_rotulos(): array
{
    return [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];
}

function roleta_dias_lista(?string $dias): array
{
    return array_values(array_filter(array_map('intval', explode(',', (string) $dias)), fn ($d) => $d >= 1 && $d <= 7));
}

function roleta_dias_texto(?string $dias): string
{
    $lista = roleta_dias_lista($dias);
    if (count($lista) === 7) {
        return 'Todos os dias';
    }
    if ($lista === [1, 2, 3, 4, 5]) {
        return 'Seg a Sex';
    }
    if ($lista === [1, 2, 3, 4, 5, 6]) {
        return 'Seg a Sáb';
    }
    $rot = roleta_dias_rotulos();
    return $lista ? implode(', ', array_map(fn ($d) => $rot[$d], $lista)) : 'Nenhum dia';
}

function roleta_horario_texto(array $roleta): string
{
    $roletaId = (int) ($roleta['roleta_id'] ?? $roleta['id'] ?? 0);
    if ($roletaId && roleta_horarios_personalizados($roletaId)) {
        $rot = roleta_dias_rotulos();
        $partes = [];
        foreach (roleta_horarios_linhas($roletaId) as $h) {
            $txt = $rot[(int) $h['dia_semana']] . ' ' . substr($h['inicio'], 0, 5) . '–' . substr($h['fim'], 0, 5);
            if ($h['pausa_inicio'] && $h['pausa_fim']) {
                $txt .= ' (pausa ' . substr($h['pausa_inicio'], 0, 5) . '–' . substr($h['pausa_fim'], 0, 5) . ')';
            }
            $partes[] = $txt;
        }
        return implode(' · ', $partes);
    }
    if (!$roleta['horario_inicio'] || !$roleta['horario_fim']) {
        return 'Qualquer horário';
    }
    return substr($roleta['horario_inicio'], 0, 5) . ' às ' . substr($roleta['horario_fim'], 0, 5);
}

/** Roleta + plantão (coordenadas e raio), sempre restrita à empresa. */
function roleta_carregar(int $empresaId, int $roletaId): ?array
{
    $stmt = db()->prepare('
        SELECT roletas.*, plantoes.nome AS plantao_nome, plantoes.endereco AS plantao_endereco,
               plantoes.bairro AS plantao_bairro, plantoes.cidade AS plantao_cidade, plantoes.estado AS plantao_estado,
               plantoes.latitude AS plantao_latitude, plantoes.longitude AS plantao_longitude,
               plantoes.raio_metros AS plantao_raio, plantoes.ativo AS plantao_ativo
        FROM roletas
        LEFT JOIN plantoes ON plantoes.id = roletas.plantao_id AND plantoes.empresa_id = roletas.empresa_id
        WHERE roletas.id = :id AND roletas.empresa_id = :empresa_id');
    $stmt->execute(['id' => $roletaId, 'empresa_id' => $empresaId]);
    return $stmt->fetch() ?: null;
}

/**
 * Está dentro do horário e do dia da semana? Devolve [bool, mensagem].
 * Horário que atravessa a meia-noite (ex.: 22:00 às 02:00) é aceito.
 */
function roleta_no_horario(array $roleta, ?DateTime $agora = null): array
{
    // Linha vinda de roleta_sessoes traz o id da SESSÃO em "id": o da roleta está em "roleta_id".
    $roletaId = (int) ($roleta['roleta_id'] ?? $roleta['id'] ?? 0);
    $empresaId = (int) ($roleta['empresa_id'] ?? 0);
    if ($roletaId && $empresaId) {
        $tz = new DateTimeZone(ROLETA_FUSO);
        $quando = $agora ? DateTimeImmutable::createFromMutable($agora)->setTimezone($tz) : new DateTimeImmutable('now', $tz);
        $sit = roleta_exp_situacao(
            $roleta,
            roleta_horarios_linhas($roletaId),
            roleta_excecoes_linhas($empresaId, $roletaId, $quando),
            $quando
        );
        return [$sit['aberta'], $sit['aberta'] ? '' : $sit['motivo']];
    }
    // Sem identificação da roleta (não deveria acontecer): regra antiga.
    $agora = $agora ?: roleta_agora();
    $dias = roleta_dias_lista($roleta['dias_semana'] ?? '1,2,3,4,5,6,7');
    if (!in_array((int) $agora->format('N'), $dias, true)) {
        return [false, 'Hoje não há plantão nesta roleta. Dias de funcionamento: ' . roleta_dias_texto($roleta['dias_semana']) . '.'];
    }
    $ini = $roleta['horario_inicio'] ?? null;
    $fim = $roleta['horario_fim'] ?? null;
    if (!$ini || !$fim) {
        return [true, ''];
    }
    $t = $agora->format('H:i:s');
    $dentro = $ini <= $fim ? ($t >= $ini && $t < $fim) : ($t >= $ini || $t < $fim);
    return $dentro ? [true, ''] : [false, 'Fora do horário do plantão. Horário da roleta: ' . roleta_horario_texto($roleta) . '.'];
}

/** Distância em metros entre dois pontos (fórmula de Haversine). */
function roleta_distancia_metros(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($a)));
}

function roleta_formatar_distancia(float $metros): string
{
    return $metros >= 1000 ? number_format($metros / 1000, 1, ',', '.') . ' km' : (int) round($metros) . ' m';
}

/** Vínculo do corretor com a roleta (null se não vinculado). */
function roleta_vinculo(int $roletaId, int $usuarioId): ?array
{
    $stmt = db()->prepare('SELECT * FROM roleta_corretores WHERE roleta_id = :r AND usuario_id = :u');
    $stmt->execute(['r' => $roletaId, 'u' => $usuarioId]);
    return $stmt->fetch() ?: null;
}

/**
 * Avalia uma posição sem gravar nada. Retorna:
 *   pode      bool    — pode entrar/permanecer
 *   codigo    string  — ok | nao_vinculado | pausado | roleta_pausada | fora_do_horario |
 *                       sem_coordenadas | posicao_invalida | gps_impreciso | fora_do_raio
 *   mensagem  string  — texto para o corretor
 *   distancia float|null
 */
function roleta_avaliar_posicao(array $roleta, ?array $vinculo, $lat, $lng, $precisao): array
{
    $r = ['pode' => false, 'codigo' => 'ok', 'mensagem' => '', 'distancia' => null];
    if (!$vinculo) {
        return ['codigo' => 'nao_vinculado', 'mensagem' => 'Você não está habilitado nesta roleta. Fale com seu gestor.'] + $r;
    }
    if (!empty($vinculo['pausado'])) {
        return ['codigo' => 'pausado', 'mensagem' => 'Sua participação nesta roleta foi pausada pelo gestor.'] + $r;
    }
    if (empty($roleta['ativa'])) {
        return ['codigo' => 'roleta_pausada', 'mensagem' => 'Esta roleta está pausada no momento.'] + $r;
    }
    [$noHorario, $msgHorario] = roleta_no_horario($roleta);
    if (!$noHorario) {
        return ['codigo' => 'fora_do_horario', 'mensagem' => $msgHorario] + $r;
    }
    if (empty($roleta['exigir_presenca'])) {
        return ['pode' => true, 'codigo' => 'ok', 'mensagem' => 'Esta roleta não exige presença no plantão.', 'distancia' => null];
    }
    if ($roleta['plantao_latitude'] === null || $roleta['plantao_longitude'] === null) {
        return ['codigo' => 'sem_coordenadas', 'mensagem' => 'O plantão desta roleta ainda não tem localização cadastrada. Avise seu gestor.'] + $r;
    }
    if (!is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180 || !is_numeric($precisao) || (float) $precisao <= 0) {
        return ['codigo' => 'posicao_invalida', 'mensagem' => 'Não recebemos uma localização válida do seu celular. Tente de novo.'] + $r;
    }
    $distancia = roleta_distancia_metros((float) $lat, (float) $lng, (float) $roleta['plantao_latitude'], (float) $roleta['plantao_longitude']);
    $r['distancia'] = $distancia;
    $precisaoMax = (int) $roleta['precisao_maxima_metros'];
    if ((float) $precisao > $precisaoMax) {
        return ['codigo' => 'gps_impreciso', 'mensagem' => 'Sinal de GPS impreciso (±' . (int) round($precisao) . ' m; o máximo aceito é ±' . $precisaoMax . ' m). Vá para perto de uma janela ou área aberta e tente de novo.'] + $r;
    }
    $raio = (int) $roleta['plantao_raio'];
    if ($distancia > $raio) {
        return ['codigo' => 'fora_do_raio', 'mensagem' => 'Você está a ' . roleta_formatar_distancia($distancia) . ' do plantão. É preciso estar a até ' . $raio . ' m.'] + $r;
    }
    return ['pode' => true, 'codigo' => 'ok', 'mensagem' => 'Você está a ' . roleta_formatar_distancia($distancia) . ' do plantão.', 'distancia' => $distancia];
}

/** Sessão aberta (pode estar vencida — confira roleta_sessao_valida). */
function roleta_sessao_aberta(int $roletaId, int $usuarioId): ?array
{
    $stmt = db()->prepare('SELECT * FROM roleta_sessoes WHERE roleta_id = :r AND usuario_id = :u AND saiu_em IS NULL LIMIT 1');
    $stmt->execute(['r' => $roletaId, 'u' => $usuarioId]);
    return $stmt->fetch() ?: null;
}

function roleta_sessao_valida(?array $sessao, array $roleta): bool
{
    if (!$sessao || $sessao['saiu_em'] !== null) {
        return false;
    }
    return strtotime($sessao['ultima_verificacao_em']) >= time() - 60 * (int) $roleta['validade_presenca_min'];
}

function roleta_fechar_sessao(int $sessaoId, string $motivo): void
{
    db()->prepare('UPDATE roleta_sessoes SET saiu_em = NOW(), motivo_saida = :m, aberta_chave = NULL WHERE id = :id AND saiu_em IS NULL')
        ->execute(['m' => $motivo, 'id' => $sessaoId]);
}

/**
 * Fecha sessões vencidas (sem renovação dentro da validade) e as que
 * ficaram fora do horário/dia ou de roleta pausada. Chamada sempre que a
 * roleta é lida, antes de distribuir e pelo cron. Retorna quantas fechou.
 */
function roleta_fechar_expiradas(?int $empresaId = null, ?int $roletaId = null): int
{
    $sql = 'SELECT s.id, s.roleta_id, r.empresa_id, s.ultima_verificacao_em, r.ativa, r.dias_semana, r.horario_inicio, r.horario_fim, r.validade_presenca_min
            FROM roleta_sessoes s INNER JOIN roletas r ON r.id = s.roleta_id
            WHERE s.saiu_em IS NULL';
    $params = [];
    if ($empresaId) {
        $sql .= ' AND s.empresa_id = :e';
        $params['e'] = $empresaId;
    }
    if ($roletaId) {
        $sql .= ' AND s.roleta_id = :r';
        $params['r'] = $roletaId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $fechadas = 0;
    foreach ($stmt->fetchAll() as $s) {
        $motivo = null;
        if (!$s['ativa']) {
            $motivo = 'roleta_pausada';
        } elseif (!roleta_no_horario($s)[0]) {
            $motivo = 'fora_do_horario';
        } elseif (strtotime($s['ultima_verificacao_em']) < time() - 60 * (int) $s['validade_presenca_min']) {
            $motivo = 'expirou';
        }
        if ($motivo) {
            roleta_fechar_sessao((int) $s['id'], $motivo);
            $fechadas++;
        }
    }
    return $fechadas;
}

/**
 * Check-in do corretor logado. $acao: verificar | entrar | renovar | sair.
 * Grava roleta_presencas em toda chamada (inclusive recusas) e abre,
 * renova ou fecha a sessão. Nunca recebe usuario_id do navegador.
 * Retorna ['ok', 'avaliacao', 'sessao' (array|null), 'distribuidos' (int)].
 */
function roleta_registrar_presenca(array $usuario, int $roletaId, string $acao, $lat, $lng, $precisao): array
{
    $empresaId = (int) $usuario['empresa_ativa_id'];
    $uid = (int) $usuario['id'];
    $roleta = roleta_carregar($empresaId, $roletaId);
    if (!$roleta) {
        return ['ok' => false, 'erro' => 'Roleta não encontrada.', 'status' => 404];
    }
    $vinculo = roleta_vinculo($roletaId, $uid);
    if (!$vinculo) {
        return ['ok' => false, 'erro' => 'Você não está habilitado nesta roleta.', 'status' => 403];
    }

    roleta_fechar_expiradas($empresaId, $roletaId);
    $sessao = roleta_sessao_aberta($roletaId, $uid);

    if ($acao === 'sair') {
        if ($sessao) {
            roleta_fechar_sessao((int) $sessao['id'], 'manual');
            roleta_gravar_presenca($roletaId, $uid, 'saida', null, null, null, null, false, 'Saiu da roleta');
            auditoria_registrar($empresaId, $uid, 'roleta.saiu', 'roleta', $roletaId);
        }
        return ['ok' => true, 'avaliacao' => null, 'sessao' => null, 'distribuidos' => 0];
    }

    $av = roleta_avaliar_posicao($roleta, $vinculo, $lat, $lng, $precisao);
    $latOk = is_numeric($lat) && abs((float) $lat) <= 90 ? (float) $lat : null;
    $lngOk = is_numeric($lng) && abs((float) $lng) <= 180 ? (float) $lng : null;
    $precOk = is_numeric($precisao) && (float) $precisao > 0 ? min((float) $precisao, 999999) : null;

    if (!$av['pode']) {
        roleta_gravar_presenca($roletaId, $uid, 'recusada', $latOk, $lngOk, $precOk, $av['distancia'], false, $av['codigo']);
        if ($sessao) {
            $motivo = $av['codigo'] === 'fora_do_horario' ? 'fora_do_horario' : ($av['codigo'] === 'roleta_pausada' ? 'roleta_pausada' : 'fora_do_raio');
            // GPS impreciso numa renovação não derruba quem já está dentro:
            // a sessão só vence pelo prazo de validade.
            if ($av['codigo'] !== 'gps_impreciso' && $av['codigo'] !== 'posicao_invalida') {
                roleta_fechar_sessao((int) $sessao['id'], $motivo);
                $sessao = null;
            }
        }
        return ['ok' => true, 'avaliacao' => $av, 'sessao' => $sessao ? roleta_sessao_aberta($roletaId, $uid) : null, 'distribuidos' => 0];
    }

    $distribuidos = 0;
    if ($acao === 'verificar') {
        roleta_gravar_presenca($roletaId, $uid, 'verificacao', $latOk, $lngOk, $precOk, $av['distancia'], true, null);
        if ($sessao) {
            db()->prepare('UPDATE roleta_sessoes SET ultima_verificacao_em = NOW() WHERE id = :id')->execute(['id' => $sessao['id']]);
        }
    } elseif ($acao === 'entrar' || $acao === 'renovar') {
        if ($sessao) {
            db()->prepare('UPDATE roleta_sessoes SET ultima_verificacao_em = NOW() WHERE id = :id')->execute(['id' => $sessao['id']]);
            roleta_gravar_presenca($roletaId, $uid, 'verificacao', $latOk, $lngOk, $precOk, $av['distancia'], true, null);
        } elseif ($acao === 'entrar') {
            // INSERT IGNORE + chave única: dois cliques simultâneos abrem uma só sessão.
            db()->prepare('INSERT IGNORE INTO roleta_sessoes (empresa_id, roleta_id, usuario_id, aberta_chave) VALUES (:e, :r, :u, :k)')
                ->execute(['e' => $empresaId, 'r' => $roletaId, 'u' => $uid, 'k' => $roletaId . ':' . $uid]);
            roleta_gravar_presenca($roletaId, $uid, 'entrada', $latOk, $lngOk, $precOk, $av['distancia'], true, null);
            auditoria_registrar($empresaId, $uid, 'roleta.entrou', 'roleta', $roletaId, ['distancia_m' => $av['distancia'] !== null ? (int) round($av['distancia']) : null]);
            // Alguém entrou: tenta entregar os leads que esperavam na fila.
            $distribuidos = roleta_processar_fila($empresaId, $roletaId, 20)['distribuidos'];
        } else {
            // "renovar" sem sessão aberta = a sessão expirou; não reabre sozinha.
            roleta_gravar_presenca($roletaId, $uid, 'verificacao', $latOk, $lngOk, $precOk, $av['distancia'], true, 'sessao_expirada');
        }
    }
    return ['ok' => true, 'avaliacao' => $av, 'sessao' => roleta_sessao_aberta($roletaId, $uid), 'distribuidos' => $distribuidos];
}

function roleta_gravar_presenca(int $roletaId, int $uid, string $tipo, ?float $lat, ?float $lng, ?float $precisao, ?float $distancia, bool $dentro, ?string $motivo): void
{
    db()->prepare('INSERT INTO roleta_presencas (roleta_id, usuario_id, tipo, latitude, longitude, precisao_metros, distancia_metros, dentro_do_raio, motivo)
                   VALUES (:r, :u, :t, :lat, :lng, :p, :d, :dentro, :m)')
        ->execute(['r' => $roletaId, 'u' => $uid, 't' => $tipo, 'lat' => $lat, 'lng' => $lng,
            'p' => $precisao !== null ? round($precisao, 1) : null, 'd' => $distancia !== null ? round($distancia, 1) : null,
            'dentro' => $dentro ? 1 : 0, 'm' => $motivo !== null ? mb_substr($motivo, 0, 120) : null]);
}

/** Quantos leads cada corretor recebeu hoje por esta roleta. */
function roleta_recebidos_hoje(int $roletaId, ?int $usuarioId = null): array
{
    $sql = 'SELECT usuario_id, COUNT(*) AS n FROM roleta_distribuicoes WHERE roleta_id = :r AND distribuido_em >= CURDATE()';
    $params = ['r' => $roletaId];
    if ($usuarioId) {
        $sql .= ' AND usuario_id = :u';
        $params['u'] = $usuarioId;
    }
    $stmt = db()->prepare($sql . ' GROUP BY usuario_id');
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int) $row['usuario_id']] = (int) $row['n'];
    }
    return $out;
}

/**
 * Corretores aptos a receber AGORA, já na ordem do rodízio (o primeiro é o
 * próximo a receber). Não confere horário — quem chama confere a roleta.
 */
function roleta_elegiveis(array $roleta, ?int $evitarUsuarioId = null): array
{
    $presenca = !empty($roleta['exigir_presenca'])
        ? 'INNER JOIN roleta_sessoes s ON s.roleta_id = rc.roleta_id AND s.usuario_id = rc.usuario_id AND s.saiu_em IS NULL
               AND s.ultima_verificacao_em >= DATE_SUB(NOW(), INTERVAL ' . (int) $roleta['validade_presenca_min'] . ' MINUTE)'
        : '';
    $limite = $roleta['max_leads_dia_corretor'] !== null
        ? ' AND (SELECT COUNT(*) FROM roleta_distribuicoes d WHERE d.roleta_id = rc.roleta_id AND d.usuario_id = rc.usuario_id AND d.distribuido_em >= CURDATE()) < ' . (int) $roleta['max_leads_dia_corretor']
        : '';
    $stmt = db()->prepare('
        SELECT rc.usuario_id, rc.ordem, rc.ultimo_lead_em, rc.total_recebidos, u.nome, u.equipe_id
        FROM roleta_corretores rc
        INNER JOIN usuarios u ON u.id = rc.usuario_id AND u.ativo = 1 AND u.empresa_id = :e
        ' . $presenca . '
        WHERE rc.roleta_id = :r AND rc.pausado = 0' . $limite . ($evitarUsuarioId ? ' AND rc.usuario_id <> ' . (int) $evitarUsuarioId : '') . '
        ORDER BY (rc.ultimo_lead_em IS NULL) DESC, rc.ultimo_lead_em ASC, rc.ordem ASC, rc.usuario_id ASC');
    $stmt->execute(['e' => (int) $roleta['empresa_id'], 'r' => (int) $roleta['id']]);
    return $stmt->fetchAll();
}

/** Roletas ativas que atendem o empreendimento, por prioridade (menor primeiro). */
function roleta_candidatas(int $empresaId, ?int $empreendimentoId): array
{
    if (!$empreendimentoId) {
        return [];
    }
    $stmt = db()->prepare('
        SELECT roletas.id FROM roletas
        INNER JOIN roleta_empreendimentos re ON re.roleta_id = roletas.id AND re.empreendimento_id = :emp
        WHERE roletas.empresa_id = :e AND roletas.ativa = 1
        ORDER BY re.prioridade ASC, roletas.id ASC');
    $stmt->execute(['emp' => $empreendimentoId, 'e' => $empresaId]);
    return array_map('intval', array_column($stmt->fetchAll(), 'id'));
}

/**
 * Distribui um lead sem responsável pelo rodízio. Independente de tela:
 * cadastro manual, fila, cron e (Etapa 5) webhook do Meta Ads chamam aqui.
 *
 * $origem: manual | meta_ads | importacao | fila | reprocessamento | recuperacao
 * $executor: usuário que pediu (null = automático)
 *
 * Um lead pode passar pela roleta mais de uma vez (recuperação por
 * inatividade): a idempotência vem de `responsavel_id IS NULL` + trava de
 * linha, não de unicidade em roleta_distribuicoes. Se o lead está na fila
 * com `evitar_usuario_id` (o corretor que o perdeu), esse corretor é pulado
 * — se só ele estiver disponível, o lead continua esperando.
 *
 * Retorna ['status' => 'distribuido'|'fila'|'ja_atribuido'|'nao_encontrado',
 *          'usuario_id', 'usuario_nome', 'roleta_id', 'roleta_nome', 'motivo']
 */
function roleta_distribuir_lead(int $leadId, string $origem = 'manual', ?array $executor = null): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, empresa_id, empreendimento_id, responsavel_id FROM leads WHERE id = :id AND excluido_em IS NULL');
    $stmt->execute(['id' => $leadId]);
    $lead = $stmt->fetch();
    if (!$lead) {
        return ['status' => 'nao_encontrado'];
    }
    if ($lead['responsavel_id']) {
        return ['status' => 'ja_atribuido', 'usuario_id' => (int) $lead['responsavel_id']];
    }
    $empresaId = (int) $lead['empresa_id'];
    $executorId = $executor ? (int) $executor['id'] : null;
    roleta_fechar_expiradas($empresaId);
    $stmt = $pdo->prepare('SELECT evitar_usuario_id FROM roleta_fila WHERE lead_id = :l AND resolvido_em IS NULL');
    $stmt->execute(['l' => $leadId]);
    $evitar = $stmt->fetchColumn();
    $evitar = $evitar ? (int) $evitar : null;

    $candidatas = roleta_candidatas($empresaId, $lead['empreendimento_id'] ? (int) $lead['empreendimento_id'] : null);
    $motivoFila = $candidatas
        ? ($evitar ? 'Nenhum outro corretor disponível na roleta agora' : 'Nenhum corretor disponível na roleta agora')
        : 'Nenhuma roleta ativa atende este empreendimento';

    foreach ($candidatas as $roletaId) {
        // READ COMMITTED: depois de obter a trava da roleta, as leituras
        // seguintes enxergam a última distribuição já confirmada.
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();
        try {
            // Ordem fixa de travas (lead → roleta) evita deadlock entre distribuições.
            $stmt = $pdo->prepare('SELECT responsavel_id FROM leads WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $leadId]);
            $resp = $stmt->fetchColumn();
            if ($resp) {
                $pdo->commit();
                return ['status' => 'ja_atribuido', 'usuario_id' => (int) $resp];
            }
            $pdo->prepare('SELECT id FROM roletas WHERE id = :id FOR UPDATE')->execute(['id' => $roletaId]);
            $roleta = roleta_carregar($empresaId, $roletaId);
            if (!$roleta || !$roleta['ativa'] || !roleta_no_horario($roleta)[0]) {
                $pdo->commit();
                continue;
            }
            $elegiveis = roleta_elegiveis($roleta, $evitar);
            if (!$elegiveis) {
                $pdo->commit();
                continue;
            }
            $escolhido = $elegiveis[0];
            $uid = (int) $escolhido['usuario_id'];
            // Mesma regra de lead_marcar_atribuicao(): prazo inteiro da recuperação
            // para o novo dono, atribuido_em agora e primeiro contato ainda por fazer.
            $upd = $pdo->prepare('UPDATE leads SET responsavel_id = :u, equipe_id = :eq, ultima_atividade_em = NOW(), ultimo_feedback_em = NOW(),
                atribuido_em = NOW(), primeiro_contato_em = NULL WHERE id = :id AND responsavel_id IS NULL');
            $upd->execute(['u' => $uid, 'eq' => $escolhido['equipe_id'], 'id' => $leadId]);
            if ($upd->rowCount() !== 1) {
                $pdo->rollBack();
                return ['status' => 'ja_atribuido'];
            }
            // NOW(6) + coluna DATETIME(6): ordem estável mesmo com várias entregas no mesmo segundo.
            $pdo->prepare('UPDATE roleta_corretores SET ultimo_lead_em = NOW(6), total_recebidos = total_recebidos + 1 WHERE roleta_id = :r AND usuario_id = :u')
                ->execute(['r' => $roletaId, 'u' => $uid]);
            $pdo->prepare('INSERT INTO roleta_distribuicoes (empresa_id, roleta_id, lead_id, usuario_id, origem, executado_por) VALUES (:e, :r, :l, :u, :o, :x)')
                ->execute(['e' => $empresaId, 'r' => $roletaId, 'l' => $leadId, 'u' => $uid, 'o' => $origem, 'x' => $executorId]);
            $pdo->prepare('UPDATE roleta_fila SET resolvido_em = NOW() WHERE lead_id = :l AND resolvido_em IS NULL')->execute(['l' => $leadId]);
            transferencia_completar_recuperacao($leadId, $uid);
            lead_registrar_evento($leadId, $executorId, 'atribuicao', 'Recebido pela roleta ' . $roleta['nome'] . ' — atribuído a ' . $escolhido['nome']);
            auditoria_registrar($empresaId, $executorId, 'roleta.lead_distribuido', 'lead', $leadId, ['roleta_id' => $roletaId, 'usuario_id' => $uid, 'origem' => $origem]);
            $pdo->commit();
            lead_apos_atribuicao($leadId, $origem === 'recuperacao');
            return ['status' => 'distribuido', 'usuario_id' => $uid, 'usuario_nome' => $escolhido['nome'], 'roleta_id' => $roletaId, 'roleta_nome' => $roleta['nome']];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // Ninguém disponível: fila de espera, sem perder o lead.
    $stmt = $pdo->prepare('SELECT id, resolvido_em FROM roleta_fila WHERE lead_id = :l');
    $stmt->execute(['l' => $leadId]);
    $naFila = $stmt->fetch();
    $origemFila = in_array($origem, ['manual', 'meta_ads', 'importacao', 'reprocessamento', 'recuperacao'], true) ? $origem : 'reprocessamento';
    $pdo->prepare('INSERT INTO roleta_fila (empresa_id, roleta_id, lead_id, origem, motivo) VALUES (:e, :r, :l, :o, :m)
                   ON DUPLICATE KEY UPDATE tentativas = tentativas + 1, motivo = VALUES(motivo), roleta_id = VALUES(roleta_id),
                       origem = IF(resolvido_em IS NULL, origem, VALUES(origem)),
                       evitar_usuario_id = IF(resolvido_em IS NULL, evitar_usuario_id, NULL),
                       resolvido_em = NULL')
        ->execute(['e' => $empresaId, 'r' => $candidatas[0] ?? null, 'l' => $leadId, 'o' => $origemFila, 'm' => $motivoFila]);
    if (!$naFila || $naFila['resolvido_em'] !== null) {
        lead_registrar_evento($leadId, $executorId, 'sistema', 'Aguardando corretor disponível — ' . mb_strtolower($motivoFila) . '.');
        auditoria_registrar($empresaId, $executorId, 'roleta.lead_em_fila', 'lead', $leadId, ['motivo' => $motivoFila]);
    }
    $roletaNome = null;
    if ($candidatas) {
        $r = roleta_carregar($empresaId, $candidatas[0]);
        $roletaNome = $r['nome'] ?? null;
    }
    return ['status' => 'fila', 'roleta_id' => $candidatas[0] ?? null, 'roleta_nome' => $roletaNome, 'motivo' => $motivoFila];
}

/**
 * Tenta distribuir os leads que estão na fila (mais antigos primeiro).
 * Filtra por empresa e, opcionalmente, pelas roletas que atendem o
 * empreendimento do lead. Retorna ['tentados', 'distribuidos'].
 */
function roleta_processar_fila(?int $empresaId = null, ?int $roletaId = null, int $limite = 200): array
{
    $sql = 'SELECT f.lead_id, f.origem FROM roleta_fila f INNER JOIN leads l ON l.id = f.lead_id AND l.excluido_em IS NULL WHERE f.resolvido_em IS NULL';
    $params = [];
    if ($empresaId) {
        $sql .= ' AND f.empresa_id = :e';
        $params['e'] = $empresaId;
    }
    if ($roletaId) {
        $sql .= ' AND l.empreendimento_id IN (SELECT empreendimento_id FROM roleta_empreendimentos WHERE roleta_id = :r)';
        $params['r'] = $roletaId;
    }
    $stmt = db()->prepare($sql . ' ORDER BY f.entrou_em ASC, f.id ASC LIMIT ' . max(1, $limite));
    $stmt->execute($params);
    $tentados = 0;
    $distribuidos = 0;
    foreach ($stmt->fetchAll() as $fila) {
        $leadId = (int) $fila['lead_id'];
        $tentados++;
        $res = roleta_distribuir_lead($leadId, $fila['origem'] === 'recuperacao' ? 'recuperacao' : 'fila', null);
        if ($res['status'] === 'distribuido') {
            $distribuidos++;
        } elseif ($res['status'] === 'ja_atribuido' || $res['status'] === 'nao_encontrado') {
            // Atribuído por outro caminho (transferência manual) ou excluído: sai da fila.
            db()->prepare('UPDATE roleta_fila SET resolvido_em = NOW() WHERE lead_id = :l AND resolvido_em IS NULL')->execute(['l' => $leadId]);
        }
    }
    return ['tentados' => $tentados, 'distribuidos' => $distribuidos];
}

/**
 * Situação de cada corretor da roleta para a tela de gestão, com a posição
 * no rodízio de quem está apto agora.
 */
function roleta_status_corretores(array $roleta): array
{
    $stmt = db()->prepare('
        SELECT rc.usuario_id, rc.ordem, rc.pausado, rc.ultimo_lead_em, rc.total_recebidos, u.nome, u.ativo,
               s.id AS sessao_id, s.entrou_em, s.ultima_verificacao_em,
               (SELECT s2.saiu_em FROM roleta_sessoes s2 WHERE s2.roleta_id = rc.roleta_id AND s2.usuario_id = rc.usuario_id AND s2.saiu_em IS NOT NULL ORDER BY s2.saiu_em DESC LIMIT 1) AS ultima_saida,
               (SELECT s3.motivo_saida FROM roleta_sessoes s3 WHERE s3.roleta_id = rc.roleta_id AND s3.usuario_id = rc.usuario_id AND s3.saiu_em IS NOT NULL ORDER BY s3.saiu_em DESC LIMIT 1) AS ultimo_motivo,
               (SELECT p.distancia_metros FROM roleta_presencas p WHERE p.roleta_id = rc.roleta_id AND p.usuario_id = rc.usuario_id AND p.distancia_metros IS NOT NULL ORDER BY p.id DESC LIMIT 1) AS ultima_distancia
        FROM roleta_corretores rc
        INNER JOIN usuarios u ON u.id = rc.usuario_id
        LEFT JOIN roleta_sessoes s ON s.roleta_id = rc.roleta_id AND s.usuario_id = rc.usuario_id AND s.saiu_em IS NULL
        WHERE rc.roleta_id = :r
        ORDER BY rc.ordem, u.nome');
    $stmt->execute(['r' => (int) $roleta['id']]);
    $linhas = $stmt->fetchAll();

    $noHorario = !empty($roleta['ativa']) && roleta_no_horario($roleta)[0];
    $fila = $noHorario ? array_map('intval', array_column(roleta_elegiveis($roleta), 'usuario_id')) : [];
    $hoje = roleta_recebidos_hoje((int) $roleta['id']);
    foreach ($linhas as &$l) {
        $uid = (int) $l['usuario_id'];
        $pos = array_search($uid, $fila, true);
        $l['posicao'] = $pos === false ? null : $pos + 1;
        $l['recebidos_hoje'] = $hoje[$uid] ?? 0;
        $l['sessao_valida'] = $l['sessao_id'] && strtotime($l['ultima_verificacao_em']) >= time() - 60 * (int) $roleta['validade_presenca_min'];
    }
    return $linhas;
}

function roleta_motivo_saida_rotulo(?string $motivo): string
{
    return [
        'manual' => 'saiu', 'fora_do_raio' => 'saiu do raio', 'expirou' => 'presença expirou',
        'fora_do_horario' => 'fim do horário', 'roleta_pausada' => 'roleta pausada',
        'removido' => 'removido da roleta', 'gestor' => 'retirado pelo gestor',
    ][$motivo] ?? 'saiu';
}

function roleta_origem_rotulo(string $origem): string
{
    return ['manual' => 'Cadastro manual', 'meta_ads' => 'Meta Ads', 'importacao' => 'Importação', 'fila' => 'Fila de espera', 'reprocessamento' => 'Reprocessamento', 'recuperacao' => 'Recuperação'][$origem] ?? $origem;
}

/** Leads aguardando na fila, filtrados pelo escopo de quem vê. */
function roleta_fila_listar(array $usuario, ?int $roletaId = null): array
{
    $scope = rbac_leads_scope($usuario);
    $sql = 'SELECT f.id AS fila_id, f.lead_id, f.roleta_id, f.motivo, f.entrou_em, f.tentativas, f.origem,
                   leads.nome, leads.telefone, empreendimentos.nome AS empreendimento_nome, roletas.nome AS roleta_nome
            FROM roleta_fila f
            INNER JOIN leads ON leads.id = f.lead_id
            LEFT JOIN empreendimentos ON empreendimentos.id = leads.empreendimento_id
            LEFT JOIN roletas ON roletas.id = f.roleta_id
            WHERE f.resolvido_em IS NULL AND leads.responsavel_id IS NULL AND ' . $scope['sql'];
    $params = $scope['params'];
    if ($roletaId) {
        $sql .= ' AND (f.roleta_id = :fr OR leads.empreendimento_id IN (SELECT empreendimento_id FROM roleta_empreendimentos WHERE roleta_id = :fr2))';
        $params['fr'] = $roletaId;
        $params['fr2'] = $roletaId;
    }
    $stmt = db()->prepare($sql . ' ORDER BY f.entrou_em ASC');
    $stmt->execute($params);
    return $stmt->fetchAll();
}
