<?php
/**
 * Central de Relatórios (Etapa 3): Visão executiva, Desempenho do corretor,
 * Equipes/gerentes/superintendências e Comparar empreendimentos.
 *
 * Todo número vem do motor (includes/relatorios/motor.php), com a permissão
 * aplicada no SQL. O corretor só abre o próprio desempenho (o filtro de
 * corretor é forçado para ele).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/estrutura.php';
require_once __DIR__ . '/../includes/relatorios/ui.php';

$usuario = auth_require();
if (!empty($usuario['eh_superadmin'])) {
    header('Location: /pages/superadmin.php');
    exit;
}
$rel = (string) ($_GET['r'] ?? 'executiva');
if (!isset(relui_relatorios()[$rel])) {
    header('Location: /pages/relatorios_central.php');
    exit;
}
[$titulo, $descricao, $iconeRel] = relui_relatorios()[$rel];
$crumb = $titulo;
$activeNav = 'relatorios';
if (!relui_pode($usuario, $rel)) {
    pagina_acesso_restrito($crumb, $activeNav, 'Visualizar indicadores/dashboard');
}
$ehCorretor = $usuario['escopo'] === 'propria_carteira';
[$entrada, $pub] = relui_entrada($_GET);
if ($rel === 'corretor' && $ehCorretor) {
    $entrada['corretor'] = $pub['corretor'] = (string) $usuario['id'];
}
$f = relatorio_filtros($usuario, $entrada);
$op = relui_opcoes($usuario);
$cob = relatorio_cobertura_historico($usuario, $f);
$gran = isset(relatorio_granularidades()[$pub['gran'] ?? '']) ? $pub['gran'] : (empty($f['erro']) ? relatorio_granularidade_padrao($f['periodo']) : 'dia');
$url = fn (array $trocar = []) => relui_url($rel, $pub, $trocar);

$pageTitle = $titulo . ' — Relatórios — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div>
        <div class="eyebrow">Relatórios<?= $ehCorretor ? '' : ' · ' . e(rbac_rotulo_escopo($usuario['escopo'])) ?></div>
        <h1><?= e($titulo) ?></h1>
        <p><?= e($descricao) ?></p>
    </div>
    <?php if (!$ehCorretor): ?>
        <div class="actions"><a class="btn btn-outline" href="/pages/relatorios_central.php"><?= icon('list') ?>Todos os relatórios</a></div>
    <?php endif; ?>
</div>

<?php
// ============================================================================ VISÃO EXECUTIVA
if ($rel === 'executiva'):
    $dims = ['empreendimento', 'superintendencia', 'gerente', 'equipe', 'corretor', 'roleta', 'plantao', 'origem', 'canal'];
    $metrica = isset(relatorio_metricas_serie()[$pub['metrica'] ?? '']) ? $pub['metrica'] : 'leads_recebidos';
    relui_filtros($rel, $usuario, $f, $pub, $op, $dims, ['gran' => $pub['gran'] ?? null, 'metrica' => $pub['metrica'] ?? null]);
    $ex = relatorio_executivo($usuario, $f);
    $I = $ex['indicadores'];
    $perdas = relatorio_perdas($usuario, $f);
    $funil = relatorio_funil($usuario, $f);
    $origens = relatorio_leads_por_origem($usuario, $f);
    $serie = relatorio_serie($usuario, $f, $metrica, $gran);
    relui_cobertura($cob);
    $card = function (string $k, string $rot, string $ic, string $ajuda, bool $menor = false, string $sub = '') use ($I) {
        $ind = $I[$k];
        relui_kpi($rot, relui_valor($ind['valor'], $ind['unidade'] ?? ''), $ajuda . ($ind['nota'] ? ' ' . $ind['nota'] : ''), $ic, relui_variacao($ind, $menor), $sub);
    };
    ?>
    <p class="rel-comparacao">Comparado com <b><?= e($ex['periodo']['anterior']['rotulo'] ?? '—') ?></b> (mesma duração). Sem base no período anterior, a variação aparece como "sem base de comparação".</p>
    <div class="kpi-grid kpi-grid-5 dash-kpis">
        <?php
        $card('leads_recebidos', 'Leads recebidos', 'user', 'Leads que entraram no período (data de entrada), em tudo o que você pode ver.', false, relui_qtd((int) $I['leads_unicos']['valor'], 'pessoa', 'pessoas diferentes'));
        $card('leads_distribuidos', 'Chegaram a um corretor', 'users', 'Dos leads do período, quantos tiveram pelo menos uma entrega a corretor registrada.');
        $card('taxa_contato_efetivo', 'Taxa de contato efetivo', 'phone', 'Dos leads distribuídos, em quantos o cliente foi alcançado (respondeu no WhatsApp, o corretor confirmou a resposta, avançou de etapa ou visitou). Mensagem automática não conta.', false, relui_qtd((int) $I['contatos_efetivos']['valor'], 'lead', 'leads'));
        $card('tempo_primeiro_contato', 'Tempo até o 1º atendimento', 'clock', 'Mediana entre a entrega ao primeiro corretor e a primeira atuação dele. Metade dos leads foi atendida antes desse tempo. Menor é melhor.', true);
        $card('leads_fora_horario', 'Chegaram fora do horário', 'alert', 'Leads que chegaram com a roleta fechada ou sem corretor apto. Só existe para leads que chegaram depois da implantação do histórico.', true);
        $card('visitas_agendadas', 'Visitas agendadas', 'calendar', 'Visitas criadas no período (reagendamento não conta de novo).', false, relui_qtd((int) $I['visitas_confirmadas']['valor'], 'confirmada', 'confirmadas') . ' pelo cliente');
        $card('visitas_realizadas', 'Visitas realizadas', 'building', 'Visitas concluídas como realizadas no período.');
        $card('taxa_comparecimento', 'Comparecimento', 'target', 'Realizadas ÷ (realizadas + cliente ausente).');
        $card('contratos_validados', 'Contratos validados', 'check', 'Vendas formais: contratos registrados e validados, pela data de assinatura. Mover no Kanban não conta.', false, dash_num($I['contratos_aguardando']['valor']) . ' aguardando validação');
        $card('vgv', 'VGV validado', 'file', 'Soma do valor dos contratos validados (Valor Geral de Vendas — não é receita nem comissão).', false, $I['vgv']['nota']);
        ?>
    </div>

    <div class="dash-grid">
        <div class="card dash-span-2">
            <div class="card-head"><div><h2>Evolução</h2><p><?= e(relatorio_metricas_serie()[$metrica]) ?> · <?= e(mb_strtolower(relatorio_granularidades()[$gran])) ?></p></div>
                <?php relui_segmentos(relatorio_granularidades(), $gran, fn ($k) => $url(['gran' => $k]), 'Agrupar a evolução'); ?></div>
            <?php relui_segmentos(['leads_recebidos' => 'Leads', 'recebimentos' => 'Entregas', 'contatos_efetivos' => 'Contatos efetivos', 'visitas_realizadas' => 'Visitas', 'contratos_validados' => 'Contratos'],
                $metrica, fn ($k) => $url(['metrica' => $k]), 'Indicador da evolução'); ?>
            <?php relui_grafico_linha($serie, relatorio_metricas_serie()[$metrica], $metrica === 'contratos_validados' ? 'contratos' : ($metrica === 'visitas_realizadas' ? 'visitas' : 'leads')); ?>
        </div>
        <div class="card">
            <div class="card-head"><div><h2>Origem dos leads <?= dash_ajuda('De onde vieram os leads que entraram no período (origem de aquisição, não o canal de atendimento).') ?></h2></div></div>
            <?php
            $itens = array_map(fn ($o) => ['rotulo' => $o['origem'], 'total' => (int) $o['leads']], $origens);
            if (count($itens) > 5) {
                $outros = array_sum(array_column(array_slice($itens, 4), 'total'));
                $itens = array_merge(array_slice($itens, 0, 4), [['rotulo' => 'Demais origens', 'total' => $outros]]);
            }
            dash_rosca($itens, 'Leads por origem');
            ?>
        </div>
        <div class="card">
            <div class="card-head"><div><h2>Contratos <?= dash_ajuda('A venda conta quando o contrato é registrado e validado. O sinal do Kanban aparece à parte e nunca é somado.') ?></h2></div></div>
            <dl class="rel-lista">
                <div><dt>Validados</dt><dd><?= e(relui_valor($I['contratos_validados']['valor'])) ?></dd></div>
                <div><dt>Aguardando validação</dt><dd><?= e(relui_valor($I['contratos_aguardando']['valor'])) ?></dd></div>
                <div><dt>Distratos no período</dt><dd><?= e(relui_valor($I['distratos']['valor'])) ?></dd></div>
                <div><dt>VGV validado</dt><dd><?= e(relui_valor($I['vgv']['valor'], 'R$')) ?></dd></div>
                <div class="rel-lista-sinal"><dt>Sinal do Kanban: movidos para "Contrato assinado"</dt><dd><?= e(relui_valor($I['contratos_pela_etapa']['valor'])) ?></dd></div>
            </dl>
        </div>
        <div class="card">
            <div class="card-head"><div><h2>Movimentação do funil <?= dash_ajuda('Mudanças de etapa feitas no período. Avanço/retrocesso pela ordem do funil. As feitas por jornadas automáticas ficam separadas.') ?></h2></div></div>
            <dl class="rel-lista">
                <div><dt>Mudanças de etapa</dt><dd><?= dash_num($funil['transicoes']) ?></dd></div>
                <div><dt>Avanços</dt><dd><?= dash_num($funil['avancos']) ?></dd></div>
                <div><dt>Retrocessos</dt><dd><?= dash_num($funil['retrocessos']) ?></dd></div>
                <div><dt>Feitas por pessoas</dt><dd><?= dash_num($funil['por_origem']['usuario'] ?? 0) ?></dd></div>
                <div><dt>Feitas por jornadas</dt><dd><?= dash_num($funil['por_origem']['jornada'] ?? 0) ?></dd></div>
            </dl>
        </div>
        <div class="card">
            <div class="card-head"><div><h2>Perdas por inatividade <?= dash_ajuda('Leads retirados do corretor pela recuperação automática por falta de feedback, pela estrutura de quem perdeu.') ?></h2></div>
                <a class="link-btn" href="/pages/relatorios.php">Detalhar</a></div>
            <dl class="rel-lista">
                <div><dt>Perdidos</dt><dd><?= e(relui_valor($perdas['perdidos_inatividade']['valor'])) ?></dd></div>
                <div><dt>Já redistribuídos</dt><dd><?= e(relui_valor($perdas['redistribuidos']['valor'])) ?></dd></div>
                <div><dt>Tempo até a redistribuição (mediana)</dt><dd><?= e(relui_valor($perdas['tempo_redistribuicao']['valor'], 'h')) ?></dd></div>
            </dl>
        </div>
    </div>
<?php
// ============================================================================ CORRETOR
elseif ($rel === 'corretor'):
    $dims = $ehCorretor ? ['empreendimento', 'origem'] : ['corretor', 'empreendimento', 'origem', 'roleta'];
    $metrica = in_array($pub['metrica'] ?? '', ['recebimentos', 'contatos_efetivos', 'contratos_validados'], true) ? $pub['metrica'] : 'recebimentos';
    $manter = ['gran' => $pub['gran'] ?? null, 'metrica' => $pub['metrica'] ?? null];
    if ($ehCorretor) {
        unset($pub['corretor']);
    }
    relui_filtros($rel, $usuario, $f, $pub, $op, $dims, $manter);
    if ($ehCorretor) {
        $pub['corretor'] = (string) $usuario['id'];
    }
    $cid = (int) ($entrada['corretor'] ?? 0);
    $alcance = $ehCorretor || in_array($cid, array_map('intval', array_column($op['corretores'], 'id')), true);
    if ($cid && !$alcance):
        ?>
        <div class="card stub-card"><div class="stub-icon"><?= icon('lock') ?></div><h2>Corretor fora do seu alcance</h2>
            <p>Escolha um corretor da sua estrutura no filtro acima.</p></div>
        <?php
    elseif (!$cid):
        ?>
        <div class="card stub-card"><div class="stub-icon"><?= icon('user') ?></div><h2>Escolha um corretor</h2>
            <p>Use o filtro <b>Corretor</b> acima. A lista mostra só quem está na sua estrutura.</p></div>
        <?php
    else:
        $nome = (string) db()->query('SELECT nome FROM usuarios WHERE id = ' . $cid . ' AND empresa_id = ' . (int) $usuario['empresa_ativa_id'])->fetchColumn();
        $linha = relatorio_agrupado($usuario, $f, 'corretor')[0] ?? null;
        $fa = $f;
        $fa['periodo'] = $f['periodo']['anterior'] ?? $f['periodo'];
        $antes = !empty($f['erro']) ? null : (relatorio_agrupado($usuario, $fa, 'corretor')[0] ?? null);
        $vis = relatorio_visitas_do_corretor($usuario, $f, $cid);
        $con = relatorio_contratos($usuario, $f);
        $serie = relatorio_serie($usuario, $f, $metrica, $gran);
        $z = ['leads_recebidos' => 0, 'recebimentos' => 0, 'atendidos' => 0, 'contato_efetivo' => 0, 'taxa_atendimento' => null, 'taxa_contato_efetivo' => null,
              'tempo_primeiro_atendimento_min' => null, 'perdidos_inatividade' => 0, 'pela_roleta' => 0, 'por_redistribuicao' => 0, 'outras_formas' => 0, 'reconstruidas' => 0];
        $L = ($linha ?? []) + $z;
        $A = $antes;
        $varQ = function (string $k, bool $taxa = false, bool $menor = false) use ($L, $A) {
            if ($A === null || $L[$k] === null || $A[$k] === null) {
                return ['sem base de comparação', ''];
            }
            $ind = ['valor' => $L[$k], 'anterior' => $A[$k], 'unidade' => $taxa ? '%' : ''];
            if ($taxa) {
                $ind['variacao'] = round($L[$k] - $A[$k], 1);
                $ind['variacao_unidade'] = 'p.p.';
            } elseif ($A[$k] > 0) {
                $ind['variacao'] = round(($L[$k] - $A[$k]) * 100 / $A[$k], 1);
            } else {
                $ind['variacao'] = null;
            }
            return relui_variacao($ind, $menor);
        };
        relui_cobertura($cob);
        ?>
        <h2 class="rel-subtitulo"><?= icon('user') ?><?= e($nome ?: 'Corretor') ?></h2>
        <div class="kpi-grid kpi-grid-4 dash-kpis">
            <?php
            relui_kpi('Leads recebidos', dash_num($L['leads_recebidos']), 'Leads entregues a este corretor no período (roleta, cadastro, transferência, oferta ativa, redistribuição) — mesmo que depois tenham saído da carteira dele.', 'user', $varQ('leads_recebidos'),
                dash_num($L['pela_roleta']) . ' pela roleta · ' . dash_num($L['por_redistribuicao']) . ' redistribuídos · ' . dash_num($L['outras_formas']) . ' outras formas');
            relui_kpi('Taxa de atendimento', relui_valor($L['taxa_atendimento'], '%'), 'Dos leads recebidos, em quantos ele registrou uma atuação válida (atendimento, etapa, visita, pendência, WhatsApp real).', 'check', $varQ('taxa_atendimento', true), relui_qtd($L['atendidos'], 'atendido', 'atendidos'));
            relui_kpi('Taxa de contato efetivo', relui_valor($L['taxa_contato_efetivo'], '%'), 'Dos leads recebidos, em quantos o cliente foi efetivamente alcançado.', 'phone', $varQ('taxa_contato_efetivo', true), relui_qtd($L['contato_efetivo'], 'lead', 'leads') . ' com contato');
            relui_kpi('Tempo até o 1º atendimento', relui_valor($L['tempo_primeiro_atendimento_min'], 'min'), 'Mediana entre receber o lead e a primeira atuação. Menor é melhor.', 'clock', $varQ('tempo_primeiro_atendimento_min', false, true));
            relui_kpi('Visitas realizadas', dash_num($vis['realizadas']), 'Visitas que este corretor conduziu e concluiu como realizadas no período.', 'building', ['', ''], relui_qtd($vis['agendadas'], 'agendada', 'agendadas') . ' · ' . dash_num($vis['ausentes']) . ' com cliente ausente');
            relui_kpi('Contratos validados', relui_valor($con['contratos_validados']['valor']), 'Contratos dele registrados e validados (data da assinatura).', 'target', ['', ''], dash_num($con['contratos_aguardando']['valor']) . ' aguardando validação');
            relui_kpi('VGV validado', relui_valor($con['vgv']['valor'], 'R$'), 'Soma do valor dos contratos validados dele. VGV não é receita.', 'file', ['', ''], $con['vgv']['nota']);
            relui_kpi('Perdidos por inatividade', dash_num($L['perdidos_inatividade']), 'Leads recebidos no período que a recuperação automática retirou dele por falta de feedback. Menor é melhor.', 'alert', $varQ('perdidos_inatividade', false, true));
            ?>
        </div>
        <div class="card">
            <div class="card-head"><div><h2>Evolução</h2><p><?= e(relatorio_metricas_serie()[$metrica]) ?></p></div>
                <?php relui_segmentos(relatorio_granularidades(), $gran, fn ($k) => $url(['gran' => $k] + ($ehCorretor ? ['corretor' => null] : [])), 'Agrupar a evolução'); ?></div>
            <?php relui_segmentos(['recebimentos' => 'Leads recebidos', 'contatos_efetivos' => 'Contatos efetivos', 'contratos_validados' => 'Contratos'],
                $metrica, fn ($k) => $url(['metrica' => $k] + ($ehCorretor ? ['corretor' => null] : [])), 'Indicador da evolução'); ?>
            <?php relui_grafico_linha($serie, relatorio_metricas_serie()[$metrica], $metrica === 'contratos_validados' ? 'contratos' : 'leads'); ?>
        </div>
    <?php endif;
// ============================================================================ GESTÃO
elseif ($rel === 'gestao'):
    $esc = $usuario['escopo'];
    $agrupamentos = array_map(fn ($a) => $a[1], relatorio_agrupamentos());
    if ($esc === 'equipe') {
        unset($agrupamentos['superintendencia'], $agrupamentos['gerente']);
    } elseif ($esc === 'superintendencia') {
        unset($agrupamentos['superintendencia']);
    }
    $padrao = $esc === 'equipe' ? 'corretor' : ($esc === 'superintendencia' ? 'equipe' : 'superintendencia');
    $agrupar = isset($agrupamentos[$pub['agrupar'] ?? '']) ? $pub['agrupar'] : $padrao;
    $criterio = isset(relatorio_criterios_ranking()[$pub['criterio'] ?? '']) ? $pub['criterio'] : 'taxa_contato_efetivo';
    $base = 5;
    $dims = ['empreendimento', 'superintendencia', 'gerente', 'equipe', 'roleta', 'origem'];
    relui_filtros($rel, $usuario, $f, $pub, $op, $dims, ['agrupar' => $agrupar, 'criterio' => $criterio]);
    relui_cobertura($cob);
    $linhas = relatorio_agrupado($usuario, $f, $agrupar);
    $rk = relatorio_ranking($linhas, $criterio, $base);
    $posicao = [];
    foreach ($rk['ranking'] as $r) {
        $posicao[(string) $r['id']] = $r['posicao'];
    }
    $tot = ['leads_recebidos' => 0, 'atendidos' => 0, 'contato_efetivo' => 0, 'perdidos_inatividade' => 0, 'contratos_validados' => 0, 'vgv' => null];
    foreach ($linhas as $l) {
        foreach (['leads_recebidos', 'atendidos', 'contato_efetivo', 'perdidos_inatividade', 'contratos_validados'] as $k) {
            $tot[$k] += $l[$k];
        }
        if ($l['vgv'] !== null) {
            $tot['vgv'] = ($tot['vgv'] ?? 0) + $l['vgv'];
        }
    }
    $proximo = ['superintendencia' => 'gerente', 'gerente' => 'equipe', 'equipe' => 'corretor'];
    $detalhe = function (array $l) use ($agrupar, $proximo, $pub) {
        if ($l['id'] === null) {
            return null;
        }
        if ($agrupar === 'corretor') {
            return relui_url('corretor', array_intersect_key($pub, array_flip(['periodo', 'ini', 'fim', 'empreendimento', 'origem'])), ['corretor' => $l['id']]);
        }
        if ($agrupar === 'empreendimento') {
            return relui_url('executiva', array_intersect_key($pub, array_flip(['periodo', 'ini', 'fim'])), ['empreendimento' => $l['id']]);
        }
        return relui_url('gestao', $pub, [$agrupar => $l['id'], 'agrupar' => $proximo[$agrupar]]);
    };
    $cols = [['Posição', 'num'], [$agrupamentos[$agrupar], 'texto'], ['Leads recebidos', 'num'], ['Atendidos', 'num'], ['Taxa de atendimento', 'num'],
             ['Contato efetivo', 'num'], ['Taxa de contato efetivo', 'num'], ['Tempo até o 1º atendimento', 'num'], ['Perdidos (inatividade)', 'num'], ['Contratos validados', 'num'], ['VGV validado', 'num']];
    ?>
    <div class="card dash-section">
        <div class="card-head">
            <div><h2>Comparativo por <?= e(mb_strtolower($agrupamentos[$agrupar])) ?> <?= dash_ajuda('Cada linha mostra o que foi ENTREGUE no período a quem estava naquela estrutura na data da entrega — não a carteira de hoje. Clique no nome para detalhar o nível de baixo.') ?></h2>
                <p>Agrupar por:</p></div>
        </div>
        <?php relui_segmentos($agrupamentos, $agrupar, fn ($k) => $url(['agrupar' => $k]), 'Agrupar por'); ?>
        <form method="get" action="/pages/relatorio.php" class="rel-criterio">
            <?php foreach (['r' => 'gestao'] + $pub as $k => $v): if ($k === 'criterio') continue; foreach ((array) $v as $vv): ?><input type="hidden" name="<?= e($k) . (is_array($v) ? '[]' : '') ?>" value="<?= e((string) $vv) ?>"><?php endforeach; endforeach; ?>
            <label for="rel-criterio">Ranking por</label>
            <select class="select-filter" id="rel-criterio" name="criterio" onchange="this.form.submit()">
                <?php foreach (relatorio_criterios_ranking() as $k => [$r]): ?><option value="<?= e($k) ?>" <?= $k === $criterio ? 'selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?>
            </select>
            <noscript><button class="btn btn-outline btn-sm" type="submit">Aplicar</button></noscript>
            <?php if (relatorio_criterios_ranking()[$criterio][2]): ?><span class="muted">Taxas e tempos só entram no ranking com pelo menos <?= $base ?> leads recebidos.</span><?php endif; ?>
        </form>
        <?php if (!$linhas): ?>
            <p class="empty-note">Nenhuma entrega de lead no período para esta seleção.</p>
        <?php else: ?>
            <div class="table-wrap dash-table-wrap">
                <table class="report-table dash-table" id="tabela-gestao" data-ordenavel>
                    <thead><tr><?php foreach ($cols as $i => [$r, $t]): ?><th scope="col" class="<?= $t === 'texto' ? '' : 'num' ?>" aria-sort="none"><button type="button" data-ordenar="<?= $i ?>" data-tipo="<?= $t ?>"><?= e($r) ?></button></th><?php endforeach; ?></tr></thead>
                    <tbody>
                        <?php foreach ($linhas as $l): $pos = $posicao[(string) $l['id']] ?? null; $link = $detalhe($l); ?>
                            <tr>
                                <td class="num" data-sort="<?= $pos ?? 9999 ?>" data-rotulo="Posição"><?= $pos ? $pos . 'º' : '<span class="muted" title="Sem base mínima para este critério">—</span>' ?></td>
                                <td class="nome" data-sort="<?= e($l['nome']) ?>" data-rotulo="<?= e($agrupamentos[$agrupar]) ?>"><?php if ($link): ?><a href="<?= e($link) ?>"><?= e($l['nome']) ?></a><?php else: ?><?= e($l['nome']) ?><?php endif; ?><?php if ($l['reconstruidas']): ?> <span class="pill pill-muted" title="Parte das entregas foi reconstruída de registros anteriores">reconstruído</span><?php endif; ?></td>
                                <?php foreach ([['leads_recebidos', ''], ['atendidos', ''], ['taxa_atendimento', '%'], ['contato_efetivo', ''], ['taxa_contato_efetivo', '%'], ['tempo_primeiro_atendimento_min', 'min'], ['perdidos_inatividade', ''], ['contratos_validados', ''], ['vgv', 'R$']] as [$k, $u]): ?>
                                    <td class="num<?= $k === $criterio ? ' rel-destaque' : '' ?>" data-sort="<?= $l[$k] === null ? -1 : $l[$k] ?>" data-rotulo="<?= e($cols[array_search($k, ['leads_recebidos', 'atendidos', 'taxa_atendimento', 'contato_efetivo', 'taxa_contato_efetivo', 'tempo_primeiro_atendimento_min', 'perdidos_inatividade', 'contratos_validados', 'vgv'], true) + 2][0]) ?>"><?= e(relui_valor($l[$k], $u)) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr>
                        <td></td><td class="nome">Total</td>
                        <td class="num"><?= dash_num($tot['leads_recebidos']) ?></td><td class="num"><?= dash_num($tot['atendidos']) ?></td>
                        <td class="num"><?= e(relui_valor(motor_taxa($tot['atendidos'], $tot['leads_recebidos']), '%')) ?></td>
                        <td class="num"><?= dash_num($tot['contato_efetivo']) ?></td>
                        <td class="num"><?= e(relui_valor(motor_taxa($tot['contato_efetivo'], $tot['leads_recebidos']), '%')) ?></td>
                        <td class="num">—</td><td class="num"><?= dash_num($tot['perdidos_inatividade']) ?></td>
                        <td class="num"><?= dash_num($tot['contratos_validados']) ?></td><td class="num"><?= e(relui_valor($tot['vgv'], 'R$')) ?></td>
                    </tr></tfoot>
                </table>
            </div>
            <p class="muted rel-rodape">Totais de taxa calculados sobre a soma (não a média das linhas). Um lead transferido no período conta para cada estrutura que o recebeu.
                <?php if ($rk['sem_base']): ?> Fora do ranking por falta de base ou de dado: <?= e(implode(', ', array_map(fn ($l) => $l['nome'], $rk['sem_base']))) ?>.<?php endif; ?></p>
        <?php endif; ?>
    </div>
<?php
// ============================================================================ EMPREENDIMENTOS
else:
    $todos = $op['empreendimentos'];
    $nomes = array_column($todos, 'nome', 'id');
    $sel = array_values(array_unique(array_filter(array_map('intval', (array) ($pub['emp'] ?? [])), fn ($id) => isset($nomes[$id]))));
    $dims = ['superintendencia', 'equipe', 'origem'];
    relui_filtros($rel, $usuario, $f, $pub, $op, $dims, ['emp' => $sel]);
    ?>
    <div class="card">
        <div class="card-head"><div><h2>Quais empreendimentos comparar?</h2><p>Escolha de 2 a 6. Os mesmos filtros e o mesmo período valem para todos.</p></div></div>
        <form method="get" action="/pages/relatorio.php" class="rel-emp-escolha">
            <input type="hidden" name="r" value="empreendimentos">
            <?php foreach ($pub as $k => $v): if ($k === 'emp') continue; ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>"><?php endforeach; ?>
            <div class="rel-emp-lista">
                <?php foreach ($todos as $em): ?>
                    <label class="checkbox-row"><input type="checkbox" name="emp[]" value="<?= (int) $em['id'] ?>" <?= in_array((int) $em['id'], $sel, true) ? 'checked' : '' ?>> <?= e($em['nome']) ?></label>
                <?php endforeach; ?>
                <?php if (!$todos): ?><p class="empty-note">Nenhum empreendimento ao seu alcance.</p><?php endif; ?>
            </div>
            <button type="submit" class="btn btn-primary btn-sm"><?= icon('check') ?>Comparar</button>
        </form>
    </div>
    <?php
    if (count($sel) < 2):
        echo '<p class="empty-note">Marque pelo menos 2 empreendimentos para comparar.</p>';
    else:
        $sel = array_slice($sel, 0, 6);
        $res = [];
        foreach ($sel as $id) {
            $fi = relatorio_filtros($usuario, $entrada + ['empreendimento' => [$id]]);
            $res[$id] = relatorio_leads($usuario, $fi) + relatorio_visitas($usuario, $fi) + relatorio_contratos($usuario, $fi);
        }
        $linhasInd = [
            ['leads_recebidos', 'Leads recebidos', false, false],
            ['leads_distribuidos', 'Chegaram a um corretor', false, false],
            ['taxa_primeiro_contato', 'Taxa de atendimento', false, true],
            ['taxa_contato_efetivo', 'Taxa de contato efetivo', false, true],
            ['tempo_primeiro_contato', 'Tempo até o 1º atendimento', true, true],
            ['visitas_agendadas', 'Visitas agendadas', false, false],
            ['visitas_realizadas', 'Visitas realizadas', false, false],
            ['taxa_comparecimento', 'Comparecimento', false, true],
            ['contratos_validados', 'Contratos validados', false, false],
            ['vgv', 'VGV validado', false, false],
        ];
        ?>
        <div class="card dash-section">
            <div class="card-head"><div><h2>Lado a lado <?= dash_ajuda('O melhor valor de cada linha fica destacado. Taxas e tempos só são destacados quando o empreendimento tem pelo menos 5 leads distribuídos.') ?></h2>
                <p><?= e($f['periodo']['rotulo'] ?? '') ?></p></div></div>
            <div class="table-wrap dash-table-wrap">
                <table class="report-table dash-table rel-comparar">
                    <thead><tr><th scope="col">Indicador</th><?php foreach ($sel as $id): ?><th scope="col" class="num"><?= e($nomes[$id]) ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                        <?php foreach ($linhasInd as [$k, $rot, $menor, $exigeBase]):
                            $vals = [];
                            foreach ($sel as $id) {
                                $v = $res[$id][$k]['valor'];
                                if ($v !== null && (!$exigeBase || $res[$id]['leads_distribuidos']['valor'] >= 5)) {
                                    $vals[$id] = $v;
                                }
                            }
                            $melhor = count($vals) >= 2 && count(array_unique($vals)) > 1 ? ($menor ? min($vals) : max($vals)) : null;
                            ?>
                            <tr><th scope="row" class="nome"><?= e($rot) ?></th>
                                <?php foreach ($sel as $id): $v = $res[$id][$k]['valor']; $isMelhor = $melhor !== null && isset($vals[$id]) && $vals[$id] == $melhor; ?>
                                    <td class="num<?= $isMelhor ? ' rel-melhor' : '' ?>" data-rotulo="<?= e($nomes[$id]) ?>"><?= e(relui_valor($v, $res[$id][$k]['unidade'] ?? '')) ?><?= $isMelhor ? ' <span class="sr-only">(melhor)</span>' : '' ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card">
            <div class="card-head"><div><h2>Leads recebidos por empreendimento</h2></div></div>
            <?php dash_barras_horizontais(array_map(fn ($id) => ['rotulo' => $nomes[$id], 'total' => (int) $res[$id]['leads_recebidos']['valor']], $sel), 'Leads recebidos por empreendimento', 6); ?>
        </div>
    <?php endif;
endif;
?>
<script src="/assets/js/relatorios.js" defer></script>
<?php require __DIR__ . '/../includes/layout_end.php'; ?>
