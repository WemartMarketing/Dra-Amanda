<?php
/**
 * POST /api/vendas_conciliacao.php — Inteligência de Vendas, conciliação.
 *   acao=recalcular                                      → recruza todas as vendas da empresa
 *   acao=revisar      venda_id, decisao, lead_ids[], justificativa
 *   acao=equivalencia tipo, valor_texto, valor_origem?, classificacao, empreendimento_id|usuario_id|imobiliaria_id
 *   acao=imobiliaria  nome [, id]  /  acao=imobiliaria_ativa id, ativa
 * Permissão revisar_correspondencias. Empresa = a da sessão.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/vendas/cruzamento.php';

$usuario = api_iniciar('revisar_correspondencias');
$empresaId = (int) $usuario['empresa_ativa_id'];
if (!vendas_cruzamento_instalado()) {
    api_erro('Rode a migração 018 antes da conciliação.', 409);
}
try {
    switch ((string) ($_POST['acao'] ?? '')) {
        case 'recalcular':
            $r = vendas_cruzar($empresaId);
            auditoria_registrar($empresaId, (int) $usuario['id'], 'vendas_cruzar', 'vendas_correspondencia', null, ['vendas' => $r['vendas']]);
            api_responder(['ok' => true, 'mensagem' => 'Cruzamento refeito em ' . $r['vendas'] . ' venda(s). Decisões humanas foram mantidas.']);

        case 'revisar':
            $s = vendas_revisar($usuario, (int) ($_POST['venda_id'] ?? 0), (string) ($_POST['decisao'] ?? ''), (array) ($_POST['lead_ids'] ?? []), (string) ($_POST['justificativa'] ?? ''));
            api_responder(['ok' => true, 'mensagem' => 'Decisão registrada: ' . (vendas_situacoes()[$s][0] ?? $s) . '.']);

        case 'equivalencia':
            // alvo: "crm:ID" | "externo:" | "externo:ID" | "nao_existe" | "nao_identificado"
            $alvo = (string) ($_POST['alvo'] ?? '');
            $tipoEq = (string) ($_POST['tipo'] ?? '');
            [$classe, $idAlvo] = array_pad(explode(':', $alvo, 2), 2, '');
            $dados = ['classificacao' => $classe, 'valor_origem' => (string) ($_POST['valor_origem'] ?? '')];
            if ($classe === 'crm') {
                $dados[$tipoEq === 'empreendimento' ? 'empreendimento_id' : 'usuario_id'] = (int) $idAlvo;
            } elseif ($classe === 'externo') {
                $dados['imobiliaria_id'] = (int) $idAlvo ?: null;
            }
            vendas_equivalencia_salvar($usuario, $tipoEq, (string) ($_POST['valor_texto'] ?? ''), $dados);
            api_responder(['ok' => true, 'mensagem' => 'Equivalência salva.']);

        case 'imobiliaria':
            $nome = api_texto($_POST['nome'] ?? null);
            if (!$nome) {
                api_erro('Informe o nome da imobiliária.', 422);
            }
            $id = (int) ($_POST['id'] ?? 0);
            if ($id) {
                $st = db()->prepare('UPDATE imobiliarias_parceiras SET nome = :n WHERE id = :id AND empresa_id = :e');
                $st->execute(['n' => $nome, 'id' => $id, 'e' => $empresaId]);
            } else {
                db()->prepare('INSERT INTO imobiliarias_parceiras (empresa_id, nome, criado_por) VALUES (:e, :n, :u)')->execute(['e' => $empresaId, 'n' => $nome, 'u' => (int) $usuario['id']]);
            }
            auditoria_registrar($empresaId, (int) $usuario['id'], 'vendas_imobiliaria', 'imobiliaria_parceira', $id ?: (int) db()->lastInsertId(), ['nome' => $nome]);
            api_responder(['ok' => true, 'mensagem' => 'Imobiliária salva.']);

        case 'imobiliaria_ativa':
            db()->prepare('UPDATE imobiliarias_parceiras SET ativa = :a WHERE id = :id AND empresa_id = :e')
                ->execute(['a' => !empty($_POST['ativa']) ? 1 : 0, 'id' => (int) ($_POST['id'] ?? 0), 'e' => $empresaId]);
            api_responder(['ok' => true, 'mensagem' => 'Atualizado.']);

        default:
            api_erro('Ação inválida.', 422);
    }
} catch (InvalidArgumentException $e) {
    api_erro($e->getMessage(), 422);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        api_erro('Já existe um cadastro com esse nome.', 409);
    }
    error_log('[vendas_conciliacao] ' . $e->getMessage());
    api_erro('Não foi possível concluir. Tente de novo.', 500);
} catch (RuntimeException $e) {
    api_erro($e->getMessage(), 409);
}
