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
