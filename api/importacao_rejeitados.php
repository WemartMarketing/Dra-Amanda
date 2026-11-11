<?php
/**
 * GET /api/importacao_rejeitados.php?lista=ID — baixa o CSV das linhas
 * recusadas na importação que o próprio usuário acabou de fazer (fica
 * disponível por 1 hora, depois o cron apaga). Só leitura.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/importacao.php';

if (!auth_check()) {
    http_response_code(401);
    exit('Sessão expirada.');
}
$usuario = current_user();
if (!empty($usuario['eh_superadmin']) || !usuario_tem_permissao($usuario, 'gerenciar_oferta_ativa')) {
    http_response_code(403);
    exit('Sem permissão.');
}
$listaId = (int) ($_GET['lista'] ?? 0);
$lista = oferta_lista_carregar($usuario, $listaId);
$token = $_SESSION['importacao_relatorios'][$listaId]['rejeitados_token'] ?? null;
$caminho = ($lista && $token) ? importacao_caminho($token, 'csv') : null;
if (!$caminho || !is_file($caminho)) {
    http_response_code(404);
    exit('Arquivo indisponível (expira 1 hora depois da importação).');
}
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="recusados-lista-' . $listaId . '.csv"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($caminho);
