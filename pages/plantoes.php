<?php
/**
 * Plantões de venda — endereço, coordenadas e raio de presença (base da
 * geolocalização das roletas, Etapa 4). Permissão: gerenciar_plantoes.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/estrutura.php';

$usuario = auth_require();
if (!empty($usuario['eh_superadmin'])) {
    header('Location: /pages/superadmin.php');
    exit;
}
if (!usuario_tem_permissao($usuario, 'gerenciar_plantoes')) {
    pagina_acesso_restrito('Plantões', 'plantoes', 'Gerenciar plantões');
}
$empresaId = (int) $usuario['empresa_ativa_id'];

$stmt = db()->prepare('
    SELECT p.*,
        (SELECT COUNT(*) FROM plantao_empreendimentos pe WHERE pe.plantao_id = p.id) AS total_empreendimentos,
        (SELECT COUNT(*) FROM roletas r WHERE r.plantao_id = p.id) AS total_roletas
    FROM plantoes p WHERE p.empresa_id = :empresa_id ORDER BY p.ativo DESC, p.nome
');
$stmt->execute(['empresa_id' => $empresaId]);
$plantoes = $stmt->fetchAll();

$selecionadoId = (int) ($_GET['id'] ?? 0);
if (!in_array($selecionadoId, array_map('intval', array_column($plantoes, 'id')), true)) {
    $selecionadoId = (int) ($plantoes[0]['id'] ?? 0);
}
$plantao = null;
foreach ($plantoes as $p) {
    if ((int) $p['id'] === $selecionadoId) {
        $plantao = $p;
    }
}

$empreendimentosVinculados = $roletasDoPlantao = [];
if ($plantao) {
    $stmt = db()->prepare('SELECT e.id, e.nome, e.ativo FROM plantao_empreendimentos pe INNER JOIN empreendimentos e ON e.id = pe.empreendimento_id WHERE pe.plantao_id = :id AND e.empresa_id = :empresa_id ORDER BY e.nome');
    $stmt->execute(['id' => $plantao['id'], 'empresa_id' => $empresaId]);
    $empreendimentosVinculados = $stmt->fetchAll();

    $stmt = db()->prepare('SELECT id, nome, ativa FROM roletas WHERE plantao_id = :id AND empresa_id = :empresa_id ORDER BY nome');
    $stmt->execute(['id' => $plantao['id'], 'empresa_id' => $empresaId]);
    $roletasDoPlantao = $stmt->fetchAll();
}

$stmt = db()->prepare('SELECT id, nome, ativo FROM empreendimentos WHERE empresa_id = :empresa_id ORDER BY ativo DESC, nome');
$stmt->execute(['empresa_id' => $empresaId]);
$empreendimentosOpt = $stmt->fetchAll();

function enderecoPlantao(array $p): string
{
    return implode(' · ', array_filter([$p['endereco'], $p['bairro'], $p['cidade'] ? $p['cidade'] . ($p['estado'] ? '/' . $p['estado'] : '') : null]));
}

$pageTitle = 'Plantões — ' . APP_NAME;
$crumb = 'Plantões';
$activeNav = 'plantoes';
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div><div class="eyebrow">Operação de vendas</div><h1>Plantões de venda</h1><p>Endereço, localização e raio onde o corretor pode entrar na roleta.</p></div>
    <div class="actions"><button type="button" class="btn btn-primary" data-open-modal="modal-plantao" data-modo="criar"><?= icon('plus') ?>Novo plantão</button></div>
</div>

<div class="roleta-layout">
    <div class="card">
        <div class="card-head">
            <div><h2>Plantões cadastrados</h2><p>Selecione um plantão para ver os detalhes.</p></div>
            <span class="pill pill-muted"><?= count($plantoes) ?> plantão(ões)</span>
        </div>
        <?php foreach ($plantoes as $p): ?>
            <a href="/pages/plantoes.php?id=<?= $p['id'] ?>" class="roleta-row <?= (int) $p['id'] === $selecionadoId ? 'is-active' : '' ?>">
                <div class="plantao-icon"><?= icon('map') ?></div>
                <div class="who-info">
                    <strong><?= htmlspecialchars($p['nome']) ?></strong>
                    <span><?= (int) $p['total_empreendimentos'] ?> empreendimento(s) · <?= (int) $p['total_roletas'] ?> roleta(s) · raio <?= (int) $p['raio_metros'] ?> m</span>
                </div>
                <span class="pill <?= $p['ativo'] ? 'pill-green' : 'pill-muted' ?>"><?= $p['ativo'] ? 'Ativo' : 'Inativo' ?></span>
            </a>
        <?php endforeach; ?>
        <?php if (!$plantoes): ?><p class="empty-note">Nenhum plantão cadastrado ainda.</p><?php endif; ?>
    </div>

    <?php if ($plantao):
        $temCoord = $plantao['latitude'] !== null && $plantao['longitude'] !== null;
        $mapaUrl = $temCoord ? 'https://www.openstreetmap.org/?mlat=' . rawurlencode($plantao['latitude']) . '&mlon=' . rawurlencode($plantao['longitude']) . '#map=17/' . rawurlencode($plantao['latitude']) . '/' . rawurlencode($plantao['longitude']) : null;
    ?>
        <div class="card">
            <div class="card-head">
                <div><h2><?= htmlspecialchars($plantao['nome']) ?></h2><p><?= htmlspecialchars(enderecoPlantao($plantao) ?: 'Endereço não informado') ?></p></div>
                <button type="button" class="btn btn-outline btn-sm" data-open-modal="modal-plantao" data-modo="editar"
                    data-id="<?= $plantao['id'] ?>" data-nome="<?= htmlspecialchars($plantao['nome']) ?>"
                    data-endereco="<?= htmlspecialchars((string) $plantao['endereco']) ?>" data-bairro="<?= htmlspecialchars((string) $plantao['bairro']) ?>"
                    data-cidade="<?= htmlspecialchars((string) $plantao['cidade']) ?>" data-estado="<?= htmlspecialchars((string) $plantao['estado']) ?>"
                    data-latitude="<?= htmlspecialchars((string) $plantao['latitude']) ?>" data-longitude="<?= htmlspecialchars((string) $plantao['longitude']) ?>"
                    data-raio="<?= (int) $plantao['raio_metros'] ?>" data-ativo="<?= (int) $plantao['ativo'] ?>"
                    data-empreendimentos="<?= implode(',', array_column($empreendimentosVinculados, 'id')) ?>">Editar plantão</button>
            </div>

            <div class="radius-vis" aria-hidden="true">
                <div class="radius-circle"></div>
                <span class="radius-label">Raio: <?= (int) $plantao['raio_metros'] ?> m</span>
            </div>

            <div class="roleta-detail-row"><span class="label">Coordenadas</span><strong><?= $temCoord ? htmlspecialchars(rtrim(rtrim($plantao['latitude'], '0'), '.') . ', ' . rtrim(rtrim($plantao['longitude'], '0'), '.')) : 'Não informadas' ?></strong></div>
            <div class="roleta-detail-row"><span class="label">Geolocalização</span><span class="pill <?= $temCoord ? 'pill-green' : 'pill-amber' ?>"><?= $temCoord ? 'Pronta para uso' : 'Falta informar a localização' ?></span></div>
            <div class="roleta-detail-row"><span class="label">Raio de liberação</span><strong><?= (int) $plantao['raio_metros'] ?> metros</strong></div>
            <?php if ($mapaUrl): ?><div class="roleta-detail-row"><span class="label">Mapa</span><a class="link-btn" href="<?= htmlspecialchars($mapaUrl) ?>" target="_blank" rel="noopener">Abrir no mapa</a></div><?php endif; ?>
            <div class="roleta-detail-row"><span class="label">Empreendimentos</span>
                <span class="chip-row chip-row-right">
                    <?php foreach ($empreendimentosVinculados as $e): ?><span class="pill pill-muted"><?= htmlspecialchars($e['nome']) ?></span><?php endforeach; ?>
                    <?php if (!$empreendimentosVinculados): ?>—<?php endif; ?>
                </span>
            </div>
            <div class="roleta-detail-row"><span class="label">Roletas deste plantão</span>
                <span class="chip-row chip-row-right">
                    <?php foreach ($roletasDoPlantao as $r): ?><a class="pill <?= $r['ativa'] ? 'pill-green' : 'pill-muted' ?>" href="/pages/roletas.php?id=<?= $r['id'] ?>"><?= htmlspecialchars($r['nome']) ?></a><?php endforeach; ?>
                    <?php if (!$roletasDoPlantao): ?>—<?php endif; ?>
                </span>
            </div>
            <div class="rule-box"><?= icon('alert') ?> O corretor só poderá entrar na roleta estando dentro do raio definido (a verificação de presença chega na etapa de Roletas).</div>
        </div>
    <?php else: ?>
        <div class="card stub-card"><div class="stub-icon"><?= icon('map') ?></div><h2>Nenhum plantão cadastrado</h2><p>Crie o primeiro plantão com o endereço e a localização do ponto de venda.</p></div>
    <?php endif; ?>
</div>

<div class="modal-overlay" id="modal-plantao" hidden>
    <div class="modal-box modal-wide">
        <button type="button" class="overlay-close" data-close-modal="modal-plantao" aria-label="Fechar">&times;</button>
        <h2 data-modal-titulo data-titulo-novo="Novo plantão" data-titulo-editar="Editar plantão">Novo plantão</h2>
        <form data-form-plantao>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="">
            <div class="field"><label for="pl-nome">Nome do plantão *</label><input type="text" id="pl-nome" name="nome" required maxlength="190" placeholder="Ex.: Casa HM — Zona Oeste"></div>
            <div class="field"><label for="pl-endereco">Endereço</label><input type="text" id="pl-endereco" name="endereco" maxlength="255" placeholder="Rua, número"></div>
            <div class="form-grid form-grid-3">
                <div class="field"><label for="pl-bairro">Bairro</label><input type="text" id="pl-bairro" name="bairro" maxlength="120"></div>
                <div class="field"><label for="pl-cidade">Cidade</label><input type="text" id="pl-cidade" name="cidade" maxlength="120"></div>
                <div class="field"><label for="pl-estado">Estado</label>
                    <select id="pl-estado" name="estado"><option value="">—</option><?php foreach (estados_brasil() as $uf): ?><option value="<?= $uf ?>"><?= $uf ?></option><?php endforeach; ?></select>
                </div>
            </div>
            <div class="form-grid form-grid-3">
                <div class="field"><label for="pl-lat">Latitude</label><input type="text" id="pl-lat" name="latitude" inputmode="decimal" placeholder="-23.5505"></div>
                <div class="field"><label for="pl-lng">Longitude</label><input type="text" id="pl-lng" name="longitude" inputmode="decimal" placeholder="-46.6333"></div>
                <div class="field"><label for="pl-raio">Raio (metros)</label><input type="number" id="pl-raio" name="raio_metros" min="50" max="5000" step="10" value="300" required></div>
            </div>
            <div class="geo-row">
                <button type="button" class="btn btn-outline btn-sm" data-usar-localizacao><?= icon('target') ?>Usar minha localização atual</button>
                <small class="field-hint" data-geo-status>Use quando estiver fisicamente no plantão. Também é possível copiar as coordenadas de um mapa.</small>
            </div>
            <div class="field">
                <label>Empreendimentos atendidos neste plantão</label>
                <div class="checkbox-grid">
                    <?php foreach ($empreendimentosOpt as $e): ?>
                        <label class="checkbox-row"><input type="checkbox" name="empreendimentos[]" value="<?= $e['id'] ?>"> <?= htmlspecialchars($e['nome']) ?><?= $e['ativo'] ? '' : ' (inativo)' ?></label>
                    <?php endforeach; ?>
                    <?php if (!$empreendimentosOpt): ?><span class="muted">Cadastre empreendimentos primeiro.</span><?php endif; ?>
                </div>
            </div>
            <label class="checkbox-row" data-campo-ativo hidden><input type="checkbox" name="ativo" value="1" checked> Plantão ativo</label>
            <div class="modal-actions"><button type="submit" class="btn btn-primary">Salvar plantão</button></div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
