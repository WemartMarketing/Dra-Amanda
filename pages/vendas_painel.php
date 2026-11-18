<?php
/**
 * Inteligência de Vendas → Painel e relatórios. Indicadores da conciliação
 * e relatórios especializados, todos a partir de vendas_fatos() (uma fonte
 * só). Números agregados; a tabela de resultados mostra a venda importada e
 * leva à Jornada, que aplica a regra de acesso da carteira atual.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/estrutura.php';
require_once __DIR__ . '/../includes/lead_componentes.php';
require_once __DIR__ . '/../includes/vendas/painel.php';

$usuario = auth_require();
$crumb = 'Inteligência de Vendas';
$activeNav = 'relatorios';
if (!empty($usuario['eh_superadmin']) || !usuario_tem_permissao($usuario, 'ver_inteligencia_vendas')) {
    pagina_acesso_restrito($crumb, $activeNav, 'Ver a Inteligência de Vendas');
}
$empresaId = (int) $usuario['empresa_ativa_id'];
$abas = ['visao' => 'Visão geral', 'encontradas' => 'Encontradas na base', 'jornada' => 'Jornada até a compra', 'empreendimentos' => 'Entre empreendimentos',
         'anuncios' => 'Anúncios', 'remarketing' => 'Após remarketing', 'redistribuicao' => 'Após redistribuição', 'canais' => 'Outros canais',
         'produtividade' => 'Produtividade', 'resultados' => 'Resultados'];
$aba = isset($abas[$_GET['aba'] ?? '']) ? $_GET['aba'] : 'visao';
$f = vendas_painel_filtros($empresaId, $_GET);
$r = vendas_fatos($f);
$fatos = $r['fatos'];
$ind = vendas_indicadores($fatos);
$op = vendas_painel_opcoes($empresaId);
$nomesEmp = array_column($op['empreendimentos'], 'nome', 'id');
$nomesUsr = array_column(db()->query("SELECT id, nome FROM usuarios WHERE empresa_id = $empresaId")->fetchAll(), 'nome', 'id');
$sit = vendas_situacoes();
$cls = vendas_classificacoes_comerciais();
$brl = fn ($v) => $v === null ? '—' : 'R$ ' . number_format((float) $v, 2, ',', '.');
$brlC = fn ($v) => $v >= 1e6 ? 'R$ ' . number_format($v / 1e6, 2, ',', '.') . ' mi' : $brl($v);
$pct = fn ($a, $b) => $b ? number_format(100 * $a / $b, 1, ',', '.') . '%' : '—';
$num = fn ($v) => $v === null ? '—' : number_format((float) $v, is_float($v) && floor($v) != $v ? 1 : 0, ',', '.');
$data = fn (?string $d) => $d ? date('d/m/Y', strtotime($d)) : '—';
$qs = fn (array $mud) => '?' . http_build_query(array_filter(array_merge(array_intersect_key($_GET, array_flip(['periodo', 'ini', 'fim', 'empreendimento', 'equipe', 'corretor', 'origem', 'situacao', 'aba'])), $mud), fn ($x) => $x !== null && $x !== ''));
$barras = function (array $linhas, string $rot, string $val, ?callable $fmt = null) {
    $max = max(array_merge([1], array_column($linhas, $val)));
    echo '<ul class="jv-barras vp-barras">';
    foreach ($linhas as $l) {
        echo '<li><span>' . e((string) $l[$rot]) . '</span><span class="jv-barra" aria-hidden="true"><i style="width: ' . round(100 * $l[$val] / $max) . '%"></i></span><b>' . e($fmt ? $fmt($l) : (string) $l[$val]) . '</b></li>';
    }
    echo '</ul>';
};
$nota = function (string $texto) use ($f) {
    echo '<p class="muted vendas-metodo"><b>Fonte:</b> vendas importadas do sistema comercial (só situações marcadas como venda) cruzadas com o CRM, regras de correspondência ' . e(VENDAS_REGRAS_VERSAO)
        . '. <b>Período:</b> data da venda, ' . e($f['periodo']['rotulo'] ?? '') . '. <b>Gerado em</b> ' . date('d/m/Y H:i') . '. ' . $texto . '</p>';
};

$pageTitle = 'Inteligência de Vendas — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div>
        <a class="back-link" href="/pages/relatorios_central.php"><?= icon('arrow-r') ?>Voltar para os relatórios</a>
        <div class="eyebrow">Relatórios · Inteligência de Vendas</div>
        <h1>Painel de vendas × CRM</h1>
        <p>O que as vendas do sistema comercial revelam sobre a jornada no CRM: quem já estava na base, de onde veio, quanto tempo levou e por quais caminhos passou. Associação no tempo não é causa; nada aqui atribui crédito ou culpa.</p>
    </div>
    <div class="actions">
        <a class="btn btn-outline" href="/pages/vendas_conciliacao.php"><?= icon('check') ?>Conciliação</a>
        <?php if (usuario_tem_permissao($usuario, 'importar_vendas')): ?><a class="btn btn-primary" href="/pages/vendas_importar.php"><?= icon('upload') ?>Importar vendas</a><?php endif; ?>
    </div>
</div>

<?php $ativos = count(array_filter([$f['empreendimento'], $f['equipe'], $f['corretor'], $f['origem'], $f['situacao']])); ?>
<details class="vp-filtros-box" data-vp-filtros<?= $ativos ? ' open' : '' ?>>
<summary class="btn btn-outline"><?= icon('filter') ?>Filtros · <?= e($f['periodo']['rotulo'] ?? '') ?><?= $ativos ? ' · ' . $ativos . ' ativo(s)' : '' ?></summary>
<form method="get" class="card vp-filtros" aria-label="Filtros">
    <input type="hidden" name="aba" value="<?= e($aba) ?>">
    <div class="field"><label>Período<select name="periodo" data-vp-periodo><?php foreach (relatorio_periodos() as $k => $rot): ?><option value="<?= $k ?>" <?= $k === $f['periodo_chave'] ? 'selected' : '' ?>><?= e($rot) ?></option><?php endforeach; ?></select></label></div>
    <div class="field" data-vp-datas <?= $f['periodo_chave'] === 'personalizado' ? '' : 'hidden' ?>><label>De<input type="date" name="ini" value="<?= e((string) ($_GET['ini'] ?? '')) ?>"></label></div>
    <div class="field" data-vp-datas <?= $f['periodo_chave'] === 'personalizado' ? '' : 'hidden' ?>><label>Até<input type="date" name="fim" value="<?= e((string) ($_GET['fim'] ?? '')) ?>"></label></div>
    <?php foreach (['empreendimento' => ['Empreendimento comprado', 'empreendimentos', 'Todos'], 'equipe' => ['Equipe (na data da venda)', 'equipes', 'Todas'], 'corretor' => ['Corretor (na data da venda)', 'corretores', 'Todos'], 'origem' => ['Origem no CRM', 'origens', 'Todas']] as $k => [$rot, $lista, $todos]): ?>
        <div class="field"><label><?= e($rot) ?><select name="<?= $k ?>"><option value=""><?= e($todos) ?></option><?php foreach ($op[$lista] as $o): ?><option value="<?= (int) $o['id'] ?>" <?= $f[$k] === (int) $o['id'] ? 'selected' : '' ?>><?= e($o['nome']) ?></option><?php endforeach; ?></select></label></div>
    <?php endforeach; ?>
    <div class="field"><label>Situação da conciliação<select name="situacao"><option value="">Todas</option><?php foreach ($sit as $k => [$rot]): ?><option value="<?= $k ?>" <?= $f['situacao'] === $k ? 'selected' : '' ?>><?= e($rot) ?></option><?php endforeach; ?></select></label></div>
    <div class="vp-filtros-acoes"><button class="btn btn-primary" type="submit"><?= icon('filter') ?>Aplicar</button><a class="link-btn" href="?aba=<?= e($aba) ?>">Limpar</a></div>
</form>
</details>

<nav class="segmented-inline vendas-abas vp-abas" aria-label="Relatórios">
    <?php foreach ($abas as $k => $rot): ?><a class="seg-link<?= $aba === $k ? ' is-active' : '' ?>" href="<?= e($qs(['aba' => $k, 'p' => null])) ?>"><?= e($rot) ?></a><?php endforeach; ?>
</nav>

<?php if ($r['erro']): ?>
    <div class="alert-strip" role="alert"><?= icon('alert') ?><span><?= e($r['erro']) ?></span></div>
<?php elseif (!$fatos): ?>
    <div class="card stub-card"><div class="stub-icon"><?= icon('info') ?></div><h2>Nenhuma venda neste período e seleção</h2><p>Ajuste os filtros ou importe a planilha de vendas.</p></div>
<?php elseif ($aba === 'visao'): ?>
    <div class="kpi-grid vendas-kpis">
        <div class="kpi"><div class="kpi-top"><span class="label">Vendas importadas</span><div class="kpi-icon"><?= icon('file') ?></div></div><div class="kpi-value"><?= $ind['vendas'] ?></div><div class="kpi-sub">VGV <?= e($brlC($ind['valor_total'])) ?></div></div>
        <div class="kpi"><div class="kpi-top"><span class="label">Já estavam no CRM</span><div class="kpi-icon"><?= icon('check') ?></div></div><div class="kpi-value txt-ok"><?= $ind['encontradas'] ?></div><div class="kpi-sub"><?= $pct($ind['encontradas'], $ind['vendas']) ?> das vendas · <?= e($brlC($ind['valor_encontradas'])) ?></div></div>
        <div class="kpi"><div class="kpi-top"><span class="label">Tempo até a compra</span><div class="kpi-icon"><?= icon('clock') ?></div></div><div class="kpi-value"><?= $num($ind['jornada_mediana']) ?></div><div class="kpi-sub">dias (mediana) · média <?= $num($ind['jornada_media']) ?></div></div>
        <div class="kpi"><div class="kpi-top"><span class="label">Outro canal, com histórico</span><div class="kpi-icon"><?= icon('externo') ?></div></div><div class="kpi-value"><?= $ind['outro_canal'] ?></div><div class="kpi-sub">vendidas por outro responsável/canal</div></div>
    </div>
    <div class="grid-2">
        <section class="card">
            <div class="card-head"><div><h2>Conciliação</h2><p>Como as vendas do período se ligaram ao CRM.</p></div></div>
            <ul class="vp-lista">
                <li><span>Encontradas — telefone e e-mail</span><b><?= $ind['por_ambos'] ?></b></li>
                <li><span>Encontradas — só telefone</span><b><?= $ind['por_telefone'] ?></b></li>
                <li><span>Encontradas — só e-mail</span><b><?= $ind['por_email'] ?></b></li>
                <li><span>Encontradas — confirmadas na revisão</span><b><?= $ind['por_revisao'] ?></b></li>
                <li><span>Ambíguas (aguardando revisão)</span><b class="<?= $ind['ambiguas'] ? 'txt-warn' : '' ?>"><?= $ind['ambiguas'] ?></b></li>
                <li><span>Entraram no CRM só depois da venda</span><b><?= $ind['lead_posterior'] ?></b></li>
                <li><span>Sem correspondência</span><b><?= $ind['sem'] ?></b></li>
            </ul>
        </section>
        <section class="card">
            <div class="card-head"><div><h2>Caminhos das vendas encontradas</h2><p>Quantas das <?= $ind['encontradas'] ?> vendas encontradas passaram por cada situação antes da compra.</p></div></div>
            <ul class="vp-lista">
                <li><span>Cliente com mais de uma oportunidade</span><b><?= $ind['multiplas_oportunidades'] ?></b></li>
                <li><span>Comprou empreendimento diferente do 1º interesse</span><b><?= $ind['empreendimento_diferente'] ?></b></li>
                <li><span>Passou pela oferta ativa</span><b><?= $ind['oferta_ativa'] ?></b></li>
                <li><span>Recebeu remarketing antes da compra</span><b><?= $ind['remarketing'] ?></b></li>
                <li><span>Passou por transferência/redistribuição</span><b><?= $ind['redistribuicao'] ?></b></li>
                <li><span>Vendida por outro canal (com histórico no CRM)</span><b><?= $ind['outro_canal'] ?></b></li>
            </ul>
        </section>
    </div>
    <?php $nota('"Encontradas" = ligadas ao CRM sem conflito (ou confirmadas na revisão) e com entrada no CRM até o dia da venda. VGV é o valor de venda das unidades, não receita.'); ?>

<?php elseif ($aba === 'encontradas'): $porEmp = vendas_agrupar($fatos, fn ($x) => $x['emp_comprado']); $porMidia = vendas_agrupar($fatos, fn ($x) => $x['midia']); ?>
    <section class="card">
        <div class="card-head"><div><h2>Vendas encontradas na base de leads</h2><p><?= $ind['encontradas'] ?> de <?= $ind['vendas'] ?> vendas (<?= $pct($ind['encontradas'], $ind['vendas']) ?>) já estavam no CRM antes da compra.</p></div></div>
        <div class="table-wrap"><table class="report-table"><thead><tr><th scope="col">Empreendimento comprado</th><th scope="col" class="num">Vendas</th><th scope="col" class="num">Encontradas</th><th scope="col" class="num">%</th><th scope="col" class="num">VGV</th><th scope="col" class="num">VGV encontradas</th></tr></thead><tbody>
            <?php foreach ($porEmp as $l): ?><tr><td data-rotulo="Empreendimento"><?= e($l['chave']) ?></td><td class="num" data-rotulo="Vendas"><?= $l['vendas'] ?></td><td class="num" data-rotulo="Encontradas"><?= $l['encontradas'] ?></td><td class="num" data-rotulo="%"><?= $pct($l['encontradas'], $l['vendas']) ?></td><td class="num" data-rotulo="VGV"><?= e($brlC($l['valor'])) ?></td><td class="num" data-rotulo="VGV encontradas"><?= e($brlC($l['valor_encontradas'])) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
    <section class="card">
        <div class="card-head"><div><h2>Por mídia declarada na venda</h2><p>A mídia é a do sistema comercial; não substitui a origem do lead no CRM.</p></div></div>
        <?php $barras($porMidia, 'chave', 'vendas', fn ($l) => $l['vendas'] . ' · ' . $l['encontradas'] . ' no CRM'); ?>
    </section>
    <?php $nota('Vendas com correspondência ambígua não contam como encontradas até a revisão.'); ?>

<?php elseif ($aba === 'jornada'): $faixas = vendas_faixas_jornada($fatos); $porOrigem = vendas_agrupar(array_filter($fatos, fn ($x) => $x['encontrada']), fn ($x) => $x['origem']); ?>
    <div class="kpi-grid vendas-kpis">
        <div class="kpi"><div class="kpi-top"><span class="label">Mediana</span><div class="kpi-icon"><?= icon('clock') ?></div></div><div class="kpi-value"><?= $num($ind['jornada_mediana']) ?></div><div class="kpi-sub">dias da 1ª entrada à venda</div></div>
        <div class="kpi"><div class="kpi-top"><span class="label">Média</span><div class="kpi-icon"><?= icon('chart') ?></div></div><div class="kpi-value"><?= $num($ind['jornada_media']) ?></div><div class="kpi-sub">dias</div></div>
        <div class="kpi"><div class="kpi-top"><span class="label">Vendas consideradas</span><div class="kpi-icon"><?= icon('check') ?></div></div><div class="kpi-value"><?= $ind['encontradas'] ?></div><div class="kpi-sub">encontradas no CRM</div></div>
    </div>
    <div class="grid-2">
        <section class="card"><div class="card-head"><div><h2>Distribuição por faixa</h2></div></div><?php $barras($faixas, 'faixa', 'vendas'); ?></section>
        <section class="card"><div class="card-head"><div><h2>Por origem no CRM (1ª entrada)</h2></div></div>
            <div class="table-wrap"><table class="report-table"><thead><tr><th scope="col">Origem</th><th scope="col" class="num">Vendas</th><th scope="col" class="num">Mediana</th><th scope="col" class="num">Média</th></tr></thead><tbody>
                <?php foreach ($porOrigem as $l): ?><tr><td data-rotulo="Origem"><?= e($l['chave']) ?></td><td class="num" data-rotulo="Vendas"><?= $l['vendas'] ?></td><td class="num" data-rotulo="Mediana"><?= $num($l['dias_mediana']) ?> d</td><td class="num" data-rotulo="Média"><?= $num($l['dias_media']) ?> d</td></tr><?php endforeach; ?>
            </tbody></table></div></section>
    </div>
    <?php $nota('Tempo de jornada = dias corridos da 1ª entrada comprovada no CRM (cadastro ou formulário do anúncio) até a data da venda. Não é tempo de atendimento ativo.'); ?>

<?php elseif ($aba === 'empreendimentos'): $mov = vendas_movimentos($fatos, $nomesEmp); $semEq = count(array_filter($fatos, fn ($x) => $x['movimento'] === 'sem_equivalencia')); ?>
    <section class="card">
        <div class="card-head"><div><h2>Conversões entre empreendimentos</h2><p>1º interesse registrado no CRM → empreendimento comprado. <?= $ind['empreendimento_diferente'] ?> venda(s) em empreendimento diferente do interesse inicial.</p></div></div>
        <?php if (!$mov): ?><p class="empty-note">Sem vendas encontradas com os dois empreendimentos conhecidos.</p><?php else: ?>
            <div class="table-wrap"><table class="report-table"><thead><tr><th scope="col">1º interesse</th><th scope="col"></th><th scope="col">Comprou</th><th scope="col" class="num">Vendas</th><th scope="col" class="num">Com outros interesses no meio</th><th scope="col" class="num">VGV</th></tr></thead><tbody>
                <?php foreach ($mov as $m): ?><tr><td data-rotulo="1º interesse"><?= e($m['de']) ?></td><td aria-hidden="true"><?= $m['mesmo'] ? '<span class="pill pill-green">mesmo</span>' : '→' ?></td><td data-rotulo="Comprou"><?= e($m['para']) ?></td><td class="num" data-rotulo="Vendas"><?= $m['vendas'] ?></td><td class="num" data-rotulo="Com outros interesses"><?= $m['com_intermediarios'] ?></td><td class="num" data-rotulo="VGV"><?= e($brlC($m['valor'])) ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
        <?php if ($semEq): ?><p class="muted"><?= $semEq ?> venda(s) encontrada(s) ficaram de fora porque o empreendimento comprado não tem equivalência no CRM. <a href="/pages/vendas_equivalencias.php">Cadastrar equivalências</a>.</p><?php endif; ?>
    </section>
    <?php $nota('Quando o cliente teve vários interesses, a tabela usa o 1º; os demais aparecem na Jornada. Nenhum empreendimento é tratado como "responsável" pela venda.'); ?>

<?php elseif ($aba === 'anuncios'): $comAn = array_filter($fatos, fn ($x) => $x['encontrada'] && ($x['anuncio'] || $x['campanha'])); $porCamp = vendas_agrupar($comAn, fn ($x) => $x['campanha']); $porAn = vendas_agrupar(array_filter($comAn, fn ($x) => $x['anuncio']), fn ($x) => $x['anuncio']); ?>
    <section class="card">
        <div class="card-head"><div><h2>Campanhas e anúncios dos clientes que compraram</h2><p><?= count($comAn) ?> das <?= $ind['encontradas'] ?> vendas encontradas têm campanha ou anúncio registrado no 1º cadastro.</p></div></div>
        <div class="grid-2">
            <div><div class="subhead">Por campanha</div><?php $porCamp ? $barras($porCamp, 'chave', 'vendas', fn ($l) => $l['vendas'] . ' · ' . $brlC($l['valor'])) : print('<p class="empty-note">Nenhuma.</p>'); ?></div>
            <div><div class="subhead">Por anúncio (Meta Ads)</div><?php $porAn ? $barras($porAn, 'chave', 'vendas') : print('<p class="empty-note">Nenhum anúncio registrado.</p>'); ?></div>
        </div>
    </section>
    <?php $nota('Mostra a campanha/anúncio do 1º cadastro do cliente que depois comprou: associação no tempo, não prova de que o anúncio causou a venda.'); ?>

<?php elseif ($aba === 'remarketing'): $comR = array_values(array_filter($fatos, fn ($x) => $x['remarketing'] > 0)); $dist = []; foreach ($comR as $x) { $d = vendas_dias_entre($x['ultima_campanha'], $x['data']); $k = $d <= 7 ? 'até 7 dias' : ($d <= 30 ? '8 a 30 dias' : ($d <= 90 ? '31 a 90 dias' : 'mais de 90 dias')); $dist[$k] = ($dist[$k] ?? 0) + 1; } ?>
    <section class="card">
        <div class="card-head"><div><h2>Vendas após remarketing</h2><p><?= count($comR) ?> de <?= $ind['encontradas'] ?> clientes encontrados receberam ao menos uma campanha de WhatsApp antes de comprar.</p></div></div>
        <?php if ($comR): ?>
            <div class="subhead">Tempo entre a última campanha e a venda</div>
            <?php $barras(array_map(fn ($k) => ['k' => $k, 'n' => $dist[$k]], array_values(array_intersect(['até 7 dias', '8 a 30 dias', '31 a 90 dias', 'mais de 90 dias'], array_keys($dist)))), 'k', 'n'); ?>
            <div class="table-wrap"><table class="report-table"><thead><tr><th scope="col">Venda</th><th scope="col">Cliente</th><th scope="col">Data da venda</th><th scope="col" class="num">Campanhas antes</th><th scope="col">Última campanha</th><th scope="col"></th></tr></thead><tbody>
                <?php foreach ($comR as $x): ?><tr><td data-rotulo="Venda"><?= e($x['chave']) ?></td><td data-rotulo="Cliente"><?= e($x['cliente'] ?? '—') ?></td><td data-rotulo="Data"><?= $data($x['data']) ?></td><td class="num" data-rotulo="Campanhas"><?= $x['remarketing'] ?></td><td data-rotulo="Última campanha"><?= $data($x['ultima_campanha']) ?></td><td><a class="btn btn-outline btn-sm" href="/pages/vendas_jornada.php?id=<?= $x['id'] ?>">Ver jornada</a></td></tr><?php endforeach; ?>
            </tbody></table></div>
        <?php else: ?><p class="empty-note">Nenhum cliente encontrado recebeu campanha antes de comprar.</p><?php endif; ?>
    </section>
    <?php $nota('Campanha enviada antes da data da venda (classificação temporal). Não presume que a campanha causou a compra.'); ?>

<?php elseif ($aba === 'redistribuicao'): $comT = array_values(array_filter($fatos, fn ($x) => $x['redistribuicao'] || $x['oferta'])); ?>
    <section class="card">
        <div class="card-head"><div><h2>Vendas após redistribuição ou oferta ativa</h2><p><?= $ind['redistribuicao'] ?> cliente(s) passaram por transferência/redistribuição e <?= $ind['oferta_ativa'] ?> pela oferta ativa antes de comprar.</p></div></div>
        <?php if ($comT): ?>
            <div class="table-wrap"><table class="report-table"><thead><tr><th scope="col">Venda</th><th scope="col">Cliente</th><th scope="col">Data</th><th scope="col" class="num">Transferências</th><th scope="col">Oferta ativa</th><th scope="col">1º corretor</th><th scope="col">Responsável na venda</th><th scope="col">Classificação</th><th scope="col"></th></tr></thead><tbody>
                <?php foreach ($comT as $x): [$cr, $cp] = $cls[$x['classificacao']]; ?><tr><td data-rotulo="Venda"><?= e($x['chave']) ?></td><td data-rotulo="Cliente"><?= e($x['cliente'] ?? '—') ?></td><td data-rotulo="Data"><?= $data($x['data']) ?></td><td class="num" data-rotulo="Transferências"><?= $x['transferencias'] ?></td><td data-rotulo="Oferta ativa"><?= $x['oferta'] ? 'Sim' : '—' ?></td>
                    <td data-rotulo="1º corretor"><?= e($nomesUsr[$x['primeiro_corretor_id']] ?? '—') ?></td><td data-rotulo="Responsável"><?= e(implode(', ', array_unique(array_map(fn ($u) => $nomesUsr[$u] ?? '#' . $u, array_column($x['responsaveis'], 'usuario_id')))) ?: '—') ?></td>
                    <td data-rotulo="Classificação"><span class="pill <?= $cp ?>"><?= e($cr) ?></span></td><td><a class="btn btn-outline btn-sm" href="/pages/vendas_jornada.php?id=<?= $x['id'] ?>">Ver jornada</a></td></tr><?php endforeach; ?>
            </tbody></table></div>
        <?php else: ?><p class="empty-note">Nenhuma venda encontrada passou por redistribuição ou oferta ativa.</p><?php endif; ?>
    </section>
    <?php $nota('Contam só transferências e atribuições registradas até a data da venda. Sem registro, não se presume que o cliente passou (ou não) pela oferta ativa. A venda não é atribuída automaticamente ao último corretor.'); ?>

<?php elseif ($aba === 'canais'): $porCls = vendas_agrupar(array_filter($fatos, fn ($x) => $x['encontrada']), fn ($x) => $x['classificacao']); $outros = array_values(array_filter($fatos, fn ($x) => $x['outro_canal'])); ?>
    <div class="grid-2">
        <section class="card"><div class="card-head"><div><h2>Quem vendeu, para clientes já conhecidos</h2><p>Vendas encontradas no CRM pela classificação comercial.</p></div></div>
            <?php $barras(array_map(fn ($l) => ['k' => $cls[$l['chave']][0] ?? $l['chave'], 'n' => $l['vendas']], $porCls), 'k', 'n'); ?>
            <p class="muted">"Não identificado" depende das <a href="/pages/vendas_equivalencias.php?aba=corretores">equivalências de corretores</a>: o prefixo do nome não classifica ninguém.</p></section>
        <section class="card"><div class="card-head"><div><h2>Vendas por outro canal com histórico anterior no CRM</h2><p><?= count($outros) ?> venda(s): outro corretor da equipe, outra equipe ou canal externo, para clientes que já estavam no CRM.</p></div></div>
            <?php if ($outros): ?><ul class="vp-lista"><?php foreach ($outros as $x): ?><li><span><a href="/pages/vendas_jornada.php?id=<?= $x['id'] ?>"><?= e($x['cliente'] ?? $x['chave']) ?></a> · <?= e($cls[$x['classificacao']][0]) ?></span><b><?= $data($x['data']) ?></b></li><?php endforeach; ?></ul>
            <?php else: ?><p class="empty-note">Nenhuma no período (com as equivalências cadastradas hoje).</p><?php endif; ?></section>
    </div>
    <?php $nota('Indicador para investigação gerencial: não presume negligência nem direito à atribuição da venda. Usa o vendedor da planilha (equivalência confirmada) e os responsáveis do CRM na data da venda.'); ?>

<?php elseif ($aba === 'produtividade'): $prod = vendas_produtividade($empresaId, $fatos, $f['periodo']); if ($f['corretor']) { $prod = array_values(array_filter($prod, fn ($l) => $l['usuario_id'] === $f['corretor'])); } ?>
    <section class="card">
        <div class="card-head"><div><h2>Produtividade comercial histórica</h2><p>O que cada corretor recebeu e fez no período (CRM) ao lado das vendas importadas em que ele era o responsável na data da venda.</p></div></div>
        <div class="table-wrap"><table class="report-table"><thead><tr><th scope="col">Corretor</th><th scope="col" class="num">Leads recebidos</th><th scope="col" class="num">Atendidos</th><th scope="col" class="num">Visitas realizadas</th><th scope="col" class="num">Transferidos (saída)</th><th scope="col" class="num">Vendas como responsável</th><th scope="col" class="num">VGV</th><th scope="col" class="num">Vendas em que foi o 1º corretor</th></tr></thead><tbody>
            <?php foreach ($prod as $l): ?><tr><td data-rotulo="Corretor"><?= e($l['nome']) ?></td><td class="num" data-rotulo="Recebidos"><?= $l['recebidos'] ?></td><td class="num" data-rotulo="Atendidos"><?= $l['atendidos'] ?></td><td class="num" data-rotulo="Visitas"><?= $l['visitas'] ?></td><td class="num" data-rotulo="Transferidos"><?= $l['transferidos'] ?></td><td class="num" data-rotulo="Vendas"><?= $l['vendas_responsavel'] ?></td><td class="num" data-rotulo="VGV"><?= e($brlC($l['valor_responsavel'])) ?></td><td class="num" data-rotulo="1º corretor"><?= $l['vendas_primeiro'] ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
    <?php $nota('Leads recebidos, atendidos, visitas e transferências contam no período pela data do próprio evento; vendas contam pela data da venda. São populações diferentes: a tabela não calcula taxa de conversão entre elas.'); ?>

<?php else: /* resultados */
    $busca = trim((string) ($_GET['q'] ?? ''));
    $lista = $busca === '' ? $fatos : array_values(array_filter($fatos, fn ($x) => stripos((string) $x['cliente'], $busca) !== false || $x['chave'] === $busca || $x['rm'] === $busca));
    $ord = in_array($_GET['ord'] ?? '', ['data', 'valor', 'dias', 'cliente'], true) ? $_GET['ord'] : 'data';
    usort($lista, fn ($a, $b) => match ($ord) { 'valor' => $b['valor'] <=> $a['valor'], 'dias' => ($b['dias_jornada'] ?? -1) <=> ($a['dias_jornada'] ?? -1), 'cliente' => strcmp((string) $a['cliente'], (string) $b['cliente']), default => strcmp($b['data'], $a['data']) });
    $pag = max(1, (int) ($_GET['p'] ?? 1));
    $total = count($lista);
    $lista = array_slice($lista, ($pag - 1) * 50, 50);
    $podeRev = usuario_tem_permissao($usuario, 'revisar_correspondencias'); ?>
    <section class="card">
        <div class="card-head vendas-filtros"><div><h2>Resultados detalhados</h2><p><?= $total ?> venda(s). Ordenar por:
            <?php foreach (['data' => 'data', 'valor' => 'valor', 'dias' => 'tempo de jornada', 'cliente' => 'cliente'] as $k => $rot): ?><a class="<?= $ord === $k ? 'is-ativo' : '' ?>" href="<?= e($qs(['ord' => $k, 'q' => $busca ?: null, 'p' => null])) ?>"><?= e($rot) ?></a><?= $k !== 'cliente' ? ' · ' : '' ?><?php endforeach; ?></p></div>
            <form method="get" class="vendas-busca" role="search"><?php foreach (array_intersect_key($_GET, array_flip(['periodo', 'ini', 'fim', 'empreendimento', 'equipe', 'corretor', 'origem', 'situacao', 'ord'])) as $k => $vq): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $vq) ?>"><?php endforeach; ?>
                <input type="hidden" name="aba" value="resultados"><input type="search" name="q" value="<?= e($busca) ?>" placeholder="Cliente, id ou Venda RM" aria-label="Buscar"><button class="btn btn-outline btn-sm" type="submit"><?= icon('search') ?>Buscar</button></form></div>
        <div class="table-wrap"><table class="report-table vp-resultados"><thead><tr><th scope="col">Cliente</th><th scope="col">Empreendimento comprado</th><th scope="col">Data</th><th scope="col" class="num">Valor</th><th scope="col">Cruzamento</th><th scope="col">Origem no CRM</th><th scope="col">Emp. inicial</th><th scope="col">Corretor inicial</th><th scope="col">Corretor da venda</th><th scope="col" class="num">Transf.</th><th scope="col">Oferta</th><th scope="col">Remarketing</th><th scope="col" class="num">Jornada</th><th scope="col"></th></tr></thead><tbody>
            <?php foreach ($lista as $x): [$sr, $sp] = $sit[$x['situacao']] ?? ['Não cruzada', 'pill-muted']; ?>
                <tr><td data-rotulo="Cliente"><?= e($x['cliente'] ?? '—') ?><br><small class="muted"><?= e($x['chave']) ?></small></td><td data-rotulo="Comprado"><?= e($x['emp_comprado'] ?? '—') ?></td><td data-rotulo="Data"><?= $data($x['data']) ?></td><td class="num" data-rotulo="Valor"><?= e($brl($x['valor'])) ?></td>
                    <td data-rotulo="Cruzamento"><span class="pill <?= $sp ?>"><?= e($sr) ?></span></td><td data-rotulo="Origem"><?= e($x['origem'] ?? '—') ?></td><td data-rotulo="Emp. inicial"><?= e($nomesEmp[$x['emp_inicial_id']] ?? '—') ?></td>
                    <td data-rotulo="Corretor inicial"><?= e($nomesUsr[$x['primeiro_corretor_id']] ?? '—') ?></td><td data-rotulo="Corretor da venda"><?= e($x['corretor_venda'] ?? '—') ?></td><td class="num" data-rotulo="Transferências"><?= $x['encontrada'] ? $x['transferencias'] : '—' ?></td>
                    <td data-rotulo="Oferta ativa"><?= $x['oferta'] ? 'Sim' : '—' ?></td><td data-rotulo="Remarketing"><?= $x['remarketing'] ? $x['remarketing'] . ' camp.' : '—' ?></td><td class="num" data-rotulo="Jornada"><?= $x['dias_jornada'] !== null ? $x['dias_jornada'] . ' d' : '—' ?></td>
                    <td class="vendas-acoes-linha"><?php if ($x['vinculada']): ?><a class="btn btn-primary btn-sm" href="/pages/vendas_jornada.php?id=<?= $x['id'] ?>">Ver jornada</a><?php elseif ($x['situacao'] === 'ambigua' && $podeRev): ?><a class="btn btn-primary btn-sm" href="/pages/vendas_revisao.php?id=<?= $x['id'] ?>">Revisar correspondência</a><?php else: ?><a class="btn btn-outline btn-sm" href="/pages/vendas_revisao.php?id=<?= $x['id'] ?>">Correspondência</a><?php endif; ?></td></tr>
            <?php endforeach; ?>
        </tbody></table></div>
        <?php $paginas = (int) ceil($total / 50); if ($paginas > 1): ?><nav class="paginacao"><?php if ($pag > 1): ?><a class="btn btn-outline btn-sm" href="<?= e($qs(['p' => $pag - 1, 'q' => $busca ?: null, 'ord' => $ord])) ?>">Anterior</a><?php endif; ?><span class="muted">Página <?= $pag ?> de <?= $paginas ?></span><?php if ($pag < $paginas): ?><a class="btn btn-outline btn-sm" href="<?= e($qs(['p' => $pag + 1, 'q' => $busca ?: null, 'ord' => $ord])) ?>">Próxima</a><?php endif; ?></nav><?php endif; ?>
    </section>
    <?php $nota('Transferências, oferta e remarketing contam até a data da venda e só para vendas encontradas. "Ver jornada" mostra apenas as oportunidades da sua carteira atual.'); ?>
<?php endif; ?>
<script>
document.querySelectorAll('[data-vp-filtros]').forEach(function (d) { if (window.innerWidth > 640) d.open = true; });
document.querySelectorAll('[data-vp-periodo]').forEach(function (s) {
    function u() { document.querySelectorAll('[data-vp-datas]').forEach(function (d) { d.hidden = s.value !== 'personalizado'; }); }
    s.addEventListener('change', u); u();
});
</script>
<?php require __DIR__ . '/../includes/layout_end.php'; ?>
