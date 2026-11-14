<?php
/**
 * Telas dos relatórios especializados (Etapa 4), chamadas por
 * pages/relatorio.php. Só formatam o que vem de especializados.php/motor.php.
 */
require_once __DIR__ . '/especializados.php';
require_once __DIR__ . '/ui.php';

/** $c: ['rel','usuario','f','pub','op','cob','url' => callable] */
function relui_tela_especializada(array $c): void
{
    $fn = 'relui_tela_' . $c['rel'];
    $fn($c);
}

function relui_tela_funil(array $c): void
{
    ['rel' => $rel, 'usuario' => $u, 'f' => $f, 'pub' => $pub, 'op' => $op] = $c;
    relui_filtros($rel, $u, $f, $pub, $op, ['empreendimento', 'superintendencia', 'gerente', 'equipe', 'corretor', 'origem', 'canal']);
    relui_cobertura($c['cob']);
    $d = relatorio_funil_detalhe($u, $f);
    ?>
    <div class="kpi-grid kpi-grid-5 dash-kpis">
        <?php
        relui_kpi('Leads do período', dash_num($d['coorte']), 'Leads que entraram no período: é sobre eles o "onde estão" e o "até onde chegaram".', 'user');
        relui_kpi('Mudanças de etapa', dash_num($d['transicoes']), 'Transições registradas no período (de qualquer lead visível), feitas por pessoas, jornadas ou pelo sistema.', 'move');
        relui_kpi('Avanços', dash_num($d['avancos']), 'Transições para uma fase mais adiante do funil.', 'up');
        relui_kpi('Retrocessos', dash_num($d['retrocessos']), 'Transições para uma fase anterior do funil.', 'down');
        relui_kpi('Feitas por jornadas', dash_num($d['por_origem']['jornada'] ?? 0), 'Mudanças feitas por jornadas automáticas. Não contam como atendimento nem como contato efetivo.', 'zap');
        ?>
    </div>
    <div class="dash-grid">
        <div class="card dash-span-2">
            <div class="card-head"><div><h2>Até onde os leads do período chegaram <?= dash_ajuda('Funil de conversão da coorte: conta o lead em cada fase que ele JÁ alcançou (etapa atual ou passagem registrada), mesmo que depois tenha voltado ou saído. "% da fase anterior" mostra onde o funil afunila.') ?></h2>
                <p>Fase mais adiantada alcançada por cada lead que entrou no período.</p></div></div>
            <?php if (!$d['coorte']): ?><p class="empty-note">Nenhum lead entrou no período.</p><?php else: ?>
                <div class="funnel rel-alcance" role="list" aria-label="Alcance por fase do funil">
                    <?php foreach ($d['alcance'] as $a): ?>
                        <div class="funnel-row" role="listitem">
                            <span class="funnel-label"><?= e($a['rotulo']) ?></span>
                            <div class="funnel-track"><div class="funnel-fill" style="width: <?= $d['coorte'] ? round($a['total'] / $d['coorte'] * 100) : 0 ?>%"></div></div>
                            <span class="funnel-value rel-alcance-valor"><b><?= dash_num($a['total']) ?></b> <small class="muted"><?= e(relui_valor($a['pct_coorte'], '%')) ?> do total<?= $a['pct_anterior'] !== null ? ' · ' . e(relui_valor($a['pct_anterior'], '%')) . ' da fase anterior' : '' ?></small></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="card">
            <div class="card-head"><div><h2>Onde estão hoje <?= dash_ajuda('Etapa atual dos leads que entraram no período.') ?></h2></div></div>
            <?php dash_barras_horizontais(array_map(fn ($p) => ['rotulo' => $p['nome'], 'total' => $p['total']], $d['posicao']), 'Etapa atual dos leads do período', 12); ?>
        </div>
    </div>
    <div class="card dash-section">
        <div class="card-head"><div><h2>Transições no período <?= dash_ajuda('Cada mudança de etapa registrada no período, de onde para onde, separando quem fez.') ?></h2></div></div>
        <?php
        $m = array_map(fn ($t) => $t + ['de_rot' => $t['de'] === '—' ? '(entrada)' : lead_etapa_rotulo($t['de']), 'para_rot' => lead_etapa_rotulo($t['para'])], $d['matriz']);
        relui_tabela('tabela-transicoes', ['de_rot' => ['De', 'texto'], 'para_rot' => ['Para', 'texto'], 'usuario' => ['Por pessoas', ''], 'jornada' => ['Por jornadas', ''],
            'sistema' => ['Pelo sistema', ''], 'total' => ['Total', '']], $m, relui_somar($m, ['usuario', 'jornada', 'sistema', 'total']) + ['de_rot' => 'Total'], null,
            historico_instalado() ? 'Nenhuma mudança de etapa no período.' : 'Requer o histórico estruturado (migração 013).');
        ?>
    </div>
    <div class="card dash-section">
        <div class="card-head"><div><h2>Tempo em cada etapa <?= dash_ajuda('Mediana do tempo que os leads ficaram em cada etapa, só para as permanências que terminaram no período (quem ainda está na etapa não entra).') ?></h2></div></div>
        <?php
        $t = [];
        foreach ($d['tempo_etapa_horas'] as $cat => $x) {
            $t[] = ['etapa' => lead_etapa_rotulo($cat), 'mediana' => $x['mediana'], 'permanencias' => $x['permanencias']];
        }
        relui_tabela('tabela-tempo-etapa', ['etapa' => ['Etapa', 'texto'], 'mediana' => ['Tempo mediano', 'h'], 'permanencias' => ['Permanências concluídas', '']], $t, null, null, 'Nenhuma permanência concluída no período.');
        ?>
    </div>
    <?php
}

