<?php
/**
 * Plantões de vendas — tela operacional (todos os usuários da empresa) e
 * cadastro (Master / gerenciar_plantoes). Amplia a tabela `plantoes`
 * existente: o mesmo cadastro alimenta a geolocalização das roletas.
 *
 * Navegação: links oficiais do Google Maps (rota de carro) e do Waze, com as
 * coordenadas do banco codificadas na URL. Sem coordenadas, busca pelo
 * endereço (também codificado). Sem endereço nem coordenadas, nenhum link é
 * gerado. Abrir a rota não pede localização do usuário.
 *
 * Visibilidade: só plantões da empresa ativa. Inativos só para quem gerencia.
 * "Meus plantões": o usuário está numa roleta do plantão ou foi autorizado
 * nele (plantao_usuarios).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/estrutura.php';
require_once __DIR__ . '/roleta.php';

const PLANTAO_FOTO_DIR = __DIR__ . '/../uploads/plantoes';

/** A migração 012 já rodou neste banco? */
function plantao_modulo_ok(): bool
{
    return modulo_tabela_existe('plantao_usuarios');
}

/** Sem a migração 012: aviso claro em vez de erro. */
function plantao_exigir_modulo(string $crumb, string $activeNav): void
{
    if (!plantao_modulo_ok()) {
        global $usuario;
        http_response_code(503);
        $pageTitle = 'Plantões — ' . APP_NAME;
        require __DIR__ . '/layout_start.php';
        echo '<div class="card stub-card"><div class="stub-icon">' . icon('map') . '</div><h2>Plantões em atualização</h2>'
           . '<p>Esta tela fica disponível assim que o administrador rodar a atualização do banco (migração 012). Avise o responsável pelo sistema.</p></div>';
        require __DIR__ . '/layout_end.php';
        exit;
    }
}

function plantao_pode_gerenciar(array $usuario): bool
{
    return empty($usuario['eh_superadmin']) && usuario_tem_permissao($usuario, 'gerenciar_plantoes');
}

/** Coordenada válida em formato numérico canônico ("-23.5262100") ou null. */
function plantao_coordenada($valor, float $limite): ?string
{
    if ($valor === null || $valor === '') {
        return null;
    }
    $v = str_replace(',', '.', trim((string) $valor));
    if (!preg_match('/^-?\d{1,3}(\.\d{1,10})?$/', $v) || abs((float) $v) > $limite) {
        return null;
    }
    return number_format((float) $v, 7, '.', '');
}

/** O plantão tem latitude e longitude utilizáveis? (0,0 é tratado como ausente) */
function plantao_coordenadas(array $p): ?array
{
    $lat = plantao_coordenada($p['latitude'] ?? null, 90);
    $lng = plantao_coordenada($p['longitude'] ?? null, 180);
    if ($lat === null || $lng === null || ((float) $lat === 0.0 && (float) $lng === 0.0)) {
        return null;
    }
    return [$lat, $lng];
}

/** "Av. X, 100 — Sala 2 · Bairro · Cidade/UF · CEP 00000-000" */
function plantao_endereco_completo(array $p): string
{
    $rua = trim((string) ($p['endereco'] ?? ''));
    if (!empty($p['complemento'])) {
        $rua .= ($rua !== '' ? ' — ' : '') . trim($p['complemento']);
    }
    $cidade = trim((string) ($p['cidade'] ?? ''));
    if ($cidade !== '' && !empty($p['estado'])) {
        $cidade .= '/' . $p['estado'];
    }
    return implode(' · ', array_filter([$rua, trim((string) ($p['bairro'] ?? '')), $cidade, !empty($p['cep']) ? 'CEP ' . $p['cep'] : ''], fn ($x) => $x !== ''));
}

/** Endereço em texto corrido para busca nos mapas (sem rótulos). */
function plantao_endereco_busca(array $p): string
{
    return implode(', ', array_filter(array_map('trim', [(string) ($p['endereco'] ?? ''), (string) ($p['bairro'] ?? ''),
        (string) ($p['cidade'] ?? ''), (string) ($p['estado'] ?? ''), (string) ($p['cep'] ?? '')]), fn ($x) => $x !== ''));
}

/**
 * Links de navegação. ['google', 'waze', 'por_coordenada' => bool] ou null
 * (sem coordenadas e sem endereço: nenhum link inválido é gerado).
 */
