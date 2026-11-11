<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/estrutura.php';
require_once __DIR__ . '/../includes/lead_componentes.php';

$usuario = auth_require();
$crumb = 'Leads';
$activeNav = 'leads';
if (!usuario_tem_permissao($usuario, 'visualizar_leads')) {
    pagina_acesso_restrito($crumb, $activeNav, 'Visualizar leads');
}
$empresaId = (int) $usuario['empresa_ativa_id'];
$ehCorretor = $usuario['escopo'] === 'propria_carteira';
$podeTransferir = usuario_tem_permissao($usuario, 'redistribuir_leads');

$filtros = leads_filtros_da_requisicao($_GET);
$porPagina = 25;
$total = leads_contar($usuario, $filtros);
$paginas = max(1, (int) ceil($total / $porPagina));
$pagina = min($paginas, max(1, (int) ($_GET['pagina'] ?? 1)));
$leads = leads_listar($usuario, $filtros, $porPagina, ($pagina - 1) * $porPagina);

$etapas = funil_etapas($empresaId, 'todas');
$stmt = db()->prepare('SELECT id, nome FROM empreendimentos WHERE empresa_id = :empresa_id AND ativo = 1 ORDER BY nome');
$stmt->execute(['empresa_id' => $empresaId]);
$empreendimentos = $stmt->fetchAll();
$responsaveis = leads_responsaveis_filtro($usuario);
$destinos = $podeTransferir ? rbac_usuarios_atribuiveis($usuario) : [];
$temFiltro = array_filter($filtros, fn ($v) => $v !== null && $v !== '');
$temFiltroExtra = array_diff_key($temFiltro, ['q' => 1]);

function url_pagina(array $filtros, int $pagina): string
{
    return '/pages/leads.php?' . http_build_query(array_filter($filtros + ['pagina' => $pagina]));
}

$pageTitle = ($ehCorretor ? 'Meus leads' : 'Leads') . ' — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>

<div class="header">
    <div>
        <div class="eyebrow"><?= $ehCorretor ? 'Minha carteira' : 'Carteira da equipe' ?></div>
        <h1><?= $ehCorretor ? 'Meus leads' : 'Leads' ?></h1>
        <p>Busque, filtre e abra qualquer cliente. Os mais urgentes aparecem primeiro.</p>
    </div>
    <div class="actions">
        <a href="/pages/kanban.php?<?= e(http_build_query(array_filter($filtros))) ?>" class="btn btn-outline"><?= icon('kanban') ?>Ver no Kanban</a>
        <?php if (usuario_tem_permissao($usuario, 'criar_leads')): ?>
            <a href="/pages/lead.php" class="btn btn-primary"><?= icon('plus') ?>Novo lead</a>
        <?php endif; ?>
    </div>
</div>

<form class="filters-row" method="get" aria-label="Filtros da lista">
    <label class="sr-only" for="f-q">Buscar</label>
    <input class="select-filter filter-search" id="f-q" type="search" name="q" placeholder="Nome ou telefone" value="<?= e($filtros['q']) ?>">
    <button type="button" class="btn btn-outline btn-sm filters-toggle" data-toggle-filtros aria-expanded="<?= $temFiltroExtra ? 'true' : 'false' ?>"><?= icon('filter') ?>Filtros<?= $temFiltroExtra ? ' (' . count($temFiltroExtra) . ')' : '' ?></button>
    <div class="filters-extra<?= $temFiltroExtra ? ' is-open' : '' ?>" data-filtros-extra>
    <label class="sr-only" for="f-situacao">Situação</label>
    <select class="select-filter" id="f-situacao" name="situacao" data-auto-filtro>
        <option value="">Todas as situações</option>
        <option value="abertos" <?= $filtros['situacao'] === 'abertos' ? 'selected' : '' ?>>Em aberto</option>
        <option value="atrasados" <?= $filtros['situacao'] === 'atrasados' ? 'selected' : '' ?>>Atrasados</option>
        <option value="encerrados" <?= $filtros['situacao'] === 'encerrados' ? 'selected' : '' ?>>Encerrados</option>
    </select>
    <label class="sr-only" for="f-etapa">Etapa</label>
    <select class="select-filter" id="f-etapa" name="etapa" data-auto-filtro>
        <option value="">Todas as etapas</option>
        <?php foreach ($etapas as $etapa): ?>
            <option value="<?= (int) $etapa['id'] ?>" <?= $filtros['etapa'] === (int) $etapa['id'] ? 'selected' : '' ?>><?= e($etapa['nome']) ?><?= $etapa['ativa'] ? '' : ' (inativa)' ?></option>
        <?php endforeach; ?>
    </select>
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
        <?php foreach ([7 => 'Últimos 7 dias', 30 => 'Últimos 30 dias', 90 => 'Últimos 90 dias'] as $dias => $rotulo): ?>
            <option value="<?= $dias ?>" <?= $filtros['periodo'] === $dias ? 'selected' : '' ?>><?= $rotulo ?></option>
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
    <button type="submit" class="btn btn-outline btn-sm filters-submit"><?= icon('search') ?>Buscar</button>
    <?php if ($temFiltro): ?><a href="/pages/leads.php" class="link-btn">Limpar filtros</a><?php endif; ?>
    <span class="filters-note"><?= $total ?> lead<?= $total === 1 ? '' : 's' ?></span>
