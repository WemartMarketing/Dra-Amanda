<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/estrutura.php';
require_once __DIR__ . '/../includes/lead_componentes.php';
require_once __DIR__ . '/../includes/importacao.php';

$usuario = auth_require();
$crumb = 'Importar planilha';
$activeNav = 'oferta';
if (!usuario_tem_permissao($usuario, 'gerenciar_oferta_ativa')) {
    pagina_acesso_restrito($crumb, $activeNav, 'Importar listas e gerenciar oferta ativa');
}
$empresaId = (int) $usuario['empresa_ativa_id'];
$stmt = db()->prepare('SELECT id, nome FROM empreendimentos WHERE empresa_id = :e AND ativo = 1 ORDER BY nome');
$stmt->execute(['e' => $empresaId]);
$empreendimentos = $stmt->fetchAll();

$passo = 1;
$token = (string) ($_GET['t'] ?? '');
$listaId = (int) ($_GET['lista'] ?? 0);
$erroPasso = null;
if ($token !== '') {
    $sessao = $_SESSION['importacoes'][$token] ?? null;
    if (!preg_match('/^[a-f0-9]{32}$/', $token) || !$sessao || (int) $sessao['empresa_id'] !== $empresaId || (int) $sessao['usuario_id'] !== (int) $usuario['id']) {
        $erroPasso = 'Importação não encontrada ou expirada. Envie a planilha de novo.';
    } else {
        $leitura = importacao_ler(importacao_caminho($token, $sessao['ext']), $sessao['ext']);
        if (!$leitura['ok']) {
            $erroPasso = $leitura['erro'];
        } else {
            $passo = 2;
            $cabecalho = $leitura['linhas'][0];
            $previa = array_slice($leitura['linhas'], 1, 10);
            $totalLinhas = count($leitura['linhas']) - 1;
            $sugestao = importacao_sugerir($cabecalho);
            $nomeSugerido = preg_replace('/\.(csv|xlsx)$/i', '', $sessao['nome']);
        }
    }
} elseif ($listaId) {
    $lista = oferta_lista_carregar($usuario, $listaId);
    if ($lista) {
        $passo = 3;
        $relSessao = $_SESSION['importacao_relatorios'][$listaId] ?? null;
        $temRejeitados = $relSessao && $relSessao['rejeitados_token'] && is_file((string) importacao_caminho($relSessao['rejeitados_token'], 'csv'));
    } else {
        $erroPasso = 'Lista não encontrada.';
    }
}

$pageTitle = 'Importar planilha — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>

<div class="header">
    <div>
        <a class="back-link" href="/pages/oferta.php"><?= icon('arrow-r') ?>Voltar para a oferta ativa</a>
        <div class="eyebrow">Oferta ativa</div>
        <h1>Importar planilha</h1>
        <p>Transforme uma lista de contatos em leads para a equipe trabalhar.</p>
    </div>
</div>

<ol class="passos" aria-label="Etapas da importação">
    <li class="<?= $passo === 1 ? 'is-atual' : 'is-feito' ?>"><span>1</span>Enviar arquivo</li>
    <li class="<?= $passo === 2 ? 'is-atual' : ($passo > 2 ? 'is-feito' : '') ?>"><span>2</span>Conferir colunas</li>
    <li class="<?= $passo === 3 ? 'is-atual' : '' ?>"><span>3</span>Resultado</li>
</ol>

<?php if ($erroPasso): ?>
    <div class="alert-strip" role="alert"><?= icon('alert') ?><span><?= e($erroPasso) ?></span><a class="link-btn" href="/pages/importar.php">Recomeçar</a></div>
<?php endif; ?>

