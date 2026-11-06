<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/leads.php';

$usuario = auth_require();
$empresaId = (int) $usuario['empresa_ativa_id'];
$scope = rbac_leads_scope($usuario);

// Filtro opcional por empreendimento (querystring ?empreendimento=ID).
$filtroEmpreendimento = isset($_GET['empreendimento']) && $_GET['empreendimento'] !== '' ? (int) $_GET['empreendimento'] : null;

$sql = '
    SELECT leads.*, empreendimentos.nome AS empreendimento_nome
    FROM leads
    LEFT JOIN empreendimentos ON empreendimentos.id = leads.empreendimento_id
    WHERE ' . $scope['sql'] . " AND leads.etapa <> 'perdido'";
$params = $scope['params'];
if ($filtroEmpreendimento) {
    $sql .= ' AND leads.empreendimento_id = :filtro_emp';
    $params['filtro_emp'] = $filtroEmpreendimento;
}
$sql .= ' ORDER BY (leads.data_retorno IS NOT NULL AND leads.data_retorno <= CURDATE()) DESC, leads.criado_em DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$todosLeads = $stmt->fetchAll();

$colunas = [];
foreach (lead_etapas_funil() as $etapa) {
    $colunas[$etapa] = [];
}
foreach ($todosLeads as $lead) {
    $colunas[$lead['etapa']][] = $lead;
}

$stmtEmp = db()->prepare('SELECT id, nome FROM empreendimentos WHERE empresa_id = :empresa_id AND ativo = 1 ORDER BY nome');
$stmtEmp->execute(['empresa_id' => $empresaId]);
$empreendimentos = $stmtEmp->fetchAll();

function tempoRelativo(string $data): string
{
    $min = (int) round((time() - strtotime($data)) / 60);
    if ($min < 60) return "Há {$min} min";
    $h = (int) round($min / 60);
    if ($h < 24) return "Há {$h} h";
    return 'Há ' . (int) round($h / 24) . ' d';
}

$pageTitle = 'Meu Kanban — ' . APP_NAME;
$crumb = 'Meus leads';
$activeNav = 'kanban';
require __DIR__ . '/../includes/layout_start.php';
?>

<div class="header">
    <div>
        <div class="eyebrow">Meus atendimentos</div>
        <h1>Meu Kanban</h1>
        <p>Arraste um card para mudar a etapa, ou abra o cliente para atualizar.</p>
    </div>
    <div class="actions">
        <a href="/pages/lead.php" class="btn btn-primary"><?= icon('plus') ?>Novo lead</a>
    </div>
</div>

<form class="filters-row" method="get">
    <select class="select-filter" name="empreendimento" onchange="this.form.submit()">
        <option value="">Todos os empreendimentos</option>
        <?php foreach ($empreendimentos as $emp): ?>
            <option value="<?= $emp['id'] ?>" <?= $filtroEmpreendimento === (int) $emp['id'] ? 'selected' : '' ?>><?= htmlspecialchars($emp['nome']) ?></option>
        <?php endforeach; ?>
    </select>
    <span class="filters-note"><?= count($todosLeads) ?> leads exibidos</span>
</form>

<div class="kanban-scroll">
    <div class="kanban" id="kanban">
        <?php foreach ($colunas as $etapa => $leadsDaColuna): ?>
            <div class="kcol kcol-<?= $etapa ?>" data-etapa="<?= $etapa ?>">
                <div class="kcol-head">
                    <strong><?= htmlspecialchars(lead_etapa_rotulo($etapa)) ?></strong>
                    <span class="kcol-count"><?= count($leadsDaColuna) ?></span>
                </div>
                <?php foreach ($leadsDaColuna as $lead):
                    $podeVoltar = lead_etapa_adjacente($etapa, -1);
                    $podeAvancar = lead_etapa_adjacente($etapa, 1);
                    $atrasado = $lead['data_retorno'] && strtotime($lead['data_retorno']) < strtotime(date('Y-m-d'));
                ?>
                    <article class="kcard" draggable="true" data-id="<?= $lead['id'] ?>" data-etapa="<?= $etapa ?>">
                        <div class="kcard-top">
                            <strong><?= htmlspecialchars($lead['nome']) ?></strong>
                            <?php if ($lead['telefone']): ?>
                                <a class="whatsapp-link" href="https://wa.me/55<?= preg_replace('/\D/', '', $lead['telefone']) ?>" target="_blank" rel="noopener" title="WhatsApp"><?= icon('whatsapp') ?></a>
                            <?php endif; ?>
                        </div>
                        <p><?= htmlspecialchars($lead['empreendimento_nome'] ?? 'Sem produto') ?></p>
                        <div class="kcard-foot">
                            <?= icon('clock') ?>
                            <span class="<?= $atrasado ? 'urgent' : '' ?>" style="<?= $atrasado ? 'color:var(--red);font-weight:700;' : '' ?>"><?= htmlspecialchars(tempoRelativo($lead['criado_em'])) ?></span>
                        </div>
                        <div class="kcard-move">
                            <?php if ($podeVoltar): ?><button type="button" data-move="<?= $lead['id'] ?>" data-destino="<?= $podeVoltar ?>">← Voltar</button><?php endif; ?>
                            <a href="/pages/lead.php?id=<?= $lead['id'] ?>"><?= icon('arrow-r') ?></a>
                            <?php if ($podeAvancar): ?><button type="button" data-move="<?= $lead['id'] ?>" data-destino="<?= $podeAvancar ?>">Avançar →</button><?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
