<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/estrutura.php';
require_once __DIR__ . '/../includes/lead_componentes.php';

$usuario = auth_require();
$crumb = 'Etapas do funil';
$activeNav = 'funil';
if (!usuario_tem_permissao($usuario, 'configurar_funil')) {
    pagina_acesso_restrito($crumb, $activeNav, 'Configurar funil');
}
$empresaId = (int) $usuario['empresa_ativa_id'];
$etapas = funil_etapas($empresaId, 'todas');
$stmt = db()->prepare('SELECT etapa_id, COUNT(*) AS qtd FROM leads WHERE empresa_id = :e AND excluido_em IS NULL GROUP BY etapa_id');
$stmt->execute(['e' => $empresaId]);
$qtdPorEtapa = array_map('intval', array_column($stmt->fetchAll(), 'qtd', 'etapa_id'));
$categorias = funil_categorias();
$ativas = array_values(array_filter($etapas, fn ($e) => (int) $e['ativa']));

$pageTitle = 'Etapas do funil — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>

<div class="header">
    <div>
        <div class="eyebrow">Configurações</div>
        <h1>Etapas do funil</h1>
        <p>Renomeie, reordene e escolha a cor de cada etapa. A <b>categoria</b> diz aos relatórios o que a etapa significa — por isso não muda depois que a etapa tem leads.</p>
    </div>
    <div class="actions">
        <button type="button" class="btn btn-primary" data-open-modal="modal-nova-etapa"><?= icon('plus') ?>Nova etapa</button>
    </div>
</div>

