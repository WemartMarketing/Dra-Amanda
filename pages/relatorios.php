<?php
/**
 * Relatórios de perdas e transferências (seção 12). Agregado pela estrutura
 * ANTERIOR do lead (rbac_transferencias_scope): o gerente vê a própria
 * equipe, o superintendente o agregado das equipes dele. Nunca mostra nome
 * ou telefone de lead — só contagens e nomes de pessoas/estruturas.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/estrutura.php';
require_once __DIR__ . '/../includes/dashboard_ui.php';

$usuario = auth_require();
$crumb = 'Perdas e transferências';
$activeNav = 'relatorios';
if (!empty($usuario['eh_superadmin'])) {
    header('Location: /pages/superadmin.php');
    exit;
}
if (!usuario_tem_permissao($usuario, 'visualizar_indicadores')) {
    pagina_acesso_restrito($crumb, $activeNav, 'Visualizar indicadores/dashboard');
}

$f = dash_filtros($usuario, $_GET);
$dimsPerdas = array_intersect_key(dash_dimensoes(), array_flip(['corretor', 'equipe', 'gerente', 'superintendencia', 'empreendimento']));
$padrao = dash_dimensao_padrao($usuario);
$por = array_key_exists($_GET['perdas_por'] ?? '', $dimsPerdas) ? $_GET['perdas_por'] : (isset($dimsPerdas[$padrao]) ? $padrao : 'corretor');
$opcoes = dash_opcoes($usuario);
$publicos = dash_filtros_publicos($f);

$linhas = dash_perdas($usuario, $f, $por);
$porEmp = $por === 'empreendimento' ? $linhas : dash_perdas($usuario, $f, 'empreendimento');
$serie = dash_perdas_serie($usuario, $f);
$motivos = dash_perdas_por_motivo($usuario, $f);
$somar = fn (array $ls, string $c) => array_sum(array_column($ls, $c));
$tot = ['nome' => 'Total'];
foreach (['inatividade', 'redistribuidos', 'administrativas', 'mudanca_equipe', 'total'] as $c) {
    $tot[$c] = $somar($linhas, $c);
}
$fAnt = ['ini' => $f['ant_ini'], 'fim' => $f['ant_fim']] + $f;
$ant = dash_perdas($usuario, $fAnt, 'superintendencia');
$antIna = $somar($ant, 'inatividade');
$antAdm = $somar($ant, 'administrativas');
[$varIna, $clsIna] = dash_variacao($tot['inatividade'], $antIna, 'qtd', true);
[$varAdm, $clsAdm] = dash_variacao($tot['administrativas'], $antAdm, 'qtd', true);
$podeExportar = usuario_tem_permissao($usuario, 'exportar_relatorios');
$urlPor = fn (string $d) => '?' . http_build_query($publicos + ['perdas_por' => $d]) . '#perdas-inatividade';

$pageTitle = 'Perdas e transferências — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div>
        <div class="eyebrow">Relatórios</div>
        <h1>Perdas e transferências</h1>
        <p><?= e(dash_periodos()[$f['periodo']]) ?>: <b><?= e($f['rotulo']) ?></b> · contado pela equipe que estava com o lead antes de ele sair.</p>
    </div>
    <div class="actions">
        <a class="btn btn-outline" href="/pages/dashboard.php?<?= e(http_build_query($publicos)) ?>"><?= icon('chart') ?>Dashboard</a>
        <?php if ($podeExportar): ?>
            <?php dash_exportar('modal-exportar', [
                'perdas' => 'Perdas por ' . mb_strtolower($dimsPerdas[$por]) . ' (tabela abaixo)',
                'perdas_empreendimento' => 'Perdas por empreendimento',
            ], $publicos + ['perdas_por' => $por]); ?>
        <?php endif; ?>
    </div>
</div>

<?php dash_form_filtros($f, $opcoes, ['perdas_por' => $por]); ?>

<div class="kpi-grid dash-kpis">
    <div class="kpi"><div class="kpi-top"><span class="label">Perdidos por inatividade <?= dash_ajuda('Leads retirados do corretor pela recuperação automática, por falta de feedback no prazo.') ?></span><div class="kpi-icon"><?= icon('clock') ?></div></div>
        <div class="kpi-value"><?= dash_num($tot['inatividade']) ?></div><div class="kpi-sub <?= $clsIna ?>"><?= e($varIna) ?></div></div>
    <div class="kpi"><div class="kpi-top"><span class="label">Já redistribuídos <?= dash_ajuda('Dos perdidos por inatividade, quantos já foram entregues a outro corretor.') ?></span><div class="kpi-icon"><?= icon('users') ?></div></div>
        <div class="kpi-value"><?= dash_num($tot['redistribuidos']) ?></div><div class="kpi-sub"><?= $tot['inatividade'] ? dash_pct($tot['redistribuidos'] / $tot['inatividade'] * 100) . ' dos perdidos' : '—' ?></div></div>
    <div class="kpi"><div class="kpi-top"><span class="label">Transferências administrativas <?= dash_ajuda('Leads que um gestor passou para outro corretor manualmente.') ?></span><div class="kpi-icon"><?= icon('users') ?></div></div>
        <div class="kpi-value"><?= dash_num($tot['administrativas']) ?></div><div class="kpi-sub <?= $clsAdm ?>"><?= e($varAdm) ?></div></div>
    <div class="kpi"><div class="kpi-top"><span class="label">Mudança de equipe <?= dash_ajuda('Leads que acompanharam o corretor quando ele trocou de equipe (o corretor continua o mesmo).') ?></span><div class="kpi-icon"><?= icon('kanban') ?></div></div>
        <div class="kpi-value"><?= dash_num($tot['mudanca_equipe']) ?></div><div class="kpi-sub">no período</div></div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-head"><div><h2>Tendência</h2><p>Saídas por <?= $f['dias'] > 92 ? 'semana' : 'dia' ?>.</p></div>
            <div class="dash-legenda-inline"><span><span class="dot seg-ina"></span>Inatividade</span><span><span class="dot seg-adm"></span>Administrativas</span></div></div>
        <?php dash_grafico_barras($serie, 'Perdas por inatividade e transferências administrativas', 'saídas'); ?>
    </div>
    <div class="card">
        <div class="card-head"><div><h2>Leads encerrados sem venda <?= dash_ajuda('Leads recebidos no período que hoje estão em "Perdido" ou "Sem interesse", pelo motivo informado pelo corretor.') ?></h2></div></div>
        <?php dash_barras_horizontais(array_map(fn ($m) => ['rotulo' => $m['motivo'], 'total' => $m['total']], $motivos), 'Motivos de perda', 10); ?>
    </div>
</div>

<div class="card dash-section" id="perdas-inatividade">
    <div class="card-head"><div><h2>Perdas por estrutura anterior <?= dash_ajuda('Quem estava com o lead quando ele saiu. Inatividade e transferências administrativas aparecem separadas.') ?></h2><p>Agrupar por:</p></div></div>
    <nav class="segmented-inline dash-por" aria-label="Agrupar perdas por">
        <?php foreach ($dimsPerdas as $d => $rot): ?>
            <a class="seg-link<?= $por === $d ? ' is-active' : '' ?>" href="<?= e($urlPor($d)) ?>"<?= $por === $d ? ' aria-current="true"' : '' ?>><?= e($rot) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php dash_tabela('tabela-perdas', dash_colunas_perdas($por), $linhas, $tot, 'inatividade', 'Nenhuma perda ou transferência no período.'); ?>
</div>

<?php if ($por !== 'empreendimento'): ?>
<div class="card dash-section" id="perdas-empreendimento">
    <div class="card-head"><div><h2>Perdas por empreendimento</h2></div></div>
    <?php
    $totEmp = ['nome' => 'Total'];
    foreach (['inatividade', 'redistribuidos', 'administrativas', 'mudanca_equipe', 'total'] as $c) {
        $totEmp[$c] = $somar($porEmp, $c);
    }
    dash_tabela('tabela-perdas-emp', dash_colunas_perdas('empreendimento'), $porEmp, $totEmp, 'inatividade', 'Nenhuma perda ou transferência no período.');
    ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
