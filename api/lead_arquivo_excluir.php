<?php
/**
 * POST /api/lead_arquivo_excluir.php  { id }
 * Exclusão lógica do registro + remoção do arquivo do disco.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/arquivos.php';

$usuario = api_iniciar('acessar_arquivos_leads');
$stmt = db()->prepare('SELECT * FROM lead_arquivos WHERE id = :id AND empresa_id = :e AND excluido_em IS NULL');
$stmt->execute(['id' => (int) ($_POST['id'] ?? 0), 'e' => $usuario['empresa_ativa_id']]);
$arquivo = $stmt->fetch();
if (!$arquivo) {
    api_erro('Arquivo não encontrado.', 404);
}
$lead = api_lead($usuario, (int) $arquivo['lead_id']);

db()->prepare('UPDATE lead_arquivos SET excluido_em = NOW() WHERE id = :id')->execute(['id' => $arquivo['id']]);
if ($caminho = lead_arquivo_caminho($arquivo['arquivo'])) {
    @unlink($caminho);
}
lead_registrar_evento((int) $lead['id'], (int) $usuario['id'], 'arquivo', 'Arquivo removido: ' . $arquivo['nome_original']);
auditoria_registrar((int) $usuario['empresa_ativa_id'], (int) $usuario['id'], 'lead.arquivo_excluido', 'lead', (int) $lead['id'], ['arquivo_id' => (int) $arquivo['id']]);
api_responder(['ok' => true]);
