<?php
/**
 * POST /api/relatorio_favorito.php — filtros favoritos da Central de Relatórios.
 *   acao=salvar  { relatorio, nome, filtros (query string) }
 *   acao=remover { id }
 * Cada usuário só vê e mexe nos próprios favoritos. Guarda só filtros:
 * ao abrir, o motor valida tudo de novo com a permissão atual.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/relatorios/ui.php';

$usuario = api_iniciar(null);
if (!relui_favoritos_instalados()) {
    api_erro('Os favoritos ainda não foram instalados (migração 014).', 503);
}
$acao = (string) ($_POST['acao'] ?? '');
if ($acao === 'salvar') {
    $rel = (string) ($_POST['relatorio'] ?? '');
    if (!relui_pode($usuario, $rel)) {
        api_erro('Você não tem acesso a este relatório.', 403);
    }
    $r = relui_favorito_salvar($usuario, $rel, (string) ($_POST['nome'] ?? ''), (string) ($_POST['filtros'] ?? ''));
    if (!$r['ok']) {
        api_erro($r['erro'], 422);
    }
    api_responder(['ok' => true, 'mensagem' => 'Filtros salvos nos favoritos.']);
}
if ($acao === 'remover') {
    if (!relui_favorito_remover($usuario, (int) ($_POST['id'] ?? 0))) {
        api_erro('Favorito não encontrado.', 404);
    }
    api_responder(['ok' => true, 'mensagem' => 'Favorito removido.']);
}
api_erro('Ação inválida.', 422);
