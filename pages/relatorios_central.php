<?php
/**
 * Central de Relatórios: as 10 categorias, cada uma com os relatórios já
 * disponíveis para quem está vendo. O que ainda está em construção aparece
 * como tal — sem números.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/estrutura.php';
require_once __DIR__ . '/../includes/relatorios/ui.php';
require_once __DIR__ . '/../includes/relatorios/indicadores.php';
require_once __DIR__ . '/../includes/relatorios/fila_exportacao.php';

$usuario = auth_require();
if (!empty($usuario['eh_superadmin'])) {
    header('Location: /pages/superadmin.php');
    exit;
}
$crumb = 'Relatórios';
$activeNav = 'relatorios';
if ($usuario['escopo'] === 'propria_carteira' && !usuario_tem_permissao($usuario, 'visualizar_indicadores')) {
    header('Location: /pages/relatorio.php?r=corretor');
    exit;
}
if (!usuario_tem_permissao($usuario, 'visualizar_indicadores')) {
    pagina_acesso_restrito($crumb, $activeNav, 'Visualizar indicadores/dashboard');
}
$tem = fn (string $p) => usuario_tem_permissao($usuario, $p);
$r = fn (string $k) => '/pages/relatorio.php?r=' . $k;
// [rótulo, descrição, url|null (em construção), disponível para este usuário]
$cat = [
    'executiva' => [[relui_relatorios()['executiva'][0], relui_relatorios()['executiva'][1], $r('executiva'), true]],
    'leads' => [
        ['Dashboard de leads e funil', 'Entrada, distribuição, atendimento e funil do período.', '/pages/dashboard.php', true],
        [relui_relatorios()['funil'][0], relui_relatorios()['funil'][1], $r('funil'), true],
    ],
    'corretores' => [
        [relui_relatorios()['corretor'][0], relui_relatorios()['corretor'][1], $r('corretor'), true],
        [relui_relatorios()['gestao'][0], relui_relatorios()['gestao'][1], $r('gestao'), true],
    ],
    'empreendimentos' => [[relui_relatorios()['empreendimentos'][0], relui_relatorios()['empreendimentos'][1], $r('empreendimentos'), true]],
    'plantoes' => [[relui_relatorios()['plantoes'][0], relui_relatorios()['plantoes'][1], $r('plantoes'), true]],
    'marketing' => [
        ['Leads por anúncio', 'Campanha, conjunto e anúncio do Meta Ads, com a prévia do criativo.', '/pages/relatorio_anuncios.php', true],
        ['Investimento em anúncios', 'Lançamentos por origem e período (manual ou planilha), base dos custos por resultado.', '/pages/investimentos.php', $tem('gerenciar_investimentos')],
        [relui_relatorios()['origens'][0], relui_relatorios()['origens'][1], $r('origens'), true],
    ],
    'whatsapp' => [['Automação e remarketing', 'Mensagens, respostas e resultados das jornadas e campanhas.', '/pages/relatorio_automacao.php',
        $tem('whatsapp_configurar') || $tem('gerenciar_campanhas_wa')]],
    'visitas' => [[relui_relatorios()['visitas'][0], relui_relatorios()['visitas'][1], $r('visitas'), true]],
    'perdas' => [
        [relui_relatorios()['perdas'][0], relui_relatorios()['perdas'][1], $r('perdas'), true],
        ['Transferências (detalhe)', 'Transferências por motivo e pela estrutura anterior, com a série no tempo.', '/pages/relatorios.php', true],
    ],
    'auditoria' => [
        ['Auditoria', 'Quem fez o quê, inclusive exportações.', '/pages/auditoria.php', $tem('visualizar_auditoria')],
        ['Qualidade dos dados', 'Origem não detalhada, atribuições sem estrutura, contratos sem registro…', '/pages/qualidade_dados.php', $tem('visualizar_auditoria') || $tem('administrar_usuarios')],
    ],
];
$icones = ['executiva' => 'chart', 'leads' => 'list', 'corretores' => 'users', 'empreendimentos' => 'building', 'plantoes' => 'map',
           'marketing' => 'anuncio', 'whatsapp' => 'whatsapp', 'visitas' => 'calendar', 'perdas' => 'alert', 'auditoria' => 'shield'];

$pageTitle = 'Relatórios — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div>
        <div class="eyebrow">Central de Relatórios · <?= e(rbac_rotulo_escopo($usuario['escopo'])) ?></div>
        <h1>Relatórios</h1>
        <p>Escolha o relatório, ajuste os filtros e veja o resultado. Cada número segue o dicionário de indicadores e respeita o que a sua função pode ver.</p>
    </div>
    <div class="actions">
        <a class="btn btn-primary" href="/pages/relatorio_modelos.php"><?= icon('file') ?>Modelos de relatório</a>
        <?php if ($tem('visualizar_auditoria') || $tem('administrar_usuarios')): ?><a class="btn btn-outline" href="/pages/qualidade_dados.php"><?= icon('shield') ?>Qualidade dos dados</a><?php endif; ?>
    </div>
</div>

<div class="rel-central">
    <?php foreach (relatorio_categorias() as $k => $cRot): $itens = array_filter($cat[$k] ?? [], fn ($i) => $i[3]); if (!$itens) continue; ?>
        <section class="card rel-cat" aria-labelledby="cat-<?= e($k) ?>">
            <div class="rel-cat-topo"><span class="kpi-icon"><?= icon($icones[$k] ?? 'chart') ?></span><h2 id="cat-<?= e($k) ?>"><?= e(is_array($cRot) ? $cRot['nome'] ?? $k : $cRot) ?></h2></div>
            <ul class="rel-cat-lista">
                <?php foreach ($itens as [$rot, $desc, $link]): ?>
                    <li>
                        <?php if ($link): ?>
                            <a href="<?= e($link) ?>"><b><?= e($rot) ?></b><span><?= e($desc) ?></span></a>
                        <?php else: ?>
                            <div class="rel-cat-breve"><b><?= e($rot) ?> <span class="pill pill-muted">em construção</span></b><span><?= e($desc) ?></span></div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>
</div>

<?php $minhas = usuario_tem_permissao($usuario, 'exportar_relatorios') ? relexp_minhas($usuario) : []; if ($minhas): ?>
<section class="card rel-exportacoes" id="minhas-exportacoes" aria-labelledby="minhas-exp-titulo">
    <div class="card-head"><div><h2 id="minhas-exp-titulo">Minhas exportações</h2><p>Arquivos grandes preparados em segundo plano. Cada link vale 24 h e só funciona para você.</p></div>
        <a class="btn btn-outline btn-sm" href="?#minhas-exportacoes">Atualizar</a></div>
    <div class="table-wrap"><table class="report-table">
        <thead><tr><th scope="col">Pedido em</th><th scope="col">Relatório</th><th scope="col">Formato</th><th scope="col">Situação</th><th scope="col" class="num">Linhas</th><th scope="col"></th></tr></thead>
        <tbody>
        <?php foreach ($minhas as $x):
            $sit = ['pendente' => ['Na fila', 'pill-muted'], 'gerando' => ['Gerando…', 'pill-blue'], 'pronto' => ['Pronto', 'pill-green'], 'erro' => ['Erro', 'pill-red'], 'expirado' => ['Expirado', 'pill-muted']][$x['status']]; ?>
            <tr>
                <td data-rotulo="Pedido em"><?= date('d/m/Y H:i', strtotime($x['criado_em'])) ?></td>
                <td data-rotulo="Relatório"><?= e(relui_relatorios()[$x['relatorio']][0] ?? $x['relatorio']) ?><?= $x['nivel'] === 'dados' ? ' (dados)' : '' ?></td>
                <td data-rotulo="Formato"><?= e(strtoupper($x['formato'])) ?></td>
                <td data-rotulo="Situação"><span class="pill <?= $sit[1] ?>"><?= e($sit[0]) ?></span><?php if ($x['status'] === 'erro' && $x['erro']): ?> <small class="muted"><?= e($x['erro']) ?></small><?php endif; ?>
                    <?php if ($x['status'] === 'pronto'): ?> <small class="muted">até <?= date('d/m H:i', strtotime($x['expira_em'])) ?></small><?php endif; ?></td>
                <td class="num" data-rotulo="Linhas"><?= $x['linhas'] !== null ? dash_num($x['linhas']) : '—' ?></td>
                <td><?php if ($x['status'] === 'pronto' && strtotime($x['expira_em']) > time()): ?><a class="btn btn-primary btn-sm" href="<?= e(relexp_link($x)) ?>"><?= icon('download') ?>Baixar</a><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/layout_end.php'; ?>
