<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
$usuario = auth_require();
$empresaId = (int) $usuario['empresa_ativa_id'];

$stmt = db()->prepare("SELECT cargo, COUNT(*) AS total FROM usuarios WHERE empresa_id = :empresa_id AND ativo = 1 GROUP BY cargo");
$stmt->execute(['empresa_id' => $empresaId]);
$porCargo = [];
foreach ($stmt->fetchAll() as $row) {
    $porCargo[$row['cargo']] = (int) $row['total'];
}
$totalUsuarios = array_sum($porCargo);

$escopos = [
    'master_empresa' => 'Toda a empresa',
    'gp_lider' => 'Todos os produtos',
    'gerente_geral' => 'Todos os produtos',
    'gp_produto' => 'Produtos vinculados',
    'superintendente' => 'Sua superintendência',
    'gerente' => 'Sua equipe',
    'corretor' => 'Sua própria carteira',
];

$pageTitle = 'Usuários e acessos — ' . APP_NAME;
$crumb = 'Usuários e acessos';
$activeNav = 'usuarios';
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div><div class="eyebrow">Administração</div><h1>Usuários e permissões</h1><p>Defina funções e controle o que cada pessoa pode acessar.</p></div>
</div>

<div class="kpi-grid">
    <div class="kpi"><div class="kpi-top"><span class="label">Usuários ativos</span><div class="kpi-icon"><?= icon('user') ?></div></div><div class="kpi-value"><?= $totalUsuarios ?></div><div class="kpi-sub">nesta empresa</div></div>
    <div class="kpi"><div class="kpi-top"><span class="label">Corretores</span><div class="kpi-icon"><?= icon('user') ?></div></div><div class="kpi-value"><?= $porCargo['corretor'] ?? 0 ?></div><div class="kpi-sub">vinculados à operação</div></div>
    <div class="kpi"><div class="kpi-top"><span class="label">Gerentes</span><div class="kpi-icon"><?= icon('shield') ?></div></div><div class="kpi-value"><?= $porCargo['gerente'] ?? 0 ?></div><div class="kpi-sub">liderando equipes</div></div>
    <div class="kpi"><div class="kpi-top"><span class="label">Funções em uso</span><div class="kpi-icon"><?= icon('shield') ?></div></div><div class="kpi-value"><?= count($porCargo) ?></div><div class="kpi-sub">cargos configuráveis</div></div>
</div>

<div class="card">
    <div class="card-head"><div><h2>Funções da empresa</h2><p>Cada cargo tem um escopo de visualização fixo nesta etapa do projeto.</p></div></div>
    <table style="width:100%;border-collapse:collapse;">
        <thead><tr style="text-align:left;font-size:11.5px;text-transform:uppercase;color:#99a3b5;"><th style="padding:10px 6px;">Função</th><th>Escopo de acesso</th><th>Usuários</th></tr></thead>
        <tbody>
        <?php foreach ($escopos as $cargo => $escopo): ?>
            <tr style="border-top:1px solid var(--line);">
                <td style="padding:12px 6px;font-weight:700;"><?= htmlspecialchars(rbac_rotulo_cargo($cargo)) ?></td>
                <td><?= htmlspecialchars($escopo) ?></td>
                <td><?= $porCargo[$cargo] ?? 0 ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card stub-card" style="margin-top:20px;">
    <div class="stub-icon"><?= icon('shield') ?></div>
    <h2>Edição de funções em desenvolvimento</h2>
    <p>Criar cargos personalizados, convidar usuários e editar permissões granulares chegam na próxima etapa do projeto.</p>
</div>
<?php require __DIR__ . '/../includes/layout_end.php'; ?>