<div class="card">
    <div class="etapa-lista">
        <?php foreach ($etapas as $i => $etapa): $id = (int) $etapa['id']; $qtd = $qtdPorEtapa[$id] ?? 0; ?>
            <div class="etapa-row<?= (int) $etapa['ativa'] ? '' : ' is-inactive' ?>">
                <div class="etapa-ordem">
                    <button type="button" class="icon-btn" aria-label="Subir <?= e($etapa['nome']) ?>" data-api-acao="/api/funil_salvar.php" data-acao="mover" data-direcao="cima" data-id="<?= $id ?>" <?= $i === 0 ? 'disabled' : '' ?>><?= icon('up') ?></button>
                    <button type="button" class="icon-btn" aria-label="Descer <?= e($etapa['nome']) ?>" data-api-acao="/api/funil_salvar.php" data-acao="mover" data-direcao="baixo" data-id="<?= $id ?>" <?= $i === count($etapas) - 1 ? 'disabled' : '' ?>><?= icon('down') ?></button>
                </div>
                <span class="etapa-dot etapa-dot-lg" style="background: <?= e($etapa['cor']) ?>"></span>
                <div class="etapa-info">
                    <strong><?= e($etapa['nome']) ?></strong>
                    <span>Categoria: <?= e($categorias[$etapa['categoria']] ?? $etapa['categoria']) ?> · <?= $qtd ?> lead<?= $qtd === 1 ? '' : 's' ?></span>
                </div>
                <div class="etapa-flags">
                    <?php if (!(int) $etapa['ativa']): ?><span class="pill pill-muted">Inativa</span>
                    <?php elseif (!(int) $etapa['exibir_kanban']): ?><span class="pill pill-muted">Fora do Kanban</span>
                    <?php else: ?><span class="pill pill-green">No Kanban</span><?php endif; ?>
                </div>
                <div class="etapa-acoes">
                    <button type="button" class="btn btn-outline btn-sm" data-open-modal="modal-etapa-<?= $id ?>"><?= icon('edit') ?>Editar</button>
                    <?php if ((int) $etapa['ativa']): ?>
                        <?php if ($qtd > 0): ?>
                            <button type="button" class="btn btn-outline btn-sm" data-open-modal="modal-desativar-<?= $id ?>">Desativar</button>
                        <?php else: ?>
                            <button type="button" class="btn btn-outline btn-sm" data-api-acao="/api/funil_salvar.php" data-acao="desativar" data-id="<?= $id ?>" data-confirmar="Desativar a etapa <?= e($etapa['nome']) ?>?">Desativar</button>
                        <?php endif; ?>
                    <?php else: ?>
                        <button type="button" class="btn btn-outline btn-sm" data-api-acao="/api/funil_salvar.php" data-acao="ativar" data-id="<?= $id ?>">Ativar</button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="modal-overlay" id="modal-etapa-<?= $id ?>" hidden>
                <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="titulo-etapa-<?= $id ?>">
                    <button type="button" class="overlay-close" data-close-modal="modal-etapa-<?= $id ?>" aria-label="Fechar">&times;</button>
                    <h2 id="titulo-etapa-<?= $id ?>">Editar etapa</h2>
                    <form data-api-form action="/api/funil_salvar.php" data-recarregar>
                        <input type="hidden" name="acao" value="salvar">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <div class="field"><label for="nome-<?= $id ?>">Nome</label><input type="text" id="nome-<?= $id ?>" name="nome" required maxlength="80" value="<?= e($etapa['nome']) ?>"></div>
                        <div class="form-grid">
                            <div class="field"><label for="cor-<?= $id ?>">Cor</label><input type="color" id="cor-<?= $id ?>" name="cor" value="<?= e($etapa['cor']) ?>"></div>
                            <div class="field"><label for="cat-<?= $id ?>">Categoria</label>
                                <select id="cat-<?= $id ?>" name="categoria" <?= $qtd > 0 ? 'disabled' : '' ?>>
                                    <?php foreach ($categorias as $v => $r): ?><option value="<?= $v ?>" <?= $etapa['categoria'] === $v ? 'selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?>
                                </select>
                                <?php if ($qtd > 0): ?><input type="hidden" name="categoria" value="<?= e($etapa['categoria']) ?>"><small class="muted">Tem leads — a categoria fica fixa.</small><?php endif; ?>
                            </div>
                        </div>
                        <label class="checkbox-row"><input type="checkbox" name="exibir_kanban" value="1" <?= (int) $etapa['exibir_kanban'] ? 'checked' : '' ?>> Mostrar como coluna no Kanban</label>
                        <div class="form-alert" data-form-alerta hidden></div>
                        <button type="submit" class="btn btn-primary"><?= icon('check') ?>Salvar</button>
                    </form>
                </div>
            </div>

            <?php if ((int) $etapa['ativa'] && $qtd > 0): ?>
                <div class="modal-overlay" id="modal-desativar-<?= $id ?>" hidden>
                    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="titulo-desativar-<?= $id ?>">
                        <button type="button" class="overlay-close" data-close-modal="modal-desativar-<?= $id ?>" aria-label="Fechar">&times;</button>
                        <h2 id="titulo-desativar-<?= $id ?>">Desativar "<?= e($etapa['nome']) ?>"</h2>
                        <p class="muted">Esta etapa tem <b><?= $qtd ?> lead<?= $qtd === 1 ? '' : 's' ?></b>. Escolha para onde eles vão — cada mudança fica no histórico do lead.</p>
                        <form data-api-form action="/api/funil_salvar.php" data-recarregar>
                            <input type="hidden" name="acao" value="desativar">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <div class="field"><label for="destino-<?= $id ?>">Mover os leads para</label>
                                <select id="destino-<?= $id ?>" name="destino_id" required>
                                    <option value="">Escolha a etapa…</option>
                                    <?php foreach ($ativas as $d): if ((int) $d['id'] === $id) continue; ?><option value="<?= (int) $d['id'] ?>"><?= e($d['nome']) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-alert" data-form-alerta hidden></div>
                            <button type="submit" class="btn btn-primary">Mover leads e desativar</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>

<div class="modal-overlay" id="modal-nova-etapa" hidden>
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="titulo-nova-etapa">
        <button type="button" class="overlay-close" data-close-modal="modal-nova-etapa" aria-label="Fechar">&times;</button>
        <h2 id="titulo-nova-etapa">Nova etapa</h2>
        <form data-api-form action="/api/funil_salvar.php" data-recarregar>
            <input type="hidden" name="acao" value="salvar">
            <div class="field"><label for="nova-nome">Nome</label><input type="text" id="nova-nome" name="nome" required maxlength="80" placeholder="Ex.: Análise de crédito"></div>
            <div class="form-grid">
                <div class="field"><label for="nova-cor">Cor</label><input type="color" id="nova-cor" name="cor" value="#635BFA"></div>
                <div class="field"><label for="nova-cat">Categoria (para os relatórios)</label>
                    <select id="nova-cat" name="categoria" required>
                        <option value="">Escolha…</option>
                        <?php foreach ($categorias as $v => $r): ?><option value="<?= $v ?>"><?= e($r) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <label class="checkbox-row"><input type="checkbox" name="exibir_kanban" value="1" checked> Mostrar como coluna no Kanban</label>
            <div class="form-alert" data-form-alerta hidden></div>
            <button type="submit" class="btn btn-primary"><?= icon('plus') ?>Criar etapa</button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
