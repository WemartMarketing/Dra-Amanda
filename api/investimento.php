<?php
/**
 * POST /api/investimento.php — investimento em anúncios (migração 016).
 * Permissão: gerenciar_investimentos.
 *   acao=salvar   { id?, data_inicio, data_fim, origem_detalhe_id, empreendimento_id?, campanha?, valor, descricao? }
 *   acao=excluir  { id }
 *   acao=importar { arquivo (CSV) } — tudo ou nada
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/investimentos.php';

$usuario = api_iniciar('gerenciar_investimentos');
$empresaId = (int) $usuario['empresa_ativa_id'];
if (!investimentos_instalados()) {
    api_erro('O lançamento de investimento ainda não foi instalado (migração 016).', 503);
}
switch ((string) ($_POST['acao'] ?? '')) {
    case 'salvar':
        $id = (int) ($_POST['id'] ?? 0) ?: null;
        $r = investimento_salvar($usuario, $_POST, $id);
        if (!$r['ok']) {
            api_erro($r['erro'], 422);
        }
        auditoria_registrar($empresaId, (int) $usuario['id'], $id ? 'investimento.editado' : 'investimento.lancado', 'marketing_investimento', $r['id'],
            ['valor' => (string) ($_POST['valor'] ?? ''), 'data_inicio' => (string) ($_POST['data_inicio'] ?? ''), 'data_fim' => (string) ($_POST['data_fim'] ?? ''), 'origem' => (int) ($_POST['origem_detalhe_id'] ?? 0)]);
        api_responder(['ok' => true, 'mensagem' => 'Investimento salvo.']);
        // no break
    case 'excluir':
        if (!investimento_excluir($usuario, (int) ($_POST['id'] ?? 0))) {
            api_erro('Lançamento não encontrado.', 404);
        }
        auditoria_registrar($empresaId, (int) $usuario['id'], 'investimento.excluido', 'marketing_investimento', (int) $_POST['id'], []);
        api_responder(['ok' => true, 'mensagem' => 'Lançamento excluído.']);
        // no break
    case 'importar':
        $f = $_FILES['arquivo'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 2 * 1024 * 1024) {
            api_erro('Envie um arquivo CSV de até 2 MB.', 422);
        }
        $r = investimento_importar_csv($usuario, (string) file_get_contents($f['tmp_name']));
        if (!$r['ok']) {
            api_erro('Nada foi importado. ' . implode(' ', array_slice($r['erros'], 0, 8)) . (count($r['erros']) > 8 ? ' (e mais ' . (count($r['erros']) - 8) . ')' : ''), 422);
        }
        auditoria_registrar($empresaId, (int) $usuario['id'], 'investimento.importado', 'marketing_investimento', null, ['linhas' => $r['importados']]);
        api_responder(['ok' => true, 'mensagem' => $r['importados'] . ' lançamento(s) importado(s).']);
        // no break
    default:
        api_erro('Ação inválida.', 422);
}
