<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/estrutura.php';
require_once __DIR__ . '/../includes/lead_componentes.php';

$usuario = auth_require();
$crumb = 'Kanban';
$activeNav = 'kanban';
if (!usuario_tem_permissao($usuario, 'visualizar_leads')) {
    pagina_acesso_restrito($crumb, $activeNav, 'Visualizar leads');
}
$empresaId = (int) $usuario['empresa_ativa_id'];
$ehCorretor = $usuario['escopo'] === 'propria_carteira';
$podeMover = usuario_tem_permissao($usuario, 'editar_leads');

$filtros = leads_filtros_da_requisicao($_GET);
$etapasKanban = funil_etapas($empresaId, 'kanban');
$colunasVisiveis = $filtros['etapa']
    ? array_values(array_filter($etapasKanban, fn ($e) => (int) $e['id'] === $filtros['etapa']))
    : $etapasKanban;

$leads = leads_listar($usuario, $filtros, 600);
$colunas = [];
foreach ($colunasVisiveis as $etapa) {
    $colunas[(int) $etapa['id']] = ['etapa' => $etapa, 'leads' => []];
}
$exibidos = 0;
foreach ($leads as $lead) {
    if (isset($colunas[(int) $lead['etapa_id']])) {
        $colunas[(int) $lead['etapa_id']]['leads'][] = $lead;
        $exibidos++;
    }
}
// No celular uma coluna por vez: abre na primeira que tem lead.
$colunaInicial = (int) ($_GET['coluna'] ?? 0);
if (!isset($colunas[$colunaInicial])) {
    $colunaInicial = 0;
    foreach ($colunas as $id => $col) {
        if ($col['leads']) { $colunaInicial = $id; break; }
    }
    $colunaInicial = $colunaInicial ?: (int) array_key_first($colunas);
}

$stmt = db()->prepare('SELECT id, nome FROM empreendimentos WHERE empresa_id = :empresa_id AND ativo = 1 ORDER BY nome');
$stmt->execute(['empresa_id' => $empresaId]);
$empreendimentos = $stmt->fetchAll();
$responsaveis = leads_responsaveis_filtro($usuario);
$temFiltro = array_filter($filtros, fn ($v) => $v !== null && $v !== '');
$temFiltroExtra = array_diff_key($temFiltro, ['q' => 1]);

$pageTitle = ($ehCorretor ? 'Meu Kanban' : 'Kanban') . ' — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>

<div class="header">
    <div>
        <div class="eyebrow"><?= $ehCorretor ? 'Meus atendimentos' : 'Atendimentos da equipe' ?></div>
        <h1><?= $ehCorretor ? 'Meu Kanban' : 'Kanban de leads' ?></h1>
        <p>Arraste o card ou use <b>Mover etapa</b>. Toque no nome para abrir a ficha.</p>
    </div>
    <div class="actions">
        <a href="/pages/leads.php?<?= e(http_build_query(array_filter($filtros))) ?>" class="btn btn-outline"><?= icon('list') ?>Ver em lista</a>
        <?php if (usuario_tem_permissao($usuario, 'criar_leads')): ?>
            <a href="/pages/lead.php" class="btn btn-primary"><?= icon('plus') ?>Novo lead</a>
        <?php endif; ?>
    </div>
</div>

