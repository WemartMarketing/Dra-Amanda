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
        ['Funil em detalhe (coortes, tempo por etapa)', 'Posição × movimentação, avanços e retrocessos por etapa.', null, true],
    ],
    'corretores' => [
        [relui_relatorios()['corretor'][0], relui_relatorios()['corretor'][1], $r('corretor'), true],
        [relui_relatorios()['gestao'][0], relui_relatorios()['gestao'][1], $r('gestao'), true],
    ],
    'empreendimentos' => [[relui_relatorios()['empreendimentos'][0], relui_relatorios()['empreendimentos'][1], $r('empreendimentos'), true]],
    'plantoes' => [['Plantões e roletas', 'Presença real × horário do plantão, espera na fila, leads fora do horário.', null, true]],
    'marketing' => [
        ['Leads por anúncio', 'Campanha, conjunto e anúncio do Meta Ads, com a prévia do criativo.', '/pages/relatorio_anuncios.php', true],
        ['Origens e UTMs', 'Leads, atendimento e vendas por origem detalhada e UTM.', null, true],
    ],
    'whatsapp' => [['Automação e remarketing', 'Mensagens, respostas e resultados das jornadas e campanhas.', '/pages/relatorio_automacao.php',
        $tem('whatsapp_configurar') || $tem('gerenciar_campanhas_wa')]],
    'visitas' => [['Visitas e vendas', 'Agendadas, confirmadas, realizadas, comparecimento e contratos.', null, true]],
    'perdas' => [['Perdas e transferências', 'Leads perdidos por inatividade e transferências, pela estrutura que perdeu.', '/pages/relatorios.php', true]],
    'auditoria' => [
        ['Auditoria', 'Quem fez o quê, inclusive exportações.', '/pages/auditoria.php', $tem('visualizar_auditoria')],
        ['Qualidade dos dados', 'Origem não detalhada, atribuições sem estrutura, contratos sem registro…', null, $tem('visualizar_auditoria')],
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
<?php require __DIR__ . '/../includes/layout_end.php'; ?>
