<?php
/**
 * Inteligência de Vendas → lote de importação: contagens, resultado de cada
 * linha (sem dados pessoais), campos alterados e reprocessamento.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/estrutura.php';
require_once __DIR__ . '/../includes/lead_componentes.php';
require_once __DIR__ . '/../includes/vendas/importacao.php';

$usuario = auth_require();
$crumb = 'Lote de vendas';
$activeNav = 'relatorios';
if (!empty($usuario['eh_superadmin']) || !(usuario_tem_permissao($usuario, 'importar_vendas') || usuario_tem_permissao($usuario, 'ver_inteligencia_vendas'))) {
    pagina_acesso_restrito($crumb, $activeNav, 'Importar planilhas de vendas');
}
$empresaId = (int) $usuario['empresa_ativa_id'];
$lote = vendas_lote_carregar($empresaId, (int) ($_GET['id'] ?? 0));
if (!$lote) {
    http_response_code(404);
}
$filtros = ['todas' => 'Todas', 'erro' => 'Recusadas', 'ignorada' => 'Ignoradas', 'atualizada' => 'Alteradas', 'incluida' => 'Novas', 'sem_alteracao' => 'Já importadas', 'alerta' => 'Com alerta'];
$f = isset($filtros[$_GET['f'] ?? '']) ? $_GET['f'] : 'todas';
$linhas = [];
$alteracoes = [];
$contagemF = [];
if ($lote) {
    $st = db()->prepare('SELECT resultado, COUNT(*) q, SUM(alertas IS NOT NULL) a FROM vendas_lote_linhas WHERE lote_id = :l GROUP BY resultado');
    $st->execute(['l' => $lote['id']]);
    foreach ($st->fetchAll() as $r) {
        $contagemF[$r['resultado']] = (int) $r['q'];
        $contagemF['alerta'] = ($contagemF['alerta'] ?? 0) + (int) $r['a'];
    }
    $contagemF['todas'] = array_sum(array_intersect_key($contagemF, array_flip(['incluida', 'atualizada', 'sem_alteracao', 'ignorada', 'erro'])));
    $where = $f === 'todas' ? '' : ($f === 'alerta' ? ' AND alertas IS NOT NULL' : ' AND resultado = ' . db()->quote($f));
    $st = db()->prepare("SELECT * FROM vendas_lote_linhas WHERE lote_id = :l$where ORDER BY FIELD(resultado,'erro','ignorada','atualizada','incluida','sem_alteracao'), linha_num LIMIT 500");
    $st->execute(['l' => $lote['id']]);
    $linhas = $st->fetchAll();
    $st = db()->prepare('SELECT venda_id, alteracoes FROM vendas_externas_versoes WHERE lote_id = :l');
    $st->execute(['l' => $lote['id']]);
    foreach ($st->fetchAll() as $v) {
        $alteracoes[(int) $v['venda_id']] = array_keys(json_decode($v['alteracoes'], true) ?: []);
    }
    $arquivoDisponivel = false;
    $orig = $lote['reprocessa_lote_id'] ? vendas_lote_carregar($empresaId, (int) $lote['reprocessa_lote_id']) : $lote;
    if ($orig && (int) $orig['arquivo_guardado']) {
        $arquivoDisponivel = is_file((string) vendas_arquivo_lote((int) $orig['id'], $orig['arquivo_ext']));
    }
}
$rotRes = ['incluida' => ['pill-green', 'Nova'], 'atualizada' => ['pill-blue', 'Alterada'], 'sem_alteracao' => ['pill-muted', 'Já importada'],
           'ignorada' => ['pill-amber', 'Ignorada'], 'erro' => ['pill-red', 'Recusada']];
$rotCampo = array_map(fn ($x) => $x[2], vendas_campos()) + ['telefone1' => 'Telefone', 'telefone2' => 'Telefone 2', 'cpf_hash' => 'CPF', 'cpf_final' => 'CPF',
    'empreendimento_texto' => 'Empreendimento', 'imobiliaria_texto' => 'Imobiliária', 'corretor_texto' => 'Corretor (origem)', 'superintendente_texto' => 'Superintendente',
    'gerente_texto' => 'Gerente', 'coordenador_texto' => 'Coordenador', 'midia_texto' => 'Mídia', 'eh_venda' => 'Conta como venda', 'data_venda_so_data' => 'Precisão da data', 'extras' => 'Informações extras'];

$pageTitle = 'Lote de vendas — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div>
        <a class="back-link" href="/pages/vendas_importar.php"><?= icon('arrow-r') ?>Voltar para a importação</a>
        <div class="eyebrow">Inteligência de Vendas · Importação</div>
        <h1><?= $lote ? 'Lote #' . (int) $lote['id'] : 'Lote não encontrado' ?></h1>
        <?php if ($lote): ?>
            <p><b><?= e($lote['arquivo_nome']) ?></b> · enviado por <?= e($lote['usuario_nome'] ?? '—') ?> em <?= date('d/m/Y H:i', strtotime($lote['criado_em'])) ?>
                <?= $lote['reprocessa_lote_id'] ? ' · reprocessamento do <a href="/pages/vendas_lote.php?id=' . (int) $lote['reprocessa_lote_id'] . '">lote #' . (int) $lote['reprocessa_lote_id'] . '</a>' : '' ?>
                · regras de importação v<?= e($lote['versao_importador']) ?></p>
        <?php endif; ?>
    </div>
    <?php if ($lote && usuario_tem_permissao($usuario, 'importar_vendas')): ?>
        <div class="actions">
            <?php if ($arquivoDisponivel): ?>
                <form data-api-form action="/api/vendas_importar.php" data-redirecionar="/pages/vendas_lote.php?id={id}">
                    <input type="hidden" name="acao" value="reprocessar"><input type="hidden" name="lote_id" value="<?= (int) $lote['id'] ?>">
                    <button type="submit" class="btn btn-outline" title="Lê de novo o arquivo original com o mesmo mapeamento. Não duplica vendas."><?= icon('refresh') ?>Reprocessar</button>
                    <div class="form-alert" data-form-alerta hidden></div>
                </form>
            <?php endif; ?>
            <a class="btn btn-primary" href="/pages/vendas_importar.php"><?= icon('upload') ?>Nova importação</a>
        </div>
    <?php endif; ?>
</div>

<?php if (!$lote): ?>
    <div class="card stub-card"><div class="stub-icon"><?= icon('alert') ?></div><h2>Lote não encontrado</h2><p>Ele não existe ou é de outra empresa.</p></div>
<?php else: ?>
    <?php if ($lote['status'] === 'falhou'): ?>
        <div class="alert-strip" role="alert"><?= icon('alert') ?><span>Esta importação falhou e nenhuma venda foi gravada: <?= e($lote['erro'] ?? '') ?></span></div>
    <?php endif; ?>
    <div class="kpi-grid vendas-kpis">
        <div class="kpi"><div class="kpi-top"><span class="label">Linhas lidas</span><div class="kpi-icon"><?= icon('list') ?></div></div><div class="kpi-value"><?= (int) $lote['linhas_lidas'] ?></div></div>
        <div class="kpi"><div class="kpi-top"><span class="label">Incluídas</span><div class="kpi-icon"><?= icon('plus') ?></div></div><div class="kpi-value txt-ok"><?= (int) $lote['incluidas'] ?></div></div>
        <div class="kpi"><div class="kpi-top"><span class="label">Atualizadas</span><div class="kpi-icon"><?= icon('edit') ?></div></div><div class="kpi-value"><?= (int) $lote['atualizadas'] ?></div></div>
        <div class="kpi"><div class="kpi-top"><span class="label">Sem alteração</span><div class="kpi-icon"><?= icon('check') ?></div></div><div class="kpi-value"><?= (int) $lote['sem_alteracao'] ?></div></div>
        <div class="kpi"><div class="kpi-top"><span class="label">Ignoradas</span><div class="kpi-icon"><?= icon('users') ?></div></div><div class="kpi-value"><?= (int) $lote['ignoradas'] ?></div></div>
        <div class="kpi"><div class="kpi-top"><span class="label">Com erro</span><div class="kpi-icon"><?= icon('alert') ?></div></div><div class="kpi-value<?= (int) $lote['com_erro'] ? ' txt-bad' : '' ?>"><?= (int) $lote['com_erro'] ?></div></div>
    </div>
    <p class="muted vendas-arquivo-nota"><?= icon('shield') ?>
        <?php if ($arquivoDisponivel && !$lote['reprocessa_lote_id']): ?>Arquivo original protegido até <?= date('d/m/Y', strtotime($lote['descartar_arquivo_em'])) ?>; depois é apagado e ficam só os dados tratados.
        <?php elseif ($arquivoDisponivel): ?>Usa o arquivo original do lote #<?= (int) $lote['reprocessa_lote_id'] ?>.
        <?php else: ?>Arquivo original já descartado; os dados tratados continuam disponíveis.<?php endif; ?>
    </p>

    <div class="card">
        <div class="card-head">
            <div><h2>Resultado por linha</h2><p>Número da linha na planilha e id da venda na origem. Dados pessoais não aparecem aqui.</p></div>
            <nav class="segmented-inline" aria-label="Filtrar linhas">
                <?php foreach ($filtros as $k => $r): if ($k !== 'todas' && empty($contagemF[$k])) continue; ?>
                    <a class="seg-link<?= $f === $k ? ' is-active' : '' ?>" href="?id=<?= (int) $lote['id'] ?>&amp;f=<?= $k ?>"><?= e($r) ?> <span class="muted"><?= (int) ($contagemF[$k] ?? 0) ?></span></a>
                <?php endforeach; ?>
            </nav>
        </div>
        <?php if (!$linhas): ?>
            <p class="empty-note">Nenhuma linha neste filtro.</p>
        <?php else: ?>
            <div class="table-wrap"><table class="report-table">
                <thead><tr><th scope="col" class="num">Linha</th><th scope="col">Id da venda</th><th scope="col">Resultado</th><th scope="col">Motivo / campos alterados</th><th scope="col">Alertas</th></tr></thead>
                <tbody>
                <?php foreach ($linhas as $l): [$pc, $pr] = $rotRes[$l['resultado']]; $alt = $l['venda_id'] ? ($alteracoes[(int) $l['venda_id']] ?? []) : []; ?>
                    <tr>
                        <td class="num" data-rotulo="Linha"><?= (int) $l['linha_num'] ?></td>
                        <td data-rotulo="Id"><?= e($l['chave_origem'] ?? '—') ?></td>
                        <td data-rotulo="Resultado"><span class="pill <?= $pc ?>"><?= e($pr) ?></span></td>
                        <td data-rotulo="Motivo"><small><?= $l['resultado'] === 'atualizada' && $alt ? 'Mudou: ' . e(implode(', ', array_unique(array_map(fn ($c) => $rotCampo[$c] ?? $c, $alt)))) : e($l['motivo'] ?? '—') ?></small></td>
                        <td data-rotulo="Alertas"><small><?= e($l['alertas'] ?? '—') ?></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php if (count($linhas) === 500): ?><p class="muted">Mostrando as primeiras 500 linhas deste filtro.</p><?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/layout_end.php'; ?>
