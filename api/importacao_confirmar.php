<?php
/**
 * POST /api/importacao_confirmar.php
 *   { token, nome_lista, empreendimento_id?, col_nome, col_telefone, col_email?, col_interesse?, col_observacao? }
 * Passo 2 → 3: importa com o mapeamento escolhido, cria a lista de oferta
 * ativa e apaga o arquivo temporário. Permissão gerenciar_oferta_ativa.
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/importacao.php';

$usuario = api_iniciar('gerenciar_oferta_ativa');
$empresaId = (int) $usuario['empresa_ativa_id'];
$token = (string) ($_POST['token'] ?? '');
$sessao = $_SESSION['importacoes'][$token] ?? null;
if (!preg_match('/^[a-f0-9]{32}$/', $token) || !$sessao || (int) $sessao['empresa_id'] !== $empresaId || (int) $sessao['usuario_id'] !== (int) $usuario['id']) {
    api_erro('Importação não encontrada ou expirada. Envie a planilha de novo.', 404);
}
$caminho = importacao_caminho($token, $sessao['ext']);
$leitura = importacao_ler($caminho, $sessao['ext']);
if (!$leitura['ok']) {
    api_erro($leitura['erro'], 422);
}
$largura = count($leitura['linhas'][0]);

$nomeLista = api_texto($_POST['nome_lista'] ?? null);
if (!$nomeLista) {
    api_erro('Dê um nome para a lista (ex.: “Feirão de março — Zona Sul”).', 422);
}
$empreendimentoId = (int) ($_POST['empreendimento_id'] ?? 0) ?: null;
if ($empreendimentoId && !api_registro_da_empresa('empreendimentos', $empreendimentoId, $empresaId)) {
    api_erro('Empreendimento não encontrado.', 404);
}
$mapa = [];
$usadas = [];
foreach (array_keys(importacao_campos()) as $campo) {
    $v = $_POST['col_' . $campo] ?? '';
    if ($v === '' || $v === null) {
        $mapa[$campo] = null;
        continue;
    }
    $i = (int) $v;
    if ((string) $i !== (string) $v || $i < 0 || $i >= $largura) {
        api_erro('Coluna inválida para ' . importacao_campos()[$campo] . '.', 422);
    }
    if (isset($usadas[$i])) {
        api_erro('A mesma coluna foi escolhida para dois campos.', 422);
    }
    $usadas[$i] = true;
    $mapa[$campo] = $i;
}
if ($mapa['nome'] === null || $mapa['telefone'] === null) {
    api_erro('Escolha as colunas de Nome e Telefone.', 422);
}

[$listaId, $rel] = importacao_executar($usuario, $leitura['linhas'], $mapa, $nomeLista, $empreendimentoId, $sessao['nome']);
@unlink($caminho);
unset($_SESSION['importacoes'][$token]);

// Linhas recusadas: CSV temporário para download (apagado pelo cron em 1 h).
$motivos = [];
foreach ($rel['rejeitados'] as $r) {
    $chave = preg_replace('/ \(linha \d+\)$/', '', $r['motivo']);
    $motivos[$chave] = ($motivos[$chave] ?? 0) + 1;
}
$rejeitadosToken = null;
if ($rel['rejeitados']) {
    $rejeitadosToken = bin2hex(random_bytes(16));
    $fh = fopen(importacao_caminho($rejeitadosToken, 'csv'), 'w');
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, ['Linha', 'Motivo', 'Nome', 'Telefone'], ';', '"', '');
    foreach ($rel['rejeitados'] as $r) {
        // Evita injeção de fórmula ao abrir no Excel.
        $limpa = fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;
        fputcsv($fh, [$r['linha'], $r['motivo'], $limpa($r['nome']), $limpa($r['telefone'])], ';', '"', '');
    }
    fclose($fh);
}
$_SESSION['importacao_relatorios'][$listaId] = [
    'motivos' => $motivos,
    'amostra' => array_slice($rel['rejeitados'], 0, 20),
    'rejeitados_token' => $rejeitadosToken,
];
api_responder(['ok' => true, 'id' => $listaId, 'mensagem' => $rel['importados'] . ' lead(s) importado(s).']);
