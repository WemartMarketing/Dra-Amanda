<?php
/**
 * GET /api/lead_criativo.php?lead_id=N — anúncio que gerou o lead (JSON),
 * para a janela "Ver criativo" do Kanban. Só para quem pode ver o lead
 * (mesmo escopo da ficha); para qualquer outro, 404 sem confirmar nada.
 * A busca é sempre pelo lead da empresa do usuário — nunca por id de anúncio.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/leads.php';
require_once __DIR__ . '/../includes/meta_criativos.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');
$sair = function (int $st, array $j): void {
    http_response_code($st);
    echo json_encode($j, JSON_UNESCAPED_UNICODE);
    exit;
};
if (!auth_check()) {
    $sair(401, ['ok' => false, 'erro' => 'Sessão expirada. Entre novamente.']);
}
$usuario = current_user();
$leadId = (int) ($_GET['lead_id'] ?? 0);
if (!empty($usuario['eh_superadmin']) || !$leadId || !lead_carregar($usuario, $leadId)) {
    $sair(404, ['ok' => false, 'erro' => 'Lead não encontrado.']);
}
if (!modulo_tabela_existe('meta_anuncios')) {
    $sair(200, ['ok' => false, 'erro' => 'A origem dos anúncios fica disponível depois da atualização do banco (migração 011).']);
}
$empresaId = (int) $usuario['empresa_ativa_id'];
meta_criativo_sincronizar_se_novo($empresaId, $leadId);
$c = meta_criativo_do_lead($empresaId, $leadId);
if (!$c) {
    $sair(200, ['ok' => true, 'tem_origem' => false]);
}
$sair(200, ['ok' => true, 'tem_origem' => true] + $c);
