<?php
/**
 * Empreendimento — detalhe (todos com visualizar_leads) e cadastro/edição
 * (gerenciar_empreendimentos). Sem ?id= abre o cadastro de um novo.
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
if (!usuario_tem_permissao($usuario, 'visualizar_leads')) {
    pagina_acesso_restrito('Empreendimentos', 'empreendimentos', 'Visualizar leads');
}
$empresaId = (int) $usuario['empresa_ativa_id'];
$podeGerenciar = usuario_tem_permissao($usuario, 'gerenciar_empreendimentos');
$id = (int) ($_GET['id'] ?? 0);

if (!$id && !$podeGerenciar) {
    pagina_acesso_restrito('Empreendimentos', 'empreendimentos', 'Gerenciar empreendimentos');
}

$emp = null;
$plantoes = $roletas = $equipes = [];
if ($id) {
    $stmt = db()->prepare('SELECT * FROM empreendimentos WHERE id = :id AND empresa_id = :empresa_id' . ($podeGerenciar ? '' : ' AND ativo = 1'));
    $stmt->execute(['id' => $id, 'empresa_id' => $empresaId]);
    $emp = $stmt->fetch();
    if (!$emp) {
        http_response_code(404);
        $pageTitle = 'Não encontrado — ' . APP_NAME;
        $crumb = 'Empreendimentos';
        $activeNav = 'empreendimentos';
        require __DIR__ . '/../includes/layout_start.php';
        echo '<div class="card stub-card"><div class="stub-icon">' . icon('alert') . '</div><h2>Empreendimento não encontrado</h2><p><a href="/pages/empreendimentos.php">Voltar para a lista</a></p></div>';
        require __DIR__ . '/../includes/layout_end.php';
        exit;
    }

    $stmt = db()->prepare('SELECT p.id, p.nome, p.ativo, p.raio_metros FROM plantao_empreendimentos pe INNER JOIN plantoes p ON p.id = pe.plantao_id WHERE pe.empreendimento_id = :id AND p.empresa_id = :empresa_id ORDER BY p.nome');
    $stmt->execute(['id' => $id, 'empresa_id' => $empresaId]);
    $plantoes = $stmt->fetchAll();

    $stmt = db()->prepare('SELECT r.id, r.nome, r.ativa FROM roleta_empreendimentos re INNER JOIN roletas r ON r.id = re.roleta_id WHERE re.empreendimento_id = :id AND r.empresa_id = :empresa_id ORDER BY r.nome');
    $stmt->execute(['id' => $id, 'empresa_id' => $empresaId]);
    $roletas = $stmt->fetchAll();

    $stmt = db()->prepare('SELECT eq.id, eq.nome FROM equipe_empreendimentos ee INNER JOIN equipes eq ON eq.id = ee.equipe_id WHERE ee.empreendimento_id = :id AND eq.empresa_id = :empresa_id ORDER BY eq.nome');
    $stmt->execute(['id' => $id, 'empresa_id' => $empresaId]);
    $equipes = $stmt->fetchAll();
}

$rotulosStatus = empreendimento_status_rotulos();
$img = $emp ? empreendimento_imagem_url($emp['imagem']) : null;
$limiteMb = round(upload_limite_bytes() / 1024 / 1024, 1);
$local = $emp ? implode(' · ', array_filter([$emp['endereco'], $emp['bairro'], $emp['cidade'] ? $emp['cidade'] . ($emp['estado'] ? '/' . $emp['estado'] : '') : null])) : '';

$pageTitle = ($emp ? $emp['nome'] : 'Novo empreendimento') . ' — ' . APP_NAME;
$crumb = $emp ? $emp['nome'] : 'Novo empreendimento';
$activeNav = 'empreendimentos';
require __DIR__ . '/../includes/layout_start.php';
?>
<a href="/pages/empreendimentos.php" class="back-link"><?= icon('arrow-r') ?>Voltar para empreendimentos</a>

<div class="header">
    <div>
        <div class="eyebrow"><?= $emp ? 'Empreendimento' : 'Cadastro' ?></div>
        <h1><?= $emp ? htmlspecialchars($emp['nome']) : 'Novo empreendimento' ?></h1>
        <?php if ($emp): ?><p><?= htmlspecialchars($emp['construtora'] ?: 'Construtora não informada') ?><?= $local ? ' · ' . htmlspecialchars($local) : '' ?></p><?php endif; ?>
    </div>
    <?php if ($emp): ?>
        <span class="pill <?= $emp['ativo'] ? empreendimento_status_pill($emp['status_comercial']) : 'pill-muted' ?>"><?= $emp['ativo'] ? htmlspecialchars($rotulosStatus[$emp['status_comercial']]) : 'Inativo' ?></span>
    <?php endif; ?>
</div>

<div class="emp-layout">
    <div>
        <?php if ($podeGerenciar): ?>
            <form class="card" data-form-empreendimento enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $emp['id'] ?? '' ?>">
                <div class="card-head"><div><h2>Dados do empreendimento</h2><p>Campos com * são obrigatórios.</p></div></div>

                <div class="field"><label for="emp-nome">Nome *</label><input type="text" id="emp-nome" name="nome" required maxlength="190" value="<?= htmlspecialchars($emp['nome'] ?? '') ?>" placeholder="Ex.: Smart Marquês"></div>
                <div class="form-grid">
                    <div class="field"><label for="emp-construtora">Construtora</label><input type="text" id="emp-construtora" name="construtora" maxlength="190" value="<?= htmlspecialchars($emp['construtora'] ?? '') ?>"></div>
                    <div class="field"><label for="emp-status">Status comercial</label>
                        <select id="emp-status" name="status_comercial">
                            <?php foreach ($rotulosStatus as $valor => $rotulo): ?><option value="<?= $valor ?>" <?= ($emp['status_comercial'] ?? 'lancamento') === $valor ? 'selected' : '' ?>><?= htmlspecialchars($rotulo) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="field"><label for="emp-endereco">Endereço</label><input type="text" id="emp-endereco" name="endereco" maxlength="255" value="<?= htmlspecialchars($emp['endereco'] ?? '') ?>" placeholder="Rua, número"></div>
                <div class="form-grid form-grid-3">
                    <div class="field"><label for="emp-bairro">Bairro</label><input type="text" id="emp-bairro" name="bairro" maxlength="120" value="<?= htmlspecialchars($emp['bairro'] ?? '') ?>"></div>
                    <div class="field"><label for="emp-cidade">Cidade</label><input type="text" id="emp-cidade" name="cidade" maxlength="120" value="<?= htmlspecialchars($emp['cidade'] ?? '') ?>"></div>
                    <div class="field"><label for="emp-estado">Estado</label>
                        <select id="emp-estado" name="estado">
                            <option value="">—</option>
                            <?php foreach (estados_brasil() as $uf): ?><option value="<?= $uf ?>" <?= ($emp['estado'] ?? '') === $uf ? 'selected' : '' ?>><?= $uf ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="field"><label for="emp-descricao">Descrição</label><textarea id="emp-descricao" name="descricao" rows="4" maxlength="5000" placeholder="Tipologias, diferenciais, faixa de preço..."><?= htmlspecialchars($emp['descricao'] ?? '') ?></textarea></div>

                <div class="field">
                    <label for="emp-imagem">Imagem</label>
                    <div class="upload-box">
                        <div class="upload-preview">
                            <?php if ($img): ?><img src="<?= htmlspecialchars($img) ?>" alt="Imagem atual" data-preview-img><?php else: ?><div class="emp-placeholder" data-preview-vazio><?= icon('image') ?></div><img alt="" data-preview-img hidden><?php endif; ?>
                        </div>
                        <div>
                            <label class="btn btn-outline btn-sm file-btn" for="emp-imagem"><?= icon('image') ?><?= $img ? 'Trocar imagem' : 'Escolher imagem' ?></label>
                            <input type="file" id="emp-imagem" name="imagem" class="sr-only" accept="image/jpeg,image/png,image/webp" data-imagem-input data-limite="<?= upload_limite_bytes() ?>">
                            <span class="file-name" data-file-name>Nenhuma imagem nova selecionada</span>
                            <small class="field-hint">JPG, PNG ou WEBP, até <?= $limiteMb ?> MB.</small>
                            <?php if ($img): ?><label class="checkbox-row"><input type="checkbox" name="remover_imagem" value="1"> Remover imagem atual</label><?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if ($emp): ?>
                    <label class="checkbox-row"><input type="checkbox" name="ativo" value="1" <?= $emp['ativo'] ? 'checked' : '' ?>> Empreendimento ativo (desmarque para tirá-lo das listas — os leads continuam guardados)</label>
                <?php endif; ?>
                <div class="modal-actions"><button type="submit" class="btn btn-primary"><?= icon('check') ?><?= $emp ? 'Salvar alterações' : 'Cadastrar empreendimento' ?></button></div>
            </form>
        <?php else: ?>
            <div class="card">
                <?php if ($img): ?><img class="emp-hero" src="<?= htmlspecialchars($img) ?>" alt="Imagem de <?= htmlspecialchars($emp['nome']) ?>"><?php endif; ?>
                <div class="info-block"><label>Endereço</label><div><?= $local ? htmlspecialchars($local) : '—' ?></div></div>
                <div class="info-block"><label>Construtora</label><div><?= htmlspecialchars($emp['construtora'] ?: '—') ?></div></div>
                <div class="info-block"><label>Sobre</label><div style="white-space:pre-line;"><?= htmlspecialchars($emp['descricao'] ?: '—') ?></div></div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($emp): ?>
        <aside class="emp-links">
            <div class="card">
                <div class="card-head"><div><h2>Plantões</h2><p>Onde o empreendimento é atendido.</p></div></div>
                <?php foreach ($plantoes as $p): ?>
                    <div class="link-row"><?= icon('map') ?><span><?= htmlspecialchars($p['nome']) ?><?= $p['ativo'] ? '' : ' (inativo)' ?></span><small><?= (int) $p['raio_metros'] ?> m</small></div>
                <?php endforeach; ?>
                <?php if (!$plantoes): ?><p class="empty-note">Nenhum plantão vinculado.</p><?php endif; ?>
                <?php if (usuario_tem_permissao($usuario, 'gerenciar_plantoes')): ?><a class="link-btn" href="/pages/plantoes.php">Vincular em Plantões</a><?php endif; ?>
            </div>
            <div class="card">
                <div class="card-head"><div><h2>Roletas</h2><p>Filas que distribuem os leads.</p></div></div>
                <?php foreach ($roletas as $r): ?>
                    <div class="link-row"><?= icon('pin') ?><span><?= htmlspecialchars($r['nome']) ?></span><span class="pill <?= $r['ativa'] ? 'pill-green' : 'pill-muted' ?>"><?= $r['ativa'] ? 'Ativa' : 'Pausada' ?></span></div>
                <?php endforeach; ?>
                <?php if (!$roletas): ?><p class="empty-note">Nenhuma roleta distribui este empreendimento.</p><?php endif; ?>
            </div>
            <div class="card">
                <div class="card-head"><div><h2>Equipes</h2><p>Quem atende este produto.</p></div></div>
                <?php foreach ($equipes as $eq): ?>
                    <div class="link-row"><?= icon('users') ?><span><?= htmlspecialchars($eq['nome']) ?></span></div>
                <?php endforeach; ?>
                <?php if (!$equipes): ?><p class="empty-note">Nenhuma equipe vinculada.</p><?php endif; ?>
            </div>
        </aside>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
