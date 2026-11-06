<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
$usuario = auth_require();
$empresaId = (int) $usuario['empresa_ativa_id'];
$podeGerenciar = rbac_pode_gerenciar($usuario);

$stmt = db()->prepare("SELECT cargo, COUNT(*) AS total FROM usuarios WHERE empresa_id = :empresa_id AND ativo = 1 GROUP BY cargo");
$stmt->execute(['empresa_id' => $empresaId]);
$porCargo = [];
foreach ($stmt->fetchAll() as $row) {
    $porCargo[$row['cargo']] = (int) $row['total'];
}
$totalUsuarios = array_sum($porCargo);
$totalSuperintendencias = (int) db()->query("SELECT COUNT(*) FROM superintendencias WHERE empresa_id = {$empresaId}")->fetchColumn();

$escopos = [
    'master_empresa' => 'Toda a empresa',
    'gp_lider' => 'Todos os produtos',
    'gerente_geral' => 'Todos os produtos',
    'gp_produto' => 'Produtos vinculados',
    'superintendente' => 'Sua superintendência',
    'gerente' => 'Sua equipe',
    'corretor' => 'Sua própria carteira',
];

$stmt = db()->prepare('SELECT usuarios.*, equipes.nome AS equipe_nome FROM usuarios LEFT JOIN equipes ON equipes.id = usuarios.equipe_id WHERE usuarios.empresa_id = :empresa_id ORDER BY usuarios.ativo DESC, usuarios.nome');
$stmt->execute(['empresa_id' => $empresaId]);
$usuariosLista = $stmt->fetchAll();

$equipesOpt = db()->prepare('SELECT id, nome FROM equipes WHERE empresa_id = :empresa_id ORDER BY nome');
$equipesOpt->execute(['empresa_id' => $empresaId]);
$equipesOpt = $equipesOpt->fetchAll();

$cargosDisponiveis = ['master_empresa', 'gp_lider', 'gerente_geral', 'gp_produto', 'superintendente', 'gerente', 'corretor'];

$pageTitle = 'Usuários e acessos — ' . APP_NAME;
$crumb = 'Usuários e acessos';
$activeNav = 'usuarios';
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div><div class="eyebrow">Administração</div><h1>Usuários e permissões</h1><p>Defina funções e controle o que cada pessoa pode acessar.</p></div>
    <?php if ($podeGerenciar): ?>
        <div class="actions"><button type="button" class="btn btn-primary" data-open-modal="modal-usuario" data-modo="criar"><?= icon('plus') ?>Novo usuário</button></div>
    <?php endif; ?>
</div>

<div class="kpi-grid">
    <div class="kpi"><div class="kpi-top"><span class="label">Usuários ativos</span><div class="kpi-icon"><?= icon('user') ?></div></div><div class="kpi-value"><?= $totalUsuarios ?></div><div class="kpi-sub">nesta empresa</div></div>
    <div class="kpi"><div class="kpi-top"><span class="label">Corretores</span><div class="kpi-icon"><?= icon('user') ?></div></div><div class="kpi-value"><?= $porCargo['corretor'] ?? 0 ?></div><div class="kpi-sub">vinculados à operação</div></div>
    <div class="kpi"><div class="kpi-top"><span class="label">Superintendências</span><div class="kpi-icon"><?= icon('building') ?></div></div><div class="kpi-value"><?= $totalSuperintendencias ?></div><div class="kpi-sub">hierarquias comerciais</div></div>
    <div class="kpi"><div class="kpi-top"><span class="label">Funções em uso</span><div class="kpi-icon"><?= icon('shield') ?></div></div><div class="kpi-value"><?= count($porCargo) ?></div><div class="kpi-sub">cargos configuráveis</div></div>
</div>

