<?php
/**
 * Histórico estruturado para a Central de Relatórios (migração 013).
 *
 * Grava, no momento em que acontece:
 *   - cada atribuição de lead a um corretor (lead_atribuicoes), com a
 *     estrutura comercial NA DATA e o 1º atendimento / 1º contato efetivo
 *     daquela atribuição — o que permite "leads recebidos" e "taxa de
 *     atendimento" do corretor sem depender da carteira atual;
 *   - cada mudança de etapa (lead_etapa_historico), inclusive as feitas por
 *     jornadas automáticas, com a origem do movimento;
 *   - cada passagem pela fila da roleta (roleta_fila_historico);
 *   - se a roleta estava aberta quando o lead chegou.
 *
 * Tudo é guardado por historico_instalado(): sem a migração 013 o sistema
 * continua funcionando exatamente como antes (nada é gravado).
 *
 * Nenhuma função daqui altera o comportamento comercial (prazos da
 * recuperação, pendências, jornadas) — só registra.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/transferencia.php';

function historico_instalado(): bool
{
    return modulo_tabela_existe('lead_atribuicoes');
}

/** Formas de recebimento (lead_atribuicoes.forma). "mudanca_equipe" não conta como lead recebido. */
function historico_formas(): array
{
    return [
        'cadastro'       => 'Cadastro com responsável',
        'roleta'         => 'Roleta',
        'transferencia'  => 'Transferência',
        'oferta_ativa'   => 'Oferta ativa',
        'redistribuicao' => 'Redistribuição (recuperação)',
        'mudanca_equipe' => 'Mudança de equipe do corretor',
        'desconhecida'   => 'Não identificada',
    ];
}

/** Categorias que, quando o PRÓPRIO usuário move o lead para elas, provam contato efetivo. */
function historico_categorias_contato(): array
{
    return ['primeiro_contato', 'em_atendimento', 'visita_agendada', 'visita_realizada', 'contrato_assinado'];
}

/**
 * Abre uma atribuição: encerra a anterior em aberto (se houver) e grava a
 * nova com a estrutura atual do corretor. Em "mudanca_equipe" o corretor é o
 * mesmo: o 1º atendimento/contato já feitos acompanham a nova linha, para
 * não parecer que o lead voltou a "não atendido".
 *
 * $extra: roleta_id, transferencia_id, motivo_anterior, em (Y-m-d H:i:s)
 */
