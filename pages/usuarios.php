<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
$usuario = auth_require();
$empresaId = (int) $usuario['empresa_ativa_id'];
$podeGerenciar = usuario_tem_permissao($usuario, 'administrar_usuarios');

if (!$podeGerenciar) {
    http_response_code(403);
    $pageTitle = 'Acesso restrito — ' . APP_NAME;
    $crumb = 'Usuários e acessos';
    $activeNav = 'usuarios';
    require __DIR__ . '/../includes/layout_start.php';
    echo '<div class="card stub-card"><div class="stub-icon">' . icon('alert') . '</div><h2>Acesso restrito</h2><p>Sua função não tem a permissão "Administrar usuários e funções".</p></div>';
    require __DIR__ . '/../includes/layout_end.php';
    exit;
}

$todasPermissoes = funcoes_catalogo_permissoes();
$rotulosEscopo = funcoes_rotulos_escopo();

$funcoesLista = db()->prepare('SELECT * FROM funcoes WHERE empresa_id = :empresa_id ORDER BY padrao_sistema DESC, nome');
$funcoesLista->execute(['empresa_id' => $empresaId]);
$funcoesLista = $funcoesLista->fetchAll();

$stmt = db()->prepare('SELECT funcao_id, permissao_chave FROM funcao_permissoes WHERE funcao_id IN (SELECT id FROM funcoes WHERE empresa_id = :empresa_id)');
$stmt->execute(['empresa_id' => $empresaId]);
$permissoesPorFuncao = [];
foreach ($stmt->fetchAll() as $row) {
    $permissoesPorFuncao[$row['funcao_id']][] = $row['permissao_chave'];
}

$stmt = db()->prepare('SELECT funcao_id, COUNT(*) AS total FROM usuarios WHERE empresa_id = :empresa_id AND ativo = 1 GROUP BY funcao_id');
$stmt->execute(['empresa_id' => $empresaId]);
$usuariosPorFuncao = [];
foreach ($stmt->fetchAll() as $row) {
    $usuariosPorFuncao[$row['funcao_id']] = (int) $row['total'];
}
$totalUsuarios = array_sum($usuariosPorFuncao);
$totalCorretores = 0;
foreach ($funcoesLista as $f) {
    if ($f['escopo'] === 'propria_carteira') {
        $totalCorretores += $usuariosPorFuncao[$f['id']] ?? 0;
    }
}
$totalSuperintendencias = (int) db()->query("SELECT COUNT(*) FROM superintendencias WHERE empresa_id = {$empresaId}")->fetchColumn();

$stmt = db()->prepare('SELECT usuarios.*, equipes.nome AS equipe_nome, funcoes.nome AS funcao_nome FROM usuarios LEFT JOIN equipes ON equipes.id = usuarios.equipe_id LEFT JOIN funcoes ON funcoes.id = usuarios.funcao_id WHERE usuarios.empresa_id = :empresa_id ORDER BY usuarios.ativo DESC, usuarios.nome');
$stmt->execute(['empresa_id' => $empresaId]);
$usuariosLista = $stmt->fetchAll();

$empreendimentosOpt = db()->prepare('SELECT id, nome, ativo FROM empreendimentos WHERE empresa_id = :empresa_id ORDER BY ativo DESC, nome');
$empreendimentosOpt->execute(['empresa_id' => $empresaId]);
$empreendimentosOpt = $empreendimentosOpt->fetchAll();

$stmt = db()->prepare('SELECT ue.usuario_id, ue.empreendimento_id FROM usuario_empreendimentos ue INNER JOIN usuarios u ON u.id = ue.usuario_id WHERE u.empresa_id = :empresa_id');
$stmt->execute(['empresa_id' => $empresaId]);
$empreendimentosPorUsuario = [];
foreach ($stmt->fetchAll() as $row) {
    $empreendimentosPorUsuario[$row['usuario_id']][] = (int) $row['empreendimento_id'];
}

