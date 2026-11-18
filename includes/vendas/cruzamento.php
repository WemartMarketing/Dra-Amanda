<?php
/**
 * Inteligência de Vendas — Fase 3: motor de cruzamento venda × CRM.
 *
 * 1. Índice de contatos (contato_identificadores) reconstruído a partir dos
 *    leads da empresa (excluídos ficam de fora) e do telefone do contato de
 *    WhatsApp ligado ao lead.
 * 2. Para cada venda: candidatos por telefone/e-mail EXATOS (e a variante do
 *    9º dígito, só como candidato), regras v1 (correspondencia.php),
 *    cronologia contra a 1ª entrada comprovada no CRM.
 * 3. Persistência idempotente: rodar de novo não duplica nada; o log só
 *    ganha linha quando o status ou o conjunto de leads muda.
 * 4. Decisão humana (confirmada/rejeitada) NUNCA é sobrescrita pelo
 *    automático; se o automático passar a discordar, vira alerta.
 *
 * Não altera leads, responsáveis, Kanban nem automações.
 */
require_once __DIR__ . '/importacao.php';
require_once __DIR__ . '/correspondencia.php';
require_once __DIR__ . '/../rbac.php';

function vendas_cruzamento_instalado(): bool
{
    return vendas_instalado() && modulo_tabela_existe('vendas_correspondencias') && modulo_tabela_existe('contato_identificadores');
}

function vendas_situacoes(): array
{
    return [
        'forte' => ['Forte', 'pill-green', 'Telefone e e-mail no mesmo cadastro'],
        'telefone' => ['Por telefone', 'pill-green', 'Só o telefone coincide'],
        'email' => ['Por e-mail', 'pill-green', 'Só o e-mail coincide'],
        'ambigua' => ['Ambígua', 'pill-amber', 'Precisa de revisão humana'],
        'sem' => ['Sem correspondência', 'pill-muted', 'Não encontrada no CRM'],
        'posterior' => ['Posterior à venda', 'pill-blue', 'Entrou no CRM depois da venda'],
        'confirmada' => ['Confirmada na revisão', 'pill-green', 'Vínculo confirmado por uma pessoa'],
        'rejeitada' => ['Sem vínculo (revisão)', 'pill-muted', 'Candidatos descartados por uma pessoa'],
    ];
}

/** Situação mostrada: a decisão humana prevalece sobre o status automático. */
function vendas_situacao_efetiva(?array $corr): string
{
    if (!$corr) {
        return 'pendente';
    }
    return $corr['decisao'] === 'automatica' ? $corr['status'] : $corr['decisao'];
}

// ------------------------------------------------------------------ índice

