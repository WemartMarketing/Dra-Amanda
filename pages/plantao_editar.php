<?php
/**
 * Cadastro/edição de plantão (gerenciar_plantoes): endereço completo, CEP,
 * coordenadas, raio, foto do stand, horário, empreendimentos, roletas e
 * corretores autorizados. Salva por api/plantao_salvar.php.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/plantoes_ui.php';

$usuario = auth_require();
$crumb = 'Plantões';
$activeNav = 'plantoes';
if (!plantao_pode_gerenciar($usuario)) {
    pagina_acesso_restrito($crumb, $activeNav, 'Gerenciar plantões');
}
plantao_exigir_modulo($crumb, $activeNav);
$empresaId = (int) $usuario['empresa_ativa_id'];
$id = (int) ($_GET['id'] ?? 0);
$p = $id ? plantao_carregar($usuario, $id) : null;
if ($id && !$p) {
    http_response_code(404);
    pagina_acesso_restrito($crumb, $activeNav, 'Plantão não encontrado');
}
$p = $p ?: ['id' => null, 'nome' => '', 'endereco' => '', 'cep' => '', 'complemento' => '', 'bairro' => '', 'cidade' => '', 'estado' => '', 'latitude' => '',
            'longitude' => '', 'raio_metros' => 300, 'foto' => null, 'horario_descricao' => '', 'observacoes' => '', 'ativo' => 1];
$q = function (string $sql, array $par = []) use ($empresaId) {
    $s = db()->prepare($sql);
    $s->execute($par + (str_contains($sql, ':e') ? ['e' => $empresaId] : []));
    return $s->fetchAll();
};
$empresaNome = (string) ($q('SELECT nome FROM empresas WHERE id = :e')[0]['nome'] ?? '');
$empreendimentos = $q('SELECT id, nome, ativo FROM empreendimentos WHERE empresa_id = :e ORDER BY ativo DESC, nome');
$roletas = $q('SELECT r.id, r.nome, r.ativa, r.plantao_id, pl.nome AS plantao_nome FROM roletas r LEFT JOIN plantoes pl ON pl.id = r.plantao_id WHERE r.empresa_id = :e ORDER BY r.nome');
$corretores = $q("SELECT DISTINCT u.id, u.nome FROM usuarios u JOIN funcao_permissoes fp ON fp.funcao_id = u.funcao_id AND fp.permissao_chave = 'participar_roleta'
                  WHERE u.empresa_id = :e AND u.ativo = 1 ORDER BY u.nome");
$selEmp = $p['id'] ? array_map('intval', array_column($q('SELECT empreendimento_id AS id FROM plantao_empreendimentos WHERE plantao_id = :p', ['p' => $p['id']]), 'id')) : [];
$selUsu = $p['id'] ? plantao_usuarios((int) $p['id']) : [];
$foto = plantao_foto_url($p['foto']);
$limiteFoto = upload_limite_bytes();
$chip = function (string $nome, int $valor, string $rotulo, bool $marcado, string $extra = '') {
    echo '<label class="dia-chip"><input type="checkbox" name="' . e($nome) . '[]" value="' . $valor . '"' . ($marcado ? ' checked' : '') . '><span>' . e($rotulo) . ($extra !== '' ? ' <small>' . e($extra) . '</small>' : '') . '</span></label>';
};

$pageTitle = ($p['id'] ? 'Editar plantão' : 'Novo plantão') . ' — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>
<a class="back-link" href="<?= $p['id'] ? '/pages/plantao.php?id=' . (int) $p['id'] : '/pages/plantoes.php' ?>">← Voltar</a>
<div class="header"><div><div class="eyebrow">Plantões</div><h1><?= $p['id'] ? 'Editar plantão' : 'Novo plantão' ?></h1><p>Endereço e localização usados nas rotas (Google Maps e Waze) e na presença das roletas.</p></div></div>

<form class="plantao-form" data-api-form action="/api/plantao_salvar.php" data-redirecionar="/pages/plantao.php?id={id}" enctype="multipart/form-data">
    <?php if ($p['id']): ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><?php endif; ?>
    <div class="grid-2">
        <section class="card">
            <div class="card-head"><div><h2>Identificação</h2></div></div>
            <div class="field"><label for="pl-nome">Nome do plantão *</label><input type="text" id="pl-nome" name="nome" required minlength="3" maxlength="190" value="<?= e($p['nome']) ?>" placeholder="Ex.: Casa HM — Zona Oeste"></div>
            <div class="field"><span class="label-like">Empresa responsável</span><p class="plantao-empresa"><?= e($empresaNome) ?></p></div>
            <div class="field"><label for="pl-foto">Foto do stand <small class="muted">(JPG, PNG ou WEBP)</small></label>
                <?php if ($foto): ?><img class="plantao-foto-atual" src="<?= e($foto) ?>" alt="Foto atual do stand"><label class="checkbox-row"><input type="checkbox" name="remover_foto" value="1"> Remover a foto atual</label><?php endif; ?>
                <input type="file" id="pl-foto" name="foto" accept="image/jpeg,image/png,image/webp" data-limite="<?= (int) $limiteFoto ?>">
            </div>
            <?php if ($p['id']): ?><label class="checkbox-row"><input type="checkbox" name="ativo" value="1" <?= $p['ativo'] ? 'checked' : '' ?>> Plantão ativo <small class="muted">(inativo some da tela dos corretores e tira todos da roleta)</small></label>
            <?php else: ?><input type="hidden" name="ativo" value="1"><?php endif; ?>
        </section>

        <section class="card">
            <div class="card-head"><div><h2>Endereço</h2></div></div>
            <div class="field"><label for="pl-cep">CEP</label><div class="plantao-cep"><input type="text" id="pl-cep" name="cep" inputmode="numeric" maxlength="9" value="<?= e($p['cep']) ?>" placeholder="00000-000"><button type="button" class="btn btn-outline" data-buscar-cep>Preencher pelo CEP</button></div><small class="field-hint" data-cep-status></small></div>
            <div class="field"><label for="pl-end">Endereço (rua e número)</label><input type="text" id="pl-end" name="endereco" maxlength="255" value="<?= e($p['endereco']) ?>" placeholder="Av. Francisco Matarazzo, 1500"></div>
            <div class="field"><label for="pl-comp">Complemento</label><input type="text" id="pl-comp" name="complemento" maxlength="120" value="<?= e($p['complemento']) ?>" placeholder="Ex.: Stand de vendas, portão 2"></div>
            <div class="form-grid form-grid-3">
                <div class="field"><label for="pl-bairro">Bairro</label><input type="text" id="pl-bairro" name="bairro" maxlength="120" value="<?= e($p['bairro']) ?>"></div>
                <div class="field"><label for="pl-cidade">Cidade</label><input type="text" id="pl-cidade" name="cidade" maxlength="120" value="<?= e($p['cidade']) ?>"></div>
                <div class="field"><label for="pl-estado">Estado</label>
                    <select id="pl-estado" name="estado"><option value="">—</option><?php foreach (estados_brasil() as $uf): ?><option value="<?= $uf ?>" <?= $p['estado'] === $uf ? 'selected' : '' ?>><?= $uf ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-grid form-grid-3">
                <div class="field"><label for="pl-lat">Latitude</label><input type="text" id="pl-lat" name="latitude" inputmode="decimal" value="<?= e((string) $p['latitude']) ?>" placeholder="-23.5262100"></div>
                <div class="field"><label for="pl-lng">Longitude</label><input type="text" id="pl-lng" name="longitude" inputmode="decimal" value="<?= e((string) $p['longitude']) ?>" placeholder="-46.6766900"></div>
                <div class="field"><label for="pl-raio">Raio da roleta (m)</label><input type="number" id="pl-raio" name="raio_metros" min="50" max="5000" step="10" value="<?= (int) $p['raio_metros'] ?>" required></div>
            </div>
            <div class="geo-row">
                <button type="button" class="btn btn-outline btn-sm" data-usar-localizacao><?= icon('target') ?>Usar minha localização atual</button>
                <small class="field-hint" data-geo-status>Use quando estiver no stand. Ou, no Google Maps, toque e segure no local e copie os números.</small>
            </div>
            <small class="field-hint">Com latitude e longitude, a rota leva exatamente ao stand e a roleta confere a presença. Sem elas, a rota busca pelo endereço.</small>
        </section>

        <section class="card">
            <div class="card-head"><div><h2>Funcionamento</h2></div></div>
            <div class="field"><span class="label-like">Roleta deste plantão</span>
                <div class="chips-escolha">
                    <?php foreach ($roletas as $r) $chip('roletas', (int) $r['id'], $r['nome'], $p['id'] && (int) $r['plantao_id'] === (int) $p['id'], $r['plantao_id'] && (int) $r['plantao_id'] !== (int) $p['id'] ? '(hoje em ' . $r['plantao_nome'] . ')' : ''); ?>
                    <?php if (!$roletas): ?><span class="muted">Nenhuma roleta cadastrada.</span><?php endif; ?>
                </div>
                <small class="field-hint">O horário do plantão (dias, pausas e feriados) é o da roleta — ajuste em <a href="/pages/roletas.php">Roletas</a> → Horários e feriados.</small></div>
            <div class="field"><label for="pl-hor">Horário de funcionamento <small class="muted">(só para plantão sem roleta)</small></label><input type="text" id="pl-hor" name="horario_descricao" maxlength="190" value="<?= e($p['horario_descricao']) ?>" placeholder="Ex.: Seg a sáb, 9h às 18h"></div>
            <div class="field"><label for="pl-obs">Orientações para quem vai até o plantão</label><textarea id="pl-obs" name="observacoes" maxlength="500" rows="3" placeholder="Ex.: Estacionamento pela rua lateral. Toque a campainha do stand."><?= e($p['observacoes']) ?></textarea></div>
        </section>

        <section class="card">
            <div class="card-head"><div><h2>Empreendimentos e corretores</h2></div></div>
            <div class="field"><span class="label-like">Empreendimentos atendidos</span>
                <div class="chips-escolha"><?php foreach ($empreendimentos as $em) $chip('empreendimentos', (int) $em['id'], $em['nome'], in_array((int) $em['id'], $selEmp, true), $em['ativo'] ? '' : '(inativo)'); ?></div></div>
            <div class="field"><span class="label-like">Corretores autorizados no plantão</span>
                <div class="chips-escolha"><?php foreach ($corretores as $c) $chip('usuarios', (int) $c['id'], $c['nome'], in_array((int) $c['id'], $selUsu, true)); ?>
                    <?php if (!$corretores): ?><span class="muted">Nenhum usuário com permissão de participar de roleta.</span><?php endif; ?></div>
                <small class="field-hint">Aparecem em “Meus plantões”. Para receber leads, o corretor também precisa estar na roleta (Roletas → corretores).</small></div>
        </section>
    </div>
    <div class="form-alert" data-form-alerta hidden></div>
    <div class="plantao-salvar"><button type="submit" class="btn btn-primary btn-lg"><?= icon('check') ?>Salvar plantão</button></div>
</form>
<?php require __DIR__ . '/../includes/layout_end.php'; ?>