$equipesOpt = db()->prepare('SELECT id, nome FROM equipes WHERE empresa_id = :empresa_id ORDER BY nome');
$equipesOpt->execute(['empresa_id' => $empresaId]);
$equipesOpt = $equipesOpt->fetchAll();

$pageTitle = 'Usuários e acessos — ' . APP_NAME;
$crumb = 'Usuários e acessos';
$activeNav = 'usuarios';
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div><div class="eyebrow">Administração</div><h1>Usuários e permissões</h1><p>Defina funções e controle o que cada pessoa pode acessar.</p></div>
    <div class="actions"><button type="button" class="btn btn-primary" data-open-modal="modal-usuario" data-modo="criar"><?= icon('plus') ?>Novo usuário</button></div>
</div>

<div class="kpi-grid">
    <div class="kpi"><div class="kpi-top"><span class="label">Usuários ativos</span><div class="kpi-icon"><?= icon('user') ?></div></div><div class="kpi-value"><?= $totalUsuarios ?></div><div class="kpi-sub">nesta empresa</div></div>
    <div class="kpi"><div class="kpi-top"><span class="label">Corretores</span><div class="kpi-icon"><?= icon('user') ?></div></div><div class="kpi-value"><?= $totalCorretores ?></div><div class="kpi-sub">vinculados à operação</div></div>
    <div class="kpi"><div class="kpi-top"><span class="label">Superintendências</span><div class="kpi-icon"><?= icon('building') ?></div></div><div class="kpi-value"><?= $totalSuperintendencias ?></div><div class="kpi-sub">hierarquias comerciais</div></div>
    <div class="kpi"><div class="kpi-top"><span class="label">Funções em uso</span><div class="kpi-icon"><?= icon('shield') ?></div></div><div class="kpi-value"><?= count($funcoesLista) ?></div><div class="kpi-sub">cargos configuráveis</div></div>
</div>

<div class="card">
    <div class="card-head">
        <div><h2>Funções da empresa</h2><p>Crie funções com o escopo e as permissões que fizerem sentido para sua operação.</p></div>
        <button type="button" class="btn btn-outline btn-sm" data-open-modal="modal-funcao" data-modo="criar"><?= icon('plus') ?>Criar função</button>
    </div>
    <table class="report-table">
        <thead><tr><th>Função</th><th>Escopo de acesso</th><th>Permissões</th><th>Usuários</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($funcoesLista as $f):
            $permissoesFuncao = $permissoesPorFuncao[$f['id']] ?? [];
        ?>
            <tr>
                <td><strong><?= htmlspecialchars($f['nome']) ?></strong></td>
                <td><?= htmlspecialchars($rotulosEscopo[$f['escopo']] ?? $f['escopo']) ?></td>
                <td>
                    <?php if ($permissoesFuncao): foreach ($todasPermissoes as $p): if (!in_array($p['chave'], $permissoesFuncao, true)) continue; ?>
                        <span class="pill pill-muted" style="margin:2px 4px 2px 0;font-size:11px;"><?= htmlspecialchars($p['rotulo']) ?></span>
                    <?php endforeach; else: ?>—<?php endif; ?>
                </td>
                <td><?= $usuariosPorFuncao[$f['id']] ?? 0 ?></td>
                <td>
                    <button type="button" class="link-btn" data-open-modal="modal-funcao" data-modo="editar"
                        data-id="<?= $f['id'] ?>" data-nome="<?= htmlspecialchars($f['nome']) ?>" data-escopo="<?= $f['escopo'] ?>"
                        data-permissoes="<?= htmlspecialchars(implode(',', $permissoesFuncao)) ?>">Editar</button>
                </td>
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
                <span><?= htmlspecialchars($u['email']) ?> · <?= htmlspecialchars($u['funcao_nome'] ?? '—') ?><?= $u['equipe_nome'] ? ' · ' . htmlspecialchars($u['equipe_nome']) : '' ?></span>
            </div>
            <span class="pill <?= $u['ativo'] ? 'pill-green' : 'pill-muted' ?>"><?= $u['ativo'] ? 'Ativo' : 'Inativo' ?></span>
            <div class="user-row-actions">
                <button type="button" class="link-btn" data-open-modal="modal-usuario" data-modo="editar"
                    data-id="<?= $u['id'] ?>" data-nome="<?= htmlspecialchars($u['nome']) ?>" data-email="<?= htmlspecialchars($u['email']) ?>"
                    data-funcao-id="<?= $u['funcao_id'] ?>" data-equipe-id="<?= $u['equipe_id'] ?? '' ?>" data-ativo="<?= $u['ativo'] ?>"
                    data-empreendimentos="<?= implode(',', $empreendimentosPorUsuario[$u['id']] ?? []) ?>">Editar</button>
                <?php if ((int) $u['id'] !== (int) $usuario['id']): ?>
                    <button type="button" class="link-btn" data-toggle-ativo="<?= $u['id'] ?>" data-ativo-atual="<?= $u['ativo'] ?>"><?= $u['ativo'] ? 'Desativar' : 'Ativar' ?></button>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (!$usuariosLista): ?><p class="empty-note">Nenhum usuário cadastrado.</p><?php endif; ?>
