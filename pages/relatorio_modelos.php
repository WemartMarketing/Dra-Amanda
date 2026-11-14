<?php
/**
 * Modelos de relatório (Etapa 6): prontos e personalizados. Cada modelo
 * gera um PDF ou Excel com várias seções da Central, com os filtros
 * escolhidos na hora (por cima dos do modelo).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/estrutura.php';
require_once __DIR__ . '/../includes/relatorios/modelos.php';

$usuario = auth_require();
$crumb = 'Modelos de relatório';
$activeNav = 'relatorios';
if (!empty($usuario['eh_superadmin']) || !usuario_tem_permissao($usuario, 'visualizar_indicadores')) {
    pagina_acesso_restrito($crumb, $activeNav, 'Visualizar indicadores/dashboard');
}
$podeExportar = usuario_tem_permissao($usuario, 'exportar_relatorios');
$op = relui_opcoes($usuario);
$meus = relmod_meus($usuario);
$modelos = [];
foreach (relmod_prontos() as $k => [$nome, $desc, $secoes, $periodo]) {
    $modelos[] = ['chave' => 'p:' . $k, 'nome' => $nome, 'desc' => $desc, 'secoes' => $secoes, 'periodo' => $periodo, 'pronto' => true];
}
foreach ($meus as $m) {
    parse_str($m['filtros'], $fm);
    $modelos[] = ['chave' => 'u:' . $m['id'], 'id' => (int) $m['id'], 'nome' => $m['nome'], 'desc' => 'Modelo personalizado.', 'secoes' => array_map(fn ($s) => [$s, []], explode(',', $m['secoes'])),
                  'periodo' => $fm['periodo'] ?? '30d', 'filtros' => $fm, 'pronto' => false];
}
$rotSecao = function (array $s): string {
    $t = relui_relatorios()[$s[0]][0] ?? $s[0];
    return isset($s[1]['agrupar']) ? $t . ' (por ' . str_replace('_', ' ', $s[1]['agrupar']) . ')' : $t;
};
$selFiltro = function (string $nome, string $rot, string $todos, array $itens, $atual = '') use (&$i) {
    if (!$itens) {
        return;
    }
    echo '<div class="field"><label>' . e($rot) . '<select name="' . e($nome) . '"><option value="">' . e($todos) . '</option>';
    foreach ($itens as $it) {
        echo '<option value="' . e((string) $it['id']) . '"' . ((string) $atual === (string) $it['id'] ? ' selected' : '') . '>' . e($it['nome']) . '</option>';
    }
    echo '</select></label></div>';
};

$pageTitle = 'Modelos de relatório — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div>
        <div class="eyebrow">Relatórios</div>
        <h1>Modelos de relatório</h1>
        <p>Relatórios montados com várias seções da Central, em um único PDF ou Excel. Escolha o período e os filtros na hora; os números seguem a sua permissão.</p>
    </div>
    <div class="actions">
        <?php if (relmod_instalados()): ?><button type="button" class="btn btn-primary" data-open-modal="modal-modelo-novo"><?= icon('plus') ?>Novo modelo</button><?php endif; ?>
        <a class="btn btn-outline" href="/pages/relatorios_central.php"><?= icon('list') ?>Todos os relatórios</a>
    </div>
</div>
<?php if (!$podeExportar): ?>
    <div class="rel-nota" role="note"><?= icon('info') ?><span>Sua função não pode gerar arquivos (permissão "Exportar relatórios"). Você pode abrir cada seção na tela.</span></div>
<?php endif; ?>

<div class="rel-central">
    <?php foreach ($modelos as $i => $m): $mid = 'modal-modelo-' . $i; ?>
        <section class="card rel-cat rel-modelo">
            <div class="rel-cat-topo"><span class="kpi-icon"><?= icon($m['pronto'] ? 'file' : 'star') ?></span><h2><?= e($m['nome']) ?></h2>
                <?php if (!$m['pronto']): ?><span class="pill pill-muted">meu</span><?php endif; ?></div>
            <p class="muted"><?= e($m['desc']) ?></p>
            <ol class="rel-modelo-secoes">
                <?php foreach ($m['secoes'] as $s): ?>
                    <li><a href="<?= e(relui_url($s[0], array_merge(['periodo' => $m['periodo']], $m['filtros'] ?? [], $s[1]))) ?>"><?= e($rotSecao($s)) ?></a></li>
                <?php endforeach; ?>
            </ol>
            <div class="rel-acoes-linha rel-modelo-acoes">
                <?php if (!$m['pronto']): ?><button type="button" class="link-btn danger" data-api-acao="/api/relatorio_modelo.php" data-acao="excluir" data-id="<?= (int) $m['id'] ?>" data-confirmar="Excluir o modelo &quot;<?= e($m['nome']) ?>&quot;?">Excluir</button><?php endif; ?>
                <?php if ($podeExportar): ?><button type="button" class="btn btn-primary btn-sm" data-open-modal="<?= $mid ?>"><?= icon('download') ?>Gerar</button><?php endif; ?>
            </div>
        </section>
        <?php if ($podeExportar): ?>
            <div class="modal-overlay" id="<?= $mid ?>" hidden>
                <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="<?= $mid ?>-t">
                    <button type="button" class="overlay-close" data-close-modal="<?= $mid ?>" aria-label="Fechar">&times;</button>
                    <h2 id="<?= $mid ?>-t"><?= e($m['nome']) ?></h2>
                    <form action="/api/relatorio_modelo.php" method="post" data-exportar-form>
                        <?= csrf_field() ?>
                        <input type="hidden" name="acao" value="gerar"><input type="hidden" name="modelo" value="<?= e($m['chave']) ?>">
                        <div class="form-grid">
                            <div class="field"><label>Período<select name="periodo" data-modelo-periodo>
                                <?php foreach (relatorio_periodos() as $k => $r): ?><option value="<?= $k ?>" <?= $k === $m['periodo'] ? 'selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?>
                            </select></label></div>
                            <div class="field" data-modelo-datas hidden><label>De<input type="date" name="ini"></label></div>
                            <div class="field" data-modelo-datas hidden><label>Até<input type="date" name="fim"></label></div>
                            <?php
                            $f = $m['filtros'] ?? [];
                            $selFiltro('empreendimento', 'Empreendimento', 'Todos', $op['empreendimentos'], $f['empreendimento'] ?? '');
                            if (count($op['superintendencias']) > 1) {
                                $selFiltro('superintendencia', 'Superintendência', 'Todas', $op['superintendencias'], $f['superintendencia'] ?? '');
                            }
                            if (count($op['equipes']) > 1) {
                                $selFiltro('equipe', 'Equipe', 'Todas', $op['equipes'], $f['equipe'] ?? '');
                            }
                            ?>
                        </div>
                        <fieldset class="rel-exp-opcoes"><legend>Formato</legend>
                            <label class="radio-row"><input type="radio" name="formato" value="pdf" checked> PDF (relatório executivo, uma seção após a outra)</label>
                            <label class="radio-row"><input type="radio" name="formato" value="xlsx"> Excel (.xlsx), uma aba por seção</label>
                        </fieldset>
                        <div class="form-alert" data-form-alerta hidden></div>
                        <button type="submit" class="btn btn-primary"><?= icon('download') ?>Gerar arquivo</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>

<?php if (relmod_instalados()): ?>
<div class="modal-overlay" id="modal-modelo-novo" hidden>
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="modal-modelo-novo-t">
        <button type="button" class="overlay-close" data-close-modal="modal-modelo-novo" aria-label="Fechar">&times;</button>
        <h2 id="modal-modelo-novo-t">Novo modelo</h2>
        <form data-api-form action="/api/relatorio_modelo.php" data-recarregar>
            <input type="hidden" name="acao" value="salvar">
            <div class="field"><label for="mod-nome">Nome</label><input type="text" id="mod-nome" name="nome" required maxlength="80" placeholder="Ex.: Reunião de segunda"></div>
            <fieldset class="rel-exp-opcoes"><legend>Seções (na ordem da lista)</legend>
                <?php foreach (relmod_secoes_livres() as $k => [$r]): ?><label class="radio-row"><input type="checkbox" name="secoes[]" value="<?= $k ?>"> <?= e($r) ?></label><?php endforeach; ?>
            </fieldset>
            <div class="form-grid">
                <div class="field"><label>Período padrão<select name="periodo"><?php foreach (relatorio_periodos() as $k => $r): if ($k === 'personalizado') continue; ?><option value="<?= $k ?>" <?= $k === 'mes_anterior' ? 'selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select></label></div>
                <?php $selFiltro('empreendimento', 'Empreendimento', 'Todos', $op['empreendimentos']); ?>
            </div>
            <div class="form-alert" data-form-alerta hidden></div>
            <button type="submit" class="btn btn-primary"><?= icon('check') ?>Salvar modelo</button>
        </form>
    </div>
</div>
<?php endif; ?>
<script>
document.querySelectorAll('[data-modelo-periodo]').forEach(function (sel) {
    var form = sel.form;
    function upd() { form.querySelectorAll('[data-modelo-datas]').forEach(function (d) { d.hidden = sel.value !== 'personalizado'; d.querySelector('input').required = sel.value === 'personalizado'; }); }
    sel.addEventListener('change', upd); upd();
});
</script>
<?php require __DIR__ . '/../includes/layout_end.php'; ?>
