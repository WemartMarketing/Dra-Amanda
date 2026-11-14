<?php
/**
 * GET /api/exportacao_baixar.php?id=&t= — baixa uma exportação preparada
 * em segundo plano. Só para o próprio usuário logado, com o token do link,
 * enquanto não expirou (24 h). Fica registrado na auditoria.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/relatorios/fila_exportacao.php';

if (!auth_check()) {
    header('Location: /login.php');
    exit;
}
$usuario = current_user();
$job = relexp_para_baixar($usuario, (int) ($_GET['id'] ?? 0), (string) ($_GET['t'] ?? ''));
if (!$job) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Arquivo não encontrado ou link expirado. Peça a exportação de novo na Central de Relatórios.';
    exit;
}
db()->prepare('UPDATE relatorio_exportacoes SET baixado_em = NOW() WHERE id = :id')->execute(['id' => $job['id']]);
auditoria_registrar((int) $usuario['empresa_ativa_id'], (int) $usuario['id'], 'relatorio.exportacao_baixada', 'relatorio', (int) $job['id'], ['relatorio' => $job['relatorio'], 'formato' => $job['formato']]);
$mime = ['xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'csv' => 'text/csv; charset=utf-8', 'pdf' => 'application/pdf'][pathinfo($job['nome_download'], PATHINFO_EXTENSION)] ?? 'application/octet-stream';
exportacao_cabecalhos($mime, $job['nome_download']);
$caminho = RELEXP_DIR . '/' . basename($job['arquivo']);
header('Content-Length: ' . filesize($caminho));
readfile($caminho);
