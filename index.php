<?php
require_once __DIR__ . '/includes/auth.php';
header('Location: ' . (auth_check() ? '/pages/inicio.php' : '/login.php'));
exit;
