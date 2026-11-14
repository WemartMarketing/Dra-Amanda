<?php
/**
 * Investimento em anúncios (migração 016) e custos por resultado.
 *
 * Cada lançamento tem período, origem (Meta Ads, Google Ads...), valor e,
 * opcionalmente, empreendimento. No relatório o valor é RATEADO POR DIA: só
 * a parte dos dias que cai dentro do período entra.
 *
 * Regras de confiabilidade (nada estimado):
 *   - sem lançamento que cubra o recorte → custo "não disponível";
 *   - custos só para quem vê a empresa toda (o investimento não é por
 *     equipe/corretor) e sem filtro de estrutura, corretor, roleta ou plantão;
 *   - com filtro de empreendimento, só entram lançamentos daquele
 *     empreendimento (os "todos os empreendimentos" não podem ser divididos);
 *   - ROI continua indisponível: o sistema não tem receita (VGV não é receita).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/origens.php';

function investimentos_instalados(): bool
{
    return modulo_tabela_existe('marketing_investimentos');
}

/** Valida e grava um lançamento. $id para editar. */
function investimento_salvar(array $usuario, array $e, ?int $id = null, string $fonte = 'manual'): array
{
    $empresaId = (int) $usuario['empresa_ativa_id'];
    $di = DateTime::createFromFormat('!Y-m-d', (string) ($e['data_inicio'] ?? ''));
    $df = DateTime::createFromFormat('!Y-m-d', (string) ($e['data_fim'] ?? ''));
    if (!$di || !$df || $di->format('Y-m-d') !== ($e['data_inicio'] ?? '') || $df->format('Y-m-d') !== ($e['data_fim'] ?? '')) {
        return ['ok' => false, 'erro' => 'Informe as datas de início e fim.'];
    }
    if ($df < $di) {
        return ['ok' => false, 'erro' => 'A data final é antes da inicial.'];
    }
    if ($di->diff($df)->days > 366) {
        return ['ok' => false, 'erro' => 'Lance períodos de até 1 ano (um lançamento por mês é o ideal).'];
    }
    $valor = is_numeric($e['valor'] ?? null) ? (float) $e['valor'] : investimento_valor_texto((string) ($e['valor'] ?? ''));
    if ($valor === null || $valor <= 0 || $valor > 100000000) {
        return ['ok' => false, 'erro' => 'Valor inválido. Use, por exemplo, 12.500,00.'];
    }
    $origem = origem_carregar($empresaId, (int) ($e['origem_detalhe_id'] ?? 0));
    if (!$origem) {
        return ['ok' => false, 'erro' => 'Escolha a origem (ex.: Meta Ads, Google Ads).'];
    }
    $emp = (int) ($e['empreendimento_id'] ?? 0) ?: null;
    if ($emp && !(int) db()->query('SELECT COUNT(*) FROM empreendimentos WHERE id = ' . $emp . ' AND empresa_id = ' . $empresaId)->fetchColumn()) {
        return ['ok' => false, 'erro' => 'Empreendimento não encontrado.'];
    }
    $dados = ['di' => $di->format('Y-m-d'), 'df' => $df->format('Y-m-d'), 'o' => (int) $origem['id'], 'emp' => $emp,
              'c' => trim(mb_substr((string) ($e['campanha'] ?? ''), 0, 190)) ?: null, 'v' => round($valor, 2),
              'd' => trim(mb_substr((string) ($e['descricao'] ?? ''), 0, 300)) ?: null];
    if ($id) {
        $upd = db()->prepare('UPDATE marketing_investimentos SET data_inicio = :di, data_fim = :df, origem_detalhe_id = :o, empreendimento_id = :emp, campanha = :c, valor = :v, descricao = :d
                              WHERE id = :id AND empresa_id = :e AND excluido_em IS NULL');
        $upd->execute($dados + ['id' => $id, 'e' => $empresaId]);
        return $upd->rowCount() || (int) db()->query('SELECT COUNT(*) FROM marketing_investimentos WHERE id = ' . (int) $id . ' AND empresa_id = ' . $empresaId . ' AND excluido_em IS NULL')->fetchColumn()
            ? ['ok' => true, 'id' => $id] : ['ok' => false, 'erro' => 'Lançamento não encontrado.'];
    }
    db()->prepare('INSERT INTO marketing_investimentos (empresa_id, data_inicio, data_fim, origem_detalhe_id, empreendimento_id, campanha, valor, descricao, fonte, criado_por)
                   VALUES (:e, :di, :df, :o, :emp, :c, :v, :d, :f, :u)')
        ->execute($dados + ['e' => $empresaId, 'f' => $fonte, 'u' => $usuario['id']]);
    return ['ok' => true, 'id' => (int) db()->lastInsertId()];
}

/** "12.500,00" → 12500.0; inválido → null. */
function investimento_valor_texto(string $t): ?float
{
    $t = preg_replace('/[^\d,\.]/', '', trim($t));
    if ($t === '') {
        return null;
    }
    if (strpos($t, ',') !== false) {
        $t = str_replace(['.', ','], ['', '.'], $t);
    } elseif (substr_count($t, '.') > 1 || preg_match('/\.\d{3}$/', $t)) {
        $t = str_replace('.', '', $t);
    }
    return is_numeric($t) ? (float) $t : null;
}

function investimento_excluir(array $usuario, int $id): bool
{
    $upd = db()->prepare('UPDATE marketing_investimentos SET excluido_em = NOW(), excluido_por = :u WHERE id = :id AND empresa_id = :e AND excluido_em IS NULL');
    $upd->execute(['u' => $usuario['id'], 'id' => $id, 'e' => $usuario['empresa_ativa_id']]);
    return $upd->rowCount() === 1;
}

function investimentos_listar(int $empresaId, ?string $ini = null, ?string $fim = null): array
{
    if (!investimentos_instalados()) {
        return [];
    }
    $sql = 'SELECT mi.*, o.nome AS origem_nome, emp.nome AS empreendimento_nome, u.nome AS criado_por_nome
            FROM marketing_investimentos mi JOIN lead_origens o ON o.id = mi.origem_detalhe_id
            LEFT JOIN empreendimentos emp ON emp.id = mi.empreendimento_id LEFT JOIN usuarios u ON u.id = mi.criado_por
            WHERE mi.empresa_id = :e AND mi.excluido_em IS NULL';
    $p = ['e' => $empresaId];
    if ($ini && $fim) {
        $sql .= ' AND mi.data_fim >= :i AND mi.data_inicio <= :f';
        $p += ['i' => $ini, 'f' => $fim];
    }
    $stmt = db()->prepare($sql . ' ORDER BY mi.data_inicio DESC, mi.id DESC');
    $stmt->execute($p);
    return $stmt->fetchAll();
}

/**
 * Importa uma planilha CSV (";" ou ","), com cabeçalho:
 *   data_inicio; data_fim; origem; empreendimento; campanha; valor; descricao
 * Datas em dd/mm/aaaa ou aaaa-mm-dd; origem e empreendimento pelo NOME.
 * Tudo ou nada: com qualquer linha inválida, nada é gravado e as linhas com
 * problema são devolvidas.
 */
function investimento_importar_csv(array $usuario, string $conteudo): array
{
    $empresaId = (int) $usuario['empresa_ativa_id'];
    $conteudo = preg_replace('/^\xEF\xBB\xBF/', '', $conteudo);
    if (!mb_check_encoding($conteudo, 'UTF-8')) {
        $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'Windows-1252');
    }
    $linhas = preg_split('/\r\n|\r|\n/', trim($conteudo));
    if (count($linhas) < 2) {
        return ['ok' => false, 'erros' => ['A planilha está vazia.'], 'importados' => 0];
    }
    $sep = substr_count($linhas[0], ';') >= substr_count($linhas[0], ',') ? ';' : ',';
    $cab = array_map(fn ($c) => mb_strtolower(trim($c, " \t\"")), str_getcsv($linhas[0], $sep));
    $idx = [];
    foreach (['data_inicio', 'data_fim', 'origem', 'empreendimento', 'campanha', 'valor', 'descricao'] as $c) {
        $idx[$c] = array_search($c, $cab, true);
    }
    foreach (['data_inicio', 'data_fim', 'origem', 'valor'] as $obrig) {
        if ($idx[$obrig] === false) {
            return ['ok' => false, 'erros' => ['Falta a coluna "' . $obrig . '" no cabeçalho.'], 'importados' => 0];
        }
    }
    $origens = [];
    foreach (origens_listar($empresaId, false) as $o) {
        $origens[mb_strtolower($o['nome'])] = (int) $o['id'];
    }
    $emps = [];
    foreach (db()->query('SELECT id, nome FROM empreendimentos WHERE empresa_id = ' . $empresaId)->fetchAll() as $e) {
        $emps[mb_strtolower($e['nome'])] = (int) $e['id'];
    }
    $data = function (string $s): string {
        $s = trim($s);
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $s, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        return $s;
    };
    $erros = [];
    $validos = [];
    foreach (array_slice($linhas, 1) as $n => $l) {
        if (trim($l) === '') {
            continue;
        }
        $c = str_getcsv($l, $sep);
        $pega = fn (string $k) => $idx[$k] === false ? '' : trim((string) ($c[$idx[$k]] ?? ''));
        $num = $n + 2;
        $oid = $origens[mb_strtolower($pega('origem'))] ?? null;
        if (!$oid) {
            $erros[] = "Linha $num: origem \"" . $pega('origem') . '" não existe (cadastre em Etapas do funil → Origens).';
            continue;
        }
        $eid = null;
        if ($pega('empreendimento') !== '') {
            $eid = $emps[mb_strtolower($pega('empreendimento'))] ?? null;
            if (!$eid) {
                $erros[] = "Linha $num: empreendimento \"" . $pega('empreendimento') . '" não encontrado.';
                continue;
            }
        }
        $reg = ['data_inicio' => $data($pega('data_inicio')), 'data_fim' => $data($pega('data_fim')), 'origem_detalhe_id' => $oid, 'empreendimento_id' => $eid,
                'campanha' => $pega('campanha'), 'valor' => investimento_valor_texto($pega('valor')), 'descricao' => $pega('descricao')];
        $validos[] = [$num, $reg];
    }
    if (count($validos) > 2000) {
        return ['ok' => false, 'erros' => ['Máximo de 2.000 linhas por planilha.'], 'importados' => 0];
    }
    $pdo = db();
    $pdo->beginTransaction();
    $ok = 0;
    foreach ($validos as [$num, $reg]) {
        $r = investimento_salvar($usuario, $reg, null, 'planilha');
        if ($r['ok']) {
            $ok++;
        } else {
            $erros[] = "Linha $num: " . $r['erro'];
        }
    }
    if ($erros) {
        $pdo->rollBack();
        return ['ok' => false, 'erros' => $erros, 'importados' => 0];
    }
    $pdo->commit();
    return ['ok' => true, 'erros' => [], 'importados' => $ok];
}

/**
 * Investimento rateado no período. $origemIds: só estas origens (null =
 * todas). $empreendimentoId: só lançamentos daquele empreendimento.
 * Retorna ['valor' => float|null, 'lancamentos' => n]. null = nenhum
 * lançamento cobre o recorte (não é zero).
 */
function investimento_no_periodo(int $empresaId, string $ini, string $fim, ?array $origemIds = null, ?array $empreendimentoIds = null): array
{
    if (!investimentos_instalados()) {
        return ['valor' => null, 'lancamentos' => 0];
    }
    $sql = 'SELECT data_inicio, data_fim, valor FROM marketing_investimentos WHERE empresa_id = :e AND excluido_em IS NULL AND data_fim >= :i AND data_inicio <= :f';
    $p = ['e' => $empresaId, 'i' => substr($ini, 0, 10), 'f' => substr($fim, 0, 10)];
    if ($origemIds !== null) {
        $origemIds = array_values(array_filter(array_map('intval', $origemIds)));
        if (!$origemIds) {
            return ['valor' => null, 'lancamentos' => 0];
        }
        $sql .= ' AND origem_detalhe_id IN (' . implode(',', $origemIds) . ')';
    }
    if ($empreendimentoIds) {
        $sql .= ' AND empreendimento_id IN (' . implode(',', array_map('intval', $empreendimentoIds)) . ')';
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($p);
    $total = 0.0;
    $n = 0;
    $pi = new DateTimeImmutable(substr($ini, 0, 10));
    $pf = new DateTimeImmutable(substr($fim, 0, 10));
    foreach ($stmt->fetchAll() as $l) {
        $a = new DateTimeImmutable($l['data_inicio']);
        $b = new DateTimeImmutable($l['data_fim']);
        $dias = $a->diff($b)->days + 1;
        $ia = max($a, $pi);
        $ib = min($b, $pf);
        $sobre = $ia <= $ib ? $ia->diff($ib)->days + 1 : 0;
        $total += (float) $l['valor'] * $sobre / $dias;
        $n++;
    }
    return ['valor' => $n ? round($total, 2) : null, 'lancamentos' => $n];
}

/**
 * Se os custos podem ser mostrados para este usuário e filtros. Retorna
 * [bool, motivo]. O investimento é da empresa (não da equipe/corretor).
 */
function investimento_custos_permitidos(array $usuario, array $f): array
{
    if (!investimentos_instalados()) {
        return [false, 'Investimento ainda não instalado (migração 016).'];
    }
    if (!in_array($usuario['escopo'], ['toda_empresa', 'todos_produtos'], true)) {
        return [false, 'Custos só para quem vê a empresa toda: o investimento não é dividido por equipe ou corretor.'];
    }
    foreach (['superintendencia', 'gerente', 'equipe', 'corretor', 'roleta', 'plantao', 'etapa', 'forma', 'campanha', 'conjunto', 'anuncio'] as $d) {
        if (!empty($f['dim'][$d])) {
            return [false, 'Custos não são calculados com filtro de estrutura, corretor, roleta, plantão ou campanha: o investimento não é lançado nesse nível.'];
        }
    }
    return [true, ''];
}

/**
 * Custos por resultado do recorte (coorte do período): investimento ÷ leads,
 * contatos efetivos, leads com visita realizada, leads com contrato validado.
 * Considera as origens filtradas (ou todas as que têm lançamento).
 */
function relatorio_custos(array $usuario, array $f): array
{
    require_once __DIR__ . '/relatorios/especializados.php';
    $vazio = ['disponivel' => false, 'motivo' => '', 'investimento' => null, 'lancamentos' => 0, 'cpl' => null, 'custo_contato' => null, 'custo_visita' => null, 'custo_contrato' => null, 'roi' => null];
    [$ok, $motivo] = investimento_custos_permitidos($usuario, $f);
    if (!$ok || $f['vazio'] || !empty($f['erro'])) {
        return ['motivo' => $motivo ?: 'Sem base para o recorte.'] + $vazio;
    }
    $origens = !empty($f['dim']['origem']) ? $f['dim']['origem'] : null;
    if (!empty($f['dim']['canal'])) {
        $stmt = db()->prepare('SELECT id FROM lead_origens WHERE empresa_id = :e AND canal IN (' . implode(',', array_map(fn ($c) => db()->quote($c), $f['dim']['canal'])) . ')');
        $stmt->execute(['e' => $usuario['empresa_ativa_id']]);
        $doCanal = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $origens = $origens === null ? $doCanal : array_values(array_intersect($origens, $doCanal));
    }
    // Origens que de fato têm investimento no recorte (período + empreendimento + origens filtradas):
    // investimento e resultados saem EXATAMENTE das mesmas origens.
    $sql = 'SELECT DISTINCT origem_detalhe_id FROM marketing_investimentos WHERE empresa_id = :e AND excluido_em IS NULL AND data_fim >= :i AND data_inicio <= :f';
    if ($origens !== null) {
        $sql .= ' AND origem_detalhe_id IN (' . (implode(',', array_map('intval', $origens)) ?: '0') . ')';
    }
    if (!empty($f['dim']['empreendimento'])) {
        $sql .= ' AND empreendimento_id IN (' . implode(',', array_map('intval', $f['dim']['empreendimento'])) . ')';
    }
    $stmt = db()->prepare($sql);
    $stmt->execute(['e' => $usuario['empresa_ativa_id'], 'i' => substr($f['periodo']['ini'], 0, 10), 'f' => substr($f['periodo']['fim'], 0, 10)]);
    $origens = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $inv = investimento_no_periodo((int) $usuario['empresa_ativa_id'], $f['periodo']['ini'], $f['periodo']['fim'], $origens, $f['dim']['empreendimento'] ?? null);
    if ($inv['valor'] === null) {
        return ['motivo' => 'Nenhum investimento lançado para este período e recorte.'] + $vazio;
    }
    $fo = $f;
    $fo['dim']['origem'] = $origens;
    $t = ['leads' => 0, 'contato_efetivo' => 0, 'com_visita' => 0, 'com_contrato' => 0];
    foreach (relatorio_origens($usuario, $fo, 'origem') as $l) {
        foreach ($t as $k => $v) {
            $t[$k] += (int) $l[$k];
        }
    }
    $div = fn (int $n) => $n > 0 ? round($inv['valor'] / $n, 2) : null;
    return ['disponivel' => true, 'motivo' => '', 'investimento' => $inv['valor'], 'lancamentos' => $inv['lancamentos'], 'resultados' => $t,
            'cpl' => $div($t['leads']), 'custo_contato' => $div($t['contato_efetivo']), 'custo_visita' => $div($t['com_visita']), 'custo_contrato' => $div($t['com_contrato']), 'roi' => null];
}
