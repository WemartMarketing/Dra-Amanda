<?php
require_once __DIR__ . '/includes/auth.php';
if (!auth_check()) {
    header('Location: /login.php');
    exit;
}
$usuario = current_user();
header('Location: ' . (!empty($usuario['eh_superadmin']) ? '/pages/superadmin.php' : '/pages/inicio.php'));
exit;
