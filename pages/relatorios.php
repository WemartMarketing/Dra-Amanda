<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/leads.php';

$usuario = auth_require();
$empresaId = (int) $usuario['empresa_ativa_id'];
$scope = rbac_leads_scope($usuario); // sempre aplicado primeiro — os filtros abaixo só restringem ainda mais, nunca abrem exceção ao escopo do cargo.

// --------------------------------------------------------------
// Filtros (GET) — período fixo + empreendimento/superintendência/
// equipe (rotulada "Gerente", já que cada equipe tem um gerente só).
// As opções dos selects listam tudo da empresa, não só o que o cargo
// atual enxergaria sozinho; isso é seguro porque o filtro escolhido
// sempre se soma (AND) ao escopo obrigatório do cargo — nunca o
// substitui — então escolher algo fora do próprio escopo simplesmente
// não traz nenhuma linha, nunca vaza dado de outra equipe.
// --------------------------------------------------------------
$periodosValidos = [7 => 'Últimos 7 dias', 30 => 'Últimos 30 dias', 90 => 'Últimos 90 dias'];
$periodo = (int) ($_GET['periodo'] ?? 30);
if (!isset($periodosValidos[$periodo])) {
    $periodo = 30;
}
$dataInicio = date('Y-m-d', strtotime("-{$periodo} days"));

$filtroEmpreendimento = isset($_GET['empreendimento']) && $_GET['empreendimento'] !== '' ? (int) $_GET['empreendimento'] : null;
$filtroSuperintendencia = isset($_GET['superintendencia']) && $_GET['superintendencia'] !== '' ? (int) $_GET['superintendencia'] : null;
$filtroEquipe = isset($_GET['equipe']) && $_GET['equipe'] !== '' ? (int) $_GET['equipe'] : null;

$extraSql = '';
$extraParams = [];
if ($filtroEmpreendimento) {
    $extraSql .= ' AND leads.empreendimento_id = :f_emp';
    $extraParams['f_emp'] = $filtroEmpreendimento;
}
if ($filtroEquipe) {
    $extraSql .= ' AND leads.equipe_id = :f_equipe';
    $extraParams['f_equipe'] = $filtroEquipe;
} elseif ($filtroSuperintendencia) {
    $extraSql .= ' AND leads.equipe_id IN (SELECT id FROM equipes WHERE superintendencia_id = :f_super)';
    $extraParams['f_super'] = $filtroSuperintendencia;
}

$baseSql = $scope['sql'] . $extraSql;
$baseParams = array_merge($scope['params'], $extraParams);

function relContar(string $baseSql, array $baseParams, string $condicao, array $params = []): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM leads WHERE ' . $baseSql . ' AND ' . $condicao);
    $stmt->execute(array_merge($baseParams, $params));
    return (int) $stmt->fetchColumn();
}

// --------------------------------------------------------------
// KPIs
// --------------------------------------------------------------
$leadsRecebidos = relContar($baseSql, $baseParams, 'leads.criado_em >= :data_inicio', ['data_inicio' => $dataInicio]);
$stmt = db()->prepare("SELECT COUNT(DISTINCT lead_eventos.lead_id) FROM lead_eventos INNER JOIN leads ON leads.id = lead_eventos.lead_id WHERE {$baseSql} AND lead_eventos.tipo = 'contato' AND lead_eventos.criado_em >= :data_inicio");
$stmt->execute(array_merge($baseParams, ['data_inicio' => $dataInicio]));
$atendimentos = (int) $stmt->fetchColumn();
$visitasAgendadas = relContar($baseSql, $baseParams, "leads.etapa IN ('visita_agendada','visita_realizada','contrato_assinado') AND leads.criado_em >= :data_inicio", ['data_inicio' => $dataInicio]);
$vendasRealizadas = relContar($baseSql, $baseParams, "leads.etapa = 'contrato_assinado' AND leads.atualizado_em >= :data_inicio", ['data_inicio' => $dataInicio]);
$perdasInatividade = relContar($baseSql, $baseParams, "leads.etapa = 'perdido' AND leads.atualizado_em >= :data_inicio", ['data_inicio' => $dataInicio]);

