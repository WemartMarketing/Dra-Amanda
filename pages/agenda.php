<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/estrutura.php';
require_once __DIR__ . '/../includes/lead_componentes.php';

$usuario = auth_require();
$crumb = 'Agenda';
$activeNav = 'agenda';
$ehCorretor = $usuario['escopo'] === 'propria_carteira';

$responsaveis = $ehCorretor ? [] : rbac_usuarios_atribuiveis($usuario);
$filtroResp = (int) ($_GET['responsavel'] ?? 0);
if ($filtroResp && !in_array($filtroResp, array_map(fn ($r) => (int) $r['id'], $responsaveis), true)) {
    $filtroResp = 0;
}
$visao = ($_GET['visao'] ?? 'lista') === 'semana' ? 'semana' : 'lista';
$base = $filtroResp ? ['usuario_id' => $filtroResp] : [];

$hoje = date('Y-m-d');
$amanha = date('Y-m-d', strtotime('+1 day'));
$atrasados = agenda_listar($usuario, $base + ['status' => ['agendado'], 'ate' => date('Y-m-d H:i:s'), 'limite' => 100]);
$deHoje = agenda_listar($usuario, $base + ['de' => $hoje . ' 00:00:00', 'ate' => $amanha . ' 00:00:00']);
$deHoje = array_values(array_filter($deHoje, fn ($c) => !($c['status'] === 'agendado' && strtotime($c['inicio']) < time())));
$proximos = agenda_listar($usuario, $base + ['status' => ['agendado'], 'de' => $amanha . ' 00:00:00', 'limite' => 60]);

// Semana (segunda a domingo), navegável.
$offsetSemana = max(-52, min(52, (int) ($_GET['semana'] ?? 0)));
$inicioSemana = strtotime('monday this week', strtotime($hoje)) + $offsetSemana * 7 * 86400;
$inicioSemana = strtotime(date('Y-m-d', $inicioSemana));
$dias = [];
if ($visao === 'semana') {
    $itensSemana = agenda_listar($usuario, $base + ['de' => date('Y-m-d 00:00:00', $inicioSemana), 'ate' => date('Y-m-d 00:00:00', strtotime('+7 days', $inicioSemana))]);
    for ($i = 0; $i < 7; $i++) {
        $dias[date('Y-m-d', strtotime("+{$i} days", $inicioSemana))] = [];
    }
    foreach ($itensSemana as $c) {
        $dias[substr($c['inicio'], 0, 10)][] = $c;
    }
}
$nomesDia = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

$pageTitle = ($ehCorretor ? 'Minha agenda' : 'Agenda') . ' — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>

<div class="header">
    <div>
        <div class="eyebrow"><?= $ehCorretor ? 'Meus compromissos' : 'Compromissos da equipe' ?></div>
        <h1><?= $ehCorretor ? 'Minha agenda' : 'Agenda' ?></h1>
        <p>Visitas, retornos e tarefas. Marque o que aconteceu para manter o histórico do cliente em dia.</p>
    </div>
    <div class="actions">
        <button type="button" class="btn btn-primary" data-open-modal="modal-agendar"><?= icon('plus') ?>Novo compromisso</button>
    </div>
</div>

<form class="filters-row" method="get" aria-label="Opções da agenda">
    <div class="segmented segmented-inline" role="group" aria-label="Visualização">
        <a class="seg-link<?= $visao === 'lista' ? ' is-active' : '' ?>" href="?<?= e(http_build_query(array_filter(['responsavel' => $filtroResp]))) ?>"><?= icon('list') ?>Lista</a>
        <a class="seg-link<?= $visao === 'semana' ? ' is-active' : '' ?>" href="?<?= e(http_build_query(array_filter(['responsavel' => $filtroResp, 'visao' => 'semana']))) ?>"><?= icon('calendar') ?>Semana</a>
    </div>
    <?php if ($visao === 'semana'): ?><input type="hidden" name="visao" value="semana"><?php endif; ?>
    <?php if ($responsaveis): ?>
        <label class="sr-only" for="f-resp">Responsável</label>
        <select class="select-filter" id="f-resp" name="responsavel" data-auto-filtro>
            <option value="">Toda a equipe</option>
            <?php foreach ($responsaveis as $r): ?><option value="<?= (int) $r['id'] ?>" <?= $filtroResp === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['nome']) ?></option><?php endforeach; ?>
        </select>
    <?php endif; ?>
    <span class="filters-note"><?= count($atrasados) ?> atrasado<?= count($atrasados) === 1 ? '' : 's' ?> · <?= count($deHoje) ?> hoje · <?= count($proximos) ?> próximos</span>
