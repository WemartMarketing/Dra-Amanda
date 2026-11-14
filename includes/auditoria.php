<?php
/**
 * Auditoria — registra eventos administrativos/sensíveis na tabela
 * `auditoria` (schema já existia desde a primeira versão; esta é a
 * primeira etapa que efetivamente grava nela).
 */
function auditoria_registrar(?int $empresaId, ?int $usuarioId, string $acao, string $entidade, ?int $entidadeId = null, array $detalhes = []): void
{
    $stmt = db()->prepare('
        INSERT INTO auditoria (empresa_id, usuario_id, acao, entidade, entidade_id, detalhes_json)
        VALUES (:empresa_id, :usuario_id, :acao, :entidade, :entidade_id, :detalhes)
    ');
    $stmt->execute([
        'empresa_id' => $empresaId,
        'usuario_id' => $usuarioId,
        'acao' => $acao,
        'entidade' => $entidade,
        'entidade_id' => $entidadeId,
        'detalhes' => $detalhes ? json_encode($detalhes, JSON_UNESCAPED_UNICODE) : null,
    ]);
}

/** Rótulo legível de uma ação gravada (ex.: lead.etapa_alterada → "Lead: etapa alterada"). */
function auditoria_rotulo_acao(string $acao): string
{
    $fixos = [
        'login.sucesso' => 'Entrou no sistema', 'login.falha' => 'Tentativa de login recusada',
        'lead.criado' => 'Lead cadastrado', 'lead.editado' => 'Lead editado', 'lead.etapa_alterada' => 'Lead mudou de etapa',
        'lead.excluido' => 'Lead excluído', 'lead.transferido' => 'Lead transferido', 'lead.recuperado_inatividade' => 'Lead retirado por inatividade',
        'lead.arquivo_enviado' => 'Arquivo anexado ao lead', 'lead.arquivo_excluido' => 'Arquivo do lead excluído',
        'lead.anonimizado' => 'Lead anonimizado (LGPD)', 'lead.dados_titular_exportados' => 'Dados do titular exportados (LGPD)',
        'relatorio.exportado' => 'Relatório exportado',
        'relatorio.exportacao_solicitada' => 'Exportação grande solicitada',
        'relatorio.exportacao_baixada' => 'Exportação baixada',
        'roleta.lead_distribuido' => 'Lead entregue pela roleta', 'roleta.lead_em_fila' => 'Lead na fila da roleta',
        'roleta.entrou' => 'Entrou na roleta', 'roleta.saiu' => 'Saiu da roleta', 'roleta.fila_processada' => 'Fila da roleta processada',
        'meta.lead_recebido' => 'Lead recebido do Meta Ads', 'oferta.lista_importada' => 'Planilha de oferta ativa importada',
        'oferta.distribuida' => 'Oferta ativa distribuída', 'backup.gerado' => 'Backup do banco gerado',
    ];
    if (isset($fixos[$acao])) {
        return $fixos[$acao];
    }
    $entidades = ['usuario' => 'Usuário', 'equipe' => 'Equipe', 'superintendencia' => 'Superintendência', 'empreendimento' => 'Empreendimento',
        'plantao' => 'Plantão', 'roleta' => 'Roleta', 'funil' => 'Funil', 'funcao' => 'Função', 'agenda' => 'Agenda', 'meta' => 'Meta Ads',
        'oferta' => 'Oferta ativa', 'recuperacao' => 'Recuperação', 'empresa' => 'Empresa', 'lead' => 'Lead'];
    [$ent, $resto] = array_pad(explode('.', $acao, 2), 2, '');
    $resto = str_replace('_', ' ', $resto);
    return ($entidades[$ent] ?? ucfirst(str_replace('_', ' ', $ent))) . ($resto !== '' ? ': ' . $resto : '');
}

function auditoria_rotulo_entidade(string $entidade): string
{
    return [
        'lead' => 'Lead', 'usuario' => 'Usuário', 'equipe' => 'Equipe', 'superintendencia' => 'Superintendência',
        'empreendimento' => 'Empreendimento', 'plantao' => 'Plantão', 'roleta' => 'Roleta', 'funil_etapa' => 'Etapa do funil',
        'funcao' => 'Função', 'agenda' => 'Compromisso', 'meta_pagina' => 'Página do Facebook', 'meta_formulario' => 'Formulário do Meta',
        'meta_evento' => 'Evento do Meta', 'oferta_lista' => 'Lista de oferta ativa', 'recuperacao_regra' => 'Regra de recuperação',
        'empresa' => 'Empresa', 'relatorio' => 'Relatório', 'sistema' => 'Sistema',
    ][$entidade] ?? ucfirst(str_replace('_', ' ', $entidade));
}

/**
 * Detalhes em texto legível e seguro: JSON indentado, com qualquer chave
 * que pareça segredo (token, senha, secret, password, chave, key)
 * mascarada. Booleanos que só dizem "foi trocado" continuam visíveis.
 */
function auditoria_detalhes_seguros(?string $json): string
{
    if ($json === null || $json === '') {
        return '';
    }
    $dados = json_decode($json, true);
    if (!is_array($dados)) {
        return mb_substr($json, 0, 500);
    }
    $mascarar = function ($v, $k = '') use (&$mascarar) {
        if (is_array($v)) {
            $out = [];
            foreach ($v as $kk => $vv) {
                $out[$kk] = $mascarar($vv, (string) $kk);
            }
            return $out;
        }
        if (!is_bool($v) && preg_match('/(token|senha|secret|password|passwd|chave|api_?key|app_key)/i', $k) && !preg_match('/_(trocad[oa]|alterad[oa])$/', $k)) {
            return '••••';
        }
        return $v;
    };
    $texto = json_encode($mascarar($dados), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return mb_substr((string) $texto, 0, 2000);
}
