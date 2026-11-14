<?php
/**
 * POST /api/origem_salvar.php — origens de aquisição da empresa (migração 013).
 * Permissão: configurar_funil.   { id?, nome, canal, ativa? }
 * Origens do sistema (Meta Ads, Indicação, Importação, padrões) não mudam.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/origens.php';

$usuario = api_iniciar('configurar_funil');
$empresaId = (int) $usuario['empresa_ativa_id'];
if (!origens_instaladas()) {
    api_erro('As origens detalhadas ainda não foram instaladas (migração 013).', 503);
}
$id = (int) ($_POST['id'] ?? 0) ?: null;
$res = origem_salvar($empresaId, $id, (string) ($_POST['nome'] ?? ''), (string) ($_POST['canal'] ?? ''), !empty($_POST['ativa']));
if (!$res['ok']) {
    api_erro($res['erro'], 422);
}
auditoria_registrar($empresaId, (int) $usuario['id'], $id ? 'origem.editada' : 'origem.criada', 'lead_origem', $res['id'],
    ['nome' => mb_substr((string) $_POST['nome'], 0, 80), 'canal' => (string) $_POST['canal'], 'ativa' => !empty($_POST['ativa'])]);
api_responder(['ok' => true, 'id' => $res['id'], 'mensagem' => 'Origem salva.']);
