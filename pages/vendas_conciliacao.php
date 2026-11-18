<?php
/**
 * Inteligência de Vendas → Conciliação: cada venda importada e o resultado
 * do cruzamento com o CRM (automático ou decidido por uma pessoa), com
 * filtros por situação, busca e acesso à revisão.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/estrutura.php';
require_once __DIR__ . '/../includes/lead_componentes.php';
require_once __DIR__ . '/../includes/vendas/cruzamento.php';

$usuario = auth_require();
$crumb = 'Conciliação de vendas';
$activeNav = 'relatorios';
if (!empty($usuario['eh_superadmin']) || !usuario_tem_permissao($usuario, 'ver_inteligencia_vendas')) {
    pagina_acesso_restrito($crumb, $activeNav, 'Ver a Inteligência de Vendas');
}
$empresaId = (int) $usuario['empresa_ativa_id'];
$podeRevisar = usuario_tem_permissao($usuario, 'revisar_correspondencias');
$instalado = vendas_cruzamento_instalado();
$sit = vendas_situacoes() + ['pendente' => ['Não cruzada', 'pill-muted', 'Ainda sem cruzamento']];
$efetiva = "CASE WHEN c.id IS NULL THEN 'pendente' WHEN c.decisao = 'automatica' THEN c.status ELSE c.decisao END";

$f = (string) ($_GET['s'] ?? '');
$f = isset($sit[$f]) ? $f : '';
$busca = trim((string) ($_GET['q'] ?? ''));
$loteF = (int) ($_GET['lote'] ?? 0);
$pagina = max(1, (int) ($_GET['p'] ?? 1));
$porPagina = 50;
$contagem = [];
$linhas = [];
$total = 0;
$resumo = ['vendas' => 0, 'valor' => 0.0];
if ($instalado) {
    $where = 'v.empresa_id = :e';
    $params = ['e' => $empresaId];
    if ($loteF) {
        $where .= ' AND v.ultimo_lote_id = :lote';
        $params['lote'] = $loteF;
    }
    if ($busca !== '') {
        $where .= ' AND (v.chave_origem = :b1 OR v.venda_rm = :b2 OR v.cliente_nome LIKE :b3)';
        $params += ['b1' => $busca, 'b2' => $busca, 'b3' => '%' . addcslashes($busca, '%_\\') . '%'];
    }
    $st = db()->prepare("SELECT $efetiva AS sit, COUNT(*) q, SUM(v.eh_venda) nv, SUM(CASE WHEN v.eh_venda = 1 THEN v.valor ELSE 0 END) val
                         FROM vendas_externas v LEFT JOIN vendas_correspondencias c ON c.venda_id = v.id WHERE $where GROUP BY sit");
    $st->execute($params);
    foreach ($st->fetchAll() as $r) {
        $contagem[$r['sit']] = (int) $r['q'];
        $resumo['vendas'] += (int) $r['nv'];
        $resumo['valor'] += (float) $r['val'];
    }
    $whereF = $where . ($f !== '' ? " AND $efetiva = :f" : '');
    if ($f !== '') {
        $params['f'] = $f;
    }
    $st = db()->prepare("SELECT COUNT(*) FROM vendas_externas v LEFT JOIN vendas_correspondencias c ON c.venda_id = v.id WHERE $whereF");
    $st->execute($params);
    $total = (int) $st->fetchColumn();
    $st = db()->prepare("SELECT v.*, c.status, c.decisao, c.cronologia, c.primeira_entrada_em, c.alertas AS c_alertas, $efetiva AS sit,
                         (SELECT COUNT(*) FROM vendas_correspondencia_leads cl WHERE cl.venda_id = v.id AND cl.papel <> 'descartado') AS n_leads
                         FROM vendas_externas v LEFT JOIN vendas_correspondencias c ON c.venda_id = v.id WHERE $whereF
                         ORDER BY FIELD($efetiva, 'ambigua', 'pendente') DESC, v.data_venda DESC, v.id DESC LIMIT " . (($pagina - 1) * $porPagina) . ", $porPagina");
    $st->execute($params);
    $linhas = $st->fetchAll();
}
$url = fn (array $mud) => '?' . http_build_query(array_filter(array_merge(['s' => $f, 'q' => $busca, 'lote' => $loteF ?: null], $mud), fn ($x) => $x !== null && $x !== ''));
$brl = fn ($v) => $v === null ? '—' : 'R$ ' . number_format((float) $v, 2, ',', '.');
$encontradas = ($contagem['forte'] ?? 0) + ($contagem['telefone'] ?? 0) + ($contagem['email'] ?? 0) + ($contagem['confirmada'] ?? 0);

$pageTitle = 'Conciliação de vendas — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div>
        <a class="back-link" href="/pages/relatorios_central.php"><?= icon('arrow-r') ?>Voltar para os relatórios</a>
        <div class="eyebrow">Relatórios · Inteligência de Vendas</div>
        <h1>Conciliação de vendas</h1>
        <p>Cada venda importada do sistema comercial e o que o cruzamento encontrou no CRM, por telefone e e-mail. Ambíguas só viram vínculo depois de revisadas por uma pessoa. Nome nunca é usado como prova.</p>
    </div>
    <div class="actions">
        <?php if ($podeRevisar && $instalado): ?>
            <button type="button" class="btn btn-outline" data-api-acao="/api/vendas_conciliacao.php" data-acao="recalcular" data-confirmar="Refazer o cruzamento de todas as vendas com os dados atuais do CRM? Decisões humanas são mantidas."><?= icon('refresh') ?>Recalcular cruzamento</button>
            <a class="btn btn-outline" href="/pages/vendas_equivalencias.php"><?= icon('building') ?>Equivalências</a>
        <?php endif; ?>
        <?php if (usuario_tem_permissao($usuario, 'importar_vendas')): ?><a class="btn btn-primary" href="/pages/vendas_importar.php"><?= icon('upload') ?>Importar vendas</a><?php endif; ?>
    </div>
</div>

<?php if (!$instalado): ?>
    <div class="card stub-card"><div class="stub-icon"><?= icon('alert') ?></div><h2>Em atualização</h2><p>Rode as migrações 017 e 018 para usar a conciliação.</p></div>
<?php else: ?>
    <div class="kpi-grid vendas-kpis">
        <div class="kpi"><div class="kpi-top"><span class="label">Vendas importadas</span><div class="kpi-icon"><?= icon('file') ?></div></div><div class="kpi-value"><?= array_sum($contagem) ?></div><div class="kpi-sub"><?= (int) $resumo['vendas'] ?> contam como venda · <?= e($brl($resumo['valor'])) ?></div></div>
        <div class="kpi"><div class="kpi-top"><span class="label">Encontradas no CRM</span><div class="kpi-icon"><?= icon('check') ?></div></div><div class="kpi-value txt-ok"><?= $encontradas ?></div><div class="kpi-sub">automáticas sem conflito + confirmadas</div></div>
        <div class="kpi"><div class="kpi-top"><span class="label">Para revisar</span><div class="kpi-icon"><?= icon('alert') ?></div></div><div class="kpi-value<?= ($contagem['ambigua'] ?? 0) ? ' txt-warn' : '' ?>"><?= (int) ($contagem['ambigua'] ?? 0) ?></div><div class="kpi-sub">ambíguas: exigem decisão humana</div></div>
        <div class="kpi"><div class="kpi-top"><span class="label">Posteriores à venda</span><div class="kpi-icon"><?= icon('clock') ?></div></div><div class="kpi-value"><?= (int) ($contagem['posterior'] ?? 0) ?></div><div class="kpi-sub">no CRM só depois de comprar</div></div>
        <div class="kpi"><div class="kpi-top"><span class="label">Sem correspondência</span><div class="kpi-icon"><?= icon('x') ?></div></div><div class="kpi-value"><?= (int) (($contagem['sem'] ?? 0) + ($contagem['rejeitada'] ?? 0)) ?></div><div class="kpi-sub">não encontradas ou descartadas</div></div>
    </div>

    <div class="card">
        <div class="card-head vendas-filtros">
            <nav class="chips-filtro" aria-label="Situação">
                <a class="chip<?= $f === '' ? ' is-active' : '' ?>" href="<?= e($url(['s' => null, 'p' => null])) ?>">Todas <b><?= array_sum($contagem) ?></b></a>
                <?php foreach ($sit as $k => [$rot]): if (empty($contagem[$k])) continue; ?>
                    <a class="chip<?= $f === $k ? ' is-active' : '' ?>" href="<?= e($url(['s' => $k, 'p' => null])) ?>"><?= e($rot) ?> <b><?= (int) $contagem[$k] ?></b></a>
                <?php endforeach; ?>
            </nav>
            <form method="get" class="vendas-busca" role="search">
                <?php if ($f): ?><input type="hidden" name="s" value="<?= e($f) ?>"><?php endif; ?>
                <?php if ($loteF): ?><input type="hidden" name="lote" value="<?= $loteF ?>"><?php endif; ?>
                <input type="search" name="q" value="<?= e($busca) ?>" placeholder="Id, Venda RM ou cliente" aria-label="Buscar venda">
                <button class="btn btn-outline btn-sm" type="submit"><?= icon('search') ?>Buscar</button>
            </form>
        </div>
        <?php if ($loteF): ?><p class="muted">Só as vendas do <a href="/pages/vendas_lote.php?id=<?= $loteF ?>">lote #<?= $loteF ?></a> · <a href="<?= e($url(['lote' => null])) ?>">ver todas</a></p><?php endif; ?>
        <?php if (!$linhas): ?>
            <p class="empty-note"><?= array_sum($contagem) ? 'Nenhuma venda neste filtro.' : 'Nenhuma venda importada ainda.' ?></p>
        <?php else: ?>
            <div class="table-wrap"><table class="report-table">
                <thead><tr><th scope="col">Venda</th><th scope="col">Data</th><th scope="col">Cliente</th><th scope="col">Empreendimento</th><th scope="col" class="num">Valor</th><th scope="col">Cruzamento</th><th scope="col" class="num">Leads</th><th scope="col">Entrada no CRM</th><th scope="col" class="num">Dias até a compra</th><th scope="col"></th></tr></thead>
                <tbody>
                <?php foreach ($linhas as $v): [$rotS, $pillS] = $sit[$v['sit']]; $dias = in_array($v['sit'], ['forte', 'telefone', 'email', 'confirmada'], true) && in_array($v['cronologia'], ['anterior', 'mesmo_dia'], true) ? vendas_dias_entre($v['primeira_entrada_em'], $v['data_venda']) : null; ?>
                    <tr>
                        <td data-rotulo="Venda"><?= e($v['chave_origem']) ?><?= $v['venda_rm'] ? '<br><small class="muted">RM ' . e($v['venda_rm']) . '</small>' : '' ?></td>
                        <td data-rotulo="Data"><?= $v['data_venda'] ? date('d/m/Y', strtotime($v['data_venda'])) : '—' ?></td>
                        <td data-rotulo="Cliente"><?= e($v['cliente_nome'] ?? '—') ?></td>
                        <td data-rotulo="Empreendimento"><?= e($v['empreendimento_texto'] ?? '—') ?></td>
                        <td class="num" data-rotulo="Valor"><?= e($brl($v['valor'])) ?><?= (int) $v['eh_venda'] ? '' : '<br><small class="muted">' . e($v['situacao'] ?? '') . ' · não conta</small>' ?></td>
                        <td data-rotulo="Cruzamento"><span class="pill <?= $pillS ?>"><?= e($rotS) ?></span><?= $v['c_alertas'] ? ' <span class="vendas-alerta" title="' . e($v['c_alertas']) . '">' . icon('info') . '</span>' : '' ?></td>
                        <td class="num" data-rotulo="Leads"><?= (int) $v['n_leads'] ?: '—' ?></td>
                        <td data-rotulo="Entrada no CRM"><?= $v['primeira_entrada_em'] ? date('d/m/Y', strtotime($v['primeira_entrada_em'])) : '—' ?></td>
                        <td class="num" data-rotulo="Dias até a compra"><?= $dias !== null ? $dias : '—' ?></td>
                        <td class="vendas-acoes-linha"><?php if (in_array($v['sit'], ['forte', 'telefone', 'email', 'confirmada', 'posterior'], true)): ?><a class="btn btn-primary btn-sm" href="/pages/vendas_jornada.php?id=<?= (int) $v['id'] ?>">Ver jornada</a><?php endif; ?>
                            <a class="btn <?= $v['sit'] === 'ambigua' && $podeRevisar ? 'btn-primary' : 'btn-outline' ?> btn-sm" href="/pages/vendas_revisao.php?id=<?= (int) $v['id'] ?>"><?= $v['sit'] === 'ambigua' && $podeRevisar ? 'Revisar' : 'Correspondência' ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php $paginas = (int) ceil($total / $porPagina); if ($paginas > 1): ?>
                <nav class="paginacao" aria-label="Páginas">
                    <?php if ($pagina > 1): ?><a class="btn btn-outline btn-sm" href="<?= e($url(['p' => $pagina - 1])) ?>">Anterior</a><?php endif; ?>
                    <span class="muted">Página <?= $pagina ?> de <?= $paginas ?> · <?= $total ?> venda(s)</span>
                    <?php if ($pagina < $paginas): ?><a class="btn btn-outline btn-sm" href="<?= e($url(['p' => $pagina + 1])) ?>">Próxima</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
        <p class="muted vendas-metodo"><b>Como ler:</b> "Dias até a compra" conta da 1ª entrada comprovada no CRM (cadastro ou formulário do anúncio) até a data da venda, só quando a pessoa já estava no CRM antes. Regras de correspondência <?= e(VENDAS_REGRAS_VERSAO) ?>.</p>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/layout_end.php'; ?>
