<?php
/**
 * Peças de tela da Central de Relatórios (Etapa 3). Todos os números vêm do
 * motor (includes/relatorios/motor.php) — a tela só formata. Texto vindo do
 * banco sempre passa por e().
 */
require_once __DIR__ . '/motor.php';
require_once __DIR__ . '/../dashboard_ui.php';
require_once __DIR__ . '/../origens.php';

/** Parâmetros de URL aceitos pelos relatórios (o resto é descartado). */
function relui_chaves(): array
{
    return ['periodo', 'ini', 'fim', 'gran', 'metrica', 'agrupar', 'criterio', 'corretor', 'empreendimento', 'plantao', 'roleta',
            'superintendencia', 'gerente', 'equipe', 'origem', 'canal', 'emp'];
}

/** Lê $_GET: devolve [entrada para o motor, parâmetros públicos para links]. */
function relui_entrada(array $get): array
{
    $pub = [];
    foreach (relui_chaves() as $k) {
        if (!isset($get[$k]) || $get[$k] === '' || $get[$k] === []) {
            continue;
        }
        $pub[$k] = is_array($get[$k]) ? array_values(array_slice(array_filter(array_map('strval', $get[$k]), 'strlen'), 0, 6)) : mb_substr((string) $get[$k], 0, 40);
    }
    $entrada = $pub;
    unset($entrada['gran'], $entrada['metrica'], $entrada['agrupar'], $entrada['criterio'], $entrada['emp']);
    return [$entrada, $pub];
}

/** Monta a URL de um relatório mantendo os filtros e trocando/removendo parâmetros. */
function relui_url(string $relatorio, array $pub, array $trocar = []): string
{
    $q = array_merge($pub, $trocar);
    $q = array_filter($q, fn ($v) => $v !== null && $v !== '' && $v !== []);
    return '/pages/relatorio.php?' . http_build_query(['r' => $relatorio] + $q);
}

