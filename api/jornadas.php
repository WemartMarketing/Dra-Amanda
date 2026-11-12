<?php
/**
 * POST /api/jornadas.php — administração das Jornadas Inteligentes (Fase 7).
 *   acao=salvar     { id?, definicao (JSON) }            cria ou substitui os blocos
 *   acao=simular    { definicao (JSON), cenario (JSON) } linha do tempo fictícia, sem banco
 *   acao=duplicar   { id }
 *   acao=status     { id, status: ativa|pausada|desativada }
 * Permissão: gerenciar_jornadas.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/jornadas_editor.php';
require_once __DIR__ . '/../includes/auditoria.php';

$usuario = api_iniciar('gerenciar_jornadas');
$empresaId = (int) $usuario['empresa_ativa_id'];
$uid = (int) $usuario['id'];
$acao = (string) ($_POST['acao'] ?? '');

$json = function (string $campo): array {
    $v = json_decode((string) ($_POST[$campo] ?? ''), true);
    if (!is_array($v)) {
        api_erro('Dados da jornada inválidos. Recarregue a página.', 422);
    }
    return $v;
};

switch ($acao) {
    case 'salvar':
        $id = (int) ($_POST['id'] ?? 0) ?: null;
        $r = jornada_salvar_definicao($empresaId, $uid, $id, $json('definicao'));
        if (!$r['ok']) {
            api_responder(['ok' => false, 'erro' => implode(' ', $r['erros']), 'erros' => $r['erros']], 422);
        }
        api_responder(['ok' => true, 'id' => $r['id'], 'avisos' => $r['avisos'],
            'mensagem' => 'Jornada salva.' . ($r['retirados'] ? ' ' . $r['retirados'] . ' lead(s) saíram dela porque o bloco em que estavam foi removido.' : '')]);

    case 'simular':
        $v = jornada_validar_definicao($empresaId, $json('definicao'));
        if (!$v['ok']) {
            api_responder(['ok' => false, 'erro' => implode(' ', $v['erros']), 'erros' => $v['erros']], 422);
        }
        $cen = json_decode((string) ($_POST['cenario'] ?? '{}'), true) ?: [];
        $cenario = [
            'consentimento' => in_array($cen['consentimento'] ?? '', ['nenhum', 'atendimento', 'marketing'], true) ? $cen['consentimento'] : 'atendimento',
            'tem_corretor' => !empty($cen['tem_corretor']), 'corretor_tenta' => !empty($cen['corretor_tenta']), 'corretor_confirma' => !empty($cen['corretor_confirma']),
            'cliente_responde' => ($cen['cliente_responde'] ?? '') === 'apos_primeira_mensagem' ? 'apos_primeira_mensagem' : 'nunca',
            'empreendimento_tem_template' => !empty($cen['empreendimento_tem_template']), 'tem_book' => !empty($cen['tem_book']),
        ];
        $sim = jornada_simular($v['def'], $cenario, jornada_templates_simulacao($empresaId));
        api_responder(['ok' => true, 'avisos' => $v['avisos']] + $sim);

    case 'duplicar':
        $r = jornada_duplicar($empresaId, $uid, (int) ($_POST['id'] ?? 0));
        if (!$r['ok']) {
            api_erro(implode(' ', $r['erros']), 422);
        }
        api_responder(['ok' => true, 'id' => $r['id'], 'mensagem' => 'Cópia criada (desligada).']);

    case 'status':
        $r = jornada_mudar_status($empresaId, $uid, (int) ($_POST['id'] ?? 0), (string) ($_POST['status'] ?? ''));
        if (!$r['ok']) {
            api_erro($r['erro'], 422);
        }
        api_responder(['ok' => true, 'mensagem' => $r['mensagem']]);
}
api_erro('Ação desconhecida.', 400);