// --------------------------------------------------------------
// Leads recebidos por dia (gráfico de barras, período inteiro, com
// dias sem lead aparecendo como zero — não somem do eixo).
// --------------------------------------------------------------
$stmt = db()->prepare("SELECT DATE(leads.criado_em) AS dia, COUNT(*) AS total FROM leads WHERE {$baseSql} AND leads.criado_em >= :data_inicio GROUP BY dia");
$stmt->execute(array_merge($baseParams, ['data_inicio' => $dataInicio]));
$porDiaRaw = [];
foreach ($stmt->fetchAll() as $row) {
    $porDiaRaw[$row['dia']] = (int) $row['total'];
}
$serieDiaria = [];
for ($d = strtotime($dataInicio); $d <= strtotime('today'); $d += 86400) {
    $chave = date('Y-m-d', $d);
    $serieDiaria[] = ['dia' => $chave, 'total' => $porDiaRaw[$chave] ?? 0];
}
$maxDiario = max(1, max(array_column($serieDiaria, 'total')));

// --------------------------------------------------------------
// Funil de conversão — cada etapa conta os leads criados no período
// que estão nela OU já passaram dela (aproximação: não guardamos a
// data em que cada etapa foi alcançada, só a etapa atual; um lead
// hoje em "Visita agendada" soma em todas as etapas anteriores do
// funil, não só na sua atual — assim como no relatório de referência).
// --------------------------------------------------------------
$funilEtapas = lead_etapas_funil();
$funil = [];
foreach ($funilEtapas as $i => $etapa) {
    $restantes = array_slice($funilEtapas, $i);
    $placeholders = [];
    $params = ['data_inicio' => $dataInicio];
    foreach ($restantes as $j => $e) {
        $ph = "et{$j}";
        $placeholders[] = ":{$ph}";
        $params[$ph] = $e;
    }
    $stmt = db()->prepare("SELECT COUNT(*) FROM leads WHERE {$baseSql} AND leads.criado_em >= :data_inicio AND leads.etapa IN (" . implode(',', $placeholders) . ')');
    $stmt->execute(array_merge($baseParams, $params));
    $funil[] = ['etapa' => $etapa, 'rotulo' => lead_etapa_rotulo($etapa), 'total' => (int) $stmt->fetchColumn()];
}
$maxFunil = max(1, $funil[0]['total']);
$conversaoGeral = $funil[0]['total'] > 0 ? round(end($funil)['total'] / $funil[0]['total'] * 100, 1) : 0.0;

