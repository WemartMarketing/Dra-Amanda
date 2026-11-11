<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/lead_componentes.php';

$usuario = auth_require();
$crumb = 'Meu perfil';
$activeNav = 'perfil';
$stmt = db()->prepare('SELECT usuarios.nome, usuarios.email, equipes.nome AS equipe_nome FROM usuarios LEFT JOIN equipes ON equipes.id = usuarios.equipe_id WHERE usuarios.id = :id');
$stmt->execute(['id' => (int) $usuario['id']]);
$dados = $stmt->fetch();

$pageTitle = 'Meu perfil — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>

<div class="header">
    <div><div class="eyebrow">Conta</div><h1>Meu perfil</h1><p>Seu nome aparece para a equipe e nos históricos dos clientes.</p></div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-head"><div><h2>Dados pessoais</h2></div></div>
        <div class="who profile-who">
            <div class="avatar avatar-lg"><?= e(lead_iniciais($dados['nome'])) ?></div>
            <div class="who-info"><strong><?= e($dados['nome']) ?></strong><span><?= e($usuario['funcao_nome'] ?? '') ?><?= $dados['equipe_nome'] ? ' · ' . e($dados['equipe_nome']) : '' ?></span></div>
        </div>
        <form data-api-form action="/api/perfil_salvar.php" data-recarregar>
            <div class="field"><label for="p-nome">Nome</label><input type="text" id="p-nome" name="nome" required maxlength="190" value="<?= e($dados['nome']) ?>" autocomplete="name"></div>
            <div class="field"><label for="p-email">E-mail de acesso</label><input type="email" id="p-email" name="email" required maxlength="190" value="<?= e($dados['email']) ?>" autocomplete="email"></div>
            <div class="field"><label for="p-senha-atual-1">Senha atual <small class="muted">(obrigatória só para trocar o e-mail)</small></label><input type="password" id="p-senha-atual-1" name="senha_atual" autocomplete="current-password"></div>
            <div class="form-alert" data-form-alerta hidden></div>
            <button type="submit" class="btn btn-primary"><?= icon('check') ?>Salvar dados</button>
        </form>
    </div>
    <div class="card">
        <div class="card-head"><div><h2>Trocar senha</h2><p>Mínimo de 8 caracteres. Você continua conectado neste aparelho.</p></div></div>
        <form data-api-form action="/api/perfil_salvar.php" data-recarregar>
            <input type="hidden" name="nome" value="<?= e($dados['nome']) ?>">
            <input type="hidden" name="email" value="<?= e($dados['email']) ?>">
            <div class="field"><label for="p-senha-atual">Senha atual</label><input type="password" id="p-senha-atual" name="senha_atual" required autocomplete="current-password"></div>
            <div class="field"><label for="p-nova">Nova senha</label><input type="password" id="p-nova" name="nova_senha" required minlength="8" autocomplete="new-password"></div>
            <div class="field"><label for="p-conf">Confirmar nova senha</label><input type="password" id="p-conf" name="confirmar_senha" required minlength="8" autocomplete="new-password"></div>
            <div class="form-alert" data-form-alerta hidden></div>
            <button type="submit" class="btn btn-primary"><?= icon('lock') ?>Trocar senha</button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