function historico_abrir_atribuicao(int $leadId, int $usuarioId, string $forma, array $extra = []): ?int
{
    if (!historico_instalado() || !isset(historico_formas()[$forma])) {
        return null;
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT empresa_id, empreendimento_id FROM leads WHERE id = :l');
    $stmt->execute(['l' => $leadId]);
    $lead = $stmt->fetch();
    if (!$lead) {
        return null;
    }
    $em = $extra['em'] ?? date('Y-m-d H:i:s');
    $anterior = historico_atribuicao_aberta($leadId);
    $herdar = ['primeiro_atendimento_em' => null, 'primeiro_contato_efetivo_em' => null, 'contato_efetivo_via' => null];
    if ($anterior) {
        if ($forma === 'mudanca_equipe' && (int) $anterior['usuario_id'] === $usuarioId) {
            $herdar = array_intersect_key($anterior, $herdar);
        }
        $motivo = $extra['motivo_anterior'] ?? ($forma === 'mudanca_equipe' ? 'mudanca_equipe' : 'transferencia');
        $pdo->prepare('UPDATE lead_atribuicoes SET encerrada_em = :em, motivo_encerramento = :m WHERE id = :id')
            ->execute(['em' => $em, 'm' => $motivo, 'id' => $anterior['id']]);
    }
    $est = estrutura_do_usuario($usuarioId);
    $pdo->prepare('INSERT INTO lead_atribuicoes (empresa_id, lead_id, usuario_id, equipe_id, gerente_id, superintendencia_id, empreendimento_id,
                       forma, roleta_id, transferencia_id, atribuido_em, primeiro_atendimento_em, primeiro_contato_efetivo_em, contato_efetivo_via)
                   VALUES (:e, :l, :u, :eq, :g, :s, :emp, :f, :r, :t, :em, :pa, :pc, :via)')
        ->execute([
            'e' => $lead['empresa_id'], 'l' => $leadId, 'u' => $usuarioId,
            'eq' => $est['equipe_id'], 'g' => $est['gerente_id'], 's' => $est['superintendencia_id'],
            'emp' => $lead['empreendimento_id'] ?: null, 'f' => $forma,
            'r' => $extra['roleta_id'] ?? null, 't' => $extra['transferencia_id'] ?? null, 'em' => $em,
            'pa' => $herdar['primeiro_atendimento_em'], 'pc' => $herdar['primeiro_contato_efetivo_em'], 'via' => $herdar['contato_efetivo_via'],
        ]);
    return (int) $pdo->lastInsertId();
}

/** Encerra a atribuição em aberto do lead (perda por inatividade, exclusão). */
function historico_encerrar_atribuicao(int $leadId, string $motivo): void
{
    if (!historico_instalado()) {
        return;
    }
    db()->prepare('UPDATE lead_atribuicoes SET encerrada_em = NOW(), motivo_encerramento = :m WHERE lead_id = :l AND encerrada_em IS NULL')
        ->execute(['m' => mb_substr($motivo, 0, 30), 'l' => $leadId]);
}

function historico_atribuicao_aberta(int $leadId): ?array
{
    if (!historico_instalado()) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM lead_atribuicoes WHERE lead_id = :l AND encerrada_em IS NULL ORDER BY atribuido_em DESC, id DESC LIMIT 1');
    $stmt->execute(['l' => $leadId]);
    return $stmt->fetch() ?: null;
}

/**
 * Primeira atuação válida (feedback) do corretor nesta atribuição. Chamado
 * por lead_registrar_feedback(); só vale se quem está com o lead é o dono
 * da atribuição aberta.
 */
function historico_registrar_atuacao(int $leadId): void
{
    if (!historico_instalado()) {
        return;
    }
    db()->prepare('UPDATE lead_atribuicoes a JOIN leads l ON l.id = a.lead_id AND l.responsavel_id = a.usuario_id
                   SET a.primeiro_atendimento_em = NOW()
                   WHERE a.lead_id = :l AND a.encerrada_em IS NULL AND a.primeiro_atendimento_em IS NULL')
        ->execute(['l' => $leadId]);
}

/**
 * Contato efetivo (definição do dicionário): $via = whatsapp | pendencia |
 * etapa | visita. Grava o primeiro do lead e o primeiro da atribuição
 * aberta. Contato efetivo também é atuação.
 */
function historico_registrar_contato_efetivo(int $leadId, string $via, ?string $em = null): void
{
    if (!historico_instalado() || !in_array($via, ['whatsapp', 'pendencia', 'etapa', 'visita'], true)) {
        return;
    }
    $em = $em ?? date('Y-m-d H:i:s');
    $pdo = db();
    $pdo->prepare('UPDATE leads SET primeiro_contato_efetivo_em = :em WHERE id = :l AND primeiro_contato_efetivo_em IS NULL')
        ->execute(['em' => $em, 'l' => $leadId]);
    $pdo->prepare('UPDATE lead_atribuicoes SET primeiro_contato_efetivo_em = :em, contato_efetivo_via = :v,
                       primeiro_atendimento_em = CASE WHEN :via2 <> \'whatsapp\' THEN COALESCE(primeiro_atendimento_em, :em2) ELSE primeiro_atendimento_em END
                   WHERE lead_id = :l AND encerrada_em IS NULL AND primeiro_contato_efetivo_em IS NULL')
        ->execute(['em' => $em, 'v' => $via, 'via2' => $via, 'em2' => $em, 'l' => $leadId]);
}

/**
 * Mudança de etapa. $origem: usuario | jornada | sistema.
 * $de / $para: linhas de funil_etapas (id, categoria) — $de pode ser null.
 */
function historico_registrar_etapa(int $empresaId, int $leadId, ?array $de, array $para, ?int $usuarioId, string $origem): void
{
    if (!historico_instalado()) {
        return;
    }
    $stmt = db()->prepare('SELECT responsavel_id FROM leads WHERE id = :l');
    $stmt->execute(['l' => $leadId]);
    $resp = $stmt->fetchColumn();
    db()->prepare('INSERT INTO lead_etapa_historico (empresa_id, lead_id, de_etapa_id, para_etapa_id, de_categoria, para_categoria, usuario_id, responsavel_id, origem, ocorrido_em)
                   VALUES (:e, :l, :de, :para, :dc, :pc, :u, :r, :o, NOW())')
        ->execute([
            'e' => $empresaId, 'l' => $leadId, 'de' => $de['id'] ?? null, 'para' => (int) $para['id'],
            'dc' => $de['categoria'] ?? null, 'pc' => (string) $para['categoria'], 'u' => $usuarioId,
            'r' => $resp ? (int) $resp : null, 'o' => $origem,
        ]);
    if ($origem === 'usuario' && $usuarioId && in_array($para['categoria'], historico_categorias_contato(), true)) {
        historico_registrar_contato_efetivo($leadId, 'etapa');
    }
}

/** O lead entrou (ou voltou) para a fila de espera da roleta. Não duplica passagem em aberto. */
function historico_fila_entrou(int $empresaId, int $leadId, ?int $roletaId, string $origem): void
{
    if (!historico_instalado()) {
        return;
    }
    $stmt = db()->prepare('SELECT COUNT(*) FROM roleta_fila_historico WHERE lead_id = :l AND saiu_em IS NULL');
    $stmt->execute(['l' => $leadId]);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }
    db()->prepare('INSERT INTO roleta_fila_historico (empresa_id, lead_id, roleta_id, origem, entrou_em) VALUES (:e, :l, :r, :o, NOW())')
        ->execute(['e' => $empresaId, 'l' => $leadId, 'r' => $roletaId, 'o' => mb_substr($origem, 0, 20)]);
}

/** $resultado: distribuido | atribuido_manual | oferta_ativa | excluido */
function historico_fila_saiu(int $leadId, string $resultado): void
{
    if (!historico_instalado()) {
        return;
    }
    db()->prepare('UPDATE roleta_fila_historico SET saiu_em = NOW(), resultado = :r WHERE lead_id = :l AND saiu_em IS NULL')
        ->execute(['r' => $resultado, 'l' => $leadId]);
}

/**
 * Na PRIMEIRA chegada do lead à roleta (cadastro, Meta Ads, importação),
 * grava se a roleta estava aberta. Nunca sobrescreve: a resposta é sobre a
 * chegada, não sobre redistribuições depois.
 */
function historico_chegada_roleta(int $leadId, ?int $roletaId, bool $aberta): void
{
    if (!historico_instalado()) {
        return;
    }
    db()->prepare('UPDATE leads SET chegada_roleta_id = :r, chegada_roleta_aberta = :a WHERE id = :l AND chegada_roleta_aberta IS NULL')
        ->execute(['r' => $roletaId, 'a' => $aberta ? 1 : 0, 'l' => $leadId]);
}