// --------------------------------------------------------------
// Resultado por empreendimento
// --------------------------------------------------------------
$stmt = db()->prepare("
    SELECT empreendimentos.id, empreendimentos.nome,
        COUNT(leads.id) AS total_leads,
        SUM(leads.etapa = 'contrato_assinado') AS total_vendas
    FROM empreendimentos
    LEFT JOIN leads ON leads.empreendimento_id = empreendimentos.id AND {$baseSql} AND leads.criado_em >= :data_inicio
    WHERE empreendimentos.empresa_id = :empresa_id
    GROUP BY empreendimentos.id, empreendimentos.nome
    ORDER BY total_leads DESC
");
$stmt->execute(array_merge($baseParams, ['data_inicio' => $dataInicio, 'empresa_id' => $empresaId]));
$porEmpreendimento = $stmt->fetchAll();

// --------------------------------------------------------------
// Oportunidades perdidas (por motivo)
// --------------------------------------------------------------
$stmt = db()->prepare("SELECT COALESCE(motivo_perda, 'Outros motivos') AS motivo, COUNT(*) AS total FROM leads WHERE {$baseSql} AND leads.etapa = 'perdido' AND leads.atualizado_em >= :data_inicio GROUP BY motivo ORDER BY total DESC");
$stmt->execute(array_merge($baseParams, ['data_inicio' => $dataInicio]));
$perdasPorMotivo = $stmt->fetchAll();
$maxPerdas = max(1, $perdasInatividade);

// --------------------------------------------------------------
// Opções dos filtros
// --------------------------------------------------------------
$empreendimentosOpt = db()->prepare('SELECT id, nome FROM empreendimentos WHERE empresa_id = :empresa_id ORDER BY nome');
$empreendimentosOpt->execute(['empresa_id' => $empresaId]);
$empreendimentosOpt = $empreendimentosOpt->fetchAll();

$superintendenciasOpt = db()->prepare('SELECT id, nome FROM superintendencias WHERE empresa_id = :empresa_id ORDER BY nome');
$superintendenciasOpt->execute(['empresa_id' => $empresaId]);
$superintendenciasOpt = $superintendenciasOpt->fetchAll();

$equipesOpt = db()->prepare("SELECT equipes.id, COALESCE(usuarios.nome, equipes.nome) AS rotulo FROM equipes LEFT JOIN usuarios ON usuarios.id = equipes.gerente_id WHERE equipes.empresa_id = :empresa_id ORDER BY rotulo");
$equipesOpt->execute(['empresa_id' => $empresaId]);
$equipesOpt = $equipesOpt->fetchAll();

$pageTitle = 'Relatórios — ' . APP_NAME;
$crumb = 'Relatórios';
$activeNav = 'relatorios';
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div><div class="eyebrow">Visão do gestor</div><h1>Desempenho comercial</h1><p>Acompanhe resultados sem precisar abrir os leads individualmente.</p></div>
    <div class="actions">
        <button type="button" class="btn btn-outline" data-open-modal="modal-excel"><?= icon('chart') ?>Exportar Excel</button>
    </div>
</div>

<form class="filters-row" method="get">
    <select class="select-filter" name="superintendencia" onchange="this.form.submit()">
        <option value="">Todas as superintendências</option>
        <?php foreach ($superintendenciasOpt as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $filtroSuperintendencia === (int) $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['nome']) ?></option>
        <?php endforeach; ?>
    </select>
    <select class="select-filter" name="equipe" onchange="this.form.submit()">
        <option value="">Todos os gerentes</option>
        <?php foreach ($equipesOpt as $e): ?>
            <option value="<?= $e['id'] ?>" <?= $filtroEquipe === (int) $e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['rotulo']) ?></option>
        <?php endforeach; ?>
    </select>
    <select class="select-filter" name="empreendimento" onchange="this.form.submit()">
        <option value="">Todos os empreendimentos</option>
        <?php foreach ($empreendimentosOpt as $emp): ?>
            <option value="<?= $emp['id'] ?>" <?= $filtroEmpreendimento === (int) $emp['id'] ? 'selected' : '' ?>><?= htmlspecialchars($emp['nome']) ?></option>
        <?php endforeach; ?>
    </select>
    <select class="select-filter" name="periodo" onchange="this.form.submit()">
        <?php foreach ($periodosValidos as $dias => $rotulo): ?>
            <option value="<?= $dias ?>" <?= $periodo === $dias ? 'selected' : '' ?>><?= $rotulo ?></option>
        <?php endforeach; ?>
    </select>
</form>