<form class="filters-row" method="get" aria-label="Filtros do Kanban">
    <label class="sr-only" for="f-q">Buscar</label>
    <input class="select-filter filter-search" id="f-q" type="search" name="q" placeholder="Nome ou telefone" value="<?= e($filtros['q']) ?>">
    <button type="button" class="btn btn-outline btn-sm filters-toggle" data-toggle-filtros aria-expanded="<?= $temFiltroExtra ? 'true' : 'false' ?>"><?= icon('filter') ?>Filtros<?= $temFiltroExtra ? ' (' . count($temFiltroExtra) . ')' : '' ?></button>
    <div class="filters-extra<?= $temFiltroExtra ? ' is-open' : '' ?>" data-filtros-extra>
    <label class="sr-only" for="f-emp">Empreendimento</label>
    <select class="select-filter" id="f-emp" name="empreendimento" data-auto-filtro>
        <option value="">Todos os empreendimentos</option>
        <?php foreach ($empreendimentos as $emp): ?>
            <option value="<?= (int) $emp['id'] ?>" <?= $filtros['empreendimento'] === (int) $emp['id'] ? 'selected' : '' ?>><?= e($emp['nome']) ?></option>
        <?php endforeach; ?>
    </select>
    <label class="sr-only" for="f-periodo">Período</label>
    <select class="select-filter" id="f-periodo" name="periodo" data-auto-filtro>
        <option value="">Qualquer data de entrada</option>
        <?php foreach ([7 => 'Entraram nos últimos 7 dias', 30 => 'Últimos 30 dias', 90 => 'Últimos 90 dias'] as $dias => $rotulo): ?>
            <option value="<?= $dias ?>" <?= $filtros['periodo'] === $dias ? 'selected' : '' ?>><?= $rotulo ?></option>
        <?php endforeach; ?>
    </select>
    <label class="sr-only" for="f-etapa">Etapa</label>
    <select class="select-filter" id="f-etapa" name="etapa" data-auto-filtro>
        <option value="">Todas as etapas</option>
        <?php foreach ($etapasKanban as $etapa): ?>
            <option value="<?= (int) $etapa['id'] ?>" <?= $filtros['etapa'] === (int) $etapa['id'] ? 'selected' : '' ?>><?= e($etapa['nome']) ?></option>
        <?php endforeach; ?>
    </select>
    <label class="sr-only" for="f-prio">Prioridade</label>
    <select class="select-filter" id="f-prio" name="prioridade" data-auto-filtro>
        <option value="">Todas as prioridades</option>
        <?php foreach (lead_prioridades() as $valor => $rotulo): ?>
            <option value="<?= $valor ?>" <?= $filtros['prioridade'] === $valor ? 'selected' : '' ?>>Prioridade <?= mb_strtolower($rotulo) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($responsaveis): ?>
        <label class="sr-only" for="f-resp">Corretor</label>
        <select class="select-filter" id="f-resp" name="responsavel" data-auto-filtro>
            <option value="">Todos os corretores</option>
            <?php foreach ($responsaveis as $r): ?>
                <option value="<?= (int) $r['id'] ?>" <?= $filtros['responsavel'] === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['nome']) ?></option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>
    </div>
    <button type="submit" class="btn btn-outline btn-sm filters-submit"><?= icon('filter') ?>Filtrar</button>
    <?php if ($temFiltro): ?><a href="/pages/kanban.php" class="link-btn">Limpar filtros</a><?php endif; ?>
    <span class="filters-note"><?= $exibidos ?> lead<?= $exibidos === 1 ? '' : 's' ?></span>
</form>

<div class="kanban-tabs" role="tablist" aria-label="Etapas">
    <?php foreach ($colunas as $id => $col): ?>
        <button type="button" role="tab" class="kanban-tab<?= $id === $colunaInicial ? ' is-current' : '' ?>" aria-selected="<?= $id === $colunaInicial ? 'true' : 'false' ?>" data-coluna-tab="<?= $id ?>">
            <span class="etapa-dot" style="background: <?= e($col['etapa']['cor']) ?>"></span><?= e($col['etapa']['nome']) ?> <b><?= count($col['leads']) ?></b>
        </button>
    <?php endforeach; ?>
</div>

<div class="kanban-scroll">
    <div class="kanban" id="kanban">
        <?php foreach ($colunas as $id => $col): $etapa = $col['etapa']; ?>
            <section class="kcol<?= $id === $colunaInicial ? ' is-current' : '' ?>" data-etapa="<?= $id ?>" data-coluna="<?= $id ?>" aria-label="<?= e($etapa['nome']) ?>" style="--etapa-cor: <?= e($etapa['cor']) ?>">
                <div class="kcol-head">
                    <strong><span class="etapa-dot" style="background: <?= e($etapa['cor']) ?>"></span><?= e($etapa['nome']) ?></strong>
                    <span class="kcol-count"><?= count($col['leads']) ?></span>
                </div>
                <?php foreach ($col['leads'] as $lead) render_lead_card($lead, !$ehCorretor, $podeMover); ?>
                <?php if (!$col['leads']): ?><p class="kcol-empty">Nenhum lead nesta etapa.</p><?php endif; ?>
            </section>
        <?php endforeach; ?>
        <?php if (!$colunas): ?>
            <div class="card stub-card" style="flex:1;"><h2>Nenhuma etapa visível</h2><p>Peça ao administrador para ativar etapas do funil.</p></div>
        <?php endif; ?>
    </div>
</div>

<?php if ($podeMover) render_modal_mover_etapa($etapasKanban); ?>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