function relui_tela_plantoes(array $c): void
{
    ['rel' => $rel, 'usuario' => $u, 'f' => $f, 'pub' => $pub, 'op' => $op] = $c;
    relui_filtros($rel, $u, $f, $pub, $op, ['roleta', 'plantao', 'corretor', 'empreendimento']);
    relui_cobertura($c['cob']);
    $d = relatorio_plantoes($u, $f);
    $t = relui_somar($d['roletas'], ['entregas', 'redistribuicoes', 'chegadas', 'fora_horario', 'fila_passagens', 'fila_aguardando', 'horas_presenca']);
    ?>
    <div class="kpi-grid kpi-grid-5 dash-kpis">
        <?php
        relui_kpi('Entregas pela roleta', dash_num($t['entregas'] ?? 0), 'Leads entregues pelo rodízio no período (primeira entrega).', 'zap', ['', ''], relui_qtd((int) ($t['redistribuicoes'] ?? 0), 'redistribuição', 'redistribuições') . ' pela roleta');
        relui_kpi('Chegaram fora do horário', relui_valor(($t['chegadas'] ?? 0) ? $t['fora_horario'] : null), 'Leads que chegaram com a roleta fechada ou sem corretor apto. Só existe para leads que chegaram depois da implantação do histórico (o horário antigo não tem versões).', 'alert', ['', ''], relui_qtd((int) ($t['chegadas'] ?? 0), 'chegada registrada', 'chegadas registradas'));
        relui_kpi('Passagens pela fila', dash_num($t['fila_passagens'] ?? 0), 'Vezes que um lead ficou esperando corretor no período.', 'clock', ['', ''], dash_num($t['fila_aguardando'] ?? 0) . ' ainda aguardando');
        relui_kpi('Horas de presença', relui_valor($t['horas_presenca'] ?? 0, 'h'), 'Soma do tempo em que os corretores estiveram com presença ativa nas roletas, dentro do período.', 'pin');
        relui_kpi('Roletas', dash_num(count($d['roletas'])), 'Roletas da empresa (ou as filtradas).', 'map');
        ?>
    </div>
    <div class="card dash-section">
        <div class="card-head"><div><h2>Por roleta <?= dash_ajuda('Entregas pela data da atribuição; chegadas e fila pela data de entrada; espera = mediana entre entrar e sair da fila (quem ainda espera não entra na mediana).') ?></h2></div></div>
        <?php relui_tabela('tabela-roletas', ['nome' => ['Roleta', 'texto'], 'plantao' => ['Plantão', 'texto'], 'entregas' => ['Entregas', ''], 'redistribuicoes' => ['Redistribuições', ''],
            'chegadas' => ['Chegadas', ''], 'fora_horario' => ['Fora do horário', ''], 'fila_passagens' => ['Passagens pela fila', ''], 'espera_mediana_min' => ['Espera mediana', 'min'],
            'fila_aguardando' => ['Aguardando', ''], 'corretores_presentes' => ['Corretores presentes', ''], 'horas_presenca' => ['Horas de presença', 'h']], $d['roletas']); ?>
    </div>
    <div class="card dash-section">
        <div class="card-head"><div><h2>Presença por corretor <?= dash_ajuda('Tempo com presença ativa em cada roleta no período (da entrada até a saída ou a última verificação). Só corretores da sua estrutura.') ?></h2></div></div>
        <?php relui_tabela('tabela-presenca', ['nome' => ['Corretor', 'texto'], 'roleta' => ['Roleta', 'texto'], 'horas' => ['Horas de presença', 'h']], $d['presenca'], null,
            fn ($l) => relui_url('corretor', array_intersect_key($c['pub'], array_flip(['periodo', 'ini', 'fim'])), ['corretor' => $l['usuario_id']]), 'Ninguém marcou presença no período.'); ?>
    </div>
    <?php
}

