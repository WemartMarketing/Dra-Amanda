<?php
/**
 * POST /api/relatorio_modelo.php — modelos de relatório (Etapa 6).
 *   acao=gerar   { modelo: p:chave | u:id, formato: pdf|xlsx, + filtros }   exportar_relatorios
 *   acao=salvar  { nome, secoes[], + filtros }                              visualizar_indicadores
 *   acao=excluir { id }
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/relatorios/modelos.php';

$usuario = api_iniciar('visualizar_indicadores');
$empresaId = (int) $usuario['empresa_ativa_id'];
[, $pub] = relui_entrada($_POST);
switch ((string) ($_POST['acao'] ?? '')) {
    case 'gerar':
        $tmp = tempnam(sys_get_temp_dir(), 'wmmod');
        $r = relmod_gerar($usuario, (string) ($_POST['modelo'] ?? ''), (string) ($_POST['formato'] ?? 'pdf'), $pub, $tmp);
        if (!$r['ok']) {
            @unlink($tmp);
            api_erro($r['erro'], 422);
        }
        auditoria_registrar($empresaId, (int) $usuario['id'], 'relatorio.exportado', 'relatorio', null, [
            'modelo' => $r['modelo'], 'formato' => $r['extensao'], 'secoes' => $r['secoes'], 'linhas' => $r['linhas'], 'filtros' => $pub]);
        exportacao_cabecalhos($r['mime'], $r['nome']);
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    case 'salvar':
        if (!relmod_instalados()) {
            api_erro('Modelos personalizados ainda não instalados (migração 016).', 503);
        }
        $r = relmod_salvar($usuario, (string) ($_POST['nome'] ?? ''), (array) ($_POST['secoes'] ?? []), http_build_query($pub));
        if (!$r['ok']) {
            api_erro($r['erro'], 422);
        }
        auditoria_registrar($empresaId, (int) $usuario['id'], 'relatorio.modelo_salvo', 'relatorio_modelo', null, ['nome' => mb_substr((string) $_POST['nome'], 0, 80)]);
        api_responder(['ok' => true, 'mensagem' => 'Modelo salvo.']);
        // no break
    case 'excluir':
        if (!relmod_excluir($usuario, (int) ($_POST['id'] ?? 0))) {
            api_erro('Modelo não encontrado.', 404);
        }
        api_responder(['ok' => true, 'mensagem' => 'Modelo excluído.']);
        // no break
    default:
        api_erro('Ação inválida.', 422);
}