/** Reconstrói o índice de contatos da empresa. Retorna quantos identificadores. */
function vendas_indice_reconstruir(int $empresaId): int
{
    $pdo = db();
    $pdo->prepare('DELETE FROM contato_identificadores WHERE empresa_id = :e')->execute(['e' => $empresaId]);
    $buf = [];
    $total = 0;
    $flush = function () use (&$buf, &$total, $pdo, $empresaId) {
        if (!$buf) {
            return;
        }
        $ph = implode(',', array_fill(0, count($buf), '(?, ?, ?, ?, ?)'));
        $vals = [];
        foreach ($buf as [$lead, $tipo, $valor, $fonte]) {
            array_push($vals, $empresaId, $lead, $tipo, $valor, $fonte);
        }
        $st = $pdo->prepare("INSERT IGNORE INTO contato_identificadores (empresa_id, lead_id, tipo, valor, fonte) VALUES $ph");
        $st->execute($vals);
        $total += $st->rowCount();
        $buf = [];
    };
    $st = $pdo->prepare('SELECT id, telefone, email FROM leads WHERE empresa_id = :e AND excluido_em IS NULL');
    $st->execute(['e' => $empresaId]);
    while ($l = $st->fetch()) {
        $t = vendas_telefone((string) $l['telefone']);
        if ($t['e164']) {
            $buf[] = [(int) $l['id'], 'telefone', $t['e164'], 'lead'];
        } elseif ($v = vendas_telefone_variante((string) $l['telefone'])) {
            $buf[] = [(int) $l['id'], 'telefone_variante', $v, 'lead'];
        }
        $em = vendas_email((string) $l['email']);
        if ($em['email']) {
            $buf[] = [(int) $l['id'], 'email', mb_substr($em['email'], 0, 190), 'lead'];
        }
        if (count($buf) >= 400) {
            $flush();
        }
    }
    if (modulo_tabela_existe('whatsapp_contatos')) {
        $st = $pdo->prepare('SELECT c.telefone, c.lead_id FROM whatsapp_contatos c JOIN leads l ON l.id = c.lead_id AND l.excluido_em IS NULL AND l.empresa_id = c.empresa_id
                             WHERE c.empresa_id = :e AND c.lead_id IS NOT NULL');
        $st->execute(['e' => $empresaId]);
        while ($c = $st->fetch()) {
            $t = vendas_telefone('+' . preg_replace('/\D/', '', (string) $c['telefone']));
            if ($t['e164']) {
                $buf[] = [(int) $c['lead_id'], 'telefone', $t['e164'], 'whatsapp'];
            }
            if (count($buf) >= 400) {
                $flush();
            }
        }
    }
    $flush();
    return $total;
}

/** Candidatos de uma venda gravada, no formato de vendas_classificar(). */
function vendas_candidatos_venda(int $empresaId, array $venda): array
{
    $tels = array_values(array_filter([$venda['telefone1'], $venda['telefone2']]));
    $buscar = function (string $tipo, array $valores) use ($empresaId): array {
        if (!$valores) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($valores), '?'));
        $st = db()->prepare("SELECT DISTINCT lead_id FROM contato_identificadores WHERE empresa_id = ? AND tipo = ? AND valor IN ($ph)");
        $st->execute(array_merge([$empresaId, $tipo], $valores));
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    };
    $idsT = $buscar('telefone', $tels);
    $idsE = $venda['email'] ? $buscar('email', [$venda['email']]) : [];
    $idsV = array_diff($buscar('telefone_variante', $tels), $idsT, $idsE);
    $todos = array_values(array_unique(array_merge($idsT, $idsE, $idsV)));
    $info = [];
    if ($todos) {
        $ph = implode(',', array_fill(0, count($todos), '?'));
        $st = db()->prepare("SELECT id, telefone, email, criado_em, meta_cadastro_em FROM leads WHERE empresa_id = ? AND excluido_em IS NULL AND id IN ($ph)");
        $st->execute(array_merge([$empresaId], $todos));
        foreach ($st->fetchAll() as $l) {
            $datas = array_filter([$l['criado_em'], $l['meta_cadastro_em']]);
            $info[(int) $l['id']] = ['tel' => vendas_telefone((string) $l['telefone'])['e164'], 'email' => vendas_email((string) $l['email'])['email'],
                                     'primeira_data' => $datas ? min($datas) : null];
        }
    }
    $pega = fn (array $ids) => array_intersect_key($info, array_flip($ids));
    return [$pega($idsT), $pega($idsE), $pega(array_values($idsV))];
}

function vendas_correspondencia(int $vendaId): ?array
{
    $st = db()->prepare('SELECT * FROM vendas_correspondencias WHERE venda_id = :v');
    $st->execute(['v' => $vendaId]);
    return $st->fetch() ?: null;
}

function vendas_correspondencia_leads(int $vendaId): array
{
    $st = db()->prepare('SELECT * FROM vendas_correspondencia_leads WHERE venda_id = :v ORDER BY lead_id');
    $st->execute(['v' => $vendaId]);
    return $st->fetchAll();
}