function relui_tela_origens(array $c): void
{
    ['rel' => $rel, 'usuario' => $u, 'f' => $f, 'pub' => $pub, 'op' => $op] = $c;
    $ags = relatorio_origens_agrupamentos();
    $por = isset($ags[$pub['agrupar'] ?? '']) ? $pub['agrupar'] : 'origem';
    relui_filtros($rel, $u, $f, $pub, $op, ['empreendimento', 'superintendencia', 'equipe', 'origem', 'canal'], ['agrupar' => $por]);
    $l = relatorio_origens($u, $f, $por);
    $t = relui_somar($l, ['leads', 'distribuidos', 'contato_efetivo', 'com_visita', 'com_contrato']);
    $t['taxa_contato_efetivo'] = motor_taxa($t['contato_efetivo'], $t['distribuidos']);
    $t['taxa_visita'] = motor_taxa($t['com_visita'], $t['leads']);
    $t['taxa_contrato'] = motor_taxa($t['com_contrato'], $t['leads']);
    require_once __DIR__ . '/../investimentos.php';
    $custos = relatorio_custos($u, $f);
    $t['investimento'] = $custos['investimento'];
    $t['custo_por_lead'] = null; // o custo total só vale para as origens investidas (cartões acima)
    $t['custo_por_contrato'] = null;
    $t['nome'] = 'Total';
    ?>
    <?php if ($custos['disponivel']): ?>
        <div class="kpi-grid kpi-grid-5 dash-kpis">
            <?php
            relui_kpi('Investimento no período', relui_valor($custos['investimento'], 'R$'), 'Soma dos lançamentos que cobrem o período, rateada por dia (só a parte dentro do período). ' . $custos['lancamentos'] . ' lançamento(s).', 'file');
            relui_kpi('Custo por lead', relui_valor($custos['cpl'], 'R$'), 'Investimento ÷ leads das origens investidas que entraram no período.', 'user', ['', ''], relui_qtd($custos['resultados']['leads'], 'lead', 'leads'));
            relui_kpi('Custo por contato efetivo', relui_valor($custos['custo_contato'], 'R$'), 'Investimento ÷ leads dessas origens com contato efetivo.', 'phone', ['', ''], relui_qtd($custos['resultados']['contato_efetivo'], 'lead', 'leads'));
            relui_kpi('Custo por visita', relui_valor($custos['custo_visita'], 'R$'), 'Investimento ÷ leads dessas origens com visita realizada.', 'building', ['', ''], relui_qtd($custos['resultados']['com_visita'], 'lead', 'leads'));
            relui_kpi('Custo por contrato', relui_valor($custos['custo_contrato'], 'R$'), 'Investimento ÷ leads dessas origens com contrato VALIDADO. ROI não é calculado: o sistema não tem receita (VGV não é receita).', 'check', ['', ''], relui_qtd($custos['resultados']['com_contrato'], 'contrato', 'contratos'));
            ?>
        </div>
    <?php else: ?>
        <div class="rel-nota" role="note"><?= icon('info') ?><span><b>Custos não disponíveis:</b> <?= e($custos['motivo']) ?> Custo por lead, por venda e ROI nunca são estimados.<?= usuario_tem_permissao($u, 'gerenciar_investimentos') ? ' <a href="/pages/investimentos.php">Lançar investimento</a>.' : '' ?></span></div>
    <?php endif; ?>
    <p class="muted rel-rodape">Para a prévia de cada criativo do Meta Ads, use <a href="/pages/relatorio_anuncios.php">Leads por anúncio</a>.</p>
    <div class="card dash-section">
        <div class="card-head"><div><h2>Leads do período por <?= e(mb_strtolower($ags[$por])) ?> <?= dash_ajuda('Leads que ENTRARAM no período e o que já aconteceu com eles até agora: chegaram a corretor, tiveram contato efetivo, visita realizada e contrato validado. Origem é de onde o cliente veio (aquisição), não o canal de atendimento.') ?></h2>
            <p>Agrupar por:</p></div></div>
        <?php relui_segmentos($ags, $por, fn ($k) => $c['url'](['agrupar' => $k]), 'Agrupar por'); ?>
        <?php relui_tabela('tabela-origens', ['nome' => [$ags[$por], 'texto'], 'leads' => ['Leads', ''], 'distribuidos' => ['Chegaram a corretor', ''], 'contato_efetivo' => ['Contato efetivo', ''],
            'taxa_contato_efetivo' => ['Taxa de contato efetivo', '%'], 'com_visita' => ['Com visita realizada', ''], 'taxa_visita' => ['% com visita', '%'],
            'com_contrato' => ['Com contrato validado', ''], 'taxa_contrato' => ['% com contrato', '%'], 'investimento' => ['Investimento', 'R$'],
            'custo_por_lead' => ['Custo por lead', 'R$'], 'custo_por_contrato' => ['Custo por contrato', 'R$']], $l, $l ? $t : null, null,
            $por !== 'canal' && !modulo_tabela_existe('lead_origens') ? 'Origens detalhadas e UTMs requerem a migração 013.' : 'Nenhum lead entrou no período.'); ?>
        <?php if (str_starts_with($por, 'utm_')): ?><p class="muted rel-rodape">"(sem UTM)": leads cadastrados sem parâmetros de campanha (o cadastro manual, o Meta Ads e as importações normalmente não têm UTM).</p><?php endif; ?>
    </div>
    <?php
}

