<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
if (!auth_check()) {
    header('Location: /login.php');
    exit;
}
$usuario = current_user();
header('Location: ' . rbac_pagina_inicial($usuario));
exit;
