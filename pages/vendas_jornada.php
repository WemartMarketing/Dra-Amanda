<?php
/**
 * Inteligência de Vendas → Jornada do Cliente: da 1ª entrada no CRM até a
 * venda, só com eventos registrados. Visível apenas a quem pode consultar
 * hoje os leads do cliente (regra de acesso vigente); o restante fica oculto.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/estrutura.php';
require_once __DIR__ . '/../includes/lead_componentes.php';
require_once __DIR__ . '/../includes/criativo_ui.php';
require_once __DIR__ . '/../includes/vendas/jornada.php';
require_once __DIR__ . '/../includes/vendas/exportar_ui.php';

$usuario = auth_require();
$crumb = 'Jornada do Cliente';
$activeNav = 'relatorios';
if (!empty($usuario['eh_superadmin']) || !usuario_tem_permissao($usuario, 'ver_inteligencia_vendas')) {
    pagina_acesso_restrito($crumb, $activeNav, 'Ver a Inteligência de Vendas');
}
$J = vendas_cruzamento_instalado() ? vendas_jornada($usuario, (int) ($_GET['id'] ?? 0)) : null;
if (!$J) {
    http_response_code(404);
}
$brl = fn ($v) => $v === null ? '—' : 'R$ ' . number_format((float) $v, 2, ',', '.');
$data = fn (?string $d) => $d ? date('d/m/Y', strtotime($d)) : '—';
$dias = fn ($n) => $n === null ? '—' : $n . ' dia' . ((int) $n === 1 ? '' : 's');
$cls = vendas_classificacoes_comerciais();
$cats = funil_categorias();
$icone = ['entrada' => 'user', 'anuncio' => 'anuncio', 'oferta' => 'list', 'distribuicao' => 'users', 'atendimento' => 'user', 'contato_efetivo' => 'check', 'tentativa' => 'phone',
          'contato' => 'note', 'transferencia' => 'move', 'etapa' => 'kanban', 'visita_agendada' => 'calendar', 'visita' => 'pin', 'visita_outra' => 'calendar',
          'whatsapp_cliente' => 'whatsapp', 'whatsapp_corretor' => 'whatsapp', 'remarketing' => 'zap', 'automacao' => 'zap', 'contrato' => 'file', 'venda' => 'star'];
$natur = ['confirmado' => ['pill-green', 'Registrado'], 'importado' => ['pill-blue', 'Importado'], 'inferido' => ['pill-amber', 'Reconstruído']];

$pageTitle = 'Jornada do Cliente — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div>
        <a class="back-link" href="/pages/vendas_conciliacao.php"><?= icon('arrow-r') ?>Voltar para a conciliação</a>
        <div class="eyebrow">Inteligência de Vendas · Jornada do Cliente</div>
        <?php if ($J): $v = $J['venda']; ?>
            <h1><?= e($v['cliente_nome'] ?: 'Venda ' . $v['chave_origem']) ?></h1>
            <p>Comprou <b><?= e($J['movimento']['comprado_nome'] ?? $v['empreendimento_texto'] ?? '—') ?></b><?= $v['unidade'] ? ' (unidade ' . e($v['unidade']) . ')' : '' ?> em <b><?= $data($v['data_venda']) ?></b> por <?= e($brl($v['valor'])) ?> · venda <?= e($v['chave_origem']) ?> no sistema comercial</p>
        <?php else: ?><h1>Venda não encontrada</h1><?php endif; ?>
    </div>
    <?php if ($J): ?><div class="actions"><?php if ($J['acesso'] === 'ok') { vendas_exportar_botao($usuario, 'jornada', ['venda' => (int) $J['venda']['id']]); } ?><a class="btn btn-outline" href="/pages/vendas_revisao.php?id=<?= (int) $J['venda']['id'] ?>"><?= icon('search') ?>Ver correspondência</a></div><?php endif; ?>
</div>

<?php if (!$J): ?>
    <div class="card stub-card"><div class="stub-icon"><?= icon('alert') ?></div><h2>Venda não encontrada</h2><p>Ela não existe ou é de outra empresa.</p></div>
<?php elseif ($J['acesso'] === 'sem_vinculo'): ?>
    <div class="card stub-card"><div class="stub-icon"><?= icon('info') ?></div><h2>Sem jornada no CRM</h2>
        <p><?= vendas_situacao_efetiva($J['corr']) === 'ambigua' ? 'A correspondência desta venda está pendente de revisão. Confirme o vínculo para ver a jornada.' : 'Esta venda não está ligada a nenhum lead do CRM. Não há eventos a mostrar — nada é inventado.' ?></p>
        <a class="btn btn-primary" href="/pages/vendas_revisao.php?id=<?= (int) $J['venda']['id'] ?>">Ver correspondência</a></div>
<?php elseif ($J['acesso'] === 'sem_permissao'): ?>
    <div class="card stub-card"><div class="stub-icon"><?= icon('lock') ?></div><h2>Fora da sua carteira</h2><p>Os leads deste cliente não estão na sua carteira atual. A jornada individual só aparece para quem pode consultar o cliente hoje; os números agregados continuam nos relatórios.</p></div>
<?php else: $m = $J['metricas']; $mov = $J['movimento']; [$clsRot, $clsPill] = $cls[$J['classificacao']] ?? [$J['classificacao'], 'pill-muted']; ?>

    <?php if ($J['incompleto'] || $J['ocultos']): ?>
        <div class="rel-nota jv-incompleto" role="note"><?= icon('info') ?><div><b>Histórico incompleto — os números abaixo usam só o que está registrado.</b>
            <ul><?php foreach ($J['incompleto'] as $t): ?><li><?= e($t) ?></li><?php endforeach; ?>
                <?php if ($J['ocultos']): ?><li><?= (int) $J['ocultos'] ?> oportunidade(s) deste cliente estão fora da sua carteira atual e não aparecem aqui.</li><?php endif; ?></ul></div></div>
    <?php endif; ?>

    <div class="kpi-grid vendas-kpis jv-kpis">
        <div class="kpi"><div class="kpi-top"><span class="label">Já estava na base?</span><div class="kpi-icon"><?= icon('user') ?></div></div>
            <div class="kpi-value <?= $J['corr']['cronologia'] === 'posterior' ? '' : 'txt-ok' ?>"><?= $J['corr']['cronologia'] === 'posterior' ? 'Não' : 'Sim' ?></div>
            <div class="kpi-sub">desde <?= $data($m['primeira_entrada']) ?> · <?= (int) $m['oportunidades'] ?> oportunidade(s)</div></div>
        <div class="kpi"><div class="kpi-top"><span class="label">Tempo até a compra</span><div class="kpi-icon"><?= icon('clock') ?></div></div>
            <div class="kpi-value"><?= $m['dias_jornada'] ?? '—' ?></div><div class="kpi-sub">dias da 1ª entrada à venda</div></div>
        <div class="kpi"><div class="kpi-top"><span class="label">Corretores</span><div class="kpi-icon"><?= icon('users') ?></div></div>
            <div class="kpi-value"><?= count(array_unique(array_column(array_filter($J['corretores'], fn ($c) => !$c['depois_da_venda']), 'usuario_id'))) ?: '—' ?></div>
            <div class="kpi-sub"><?= (int) $m['transferencias'] ?> transferência(s) · <?= (int) $m['redistribuicoes_inatividade'] ?> por inatividade</div></div>
        <div class="kpi"><div class="kpi-top"><span class="label">Remarketing antes</span><div class="kpi-icon"><?= icon('zap') ?></div></div>
            <div class="kpi-value"><?= (int) $m['campanhas_remarketing'] ?></div><div class="kpi-sub">campanha(s) · <?= (int) $m['jornadas_automaticas'] ?> jornada(s) automática(s)</div></div>
        <div class="kpi"><div class="kpi-top"><span class="label">Oferta ativa</span><div class="kpi-icon"><?= icon('list') ?></div></div>
            <div class="kpi-value"><?= $m['oferta_ativa'] === 'sim' ? 'Sim' : '—' ?></div><div class="kpi-sub"><?= $m['oferta_ativa'] === 'sim' ? 'passou por lista de oferta ativa' : 'sem registro (não presumido)' ?></div></div>
        <div class="kpi"><div class="kpi-top"><span class="label">Mesmo empreendimento?</span><div class="kpi-icon"><?= icon('building') ?></div></div>
            <div class="kpi-value"><?= ['mesmo' => 'Sim', 'diferente' => 'Não', 'sem_equivalencia' => '—', 'sem_interesse' => '—'][$mov['resultado']] ?></div>
            <div class="kpi-sub"><?= ['mesmo' => 'comprou o do 1º interesse', 'diferente' => 'comprou outro empreendimento', 'sem_equivalencia' => 'cadastre a equivalência', 'sem_interesse' => 'lead sem empreendimento'][$mov['resultado']] ?></div></div>
    </div>

    <div class="grid-2">
        <section class="card">
            <div class="card-head"><div><h2>Entrada × compra</h2><p>Todos os interesses registrados, em ordem. Nenhum é eleito como "responsável" pela venda.</p></div></div>
            <ol class="jv-fluxo">
                <?php foreach ($J['empreendimentos'] as $i => $e): $depois = $J['venda']['data_venda'] && substr($e['entrada_em'], 0, 10) > substr($J['venda']['data_venda'], 0, 10); ?>
                    <li class="<?= $depois ? 'is-depois' : '' ?>"><span class="jv-fluxo-rot"><?= $i === 0 ? '1º interesse' : ($depois ? 'Depois da venda' : 'Interesse') ?> · <?= $data($e['entrada_em']) ?></span><b><?= e($e['nome'] ?? 'Sem empreendimento informado') ?></b></li>
                <?php endforeach; ?>
                <li class="is-compra"><span class="jv-fluxo-rot">Comprou · <?= $data($J['venda']['data_venda']) ?></span><b><?= e($mov['comprado_nome'] ?? '—') ?></b><?= $J['venda']['empreendimento_id'] ? '' : ' <small class="muted">(sem equivalência no CRM)</small>' ?></li>
            </ol>
            <p class="jv-resultado"><span class="pill <?= ['mesmo' => 'pill-green', 'diferente' => 'pill-blue'][$mov['resultado']] ?? 'pill-muted' ?>"><?= e(['mesmo' => 'Comprou o mesmo empreendimento do 1º interesse', 'diferente' => 'Conversão em empreendimento diferente do interesse inicial', 'sem_equivalencia' => 'Comparação indisponível: empreendimento comprado sem equivalência', 'sem_interesse' => 'Comparação indisponível: interesse inicial não registrado'][$mov['resultado']]) ?></span>
                <?= $mov['resultado'] === 'diferente' && $mov['comprado_entre_interesses'] ? '<small class="muted">O comprado estava entre os interesses registrados.</small>' : '' ?></p>
        </section>

        <section class="card">
            <div class="card-head"><div><h2>De onde veio</h2><p>Duas informações diferentes, mostradas lado a lado — uma não substitui a outra.</p></div></div>
            <?php $o1 = $J['origens'][0]; $antes = array_values(array_filter($J['origens'], fn ($o) => $o['antes_da_venda'])); $ult = $antes ? end($antes) : null; ?>
            <dl class="vendas-dl">
                <dt>Origem no CRM (1ª conhecida)</dt><dd><?= e($o1['origem']) ?><?= $o1['campanha'] ? ' · ' . e($o1['campanha']) : '' ?> <small class="muted"><?= $data($o1['entrada_em']) ?></small></dd>
                <?php if ($ult && $ult['lead_id'] !== $o1['lead_id']): ?><dt>Última origem antes da compra</dt><dd><?= e($ult['origem']) ?><?= $ult['campanha'] ? ' · ' . e($ult['campanha']) : '' ?> <small class="muted"><?= $data($ult['entrada_em']) ?></small></dd><?php endif; ?>
                <?php if ($o1['utm']): ?><dt>UTMs</dt><dd><?= e(implode(' · ', array_map(fn ($k, $x) => $k . '=' . $x, array_keys($o1['utm']), $o1['utm']))) ?></dd><?php endif; ?>
                <dt>Mídia declarada na venda</dt><dd><?= e($J['venda']['midia_texto'] ?? '—') ?> <small class="muted">(sistema comercial)</small></dd>
            </dl>
            <p class="muted jv-nota">Origem diferente da mídia declarada não indica erro nem fraude: são registros de sistemas e momentos diferentes. Um anúncio no histórico mostra associação no tempo, não causa da compra.</p>
        </section>
    </div>

    <?php if ($J['anuncio'] && ($J['anuncio']['tem_anuncio'] || $J['anuncio']['campanha'])): render_criativo_card($J['anuncio']); elseif ($o1['canal'] === 'meta_ads'): ?>
        <div class="rel-nota" role="note"><?= icon('anuncio') ?><span>Anúncio de origem não disponível: o lead não tem o anúncio registrado.</span></div>
    <?php endif; ?>

    <section class="card">
        <div class="card-head"><div><h2>Quem atendeu</h2><p>Responsáveis do CRM por oportunidade, com o tempo de responsabilidade até a venda. Não atribui crédito, comissão nem culpa.</p></div>
            <span class="pill <?= $clsPill ?>"><?= e($clsRot) ?></span></div>
        <div class="grid-2 jv-vendedor">
            <dl class="vendas-dl">
                <dt>Corretor da venda (sistema comercial)</dt><dd><?= e($J['venda']['corretor_texto'] ?? '—') ?><?= $J['venda']['corretor_id_origem'] ? ' <small class="muted">id ' . e($J['venda']['corretor_id_origem']) . '</small>' : '' ?></dd>
                <dt>Primeiro corretor no CRM</dt><dd><?= e($m['primeiro_corretor'] ?? 'Não disponível') ?></dd>
                <dt>Responsável(is) na data da venda</dt><dd><?= e(implode(', ', array_unique(array_column($J['responsaveis_na_venda'], 'nome'))) ?: 'Sem histórico') ?></dd>
            </dl>
            <dl class="vendas-dl">
                <dt>Responsável atual</dt><dd><?= e(implode(', ', array_unique(array_filter(array_column($J['leads'], 'resp_nome')))) ?: 'Sem responsável') ?></dd>
                <dt>Gerentes envolvidos</dt><dd><?= e(implode(', ', $J['gerentes']) ?: '—') ?></dd>
                <dt>Superintendências</dt><dd><?= e(implode(', ', $J['superintendencias']) ?: '—') ?></dd>
            </dl>
        </div>
        <?php if ($J['corretores']): ?>
            <div class="table-wrap"><table class="report-table">
                <thead><tr><th scope="col">Corretor</th><th scope="col">Oportunidade</th><th scope="col">Equipe / gerente</th><th scope="col">Como recebeu</th><th scope="col">De</th><th scope="col">Até</th><th scope="col" class="num">Dias até a venda</th></tr></thead>
                <tbody>
                <?php foreach ($J['corretores'] as $c): ?>
                    <tr class="<?= $c['depois_da_venda'] ? 'jv-depois' : '' ?>">
                        <td data-rotulo="Corretor"><?= e($c['nome']) ?><?= $c['reconstruido'] ? ' <span class="pill pill-amber">Reconstruído</span>' : '' ?></td>
                        <td data-rotulo="Oportunidade"><?= e($J['leads'][$c['lead_id']]['emp_nome'] ?? 'Lead #' . $c['lead_id']) ?></td>
                        <td data-rotulo="Equipe"><?= e(trim(($c['equipe'] ?? '—') . ($c['gerente'] ? ' · ' . $c['gerente'] : ''))) ?></td>
                        <td data-rotulo="Como recebeu"><?= e($c['forma']) ?></td>
                        <td data-rotulo="De"><?= $data($c['de']) ?></td>
                        <td data-rotulo="Até"><?= $c['ate'] ? $data($c['ate']) : 'atual' ?></td>
                        <td class="num" data-rotulo="Dias até a venda"><?= $c['depois_da_venda'] ? 'depois da venda' : (int) $c['dias_ate_venda'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php else: ?><p class="empty-note">Sem histórico de distribuição registrado.</p><?php endif; ?>
    </section>

    <section class="card">
        <div class="card-head"><div><h2>Tempos</h2><p>Três medidas diferentes: tempo decorrido (jornada), tempo sob responsabilidade e interação efetiva. Uma não substitui a outra.</p></div></div>
        <div class="grid-2">
            <dl class="vendas-dl">
                <dt>Jornada (1ª entrada → venda)</dt><dd><?= $dias($m['dias_jornada']) ?></dd>
                <dt>Até o 1º atendimento humano</dt><dd><?= $dias($m['dias_ate_primeiro_atendimento']) ?></dd>
                <dt>1º atendimento → venda</dt><dd><?= $dias($m['dias_primeiro_atendimento_ate_venda']) ?></dd>
                <dt>1ª visita realizada → venda</dt><dd><?= $dias($m['dias_primeira_visita_ate_venda']) ?></dd>
                <dt>Última interação → venda</dt><dd><?= $dias($m['dias_ultima_interacao_ate_venda']) ?></dd>
                <dt>Dias com interação efetiva</dt><dd><?= (int) $m['dias_com_interacao_efetiva'] ?> <small class="muted">(contato registrado, resposta do cliente ou visita)</small></dd>
                <dt>Tentativas e contatos</dt><dd><?= (int) $m['tentativas_contato'] ?> · <?= (int) $m['respostas_whatsapp'] ?> resposta(s) no WhatsApp · <?= (int) $m['visitas_realizadas'] ?> visita(s)</dd>
            </dl>
            <div>
                <?php if ($m['responsabilidade_por_corretor']): ?>
                    <div class="subhead">Sob responsabilidade de cada corretor</div>
                    <ul class="jv-barras">
                        <?php $max = max($m['responsabilidade_por_corretor']) ?: 1; foreach ($m['responsabilidade_por_corretor'] as $nome => $d): ?>
                            <li><span><?= e($nome) ?></span><span class="jv-barra" aria-hidden="true"><i style="width: <?= round(100 * $d / $max) ?>%"></i></span><b><?= (int) $d ?> d</b></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($m['inatividade']): ?>
                    <div class="subhead">Períodos sem interação (mais de <?= VENDAS_INATIVIDADE_DIAS ?> dias)</div>
                    <ul class="regra-lista"><?php foreach ($m['inatividade'] as $p): ?><li class="is-warn"><?= icon('clock') ?><span><?= $data($p['de']) ?> → <?= $data($p['ate']) ?>: <b><?= (int) $p['dias'] ?> dias</b></span></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($J['etapas']): ?>
            <div class="subhead">Tempo em cada etapa do Kanban (até a venda)</div>
            <div class="jv-etapas">
                <?php foreach ($J['etapas'] as $lid => $t): ?>
                    <div><span class="jv-fluxo-rot"><?= e($J['leads'][$lid]['emp_nome'] ?? 'Lead #' . $lid) ?></span>
                        <ul><?php foreach ($cats as $k => $r): if (!isset($t[$k])) continue; ?><li><span><?= e($r) ?></span><b><?= e(number_format($t[$k], 1, ',', '.')) ?> d</b></li><?php endforeach; ?></ul></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="card-head"><div><h2>Linha do tempo</h2><p>Só eventos gravados no CRM e a venda importada. Lacunas ficam como lacunas.</p></div>
            <div class="chips-filtro"><?php foreach ($natur as [$p, $r]): ?><span class="pill <?= $p ?>"><?= e($r) ?></span><?php endforeach; ?></div></div>
        <?php $porLead = count($J['leads']) > 1; $antesV = array_filter($J['eventos'], fn ($e) => !$e['depois_da_venda']); $depoisV = array_filter($J['eventos'], fn ($e) => $e['depois_da_venda']); ?>
        <ol class="jv-tl">
            <?php foreach ($antesV as $e): [$np, $nr] = $natur[$e['natureza']]; ?>
                <li class="jv-ev jv-<?= e($e['tipo']) ?>">
                    <span class="jv-ic"><?= icon($icone[$e['tipo']] ?? 'info') ?></span>
                    <div class="jv-corpo">
                        <div class="jv-topo"><time datetime="<?= e($e['em']) ?>"><?= $e['tipo'] === 'venda' ? $data($e['em']) : date('d/m/Y H:i', strtotime($e['em'])) ?></time>
                            <?php if ($e['natureza'] !== 'confirmado'): ?><span class="pill <?= $np ?>"><?= e($nr) ?></span><?php endif; ?>
                            <?php if ($porLead && $e['lead_id']): ?><span class="jv-lead"><?= e($J['leads'][$e['lead_id']]['emp_nome'] ?? 'Lead #' . $e['lead_id']) ?></span><?php endif; ?></div>
                        <b><?= e($e['titulo']) ?></b><?= $e['detalhe'] ? '<span class="jv-det">' . e($e['detalhe']) . '</span>' : '' ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php if ($depoisV): ?>
            <details class="jv-depois-bloco"><summary><?= count($depoisV) ?> evento(s) depois da venda</summary>
                <ol class="jv-tl"><?php foreach ($depoisV as $e): ?><li class="jv-ev"><span class="jv-ic"><?= icon($icone[$e['tipo']] ?? 'info') ?></span><div class="jv-corpo"><div class="jv-topo"><time><?= date('d/m/Y H:i', strtotime($e['em'])) ?></time></div><b><?= e($e['titulo']) ?></b><?= $e['detalhe'] ? '<span class="jv-det">' . e($e['detalhe']) . '</span>' : '' ?></div></li><?php endforeach; ?></ol>
            </details>
        <?php endif; ?>
        <p class="muted vendas-metodo"><b>Notas:</b> a 1ª entrada é a menor data entre o cadastro do lead e o formulário do anúncio. A venda não tem hora: no mesmo dia, ela aparece por último. Mensagens de WhatsApp são agrupadas por dia. "Reconstruído" = registrado depois, a partir de outros dados, e não observado na hora. Remarketing e anúncios mostram associação no tempo, não causa.</p>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/layout_end.php'; ?>