function relui_tela_visitas(array $c): void
{
    ['rel' => $rel, 'usuario' => $u, 'f' => $f, 'pub' => $pub, 'op' => $op] = $c;
    $por = in_array($pub['agrupar'] ?? '', ['corretor', 'empreendimento'], true) ? $pub['agrupar'] : 'corretor';
    relui_filtros($rel, $u, $f, $pub, $op, ['empreendimento', 'superintendencia', 'equipe', 'corretor'], ['agrupar' => $por]);
    $l = relatorio_visitas_por($u, $f, $por);
    $con = relatorio_contratos($u, $f);
    $t = relui_somar($l, ['agendadas', 'confirmadas', 'realizadas', 'ausentes', 'canceladas', 'reagendadas', 'viraram_contrato']);
    $t['comparecimento'] = motor_taxa($t['realizadas'], ($t['realizadas'] ?? 0) + ($t['ausentes'] ?? 0));
    $t['taxa_contrato'] = $t['viraram_contrato'] === null ? null : motor_taxa($t['viraram_contrato'], $t['realizadas']);
    $t['nome'] = 'Total';
    ?>
    <div class="kpi-grid kpi-grid-5 dash-kpis">
        <?php
        relui_kpi('Visitas agendadas', dash_num($t['agendadas'] ?? 0), 'Visitas criadas no período (reagendamento não conta de novo).', 'calendar', ['', ''], relui_qtd((int) ($t['confirmadas'] ?? 0), 'confirmada', 'confirmadas') . ' pelo cliente');
        relui_kpi('Realizadas', dash_num($t['realizadas'] ?? 0), 'Visitas concluídas como realizadas no período.', 'building', ['', ''], dash_num($t['ausentes'] ?? 0) . ' com cliente ausente');
        relui_kpi('Comparecimento', relui_valor($t['comparecimento'], '%'), 'Realizadas ÷ (realizadas + cliente ausente).', 'target');
        relui_kpi('Visita → contrato', relui_valor($t['taxa_contrato'], '%'), 'Dos leads com visita realizada no período, quantos já têm contrato validado.', 'check', ['', ''], relui_qtd((int) ($t['viraram_contrato'] ?? 0), 'lead', 'leads'));
        relui_kpi('Contratos validados', relui_valor($con['contratos_validados']['valor']), 'Contratos validados com data de assinatura no período.', 'file', ['', ''], 'VGV ' . relui_valor($con['vgv']['valor'], 'R$'));
        ?>
    </div>
    <div class="card dash-section">
        <div class="card-head"><div><h2>Visitas por <?= $por === 'corretor' ? 'corretor' : 'empreendimento' ?> <?= dash_ajuda('Cada situação pela própria data: agendada (criação), confirmada (quando o cliente confirmou), realizada/ausente/cancelada/reagendada (conclusão). Corretor = quem conduziu a visita.') ?></h2><p>Agrupar por:</p></div></div>
        <?php relui_segmentos(['corretor' => 'Corretor', 'empreendimento' => 'Empreendimento'], $por, fn ($k) => $c['url'](['agrupar' => $k]), 'Agrupar por'); ?>
        <?php relui_tabela('tabela-visitas', ['nome' => [$por === 'corretor' ? 'Corretor' : 'Empreendimento', 'texto'], 'agendadas' => ['Agendadas', ''], 'confirmadas' => ['Confirmadas', ''],
            'realizadas' => ['Realizadas', ''], 'ausentes' => ['Cliente ausente', ''], 'canceladas' => ['Canceladas', ''], 'reagendadas' => ['Reagendadas', ''],
            'comparecimento' => ['Comparecimento', '%'], 'viraram_contrato' => ['Viraram contrato', ''], 'taxa_contrato' => ['Visita → contrato', '%']], $l, $l ? $t : null,
            $por === 'corretor' ? fn ($x) => relui_url('corretor', array_intersect_key($c['pub'], array_flip(['periodo', 'ini', 'fim'])), ['corretor' => $x['id']]) : null, 'Nenhuma visita no período.'); ?>
    </div>
    <?php
}

