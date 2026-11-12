<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/estrutura.php';
require_once __DIR__ . '/../includes/lead_componentes.php';
require_once __DIR__ . '/../includes/pendencias_ui.php';
require_once __DIR__ . '/../includes/whatsapp_conversas.php';

$usuario = auth_require();
$crumb = 'Pendências';
$activeNav = 'inicio';
$empresaId = (int) $usuario['empresa_ativa_id'];
$pendencias = pendencias_do_usuario($usuario, 100);
$etapas = funil_etapas($empresaId);
$podeWhatsapp = wa_pode_atender($usuario) && wa_central_ativa($empresaId);

$pageTitle = 'Pendências — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div>
        <div class="eyebrow">Sua central</div>
        <h1>Pendências</h1>
        <p>O que precisa da sua atenção nos seus leads: clientes que escreveram e confirmações de contato.</p>
    </div>
</div>
<?php if ($pendencias): ?>
    <div class="pend-lista">
        <?php foreach ($pendencias as $p) { render_pendencia($p, $etapas, $podeWhatsapp); } ?>
    </div>
<?php else: ?>
    <div class="card stub-card"><div class="stub-icon"><?= icon('check') ?></div><h2>Nada pendente</h2><p>Quando um cliente escrever ou houver algo para confirmar, aparece aqui.</p></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/layout_end.php'; ?>
