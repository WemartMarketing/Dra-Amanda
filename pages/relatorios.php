<?php
require_once __DIR__ . '/../includes/auth.php';
$usuario = auth_require();

$pageTitle = 'Relatórios — ' . APP_NAME;
$crumb = 'Relatórios';
$activeNav = 'relatorios';
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div><div class="eyebrow">Visão do gestor</div><h1>Desempenho comercial</h1><p>Acompanhe resultados sem precisar abrir os leads individualmente.</p></div>
</div>
<div class="card stub-card">
    <div class="stub-icon"><?= icon('chart') ?></div>
    <h2>Em desenvolvimento</h2>
    <p>Os KPIs consolidados, o funil de conversão e a exportação para Excel desta tela chegam na próxima etapa do projeto.</p>
</div>
<?php require __DIR__ . '/../includes/layout_end.php'; ?>
