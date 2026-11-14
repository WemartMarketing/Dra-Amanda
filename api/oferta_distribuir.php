<?php
/**
 * POST /api/oferta_distribuir.php  { lista_id, acao, usuario_ids[]? }
 *   acao: roleta   → cada lead sem corretor vai para roleta_distribuir_lead (origem importacao)
 *         dividir  → divide igualmente (rodízio) entre os corretores escolhidos
 *         encerrar → marca a lista como encerrada (os leads continuam)
 * Permissão gerenciar_oferta_ativa; a lista precisa ser visível ao usuário
 * e os corretores precisam estar no recorte dele. Até 500 leads por envio.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/importacao.php';
require_once __DIR__ . '/../includes/roleta.php';

$usuario = api_iniciar('gerenciar_oferta_ativa');
$empresaId = (int) $usuario['empresa_ativa_id'];
$lista = oferta_lista_carregar($usuario, (int) ($_POST['lista_id'] ?? 0));
if (!$lista) {
    api_erro('Lista não encontrada.', 404);
}
$acao = (string) ($_POST['acao'] ?? '');
$pdo = db();
@set_time_limit(180);
const OFERTA_LOTE = 500;

if ($acao === 'encerrar') {
    $pdo->prepare("UPDATE oferta_listas SET status = 'encerrada' WHERE id = :id")->execute(['id' => $lista['id']]);
    auditoria_registrar($empresaId, (int) $usuario['id'], 'oferta.lista_encerrada', 'oferta_lista', (int) $lista['id'], []);
    api_responder(['ok' => true, 'mensagem' => 'Lista encerrada.']);
}
if (!in_array($acao, ['roleta', 'dividir'], true)) {
    api_erro('Ação inválida.', 422);
}
if ($lista['status'] === 'encerrada') {
    api_erro('Esta lista está encerrada.', 409);
}

$stmt = $pdo->prepare('SELECT id FROM leads WHERE empresa_id = :e AND oferta_lista_id = :l AND responsavel_id IS NULL AND excluido_em IS NULL ORDER BY id LIMIT ' . OFERTA_LOTE);
$stmt->execute(['e' => $empresaId, 'l' => $lista['id']]);
$ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
if (!$ids) {
    api_erro('Não há leads sem corretor nesta lista.', 409);
}

$distribuidos = 0;
$fila = 0;
$porCorretor = [];
if ($acao === 'roleta') {
    if (!$lista['empreendimento_id']) {
        api_erro('A lista não tem empreendimento — escolha "Dividir igualmente" ou importe vinculando um empreendimento.', 422);
    }
    foreach ($ids as $leadId) {
        $res = roleta_distribuir_lead($leadId, 'importacao', $usuario);
        if ($res['status'] === 'distribuido') {
            $distribuidos++;
            $porCorretor[$res['usuario_id']] = ($porCorretor[$res['usuario_id']] ?? 0) + 1;
        } elseif ($res['status'] === 'fila') {
            $fila++;
        }
    }
} else {
    $permitidos = [];
    foreach (oferta_corretores($usuario) as $c) {
        $permitidos[(int) $c['id']] = $c;
    }
    $escolhidos = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['usuario_ids'] ?? [])))));
    $escolhidos = array_values(array_filter($escolhidos, fn ($id) => isset($permitidos[$id])));
    if (!$escolhidos) {
        api_erro('Escolha pelo menos um corretor da sua estrutura.', 422);
    }
    $upd = $pdo->prepare('UPDATE leads SET responsavel_id = :u, equipe_id = :eq, ultima_atividade_em = NOW(), ultimo_feedback_em = NOW(),
            atribuido_em = NOW(), primeiro_contato_em = NULL
        WHERE id = :id AND empresa_id = :e AND responsavel_id IS NULL AND excluido_em IS NULL');
    $saiFila = $pdo->prepare('UPDATE roleta_fila SET resolvido_em = NOW() WHERE lead_id = :l AND resolvido_em IS NULL');
    $i = 0;
    $pdo->beginTransaction();
    foreach ($ids as $leadId) {
        $uid = $escolhidos[$i % count($escolhidos)];
        $c = $permitidos[$uid];
        $upd->execute(['u' => $uid, 'eq' => $c['equipe_id'] ?: null, 'id' => $leadId, 'e' => $empresaId]);
        if ($upd->rowCount() !== 1) {
            continue; // já recebeu corretor por outro caminho
        }
        $i++;
        $saiFila->execute(['l' => $leadId]);
        historico_fila_saiu($leadId, 'oferta_ativa');
        historico_abrir_atribuicao($leadId, $uid, 'oferta_ativa');
        lead_registrar_evento($leadId, (int) $usuario['id'], 'atribuicao', 'Oferta ativa "' . $lista['nome'] . '": atribuído a ' . $c['nome'] . ' por ' . $usuario['nome'] . ' (divisão igual)');
        $distribuidos++;
        $porCorretor[$uid] = ($porCorretor[$uid] ?? 0) + 1;
    }
    $pdo->commit();
}

if ($distribuidos && $lista['status'] === 'importada') {
    $pdo->prepare("UPDATE oferta_listas SET status = 'em_distribuicao' WHERE id = :id")->execute(['id' => $lista['id']]);
}
auditoria_registrar($empresaId, (int) $usuario['id'], 'oferta.distribuida', 'oferta_lista', (int) $lista['id'], [
    'modo' => $acao, 'distribuidos' => $distribuidos, 'em_fila' => $fila, 'por_corretor' => $porCorretor,
]);
$stmt = $pdo->prepare('SELECT COUNT(*) FROM leads WHERE empresa_id = :e AND oferta_lista_id = :l AND responsavel_id IS NULL AND excluido_em IS NULL');
$stmt->execute(['e' => $empresaId, 'l' => $lista['id']]);
$restam = (int) $stmt->fetchColumn();
$msg = $distribuidos . ' lead(s) distribuído(s)' . ($fila ? ', ' . $fila . ' na fila da roleta (sem corretor disponível agora)' : '') . '.';
if ($restam > $fila && count($ids) === OFERTA_LOTE) {
    $msg .= ' Ainda há leads sem corretor — clique de novo para continuar.';
}
api_responder(['ok' => true, 'mensagem' => $msg]);
