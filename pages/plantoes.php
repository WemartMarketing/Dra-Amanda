<?php
/**
 * Plantões de vendas — tela operacional: todos os usuários da empresa veem os
 * plantões ativos (endereço, horário, empreendimentos) e abrem a rota no
 * Google Maps ou no Waze com um toque. Quem gerencia (gerenciar_plantoes)
 * vê também os inativos e cadastra/edita em pages/plantao_editar.php.
 * Regras em includes/plantoes.php.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/plantoes_ui.php';

$usuario = auth_require();
if (!empty($usuario['eh_superadmin'])) {
    header('Location: /pages/superadmin.php');
    exit;
}
plantao_exigir_modulo('Plantões', 'plantoes');
$podeGerenciar = plantao_pode_gerenciar($usuario);
$busca = trim((string) ($_GET['q'] ?? ''));
$filtro = ($_GET['filtro'] ?? '') === 'meus' ? 'meus' : 'todos';
$plantoes = plantoes_listar($usuario, $busca, $filtro);
$totalMeus = $filtro === 'meus' ? count($plantoes) : count(array_filter($plantoes, fn ($p) => !empty($p['meu'])));

$pageTitle = 'Plantões — ' . APP_NAME;
$crumb = 'Plantões';
$activeNav = 'plantoes';
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div><div class="eyebrow">Operação de vendas</div><h1>Plantões de vendas</h1><p>Encontre o plantão, veja o endereço e abra a rota no Google Maps ou no Waze.</p></div>
    <?php if ($podeGerenciar): ?><div class="actions"><a class="btn btn-primary" href="/pages/plantao_editar.php"><?= icon('plus') ?>Novo plantão</a></div><?php endif; ?>
</div>

<form class="plantao-busca" method="get" role="search" aria-label="Buscar plantões">
    <input type="hidden" name="filtro" value="<?= e($filtro) ?>">
    <label class="sr-only" for="pl-busca">Buscar por nome, bairro, cidade ou empreendimento</label>
    <div class="plantao-busca-campo"><?= icon('search') ?><input type="search" id="pl-busca" name="q" value="<?= e($busca) ?>" placeholder="Nome, bairro, cidade ou empreendimento" maxlength="80" autocomplete="off"></div>
    <button type="submit" class="btn btn-primary">Buscar</button>
</form>
<div class="plantao-filtros" role="tablist" aria-label="Quais plantões mostrar">
    <a role="tab" class="plantao-filtro<?= $filtro === 'todos' ? ' is-current' : '' ?>" aria-selected="<?= $filtro === 'todos' ? 'true' : 'false' ?>" href="?<?= e(http_build_query(array_filter(['q' => $busca]))) ?>">Todos os plantões</a>
    <a role="tab" class="plantao-filtro<?= $filtro === 'meus' ? ' is-current' : '' ?>" aria-selected="<?= $filtro === 'meus' ? 'true' : 'false' ?>" href="?<?= e(http_build_query(array_filter(['q' => $busca, 'filtro' => 'meus']))) ?>">Meus plantões<?= $totalMeus ? ' (' . $totalMeus . ')' : '' ?></a>
</div>

<?php if ($plantoes): ?>
    <div class="plantao-grid">
        <?php foreach ($plantoes as $p) render_plantao_card($p); ?>
    </div>
<?php else: ?>
    <div class="card stub-card">
        <div class="stub-icon"><?= icon('map') ?></div>
        <?php if ($busca !== ''): ?>
            <h2>Nenhum plantão encontrado</h2><p>Nada com “<?= e($busca) ?>”. Tente outro nome, bairro, cidade ou empreendimento.</p>
            <a class="btn btn-outline" href="?filtro=<?= e($filtro) ?>">Limpar busca</a>
        <?php elseif ($filtro === 'meus'): ?>
            <h2>Você ainda não está em nenhum plantão</h2><p>Quando o gestor te incluir num plantão ou na roleta dele, ele aparece aqui.</p>
            <a class="btn btn-outline" href="?">Ver todos os plantões</a>
        <?php else: ?>
            <h2>Nenhum plantão cadastrado</h2><p><?= $podeGerenciar ? 'Cadastre o primeiro plantão com o endereço e a localização do stand.' : 'Os plantões da empresa aparecem aqui assim que forem cadastrados.' ?></p>
            <?php if ($podeGerenciar): ?><a class="btn btn-primary" href="/pages/plantao_editar.php"><?= icon('plus') ?>Novo plantão</a><?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
