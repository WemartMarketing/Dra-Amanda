<?php
/**
 * Auditoria dos dados para a Central de Relatórios (Etapa 1).
 *
 * Mede, no banco REAL, quanto histórico existe e onde há buracos — antes de
 * construir gráficos. Só leitura; só contagens (nenhum nome, telefone ou
 * e-mail sai daqui). Usada pelo terminal (ferramentas/auditoria_relatorios.php)
 * e, na Etapa 6, pelo Painel de Qualidade dos Dados.
 *
 * Cada item: ['rotulo', 'valor', 'base' (opcional), 'nivel' (ok|info|alerta|critico), 'nota'].
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../whatsapp_regras.php';

function relaud_item(string $rotulo, $valor, string $nivel = 'info', string $nota = '', $base = null): array
{
    return ['rotulo' => $rotulo, 'valor' => $valor, 'base' => $base, 'nivel' => $nivel, 'nota' => $nota];
}

/** Percentual "x de y" para notas. */
function relaud_pct(int $x, int $y): string
{
    return $y ? number_format(100 * $x / $y, 1, ',', '.') . '%' : '—';
}

/**
 * Auditoria de uma empresa. Devolve [secao => [itens]].
 */
function relatorio_auditoria_dados(int $empresaId): array
{
    $pdo = db();
    $um = function (string $sql, array $p = []) use ($pdo, $empresaId) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($p + (str_contains($sql, ':e') ? ['e' => $empresaId] : []));
        return $stmt->fetchColumn();
    };
    $tem = fn (string $t) => modulo_tabela_existe($t);
    $s = [];

    // ---------------------------------------------------------------- Leads
    $total = (int) $um('SELECT COUNT(*) FROM leads WHERE empresa_id = :e AND excluido_em IS NULL');
    $periodo = $pdo->prepare('SELECT MIN(criado_em), MAX(criado_em) FROM leads WHERE empresa_id = :e AND excluido_em IS NULL');
    $periodo->execute(['e' => $empresaId]);
    [$min, $max] = $periodo->fetch(PDO::FETCH_NUM);
    $s['Leads'][] = relaud_item('Leads ativos', $total, 'info', $min ? 'De ' . date('d/m/Y', strtotime($min)) . ' a ' . date('d/m/Y', strtotime($max)) . '.' : 'Nenhum lead.');
    $s['Leads'][] = relaud_item('Leads excluídos (fora dos relatórios)', (int) $um('SELECT COUNT(*) FROM leads WHERE empresa_id = :e AND excluido_em IS NOT NULL'));
    $stmt = $pdo->prepare('SELECT origem, COUNT(*) FROM leads WHERE empresa_id = :e AND excluido_em IS NULL GROUP BY origem');
    $stmt->execute(['e' => $empresaId]);
    foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $o => $n) {
        $s['Leads'][] = relaud_item('Origem: ' . $o, (int) $n, in_array($o, ['manual', 'outro'], true) ? 'alerta' : 'info',
            in_array($o, ['manual', 'outro'], true) ? 'Origem genérica: Google Ads, site, telefone etc. podem estar aqui sem distinção.' : '', $total);
    }
    $semEmp = (int) $um('SELECT COUNT(*) FROM leads WHERE empresa_id = :e AND excluido_em IS NULL AND empreendimento_id IS NULL');
    $s['Leads'][] = relaud_item('Leads sem empreendimento', $semEmp, $semEmp ? 'alerta' : 'ok', relaud_pct($semEmp, $total) . ' do total.', $total);
    // Duplicados pelo telefone normalizado (feito no PHP: mesma regra do WhatsApp).
    $stmt = $pdo->prepare('SELECT telefone, email FROM leads WHERE empresa_id = :e AND excluido_em IS NULL');
    $stmt->execute(['e' => $empresaId]);
    $chaves = [];
    $semContato = 0;
    foreach ($stmt->fetchAll() as $l) {
        $k = wa_normalizar_telefone((string) $l['telefone']) ?: (trim((string) $l['email']) !== '' ? 'm:' . mb_strtolower(trim($l['email'])) : null);
        if ($k === null) {
            $semContato++;
            continue;
        }
        $chaves[$k] = ($chaves[$k] ?? 0) + 1;
    }
    $grupos = count(array_filter($chaves, fn ($n) => $n > 1));
    $extras = array_sum(array_map(fn ($n) => $n - 1, array_filter($chaves, fn ($n) => $n > 1)));
    $s['Leads'][] = relaud_item('Leads únicos (telefone/e-mail)', count($chaves) + $semContato, 'info', 'Cadastros a mais para a mesma pessoa: ' . $extras . ' (em ' . $grupos . ' pessoa[s]).', $total);
    $s['Leads'][] = relaud_item('Leads sem telefone válido nem e-mail', $semContato, $semContato ? 'alerta' : 'ok');
    if ($tem('meta_eventos')) {
        $s['Leads'][] = relaud_item('Reentradas do Meta (cadastro repetido, sem lead novo)', (int) $um("SELECT COUNT(*) FROM meta_eventos WHERE empresa_id = :e AND status = 'duplicado'"));
    }

    // ---------------------------------------------------------------- Atribuição
    $comResp = (int) $um('SELECT COUNT(*) FROM leads WHERE empresa_id = :e AND excluido_em IS NULL AND responsavel_id IS NOT NULL');
    $s['Atribuição e histórico do corretor'][] = relaud_item('Leads com corretor hoje', $comResp, 'info', '', $total);
    $semAtrib = (int) $um('SELECT COUNT(*) FROM leads WHERE empresa_id = :e AND excluido_em IS NULL AND responsavel_id IS NOT NULL AND atribuido_em IS NULL');
    $s['Atribuição e histórico do corretor'][] = relaud_item('Com corretor mas sem data de atribuição', $semAtrib, $semAtrib ? 'alerta' : 'ok', 'Ficam fora do "tempo até o primeiro atendimento".');
    $s['Atribuição e histórico do corretor'][] = relaud_item('Entregas pela roleta registradas', (int) $um('SELECT COUNT(*) FROM roleta_distribuicoes WHERE empresa_id = :e'));
    $stmt = $pdo->prepare('SELECT motivo, COUNT(*) FROM lead_transferencias WHERE empresa_id = :e GROUP BY motivo');
    $stmt->execute(['e' => $empresaId]);
    foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $m => $n) {
        $s['Atribuição e histórico do corretor'][] = relaud_item('Transferências: ' . $m, (int) $n);
    }
    // Lead com dono hoje sem NENHUM registro de como chegou a ele.
    $semOrigemAtrib = (int) $um("SELECT COUNT(*) FROM leads l WHERE l.empresa_id = :e AND l.excluido_em IS NULL AND l.responsavel_id IS NOT NULL
        AND NOT EXISTS (SELECT 1 FROM roleta_distribuicoes d WHERE d.lead_id = l.id AND d.usuario_id = l.responsavel_id)
        AND NOT EXISTS (SELECT 1 FROM lead_transferencias t WHERE t.lead_id = l.id AND t.para_usuario_id = l.responsavel_id)
        AND NOT EXISTS (SELECT 1 FROM auditoria a WHERE a.entidade = 'lead' AND a.entidade_id = l.id AND a.acao = 'lead.criado')
        AND NOT EXISTS (SELECT 1 FROM lead_eventos ev WHERE ev.lead_id = l.id AND ev.tipo IN ('atribuicao','importacao'))");
    $s['Atribuição e histórico do corretor'][] = relaud_item('Leads com corretor sem registro de como chegaram a ele', $semOrigemAtrib, $semOrigemAtrib ? 'alerta' : 'ok',
        'Para esses, "leads recebidos pelo corretor" só pode usar a carteira atual (limitação declarada).', $comResp);
    $s['Atribuição e histórico do corretor'][] = relaud_item('Leads trocados de corretor (perderam a data do 1º atendimento do anterior)',
        (int) $um('SELECT COUNT(DISTINCT lead_id) FROM lead_transferencias WHERE empresa_id = :e'), 'info', 'O 1º atendimento é zerado na troca: o histórico por corretor nasce na Etapa 2.');

    // ---------------------------------------------------------------- Atendimento
    $comContato = (int) $um('SELECT COUNT(*) FROM leads WHERE empresa_id = :e AND excluido_em IS NULL AND responsavel_id IS NOT NULL AND primeiro_contato_em IS NOT NULL');
    $s['Atendimento'][] = relaud_item('Leads com 1º atendimento registrado', $comContato, 'info', relaud_pct($comContato, $comResp) . ' dos que têm corretor.', $comResp);
    $incoer = (int) $um('SELECT COUNT(*) FROM leads WHERE empresa_id = :e AND excluido_em IS NULL AND primeiro_contato_em < atribuido_em');
    $s['Atendimento'][] = relaud_item('1º atendimento antes da atribuição (data incoerente)', $incoer, $incoer ? 'alerta' : 'ok');
    $futuro = (int) $um('SELECT COUNT(*) FROM leads WHERE empresa_id = :e AND (criado_em > DATE_ADD(NOW(), INTERVAL 1 DAY) OR atribuido_em > DATE_ADD(NOW(), INTERVAL 1 DAY))');
    $s['Atendimento'][] = relaud_item('Leads com datas no futuro', $futuro, $futuro ? 'critico' : 'ok');
    $stmt = $pdo->prepare('SELECT ev.tipo, COUNT(*) FROM lead_eventos ev JOIN leads l ON l.id = ev.lead_id WHERE l.empresa_id = :e GROUP BY ev.tipo');
    $stmt->execute(['e' => $empresaId]);
    foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $t => $n) {
        $s['Atendimento'][] = relaud_item('Eventos no histórico: ' . $t, (int) $n);
    }

    // ---------------------------------------------------------------- Funil
    $trans = $pdo->prepare("SELECT COUNT(*), MIN(criado_em) FROM auditoria WHERE empresa_id = :e AND acao = 'lead.etapa_alterada'");
    $trans->execute(['e' => $empresaId]);
    [$nTrans, $desde] = $trans->fetch(PDO::FETCH_NUM);
    $s['Funil (Kanban)'][] = relaud_item('Mudanças de etapa com origem/destino estruturados', (int) $nTrans, 'info', $desde ? 'Desde ' . date('d/m/Y', strtotime($desde)) . '.' : 'Nenhuma ainda.');
    $s['Funil (Kanban)'][] = relaud_item('Mudanças de etapa só em texto (histórico do lead)', (int) $um("SELECT COUNT(*) FROM lead_eventos ev JOIN leads l ON l.id = ev.lead_id WHERE l.empresa_id = :e AND ev.tipo = 'mudanca_etapa'"));
    $semHist = (int) $um("SELECT COUNT(*) FROM leads l JOIN funil_etapas fe ON fe.id = l.etapa_id
        WHERE l.empresa_id = :e AND l.excluido_em IS NULL AND fe.ordem > (SELECT MIN(f2.ordem) FROM funil_etapas f2 WHERE f2.empresa_id = l.empresa_id AND f2.ativa = 1)
          AND NOT EXISTS (SELECT 1 FROM auditoria a WHERE a.acao = 'lead.etapa_alterada' AND a.entidade = 'lead' AND a.entidade_id = l.id)");
    $s['Funil (Kanban)'][] = relaud_item('Leads fora da 1ª etapa sem nenhuma transição estruturada', $semHist, $semHist ? 'alerta' : 'ok',
        'Para esses não dá para dizer QUANDO avançaram: entram só na "posição atual".');
    $s['Funil (Kanban)'][] = relaud_item('Mudanças de etapa feitas por jornada (sem registro estruturado)',
        (int) $um("SELECT COUNT(*) FROM lead_eventos ev JOIN leads l ON l.id = ev.lead_id WHERE l.empresa_id = :e AND ev.tipo = 'jornada' AND ev.descricao LIKE '%lead movido para%'"),
        'info', 'Passam a ser estruturadas na Etapa 2.');
    $stmt = $pdo->prepare('SELECT fe.nome, COUNT(l.id) FROM funil_etapas fe LEFT JOIN leads l ON l.etapa_id = fe.id AND l.excluido_em IS NULL
                           WHERE fe.empresa_id = :e GROUP BY fe.id, fe.nome, fe.ordem ORDER BY fe.ordem');
    $stmt->execute(['e' => $empresaId]);
    foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$nome, $n]) {
        $s['Funil (Kanban)'][] = relaud_item('Agora em "' . $nome . '"', (int) $n);
    }
    $semEtapa = (int) $um('SELECT COUNT(*) FROM leads WHERE empresa_id = :e AND excluido_em IS NULL AND etapa_id IS NULL');
    $s['Funil (Kanban)'][] = relaud_item('Leads sem etapa', $semEtapa, $semEtapa ? 'alerta' : 'ok');

    // ---------------------------------------------------------------- Contratos
    $nContr = (int) $um("SELECT COUNT(*) FROM leads WHERE empresa_id = :e AND excluido_em IS NULL AND etapa = 'contrato_assinado'");
    $s['Contratos e visitas'][] = relaud_item('Leads hoje em "contrato assinado"', $nContr);
    $contrSemData = (int) $um("SELECT COUNT(*) FROM leads WHERE empresa_id = :e AND excluido_em IS NULL AND etapa = 'contrato_assinado' AND contrato_assinado_em IS NULL");
    $s['Contratos e visitas'][] = relaud_item('Contratos sem data de assinatura', $contrSemData, $contrSemData ? 'alerta' : 'ok', 'Ficam fora de "contratos no período".');
    $saiu = (int) $um("SELECT COUNT(DISTINCT a.entidade_id) FROM auditoria a JOIN leads l ON l.id = a.entidade_id
        WHERE a.empresa_id = :e AND a.acao = 'lead.etapa_alterada' AND a.detalhes_json LIKE '%\"para\":\"contrato_assinado\"%' AND l.etapa <> 'contrato_assinado'");
    $s['Contratos e visitas'][] = relaud_item('Leads que entraram em "contrato" e depois saíram da etapa', $saiu, $saiu ? 'alerta' : 'ok', 'Hoje o contrato deles "some" do período — a Etapa 2 preserva.');
    $s['Contratos e visitas'][] = relaud_item('Contratos com valor (VGV) registrado', 'não existe', 'alerta', 'O CRM não tem cadastro formal de contrato nem valor.');
    $stmt = $pdo->prepare("SELECT status, COUNT(*) FROM agenda_compromissos WHERE empresa_id = :e AND tipo = 'visita' GROUP BY status");
    $stmt->execute(['e' => $empresaId]);
    foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $st => $n) {
        $s['Contratos e visitas'][] = relaud_item('Visitas: ' . $st, (int) $n);
    }
    $vencidas = (int) $um("SELECT COUNT(*) FROM agenda_compromissos WHERE empresa_id = :e AND tipo = 'visita' AND status = 'agendado' AND inicio < DATE_SUB(NOW(), INTERVAL 1 DAY)");
    $s['Contratos e visitas'][] = relaud_item('Visitas que já passaram e continuam "agendadas" (sem situação)', $vencidas, $vencidas ? 'alerta' : 'ok', 'Não entram como realizadas nem ausentes.');
    $s['Contratos e visitas'][] = relaud_item('Visitas confirmadas com o cliente', 'não existe', 'info', 'A agenda não tem o status "confirmada".');

    // ---------------------------------------------------------------- Roletas
    $s['Plantões e roletas'][] = relaud_item('Sessões de presença registradas', (int) $um('SELECT COUNT(*) FROM roleta_sessoes WHERE empresa_id = :e'));
    $s['Plantões e roletas'][] = relaud_item('Leads na fila agora', (int) $um('SELECT COUNT(*) FROM roleta_fila WHERE empresa_id = :e AND resolvido_em IS NULL'));
    $s['Plantões e roletas'][] = relaud_item('Passagens pela fila guardadas', (int) $um('SELECT COUNT(*) FROM roleta_fila WHERE empresa_id = :e'), 'info',
        'A fila guarda só a última passagem de cada lead.');
    $s['Plantões e roletas'][] = relaud_item('Horário das roletas com versões (histórico)', 'não existe', 'info', 'Mudar o horário muda o passado; "dentro/fora do horário" precisa ser gravado na chegada do lead.');

    // ---------------------------------------------------------------- Marketing
    $meta = (int) $um("SELECT COUNT(*) FROM leads WHERE empresa_id = :e AND excluido_em IS NULL AND origem = 'meta_ads'");
    $s['Marketing'][] = relaud_item('Leads do Meta Ads', $meta, 'info', '', $total);
    if (modulo_tabela_existe('meta_anuncios')) {
        $semAd = (int) $um("SELECT COUNT(*) FROM leads WHERE empresa_id = :e AND excluido_em IS NULL AND origem = 'meta_ads' AND meta_ad_id IS NULL");
        $s['Marketing'][] = relaud_item('Leads do Meta sem anúncio identificado', $semAd, $semAd ? 'alerta' : 'ok', relaud_pct($semAd, $meta) . ' (anteriores à migração 011 ou cadastros orgânicos).', $meta);
        $stmt = $pdo->prepare('SELECT sync_status, COUNT(*) FROM meta_anuncios WHERE empresa_id = :e GROUP BY sync_status');
        $stmt->execute(['e' => $empresaId]);
        foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $st => $n) {
            $s['Marketing'][] = relaud_item('Anúncios — sincronização: ' . $st, (int) $n, in_array($st, ['erro', 'sem_permissao'], true) ? 'alerta' : 'info');
        }
    } else {
        $s['Marketing'][] = relaud_item('Origem por anúncio/criativo', 'migração 011 pendente', 'alerta');
    }
    $semCamp = (int) $um("SELECT COUNT(*) FROM leads WHERE empresa_id = :e AND excluido_em IS NULL AND origem = 'meta_ads' AND (campanha IS NULL OR campanha = '')");
    $s['Marketing'][] = relaud_item('Leads do Meta sem nome de campanha', $semCamp, $semCamp ? 'alerta' : 'ok');
    $s['Marketing'][] = relaud_item('Erros nos avisos do Meta (webhook)', (int) $um("SELECT COUNT(*) FROM meta_eventos WHERE empresa_id = :e AND status = 'erro'"));
    $s['Marketing'][] = relaud_item('UTMs (origem de site/landing page)', 'não existe', 'alerta', 'O cadastro não guarda utm_source/utm_campaign.');
    $s['Marketing'][] = relaud_item('Investimento publicitário', 'não existe', 'alerta', 'Sem lançamentos nem integração: CPL e custos ficam "não disponível".');
    $s['Marketing'][] = relaud_item('Google Ads', 'não integrado', 'info');

    // ---------------------------------------------------------------- WhatsApp
    if ($tem('whatsapp_mensagens')) {
        $stmt = $pdo->prepare("SELECT status, COUNT(*) FROM whatsapp_mensagens WHERE empresa_id = :e AND direcao = 'saida' GROUP BY status");
        $stmt->execute(['e' => $empresaId]);
        foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $st => $n) {
            $s['WhatsApp e remarketing'][] = relaud_item('Mensagens enviadas: ' . $st, (int) $n, $st === 'falhou' && $n ? 'alerta' : 'info', $st === 'simulada' ? 'Modo de teste: não chegaram ao cliente; ficam fora dos indicadores reais.' : '');
        }
        $s['WhatsApp e remarketing'][] = relaud_item('Mensagens recebidas dos clientes', (int) $um("SELECT COUNT(*) FROM whatsapp_mensagens WHERE empresa_id = :e AND direcao = 'entrada'"));
        $s['WhatsApp e remarketing'][] = relaud_item('Conversas sem lead vinculado', (int) $um('SELECT COUNT(*) FROM whatsapp_conversas WHERE empresa_id = :e AND lead_id IS NULL'));
        $s['WhatsApp e remarketing'][] = relaud_item('Avisos do WhatsApp com erro de processamento', (int) $um("SELECT COUNT(*) FROM whatsapp_webhook_eventos WHERE empresa_id = :e AND status = 'erro'"));
        $s['WhatsApp e remarketing'][] = relaud_item('Campanhas de remarketing', (int) $um('SELECT COUNT(*) FROM whatsapp_campanhas WHERE empresa_id = :e'));
        $semTarifa = !(int) $um("SELECT COUNT(*) FROM whatsapp_tarifas WHERE (empresa_id = :e OR empresa_id IS NULL)");
        $s['WhatsApp e remarketing'][] = relaud_item('Tarifas de mensagem cadastradas', $semTarifa ? 'nenhuma' : 'sim', $semTarifa ? 'alerta' : 'ok', $semTarifa ? 'Custo das mensagens fica "não disponível".' : '');
    } else {
        $s['WhatsApp e remarketing'][] = relaud_item('Módulo WhatsApp', 'migrações 008–010 pendentes', 'alerta');
    }
    return $s;
}
