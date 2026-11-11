<?php
/**
 * POST /api/recuperacao_salvar.php
 *   { ativa?, dias_sem_feedback, avisar_dias_antes, categorias[], redistribuir_automaticamente? }
 * Permissão configurar_recuperacao. Só a regra da própria empresa.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/recuperacao.php';

$usuario = api_iniciar('configurar_recuperacao');
$empresaId = (int) $usuario['empresa_ativa_id'];

$dias = (int) ($_POST['dias_sem_feedback'] ?? 0);
if ($dias < 1 || $dias > 60) {
    api_erro('Dias sem feedback: escolha de 1 a 60.', 422);
}
$aviso = (int) ($_POST['avisar_dias_antes'] ?? 0);
if ($aviso < 0 || $aviso >= $dias) {
    api_erro('O aviso precisa vir antes do prazo: de 0 a ' . ($dias - 1) . ' dia(s).', 422);
}
$permitidas = array_keys(recuperacao_categorias_permitidas());
$categorias = array_values(array_intersect($permitidas, array_map('strval', (array) ($_POST['categorias'] ?? []))));
$ativa = !empty($_POST['ativa']) ? 1 : 0;
if ($ativa && !$categorias) {
    api_erro('Escolha pelo menos uma etapa sujeita à recuperação (ou desative a regra).', 422);
}
$redistribuir = !empty($_POST['redistribuir_automaticamente']) ? 1 : 0;

$antes = recuperacao_regra($empresaId);
db()->prepare('INSERT INTO recuperacao_regras (empresa_id, ativa, dias_sem_feedback, avisar_dias_antes, categorias, redistribuir_automaticamente)
               VALUES (:e, :a, :d, :av, :c, :r)
               ON DUPLICATE KEY UPDATE ativa = VALUES(ativa), dias_sem_feedback = VALUES(dias_sem_feedback),
                   avisar_dias_antes = VALUES(avisar_dias_antes), categorias = VALUES(categorias),
                   redistribuir_automaticamente = VALUES(redistribuir_automaticamente)')
    ->execute(['e' => $empresaId, 'a' => $ativa, 'd' => $dias, 'av' => $aviso, 'c' => implode(',', $categorias) ?: 'novo_lead', 'r' => $redistribuir]);
auditoria_registrar($empresaId, (int) $usuario['id'], 'recuperacao.regra_salva', 'empresa', $empresaId, [
    'antes' => ['ativa' => $antes['ativa'], 'dias' => $antes['dias_sem_feedback'], 'aviso' => $antes['avisar_dias_antes'], 'categorias' => $antes['lista_categorias'], 'redistribuir' => $antes['redistribuir_automaticamente']],
    'depois' => ['ativa' => $ativa, 'dias' => $dias, 'aviso' => $aviso, 'categorias' => $categorias, 'redistribuir' => $redistribuir],
]);
api_responder(['ok' => true, 'mensagem' => 'Regra de recuperação salva.']);
