<?php
/**
 * Investimento em anúncios (Etapa 6): lançamentos por período e origem,
 * usados nos custos por resultado da Central de Relatórios.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/estrutura.php';
require_once __DIR__ . '/../includes/investimentos.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/lead_componentes.php';

$usuario = auth_require();
$crumb = 'Investimento em anúncios';
$activeNav = 'relatorios';
if (!usuario_tem_permissao($usuario, 'gerenciar_investimentos')) {
    pagina_acesso_restrito($crumb, $activeNav, 'Lançar investimento em anúncios');
}
$empresaId = (int) $usuario['empresa_ativa_id'];
$instalado = investimentos_instalados();
$ano = (int) ($_GET['ano'] ?? date('Y'));
$ano = $ano >= 2015 && $ano <= (int) date('Y') + 1 ? $ano : (int) date('Y');
$lista = $instalado ? investimentos_listar($empresaId, $ano . '-01-01', $ano . '-12-31') : [];
$origens = origens_listar($empresaId);
$emps = db()->query('SELECT id, nome FROM empreendimentos WHERE empresa_id = ' . $empresaId . ' ORDER BY nome')->fetchAll();
$total = array_sum(array_map(fn ($l) => (float) $l['valor'], $lista));

$pageTitle = 'Investimento em anúncios — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div>
        <div class="eyebrow">Relatórios · Marketing</div>
        <h1>Investimento em anúncios</h1>
        <p>Lance quanto foi investido em cada origem (Meta Ads, Google Ads…) e período. Os relatórios calculam custo por lead, por contato, por visita e por contrato <b>só com o que foi lançado</b> — rateado por dia. Sem lançamento, nada é estimado.</p>
    </div>
    <?php if ($instalado): ?>
        <div class="actions">
            <button type="button" class="btn btn-outline" data-open-modal="modal-inv-importar"><?= icon('upload') ?>Importar planilha</button>
            <button type="button" class="btn btn-primary" data-open-modal="modal-inv-0"><?= icon('plus') ?>Lançar investimento</button>
        </div>
    <?php endif; ?>
</div>

<?php if (!$instalado): ?>
    <div class="card stub-card"><div class="stub-icon"><?= icon('alert') ?></div><h2>Em atualização</h2><p>Rode a migração 016 para lançar investimentos.</p></div>
<?php else: ?>
    <div class="card dash-section">
        <div class="card-head">
            <div><h2>Lançamentos de <?= $ano ?></h2><p><?= count($lista) ?> lançamento(s) · total <?= e('R$ ' . number_format($total, 2, ',', '.')) ?></p></div>
            <nav class="segmented-inline" aria-label="Ano">
                <?php foreach ([$ano - 1, $ano, $ano + 1] as $a): if ($a > (int) date('Y') + 1) continue; ?>
                    <a class="seg-link<?= $a === $ano ? ' is-active' : '' ?>" href="?ano=<?= $a ?>"><?= $a ?></a>
                <?php endforeach; ?>
            </nav>
        </div>
        <?php if (!$lista): ?>
            <p class="empty-note">Nenhum lançamento em <?= $ano ?>.</p>
        <?php else: ?>
            <div class="table-wrap"><table class="report-table">
                <thead><tr><th scope="col">Período</th><th scope="col">Origem</th><th scope="col">Empreendimento</th><th scope="col">Campanha / descrição</th><th scope="col" class="num">Valor</th><th scope="col">Lançado</th><th scope="col"></th></tr></thead>
                <tbody>
                <?php foreach ($lista as $l): $lid = (int) $l['id']; ?>
                    <tr>
                        <td data-rotulo="Período"><?= date('d/m/Y', strtotime($l['data_inicio'])) ?> a <?= date('d/m/Y', strtotime($l['data_fim'])) ?></td>
                        <td data-rotulo="Origem"><?= e($l['origem_nome']) ?></td>
                        <td data-rotulo="Empreendimento"><?= e($l['empreendimento_nome'] ?? 'Todos') ?></td>
                        <td data-rotulo="Campanha"><?= e(trim(($l['campanha'] ?? '') . ($l['descricao'] ? ' — ' . $l['descricao'] : ''), ' —')) ?: '—' ?></td>
                        <td class="num" data-rotulo="Valor"><?= e('R$ ' . number_format((float) $l['valor'], 2, ',', '.')) ?></td>
                        <td data-rotulo="Lançado"><small class="muted"><?= e($l['criado_por_nome'] ?? '—') ?> · <?= $l['fonte'] === 'planilha' ? 'planilha' : 'manual' ?></small></td>
                        <td><div class="rel-acoes-linha">
                            <button type="button" class="btn btn-outline btn-sm" data-open-modal="modal-inv-<?= $lid ?>"><?= icon('edit') ?>Editar</button>
                            <button type="button" class="link-btn danger" data-api-acao="/api/investimento.php" data-acao="excluir" data-id="<?= $lid ?>" data-confirmar="Excluir este lançamento? Os custos dos relatórios serão recalculados.">Excluir</button>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>

    <?php foreach (array_merge([['id' => 0, 'data_inicio' => date('Y-m-01'), 'data_fim' => date('Y-m-t'), 'origem_detalhe_id' => 0, 'empreendimento_id' => null, 'campanha' => '', 'valor' => '', 'descricao' => '']], $lista) as $l): $lid = (int) $l['id']; ?>
        <div class="modal-overlay" id="modal-inv-<?= $lid ?>" hidden>
            <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="modal-inv-<?= $lid ?>-t">
                <button type="button" class="overlay-close" data-close-modal="modal-inv-<?= $lid ?>" aria-label="Fechar">&times;</button>
                <h2 id="modal-inv-<?= $lid ?>-t"><?= $lid ? 'Editar lançamento' : 'Lançar investimento' ?></h2>
                <form data-api-form action="/api/investimento.php" data-recarregar>
                    <input type="hidden" name="acao" value="salvar"><?php if ($lid): ?><input type="hidden" name="id" value="<?= $lid ?>"><?php endif; ?>
                    <div class="form-grid">
                        <div class="field"><label for="inv-di-<?= $lid ?>">De</label><input type="date" id="inv-di-<?= $lid ?>" name="data_inicio" required value="<?= e($l['data_inicio']) ?>"></div>
                        <div class="field"><label for="inv-df-<?= $lid ?>">Até</label><input type="date" id="inv-df-<?= $lid ?>" name="data_fim" required value="<?= e($l['data_fim']) ?>"></div>
                        <div class="field"><label for="inv-o-<?= $lid ?>">Origem</label>
                            <select id="inv-o-<?= $lid ?>" name="origem_detalhe_id" required><option value="">Escolha…</option>
                                <?php foreach ($origens as $o): ?><option value="<?= (int) $o['id'] ?>" <?= (int) $l['origem_detalhe_id'] === (int) $o['id'] ? 'selected' : '' ?>><?= e($o['nome']) ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="field"><label for="inv-e-<?= $lid ?>">Empreendimento</label>
                            <select id="inv-e-<?= $lid ?>" name="empreendimento_id"><option value="">Todos (não dividido)</option>
                                <?php foreach ($emps as $em): ?><option value="<?= (int) $em['id'] ?>" <?= (int) $l['empreendimento_id'] === (int) $em['id'] ? 'selected' : '' ?>><?= e($em['nome']) ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="field"><label for="inv-v-<?= $lid ?>">Valor investido</label><input type="text" id="inv-v-<?= $lid ?>" name="valor" required inputmode="decimal" placeholder="Ex.: 12.500,00" value="<?= $l['valor'] !== '' ? e(number_format((float) $l['valor'], 2, ',', '.')) : '' ?>"></div>
                        <div class="field"><label for="inv-c-<?= $lid ?>">Campanha (opcional)</label><input type="text" id="inv-c-<?= $lid ?>" name="campanha" maxlength="190" value="<?= e((string) $l['campanha']) ?>"></div>
                    </div>
                    <div class="field"><label for="inv-d-<?= $lid ?>">Descrição (opcional)</label><input type="text" id="inv-d-<?= $lid ?>" name="descricao" maxlength="300" value="<?= e((string) $l['descricao']) ?>"></div>
                    <p class="muted">O valor é distribuído igualmente pelos dias do período. Ex.: R$ 3.000 de 01 a 30/06 = R$ 100 por dia; um relatório de 01 a 10/06 considera R$ 1.000. Com o empreendimento "Todos", o valor não entra quando o relatório é filtrado por um empreendimento.</p>
                    <div class="form-alert" data-form-alerta hidden></div>
                    <button type="submit" class="btn btn-primary"><?= icon('check') ?>Salvar</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="modal-overlay" id="modal-inv-importar" hidden>
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="modal-inv-imp-t">
            <button type="button" class="overlay-close" data-close-modal="modal-inv-importar" aria-label="Fechar">&times;</button>
            <h2 id="modal-inv-imp-t">Importar planilha de investimento</h2>
            <p class="muted">Arquivo CSV (Excel → "Salvar como CSV") com o cabeçalho:<br><code>data_inicio;data_fim;origem;empreendimento;campanha;valor;descricao</code><br>
                Datas em dd/mm/aaaa; origem e empreendimento pelo nome (como aparecem no sistema; empreendimento vazio = todos). Se alguma linha tiver erro, <b>nada</b> é importado e você vê quais linhas corrigir.</p>
            <form data-api-form action="/api/investimento.php" data-recarregar>
                <input type="hidden" name="acao" value="importar">
                <div class="field"><label for="inv-arq">Arquivo</label><input type="file" id="inv-arq" name="arquivo" accept=".csv,text/csv" required></div>
                <div class="form-alert" data-form-alerta hidden></div>
                <button type="submit" class="btn btn-primary"><?= icon('upload') ?>Importar</button>
            </form>
        </div>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/layout_end.php'; ?>
