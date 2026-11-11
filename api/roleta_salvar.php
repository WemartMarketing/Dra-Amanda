<?php
/**
 * POST /api/roleta_salvar.php — cria (sem id) ou edita uma roleta da empresa.
 *   { id?, nome, plantao_id?, horario_inicio, horario_fim, dias_semana[],
 *     exigir_presenca, precisao_maxima_metros, validade_presenca_min,
 *     intervalo_verificacao_min, max_leads_dia_corretor?, ativa }
 * Endereço, coordenadas e raio de presença vêm do plantão (Etapa 2).
 * Pausar uma roleta encerra as presenças abertas nela.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/roleta.php';

$usuario = api_iniciar('gerenciar_roletas');
$empresaId = (int) $usuario['empresa_ativa_id'];
$id = (int) ($_POST['id'] ?? 0);

$nome = api_texto($_POST['nome'] ?? null);
if (!$nome) {
    api_erro('Informe um nome para a roleta.', 422);
}
$plantaoId = (int) ($_POST['plantao_id'] ?? 0) ?: null;
if ($plantaoId && !api_registro_da_empresa('plantoes', $plantaoId, $empresaId)) {
    api_erro('Plantão inválido.', 422);
}

$hora = function ($valor) {
    $valor = trim((string) $valor);
    if ($valor === '') {
        return null;
    }
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $valor)) {
        api_erro('Horário inválido. Use HH:MM.', 422);
    }
    return substr($valor, 0, 5) . ':00';
};
$horarioInicio = $hora($_POST['horario_inicio'] ?? '');
$horarioFim = $hora($_POST['horario_fim'] ?? '');
if (($horarioInicio === null) !== ($horarioFim === null)) {
    api_erro('Informe o início e o fim do horário (ou deixe os dois vazios para qualquer horário).', 422);
}
if ($horarioInicio !== null && $horarioInicio === $horarioFim) {
    api_erro('O início e o fim do horário não podem ser iguais.', 422);
}

$dias = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['dias_semana'] ?? [])), fn ($d) => $d >= 1 && $d <= 7)));
sort($dias);
if (!$dias) {
    api_erro('Escolha pelo menos um dia da semana.', 422);
}

$inteiro = function (string $campo, int $min, int $max, ?int $padrao, string $rotulo) {
    $valor = trim((string) ($_POST[$campo] ?? ''));
    if ($valor === '') {
        return $padrao;
    }
    if (!ctype_digit($valor) || (int) $valor < $min || (int) $valor > $max) {
        api_erro("{$rotulo} deve ser um número de {$min} a {$max}.", 422);
    }
    return (int) $valor;
};
$precisao = $inteiro('precisao_maxima_metros', 10, 2000, 150, 'Precisão máxima do GPS');
$validade = $inteiro('validade_presenca_min', 5, 240, 20, 'Validade da presença');
$intervalo = $inteiro('intervalo_verificacao_min', 1, 60, 5, 'Intervalo de verificação');
if ($intervalo >= $validade) {
    api_erro('O intervalo de verificação precisa ser menor que a validade da presença, senão a presença vence antes de ser renovada.', 422);
}
$limiteDia = $inteiro('max_leads_dia_corretor', 1, 500, null, 'Limite diário por corretor');

$dados = [
    'nome' => $nome,
    'plantao_id' => $plantaoId,
    'horario_inicio' => $horarioInicio,
    'horario_fim' => $horarioFim,
    'dias_semana' => implode(',', $dias),
    'exigir_presenca' => !empty($_POST['exigir_presenca']) ? 1 : 0,
    'precisao_maxima_metros' => $precisao,
    'validade_presenca_min' => $validade,
    'intervalo_verificacao_min' => $intervalo,
    'max_leads_dia_corretor' => $limiteDia,
    'ativa' => !empty($_POST['ativa']) ? 1 : 0,
];

if ($id) {
    $atual = roleta_carregar($empresaId, $id);
    if (!$atual) {
        api_erro('Roleta não encontrada.', 404);
    }
    $sets = implode(', ', array_map(fn ($c) => "{$c} = :{$c}", array_keys($dados)));
    db()->prepare("UPDATE roletas SET {$sets} WHERE id = :id AND empresa_id = :empresa_id")
        ->execute($dados + ['id' => $id, 'empresa_id' => $empresaId]);
    // Pausar, mudar horário/dias ou desligar a exigência fecha quem não se encaixa mais.
    roleta_fechar_expiradas($empresaId, $id);
    auditoria_registrar($empresaId, (int) $usuario['id'], $atual['ativa'] && !$dados['ativa'] ? 'roleta.pausada' : 'roleta.editada', 'roleta', $id, $dados);
    api_responder(['ok' => true, 'id' => $id, 'mensagem' => 'Regras da roleta salvas.']);
}

$colunas = array_keys($dados);
db()->prepare('INSERT INTO roletas (empresa_id, ' . implode(', ', $colunas) . ') VALUES (:empresa_id, :' . implode(', :', $colunas) . ')')
    ->execute($dados + ['empresa_id' => $empresaId]);
$novoId = (int) db()->lastInsertId();
auditoria_registrar($empresaId, (int) $usuario['id'], 'roleta.criada', 'roleta', $novoId, $dados);
api_responder(['ok' => true, 'id' => $novoId, 'mensagem' => 'Roleta criada.']);
