<?php
/**
 * POST /api/funil_salvar.php — etapas do funil da empresa. Permissão: configurar_funil.
 *   acao=salvar     { id?, nome, cor, categoria, exibir_kanban? }
 *   acao=mover      { id, direcao: cima|baixo }
 *   acao=ativar     { id }
 *   acao=desativar  { id, destino_id (obrigatório se houver leads na etapa) }
 *
 * Regras que preservam os relatórios: a categoria de uma etapa que já tem
 * leads não muda; desativar uma etapa com leads exige escolher para onde
 * eles vão (cada lead passa por lead_mudar_etapa, com histórico e
 * auditoria); sempre sobra ao menos uma etapa ativa visível no Kanban.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/funil.php';

$usuario = api_iniciar('configurar_funil');
$empresaId = (int) $usuario['empresa_ativa_id'];
$acao = (string) ($_POST['acao'] ?? 'salvar');
$id = (int) ($_POST['id'] ?? 0);
$etapa = $id ? funil_etapa($empresaId, $id) : null;
if ($id && !$etapa) {
    api_erro('Etapa não encontrada.', 404);
}

function contar_leads_etapa(int $etapaId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM leads WHERE etapa_id = :id AND excluido_em IS NULL');
    $stmt->execute(['id' => $etapaId]);
    return (int) $stmt->fetchColumn();
}

function restaria_etapa_no_kanban(int $empresaId, int $saindoId): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM funil_etapas WHERE empresa_id = :e AND ativa = 1 AND exibir_kanban = 1 AND id <> :id');
    $stmt->execute(['e' => $empresaId, 'id' => $saindoId]);
    return (int) $stmt->fetchColumn() > 0;
}

switch ($acao) {
    case 'salvar':
        $nome = api_texto($_POST['nome'] ?? null, 80);
        if (!$nome) {
            api_erro('Informe o nome da etapa.', 422);
        }
        $cor = strtoupper(trim((string) ($_POST['cor'] ?? '#635BFA')));
        if (!preg_match('/^#[0-9A-F]{6}$/', $cor)) {
            api_erro('Cor inválida.', 422);
        }
        $categoria = (string) ($_POST['categoria'] ?? ($etapa['categoria'] ?? ''));
        if (!array_key_exists($categoria, funil_categorias())) {
            api_erro('Escolha a categoria da etapa.', 422);
        }
        $exibir = !empty($_POST['exibir_kanban']) ? 1 : 0;

        $stmt = db()->prepare('SELECT id FROM funil_etapas WHERE empresa_id = :e AND nome = :nome AND id <> :id');
        $stmt->execute(['e' => $empresaId, 'nome' => $nome, 'id' => $id]);
        if ($stmt->fetchColumn()) {
            api_erro('Já existe uma etapa com esse nome.', 422);
        }

        if ($etapa) {
            if ($categoria !== $etapa['categoria'] && contar_leads_etapa($id) > 0) {
                api_erro('Esta etapa já tem leads, então a categoria não pode mudar (os relatórios dependem dela). Crie uma etapa nova.', 409);
            }
            if (!$exibir && (int) $etapa['exibir_kanban'] && (int) $etapa['ativa'] && !restaria_etapa_no_kanban($empresaId, $id)) {
                api_erro('O Kanban precisa de ao menos uma etapa visível.', 409);
            }
            db()->prepare('UPDATE funil_etapas SET nome = :nome, cor = :cor, categoria = :categoria, exibir_kanban = :exibir WHERE id = :id AND empresa_id = :e')
                ->execute(['nome' => $nome, 'cor' => $cor, 'categoria' => $categoria, 'exibir' => $exibir, 'id' => $id, 'e' => $empresaId]);
            auditoria_registrar($empresaId, (int) $usuario['id'], 'funil.etapa_editada', 'funil_etapa', $id, ['nome' => $nome, 'categoria' => $categoria, 'exibir_kanban' => $exibir]);
        } else {
            $stmt = db()->prepare('SELECT COALESCE(MAX(ordem), 0) FROM funil_etapas WHERE empresa_id = :e');
            $stmt->execute(['e' => $empresaId]);
            $ordem = (int) $stmt->fetchColumn() + 10;
            db()->prepare('INSERT INTO funil_etapas (empresa_id, nome, categoria, ordem, cor, exibir_kanban) VALUES (:e, :nome, :categoria, :ordem, :cor, :exibir)')
                ->execute(['e' => $empresaId, 'nome' => $nome, 'categoria' => $categoria, 'ordem' => $ordem, 'cor' => $cor, 'exibir' => $exibir]);
            $id = (int) db()->lastInsertId();
            auditoria_registrar($empresaId, (int) $usuario['id'], 'funil.etapa_criada', 'funil_etapa', $id, ['nome' => $nome, 'categoria' => $categoria]);
        }
        api_responder(['ok' => true, 'id' => $id]);

    case 'mover':
        if (!$etapa) api_erro('Etapa não encontrada.', 404);
        $todas = funil_etapas($empresaId, 'todas');
        $ids = array_map(fn ($e) => (int) $e['id'], $todas);
        $pos = array_search($id, $ids, true);
        $alvo = ($_POST['direcao'] ?? '') === 'cima' ? $pos - 1 : $pos + 1;
        if ($pos === false || !isset($ids[$alvo])) {
            api_responder(['ok' => true]);
        }
        [$ids[$pos], $ids[$alvo]] = [$ids[$alvo], $ids[$pos]];
        $pdo = db();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('UPDATE funil_etapas SET ordem = :ordem WHERE id = :id AND empresa_id = :e');
        foreach ($ids as $i => $etapaId) {
            $stmt->execute(['ordem' => ($i + 1) * 10, 'id' => $etapaId, 'e' => $empresaId]);
        }
        $pdo->commit();
        auditoria_registrar($empresaId, (int) $usuario['id'], 'funil.reordenado', 'funil_etapa', $id, ['ordem' => $ids]);
        api_responder(['ok' => true]);

    case 'ativar':
        if (!$etapa) api_erro('Etapa não encontrada.', 404);
        db()->prepare('UPDATE funil_etapas SET ativa = 1 WHERE id = :id AND empresa_id = :e')->execute(['id' => $id, 'e' => $empresaId]);
        auditoria_registrar($empresaId, (int) $usuario['id'], 'funil.etapa_ativada', 'funil_etapa', $id);
        api_responder(['ok' => true]);

    case 'desativar':
        if (!$etapa) api_erro('Etapa não encontrada.', 404);
        if ((int) $etapa['exibir_kanban'] && !restaria_etapa_no_kanban($empresaId, $id)) {
            api_erro('O Kanban precisa de ao menos uma etapa ativa.', 409);
        }
        $qtd = contar_leads_etapa($id);
        $destino = null;
        if ($qtd > 0) {
            $destino = funil_etapa($empresaId, (int) ($_POST['destino_id'] ?? 0));
            if (!$destino || !(int) $destino['ativa'] || (int) $destino['id'] === $id) {
                api_erro("Esta etapa tem {$qtd} lead(s). Escolha para qual etapa ativa eles vão.", 422);
            }
        }
        $pdo = db();
        $pdo->beginTransaction();
        if ($destino) {
            $stmt = $pdo->prepare('SELECT * FROM leads WHERE etapa_id = :id AND empresa_id = :e AND excluido_em IS NULL');
            $stmt->execute(['id' => $id, 'e' => $empresaId]);
            foreach ($stmt->fetchAll() as $lead) {
                lead_mudar_etapa($lead, (int) $destino['id'], $usuario, 'etapa "' . $etapa['nome'] . '" desativada');
            }
        }
        $pdo->prepare('UPDATE funil_etapas SET ativa = 0 WHERE id = :id AND empresa_id = :e')->execute(['id' => $id, 'e' => $empresaId]);
        $pdo->commit();
        auditoria_registrar($empresaId, (int) $usuario['id'], 'funil.etapa_desativada', 'funil_etapa', $id, ['leads_movidos' => $qtd, 'destino_id' => $destino['id'] ?? null]);
        api_responder(['ok' => true, 'mensagem' => $qtd ? "{$qtd} lead(s) movidos para \"{$destino['nome']}\"." : 'Etapa desativada.']);

    default:
        api_erro('Ação inválida.', 422);
}