</form>

<?php if ($visao === 'lista'): ?>
    <?php if ($atrasados): ?>
        <div class="card agenda-group agenda-group-late">
            <div class="card-head"><div><h2><?= icon('alert') ?>Atrasados</h2><p>Compromissos que já passaram e não foram marcados.</p></div><span class="pill pill-red"><?= count($atrasados) ?></span></div>
            <?php foreach ($atrasados as $c) render_compromisso($c, !$ehCorretor); ?>
        </div>
    <?php endif; ?>
    <div class="card agenda-group">
        <div class="card-head"><div><h2>Hoje</h2><p><?= e(ucfirst(strftime_pt(time()))) ?></p></div><span class="pill pill-blue"><?= count($deHoje) ?></span></div>
        <?php foreach ($deHoje as $c) render_compromisso($c, !$ehCorretor); ?>
        <?php if (!$deHoje): ?><p class="empty-note">Nada marcado para hoje.</p><?php endif; ?>
    </div>
    <div class="card agenda-group">
        <div class="card-head"><div><h2>Próximos</h2><p>A partir de amanhã.</p></div><span class="pill pill-muted"><?= count($proximos) ?></span></div>
        <?php $diaAtual = ''; foreach ($proximos as $c): $dia = substr($c['inicio'], 0, 10); if ($dia !== $diaAtual): $diaAtual = $dia; ?>
            <h3 class="subhead"><?= e(ucfirst(strftime_pt(strtotime($dia)))) ?></h3>
        <?php endif; render_compromisso($c, !$ehCorretor); endforeach; ?>
        <?php if (!$proximos): ?><p class="empty-note">Nenhum compromisso futuro.</p><?php endif; ?>
    </div>
<?php else: ?>
    <div class="week-nav">
        <a class="btn btn-outline btn-sm" href="?<?= e(http_build_query(array_filter(['responsavel' => $filtroResp, 'visao' => 'semana', 'semana' => $offsetSemana - 1]))) ?>">← Semana anterior</a>
        <strong><?= date('d/m', $inicioSemana) ?> a <?= date('d/m', strtotime('+6 days', $inicioSemana)) ?></strong>
        <a class="btn btn-outline btn-sm" href="?<?= e(http_build_query(array_filter(['responsavel' => $filtroResp, 'visao' => 'semana', 'semana' => $offsetSemana + 1]))) ?>">Próxima semana →</a>
    </div>
    <div class="week-grid">
        <?php foreach ($dias as $dia => $itens): $ts = strtotime($dia); ?>
            <div class="week-day<?= $dia === $hoje ? ' is-today' : '' ?>">
                <div class="week-day-head"><span><?= $nomesDia[(int) date('w', $ts)] ?></span><strong><?= date('d', $ts) ?></strong></div>
                <?php foreach ($itens as $c): $vencido = $c['status'] === 'agendado' && strtotime($c['inicio']) < time(); ?>
                    <a class="week-item week-item-<?= e($c['tipo']) ?><?= $vencido ? ' is-late' : '' ?><?= $c['status'] !== 'agendado' ? ' is-done' : '' ?>" href="<?= $c['lead_id'] ? '/pages/lead.php?id=' . (int) $c['lead_id'] . '&aba=agenda' : '?visao=lista' ?>">
                        <b><?= date('H\hi', strtotime($c['inicio'])) ?></b> <?= e($c['titulo']) ?>
                        <?php if (!$ehCorretor): ?><small><?= e($c['usuario_nome']) ?></small><?php endif; ?>
                    </a>
                <?php endforeach; ?>
                <?php if (!$itens): ?><span class="week-empty">—</span><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php render_modal_agendar(null, $responsaveis, false, 'retorno'); ?>
<?php render_modal_compromisso(); ?>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
