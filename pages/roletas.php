<?php
require_once __DIR__ . '/../includes/auth.php';
$usuario = auth_require();
$empresaId = (int) $usuario['empresa_ativa_id'];

$stmt = db()->prepare('SELECT * FROM roletas WHERE empresa_id = :empresa_id ORDER BY nome');
$stmt->execute(['empresa_id' => $empresaId]);
$roletas = $stmt->fetchAll();

$pageTitle = 'Roletas — ' . APP_NAME;
$crumb = 'Roletas';
$activeNav = 'roletas';
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div><div class="eyebrow">Operação de vendas</div><h1>Roletas de distribuição</h1><p>Configure quem pode receber leads e em qual plantão.</p></div>
</div>

<div class="card">
    <div class="card-head"><div><h2>Plantões cadastrados</h2><p>Dados reais desta empresa — edição de regras ainda em desenvolvimento.</p></div><span class="pill pill-muted"><?= count($roletas) ?> roleta(s)</span></div>
    <?php foreach ($roletas as $r): ?>
        <div class="task-row">
            <div class="plantao-icon"><?= icon('pin') ?></div>
            <div class="who-info">
                <strong><?= htmlspecialchars($r['nome']) ?></strong>
                <span><?= $r['raio_presenca_metros'] ? (int) $r['raio_presenca_metros'] . ' m de raio' : 'Sem geolocalização' ?><?= $r['horario_inicio'] ? ' · ' . substr($r['horario_inicio'], 0, 5) . ' às ' . substr($r['horario_fim'], 0, 5) : '' ?></span>
            </div>
            <span class="pill <?= $r['ativa'] ? 'pill-green' : 'pill-muted' ?>"><?= $r['ativa'] ? 'Ativa' : 'Pausada' ?></span>
        </div>
    <?php endforeach; ?>
    <?php if (!$roletas): ?><p style="color:#8893a4;font-size:14px;">Nenhuma roleta cadastrada ainda.</p><?php endif; ?>
</div>

<div class="card stub-card" style="margin-top:20px;">
    <div class="stub-icon"><?= icon('pin') ?></div>
    <h2>Edição de regras em desenvolvimento</h2>
    <p>Criar/editar roleta, raio de presença por geolocalização e entrada automática de leads do Meta Ads chegam na próxima etapa do projeto.</p>
</div>
<?php require __DIR__ . '/../includes/layout_end.php'; ?>
