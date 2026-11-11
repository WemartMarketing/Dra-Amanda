<?php
require_once __DIR__ . '/includes/auth.php';

if (auth_check()) {
    $u = current_user();
    header('Location: ' . (!empty($u['eh_superadmin']) ? '/pages/superadmin.php' : '/pages/inicio.php'));
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
            header('Location: ' . (!empty($resultado['usuario']['eh_superadmin']) ? '/pages/superadmin.php' : '/pages/inicio.php'));
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
</head>
<body>
<div class="login-shell">
    <div class="login-card">
        <div class="brand"><span class="brandmark">W</span>WeMart <span class="hl">Sales</span></div>
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
</div>
</body>
</html>
