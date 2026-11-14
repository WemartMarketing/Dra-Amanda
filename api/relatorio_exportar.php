<?php
/**
 * POST /api/relatorio_exportar.php — exporta um relatório da Central.
 *   r, formato (xlsx|csv|pdf), nivel (resumido|completo|dados), ciente_lgpd (dados)
 *   + os mesmos filtros da tela (período, estrutura, origem...).
 *
 * Mesma permissão da tela (relui_pode) + exportar_relatorios, com o mesmo
 * escopo no SQL. Os números saem das mesmas funções da tela. Exportação de
 * dados acima de RELEXP_LIMITE_IMEDIATO linhas vai para a fila do cron e é
 * baixada depois pela Central (link temporário). Tudo fica na auditoria.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/relatorios/fila_exportacao.php';

$usuario = api_iniciar('exportar_relatorios');
$empresaId = (int) $usuario['empresa_ativa_id'];
$rel = (string) ($_POST['r'] ?? '');
$formato = (string) ($_POST['formato'] ?? 'xlsx');
$nivel = (string) ($_POST['nivel'] ?? 'completo');
if (!relui_pode($usuario, $rel)) {
    api_erro('Você não tem acesso a este relatório.', 403);
}
if (!isset(relexp_formatos()[$formato]) || !isset(relexp_niveis()[$nivel])) {
    api_erro('Formato inválido.', 422);
}
if ($formato === 'pdf' && $nivel === 'dados') {
    api_erro('A exportação de dados (uma linha por lead) sai em Excel ou CSV. O PDF é o relatório executivo.', 422);
}
if ($nivel === 'dados' && empty($_POST['ciente_lgpd'])) {
    api_erro('Confirme o uso adequado dos dados pessoais (LGPD) para exportar os dados.', 422);
}
[, $pub] = relui_entrada($_POST);

// Muitas linhas: prepara em segundo plano.
if ($nivel === 'dados' && relexp_fila_instalada() && relexp_dados_contar($usuario, $pub) > RELEXP_LIMITE_IMEDIATO) {
    $pend = (int) db()->query("SELECT COUNT(*) FROM relatorio_exportacoes WHERE usuario_id = " . (int) $usuario['id'] . " AND status IN ('pendente','gerando')")->fetchColumn();
    if ($pend >= 3) {
        api_erro('Você já tem 3 exportações em preparo. Aguarde terminarem.', 429);
    }
    $id = relexp_enfileirar($usuario, $rel, $formato, $nivel, $pub);
    auditoria_registrar($empresaId, (int) $usuario['id'], 'relatorio.exportacao_solicitada', 'relatorio', $id, ['relatorio' => $rel, 'formato' => $formato, 'nivel' => $nivel, 'filtros' => $pub]);
    api_responder(['ok' => true, 'fila' => true, 'id' => $id,
        'mensagem' => 'Arquivo grande: estamos preparando em segundo plano. Ele aparece em Relatórios → Minhas exportações em alguns minutos (o link vale 24 h).']);
}

$tmp = tempnam(sys_get_temp_dir(), 'wmexp');
try {
    $r = relexp_gerar_arquivo($usuario, $rel, $formato, $nivel, $pub, $tmp);
    if (!$r['ok']) {
        @unlink($tmp); // api_erro encerra o script (o finally não roda)
        api_erro($r['erro'], 422);
    }
    auditoria_registrar($empresaId, (int) $usuario['id'], 'relatorio.exportado', 'relatorio', null, [
        'relatorio' => $rel, 'formato' => $r['extensao'], 'nivel' => $nivel, 'linhas' => $r['linhas'], 'filtros' => $r['filtros']]);
    exportacao_cabecalhos($r['mime'], $r['nome']);
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
} finally {
    @unlink($tmp);
}