<div class="card">
    <div class="card-head">
        <div><h2>Funções da empresa</h2><p>Cada cargo tem um escopo de visualização fixo nesta etapa do projeto.</p></div>
        <?php if ($podeGerenciar): ?><button type="button" class="btn btn-outline btn-sm" data-open-modal="modal-nova-funcao"><?= icon('plus') ?>Criar função</button><?php endif; ?>
    </div>
    <table class="report-table">
        <thead><tr><th>Função</th><th>Escopo de acesso</th><th>Usuários</th></tr></thead>
        <tbody>
        <?php foreach ($escopos as $cargo => $escopo): ?>
            <tr>
                <td><strong><?= htmlspecialchars(rbac_rotulo_cargo($cargo)) ?></strong></td>
                <td><?= htmlspecialchars($escopo) ?></td>
                <td><?= $porCargo[$cargo] ?? 0 ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card" style="margin-top:20px;">
    <div class="card-head"><div><h2>Usuários</h2><p>Todas as pessoas com acesso a esta empresa.</p></div></div>
    <?php foreach ($usuariosLista as $u):
        $iniciais = mb_strtoupper(mb_substr($u['nome'], 0, 1) . mb_substr(strrchr($u['nome'], ' ') ?: '', 1, 1));
    ?>
        <div class="user-row">
            <div class="avatar"><?= htmlspecialchars($iniciais) ?></div>
            <div class="who-info">
                <strong><?= htmlspecialchars($u['nome']) ?></strong>
                <span><?= htmlspecialchars($u['email']) ?> · <?= htmlspecialchars(rbac_rotulo_cargo($u['cargo'])) ?><?= $u['equipe_nome'] ? ' · ' . htmlspecialchars($u['equipe_nome']) : '' ?></span>
            </div>
            <span class="pill <?= $u['ativo'] ? 'pill-green' : 'pill-muted' ?>"><?= $u['ativo'] ? 'Ativo' : 'Inativo' ?></span>
            <?php if ($podeGerenciar): ?>
                <div class="user-row-actions">
                    <button type="button" class="link-btn" data-open-modal="modal-usuario" data-modo="editar"
                        data-id="<?= $u['id'] ?>" data-nome="<?= htmlspecialchars($u['nome']) ?>" data-email="<?= htmlspecialchars($u['email']) ?>"
                        data-cargo="<?= $u['cargo'] ?>" data-equipe-id="<?= $u['equipe_id'] ?? '' ?>" data-ativo="<?= $u['ativo'] ?>">Editar</button>
                    <?php if ((int) $u['id'] !== (int) $usuario['id']): ?>
                        <button type="button" class="link-btn" data-toggle-ativo="<?= $u['id'] ?>" data-ativo-atual="<?= $u['ativo'] ?>"><?= $u['ativo'] ? 'Desativar' : 'Ativar' ?></button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if (!$usuariosLista): ?><p class="empty-note">Nenhum usuário cadastrado.</p><?php endif; ?>
</div>

<div class="card stub-card" style="margin-top:20px;">
    <div class="stub-icon"><?= icon('shield') ?></div>
    <h2>Edição de permissões em desenvolvimento</h2>
    <p>Esta etapa usa as 8 funções fixas do sistema. Criar cargos personalizados com escopos próprios chega em uma próxima etapa.</p>
</div>

<?php if ($podeGerenciar): ?>
<div class="modal-overlay" id="modal-usuario" hidden>
    <div class="modal-box">
        <button type="button" class="overlay-close" data-close-modal="modal-usuario">&times;</button>
        <h2 id="modal-usuario-titulo">Novo usuário</h2>
        <form data-form-usuario>
            <input type="hidden" name="id" value="">
            <div class="field"><label>Nome</label><input type="text" name="nome" required></div>
            <div class="field"><label>E-mail</label><input type="text" name="email" required></div>
            <div class="field"><label>Senha</label><input type="password" name="senha" placeholder="Deixe vazio para manter a atual" autocomplete="new-password"></div>
            <div class="field"><label>Cargo</label>
                <select name="cargo" required>
                    <?php foreach ($cargosDisponiveis as $c): ?><option value="<?= $c ?>"><?= htmlspecialchars(rbac_rotulo_cargo($c)) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label>Equipe (opcional)</label>
                <select name="equipe_id">
                    <option value="">Sem equipe</option>
                    <?php foreach ($equipesOpt as $e): ?><option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['nome']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <label class="checkbox-row" id="campo-ativo-usuario" style="display:none;"><input type="checkbox" name="ativo" value="1" checked> Usuário ativo</label>
            <button type="submit" class="btn btn-primary">Salvar</button>
        </form>
    </div>
</div>

<div class="modal-overlay" id="modal-nova-funcao" hidden>
    <div class="modal-box">
        <button type="button" class="overlay-close" data-close-modal="modal-nova-funcao">&times;</button>
        <div class="stub-icon" style="margin:0 auto 14px;"><?= icon('shield') ?></div>
        <h2 style="text-align:center;">Funções personalizadas em desenvolvimento</h2>
        <p style="text-align:center;color:#8893a4;font-size:14px;">Por enquanto, a empresa usa as 8 funções fixas do sistema (lista acima). A criação de cargos com escopos próprios chega em uma próxima etapa.</p>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
