<?php
/**
 * Relatórios de automação (Fase 7, seção 15). Tudo agregado — sem nome nem
 * telefone — e sempre dentro do recorte de leads de quem consulta
 * (rbac_leads_scope) e dos filtros (período, empreendimento, equipe).
 *
 * Critério de "resultado após automação" (sem atribuir a venda à última
 * mensagem): o lead recebeu a PRIMEIRA mensagem automática no período e,
 * DEPOIS dela, teve visita agendada / contrato assinado. É uma medida de
 * influência, não de autoria — a tela deixa isso escrito.
 */
require_once __DIR__ . '/dashboard.php';
require_once __DIR__ . '/campanhas.php';

/** Leads (no recorte) com a 1ª mensagem automática no período, com o que veio depois. */
function relaut_leads(array $usuario, array $f): array
{
    $empresaId = (int) $usuario['empresa_ativa_id'];
    $scope = rbac_leads_scope($usuario);
    $extra = '';
    $p = $scope['params'] + ['ra_e' => $empresaId, 'ra_e2' => $empresaId, 'ra_ini' => $f['ini'], 'ra_fim' => $f['fim']];
    if ($f['empreendimento']) {
        $extra .= ' AND leads.empreendimento_id = :ra_emp';
        $p['ra_emp'] = $f['empreendimento'];
    }
    if ($f['equipe']) {
        $extra .= ' AND leads.equipe_id = :ra_eq';
        $p['ra_eq'] = $f['equipe'];
    }
    $ok = "'enviada','entregue','lida','simulada'";
    $sql = "SELECT a.lead_id, a.primeira, leads.empreendimento_id, emp.nome AS empreendimento, leads.equipe_id, eq.nome AS equipe,
                (SELECT MIN(e.criado_em) FROM whatsapp_mensagens e JOIN whatsapp_conversas c2 ON c2.id = e.conversa_id
                  WHERE c2.lead_id = a.lead_id AND e.direcao = 'entrada' AND e.tipo <> 'reacao'
                    AND e.criado_em >= a.primeira AND e.criado_em < DATE_ADD(a.primeira, INTERVAL 7 DAY)) AS resposta_em,
                EXISTS (SELECT 1 FROM agenda_compromissos ag WHERE ag.lead_id = a.lead_id AND ag.tipo = 'visita' AND ag.criado_em >= a.primeira) AS visita,
                (leads.contrato_assinado_em IS NOT NULL AND leads.contrato_assinado_em >= a.primeira) AS contrato,
                EXISTS (SELECT 1 FROM corretor_pendencias pd WHERE pd.lead_id = a.lead_id AND pd.tipo = 'responder_whatsapp' AND pd.criado_em >= a.primeira) AS humano,
                EXISTS (SELECT 1 FROM whatsapp_mensagens d JOIN whatsapp_conversas c3 ON c3.id = d.conversa_id
                         WHERE c3.lead_id = a.lead_id AND d.direcao = 'saida' AND d.tipo = 'documento' AND d.status IN ($ok)) AS book
            FROM (SELECT cv.lead_id, MIN(m.criado_em) AS primeira FROM whatsapp_mensagens m JOIN whatsapp_conversas cv ON cv.id = m.conversa_id
                  WHERE m.empresa_id = :ra_e AND m.direcao = 'saida' AND m.enviada_por IS NULL AND m.status IN ($ok) AND cv.lead_id IS NOT NULL
                  GROUP BY cv.lead_id HAVING primeira >= :ra_ini AND primeira < :ra_fim) a
            JOIN leads ON leads.id = a.lead_id
            LEFT JOIN empreendimentos emp ON emp.id = leads.empreendimento_id
            LEFT JOIN equipes eq ON eq.id = leads.equipe_id
            WHERE leads.empresa_id = :ra_e2 AND {$scope['sql']} {$extra}";
    $stmt = db()->prepare($sql);
    $stmt->execute($p);
    return $stmt->fetchAll();
}

