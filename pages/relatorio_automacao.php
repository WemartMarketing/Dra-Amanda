<?php
/**
 * Resultados das automações do WhatsApp (Fase 7, seção 15): jornadas e
 * campanhas, com filtros e exportação para Excel. Números agregados, no
 * recorte de leads de quem consulta. Cálculos em includes/relatorio_automacao.php.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/lead_componentes.php';
require_once __DIR__ . '/../includes/dashboard_ui.php';
require_once __DIR__ . '/../includes/relatorio_automacao.php';
require_once __DIR__ . '/../includes/whatsapp_conversas.php';

$usuario = auth_require();
$crumb = 'Resultados das automações';
$activeNav = 'whatsapp';
if (!usuario_tem_permissao($usuario, 'visualizar_indicadores')
    || !(usuario_tem_permissao($usuario, 'whatsapp_configurar') || usuario_tem_permissao($usuario, 'gerenciar_campanhas_wa'))) {
    pagina_acesso_restrito($crumb, $activeNav, 'Visualizar indicadores e configurar WhatsApp ou campanhas');
}
$f = dash_filtros($usuario, $_GET);
$op = dash_opcoes($usuario);
$r = relatorio_automacao($usuario, $f);
$ind = $r['indicadores'];
$publicos = array_intersect_key(dash_filtros_publicos($f), array_flip(['periodo', 'de', 'ate', 'empreendimento', 'equipe']));
$podeExportar = usuario_tem_permissao($usuario, 'exportar_relatorios');
$rs = fn ($v) => $v === null ? '—' : 'R$ ' . number_format((float) $v, 2, ',', '.');
$paraTela = function (array $linhas) use ($rs) {
    return array_map(function ($l) use ($rs) {
        foreach (['custo', 'custo_por_resposta'] as $k) {
            if (array_key_exists($k, $l)) {
                $l[$k] = $rs($l[$k] === null ? null : (float) $l[$k]);
            }
        }
        return $l;
    }, $linhas);
};
$colTela = function (string $t) {
    $c = relaut_colunas($t);
    foreach (['custo', 'custo_por_resposta'] as $k) {
        if (isset($c[$k])) {
            $c[$k][1] = 'texto';
        }
    }
    return $c;
};

$pageTitle = 'Resultados das automações — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div>
        <div class="eyebrow">WhatsApp</div>
        <h1>Resultados das automações</h1>
        <p><?= e(dash_periodos()[$f['periodo']]) ?>: <b><?= e($f['rotulo']) ?></b> · leads cuja primeira mensagem automática saiu no período.</p>
    </div>
    <?php if ($podeExportar): ?>
        <div class="actions">
            <?php dash_exportar('modal-exportar', [
                'automacao_indicadores' => 'Indicadores gerais', 'automacao_empreendimento' => 'Por empreendimento', 'automacao_equipe' => 'Por equipe',
                'automacao_jornada' => 'Por jornada', 'automacao_campanha' => 'Por campanha',
            ], $publicos, false, 'Exportar Excel'); ?>
        </div>
    <?php endif; ?>
</div>
<?php wa_render_abas($usuario, 'relatorios'); ?>

<form class="filters-row dash-filtros" method="get" aria-label="Filtros">
    <label class="sr-only" for="df-periodo">Período</label>
    <select class="select-filter" id="df-periodo" name="periodo" data-periodo>
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

<div class="kpi-grid dash-kpis relaut-kpis">
    <div class="kpi"><div class="kpi-top"><span class="label">Receberam a 1ª mensagem <?= dash_ajuda('Leads que receberam a primeira mensagem automática (jornada ou campanha) no período.') ?></span><div class="kpi-icon"><?= icon('whatsapp') ?></div></div>
        <div class="kpi-value"><?= dash_num($ind['leads']) ?></div><div class="kpi-sub"><?= $ind['simuladas'] ? dash_num($ind['simuladas']) . ' mensagem(ns) simulada(s) no modo de teste' : 'mensagens reais' ?></div></div>
    <div class="kpi"><div class="kpi-top"><span class="label">Taxa de entrega <?= dash_ajuda('Mensagens reais confirmadas como entregues/lidas pela Meta, sobre as enviadas e falhas. Simuladas não contam.') ?></span><div class="kpi-icon"><?= icon('check') ?></div></div>
        <div class="kpi-value"><?= $ind['taxa_entrega'] === null ? '—' : dash_pct($ind['taxa_entrega']) ?></div><div class="kpi-sub"><?= dash_num($ind['falharam']) ?> falha(s)</div></div>
    <div class="kpi"><div class="kpi-top"><span class="label">Taxa de resposta <?= dash_ajuda('Dos leads com automação, quantos escreveram em até 7 dias depois da primeira mensagem.') ?></span><div class="kpi-icon"><?= icon('users') ?></div></div>
        <div class="kpi-value"><?= $ind['taxa_resposta'] === null ? '—' : dash_pct($ind['taxa_resposta']) ?></div><div class="kpi-sub"><?= dash_num($ind['responderam']) ?> responderam · mediana <?= e(dash_duracao($ind['tempo_resposta'] === null ? null : (float) $ind['tempo_resposta'])) ?></div></div>
    <div class="kpi"><div class="kpi-top"><span class="label">Custo estimado <?= dash_ajuda('Soma das tarifas estimadas das mensagens cobradas (jornadas + campanhas). Simuladas custam zero.') ?></span><div class="kpi-icon"><?= icon('chart') ?></div></div>
        <div class="kpi-value"><?= e($rs($ind['custo_total'])) ?></div><div class="kpi-sub">por resposta: <?= e($rs($ind['custo_por_resposta'])) ?></div></div>
</div>

<div class="card relaut-lista">
    <div class="card-head"><div><h2>Indicadores do período</h2><p>Resultado “depois da automação” = aconteceu depois da primeira mensagem automática. Mostra influência, <b>não</b> atribui a venda à última mensagem.</p></div></div>
    <div class="relaut-grid">
        <?php foreach (relaut_indicadores_linhas($ind) as [$rot, $val]): ?>
            <div class="relaut-item"><span><?= e($rot) ?></span><b><?= e($val) ?></b></div>
        <?php endforeach; ?>
    </div>
</div>

<div class="card"><div class="card-head"><div><h2>Por empreendimento</h2></div></div><?php dash_tabela('t-emp', relaut_colunas('empreendimento'), $r['por_empreendimento'], null, 'leads', 'Nenhum lead recebeu automação no período.'); ?></div>
<div class="card"><div class="card-head"><div><h2>Por equipe</h2></div></div><?php dash_tabela('t-eq', relaut_colunas('equipe'), $r['por_equipe'], null, 'leads', 'Nenhum lead recebeu automação no período.'); ?></div>
<div class="card"><div class="card-head"><div><h2>Por jornada</h2><p>Leads que entraram em cada jornada no período.</p></div></div><?php dash_tabela('t-jor', $colTela('jornada'), $paraTela($r['por_jornada']), null, 'entraram', 'Nenhum lead entrou em jornadas no período.'); ?></div>
<div class="card"><div class="card-head"><div><h2>Por campanha</h2><p>Campanhas criadas no período.</p></div></div><?php dash_tabela('t-camp', $colTela('campanha'), $paraTela($r['por_campanha']), null, null, 'Nenhuma campanha no período.'); ?></div>
<?php require __DIR__ . '/../includes/layout_end.php'; ?>
