<?php
/**
 * LGPD — anonimização de um lead e exportação dos dados do titular.
 *
 * Anonimizar é IRREVERSÍVEL: troca nome, telefone, e-mail, interesse,
 * campanha, renda e observações por marcadores, apaga os arquivos
 * anexados (do disco e do banco), limpa os textos livres do histórico,
 * da agenda e das tarefas, e tira o nome/telefone/e-mail que tenham sido
 * citados em outros textos (transferências, fila, auditoria). Ficam etapa,
 * datas, origem, empreendimento, responsável e a estrutura — por isso os
 * relatórios e dashboards continuam com os mesmos números.
 */
require_once __DIR__ . '/leads.php';
require_once __DIR__ . '/auditoria.php';
require_once __DIR__ . '/arquivos.php';

const LGPD_REMOVIDO = '[removido — LGPD]';

/** Trechos que identificam o titular, para tirar de textos livres. */
function lgpd_identificadores(array $lead): array
{
    $ids = [];
    foreach (['nome', 'email', 'telefone'] as $c) {
        $v = trim((string) ($lead[$c] ?? ''));
        if (mb_strlen($v) >= 3) {
            $ids[] = $v;
        }
    }
    $dig = lead_telefone_normalizado($lead['telefone'] ?? '');
    if (strlen($dig) >= 8) {
        $ids[] = $dig;
        $ids[] = lead_formatar_telefone($dig);
        $ids[] = '55' . $dig;
    }
    // Primeiro nome sozinho é comum demais para apagar de qualquer texto; nome completo e partes longas sim.
    $partes = preg_split('/\s+/', trim((string) ($lead['nome'] ?? '')));
    if (count($partes) >= 2) {
        $ids[] = $partes[0] . ' ' . $partes[1];
    }
    $ids = array_values(array_unique(array_filter($ids)));
    usort($ids, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a)); // mais longos primeiro
    return $ids;
}

function lgpd_limpar_texto(?string $texto, array $ids): ?string
{
    if ($texto === null || $texto === '') {
        return $texto;
    }
    foreach ($ids as $id) {
        $texto = str_ireplace($id, '[titular]', $texto);
    }
    return $texto;
}

/** Limpa recursivamente os textos de um JSON de auditoria. */
function lgpd_limpar_json(?string $json, array $ids): ?string
{
    if ($json === null || $json === '') {
        return $json;
    }
    $dados = json_decode($json, true);
    if (!is_array($dados)) {
        return lgpd_limpar_texto($json, $ids);
    }
    array_walk_recursive($dados, function (&$v, $k) use ($ids) {
        if (is_string($v)) {
            $v = in_array($k, ['nome', 'telefone', 'email'], true) ? '[titular]' : lgpd_limpar_texto($v, $ids);
        }
    });
    return json_encode($dados, JSON_UNESCAPED_UNICODE);
}

/**
 * Anonimiza o lead (já carregado dentro do escopo de quem pede).
 * Devolve contagens do que foi alterado (sem nenhum dado pessoal).
 */
