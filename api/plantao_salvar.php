<?php
/**
 * POST /api/plantao_salvar.php
 *   { id?, nome, endereco, bairro, cidade, estado, latitude, longitude,
 *     raio_metros, ativo?, empreendimentos[] }
 * Permissão: gerenciar_plantoes. Sem exclusão física (roletas apontam
 * para o plantão); desativar com "ativo".
 */
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/estrutura.php';

$usuario = api_iniciar('gerenciar_plantoes');
$empresaId = (int) $usuario['empresa_ativa_id'];
$id = (int) ($_POST['id'] ?? 0);

if ($id && !api_registro_da_empresa('plantoes', $id, $empresaId)) {
    api_erro('Plantão não encontrado.', 404);
}

$nome = api_texto($_POST['nome'] ?? null);
if ($nome === null) {
    api_erro('Informe o nome do plantão.', 422);
}
$estado = strtoupper(trim((string) ($_POST['estado'] ?? '')));
if ($estado !== '' && !in_array($estado, estados_brasil(), true)) {
    api_erro('Estado inválido.', 422);
}

/** Aceita vírgula decimal (padrão brasileiro) e valida a faixa. */
function coordenada(?string $valor, float $limite): ?float
{
    $valor = str_replace(',', '.', trim((string) $valor));
    if ($valor === '') {
        return null;
    }
    if (!is_numeric($valor) || abs((float) $valor) > $limite) {
        return NAN;
    }
    return round((float) $valor, 7);
}
$latitude = coordenada($_POST['latitude'] ?? null, 90);
$longitude = coordenada($_POST['longitude'] ?? null, 180);
if (is_float($latitude) && is_nan($latitude) || is_float($longitude) && is_nan($longitude)) {
    api_erro('Coordenadas inválidas. Use o formato -23.5505 / -46.6333.', 422);
}
if (($latitude === null) !== ($longitude === null)) {
    api_erro('Informe latitude e longitude juntas (ou deixe as duas vazias).', 422);
}

$raio = (int) ($_POST['raio_metros'] ?? 300);
if ($raio < 50 || $raio > 5000) {
    api_erro('O raio deve ficar entre 50 e 5.000 metros.', 422);
}

$empreendimentos = api_ids_da_empresa('empreendimentos', $_POST['empreendimentos'] ?? [], $empresaId);

$dados = [
    'nome'        => $nome,
    'endereco'    => api_texto($_POST['endereco'] ?? null, 255),
    'bairro'      => api_texto($_POST['bairro'] ?? null, 120),
    'cidade'      => api_texto($_POST['cidade'] ?? null, 120),
    'estado'      => $estado !== '' ? $estado : null,
    'latitude'    => $latitude,
    'longitude'   => $longitude,
    'raio_metros' => $raio,
    'ativo'       => $id ? (!empty($_POST['ativo']) ? 1 : 0) : 1,
];

db()->beginTransaction();
if ($id) {
    $sets = implode(', ', array_map(fn ($c) => "{$c} = :{$c}", array_keys($dados)));
    db()->prepare("UPDATE plantoes SET {$sets} WHERE id = :id AND empresa_id = :empresa_id")
        ->execute($dados + ['id' => $id, 'empresa_id' => $empresaId]);
    $evento = 'plantao.editado';
} else {
    $colunas = implode(', ', array_keys($dados));
    $valores = implode(', ', array_map(fn ($c) => ":{$c}", array_keys($dados)));
    db()->prepare("INSERT INTO plantoes (empresa_id, {$colunas}) VALUES (:empresa_id, {$valores})")
        ->execute($dados + ['empresa_id' => $empresaId]);
    $id = (int) db()->lastInsertId();
    $evento = 'plantao.criado';
}
db()->prepare('DELETE FROM plantao_empreendimentos WHERE plantao_id = :id')->execute(['id' => $id]);
$ins = db()->prepare('INSERT INTO plantao_empreendimentos (plantao_id, empreendimento_id) VALUES (:pid, :eid)');
foreach ($empreendimentos as $empId) {
    $ins->execute(['pid' => $id, 'eid' => $empId]);
}
db()->commit();

auditoria_registrar($empresaId, (int) $usuario['id'], $evento, 'plantao', $id, [
    'nome' => $nome, 'raio_metros' => $raio, 'latitude' => $latitude, 'longitude' => $longitude,
    'ativo' => $dados['ativo'], 'empreendimentos' => $empreendimentos,
]);
api_responder(['ok' => true, 'id' => $id]);
