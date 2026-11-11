<?php
/**
 * Pedaços de tela compartilhados pelo Dashboard e pelos Relatórios:
 * formulário de filtros, ajuda "?", tabelas ordenáveis e o diálogo de
 * exportação. Todo texto vindo do banco passa por e().
 */
require_once __DIR__ . '/dashboard.php';
require_once __DIR__ . '/lead_componentes.php';
require_once __DIR__ . '/exportacao.php';

/** Botão "?" com a explicação de uma linha (abre ao tocar no celular). */
function dash_ajuda(string $texto): string
{
    return '<details class="dash-ajuda"><summary aria-label="O que significa?">?</summary><p>' . e($texto) . '</p></details>';
}

/** Formulário de filtros (GET). $manter: campos extras que a página quer preservar (ex.: agrupamento). */
function dash_form_filtros(array $f, array $op, array $manter = []): void
{
    $ativos = array_diff_key(dash_filtros_publicos($f), ['periodo' => 1, 'de' => 1, 'ate' => 1]);
    $sel = function (string $nome, string $rotulo, string $todos, array $itens, $atual) {
        if (!$itens) {
            return;
        }
        echo '<label class="sr-only" for="df-' . $nome . '">' . e($rotulo) . '</label>';
        echo '<select class="select-filter" id="df-' . $nome . '" name="' . $nome . '"><option value="">' . e($todos) . '</option>';
        foreach ($itens as $v => $r) {
            echo '<option value="' . e((string) $v) . '"' . ((string) $atual === (string) $v ? ' selected' : '') . '>' . e($r) . '</option>';
        }
        echo '</select>';
    };
    $mapa = fn (array $rows) => array_column($rows, 'nome', 'id');
    ?>
    <form class="filters-row dash-filtros" method="get" aria-label="Filtros do relatório">
        <?php foreach ($manter as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>"><?php endforeach; ?>
        <label class="sr-only" for="df-periodo">Período</label>
        <select class="select-filter" id="df-periodo" name="periodo" data-periodo>
            <?php foreach (dash_periodos() as $k => $r): ?><option value="<?= $k ?>" <?= $f['periodo'] === $k ? 'selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?>
        </select>
        <span class="dash-datas" data-periodo-datas <?= $f['periodo'] === 'personalizado' ? '' : 'hidden' ?>>
            <label class="sr-only" for="df-de">De</label><input class="select-filter" type="date" id="df-de" name="de" value="<?= e($f['de']) ?>">
            <span aria-hidden="true">até</span>
            <label class="sr-only" for="df-ate">Até</label><input class="select-filter" type="date" id="df-ate" name="ate" value="<?= e($f['ate']) ?>">
        </span>
        <button type="button" class="btn btn-outline btn-sm filters-toggle" data-toggle-filtros aria-expanded="<?= $ativos ? 'true' : 'false' ?>"><?= icon('filter') ?>Filtros<?= $ativos ? ' (' . count($ativos) . ')' : '' ?></button>
        <div class="filters-extra<?= $ativos ? ' is-open' : '' ?>" data-filtros-extra>
            <?php
            $sel('empreendimento', 'Empreendimento', 'Todos os empreendimentos', $mapa($op['empreendimentos']), $f['empreendimento']);
            if (count($op['superintendencias']) > 1 || $f['superintendencia']) {
                $sel('superintendencia', 'Superintendência', 'Todas as superintendências', $mapa($op['superintendencias']), $f['superintendencia']);
            }
            if (count($op['equipes']) > 1 || $f['equipe']) {
                $sel('equipe', 'Equipe', 'Todas as equipes', $mapa($op['equipes']), $f['equipe']);
            }
            if (count($op['gerentes']) > 1 || $f['gerente']) {
                $sel('gerente', 'Gerente', 'Todos os gerentes', $mapa($op['gerentes']), $f['gerente']);
            }
            $sel('corretor', 'Corretor', 'Todos os corretores', $mapa($op['corretores']), $f['corretor']);
            $sel('origem', 'Origem', 'Todas as origens', lead_origens(), $f['origem']);
            $sel('campanha', 'Campanha', 'Todas as campanhas', ['__sem__' => 'Sem campanha'] + array_combine($op['campanhas'], $op['campanhas']), $f['campanha']);
            ?>
            <button type="submit" class="btn btn-primary btn-sm"><?= icon('check') ?>Aplicar</button>
            <?php if ($ativos || $f['periodo'] !== '30d'): ?><a class="btn btn-outline btn-sm" href="?<?= e(http_build_query($manter)) ?>">Limpar</a><?php endif; ?>
        </div>
    </form>
    <?php
}

/**
 * Tabela ordenável com linha de totais. $colunas: [chave => [rótulo, tipo]].
 * $barra: coluna que ganha uma barrinha proporcional (ou null).
 */
function dash_tabela(string $id, array $colunas, array $linhas, ?array $totais = null, ?string $barra = null, string $vazio = 'Nada no período.'): void
{
    if (!$linhas) {
        echo '<p class="empty-note">' . e($vazio) . '</p>';
        return;
    }
    $max = $barra ? max(1, ...array_map(fn ($l) => (float) ($l[$barra] ?? 0), $linhas)) : 1;
    ?>
    <div class="table-wrap dash-table-wrap">
        <table class="report-table dash-table" id="<?= e($id) ?>" data-ordenavel>
            <thead><tr>
                <?php $i = 0; foreach ($colunas as $k => [$rotulo, $tipo]): ?>
                    <th scope="col" class="<?= $tipo === 'texto' ? '' : 'num' ?>" aria-sort="none"><button type="button" data-ordenar="<?= $i++ ?>" data-tipo="<?= $tipo === 'texto' ? 'texto' : 'num' ?>"><?= e($rotulo) ?></button></th>
                <?php endforeach; ?>
            </tr></thead>
            <tbody>
                <?php foreach ($linhas as $l): ?>
                    <tr>
                        <?php foreach ($colunas as $k => [$rotulo, $tipo]): $v = $l[$k] ?? null; ?>
                            <td class="<?= $tipo === 'texto' ? 'nome' : 'num' ?>" data-sort="<?= e(dash_ordem($v, $tipo)) ?>" data-rotulo="<?= e($rotulo) ?>">
                                <?php if ($k === $barra): ?>
                                    <span class="dash-cell-bar"><span style="width: <?= round((float) $v / $max * 100) ?>%"></span></span>
                                <?php endif; ?>
                                <?= e(dash_celula($v, $tipo)) ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if ($totais): ?>
                <tfoot><tr>
                    <?php foreach ($colunas as $k => [$rotulo, $tipo]): ?>
                        <td class="<?= $tipo === 'texto' ? 'nome' : 'num' ?>" data-rotulo="<?= e($rotulo) ?>"><?= e($k === 'nome' ? 'Total' : dash_celula($totais[$k] ?? null, $tipo)) ?></td>
                    <?php endforeach; ?>
                </tr></tfoot>
            <?php endif; ?>
        </table>
    </div>
    <?php
}

/**
 * Botão + diálogo de exportação. $opcoes: [valor => rótulo] do que pode ser
 * exportado; $campos: filtros enviados junto (os mesmos da tela).
 * $dadosPessoais: mostra o lembrete da LGPD.
 */
function dash_exportar(string $id, array $opcoes, array $campos, bool $dadosPessoais = false, string $rotuloBotao = 'Exportar'): void
{
    $formato = exportacao_formato();
    ?>
    <button type="button" class="btn btn-outline" data-open-modal="<?= e($id) ?>"><?= icon('download') ?><?= e($rotuloBotao) ?></button>
    <div class="modal-overlay" id="<?= e($id) ?>" hidden>
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="<?= e($id) ?>-titulo">
            <button type="button" class="overlay-close" data-close-modal="<?= e($id) ?>" aria-label="Fechar">&times;</button>
            <h2 id="<?= e($id) ?>-titulo">Exportar planilha</h2>
            <form action="/api/exportar.php" method="post" data-exportar-form>
                <?= csrf_field() ?>
                <?php foreach ($campos as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>"><?php endforeach; ?>
                <?php if (count($opcoes) > 1): ?>
                    <div class="field"><label for="<?= e($id) ?>-o">O que exportar</label>
                        <select id="<?= e($id) ?>-o" name="relatorio"><?php foreach ($opcoes as $v => $r): ?><option value="<?= e($v) ?>"><?= e($r) ?></option><?php endforeach; ?></select>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="relatorio" value="<?= e((string) array_key_first($opcoes)) ?>">
                    <p class="export-o-que"><?= e((string) reset($opcoes)) ?></p>
                <?php endif; ?>
                <p class="export-formato"><?= icon('file') ?><span>
                    <?= $formato === 'xlsx'
                        ? 'Formato: <b>Excel (.xlsx)</b>. Abre direto no Excel, Google Planilhas ou LibreOffice.'
                        : 'Formato: <b>CSV</b> (separado por ponto e vírgula). Este servidor não tem o módulo ZipArchive do PHP para gerar .xlsx; o CSV abre no Excel normalmente.' ?>
                </span></p>
                <p class="muted">Leva exatamente os mesmos filtros e o mesmo recorte que você vê na tela (no máximo <?= dash_num(exportacao_limite()) ?> linhas).</p>
                <?php if ($dadosPessoais): ?>
                    <div class="lgpd-aviso" role="note"><?= icon('shield') ?><div>
                        <b>Esta planilha tem dados pessoais (nome, telefone, e-mail).</b>
                        Pela LGPD, use só para o atendimento, não compartilhe fora da empresa e apague o arquivo quando não precisar mais. A exportação fica registrada na auditoria.
                    </div></div>
                    <label class="check-line"><input type="checkbox" name="ciente_lgpd" value="1" required> Entendi e vou usar os dados só para o atendimento</label>
                <?php endif; ?>
                <div class="form-alert" data-form-alerta hidden></div>
                <button type="submit" class="btn btn-primary"><?= icon('download') ?>Baixar planilha</button>
            </form>
        </div>
    </div>
    <?php
}

/** Gráfico de barras verticais (CSS) com a alternativa em tabela. */
function dash_grafico_barras(array $serie, string $titulo, string $rotuloValor = 'leads'): void
{
    $max = max(1, ...array_column($serie, 'total') ?: [1]);
    $n = count($serie);
    $passo = $n > 16 ? (int) ceil($n / 8) : 1;
    ?>
    <div class="dash-bars" role="img" aria-label="<?= e($titulo) ?>: gráfico de barras; a tabela com os números está logo abaixo.">
        <?php foreach ($serie as $i => $p): ?>
            <div class="dash-bar-col" title="<?= e($p['rotulo']) ?>: <?= (int) $p['total'] ?> <?= e($rotuloValor) ?>">
                <?php if (isset($p['inatividade'])): ?>
                    <div class="dash-bar-stack" style="height: <?= $p['total'] ? max(3, round($p['total'] / $max * 100)) : 0 ?>%">
                        <span class="seg-adm" style="flex: <?= (int) $p['administrativas'] ?>"></span><span class="seg-ina" style="flex: <?= (int) $p['inatividade'] ?>"></span>
                    </div>
                <?php else: ?>
                    <div class="dash-bar" style="height: <?= $p['total'] ? max(3, round($p['total'] / $max * 100)) : 0 ?>%"></div>
                <?php endif; ?>
                <span class="dash-bar-label"><?= $i % $passo === 0 ? e($p['rotulo']) : '' ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <details class="dash-como-tabela"><summary>Ver como tabela</summary>
        <div class="table-wrap"><table class="report-table"><thead><tr><th scope="col">Data</th>
            <?php if (isset($serie[0]['inatividade'])): ?><th scope="col" class="num">Inatividade</th><th scope="col" class="num">Administrativas</th><?php endif; ?>
            <th scope="col" class="num">Total</th></tr></thead><tbody>
            <?php foreach ($serie as $p): ?><tr><td><?= e($p['rotulo']) ?></td>
                <?php if (isset($p['inatividade'])): ?><td class="num"><?= (int) $p['inatividade'] ?></td><td class="num"><?= (int) $p['administrativas'] ?></td><?php endif; ?>
                <td class="num"><?= (int) $p['total'] ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </details>
    <?php
}

/** Barras horizontais (CSS) para uma lista [rotulo, total]. */
function dash_barras_horizontais(array $itens, string $titulo, int $limite = 8): void
{
    $itens = array_slice($itens, 0, $limite);
    if (!$itens || !array_sum(array_column($itens, 'total'))) {
        echo '<p class="empty-note">Nada no período.</p>';
        return;
    }
    $max = max(1, ...array_column($itens, 'total'));
    echo '<div class="funnel" role="list" aria-label="' . e($titulo) . '">';
    foreach ($itens as $it) {
        echo '<div class="funnel-row" role="listitem"><span class="funnel-label" title="' . e($it['rotulo']) . '">' . e($it['rotulo']) . '</span>'
            . '<div class="funnel-track"><div class="funnel-fill" style="width:' . round($it['total'] / $max * 100) . '%"></div></div>'
            . '<span class="funnel-value">' . dash_num($it['total']) . '</span></div>';
    }
    echo '</div>';
}

/** Rosca (SVG) com legenda em texto — a legenda já traz os números. */
function dash_rosca(array $itens, string $titulo): void
{
    $total = array_sum(array_column($itens, 'total'));
    if (!$total) {
        echo '<p class="empty-note">Nada no período.</p>';
        return;
    }
    $cores = ['#635bfa', '#189d72', '#df9e25', '#dc6070', '#3b8fd9', '#8c96aa'];
    $r = 15.9155; // circunferência 100
    $acum = 0;
    echo '<div class="dash-rosca"><svg viewBox="0 0 42 42" role="img" aria-label="' . e($titulo) . '"><title>' . e($titulo) . '</title>';
    echo '<circle cx="21" cy="21" r="' . $r . '" fill="none" stroke="#eef0f6" stroke-width="6"></circle>';
    foreach (array_values($itens) as $i => $it) {
        if (!$it['total']) {
            continue;
        }
        $pct = $it['total'] / $total * 100;
        echo '<circle cx="21" cy="21" r="' . $r . '" fill="none" stroke="' . $cores[$i % count($cores)] . '" stroke-width="6" stroke-dasharray="'
            . round($pct, 3) . ' ' . round(100 - $pct, 3) . '" stroke-dashoffset="' . round(25 - $acum, 3) . '"><title>' . e($it['rotulo']) . ': ' . (int) $it['total'] . '</title></circle>';
        $acum += $pct;
    }
    echo '<text x="21" y="21" text-anchor="middle" dominant-baseline="central" class="dash-rosca-total">' . dash_num($total) . '</text></svg><ul class="dash-legenda">';
    foreach (array_values($itens) as $i => $it) {
        echo '<li><span class="dot" style="background:' . $cores[$i % count($cores)] . '"></span>' . e($it['rotulo'])
            . '<b>' . dash_num($it['total']) . ' <small>(' . dash_pct($it['total'] / $total * 100) . ')</small></b></li>';
    }
    echo '</ul></div>';
}
