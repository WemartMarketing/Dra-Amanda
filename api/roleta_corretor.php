<?php
/**
 * POST /api/roleta_corretor.php
 *   { roleta_id, usuario_id, acao: 'add'|'remover'|'pausar'|'retomar'|'retirar' }
 * Gestão dos corretores de uma roleta (permissão gerenciar_roletas).
 *   add      — habilita (vai para o fim da ordem de cadastro)
 *   remover  — desvincula e encerra a presença aberta
 *   pausar   — fica vinculado, mas não recebe e não consegue entrar
 *   retomar  — volta a poder entrar/receber
 *   retirar  — encerra a presença atual (ele pode entrar de novo)
 * Roleta e corretor precisam ser da empresa do gestor.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/roleta.php';

$usuario = api_iniciar('gerenciar_roletas');
$empresaId = (int) $usuario['empresa_ativa_id'];
$roletaId = (int) ($_POST['roleta_id'] ?? 0);
$usuarioId = (int) ($_POST['usuario_id'] ?? 0);
$acao = (string) ($_POST['acao'] ?? '');

if (!$roletaId || !$usuarioId || !in_array($acao, ['add', 'remover', 'pausar', 'retomar', 'retirar'], true)) {
    api_erro('Dados inválidos.', 422);
}
if (!api_registro_da_empresa('roletas', $roletaId, $empresaId)) {
    api_erro('Roleta não encontrada.', 404);
}
$stmt = db()->prepare('
    SELECT usuarios.id FROM usuarios
    INNER JOIN funcoes ON funcoes.id = usuarios.funcao_id
    INNER JOIN funcao_permissoes fp ON fp.funcao_id = funcoes.id AND fp.permissao_chave = \'participar_roleta\'
    WHERE usuarios.id = :id AND usuarios.empresa_id = :empresa_id' . ($acao === 'add' ? ' AND usuarios.ativo = 1' : ''));
$stmt->execute(['id' => $usuarioId, 'empresa_id' => $empresaId]);
if (!$stmt->fetch() && $acao === 'add') {
    api_erro('Corretor não encontrado nesta empresa ou sem permissão para participar de roletas.', 404);
}
if ($acao !== 'add' && !roleta_vinculo($roletaId, $usuarioId)) {
    api_erro('Este corretor não está nesta roleta.', 404);
}

$sessao = roleta_sessao_aberta($roletaId, $usuarioId);
switch ($acao) {
    case 'add':
        $stmt = db()->prepare('SELECT COALESCE(MAX(ordem), 0) + 1 FROM roleta_corretores WHERE roleta_id = :r');
        $stmt->execute(['r' => $roletaId]);
        db()->prepare('INSERT IGNORE INTO roleta_corretores (roleta_id, usuario_id, ordem) VALUES (:r, :u, :o)')
            ->execute(['r' => $roletaId, 'u' => $usuarioId, 'o' => (int) $stmt->fetchColumn()]);
        break;
    case 'remover':
        if ($sessao) {
            roleta_fechar_sessao((int) $sessao['id'], 'removido');
        }
        db()->prepare('DELETE FROM roleta_corretores WHERE roleta_id = :r AND usuario_id = :u')->execute(['r' => $roletaId, 'u' => $usuarioId]);
        break;
    case 'pausar':
        db()->prepare('UPDATE roleta_corretores SET pausado = 1 WHERE roleta_id = :r AND usuario_id = :u')->execute(['r' => $roletaId, 'u' => $usuarioId]);
        if ($sessao) {
            roleta_fechar_sessao((int) $sessao['id'], 'gestor');
        }
        break;
    case 'retomar':
        db()->prepare('UPDATE roleta_corretores SET pausado = 0 WHERE roleta_id = :r AND usuario_id = :u')->execute(['r' => $roletaId, 'u' => $usuarioId]);
        break;
    case 'retirar':
        if ($sessao) {
            roleta_fechar_sessao((int) $sessao['id'], 'gestor');
        }
        break;
}
auditoria_registrar($empresaId, (int) $usuario['id'], 'roleta.corretor_' . $acao, 'roleta', $roletaId, ['usuario_id' => $usuarioId]);
api_responder(['ok' => true]);
