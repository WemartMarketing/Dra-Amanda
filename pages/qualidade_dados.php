<?php
/**
 * Painel de Qualidade dos Dados (Etapa 6): o que compromete os relatórios,
 * com alertas e onde corrigir. Mesma verificação de
 * ferramentas/auditoria_relatorios.php. Só contagens — nenhum dado pessoal.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/estrutura.php';
require_once __DIR__ . '/../includes/relatorios/auditoria_dados.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/lead_componentes.php';

$usuario = auth_require();
$crumb = 'Qualidade dos dados';
$activeNav = 'relatorios';
if (!empty($usuario['eh_superadmin']) || !(usuario_tem_permissao($usuario, 'visualizar_auditoria') || usuario_tem_permissao($usuario, 'administrar_usuarios'))) {
    pagina_acesso_restrito($crumb, $activeNav, 'Ver auditoria');
}
$secoes = relatorio_auditoria_dados((int) $usuario['empresa_ativa_id']);
$cont = ['critico' => 0, 'alerta' => 0, 'ok' => 0];
foreach ($secoes as $itens) {
    foreach ($itens as $it) {
        if (isset($cont[$it['nivel']])) {
            $cont[$it['nivel']]++;
        }
    }
}
// Onde corrigir (pelo começo do rótulo).
$corrigir = [
    'Leads sem empreendimento' => ['/pages/leads.php', 'Abrir os leads'],
    'Leads com origem não detalhada' => ['/pages/funil.php#origens', 'Cadastrar origens'],
    'Lançamentos de investimento' => ['/pages/investimentos.php', 'Lançar investimento'],
    'Leads de Meta Ads' => ['/pages/investimentos.php', 'Lançar investimento'],
    'Leads de Google Ads' => ['/pages/investimentos.php', 'Lançar investimento'],
    'Leads com dono e sem atribuição aberta' => [null, 'Rode php -q ferramentas/retroalimentar_historico.php'],
    'Leads em "Contrato assinado" sem contrato registrado' => ['/pages/kanban.php', 'Registrar os contratos na ficha'],
    'Tarifas de mensagem' => ['/pages/whatsapp.php', 'Cadastrar tarifas'],
    'Equipes sem superintendência' => ['/pages/equipes.php', 'Ajustar equipes'],
];
$onde = function (string $rotulo) use ($corrigir) {
    foreach ($corrigir as $prefixo => $x) {
        if (str_starts_with($rotulo, $prefixo)) {
            return $x;
        }
    }
    return null;
};
$pill = ['critico' => ['pill-red', 'Crítico'], 'alerta' => ['pill-amber', 'Atenção'], 'ok' => ['pill-green', 'OK'], 'info' => ['pill-muted', 'Info']];

$pageTitle = 'Qualidade dos dados — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div>
        <div class="eyebrow">Relatórios · Auditoria</div>
        <h1>Qualidade dos dados</h1>
        <p>O que pode deixar os relatórios incompletos ou imprecisos, e onde corrigir. Só contagens (nenhum nome, telefone ou e-mail). Atualizado agora: <?= date('d/m/Y H:i') ?>.</p>
    </div>
    <div class="actions"><a class="btn btn-outline" href="/pages/relatorios_central.php"><?= icon('list') ?>Todos os relatórios</a></div>
</div>
<div class="kpi-grid kpi-grid-3 dash-kpis">
    <div class="kpi"><div class="kpi-top"><span class="label">Críticos</span><div class="kpi-icon"><?= icon('alert') ?></div></div><div class="kpi-value"><?= $cont['critico'] ?></div><div class="kpi-sub">Distorcem números importantes: resolva primeiro.</div></div>
    <div class="kpi"><div class="kpi-top"><span class="label">Pontos de atenção</span><div class="kpi-icon"><?= icon('info') ?></div></div><div class="kpi-value"><?= $cont['alerta'] ?></div><div class="kpi-sub">Deixam algum indicador parcial ou indisponível.</div></div>
    <div class="kpi"><div class="kpi-top"><span class="label">Verificações em ordem</span><div class="kpi-icon"><?= icon('check') ?></div></div><div class="kpi-value"><?= $cont['ok'] ?></div><div class="kpi-sub">Sem problemas encontrados.</div></div>
</div>
<div class="rel-central">
    <?php foreach ($secoes as $titulo => $itens):
        usort($itens, fn ($a, $b) => (['critico' => 0, 'alerta' => 1, 'info' => 2, 'ok' => 3][$a['nivel']] ?? 2) <=> (['critico' => 0, 'alerta' => 1, 'info' => 2, 'ok' => 3][$b['nivel']] ?? 2)); ?>
        <section class="card rel-cat">
            <div class="rel-cat-topo"><h2><?= e($titulo) ?></h2></div>
            <ul class="rel-qualidade">
                <?php foreach ($itens as $it): [$cls, $rot] = $pill[$it['nivel']] ?? $pill['info']; $o = $onde($it['rotulo']); ?>
                    <li class="nivel-<?= e($it['nivel']) ?>">
                        <div class="rel-q-topo"><span class="pill <?= $cls ?>"><?= e($rot) ?></span><b><?= e($it['rotulo']) ?></b><span class="rel-q-valor"><?= e(is_numeric($it['valor']) ? dash_num($it['valor']) : (string) $it['valor']) ?></span></div>
                        <?php if ($it['nota']): ?><small><?= e($it['nota']) ?></small><?php endif; ?>
                        <?php if ($o && in_array($it['nivel'], ['critico', 'alerta'], true) && (!is_numeric($it['valor']) || $it['valor'] > 0 || str_starts_with($it['rotulo'], 'Lançamentos'))): ?>
                            <small><?php if ($o[0]): ?><a href="<?= e($o[0]) ?>"><?= e($o[1]) ?> →</a><?php else: ?><?= e($o[1]) ?><?php endif; ?></small>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>
</div>
<?php require __DIR__ . '/../includes/layout_end.php'; ?>
