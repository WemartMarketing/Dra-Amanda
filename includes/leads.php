<?php
/**
 * Helpers compartilhados sobre o funil de leads (etapas, rótulos,
 * registro de eventos no histórico). Usado por inicio.php, kanban.php,
 * lead.php e os endpoints em api/.
 */

/** Ordem oficial das etapas do funil (Kanban, tela 02). */
function lead_etapas_funil(): array
{
    return ['novo_lead', 'primeiro_contato', 'em_atendimento', 'visita_agendada', 'visita_realizada', 'contrato_assinado'];
}

function lead_etapa_rotulo(string $etapa): string
{
    return [
        'novo_lead'         => 'Novo lead',
        'primeiro_contato'  => '1º contato',
        'em_atendimento'    => 'Em atendimento',
        'visita_agendada'   => 'Visita agendada',
        'visita_realizada'  => 'Visita realizada',
        'contrato_assinado' => 'Contrato assinado',
        'perdido'           => 'Perdido',
    ][$etapa] ?? $etapa;
}

/** Grava uma linha no histórico (lead_eventos) do lead. */
function lead_registrar_evento(int $leadId, ?int $usuarioId, string $tipo, string $descricao): void
{
    $stmt = db()->prepare('INSERT INTO lead_eventos (lead_id, usuario_id, tipo, descricao) VALUES (:lead_id, :usuario_id, :tipo, :descricao)');
    $stmt->execute([
        'lead_id' => $leadId,
        'usuario_id' => $usuarioId,
        'tipo' => $tipo,
        'descricao' => $descricao,
    ]);
}

/** Próxima/anterior etapa no funil linear (para os botões do Kanban). */
function lead_etapa_adjacente(string $etapaAtual, int $direcao): ?string
{
    $funil = lead_etapas_funil();
    $idx = array_search($etapaAtual, $funil, true);
    if ($idx === false) {
        return null;
    }
    $novoIdx = $idx + $direcao;
    return $funil[$novoIdx] ?? null;
}

/** Formata um telefone brasileiro (10 ou 11 dígitos) para exibição. */
function lead_formatar_telefone(?string $telefone): string
{
    if (!$telefone) {
        return '—';
    }
    $digitos = preg_replace('/\D/', '', $telefone);
    if (strlen($digitos) === 11) {
        return sprintf('(%s) %s-%s', substr($digitos, 0, 2), substr($digitos, 2, 5), substr($digitos, 7));
    }
    if (strlen($digitos) === 10) {
        return sprintf('(%s) %s-%s', substr($digitos, 0, 2), substr($digitos, 2, 4), substr($digitos, 6));
    }
    return $telefone;
}