<?php if ($passo === 1): ?>
    <div class="grid-2">
        <div class="card form-card">
            <div class="card-head"><div><h2>1. Escolha a planilha</h2><p>CSV ou XLSX, até 5 MB e 5.000 linhas. A primeira linha deve ter os nomes das colunas.</p></div></div>
            <form data-api-form action="/api/importacao_enviar.php" data-redirecionar="/pages/importar.php?t={id}" enctype="multipart/form-data">
                <div class="upload-box">
                    <label class="file-btn btn btn-outline" for="arquivo"><?= icon('upload') ?>Escolher arquivo</label>
                    <input type="file" id="arquivo" name="arquivo" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required data-limite="<?= IMPORTACAO_MAX_BYTES ?>" class="sr-only" onchange="this.form.querySelector('.file-name').textContent = this.files[0] ? this.files[0].name : 'Nenhum arquivo escolhido'">
                    <span class="file-name">Nenhum arquivo escolhido</span>
                </div>
                <div class="form-alert" data-form-alerta hidden></div>
                <button type="submit" class="btn btn-primary btn-lg"><?= icon('arrow-r') ?>Enviar e conferir</button>
            </form>
        </div>
        <div class="card">
            <div class="card-head"><div><h2>Antes de importar</h2></div></div>
            <ul class="regra-lista">
                <li class="is-ok"><?= icon('check') ?>Colunas aceitas: nome, telefone (obrigatórios), e-mail, interesse e observação.</li>
                <li class="is-ok"><?= icon('check') ?>Telefone com DDD; o 55 do país é removido sozinho.</li>
                <li class="is-ok"><?= icon('check') ?>Telefones já cadastrados na empresa ou repetidos na planilha são recusados — nada é duplicado.</li>
                <li class="is-ok"><?= icon('check') ?>Os leads entram sem corretor; você distribui depois, pela roleta ou dividindo igualmente.</li>
            </ul>
            <div class="rule-box lgpd-box"><?= icon('shield') ?><span><b>LGPD:</b> importe apenas contatos com base legal para o contato comercial (consentimento ou legítimo interesse documentado) e de origem conhecida. Cada importação fica registrada na auditoria com o arquivo e quem importou. O arquivo enviado é apagado do servidor após a importação.</span></div>
        </div>
    </div>

<?php elseif ($passo === 2): ?>
    <form data-api-form action="/api/importacao_confirmar.php" data-redirecionar="/pages/importar.php?lista={id}" class="card import-map">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="card-head"><div><h2>2. Confira as colunas</h2><p><b><?= e($sessao['nome']) ?></b> · <?= $totalLinhas ?> linha<?= $totalLinhas === 1 ? '' : 's' ?> de dados. Mostrando as 10 primeiras.</p></div></div>
        <div class="form-grid">
            <div class="field"><label for="nome-lista">Nome da lista</label><input type="text" id="nome-lista" name="nome_lista" required maxlength="190" value="<?= e($nomeSugerido) ?>"></div>
            <div class="field"><label for="emp-lista">Empreendimento</label>
                <select id="emp-lista" name="empreendimento_id"><option value="">Sem empreendimento</option><?php foreach ($empreendimentos as $emp): ?><option value="<?= (int) $emp['id'] ?>"><?= e($emp['nome']) ?></option><?php endforeach; ?></select>
                <small class="field-hint">Necessário para distribuir pela roleta do empreendimento.</small></div>
        </div>
        <div class="subhead">Qual coluna é cada campo?</div>
        <div class="map-grid">
            <?php foreach (importacao_campos() as $campo => $rotulo): $obrig = in_array($campo, ['nome', 'telefone'], true); ?>
                <div class="field"><label for="col-<?= $campo ?>"><?= e($rotulo) ?><?= $obrig ? ' *' : '' ?></label>
                    <select id="col-<?= $campo ?>" name="col_<?= $campo ?>" <?= $obrig ? 'required' : '' ?>>
                        <option value=""><?= $obrig ? 'Escolha a coluna…' : 'Não importar' ?></option>
                        <?php foreach ($cabecalho as $i => $rot): ?><option value="<?= $i ?>" <?= ($sugestao[$campo] ?? null) === $i ? 'selected' : '' ?>><?= e($rot !== '' ? $rot : 'Coluna ' . ($i + 1)) ?></option><?php endforeach; ?>
                    </select></div>
            <?php endforeach; ?>
        </div>
        <div class="table-wrap"><table class="report-table import-previa">
            <thead><tr><?php foreach ($cabecalho as $i => $rot): ?><th><?= e($rot !== '' ? $rot : 'Coluna ' . ($i + 1)) ?></th><?php endforeach; ?></tr></thead>
            <tbody><?php foreach ($previa as $linha): ?><tr><?php foreach ($linha as $v): ?><td><?= e($v) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
        </table></div>
        <div class="form-alert" data-form-alerta hidden></div>
        <div class="modal-actions">
            <a href="/pages/importar.php" class="btn btn-outline">Trocar arquivo</a>
            <button type="submit" class="btn btn-primary btn-lg"><?= icon('check') ?>Importar <?= $totalLinhas ?> linha<?= $totalLinhas === 1 ? '' : 's' ?></button>
        </div>
    </form>

