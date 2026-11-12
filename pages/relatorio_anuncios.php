<?php
/**
 * Leads por campanha e por anúncio (Meta Ads). Só números do CRM; sem
 * investimento nem CPL (não integrados). Mesmo recorte do Dashboard.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/lead_componentes.php';
require_once __DIR__ . '/../includes/dashboard_ui.php';
require_once __DIR__ . '/../includes/relatorio_anuncios.php';

$usuario = auth_require();
$crumb = 'Leads por anúncio';
$activeNav = 'dashboard';
if (!usuario_tem_permissao($usuario, 'visualizar_indicadores')) {
    pagina_acesso_restrito($crumb, $activeNav, 'Visualizar indicadores/dashboard');
}
$f = dash_filtros($usuario, $_GET);
$op = dash_opcoes($usuario);
$instalado = modulo_tabela_existe('meta_anuncios');
$porCampanha = $instalado ? relanu_linhas($usuario, $f, 'campanha') : [];
$porAnuncio = $instalado ? relanu_linhas($usuario, $f, 'anuncio') : [];
$publicos = array_intersect_key(dash_filtros_publicos($f), array_flip(['periodo', 'de', 'ate', 'empreendimento', 'equipe']));
$podeExportar = usuario_tem_permissao($usuario, 'exportar_relatorios');

$pageTitle = 'Leads por anúncio — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div>
        <div class="eyebrow">Relatórios · Meta Ads</div>
        <h1>Leads por campanha e anúncio</h1>
        <p><?= e(dash_periodos()[$f['periodo']]) ?>: <b><?= e($f['rotulo']) ?></b> · leads do Meta Ads que chegaram no período. Só dados do CRM — investimento e custo por lead não estão integrados.</p>
    </div>
    <div class="actions">
        <a class="btn btn-outline" href="/pages/dashboard.php?<?= e(http_build_query($publicos)) ?>"><?= icon('chart') ?>Dashboard</a>
        <?php if ($podeExportar && $instalado): ?>
            <?php dash_exportar('modal-exportar', ['anuncios_campanha' => 'Leads por campanha', 'anuncios_anuncio' => 'Leads por anúncio'], $publicos, false, 'Exportar Excel'); ?>
        <?php endif; ?>
    </div>
</div>
<form class="filters-row dash-filtros" method="get" aria-label="Filtros">
    <select class="select-filter" name="periodo" data-periodo aria-label="Período">
        <?php foreach (dash_periodos() as $k => $rot): ?><option value="<?= $k ?>" <?= $f['periodo'] === $k ? 'selected' : '' ?>><?= e($rot) ?></option><?php endforeach; ?>
    </select>
    <span class="dash-datas" data-periodo-datas <?= $f['periodo'] === 'personalizado' ? '' : 'hidden' ?>>
        <input class="select-filter" type="date" name="de" value="<?= e($f['de']) ?>" aria-label="De"> <span aria-hidden="true">até</span>
        <input class="select-filter" type="date" name="ate" value="<?= e($f['ate']) ?>" aria-label="Até">
    </span>
    <select class="select-filter" name="empreendimento" aria-label="Empreendimento"><option value="">Todos os empreendimentos</option>
        <?php foreach ($op['empreendimentos'] as $x): ?><option value="<?= (int) $x['id'] ?>" <?= (int) $f['empreendimento'] === (int) $x['id'] ? 'selected' : '' ?>><?= e($x['nome']) ?></option><?php endforeach; ?></select>
    <?php if (count($op['equipes']) > 1): ?>
        <select class="select-filter" name="equipe" aria-label="Equipe"><option value="">Todas as equipes</option>
            <?php foreach ($op['equipes'] as $x): ?><option value="<?= (int) $x['id'] ?>" <?= (int) $f['equipe'] === (int) $x['id'] ? 'selected' : '' ?>><?= e($x['nome']) ?></option><?php endforeach; ?></select>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary btn-sm"><?= icon('check') ?>Aplicar</button>
</form>
<?php if (!$instalado): ?>
    <div class="card"><p class="empty-note">Este relatório fica disponível depois da atualização do banco (migração 011).</p></div>
<?php else: ?>
    <div class="card"><div class="card-head"><div><h2>Por campanha</h2></div></div><?php dash_tabela('t-camp', relanu_colunas('campanha'), $porCampanha, $porCampanha ? relanu_totais($porCampanha) : null, 'leads', 'Nenhum lead do Meta Ads no período.'); ?></div>
    <div class="card" style="margin-top:20px;"><div class="card-head"><div><h2>Por anúncio</h2><p>O anúncio é o que estava ligado ao formulário enviado pelo cliente.</p></div></div><?php dash_tabela('t-anu', relanu_colunas('anuncio'), $porAnuncio, null, 'leads', 'Nenhum lead do Meta Ads no período.'); ?></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/layout_end.php'; ?>