<div class="kpi-grid kpi-grid-5">
    <div class="kpi"><div class="kpi-top"><span class="label">Leads recebidos</span><div class="kpi-icon"><?= icon('user') ?></div></div><div class="kpi-value"><?= $leadsRecebidos ?></div><div class="kpi-sub">no período</div></div>
    <div class="kpi"><div class="kpi-top"><span class="label">Atendimentos</span><div class="kpi-icon"><?= icon('phone') ?></div></div><div class="kpi-value"><?= $atendimentos ?></div><div class="kpi-sub">registrados no período</div></div>
    <div class="kpi"><div class="kpi-top"><span class="label">Visitas agendadas</span><div class="kpi-icon"><?= icon('calendar') ?></div></div><div class="kpi-value"><?= $visitasAgendadas ?></div><div class="kpi-sub">visitas confirmadas</div></div>
    <div class="kpi"><div class="kpi-top"><span class="label">Vendas realizadas</span><div class="kpi-icon"><?= icon('check') ?></div></div><div class="kpi-value"><?= $vendasRealizadas ?></div><div class="kpi-sub">contratos assinados</div></div>
    <div class="kpi"><div class="kpi-top"><span class="label">Perdas por inatividade</span><div class="kpi-icon"><?= icon('clock') ?></div></div><div class="kpi-value"><?= $perdasInatividade ?></div><div class="kpi-sub">redistribuídos/perdidos</div></div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-head">
            <div><h2>Leads recebidos por dia</h2><p>Evolução da demanda nos últimos <?= $periodo ?> dias.</p></div>
            <span class="pill pill-muted"><?= $leadsRecebidos ?> no período</span>
        </div>
        <div class="bar-chart">
            <?php foreach ($serieDiaria as $ponto): ?>
                <div class="bar-col" title="<?= date('d/m', strtotime($ponto['dia'])) ?>: <?= $ponto['total'] ?> lead(s)">
                    <div class="bar" style="height: <?= max(3, round($ponto['total'] / $maxDiario * 100)) ?>%"></div>
                    <?php if (count($serieDiaria) <= 31): ?><span class="bar-label"><?= date('d/m', strtotime($ponto['dia'])) ?></span><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><div><h2>Funil de conversão</h2><p>Onde suas oportunidades avançam.</p></div></div>
        <div class="funnel">
            <?php foreach ($funil as $passo): ?>
                <div class="funnel-row">
                    <span class="funnel-label"><?= htmlspecialchars($passo['rotulo']) ?></span>
                    <div class="funnel-track"><div class="funnel-fill" style="width: <?= max(2, round($passo['total'] / $maxFunil * 100)) ?>%"></div></div>
                    <span class="funnel-value"><?= $passo['total'] ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="funnel-total"><span>Conversão geral</span><strong><?= $conversaoGeral ?>%</strong></div>
    </div>
</div>

<div class="grid-2" style="margin-top:20px;">
    <div class="card">
        <div class="card-head">
            <div><h2>Resultado por empreendimento</h2></div>
        </div>
        <table class="report-table">
            <thead><tr><th>Empreendimento</th><th>Leads</th><th>Vendas</th><th>Conversão</th></tr></thead>
            <tbody>
            <?php foreach ($porEmpreendimento as $row):
                $conv = $row['total_leads'] > 0 ? round($row['total_vendas'] / $row['total_leads'] * 100, 1) : 0.0;
            ?>
                <tr>
                    <td><strong><?= htmlspecialchars($row['nome']) ?></strong></td>
                    <td><?= (int) $row['total_leads'] ?></td>
                    <td><?= (int) $row['total_vendas'] ?></td>
                    <td><span class="pill pill-green"><?= $conv ?>%</span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$porEmpreendimento): ?><tr><td colspan="4" class="empty-note">Nenhum empreendimento cadastrado.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <div class="card-head">
            <div><h2>Oportunidades perdidas</h2><p>Por falta de atuação dentro do prazo.</p></div>
            <?php if ($perdasInatividade > 0): ?><span class="pill pill-red">Atenção</span><?php endif; ?>
        </div>
        <div class="perdas-headline"><strong><?= $perdasInatividade ?></strong><div><b>Leads perdidos</b><span>No período selecionado.</span></div></div>
        <?php if ($perdasPorMotivo): ?>
            <div class="funnel" style="margin-top:14px;">
                <?php foreach ($perdasPorMotivo as $m): ?>
                    <div class="funnel-row">
                        <span class="funnel-label"><?= htmlspecialchars($m['motivo']) ?></span>
                        <div class="funnel-track funnel-track-red"><div class="funnel-fill funnel-fill-red" style="width: <?= max(2, round($m['total'] / $maxPerdas * 100)) ?>%"></div></div>
                        <span class="funnel-value"><?= (int) $m['total'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="empty-note">Nenhuma oportunidade perdida no período — ótimo sinal.</p>
        <?php endif; ?>
    </div>
</div>

<div class="modal-overlay" id="modal-excel" hidden>
    <div class="modal-box">
        <button type="button" class="overlay-close" data-close-modal="modal-excel">&times;</button>
        <div class="stub-icon" style="margin:0 auto 14px;"><?= icon('chart') ?></div>
        <h2 style="text-align:center;">Exportação em desenvolvimento</h2>
        <p style="text-align:center;color:#8893a4;font-size:14px;">A exportação para Excel desta tela chega na próxima etapa do projeto.</p>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