/** Opções dos filtros, limitadas ao que quem vê alcança. */
function relui_opcoes(array $usuario): array
{
    $e = (int) $usuario['empresa_ativa_id'];
    if ($usuario['escopo'] === 'propria_carteira') {
        // Corretor: só o que é dele (dash_opcoes é dos gestores).
        $emps = [];
        if (historico_instalado()) {
            $stmt = db()->prepare('SELECT DISTINCT emp.id, emp.nome FROM empreendimentos emp
                JOIN lead_atribuicoes a ON a.empreendimento_id = emp.id AND a.usuario_id = :u WHERE emp.empresa_id = :e ORDER BY emp.nome');
            $stmt->execute(['u' => $usuario['id'], 'e' => $e]);
            $emps = $stmt->fetchAll();
        }
        $op = ['empreendimentos' => $emps, 'equipes' => [], 'superintendencias' => [], 'gerentes' => [],
               'corretores' => [['id' => $usuario['id'], 'nome' => $usuario['nome']]], 'campanhas' => []];
    } else {
        $op = dash_opcoes($usuario);
    }
    $op['origens'] = array_map(fn ($o) => ['id' => $o['id'], 'nome' => $o['nome']], origens_listar($e, false));
    $op['roletas'] = $usuario['escopo'] === 'propria_carteira' ? [] : db()->query('SELECT id, nome FROM roletas WHERE empresa_id = ' . $e . ' ORDER BY nome')->fetchAll();
    $op['plantoes'] = $usuario['escopo'] === 'propria_carteira' || !modulo_tabela_existe('plantoes') ? []
        : db()->query('SELECT id, nome FROM plantoes WHERE empresa_id = ' . $e . ' ORDER BY nome')->fetchAll();
    return $op;
}

/**
 * Barra de filtros (sempre visível) + chips dos filtros aplicados + limpar +
 * favoritos. $dims: quais dimensões este relatório oferece.
 * $manter: parâmetros da página que não são filtros (agrupar, critério...).
 */
function relui_filtros(string $relatorio, array $usuario, array $f, array $pub, array $op, array $dims, array $manter = []): void
{
    $listas = [
        'empreendimento' => ['Empreendimento', 'Todos os empreendimentos', $op['empreendimentos']],
        'superintendencia' => ['Superintendência', 'Todas as superintendências', $op['superintendencias']],
        'gerente' => ['Gerente', 'Todos os gerentes', $op['gerentes']],
        'equipe' => ['Equipe', 'Todas as equipes', $op['equipes']],
        'corretor' => ['Corretor', 'Todos os corretores', $op['corretores']],
        'roleta' => ['Roleta', 'Todas as roletas', $op['roletas']],
        'plantao' => ['Plantão', 'Todos os plantões', $op['plantoes']],
        'origem' => ['Origem', 'Todas as origens', $op['origens']],
        'canal' => ['Grupo de origem', 'Todos os grupos', array_map(fn ($k, $v) => ['id' => $k, 'nome' => $v], array_keys(lead_origens()), lead_origens())],
    ];
    $chips = [];
    foreach ($dims as $d) {
        if (!isset($pub[$d]) || !isset($listas[$d])) {
            continue;
        }
        $nome = null;
        foreach ($listas[$d][2] as $it) {
            if ((string) $it['id'] === (string) $pub[$d]) {
                $nome = $it['nome'];
            }
        }
        $chips[] = [$listas[$d][0] . ': ' . ($nome ?? 'não encontrado(a)'), relui_url($relatorio, $pub, [$d => null])];
    }
    $p = $f['periodo'];
    $favoritos = relui_favoritos_listar($usuario, $relatorio);
    ?>
    <form class="rel-filtros" method="get" action="/pages/relatorio.php" aria-label="Filtros do relatório">
        <input type="hidden" name="r" value="<?= e($relatorio) ?>">
        <?php foreach ($manter as $k => $v): if ($v === null || $v === '') continue; ?>
            <?php foreach ((array) $v as $vv): ?><input type="hidden" name="<?= e($k) . (is_array($v) ? '[]' : '') ?>" value="<?= e((string) $vv) ?>"><?php endforeach; ?>
        <?php endforeach; ?>
        <div class="rel-filtros-linha">
            <label class="rel-campo"><span>Período</span>
                <select class="select-filter" name="periodo" data-periodo>
                    <?php foreach (relatorio_periodos() as $k => $r): ?><option value="<?= $k ?>" <?= ($p['chave'] ?? '') === $k || (!empty($f['erro']) && ($pub['periodo'] ?? '') === $k) ? 'selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?>
                </select>
            </label>
            <span class="rel-datas" data-periodo-datas <?= ($pub['periodo'] ?? '') === 'personalizado' ? '' : 'hidden' ?>>
                <label class="rel-campo"><span>De</span><input class="select-filter" type="date" name="ini" value="<?= e((string) ($pub['ini'] ?? '')) ?>"></label>
                <label class="rel-campo"><span>Até</span><input class="select-filter" type="date" name="fim" value="<?= e((string) ($pub['fim'] ?? '')) ?>"></label>
            </span>
            <?php foreach ($dims as $d): if (!isset($listas[$d])) continue; [$rot, $todos, $itens] = $listas[$d];
                if (!$itens || (count($itens) < 2 && !isset($pub[$d]) && $d !== 'corretor')) continue; ?>
                <label class="rel-campo"><span><?= e($rot) ?></span>
                    <select class="select-filter" name="<?= e($d) ?>">
                        <option value=""><?= e($todos) ?></option>
                        <?php foreach ($itens as $it): ?><option value="<?= e((string) $it['id']) ?>" <?= (string) ($pub[$d] ?? '') === (string) $it['id'] ? 'selected' : '' ?>><?= e($it['nome']) ?></option><?php endforeach; ?>
                    </select>
                </label>
            <?php endforeach; ?>
            <div class="rel-filtros-acoes">
                <button type="submit" class="btn btn-primary btn-sm"><?= icon('check') ?>Aplicar</button>
                <a class="btn btn-outline btn-sm" href="<?= e(relui_url($relatorio, array_intersect_key($manter, ['agrupar' => 1, 'criterio' => 1, 'metrica' => 1]))) ?>">Limpar</a>
            </div>
        </div>
    </form>
    <div class="rel-aplicados">
        <span class="rel-aplicados-rot">Mostrando:</span>
        <span class="pill pill-blue"><?= icon('calendar') ?><?= e(empty($f['erro']) ? $p['rotulo'] : 'período inválido') ?></span>
        <?php foreach ($chips as [$txt, $url]): ?>
            <a class="pill pill-muted rel-chip" href="<?= e($url) ?>" title="Remover este filtro"><?= e($txt) ?> <span aria-hidden="true">×</span><span class="sr-only">(remover)</span></a>
        <?php endforeach; ?>
        <?php if (!$chips): ?><span class="muted">sem outros filtros — tudo o que você tem permissão para ver.</span><?php endif; ?>
        <?php if (relui_favoritos_instalados()): ?>
            <span class="rel-favoritos">
                <?php if ($favoritos): ?>
                    <details class="rel-fav-menu"><summary class="btn btn-outline btn-sm"><?= icon('star') ?>Favoritos (<?= count($favoritos) ?>)</summary>
                        <ul>
                            <?php foreach ($favoritos as $fav): ?>
                                <li><a href="/pages/relatorio.php?<?= e('r=' . rawurlencode($relatorio) . '&' . $fav['filtros']) ?>"><?= e($fav['nome']) ?></a>
                                    <button type="button" class="link-btn danger" data-api-acao="/api/relatorio_favorito.php" data-acao="remover" data-id="<?= (int) $fav['id'] ?>" data-confirmar="Remover o favorito &quot;<?= e($fav['nome']) ?>&quot;?" aria-label="Remover <?= e($fav['nome']) ?>">remover</button></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>
                <button type="button" class="btn btn-outline btn-sm" data-open-modal="modal-favorito"><?= icon('star') ?>Salvar filtros</button>
            </span>
        <?php endif; ?>
    </div>
    <?php if (!empty($f['erro'])): ?>
        <div class="alert-strip" role="alert"><?= icon('alert') ?><span><?= e($f['erro']) ?> Nenhum número é mostrado com um período inválido.</span></div>
    <?php elseif ($f['vazio']): ?>
        <div class="alert-strip" role="status"><?= icon('alert') ?><span>Um dos filtros não existe ou não está ao seu alcance — por segurança o resultado fica vazio. Clique em <b>Limpar</b>.</span></div>
    <?php endif; ?>
    <?php if (relui_favoritos_instalados()): ?>
        <div class="modal-overlay" id="modal-favorito" hidden>
            <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="modal-favorito-titulo">
                <button type="button" class="overlay-close" data-close-modal="modal-favorito" aria-label="Fechar">&times;</button>
                <h2 id="modal-favorito-titulo">Salvar estes filtros</h2>
                <p class="muted">Guarda só a combinação de filtros (período, estrutura, origem…). Os números são recalculados toda vez que você abrir.</p>
                <form data-api-form action="/api/relatorio_favorito.php" data-recarregar>
                    <input type="hidden" name="acao" value="salvar">
                    <input type="hidden" name="relatorio" value="<?= e($relatorio) ?>">
                    <input type="hidden" name="filtros" value="<?= e(http_build_query($pub)) ?>">
                    <div class="field"><label for="fav-nome">Nome</label><input type="text" id="fav-nome" name="nome" required maxlength="80" placeholder="Ex.: Equipe A — mês anterior"></div>
                    <div class="form-alert" data-form-alerta hidden></div>
                    <button type="submit" class="btn btn-primary"><?= icon('check') ?>Salvar</button>
                </form>
            </div>
        </div>
    <?php endif;
}

// ------------------------------------------------------------------ Favoritos

function relui_favoritos_instalados(): bool
{
    return modulo_tabela_existe('relatorio_favoritos');
}

function relui_favoritos_listar(array $usuario, string $relatorio): array
{
    if (!relui_favoritos_instalados()) {
        return [];
    }
    $stmt = db()->prepare('SELECT id, nome, filtros FROM relatorio_favoritos WHERE usuario_id = :u AND empresa_id = :e AND relatorio = :r ORDER BY nome');
    $stmt->execute(['u' => $usuario['id'], 'e' => $usuario['empresa_ativa_id'], 'r' => $relatorio]);
    return $stmt->fetchAll();
}

/** Limpa a query string: só chaves conhecidas, valores curtos. */
function relui_filtros_limpos(string $qs): string
{
    parse_str($qs, $a);
    [, $pub] = relui_entrada(array_intersect_key($a, array_flip(relui_chaves())));
    return http_build_query($pub);
}

function relui_favorito_salvar(array $usuario, string $relatorio, string $nome, string $filtros): array
{
    if (!isset(relui_relatorios()[$relatorio])) {
        return ['ok' => false, 'erro' => 'Relatório inválido.'];
    }
    $nome = trim(preg_replace('/\s+/', ' ', $nome));
    if (mb_strlen($nome) < 2 || mb_strlen($nome) > 80) {
        return ['ok' => false, 'erro' => 'Dê um nome de 2 a 80 caracteres.'];
    }
    $stmt = db()->prepare('SELECT COUNT(*) FROM relatorio_favoritos WHERE usuario_id = :u AND empresa_id = :e');
    $stmt->execute(['u' => $usuario['id'], 'e' => $usuario['empresa_ativa_id']]);
    if ((int) $stmt->fetchColumn() >= 50) {
        return ['ok' => false, 'erro' => 'Limite de 50 favoritos. Remova algum antes.'];
    }
    db()->prepare('INSERT INTO relatorio_favoritos (empresa_id, usuario_id, relatorio, nome, filtros) VALUES (:e, :u, :r, :n, :f)
                   ON DUPLICATE KEY UPDATE filtros = VALUES(filtros)')
        ->execute(['e' => $usuario['empresa_ativa_id'], 'u' => $usuario['id'], 'r' => $relatorio, 'n' => $nome, 'f' => mb_substr(relui_filtros_limpos($filtros), 0, 2000)]);
    return ['ok' => true];
}

function relui_favorito_remover(array $usuario, int $id): bool
{
    $stmt = db()->prepare('DELETE FROM relatorio_favoritos WHERE id = :id AND usuario_id = :u AND empresa_id = :e');
    $stmt->execute(['id' => $id, 'u' => $usuario['id'], 'e' => $usuario['empresa_ativa_id']]);
    return $stmt->rowCount() === 1;
}

// ------------------------------------------------------------------ Catálogo e permissões

/** Relatórios desta etapa: chave => [título, descrição, ícone]. */
function relui_relatorios(): array
{
    return [
        'executiva' => ['Visão executiva', 'Os números principais do período, comparados com o período anterior.', 'chart'],
        'corretor' => ['Desempenho do corretor', 'Leads recebidos, atendimento, contato efetivo, visitas e contratos de um corretor, com a evolução.', 'user'],
        'gestao' => ['Equipes, gerentes e superintendências', 'Comparativo por estrutura (na data de cada entrega), com ranking por um critério escolhido.', 'users'],
        'empreendimentos' => ['Comparar empreendimentos', 'Os mesmos indicadores lado a lado para 2 a 6 empreendimentos.', 'building'],
    ];
}

/** Quem pode abrir cada relatório. Corretor só abre o próprio desempenho. */
function relui_pode(array $usuario, string $relatorio): bool
{
    if (!empty($usuario['eh_superadmin'])) {
        return false;
    }
    $gestor = usuario_tem_permissao($usuario, 'visualizar_indicadores');
    if ($relatorio === 'corretor') {
        return $gestor || $usuario['escopo'] === 'propria_carteira';
    }
    return $gestor && isset(relui_relatorios()[$relatorio]);
}

// ------------------------------------------------------------------ Formatação

/** Valor de um indicador do motor para a tela. */
function relui_valor($v, string $unidade = ''): string
{
    if ($v === null) {
        return '—';
    }
    switch ($unidade) {
        case '%':
            return number_format((float) $v, 1, ',', '.') . '%';
        case 'min':
            return dash_duracao((float) $v * 60);
        case 'h':
            return dash_duracao((float) $v * 3600);
        case 'R$':
            return 'R$ ' . number_format((float) $v, 2, ',', '.');
        default:
            return dash_num($v);
    }
}

/** Texto e classe da variação (do relatorio_executivo). $menorMelhor inverte a cor. */
function relui_variacao(array $ind, bool $menorMelhor = false): array
{
    if (($ind['variacao'] ?? null) === null) {
        $semBase = ($ind['anterior'] ?? null) === null || ($ind['valor'] ?? null) === null || (($ind['anterior'] ?? 0) == 0 && ($ind['unidade'] ?? '') !== '%');
        return [$semBase ? 'sem base de comparação' : 'igual ao período anterior', ''];
    }
    $v = $ind['variacao'];
    $u = $ind['variacao_unidade'] ?? '%';
    $txt = ($v > 0 ? '+' : ($v < 0 ? '−' : '')) . number_format(abs($v), 1, ',', '.') . ($u === 'p.p.' ? ' p.p.' : '%') . ' vs. anterior';
    if ($v == 0) {
        return ['igual ao período anterior', ''];
    }
    $bom = $menorMelhor ? $v < 0 : $v > 0;
    return [$txt, $bom ? 'up' : 'down'];
}

/** Cartão de indicador. */
function relui_kpi(string $rotulo, string $valor, string $ajuda, string $icone, array $variacao = ['', ''], string $sub = ''): void
{
    ?>
    <div class="kpi">
        <div class="kpi-top"><span class="label"><?= e($rotulo) ?> <?= dash_ajuda($ajuda) ?></span><div class="kpi-icon"><?= icon($icone) ?></div></div>
        <div class="kpi-value"><?= e($valor) ?></div>
        <?php if ($sub !== ''): ?><div class="kpi-sub"><?= e($sub) ?></div><?php endif; ?>
        <?php if ($variacao[0] !== ''): ?><div class="kpi-sub <?= e($variacao[1]) ?>"><?= e($variacao[0]) ?></div><?php endif; ?>
    </div>
    <?php
}

/** Aviso de histórico reconstruído / migração pendente. */
function relui_cobertura(array $cob): void
{
    if (!$cob['instalado']) {
        echo '<div class="alert-strip" role="status">' . icon('alert') . '<span><b>Histórico estruturado não instalado</b> (migração 013): os números por corretor e estrutura ficam indisponíveis.</span></div>';
        return;
    }
    if ($cob['reconstruidas'] > 0 && $cob['total'] > 0) {
        $pct = round($cob['reconstruidas'] * 100 / $cob['total']);
        echo '<div class="rel-nota" role="note">' . icon('info') . '<span>' . $pct . '% das entregas deste período foram <b>reconstruídas</b> de registros anteriores ao histórico estruturado'
            . ($cob['ao_vivo_desde'] ? ' (gravação completa desde ' . e(date('d/m/Y', strtotime($cob['ao_vivo_desde']))) . ')' : '')
            . '. Atendimentos sem registro aparecem como "não atendido" — nada é suposto.</span></div>';
    }
}

/**
 * Gráfico de linha (SVG) de uma série do motor, com dica ao passar o mouse
 * ou focar (assets/js/relatorios.js) e a mesma série em tabela.
 */
function relui_grafico_linha(array $serie, string $titulo, string $unidade = 'leads'): void
{
    $n = count($serie);
    if (!$n) {
        echo '<p class="empty-note">Sem intervalos no período.</p>';
        return;
    }
    $max = max(array_column($serie, 'total'));
    $topo = $max <= 4 ? 4 : (int) (ceil($max / 4) * 4);
    $W = 720;
    $H = 220;
    $esq = 34;
    $dir = 12;
    $cima = 12;
    $baixo = 26;
    $x = fn (int $i) => $n === 1 ? ($esq + ($W - $esq - $dir) / 2) : $esq + $i * ($W - $esq - $dir) / ($n - 1);
    $y = fn (float $v) => $cima + ($H - $cima - $baixo) * (1 - $v / $topo);
    $pts = [];
    foreach ($serie as $i => $p) {
        $pts[] = round($x($i), 1) . ',' . round($y($p['total']), 1);
    }
    $passo = max(1, (int) ceil($n / 8));
    $dados = array_map(fn ($p) => [$p['rotulo'], (int) $p['total']], $serie);
    ?>
    <figure class="rel-linha" data-rel-linha data-unidade="<?= e($unidade) ?>" data-pontos="<?= e(json_encode($dados, JSON_UNESCAPED_UNICODE)) ?>">
        <svg viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="<?= e($titulo) ?>. Total no período: <?= dash_num(array_sum(array_column($serie, 'total'))) ?>. A tabela com os números está logo abaixo." preserveAspectRatio="none">
            <?php for ($g = 0; $g <= 4; $g++): $gy = $y($topo * $g / 4); ?>
                <line x1="<?= $esq ?>" x2="<?= $W - $dir ?>" y1="<?= round($gy, 1) ?>" y2="<?= round($gy, 1) ?>" class="rel-grade<?= $g === 0 ? ' rel-base' : '' ?>"></line>
                <text x="<?= $esq - 6 ?>" y="<?= round($gy + 4, 1) ?>" text-anchor="end" class="rel-eixo"><?= dash_num($topo * $g / 4) ?></text>
            <?php endfor; ?>
            <polygon class="rel-area" points="<?= round($x(0), 1) . ',' . round($y(0), 1) . ' ' . implode(' ', $pts) . ' ' . round($x($n - 1), 1) . ',' . round($y(0), 1) ?>"></polygon>
            <polyline class="rel-traco" points="<?= implode(' ', $pts) ?>"></polyline>
            <?php foreach ($serie as $i => $p): if (($i % $passo !== 0 || ($i !== $n - 1 && $n - 1 - $i < $passo * 0.75)) && $i !== $n - 1) continue; ?>
                <text x="<?= round($x($i), 1) ?>" y="<?= $H - 6 ?>" text-anchor="<?= $i === 0 ? 'start' : ($i === $n - 1 ? 'end' : 'middle') ?>" class="rel-eixo"><?= e($p['rotulo']) ?></text>
            <?php endforeach; ?>
            <line class="rel-mira" x1="0" x2="0" y1="<?= $cima ?>" y2="<?= $H - $baixo ?>" hidden></line>
            <rect class="rel-alvo" x="<?= $esq ?>" y="0" width="<?= $W - $esq - $dir ?>" height="<?= $H ?>" tabindex="0" aria-label="Passe o mouse ou use as setas para ver cada valor"></rect>
        </svg>
        <span class="rel-marca" aria-hidden="true" hidden></span>
        <div class="rel-dica" role="status" hidden></div>
    </figure>
    <details class="dash-como-tabela"><summary>Ver como tabela</summary>
        <div class="table-wrap"><table class="report-table"><thead><tr><th scope="col">Intervalo</th><th scope="col" class="num"><?= e(ucfirst($unidade)) ?></th></tr></thead><tbody>
            <?php foreach ($serie as $p): ?><tr><td><?= e($p['rotulo']) ?></td><td class="num"><?= dash_num($p['total']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </details>
    <?php
}

/** "1 lead", "2 leads". */
function relui_qtd(int $n, string $um, string $varios): string
{
    return dash_num($n) . ' ' . ($n === 1 ? $um : $varios);
}

/** Seletor segmentado de links (granularidade, métrica, agrupamento). */
function relui_segmentos(array $opcoes, string $atual, callable $url, string $rotulo): void
{
    echo '<nav class="segmented-inline rel-seg" aria-label="' . e($rotulo) . '">';
    foreach ($opcoes as $k => $r) {
        echo '<a class="seg-link' . ($k === $atual ? ' is-active' : '') . '" href="' . e($url($k)) . '"' . ($k === $atual ? ' aria-current="true"' : '') . '>' . e($r) . '</a>';
    }
    echo '</nav>';
}