/** Agrega uma lista de leads (de relaut_leads) em números. */
function relaut_agregar(array $leads): array
{
    $n = count($leads);
    $resp = array_values(array_filter($leads, fn ($l) => $l['resposta_em'] !== null));
    $tempos = array_map(fn ($l) => strtotime($l['resposta_em']) - strtotime($l['primeira']), $resp);
    sort($tempos);
    $mediana = null;
    if ($tempos) {
        $m = intdiv(count($tempos), 2);
        $mediana = count($tempos) % 2 ? $tempos[$m] : ($tempos[$m - 1] + $tempos[$m]) / 2;
    }
    return [
        'leads' => $n, 'responderam' => count($resp), 'taxa_resposta' => $n ? round(100 * count($resp) / $n, 1) : null,
        'tempo_resposta' => $mediana, 'humano' => count(array_filter($leads, fn ($l) => (int) $l['humano'])),
        'book' => count(array_filter($leads, fn ($l) => (int) $l['book'])),
        'visitas' => count(array_filter($leads, fn ($l) => (int) $l['visita'])),
        'contratos' => count(array_filter($leads, fn ($l) => (int) $l['contrato'])),
    ];
}

/** Todos os indicadores e quebras da tela. */
function relatorio_automacao(array $usuario, array $f): array
{
    $empresaId = (int) $usuario['empresa_ativa_id'];
    $pdo = db();
    $leads = relaut_leads($usuario, $f);
    $ind = relaut_agregar($leads);

    // Entrega (só mensagens reais: as simuladas do modo de teste ficam à parte) e custos.
    $scope = rbac_leads_scope($usuario);
    $extra = '';
    $p = $scope['params'] + ['ra_e' => $empresaId, 'ra_ini' => $f['ini'], 'ra_fim' => $f['fim']];
    if ($f['empreendimento']) {
        $extra .= ' AND leads.empreendimento_id = :ra_emp';
        $p['ra_emp'] = $f['empreendimento'];
    }
    if ($f['equipe']) {
        $extra .= ' AND leads.equipe_id = :ra_eq';
        $p['ra_eq'] = $f['equipe'];
    }
    $stmt = $pdo->prepare("SELECT m.status, (m.campanha_id IS NOT NULL) AS de_campanha, COUNT(*) AS n, SUM(COALESCE(m.custo_estimado, 0)) AS custo
                           FROM whatsapp_mensagens m JOIN whatsapp_conversas cv ON cv.id = m.conversa_id JOIN leads ON leads.id = cv.lead_id
                           WHERE m.empresa_id = :ra_e AND m.direcao = 'saida' AND m.enviada_por IS NULL AND m.criado_em >= :ra_ini AND m.criado_em < :ra_fim
                             AND {$scope['sql']} {$extra}
                           GROUP BY m.status, de_campanha");
    $stmt->execute($p);
    $st = ['enviadas' => 0, 'entregues' => 0, 'falharam' => 0, 'simuladas' => 0, 'custo_jornadas' => 0.0, 'custo_campanhas' => 0.0];
    foreach ($stmt->fetchAll() as $r) {
        $q = (int) $r['n'];
        if ($r['status'] === 'simulada') {
            $st['simuladas'] += $q;
        } elseif (in_array($r['status'], ['enviada', 'entregue', 'lida'], true)) {
            $st['enviadas'] += $q;
            if ($r['status'] !== 'enviada') {
                $st['entregues'] += $q;
            }
        } elseif ($r['status'] === 'falhou') {
            $st['falharam'] += $q;
        }
        $st[(int) $r['de_campanha'] ? 'custo_campanhas' : 'custo_jornadas'] += (float) $r['custo'];
    }
    $tentadas = $st['enviadas'] + $st['falharam'];
    $ind['taxa_entrega'] = $tentadas ? round(100 * $st['entregues'] / $tentadas, 1) : null;
    $ind += $st;
    $ind['custo_total'] = round($st['custo_jornadas'] + $st['custo_campanhas'], 2);
    $ind['custo_por_resposta'] = $ind['responderam'] && $ind['custo_total'] > 0 ? round($ind['custo_total'] / $ind['responderam'], 2) : null;

    // Recuperação e descadastros (só números, empresa inteira dentro do filtro de empreendimento).
    $pt = ['e' => $empresaId, 'i' => $f['ini'], 'f' => $f['fim']];
    $empSql = '';
    if ($f['empreendimento']) {
        $empSql = ' AND empreendimento_id = :emp';
        $pt['emp'] = $f['empreendimento'];
    }
    $stmt = $pdo->prepare("SELECT COUNT(*), SUM(para_usuario_id IS NOT NULL) FROM lead_transferencias WHERE empresa_id = :e AND motivo = 'inatividade' AND criado_em >= :i AND criado_em < :f" . $empSql);
    $stmt->execute($pt);
    [$ind['recuperados'], $ind['redistribuidos']] = array_map('intval', $stmt->fetch(PDO::FETCH_NUM));
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM whatsapp_contatos WHERE empresa_id = :e AND opt_out_em >= :i AND opt_out_em < :f');
    $stmt->execute(['e' => $empresaId, 'i' => $f['ini'], 'f' => $f['fim']]);
    $ind['descadastros'] = (int) $stmt->fetchColumn();

    // Quebras.
    $agrupar = function (string $chave, string $rotulo) use ($leads): array {
        $g = [];
        foreach ($leads as $l) {
            $g[(string) ($l[$chave] ?? '')][] = $l;
        }
        $linhas = [];
        foreach ($g as $k => $ls) {
            $linhas[] = ['nome' => $ls[0][$rotulo] ?: 'Sem ' . ($chave === 'equipe_id' ? 'equipe' : 'empreendimento')] + relaut_agregar($ls);
        }
        usort($linhas, fn ($a, $b) => $b['leads'] <=> $a['leads']);
        return $linhas;
    };
    $porJornada = [];
    $stmt = $pdo->prepare('SELECT id, nome FROM jornadas WHERE empresa_id = :e ORDER BY id');
    $stmt->execute(['e' => $empresaId]);
    foreach ($stmt->fetchAll() as $j) {
        $q = $pdo->prepare("SELECT COUNT(*) AS entraram, SUM(x.mensagens_enviadas > 0) AS com_mensagem, SUM(x.status = 'concluida') AS concluidas,
                    SUM(x.motivo IN ('Cliente respondeu pelo WhatsApp','Corretor confirmou que o cliente respondeu')) AS pararam_resposta,
                    (SELECT COALESCE(SUM(m.custo_estimado), 0) FROM whatsapp_mensagens m WHERE m.jornada_execucao_id IN
                        (SELECT id FROM jornada_execucoes WHERE jornada_id = :j2 AND iniciada_em >= :i2 AND iniciada_em < :f2)) AS custo
                FROM jornada_execucoes x JOIN leads ON leads.id = x.lead_id
                WHERE x.jornada_id = :j AND x.iniciada_em >= :i AND x.iniciada_em < :f AND {$scope['sql']}");
        $q->execute($scope['params'] + ['j' => $j['id'], 'j2' => $j['id'], 'i' => $f['ini'], 'f' => $f['fim'], 'i2' => $f['ini'], 'f2' => $f['fim']]);
        $r = $q->fetch();
        if ((int) $r['entraram']) {
            $porJornada[] = ['nome' => $j['nome']] + array_map(fn ($v) => $v === null ? 0 : $v, $r);
        }
    }
    $porCampanha = [];
    $stmt = $pdo->prepare('SELECT id, nome, status, criado_em FROM whatsapp_campanhas WHERE empresa_id = :e AND criado_em >= :i AND criado_em < :f ORDER BY id DESC');
    $stmt->execute(['e' => $empresaId, 'i' => $f['ini'], 'f' => $f['fim']]);
    foreach ($stmt->fetchAll() as $c) {
        $porCampanha[] = ['nome' => $c['nome'], 'id' => (int) $c['id'], 'status' => $c['status']] + campanha_resultados($empresaId, (int) $c['id']);
    }
    return ['indicadores' => $ind, 'por_empreendimento' => $agrupar('empreendimento_id', 'empreendimento'),
            'por_equipe' => $agrupar('equipe_id', 'equipe'), 'por_jornada' => $porJornada, 'por_campanha' => $porCampanha];
}

/** Colunas para tela e Excel. */
function relaut_colunas(string $tabela): array
{
    $grupo = ['nome' => ['Nome', 'texto'], 'leads' => ['Leads com automação', 'int'], 'responderam' => ['Responderam', 'int'], 'taxa_resposta' => ['Taxa de resposta (%)', 'pct'],
              'tempo_resposta' => ['Tempo até a 1ª resposta (mediana)', 'tempo'], 'book' => ['Receberam o book', 'int'], 'humano' => ['Encaminhados ao corretor', 'int'],
              'visitas' => ['Visita agendada depois', 'int'], 'contratos' => ['Contrato depois', 'int']];
    return [
        'empreendimento' => $grupo, 'equipe' => $grupo,
        'jornada' => ['nome' => ['Jornada', 'texto'], 'entraram' => ['Leads que entraram', 'int'], 'com_mensagem' => ['Receberam mensagem', 'int'],
                      'pararam_resposta' => ['Pararam por resposta', 'int'], 'concluidas' => ['Concluídas', 'int'], 'custo' => ['Custo estimado (R$)', 'num']],
        'campanha' => ['nome' => ['Campanha', 'texto'], 'destinatarios' => ['Contatos', 'int'], 'enviadas' => ['Enviadas', 'int'], 'simuladas' => ['Simuladas (teste)', 'int'],
                       'entregues' => ['Entregues', 'int'], 'lidas' => ['Lidas', 'int'], 'respostas' => ['Responderam', 'int'], 'nao_enviadas' => ['Não enviadas (regras)', 'int'],
                       'falharam' => ['Falharam', 'int'], 'descadastros' => ['Pediram para sair', 'int'], 'custo' => ['Custo (R$)', 'num'], 'custo_por_resposta' => ['Custo por resposta (R$)', 'num']],
    ][$tabela] ?? [];
}

/** Indicadores como linhas (para o Excel). */
function relaut_indicadores_linhas(array $ind): array
{
    $pct = fn ($v) => $v === null ? '—' : number_format((float) $v, 1, ',', '') . '%';
    $rs = fn ($v) => $v === null ? '—' : 'R$ ' . number_format((float) $v, 2, ',', '.');
    return [
        ['Leads que receberam a primeira mensagem automática', (string) $ind['leads']],
        ['Taxa de entrega (mensagens reais)', $pct($ind['taxa_entrega'])],
        ['Mensagens simuladas (modo de teste)', (string) $ind['simuladas']],
        ['Taxa de resposta', $pct($ind['taxa_resposta'])],
        ['Tempo até a primeira resposta (mediana)', $ind['tempo_resposta'] === null ? '—' : dash_duracao((float) $ind['tempo_resposta'])],
        ['Receberam o book', (string) $ind['book']],
        ['Encaminhados para atendimento humano', (string) $ind['humano']],
        ['Leads recuperados (inatividade)', (string) $ind['recuperados']],
        ['Leads redistribuídos', (string) $ind['redistribuidos']],
        ['Visitas agendadas depois da automação', (string) $ind['visitas']],
        ['Contratos assinados depois da automação', (string) $ind['contratos']],
        ['Custo estimado — jornadas', $rs($ind['custo_jornadas'])],
        ['Custo estimado — campanhas', $rs($ind['custo_campanhas'])],
        ['Custo por resposta', $rs($ind['custo_por_resposta'])],
        ['Pedidos de descadastro', (string) $ind['descadastros']],
    ];
}