<?php elseif ($passo === 3): ?>
    <div class="card">
        <div class="card-head"><div><h2>3. Importação concluída</h2><p>Lista <b><?= e($lista['nome']) ?></b><?= $lista['empreendimento_nome'] ? ' · ' . e($lista['empreendimento_nome']) : '' ?> · arquivo <?= e($lista['arquivo_nome']) ?></p></div></div>
        <div class="kpi-grid">
            <div class="kpi"><div class="kpi-top"><span class="label">Linhas lidas</span><div class="kpi-icon"><?= icon('list') ?></div></div><div class="kpi-value"><?= (int) $lista['linhas_lidas'] ?></div></div>
            <div class="kpi"><div class="kpi-top"><span class="label">Importados</span><div class="kpi-icon"><?= icon('check') ?></div></div><div class="kpi-value txt-ok"><?= (int) $lista['importados'] ?></div><div class="kpi-sub">viraram leads</div></div>
            <div class="kpi"><div class="kpi-top"><span class="label">Duplicados</span><div class="kpi-icon"><?= icon('users') ?></div></div><div class="kpi-value"><?= (int) $lista['duplicados'] ?></div><div class="kpi-sub">já cadastrados ou repetidos</div></div>
            <div class="kpi"><div class="kpi-top"><span class="label">Inválidos</span><div class="kpi-icon"><?= icon('alert') ?></div></div><div class="kpi-value"><?= (int) $lista['invalidos'] ?></div><div class="kpi-sub">sem nome ou telefone</div></div>
        </div>
        <?php if ($relSessao && $relSessao['motivos']): ?>
            <div class="subhead">Por que linhas foram recusadas</div>
            <ul class="regra-lista">
                <?php foreach ($relSessao['motivos'] as $motivo => $qtd): ?><li class="is-no"><?= icon('x') ?><?= e($motivo) ?>: <b><?= (int) $qtd ?></b></li><?php endforeach; ?>
            </ul>
            <div class="table-wrap"><table class="report-table">
                <thead><tr><th>Linha</th><th>Motivo</th><th>Nome</th><th>Telefone</th></tr></thead>
                <tbody><?php foreach ($relSessao['amostra'] as $r): ?><tr><td><?= (int) $r['linha'] ?></td><td><?= e($r['motivo']) ?></td><td><?= e($r['nome']) ?></td><td><?= e($r['telefone']) ?></td></tr><?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
        <div class="modal-actions">
            <?php if (!empty($temRejeitados)): ?><a class="btn btn-outline" href="/api/importacao_rejeitados.php?lista=<?= (int) $lista['id'] ?>"><?= icon('download') ?>Baixar linhas recusadas (CSV)</a><?php endif; ?>
            <a class="btn btn-outline" href="/pages/importar.php"><?= icon('plus') ?>Importar outra</a>
            <a class="btn btn-primary btn-lg" href="/pages/oferta.php?id=<?= (int) $lista['id'] ?>"><?= icon('arrow-r') ?>Distribuir os leads</a>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
