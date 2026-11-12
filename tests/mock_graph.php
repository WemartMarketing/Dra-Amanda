<?php
/**
 * Simulador da Graph API da Meta para os testes (NUNCA usado em produção).
 * Roda com:  php -S 127.0.0.1:PORTA tests/mock_graph.php
 * Cenário em tests/tmp/mock_cenario.json:
 *   {"rotas": [{"metodo": "POST", "caminho": "#/messages$#", "respostas": [{"status": 200, "corpo": {...}}, …]}]}
 * Cada rota devolve as respostas em sequência (a última se repete).
 * Toda requisição recebida é registrada em tests/tmp/mock_log.jsonl.
 */
// Segunda trava (além do .htaccess): só responde no servidor embutido do PHP (php -S) usado pelos testes.
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}
$dir = __DIR__ . '/tmp';
$cen = json_decode((string) @file_get_contents("$dir/mock_cenario.json"), true) ?: ['rotas' => []];
$metodo = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI']);
$caminho = preg_replace('#^/v[0-9.]+#', '', $uri['path']);
$bruto = file_get_contents('php://input');
$corpo = $bruto !== '' ? (json_decode($bruto, true) ?? $bruto) : $_POST;
file_put_contents("$dir/mock_log.jsonl", json_encode([
    'metodo' => $metodo, 'caminho' => $caminho, 'query' => $uri['query'] ?? '',
    'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? '', 'corpo' => $corpo,
    'arquivo' => isset($_FILES['file']) ? $_FILES['file']['name'] : null,
], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
header('Content-Type: application/json');
foreach ($cen['rotas'] as $i => $rota) {
    if ($rota['metodo'] === $metodo && preg_match($rota['caminho'], $caminho)) {
        $arq = "$dir/mock_cont_$i";
        $n = (int) @file_get_contents($arq);
        file_put_contents($arq, (string) ($n + 1));
        $resp = $rota['respostas'][min($n, count($rota['respostas']) - 1)];
        http_response_code($resp['status']);
        echo json_encode($resp['corpo']);
        exit;
    }
}
http_response_code(404);
echo json_encode(['error' => ['message' => 'rota não simulada: ' . $metodo . ' ' . $caminho, 'code' => 100]]);