</div>

<div class="modal-overlay" id="modal-usuario" hidden>
    <div class="modal-box">
        <button type="button" class="overlay-close" data-close-modal="modal-usuario">&times;</button>
        <h2 id="modal-usuario-titulo">Novo usuário</h2>
        <form data-form-usuario>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="">
            <div class="field"><label>Nome</label><input type="text" name="nome" required></div>
            <div class="field"><label>E-mail</label><input type="text" name="email" required></div>
            <div class="field"><label>Senha</label><input type="password" name="senha" placeholder="Deixe vazio para manter a atual" autocomplete="new-password"></div>
            <div class="field"><label>Função</label>
                <select name="funcao_id" required>
                    <?php foreach ($funcoesLista as $f): ?><option value="<?= $f['id'] ?>" data-escopo="<?= $f['escopo'] ?>"><?= htmlspecialchars($f['nome']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="field" id="campo-empreendimentos-usuario" hidden>
                <label>Empreendimentos que esta pessoa acompanha</label>
                <small class="field-hint">Funções com escopo "Produtos vinculados" só enxergam os leads destes empreendimentos.</small>
                <div class="checkbox-grid">
                    <?php foreach ($empreendimentosOpt as $e): ?>
                        <label class="checkbox-row"><input type="checkbox" name="empreendimentos[]" value="<?= $e['id'] ?>"> <?= htmlspecialchars($e['nome']) ?><?= $e['ativo'] ? '' : ' (inativo)' ?></label>
                    <?php endforeach; ?>
                </div>
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

<div class="modal-overlay" id="modal-funcao" hidden>
    <div class="modal-box modal-wide">
        <button type="button" class="overlay-close" data-close-modal="modal-funcao">&times;</button>
        <h2 id="modal-funcao-titulo">Criar função</h2>
        <form data-form-funcao>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="">
            <div class="field"><label>Nome da função</label><input type="text" name="nome" required placeholder="Ex.: Corretor sênior"></div>
            <div class="field"><label>Escopo de acesso</label>
                <select name="escopo" required>
                    <?php foreach ($rotulosEscopo as $valor => $rotulo): ?><option value="<?= $valor ?>"><?= htmlspecialchars($rotulo) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Permissões</label>
                <div class="checkbox-grid">
                    <?php foreach ($todasPermissoes as $p): ?>
                        <label class="checkbox-row"><input type="checkbox" name="permissoes[]" value="<?= $p['chave'] ?>"> <?= htmlspecialchars($p['rotulo']) ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Salvar função</button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
