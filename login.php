<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/logo.php';
require_once __DIR__ . '/includes/rbac.php';

if (auth_check()) {
    $u = current_user();
    header('Location: ' . rbac_pagina_inicial($u));
    exit;
}

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validar_requisicao()) {
        $erro = 'Sessão expirada, tente novamente.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $senha = (string) ($_POST['senha'] ?? '');
        $resultado = $email !== '' && $senha !== '' ? auth_login($email, $senha) : ['ok' => false, 'bloqueado' => false];
        if ($resultado['ok']) {
            header('Location: ' . rbac_pagina_inicial(current_user()));
            exit;
        }
        $erro = !empty($resultado['bloqueado'])
            ? 'Muitas tentativas. Tente novamente em alguns minutos.'
            : 'E-mail ou senha inválidos.';
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
<title>Entrar — <?= htmlspecialchars(APP_NAME) ?></title>
<link rel="stylesheet" href="/assets/css/app.css">
<?= favicon_tags() ?>
</head>
<body>
<div class="login-shell">
    <div class="login-card">
        <div class="brand"><?= logo_html('claro') ?></div>
        <h1>Entrar na sua conta</h1>
        <p class="sub">Acesse seus leads e atendimentos.</p>

        <?php if ($erro): ?>
            <div class="login-error"><?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>

        <form method="post" action="/login.php">
            <?= csrf_field() ?>
            <div class="field">
                <label for="email">E-mail</label>
                <input type="email" id="email" name="email" required autofocus value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="senha">Senha</label>
                <input type="password" id="senha" name="senha" required>
            </div>
            <button type="submit" class="btn btn-primary">Entrar</button>
        </form>

        <div class="login-demo">
            <b>Conta de demonstração</b><br>
            corretor: carlos@hmvendas.demo · senha: demo123<br>
            gestor: fernanda.gestora@hmvendas.demo · senha: demo123<br>
            superadmin: superadmin@wemart.demo · senha: demo123
        </div>
    </div>
    <p class="login-legal">Ao entrar você concorda com o uso dos dados conforme a LGPD — <a href="/privacidade.php">privacidade e uso dos dados</a>.</p>
</div>
</body>
</html>