function plantao_links_navegacao(array $p): ?array
{
    if ($c = plantao_coordenadas($p)) {
        $destino = rawurlencode($c[0] . ',' . $c[1]);
        return [
            'google' => 'https://www.google.com/maps/dir/?api=1&destination=' . $destino . '&travelmode=driving&dir_action=navigate',
            'waze' => 'https://www.waze.com/ul?ll=' . $destino . '&navigate=yes',
            'por_coordenada' => true,
        ];
    }
    $end = plantao_endereco_busca($p);
    if (mb_strlen($end) < 5) {
        return null;
    }
    $q = rawurlencode($end);
    return [
        'google' => 'https://www.google.com/maps/dir/?api=1&destination=' . $q . '&travelmode=driving',
        'waze' => 'https://www.waze.com/ul?q=' . $q . '&navigate=yes',
        'por_coordenada' => false,
    ];
}

function plantao_foto_url(?string $foto): ?string
{
    return $foto && preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $foto) ? '/uploads/plantoes/' . $foto : null;
}

/**
 * Lista da tela. $filtro: todos | meus. $busca: nome, bairro, cidade ou
 * empreendimento. Sempre só a empresa ativa; inativos só para quem gerencia.
 */
function plantoes_listar(array $usuario, string $busca = '', string $filtro = 'todos'): array
{
    $empresaId = (int) $usuario['empresa_ativa_id'];
    $uid = (int) $usuario['id'];
    $sql = 'SELECT p.*,
                (EXISTS (SELECT 1 FROM plantao_usuarios pu WHERE pu.plantao_id = p.id AND pu.usuario_id = :u1)
                 OR EXISTS (SELECT 1 FROM roletas r JOIN roleta_corretores rc ON rc.roleta_id = r.id WHERE r.plantao_id = p.id AND r.empresa_id = p.empresa_id AND rc.usuario_id = :u2)) AS meu
            FROM plantoes p WHERE p.empresa_id = :e';
    $params = ['e' => $empresaId, 'u1' => $uid, 'u2' => $uid];
    if (!plantao_pode_gerenciar($usuario)) {
        $sql .= ' AND p.ativo = 1';
    }
    $busca = trim(mb_substr($busca, 0, 80));
    if ($busca !== '') {
        $sql .= " AND (p.nome LIKE :b1 OR p.bairro LIKE :b2 OR p.cidade LIKE :b3 OR p.endereco LIKE :b4
                  OR EXISTS (SELECT 1 FROM plantao_empreendimentos pe JOIN empreendimentos em ON em.id = pe.empreendimento_id
                             WHERE pe.plantao_id = p.id AND em.empresa_id = p.empresa_id AND em.nome LIKE :b5))";
        $like = '%' . addcslashes($busca, '%_\\') . '%';
        foreach (['b1', 'b2', 'b3', 'b4', 'b5'] as $k) {
            $params[$k] = $like;
        }
    }
    if ($filtro === 'meus') {
        $sql .= ' HAVING meu = 1';
    }
    $stmt = db()->prepare($sql . ' ORDER BY p.ativo DESC, meu DESC, p.nome');
    $stmt->execute($params);
    $lista = $stmt->fetchAll();
    foreach ($lista as &$p) {
        $p['empreendimentos'] = plantao_empreendimentos($empresaId, (int) $p['id']);
        $p['horario'] = plantao_horario_resumo($empresaId, $p);
    }
    return $lista;
}

/** Plantão visível para o usuário (ou null). */
function plantao_carregar(array $usuario, int $id): ?array
{
    if (!empty($usuario['eh_superadmin']) || $id <= 0) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM plantoes WHERE id = :id AND empresa_id = :e');
    $stmt->execute(['id' => $id, 'e' => (int) $usuario['empresa_ativa_id']]);
    $p = $stmt->fetch();
    if (!$p || (!$p['ativo'] && !plantao_pode_gerenciar($usuario))) {
        return null;
    }
    return $p;
}