</form>

<div class="card lead-list-card">
    <?php if ($podeTransferir && $leads): ?>
        <div class="bulk-bar" data-bulk-bar hidden>
            <span data-bulk-contagem>0 selecionados</span>
            <button type="button" class="btn btn-primary btn-sm" data-transferir-selecionados><?= icon('users') ?>Transferir selecionados</button>
        </div>
    <?php endif; ?>

    <?php if (!$leads): ?>
        <div class="stub-card"><div class="stub-icon"><?= icon('search') ?></div><h2>Nenhum lead encontrado</h2><p><?= $temFiltro ? 'Tente outros filtros ou limpe a busca.' : 'Os leads atribuídos a você aparecem aqui.' ?></p></div>
    <?php else: ?>
        <table class="lead-table">
            <thead>
                <tr>
                    <?php if ($podeTransferir): ?><th class="col-check"><label class="sr-only" for="sel-todos">Selecionar todos</label><input type="checkbox" id="sel-todos" data-selecionar-todos></th><?php endif; ?>
                    <th>Cliente</th>
                    <th>Etapa</th>
                    <th>Próxima ação</th>
                    <?php if (!$ehCorretor): ?><th>Responsável</th><?php endif; ?>
                    <th>Última atividade</th>
                    <th class="col-acoes"><span class="sr-only">Ações</span></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($leads as $lead): $whats = lead_link_whatsapp($lead['telefone']); ?>
                <tr class="<?= $lead['atrasado'] ? 'is-late' : '' ?>">
                    <?php if ($podeTransferir): ?><td class="col-check"><input type="checkbox" value="<?= (int) $lead['id'] ?>" data-selecionar-lead aria-label="Selecionar <?= e($lead['nome']) ?>"></td><?php endif; ?>
                    <td class="col-cliente">
                        <a href="/pages/lead.php?id=<?= (int) $lead['id'] ?>" class="lead-link"><?= e($lead['nome']) ?></a>
                        <?php if ($lead['prioridade'] === 'alta'): ?><span class="pill pill-red">Alta</span><?php endif; ?>
                        <span class="lead-sub"><?= e(lead_formatar_telefone($lead['telefone'])) ?> · <?= e($lead['empreendimento_nome'] ?? 'Sem empreendimento') ?></span>
                    </td>
                    <td><span class="etapa-chip" style="--etapa-cor: <?= e($lead['etapa_cor'] ?? '#99a3b5') ?>"><?= e($lead['etapa_nome'] ?? lead_etapa_rotulo($lead['etapa'])) ?></span></td>
                    <td class="<?= $lead['atrasado'] ? 'txt-late' : '' ?>"><?= $lead['atrasado'] ? '<b>Atrasado</b> · ' : '' ?><?= e(lead_proxima_acao($lead)) ?></td>
                    <?php if (!$ehCorretor): ?><td><?= e($lead['responsavel_nome'] ?? '—') ?></td><?php endif; ?>
                    <td class="txt-muted"><?= e(tempo_relativo($lead['ultima_atividade_em'] ?? $lead['criado_em'])) ?></td>
                    <td class="col-acoes">
                        <?php if ($whats): ?>
                            <a class="icon-btn icon-btn-whats" href="<?= e($whats) ?>" target="_blank" rel="noopener" data-registrar="whatsapp" data-lead="<?= (int) $lead['id'] ?>" aria-label="WhatsApp de <?= e($lead['nome']) ?>"><?= icon('whatsapp') ?></a>
                            <a class="icon-btn" href="tel:<?= e(lead_telefone_normalizado($lead['telefone'])) ?>" data-registrar="ligacao" data-lead="<?= (int) $lead['id'] ?>" aria-label="Ligar para <?= e($lead['nome']) ?>"><?= icon('phone') ?></a>
                        <?php endif; ?>
                        <a class="icon-btn" href="/pages/lead.php?id=<?= (int) $lead['id'] ?>" aria-label="Abrir ficha de <?= e($lead['nome']) ?>"><?= icon('arrow-r') ?></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($paginas > 1): ?>
            <nav class="pager" aria-label="Paginação">
                <?php if ($pagina > 1): ?><a class="btn btn-outline btn-sm" href="<?= e(url_pagina($filtros, $pagina - 1)) ?>">← Anterior</a><?php endif; ?>
                <span>Página <?= $pagina ?> de <?= $paginas ?></span>
                <?php if ($pagina < $paginas): ?><a class="btn btn-outline btn-sm" href="<?= e(url_pagina($filtros, $pagina + 1)) ?>">Próxima →</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($podeTransferir) render_modal_transferir($destinos); ?>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
