<?php
/**
 * Dashboard do gestor (seção 12). Só para quem tem "Visualizar
 * indicadores"; corretores continuam com o Início simples.
 * Todas as contas estão em includes/dashboard.php (as mesmas da exportação).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/estrutura.php';
require_once __DIR__ . '/../includes/dashboard_ui.php';

$usuario = auth_require();
$crumb = 'Dashboard';
$activeNav = 'dashboard';
if (!empty($usuario['eh_superadmin'])) {
    header('Location: /pages/superadmin.php');
    exit;
}
if (!usuario_tem_permissao($usuario, 'visualizar_indicadores')) {
    pagina_acesso_restrito($crumb, $activeNav, 'Visualizar indicadores/dashboard');
}

$f = dash_filtros($usuario, $_GET);
$por = array_key_exists($_GET['por'] ?? '', dash_dimensoes()) ? $_GET['por'] : dash_dimensao_padrao($usuario);
$opcoes = dash_opcoes($usuario);

$k = dash_kpis($usuario, $f, $f['ini'], $f['fim']);
$a = dash_kpis($usuario, $f, $f['ant_ini'], $f['ant_fim']);
$serie = dash_serie_leads($usuario, $f);
$funil = dash_funil($usuario, $f);
$quebra = dash_quebra($usuario, $f, $por);
$totais = dash_totais($quebra);
$porEmp = dash_recebidos_por($usuario, $f, 'empreendimento');
$porOrigem = dash_recebidos_por($usuario, $f, 'origem');
$porCampanha = dash_recebidos_por($usuario, $f, 'campanha');
$porRoleta = dash_por_roleta($usuario, $f);
$filaAgora = dash_fila_agora($usuario, $f);
$podeExportar = usuario_tem_permissao($usuario, 'exportar_relatorios');
$publicos = dash_filtros_publicos($f);
$horas = dash_horas_sem_atendimento();

$kpis = [
    ['Leads recebidos', dash_num($k['recebidos']), dash_variacao($k['recebidos'], $a['recebidos']), 'user',
        'Leads que entraram no período, por qualquer origem.'],
    ['Distribuídos', dash_num($k['distribuidos']), dash_variacao($k['distribuidos'], $a['distribuidos']), 'users',
        'Dos leads recebidos no período, quantos já estão com um corretor.'],
    ['Sem atendimento', dash_num($k['sem_atendimento']), dash_variacao($k['sem_atendimento'], $a['sem_atendimento'], 'qtd', true), 'alert',
        "Leads com corretor há mais de {$horas} h e ainda sem nenhum contato registrado. Quanto menor, melhor."],
    ['Taxa de primeiro contato', dash_pct($k['taxa_contato']), dash_variacao($k['taxa_contato'], $a['taxa_contato'], 'pp'), 'phone',
        'Dos leads distribuídos, a parte que o corretor já atendeu (mudou etapa, registrou atendimento ou visita).'],
    ['Tempo até o 1º contato', dash_duracao($k['tempo_medio']), dash_variacao($k['tempo_medio'], $a['tempo_medio'], 'tempo', true), 'clock',
        'Tempo médio entre o lead chegar ao corretor e o primeiro atendimento registrado. Mediana: metade dos leads foi atendida antes desse tempo.',
        'Mediana: ' . dash_duracao($k['tempo_mediana'])],
    ['Visitas agendadas', dash_num($k['visitas_agendadas']), dash_variacao($k['visitas_agendadas'], $a['visitas_agendadas']), 'calendar',
        'Visitas marcadas na agenda no período (um reagendamento não conta duas vezes).'],
    ['Visitas realizadas', dash_num($k['visitas_realizadas']), dash_variacao($k['visitas_realizadas'], $a['visitas_realizadas']), 'building',
        'Visitas marcadas como realizadas no período.'],
    ['Contratos assinados', dash_num($k['contratos']), dash_variacao($k['contratos'], $a['contratos']), 'check',
        'Leads que chegaram à etapa "Contrato assinado" no período.'],
    ['Taxa de conversão', dash_pct($k['taxa_conversao'], 1), dash_variacao($k['taxa_conversao'], $a['taxa_conversao'], 'pp'), 'target',
        'Contratos assinados no período ÷ leads recebidos no período.'],
    ['Perdidos por inatividade', dash_num($k['perdidos_inatividade']), dash_variacao($k['perdidos_inatividade'], $a['perdidos_inatividade'], 'qtd', true), 'clock',
        'Leads retirados do corretor pela recuperação automática por falta de feedback. Quanto menor, melhor.'],
];

$urlPor = fn (string $d) => '?' . http_build_query($publicos + ['por' => $d]) . '#produtividade';
$maxFunil = max(1, ...array_column($funil, 'total'));

$pageTitle = 'Dashboard — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div>
        <div class="eyebrow">Visão do gestor · <?= e(rbac_rotulo_escopo($usuario['escopo'])) ?></div>
        <h1>Dashboard</h1>
        <p><?= e(dash_periodos()[$f['periodo']]) ?>: <b><?= e($f['rotulo']) ?></b> · comparado com <?= e($f['rotulo_anterior']) ?></p>
    </div>
    <div class="actions">
        <a class="btn btn-outline" href="/pages/relatorios.php?<?= e(http_build_query($publicos)) ?>"><?= icon('chart') ?>Perdas e transferências</a>
        <?php if ($podeExportar): ?>
            <?php dash_exportar('modal-exportar', [
                'quebra' => 'Produtividade por ' . mb_strtolower(dash_dimensoes()[$por]) . ' (tabela abaixo)',
                'quebra_empreendimento' => 'Resultados por empreendimento',
                'quebra_campanha' => 'Resultados por campanha',
                'roleta' => 'Resultados por roleta',
            ], $publicos + ['por' => $por]); ?>
        <?php endif; ?>
    </div>
</div>

<?php dash_form_filtros($f, $opcoes, ['por' => $por]); ?>

<?php if ($k['sem_atendimento'] > 0): ?>
    <div class="alert-strip dash-alerta" role="status"><?= icon('alert') ?>
        <span><b><?= dash_num($k['sem_atendimento']) ?> lead<?= $k['sem_atendimento'] === 1 ? '' : 's' ?></b> com corretor há mais de <?= $horas ?> h sem nenhum contato registrado.</span>
    </div>
<?php endif; ?>

<div class="kpi-grid kpi-grid-5 dash-kpis">
    <?php foreach ($kpis as $kp): [$rot, $val, [$varTxt, $varCls], $ic, $ajuda] = $kp; ?>
        <div class="kpi">
            <div class="kpi-top"><span class="label"><?= e($rot) ?> <?= dash_ajuda($ajuda) ?></span><div class="kpi-icon"><?= icon($ic) ?></div></div>
            <div class="kpi-value"><?= e($val) ?></div>
            <?php if (!empty($kp[5])): ?><div class="kpi-sub"><?= e($kp[5]) ?></div><?php endif; ?>
            <div class="kpi-sub <?= $varCls ?>"><?= e($varTxt) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="dash-grid">
    <div class="card dash-span-2">
        <div class="card-head"><div><h2>Leads recebidos por <?= $f['dias'] > 92 ? 'semana' : 'dia' ?></h2><p>Entrada de leads no período.</p></div><span class="pill pill-muted"><?= dash_num($k['recebidos']) ?> no total</span></div>
        <?php dash_grafico_barras($serie, 'Leads recebidos por ' . ($f['dias'] > 92 ? 'semana' : 'dia')); ?>
    </div>
    <div class="card">
        <div class="card-head"><div><h2>Origem dos leads <?= dash_ajuda('De onde vieram os leads recebidos no período.') ?></h2></div></div>
        <?php dash_rosca($porOrigem, 'Leads por origem'); ?>
    </div>
    <div class="card">
        <div class="card-head"><div><h2>Funil de vendas <?= dash_ajuda('Em que etapa estão hoje os leads recebidos no período.') ?></h2><p>Onde estão hoje os leads do período.</p></div></div>
        <div class="funnel" role="list" aria-label="Funil de vendas">
            <?php foreach ($funil as $et): $vermelho = in_array($et['categoria'], ['sem_interesse', 'perdido'], true); ?>
                <div class="funnel-row" role="listitem">
                    <span class="funnel-label"><?= e($et['rotulo']) ?></span>
                    <div class="funnel-track<?= $vermelho ? ' funnel-track-red' : '' ?>"><div class="funnel-fill<?= $vermelho ? ' funnel-fill-red' : '' ?>" style="width: <?= round($et['total'] / $maxFunil * 100) ?>%"></div></div>
                    <span class="funnel-value"><?= dash_num($et['total']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><div><h2>Por empreendimento <?= dash_ajuda('Leads recebidos no período em cada empreendimento. A tabela completa está em Produtividade → Empreendimento.') ?></h2></div>
            <a class="link-btn" href="<?= e($urlPor('empreendimento')) ?>">Ver tabela</a></div>
        <?php dash_barras_horizontais($porEmp, 'Leads por empreendimento'); ?>
    </div>
    <div class="card">
        <div class="card-head"><div><h2>Por campanha <?= dash_ajuda('Leads recebidos e contratos por campanha de origem (Meta Ads, importação ou informada no cadastro).') ?></h2></div>
            <a class="link-btn" href="<?= e($urlPor('campanha')) ?>">Ver tabela</a></div>
        <?php dash_barras_horizontais($porCampanha, 'Leads por campanha'); ?>
    </div>
</div>

<div class="card dash-section" id="produtividade">
    <div class="card-head">
        <div><h2>Produtividade <?= dash_ajuda('Cada linha mostra os leads recebidos no período por quem (ou onde) estão hoje, e o que aconteceu no período: visitas, contratos e perdas. Clique no título de uma coluna para ordenar.') ?></h2>
            <p>Agrupar por:</p></div>
    </div>
    <nav class="segmented-inline dash-por" aria-label="Agrupar produtividade por">
        <?php foreach (dash_dimensoes() as $d => $rot): ?>
            <a class="seg-link<?= $por === $d ? ' is-active' : '' ?>" href="<?= e($urlPor($d)) ?>"<?= $por === $d ? ' aria-current="true"' : '' ?>><?= e($rot) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php dash_tabela('tabela-produtividade', dash_colunas_quebra($por), $quebra, $totais, 'recebidos'); ?>
</div>

<div class="card dash-section" id="roletas">
    <div class="card-head">
        <div><h2>Resultados por roleta <?= dash_ajuda('Leads entregues por cada roleta no período e, desses, quantos o corretor já atendeu e quantos viraram contrato.') ?></h2></div>
        <span class="pill <?= $filaAgora ? 'pill-amber' : 'pill-muted' ?>"><?= dash_num($filaAgora) ?> na fila de espera agora</span>
    </div>
    <?php dash_tabela('tabela-roletas', dash_colunas_roleta(), $porRoleta, null, 'entregues', 'Nenhum lead entregue por roleta no período.'); ?>
</div>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