function relui_tela_perdas(array $c): void
{
    ['rel' => $rel, 'usuario' => $u, 'f' => $f, 'pub' => $pub, 'op' => $op] = $c;
    $ags = array_map(fn ($a) => $a[1], relatorio_agrupamentos());
    $por = isset($ags[$pub['agrupar'] ?? '']) ? $pub['agrupar'] : ($u['escopo'] === 'equipe' ? 'corretor' : 'equipe');
    relui_filtros($rel, $u, $f, $pub, $op, ['empreendimento', 'superintendencia', 'gerente', 'equipe', 'corretor'], ['agrupar' => $por]);
    relui_cobertura($c['cob']);
    $pe = relatorio_perdas($u, $f);
    $l = relatorio_perdas_por($u, $f, $por);
    $dest = relatorio_perdas_destino($u, $f, $por === 'corretor' ? 'equipe' : $por);
    $t = relui_somar($l, ['inatividade', 'transferidos', 'total', 'redistribuidos', 'contato_depois', 'contrato_depois']);
    $t['taxa_contato_depois'] = motor_taxa($t['contato_depois'], $t['redistribuidos']);
    $t['tempo_redistribuicao_h'] = $pe['tempo_redistribuicao']['valor'];
    $t['nome'] = 'Total';
    ?>
    <div class="kpi-grid kpi-grid-4 dash-kpis">
        <?php
        relui_kpi('Perdidos por inatividade', relui_valor($pe['perdidos_inatividade']['valor']), 'Leads retirados do corretor pela recuperação automática por falta de feedback no período. Menor é melhor.', 'alert');
        relui_kpi('Já redistribuídos', relui_valor($pe['redistribuidos']['valor']), 'Dos perdidos, quantos já foram entregues a outro corretor.', 'users');
        relui_kpi('Tempo até a redistribuição', relui_valor($pe['tempo_redistribuicao']['valor'], 'h'), 'Mediana entre a perda e a nova entrega.', 'clock');
        relui_kpi('Transferidos', dash_num($t['transferidos'] ?? 0), 'Leads que saíram da carteira por transferência (administrativa, voluntária, oferta ativa) no período.', 'move');
        ?>
    </div>
    <div class="card dash-section">
            <div class="card-head"><div><h2>Por <?= e(mb_strtolower($ags[$por])) ?> que perdeu <?= dash_ajuda('Estrutura de quem estava com o lead na data da entrega (não a de hoje). "Contato depois": dos redistribuídos, em quantos o novo corretor teve contato efetivo. "Contrato depois": contrato validado com outro corretor.') ?></h2><p>Agrupar por:</p></div></div>
            <?php relui_segmentos($ags, $por, fn ($k) => $c['url'](['agrupar' => $k]), 'Agrupar por'); ?>
            <?php relui_tabela('tabela-perdas', ['nome' => [$ags[$por], 'texto'], 'inatividade' => ['Perdidos (inatividade)', ''], 'redistribuidos' => ['Redistribuídos', ''],
                'tempo_redistribuicao_h' => ['Tempo até redistribuir', 'h'], 'contato_depois' => ['Contato depois', ''], 'taxa_contato_depois' => ['% contato depois', '%'],
                'contrato_depois' => ['Contrato depois', ''], 'transferidos' => ['Transferidos', ''], 'total' => ['Total de saídas', '']], $l, $l ? $t : null, null, 'Nenhuma perda ou transferência no período.'); ?>
    </div>
    <div class="dash-grid">
        <div class="card">
            <div class="card-head"><div><h2>Para onde foram <?= dash_ajuda('Perdidos por inatividade no período, pela ' . ($por === 'corretor' ? 'equipe' : mb_strtolower($ags[$por])) . ' de quem recebeu depois.') ?></h2></div>
                <a class="link-btn" href="/pages/relatorios.php">Ver transferências</a></div>
            <?php dash_barras_horizontais($dest, 'Destino dos leads perdidos'); ?>
        </div>
    </div>
    <?php
}
