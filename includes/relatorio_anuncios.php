<?php
/**
 * Leads por campanha e por anúncio do Meta Ads — só com registros reais do
 * CRM (leads, primeiro contato, visitas, contratos). Não há investimento,
 * CPL nem métricas de mídia: isso não está integrado e não é inventado.
 * Sempre no recorte de leads de quem consulta (rbac_leads_scope).
 */
require_once __DIR__ . '/dashboard.php';

function relanu_linhas(array $usuario, array $f, string $por): array
{
    $scope = rbac_leads_scope($usuario);
    $p = $scope['params'] + ['ra_ini' => $f['ini'], 'ra_fim' => $f['fim']];
    $extra = '';
    if ($f['empreendimento']) {
        $extra .= ' AND leads.empreendimento_id = :ra_emp';
        $p['ra_emp'] = $f['empreendimento'];
    }
    if ($f['equipe']) {
        $extra .= ' AND leads.equipe_id = :ra_eq';
        $p['ra_eq'] = $f['equipe'];
    }
    $grupo = $por === 'anuncio'
        ? ['COALESCE(leads.meta_ad_id, \'\')', "COALESCE(MAX(a.nome), IF(leads.meta_ad_id IS NULL, 'Sem anúncio identificado', CONCAT('Anúncio ', leads.meta_ad_id)))", 'MAX(COALESCE(a.campanha_nome, leads.campanha))']
        : ['COALESCE(leads.meta_campanha_id, a.campanha_id, leads.campanha, \'\')', "COALESCE(MAX(a.campanha_nome), MAX(leads.campanha), 'Sem campanha identificada')", 'NULL'];
    $sql = "SELECT {$grupo[0]} AS chave, {$grupo[1]} AS nome, {$grupo[2]} AS campanha,
                   COUNT(*) AS leads,
                   SUM(leads.primeiro_contato_em IS NOT NULL) AS contatados,
                   SUM(EXISTS (SELECT 1 FROM agenda_compromissos ag WHERE ag.lead_id = leads.id AND ag.tipo = 'visita' AND ag.status <> 'cancelado')) AS visitas,
                   SUM(leads.contrato_assinado_em IS NOT NULL) AS contratos
            FROM leads LEFT JOIN meta_anuncios a ON a.id = leads.meta_anuncio_id AND a.empresa_id = leads.empresa_id
            WHERE leads.origem = 'meta_ads' AND leads.criado_em >= :ra_ini AND leads.criado_em < :ra_fim AND {$scope['sql']} {$extra}
            GROUP BY chave ORDER BY leads DESC LIMIT 500";
    $stmt = db()->prepare($sql);
    $stmt->execute($p);
    $linhas = [];
    foreach ($stmt->fetchAll() as $l) {
        $n = (int) $l['leads'];
        $linhas[] = ['nome' => $l['nome'], 'campanha' => $l['campanha'], 'leads' => $n, 'contatados' => (int) $l['contatados'],
                     'taxa_contato' => $n ? 100 * $l['contatados'] / $n : null, 'visitas' => (int) $l['visitas'],
                     'contratos' => (int) $l['contratos'], 'conversao' => $n ? 100 * $l['contratos'] / $n : null];
    }
    return $linhas;
}

function relanu_colunas(string $por): array
{
    $c = ['nome' => [$por === 'anuncio' ? 'Anúncio' : 'Campanha', 'texto']];
    if ($por === 'anuncio') {
        $c['campanha'] = ['Campanha', 'texto'];
    }
    return $c + ['leads' => ['Leads', 'int'], 'contatados' => ['Com 1º contato', 'int'], 'taxa_contato' => ['Taxa de 1º contato (%)', 'pct'],
                 'visitas' => ['Visitas agendadas', 'int'], 'contratos' => ['Contratos', 'int'], 'conversao' => ['Conversão em contrato (%)', 'pct']];
}

function relanu_totais(array $linhas): array
{
    $t = ['nome' => 'Total', 'campanha' => null];
    foreach (['leads', 'contatados', 'visitas', 'contratos'] as $k) {
        $t[$k] = array_sum(array_column($linhas, $k));
    }
    $t['taxa_contato'] = $t['leads'] ? 100 * $t['contatados'] / $t['leads'] : null;
    $t['conversao'] = $t['leads'] ? 100 * $t['contratos'] / $t['leads'] : null;
    return $t;
}