/** Empreendimentos do plantão: os vinculados ao plantão + os das roletas dele. */
function plantao_empreendimentos(int $empresaId, int $plantaoId): array
{
    $stmt = db()->prepare('SELECT DISTINCT e.id, e.nome FROM empreendimentos e
                           WHERE e.empresa_id = :e AND e.ativo = 1 AND (
                               e.id IN (SELECT empreendimento_id FROM plantao_empreendimentos WHERE plantao_id = :p1)
                            OR e.id IN (SELECT re.empreendimento_id FROM roleta_empreendimentos re JOIN roletas r ON r.id = re.roleta_id WHERE r.plantao_id = :p2 AND r.empresa_id = :e2))
                           ORDER BY e.nome');
    $stmt->execute(['e' => $empresaId, 'e2' => $empresaId, 'p1' => $plantaoId, 'p2' => $plantaoId]);
    return $stmt->fetchAll();
}

function plantao_roletas(int $empresaId, int $plantaoId): array
{
    $stmt = db()->prepare('SELECT * FROM roletas WHERE plantao_id = :p AND empresa_id = :e ORDER BY ativa DESC, nome');
    $stmt->execute(['p' => $plantaoId, 'e' => $empresaId]);
    return $stmt->fetchAll();
}

/** Horário de funcionamento em uma linha: o da roleta ativa do plantão, senão o texto do cadastro. */
function plantao_horario_resumo(int $empresaId, array $p): ?string
{
    foreach (plantao_roletas($empresaId, (int) $p['id']) as $r) {
        if ($r['ativa']) {
            $dias = roleta_horarios_personalizados((int) $r['id']) ? '' : roleta_dias_texto($r['dias_semana']) . ' · ';
            return $dias . roleta_horario_texto($r);
        }
    }
    return $p['horario_descricao'] ?: null;
}

/** O usuário está autorizado no plantão (pela roleta ou pelo cadastro do plantão)? */
function plantao_usuario_vinculado(int $plantaoId, int $usuarioId): bool
{
    $stmt = db()->prepare('SELECT (EXISTS (SELECT 1 FROM plantao_usuarios WHERE plantao_id = :p1 AND usuario_id = :u1)
                                   OR EXISTS (SELECT 1 FROM roletas r JOIN roleta_corretores rc ON rc.roleta_id = r.id WHERE r.plantao_id = :p2 AND rc.usuario_id = :u2))');
    $stmt->execute(['p1' => $plantaoId, 'u1' => $usuarioId, 'p2' => $plantaoId, 'u2' => $usuarioId]);
    return (bool) $stmt->fetchColumn();
}

/** Corretores autorizados no cadastro do plantão. */
function plantao_usuarios(int $plantaoId): array
{
    $stmt = db()->prepare('SELECT usuario_id FROM plantao_usuarios WHERE plantao_id = :p');
    $stmt->execute(['p' => $plantaoId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** CEP no formato 00000-000 (ou null; false se inválido). */
function plantao_cep($valor)
{
    $d = preg_replace('/\D/', '', (string) $valor);
    if ($d === '') {
        return null;
    }
    return strlen($d) === 8 ? substr($d, 0, 5) . '-' . substr($d, 5) : false;
}

/**
 * Cria ou edita (sem exclusão física: roletas e histórico apontam para o
 * plantão). $in: os campos do formulário. Devolve ['ok', 'id'] ou ['ok' => false, 'erro'].
 * A foto (upload) é tratada pelo chamador e chega em $foto (nome do arquivo,
 * '' para remover, null para manter).
 */
function plantao_salvar(array $usuario, ?int $id, array $in, ?string $foto = null): array
{
    $empresaId = (int) $usuario['empresa_ativa_id'];
    $pdo = db();
    $atual = null;
    if ($id) {
        $stmt = $pdo->prepare('SELECT * FROM plantoes WHERE id = :id AND empresa_id = :e');
        $stmt->execute(['id' => $id, 'e' => $empresaId]);
        $atual = $stmt->fetch();
        if (!$atual) {
            return ['ok' => false, 'erro' => 'Plantão não encontrado.'];
        }
    }
    $txt = fn ($k, $n) => ($t = trim(mb_substr((string) ($in[$k] ?? ''), 0, $n))) !== '' ? $t : null;
    $nome = $txt('nome', 190);
    if (!$nome || mb_strlen($nome) < 3) {
        return ['ok' => false, 'erro' => 'Informe o nome do plantão.'];
    }
    $estado = strtoupper(trim((string) ($in['estado'] ?? '')));
    if ($estado !== '' && !in_array($estado, estados_brasil(), true)) {
        return ['ok' => false, 'erro' => 'Estado inválido.'];
    }
    $cep = plantao_cep($in['cep'] ?? '');
    if ($cep === false) {
        return ['ok' => false, 'erro' => 'CEP inválido. Use 8 números (ex.: 05001-100).'];
    }
    $latIn = trim((string) ($in['latitude'] ?? ''));
    $lngIn = trim((string) ($in['longitude'] ?? ''));
    $lat = plantao_coordenada($latIn, 90);
    $lng = plantao_coordenada($lngIn, 180);
    if (($latIn !== '' && $lat === null) || ($lngIn !== '' && $lng === null)) {
        return ['ok' => false, 'erro' => 'Coordenadas inválidas. Use o formato -23.5505 e -46.6333 (latitude de -90 a 90; longitude de -180 a 180).'];
    }
    if (($lat === null) !== ($lng === null)) {
        return ['ok' => false, 'erro' => 'Informe latitude e longitude juntas (ou deixe as duas vazias).'];
    }
    if ($lat !== null && (float) $lat === 0.0 && (float) $lng === 0.0) {
        return ['ok' => false, 'erro' => 'Coordenadas 0,0 não são um endereço real. Confira no mapa.'];
    }
    $raio = (int) ($in['raio_metros'] ?? ($atual['raio_metros'] ?? 300));
    if ($raio < 50 || $raio > 5000) {
        return ['ok' => false, 'erro' => 'O raio deve ficar entre 50 e 5.000 metros.'];
    }
    $ids = function (string $tabela, $valores, string $extra = '') use ($pdo, $empresaId): array {
        $v = array_values(array_unique(array_filter(array_map('intval', (array) $valores))));
        if (!$v) {
            return [];
        }
        $stmt = $pdo->prepare("SELECT id FROM {$tabela} WHERE empresa_id = ? {$extra} AND id IN (" . implode(',', array_fill(0, count($v), '?')) . ')');
        $stmt->execute(array_merge([$empresaId], $v));
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    };
    $empreendimentos = $ids('empreendimentos', $in['empreendimentos'] ?? []);
    $roletas = $ids('roletas', $in['roletas'] ?? []);
    $usuarios = $ids('usuarios', $in['usuarios'] ?? [], 'AND ativo = 1');

    $dados = [
        'nome' => $nome, 'endereco' => $txt('endereco', 255), 'cep' => $cep, 'complemento' => $txt('complemento', 120),
        'bairro' => $txt('bairro', 120), 'cidade' => $txt('cidade', 120), 'estado' => $estado !== '' ? $estado : null,
        'latitude' => $lat, 'longitude' => $lng, 'raio_metros' => $raio, 'horario_descricao' => $txt('horario_descricao', 190),
        'observacoes' => $txt('observacoes', 500), 'ativo' => $id ? (!empty($in['ativo']) ? 1 : 0) : 1,
    ];
    if ($foto !== null) {
        $dados['foto'] = $foto !== '' ? $foto : null;
    }
    $pdo->beginTransaction();
    try {
        if ($id) {
            $sets = implode(', ', array_map(fn ($c) => "{$c} = :{$c}", array_keys($dados)));
            $pdo->prepare("UPDATE plantoes SET {$sets}, atualizado_em = NOW() WHERE id = :id AND empresa_id = :empresa_id")
                ->execute($dados + ['id' => $id, 'empresa_id' => $empresaId]);
        } else {
            $cols = implode(', ', array_keys($dados));
            $vals = implode(', ', array_map(fn ($c) => ":{$c}", array_keys($dados)));
            $pdo->prepare("INSERT INTO plantoes (empresa_id, {$cols}) VALUES (:empresa_id, {$vals})")->execute($dados + ['empresa_id' => $empresaId]);
            $id = (int) $pdo->lastInsertId();
        }
        $pdo->prepare('DELETE FROM plantao_empreendimentos WHERE plantao_id = :id')->execute(['id' => $id]);
        $ins = $pdo->prepare('INSERT INTO plantao_empreendimentos (plantao_id, empreendimento_id) VALUES (:p, :e)');
        foreach ($empreendimentos as $e) {
            $ins->execute(['p' => $id, 'e' => $e]);
        }
        $pdo->prepare('DELETE FROM plantao_usuarios WHERE plantao_id = :id')->execute(['id' => $id]);
        $ins = $pdo->prepare('INSERT INTO plantao_usuarios (plantao_id, usuario_id) VALUES (:p, :u)');
        foreach ($usuarios as $u) {
            $ins->execute(['p' => $id, 'u' => $u]);
        }
        // Roletas deste plantão: as marcadas passam a apontar para ele; as desmarcadas ficam sem plantão.
        $pdo->prepare('UPDATE roletas SET plantao_id = NULL WHERE plantao_id = :p AND empresa_id = :e')->execute(['p' => $id, 'e' => $empresaId]);
        if ($roletas) {
            $pdo->prepare('UPDATE roletas SET plantao_id = ? WHERE empresa_id = ? AND id IN (' . implode(',', array_fill(0, count($roletas), '?')) . ')')
                ->execute(array_merge([$id, $empresaId], $roletas));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    // Mudou o lugar, o raio ou desativou: quem está na roleta é reavaliado.
    foreach ($roletas as $r) {
        roleta_fechar_expiradas($empresaId, $r);
    }
    auditoria_registrar($empresaId, (int) $usuario['id'], $atual ? 'plantao.editado' : 'plantao.criado', 'plantao', $id, [
        'nome' => $nome, 'raio_metros' => $raio, 'latitude' => $lat, 'longitude' => $lng, 'ativo' => $dados['ativo'],
        'empreendimentos' => $empreendimentos, 'roletas' => $roletas, 'usuarios' => $usuarios,
    ]);
    return ['ok' => true, 'id' => $id, 'foto_anterior' => $atual['foto'] ?? null];
}
