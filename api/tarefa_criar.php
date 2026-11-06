<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';

$usuario = auth_require();
$leadId = (int) ($_POST['lead_id'] ?? 0);
$titulo = trim($_POST['titulo'] ?? '');

if (!$leadId || $titulo === '' || !rbac_pode_ver_lead($usuario, $leadId)) {
    http_response_code(422);
    exit('Dados inválidos.');
}

db()->prepare('INSERT INTO lead_tarefas (lead_id, titulo) VALUES (:lead_id, :titulo)')
    ->execute(['lead_id' => $leadId, 'titulo' => $titulo]);

echo 'ok';