function lgpd_anonimizar_lead(array $lead, array $usuario): array
{
    $pdo = db();
    $leadId = (int) $lead['id'];
    $empresaId = (int) $lead['empresa_id'];
    $ids = lgpd_identificadores($lead);
    $res = ['eventos' => 0, 'compromissos' => 0, 'tarefas' => 0, 'arquivos' => 0, 'outros_textos' => 0];
    $apagar = [];

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE leads SET nome = :nome, telefone = NULL, email = NULL, interesse = NULL, campanha = NULL,
                renda_informada = NULL, proximo_passo = NULL, meta_leadgen_id = NULL
            WHERE id = :id AND empresa_id = :e")
            ->execute(['nome' => 'Lead anonimizado #' . $leadId, 'id' => $leadId, 'e' => $empresaId]);
        // motivo_perda alimenta o relatório de perdas por motivo: fica, sem identificadores.
        $stmt = $pdo->prepare('SELECT motivo_perda FROM leads WHERE id = :id');
        $stmt->execute(['id' => $leadId]);
        $motivo = $stmt->fetchColumn();
        if ($motivo) {
            $pdo->prepare('UPDATE leads SET motivo_perda = :m WHERE id = :id')->execute(['m' => mb_substr(lgpd_limpar_texto($motivo, $ids), 0, 190), 'id' => $leadId]);
        }

        // Histórico: textos livres do atendimento somem; os de sistema perdem só os identificadores.
        $livres = ['nota', 'contato', 'ligacao', 'whatsapp', 'visita'];
        $stmt = $pdo->prepare('SELECT id, tipo, descricao FROM lead_eventos WHERE lead_id = :id');
        $stmt->execute(['id' => $leadId]);
        $upd = $pdo->prepare('UPDATE lead_eventos SET descricao = :d WHERE id = :id');
        foreach ($stmt->fetchAll() as $ev) {
            $novo = in_array($ev['tipo'], $livres, true) ? LGPD_REMOVIDO : lgpd_limpar_texto($ev['descricao'], $ids);
            if ($novo !== $ev['descricao']) {
                $upd->execute(['d' => mb_substr($novo, 0, 500), 'id' => $ev['id']]);
                $res['eventos']++;
            }
        }

        $stmt = $pdo->prepare("UPDATE agenda_compromissos SET titulo = CASE tipo WHEN 'visita' THEN 'Visita' WHEN 'retorno' THEN 'Retorno' ELSE 'Tarefa' END,
                observacao = NULL WHERE lead_id = :id AND empresa_id = :e");
        $stmt->execute(['id' => $leadId, 'e' => $empresaId]);
        $res['compromissos'] = $stmt->rowCount();

        $stmt = $pdo->prepare("UPDATE lead_tarefas SET titulo = 'Tarefa', descricao = NULL WHERE lead_id = :id");
        $stmt->execute(['id' => $leadId]);
        $res['tarefas'] = $stmt->rowCount();

        $stmt = $pdo->prepare('SELECT id, arquivo FROM lead_arquivos WHERE lead_id = :id AND empresa_id = :e');
        $stmt->execute(['id' => $leadId, 'e' => $empresaId]);
        foreach ($stmt->fetchAll() as $arq) {
            if ($c = lead_arquivo_caminho($arq['arquivo'])) {
                $apagar[] = $c;
            }
            $res['arquivos']++;
        }
        $pdo->prepare('DELETE FROM lead_arquivos WHERE lead_id = :id AND empresa_id = :e')->execute(['id' => $leadId, 'e' => $empresaId]);

        // Textos em que o titular pode ter sido citado.
        $limpar = function (string $tabela, string $col, string $where, array $p) use ($pdo, $ids, &$res) {
            $stmt = $pdo->prepare("SELECT id, {$col} AS v FROM {$tabela} WHERE {$where}");
            $stmt->execute($p);
            $upd = $pdo->prepare("UPDATE {$tabela} SET {$col} = :v WHERE id = :id");
            foreach ($stmt->fetchAll() as $r) {
                $novo = $col === 'detalhes_json' ? lgpd_limpar_json($r['v'], $ids) : lgpd_limpar_texto($r['v'], $ids);
                if ($novo !== $r['v']) {
                    $upd->execute(['v' => $novo, 'id' => $r['id']]);
                    $res['outros_textos']++;
                }
            }
        };
        $limpar('lead_transferencias', 'observacao', 'lead_id = :id AND empresa_id = :e', ['id' => $leadId, 'e' => $empresaId]);
        $limpar('roleta_fila', 'motivo', 'lead_id = :id AND empresa_id = :e', ['id' => $leadId, 'e' => $empresaId]);
        $limpar('auditoria', 'detalhes_json', "entidade = 'lead' AND entidade_id = :id AND empresa_id = :e", ['id' => $leadId, 'e' => $empresaId]);
        $pdo->prepare("UPDATE meta_eventos SET payload = '{}', erro = NULL WHERE lead_id = :id AND empresa_id = :e")->execute(['id' => $leadId, 'e' => $empresaId]);

        lead_registrar_evento($leadId, (int) $usuario['id'], 'sistema', 'Dados pessoais anonimizados (LGPD) por ' . $usuario['nome'] . '. Esta ação não pode ser desfeita.');
        auditoria_registrar($empresaId, (int) $usuario['id'], 'lead.anonimizado', 'lead', $leadId, $res);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    // Arquivos só saem do disco depois do commit (se o banco falhar, nada é perdido pela metade).
    foreach ($apagar as $c) {
        @unlink($c);
    }
    return $res;
}

/** Tudo o que o sistema guarda sobre o titular de UM lead (para atender um pedido de acesso). */
function lgpd_dados_titular(array $lead): array
{
    $pdo = db();
    $leadId = (int) $lead['id'];
    $q = function (string $sql) use ($pdo, $leadId) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $leadId]);
        return $stmt->fetchAll();
    };
    return [
        'gerado_em' => date('c'),
        'aviso' => 'Dados pessoais de um único titular, exportados do WeMart Sales a pedido. Trate conforme a LGPD.',
        'cadastro' => [
            'codigo' => $leadId,
            'nome' => $lead['nome'], 'telefone' => $lead['telefone'], 'email' => $lead['email'],
            'interesse' => $lead['interesse'], 'renda_informada' => $lead['renda_informada'],
            'origem' => lead_origens()[$lead['origem']] ?? $lead['origem'], 'campanha' => $lead['campanha'] ?? null,
            'empreendimento' => $lead['empreendimento_nome'] ?? null, 'etapa' => lead_etapa_rotulo((string) $lead['etapa']),
            'responsavel_atual' => $lead['responsavel_nome'] ?? null, 'data_entrada' => $lead['criado_em'],
            'primeiro_contato' => $lead['primeiro_contato_em'] ?? null, 'proximo_passo' => $lead['proximo_passo'], 'data_retorno' => $lead['data_retorno'],
            'motivo_perda' => $lead['motivo_perda'],
        ],
        'historico' => $q('SELECT e.criado_em AS data, e.tipo, e.descricao, u.nome AS por FROM lead_eventos e LEFT JOIN usuarios u ON u.id = e.usuario_id WHERE e.lead_id = :id ORDER BY e.criado_em, e.id'),
        'agenda' => $q('SELECT a.tipo, a.titulo, a.inicio, a.status, a.observacao, u.nome AS responsavel FROM agenda_compromissos a LEFT JOIN usuarios u ON u.id = a.usuario_id WHERE a.lead_id = :id ORDER BY a.inicio'),
        'tarefas' => $q('SELECT titulo, descricao, data_vencimento, concluida FROM lead_tarefas WHERE lead_id = :id ORDER BY criado_em'),
        'arquivos' => $q('SELECT nome_original, mime, tamanho, criado_em FROM lead_arquivos WHERE lead_id = :id AND excluido_em IS NULL ORDER BY criado_em'),
        'transferencias' => $q("SELECT t.criado_em AS data, t.motivo, du.nome AS de, pu.nome AS para FROM lead_transferencias t
            LEFT JOIN usuarios du ON du.id = t.de_usuario_id LEFT JOIN usuarios pu ON pu.id = t.para_usuario_id WHERE t.lead_id = :id ORDER BY t.criado_em"),
    ];
}
