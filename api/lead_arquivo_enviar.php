<?php
/**
 * POST (multipart) /api/lead_arquivo_enviar.php  { lead_id, arquivo }
 * Permissão acessar_arquivos_leads + lead no escopo. Ver includes/arquivos.php.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/arquivos.php';

$usuario = api_iniciar('acessar_arquivos_leads');
$lead = api_lead($usuario, (int) ($_POST['lead_id'] ?? 0));

$upload = lead_arquivo_receber('arquivo');
if (!$upload['ok']) {
    api_erro($upload['erro'], 422);
}
db()->prepare('
    INSERT INTO lead_arquivos (empresa_id, lead_id, nome_original, arquivo, mime, tamanho, enviado_por)
    VALUES (:empresa_id, :lead_id, :nome_original, :arquivo, :mime, :tamanho, :enviado_por)
')->execute([
    'empresa_id' => $usuario['empresa_ativa_id'], 'lead_id' => $lead['id'], 'nome_original' => $upload['nome_original'],
    'arquivo' => $upload['arquivo'], 'mime' => $upload['mime'], 'tamanho' => $upload['tamanho'], 'enviado_por' => $usuario['id'],
]);
$id = (int) db()->lastInsertId();
lead_registrar_evento((int) $lead['id'], (int) $usuario['id'], 'arquivo', 'Arquivo enviado: ' . $upload['nome_original']);
auditoria_registrar((int) $usuario['empresa_ativa_id'], (int) $usuario['id'], 'lead.arquivo_enviado', 'lead', (int) $lead['id'], ['arquivo_id' => $id, 'mime' => $upload['mime'], 'tamanho' => $upload['tamanho']]);
api_responder(['ok' => true, 'id' => $id, 'mensagem' => 'Arquivo enviado.']);