/** Cruza UMA venda (linha de vendas_externas) e persiste. Retorna a situação efetiva. */
function vendas_cruzar_venda(int $empresaId, array $venda): string
{
    $pdo = db();
    $vid = (int) $venda['id'];
    [$pt, $pe, $pv] = vendas_candidatos_venda($empresaId, $venda);
    $r = vendas_classificar($pt, $pe, $venda['data_venda'], $venda['email'], $pv);
    $ant = vendas_correspondencia($vid);
    $antLeads = array_map(fn ($l) => (int) $l['lead_id'], vendas_correspondencia_leads($vid));
    $manual = $ant && $ant['decisao'] !== 'automatica';

    $alertas = $r['alertas'];
    if ($manual) {
        $concordaria = $ant['decisao'] === 'confirmada'
            ? in_array($r['status'], ['forte', 'telefone', 'email', 'posterior'], true) && !array_diff($r['lead_ids'], $antLeads)
            : $r['status'] === 'sem';
        if (!$concordaria) {
            $alertas[] = 'O cruzamento automático atual (' . (vendas_situacoes()[$r['status']][0] ?? $r['status']) . ') difere da revisão humana: confira.';
        }
    }
    $pdo->beginTransaction();
    try {
        $dados = ['e' => $empresaId, 'v' => $vid, 's' => $r['status'], 'm' => mb_substr($r['motivo'], 0, 300), 'c' => $r['cronologia'],
                  'p' => $manual ? ($ant['primeira_entrada_em'] ?? null) : $r['primeira_data'], 'a' => $alertas ? mb_substr(implode(' | ', $alertas), 0, 600) : null,
                  'rv' => VENDAS_REGRAS_VERSAO, 'vin' => $manual ? (int) $ant['vinculada'] : (in_array($r['status'], ['forte', 'telefone', 'email', 'posterior'], true) ? 1 : 0)];
        if ($manual) {
            $dados['c'] = $ant['cronologia'];
        }
        $pdo->prepare('INSERT INTO vendas_correspondencias (empresa_id, venda_id, status, motivo, cronologia, primeira_entrada_em, alertas, regras_versao, vinculada, calculado_em)
                       VALUES (:e, :v, :s, :m, :c, :p, :a, :rv, :vin, NOW())
                       ON DUPLICATE KEY UPDATE status = VALUES(status), motivo = VALUES(motivo), cronologia = VALUES(cronologia), primeira_entrada_em = VALUES(primeira_entrada_em),
                       alertas = VALUES(alertas), regras_versao = VALUES(regras_versao), vinculada = VALUES(vinculada), calculado_em = NOW()')
            ->execute($dados);
        $novosLeads = $antLeads;
        if (!$manual) {
            $pdo->prepare('DELETE FROM vendas_correspondencia_leads WHERE venda_id = :v')->execute(['v' => $vid]);
            $ins = $pdo->prepare('INSERT INTO vendas_correspondencia_leads (venda_id, lead_id, evidencia, papel) VALUES (:v, :l, :ev, :p)');
            $papel = $dados['vin'] ? 'vinculado' : 'candidato';
            foreach ($r['lead_ids'] as $lid) {
                $ev = isset($pt[$lid]) && isset($pe[$lid]) ? 'telefone_email' : (isset($pt[$lid]) ? 'telefone' : (isset($pe[$lid]) ? 'email' : 'variante'));
                $ins->execute(['v' => $vid, 'l' => $lid, 'ev' => $ev, 'p' => $papel]);
            }
            $novosLeads = $r['lead_ids'];
        }
        sort($antLeads);
        sort($novosLeads);
        if (!$ant || $ant['status'] !== $r['status'] || $antLeads !== $novosLeads) {
            $pdo->prepare("INSERT INTO vendas_conciliacao_log (venda_id, origem, status_anterior, status_novo, leads_anteriores, leads_novos, regras_versao)
                           VALUES (:v, 'cruzamento', :sa, :sn, :la, :ln, :rv)")
                ->execute(['v' => $vid, 'sa' => $ant['status'] ?? null, 'sn' => $r['status'], 'la' => $antLeads ? implode(',', $antLeads) : null,
                           'ln' => $novosLeads ? implode(',', $novosLeads) : null, 'rv' => VENDAS_REGRAS_VERSAO]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $manual ? $ant['decisao'] : $r['status'];
}

// ------------------------------------------------------------------ equivalências

/** Aplica as equivalências de empreendimento confirmadas às vendas da empresa. Retorna quantas vendas mudaram. */
function vendas_aplicar_equivalencias(int $empresaId): int
{
    $pdo = db();
    $mapa = [];
    $st = $pdo->prepare("SELECT valor_origem, classificacao, empreendimento_id FROM vendas_equivalencias WHERE empresa_id = :e AND tipo = 'empreendimento'");
    $st->execute(['e' => $empresaId]);
    foreach ($st->fetchAll() as $q) {
        $mapa[$q['valor_origem']] = $q['classificacao'] === 'crm' ? (int) $q['empreendimento_id'] : null;
    }
    $n = 0;
    $st = $pdo->prepare('SELECT DISTINCT empreendimento_texto FROM vendas_externas WHERE empresa_id = :e AND empreendimento_texto IS NOT NULL');
    $st->execute(['e' => $empresaId]);
    $upd = $pdo->prepare('UPDATE vendas_externas SET empreendimento_id = :emp WHERE empresa_id = :e AND empreendimento_texto = :t AND NOT (empreendimento_id <=> :emp2)');
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $texto) {
        $alvo = $mapa[vendas_nome_comparavel($texto)] ?? null;
        $upd->execute(['emp' => $alvo, 'emp2' => $alvo, 'e' => $empresaId, 't' => $texto]);
        $n += $upd->rowCount();
    }
    return $n;
}

/** Sugestão (nunca aplicada sozinha) de empreendimento do CRM com o mesmo nome comparável. */
function vendas_sugestao_empreendimento(int $empresaId, string $texto): ?int
{
    static $cache = [];
    if (!isset($cache[$empresaId])) {
        $cache[$empresaId] = [];
        $st = db()->prepare('SELECT id, nome FROM empreendimentos WHERE empresa_id = :e');
        $st->execute(['e' => $empresaId]);
        foreach ($st->fetchAll() as $e) {
            $cache[$empresaId][vendas_nome_comparavel($e['nome'])][] = (int) $e['id'];
        }
    }
    $ids = $cache[$empresaId][vendas_nome_comparavel($texto)] ?? [];
    return count($ids) === 1 ? $ids[0] : null;
}

/** Sugestão de usuário do CRM para um corretor da planilha: nome idêntico (sem acento/caixa) depois do prefixo "XXX_". Só sugestão. */
function vendas_sugestao_corretor(int $empresaId, string $texto): ?int
{
    static $cache = [];
    if (!isset($cache[$empresaId])) {
        $cache[$empresaId] = [];
        $st = db()->prepare('SELECT id, nome FROM usuarios WHERE empresa_id = :e');
        $st->execute(['e' => $empresaId]);
        foreach ($st->fetchAll() as $u) {
            $cache[$empresaId][vendas_nome_comparavel($u['nome'])][] = (int) $u['id'];
        }
    }
    $nome = preg_replace('/^[^_]+_/', '', $texto);
    $ids = $cache[$empresaId][vendas_nome_comparavel($nome)] ?? [];
    return count($ids) === 1 ? $ids[0] : null;
}

/** Salva uma equivalência confirmada por uma pessoa. $dados: classificacao, empreendimento_id|usuario_id|imobiliaria_id. */
function vendas_equivalencia_salvar(array $usuario, string $tipo, string $valorTexto, array $dados): void
{
    $empresaId = (int) $usuario['empresa_ativa_id'];
    if (!in_array($tipo, ['empreendimento', 'corretor'], true)) {
        throw new InvalidArgumentException('Tipo inválido.');
    }
    $classe = (string) ($dados['classificacao'] ?? '');
    $validas = $tipo === 'empreendimento' ? ['crm', 'nao_existe'] : ['crm', 'externo', 'nao_identificado'];
    if (!in_array($classe, $validas, true)) {
        throw new InvalidArgumentException('Classificação inválida.');
    }
    $emp = $usr = $imob = null;
    $confere = function (string $tabela, int $id) use ($empresaId): bool {
        $st = db()->prepare("SELECT 1 FROM $tabela WHERE id = :id AND empresa_id = :e");
        $st->execute(['id' => $id, 'e' => $empresaId]);
        return (bool) $st->fetchColumn();
    };
    if ($tipo === 'empreendimento' && $classe === 'crm') {
        $emp = (int) ($dados['empreendimento_id'] ?? 0);
        if (!$emp || !$confere('empreendimentos', $emp)) {
            throw new InvalidArgumentException('Escolha o empreendimento do CRM.');
        }
    }
    if ($tipo === 'corretor' && $classe === 'crm') {
        $usr = (int) ($dados['usuario_id'] ?? 0);
        if (!$usr || !$confere('usuarios', $usr)) {
            throw new InvalidArgumentException('Escolha o usuário do CRM.');
        }
    }
    if ($tipo === 'corretor' && $classe === 'externo' && !empty($dados['imobiliaria_id'])) {
        $imob = (int) $dados['imobiliaria_id'];
        if (!$confere('imobiliarias_parceiras', $imob)) {
            throw new InvalidArgumentException('Imobiliária não encontrada.');
        }
    }
    $valorTexto = vendas_texto($valorTexto);
    $chave = $tipo === 'empreendimento' ? vendas_nome_comparavel($valorTexto) : mb_substr((string) ($dados['valor_origem'] ?? ''), 0, 190);
    if ($chave === '') {
        throw new InvalidArgumentException('Valor de origem vazio.');
    }
    db()->prepare('INSERT INTO vendas_equivalencias (empresa_id, sistema_origem, tipo, valor_origem, valor_texto, classificacao, empreendimento_id, usuario_id, imobiliaria_id, confirmado_por, confirmado_em)
                   VALUES (:e, :s, :t, :vo, :vt, :c, :emp, :u, :i, :por, NOW())
                   ON DUPLICATE KEY UPDATE valor_texto = VALUES(valor_texto), classificacao = VALUES(classificacao), empreendimento_id = VALUES(empreendimento_id),
                   usuario_id = VALUES(usuario_id), imobiliaria_id = VALUES(imobiliaria_id), confirmado_por = VALUES(confirmado_por), confirmado_em = NOW()')
        ->execute(['e' => $empresaId, 's' => VENDAS_SISTEMA_HM, 't' => $tipo, 'vo' => $chave, 'vt' => $valorTexto, 'c' => $classe, 'emp' => $emp, 'u' => $usr, 'i' => $imob, 'por' => (int) $usuario['id']]);
    auditoria_registrar($empresaId, (int) $usuario['id'], 'vendas_equivalencia', 'vendas_equivalencia', null, ['tipo' => $tipo, 'classificacao' => $classe]);
    if ($tipo === 'empreendimento') {
        vendas_aplicar_equivalencias($empresaId);
    }
}

// ------------------------------------------------------------------ execução

/**
 * Cruza as vendas da empresa (todas, ou só as do lote). Idempotente.
 * Retorna ['vendas' => n, 'identificadores' => n, 'por_situacao' => [...]].
 */
function vendas_cruzar(int $empresaId, ?int $loteId = null): array
{
    if (!vendas_cruzamento_instalado()) {
        throw new RuntimeException('Rode a migração 018 antes do cruzamento.');
    }
    $pdo = db();
    $trava = 'wemart_vendas_cruz_' . $empresaId;
    if ((int) $pdo->query('SELECT GET_LOCK(' . $pdo->quote($trava) . ', 30)')->fetchColumn() !== 1) {
        throw new RuntimeException('Outro cruzamento desta empresa está em andamento. Tente em instantes.');
    }
    try {
        $ids = vendas_indice_reconstruir($empresaId);
        vendas_aplicar_equivalencias($empresaId);
        $sql = 'SELECT * FROM vendas_externas WHERE empresa_id = :e' . ($loteId ? ' AND ultimo_lote_id = :l' : '') . ' ORDER BY id';
        $st = $pdo->prepare($sql);
        $st->execute($loteId ? ['e' => $empresaId, 'l' => $loteId] : ['e' => $empresaId]);
        $por = [];
        $n = 0;
        foreach ($st->fetchAll() as $v) {
            $s = vendas_cruzar_venda($empresaId, $v);
            $por[$s] = ($por[$s] ?? 0) + 1;
            $n++;
        }
    } finally {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($trava) . ')');
    }
    return ['vendas' => $n, 'identificadores' => $ids, 'por_situacao' => $por];
}

// ------------------------------------------------------------------ revisão humana

/**
 * Decisão humana sobre uma venda.
 *   confirmar: $leadIds (entre os candidatos/vinculados/descartados da venda) passam a "vinculado"; os demais, "descartado".
 *   rejeitar:  nenhum lead é o comprador.
 *   reabrir:   volta ao resultado automático (recalcula).
 */
function vendas_revisar(array $usuario, int $vendaId, string $decisao, array $leadIds, string $justificativa): string
{
    $empresaId = (int) $usuario['empresa_ativa_id'];
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM vendas_externas WHERE id = :id AND empresa_id = :e');
    $st->execute(['id' => $vendaId, 'e' => $empresaId]);
    $venda = $st->fetch();
    if (!$venda) {
        throw new InvalidArgumentException('Venda não encontrada.');
    }
    $justificativa = trim($justificativa);
    if (mb_strlen($justificativa) < 5) {
        throw new InvalidArgumentException('Explique a decisão (ao menos algumas palavras): ela fica no histórico.');
    }
    $corr = vendas_correspondencia($vendaId);
    if (!$corr) {
        throw new InvalidArgumentException('Esta venda ainda não foi cruzada. Rode o cruzamento primeiro.');
    }
    $ligados = array_map(fn ($l) => (int) $l['lead_id'], vendas_correspondencia_leads($vendaId));
    $leadIds = array_values(array_unique(array_map('intval', $leadIds)));
    if ($decisao === 'confirmar') {
        if (!$leadIds || array_diff($leadIds, $ligados)) {
            throw new InvalidArgumentException('Escolha um ou mais leads entre os candidatos desta venda.');
        }
        // Os leads precisam continuar ativos e o revisor precisa enxergá-los (escopo atual).
        $sc = rbac_leads_scope($usuario);
        $params = $sc['params'];
        $marcas = [];
        foreach ($leadIds as $i => $lid) {
            $marcas[] = ':vl' . $i;
            $params['vl' . $i] = $lid;
        }
        $q = $pdo->prepare('SELECT leads.id, leads.criado_em, leads.meta_cadastro_em FROM leads WHERE ' . $sc['sql'] . ' AND leads.id IN (' . implode(',', $marcas) . ')');
        $q->execute($params);
        $visiveis = $q->fetchAll();
        if (count($visiveis) !== count($leadIds)) {
            throw new InvalidArgumentException('Algum lead escolhido não está mais ativo ou está fora da sua carteira.');
        }
        $datas = [];
        foreach ($visiveis as $l) {
            $datas = array_merge($datas, array_filter([$l['criado_em'], $l['meta_cadastro_em']]));
        }
        $primeira = $datas ? min($datas) : null;
        $crono = !$primeira || !$venda['data_venda'] ? 'desconhecida'
            : (substr($primeira, 0, 10) < substr($venda['data_venda'], 0, 10) ? 'anterior' : (substr($primeira, 0, 10) === substr($venda['data_venda'], 0, 10) ? 'mesmo_dia' : 'posterior'));
    } elseif (!in_array($decisao, ['rejeitar', 'reabrir'], true)) {
        throw new InvalidArgumentException('Decisão inválida.');
    }
    $novaDecisao = ['confirmar' => 'confirmada', 'rejeitar' => 'rejeitada', 'reabrir' => 'automatica'][$decisao];
    $pdo->beginTransaction();
    try {
        if ($decisao === 'confirmar') {
            $pdo->prepare("UPDATE vendas_correspondencia_leads SET papel = 'descartado' WHERE venda_id = :v")->execute(['v' => $vendaId]);
            $ph = implode(',', array_fill(0, count($leadIds), '?'));
            $pdo->prepare("UPDATE vendas_correspondencia_leads SET papel = 'vinculado' WHERE venda_id = ? AND lead_id IN ($ph)")->execute(array_merge([$vendaId], $leadIds));
            $pdo->prepare("UPDATE vendas_correspondencias SET decisao = 'confirmada', vinculada = 1, primeira_entrada_em = :p, cronologia = :c, revisado_por = :u, revisado_em = NOW() WHERE venda_id = :v")
                ->execute(['p' => $primeira, 'c' => $crono, 'u' => (int) $usuario['id'], 'v' => $vendaId]);
        } elseif ($decisao === 'rejeitar') {
            $pdo->prepare("UPDATE vendas_correspondencia_leads SET papel = 'descartado' WHERE venda_id = :v")->execute(['v' => $vendaId]);
            $pdo->prepare("UPDATE vendas_correspondencias SET decisao = 'rejeitada', vinculada = 0, primeira_entrada_em = NULL, cronologia = NULL, revisado_por = :u, revisado_em = NOW() WHERE venda_id = :v")
                ->execute(['u' => (int) $usuario['id'], 'v' => $vendaId]);
        } else {
            $pdo->prepare("UPDATE vendas_correspondencias SET decisao = 'automatica', revisado_por = :u, revisado_em = NOW() WHERE venda_id = :v")
                ->execute(['u' => (int) $usuario['id'], 'v' => $vendaId]);
        }
        $pdo->prepare('INSERT INTO vendas_revisoes (empresa_id, venda_id, usuario_id, decisao, lead_ids, justificativa) VALUES (:e, :v, :u, :d, :l, :j)')
            ->execute(['e' => $empresaId, 'v' => $vendaId, 'u' => (int) $usuario['id'], 'd' => $decisao, 'l' => $leadIds ? implode(',', $leadIds) : null, 'j' => mb_substr($justificativa, 0, 500)]);
        $pdo->prepare("INSERT INTO vendas_conciliacao_log (venda_id, origem, status_anterior, status_novo, leads_anteriores, leads_novos, regras_versao, usuario_id)
                       VALUES (:v, 'revisao', :sa, :sn, :la, :ln, :rv, :u)")
            ->execute(['v' => $vendaId, 'sa' => vendas_situacao_efetiva($corr), 'sn' => $novaDecisao === 'automatica' ? 'reaberta' : $novaDecisao,
                       'la' => $ligados ? implode(',', $ligados) : null, 'ln' => $leadIds ? implode(',', $leadIds) : null, 'rv' => VENDAS_REGRAS_VERSAO, 'u' => (int) $usuario['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    auditoria_registrar($empresaId, (int) $usuario['id'], 'vendas_revisar', 'venda_externa', $vendaId, ['decisao' => $decisao, 'leads' => count($leadIds)]);
    if ($decisao === 'reabrir') {
        vendas_indice_reconstruir($empresaId);
        $st = $pdo->prepare('SELECT * FROM vendas_externas WHERE id = :id');
        $st->execute(['id' => $vendaId]);
        return vendas_cruzar_venda($empresaId, $st->fetch());
    }
    return $novaDecisao;
}

// ------------------------------------------------------------------ máscaras (telas)

/** (11) 9••••-4321 — só o suficiente para comparar. */
function vendas_mascarar_telefone(?string $e164): string
{
    if (!$e164 || strlen($e164) < 12) {
        return '—';
    }
    $d = substr($e164, 2);
    return '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 1) . '••••-' . substr($d, -4);
}

/** c•••@gmail.com */
function vendas_mascarar_email(?string $email): string
{
    if (!$email || !str_contains($email, '@')) {
        return '—';
    }
    [$u, $dom] = explode('@', $email, 2);
    return mb_substr($u, 0, 1) . '•••@' . $dom;
}
