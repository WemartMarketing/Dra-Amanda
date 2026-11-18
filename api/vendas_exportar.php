<?php
/**
 * POST /api/vendas_exportar.php — exporta a Inteligência de Vendas.
 *   tipo (painel|conciliacao|jornada), formato (xlsx|csv|pdf), nivel (resumido|completo),
 *   ciente_lgpd (obrigatório quando há nome de cliente: "completo" e jornada)
 *   + os filtros da tela (painel: período/empreendimento/equipe/corretor/origem/situação;
 *     conciliação: lote; jornada: venda).
 * Permissões: exportar_relatorios + ver_inteligencia_vendas. A jornada
 * respeita a carteira atual. Telefone, e-mail e CPF nunca saem. Tudo fica
 * na auditoria (sem dados pessoais).
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/vendas/exportar.php';

$usuario = api_iniciar('exportar_relatorios');
if (!usuario_tem_permissao($usuario, 'ver_inteligencia_vendas')) {
    api_erro('Sua função não tem acesso à Inteligência de Vendas.', 403);
}
$tipo = (string) ($_POST['tipo'] ?? '');
$formato = (string) ($_POST['formato'] ?? 'xlsx');
$nivel = $tipo === 'jornada' ? 'completo' : (string) ($_POST['nivel'] ?? 'resumido');
if ($nivel === 'completo' && empty($_POST['ciente_lgpd'])) {
    api_erro('Este arquivo contém o nome do cliente. Confirme o uso adequado dos dados pessoais (LGPD) para exportar.', 422);
}
$get = array_intersect_key($_POST, array_flip(['periodo', 'ini', 'fim', 'empreendimento', 'equipe', 'corretor', 'origem', 'situacao', 'lote', 'venda']));
$tmp = tempnam(sys_get_temp_dir(), 'wmvexp');
try {
    $r = vendas_export_gerar($usuario, $tipo, $formato, $nivel, $get, $tmp);
    if (!$r['ok']) {
        @unlink($tmp); // api_erro encerra o script (o finally não roda)
        api_erro($r['erro'], 422);
    }
    auditoria_registrar((int) $usuario['empresa_ativa_id'], (int) $usuario['id'], 'vendas.exportado', 'inteligencia_vendas', $tipo === 'jornada' ? (int) ($get['venda'] ?? 0) : null,
        ['tipo' => $tipo, 'formato' => $r['extensao'], 'nivel' => $nivel, 'linhas' => $r['linhas'], 'filtros' => $r['pub']]);
    exportacao_cabecalhos($r['mime'], $r['nome']);
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
} finally {
    @unlink($tmp);
}
