<?php
/**
 * Inteligência de Vendas → revisão de UMA venda: a venda, o resultado do
 * cruzamento, os leads candidatos (com a evidência de cada um) e a decisão
 * humana. Contatos sempre mascarados; lead fora da carteira do usuário
 * aparece sem dados (regra de acesso vigente).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/estrutura.php';
require_once __DIR__ . '/../includes/lead_componentes.php';
require_once __DIR__ . '/../includes/vendas/cruzamento.php';

$usuario = auth_require();
$crumb = 'Revisar correspondência';
$activeNav = 'relatorios';
if (!empty($usuario['eh_superadmin']) || !usuario_tem_permissao($usuario, 'ver_inteligencia_vendas')) {
    pagina_acesso_restrito($crumb, $activeNav, 'Ver a Inteligência de Vendas');
}
$empresaId = (int) $usuario['empresa_ativa_id'];
$podeRevisar = usuario_tem_permissao($usuario, 'revisar_correspondencias');
$venda = null;
if (vendas_cruzamento_instalado()) {
    $st = db()->prepare('SELECT v.*, e.nome AS emp_crm FROM vendas_externas v LEFT JOIN empreendimentos e ON e.id = v.empreendimento_id WHERE v.id = :id AND v.empresa_id = :e');
    $st->execute(['id' => (int) ($_GET['id'] ?? 0), 'e' => $empresaId]);
    $venda = $st->fetch() ?: null;
}
if (!$venda) {
    http_response_code(404);
}
$corr = $venda ? vendas_correspondencia((int) $venda['id']) : null;
$candidatos = [];
$historico = [];
if ($venda) {
    $lig = vendas_correspondencia_leads((int) $venda['id']);
    $ids = array_map(fn ($l) => (int) $l['lead_id'], $lig);
    $visiveis = [];
    if ($ids) {
        $sc = rbac_leads_scope($usuario);
        $params = $sc['params'];
        $marcas = [];
        foreach ($ids as $i => $lid) {
            $marcas[] = ':cl' . $i;
            $params['cl' . $i] = $lid;
        }
        $st = db()->prepare('SELECT leads.*, e.nome AS emp_nome, u.nome AS resp_nome FROM leads LEFT JOIN empreendimentos e ON e.id = leads.empreendimento_id
                             LEFT JOIN usuarios u ON u.id = leads.responsavel_id WHERE ' . $sc['sql'] . ' AND leads.id IN (' . implode(',', $marcas) . ')');
        $st->execute($params);
        foreach ($st->fetchAll() as $l) {
            $visiveis[(int) $l['id']] = $l;
        }
    }
    $telsVenda = array_filter([$venda['telefone1'], $venda['telefone2']]);
    foreach ($lig as $l) {
        $info = $visiveis[(int) $l['lead_id']] ?? null;
        $tel = $info ? vendas_telefone((string) $info['telefone'])['e164'] : null;
        $telVar = $info && !$tel ? vendas_telefone_variante((string) $info['telefone']) : null;   // celular antigo, sem o 9
        $em = $info ? vendas_email((string) $info['email'])['email'] : null;
        $candidatos[] = ['lig' => $l, 'lead' => $info, 'tel' => $tel, 'email' => $em,
                         'tel_var' => $telVar, 'tel_ok' => $tel && in_array($tel, $telsVenda, true), 'email_ok' => $em && $em === $venda['email']];
    }
    $st = db()->prepare("SELECT r.criado_em, r.decisao, r.justificativa, r.lead_ids, u.nome FROM vendas_revisoes r LEFT JOIN usuarios u ON u.id = r.usuario_id WHERE r.venda_id = :v ORDER BY r.id DESC");
    $st->execute(['v' => $venda['id']]);
    $revisoes = $st->fetchAll();
    $st = db()->prepare('SELECT * FROM vendas_conciliacao_log WHERE venda_id = :v ORDER BY id DESC LIMIT 20');
    $st->execute(['v' => $venda['id']]);
    $historico = $st->fetchAll();
}
$sit = vendas_situacoes();
$efetiva = vendas_situacao_efetiva($corr);
$papel = ['vinculado' => ['pill-green', 'Vinculado'], 'candidato' => ['pill-amber', 'Candidato'], 'descartado' => ['pill-muted', 'Descartado']];
$evid = ['telefone_email' => 'Telefone e e-mail', 'telefone' => 'Telefone', 'email' => 'E-mail', 'variante' => 'Telefone só com/sem o 9º dígito', 'manual' => 'Manual'];
$brl = fn ($v) => $v === null ? '—' : 'R$ ' . number_format((float) $v, 2, ',', '.');

$pageTitle = 'Revisar correspondência — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div>
        <a class="back-link" href="/pages/vendas_conciliacao.php"><?= icon('arrow-r') ?>Voltar para a conciliação</a>
        <div class="eyebrow">Inteligência de Vendas · Conciliação</div>
        <h1><?= $venda ? 'Venda ' . e($venda['chave_origem']) : 'Venda não encontrada' ?></h1>
        <?php if ($venda && $corr): [$r1, $p1] = $sit[$efetiva] ?? [$efetiva, 'pill-muted']; ?>
            <p><span class="pill <?= $p1 ?>"><?= e($r1) ?></span> <?= e($corr['motivo']) ?> <span class="muted">· regras <?= e($corr['regras_versao']) ?> · calculado em <?= date('d/m/Y H:i', strtotime($corr['calculado_em'])) ?></span></p>
        <?php endif; ?>
    </div>
</div>

<?php if ($venda && $corr && (int) $corr['vinculada']): ?><div class="actions vendas-jornada-link"><a class="btn btn-primary" href="/pages/vendas_jornada.php?id=<?= (int) $venda['id'] ?>"><?= icon('clock') ?>Ver jornada do cliente</a></div><?php endif; ?>
<?php if (!$venda): ?>
    <div class="card stub-card"><div class="stub-icon"><?= icon('alert') ?></div><h2>Venda não encontrada</h2><p>Ela não existe ou é de outra empresa.</p></div>
<?php else: ?>
    <?php if ($corr && $corr['alertas']): ?><div class="rel-nota" role="note"><?= icon('info') ?><span><?= e(str_replace(' | ', ' · ', $corr['alertas'])) ?></span></div><?php endif; ?>
    <?php if (!$corr): ?><div class="rel-nota" role="note"><?= icon('info') ?><span>Esta venda ainda não foi cruzada. Use "Recalcular cruzamento" na conciliação.</span></div><?php endif; ?>

    <div class="grid-2">
        <div class="card">
            <div class="card-head"><div><h2>Venda (sistema comercial)</h2><p>Dados importados; não substituem os do CRM.</p></div></div>
            <dl class="vendas-dl">
                <dt>Cliente</dt><dd><?= e($venda['cliente_nome'] ?? '—') ?></dd>
                <dt>Telefone(s)</dt><dd><?= e(implode(' · ', array_map('vendas_mascarar_telefone', array_filter([$venda['telefone1'], $venda['telefone2']]))) ?: '—') ?></dd>
                <dt>E-mail</dt><dd><?= e(vendas_mascarar_email($venda['email'])) ?></dd>
                <dt>Data da venda</dt><dd><?= $venda['data_venda'] ? date('d/m/Y', strtotime($venda['data_venda'])) . ($venda['data_venda_so_data'] ? ' <small class="muted">(sem hora)</small>' : '') : '—' ?></dd>
                <dt>Empreendimento</dt><dd><?= e($venda['empreendimento_texto'] ?? '—') ?><?= $venda['emp_crm'] ? ' <small class="muted">→ ' . e($venda['emp_crm']) . ' no CRM</small>' : ' <small class="muted">(sem equivalência)</small>' ?></dd>
                <dt>Unidade</dt><dd><?= e(trim(($venda['bloco'] ? 'Bloco ' . $venda['bloco'] . ' · ' : '') . ($venda['unidade'] ?? ''), ' ·') ?: '—') ?></dd>
                <dt>Valor da venda</dt><dd><?= e($brl($venda['valor'])) ?> <small class="muted">(não é receita)</small></dd>
                <dt>Situação</dt><dd><?= e($venda['situacao'] ?? '—') ?><?= (int) $venda['eh_venda'] ? '' : ' <small class="muted">· não conta como venda</small>' ?></dd>
                <dt>Corretor (origem)</dt><dd><?= e($venda['corretor_texto'] ?? '—') ?><?= $venda['corretor_id_origem'] ? ' <small class="muted">id ' . e($venda['corretor_id_origem']) . '</small>' : '' ?></dd>
                <dt>Mídia declarada na venda</dt><dd><?= e($venda['midia_texto'] ?? '—') ?></dd>
                <?php if ($venda['data_cadastro_origem']): ?><dt>Cadastro no sistema externo</dt><dd><?= date('d/m/Y', strtotime($venda['data_cadastro_origem'])) ?> <small class="muted">(importado)</small></dd><?php endif; ?>
            </dl>
        </div>
        <div class="card">
            <div class="card-head"><div><h2>O que o cruzamento usa</h2></div></div>
            <ul class="regra-lista">
                <li class="is-ok"><?= icon('check') ?>Telefone com DDD e e-mail, comparados exatamente depois de padronizados.</li>
                <li class="is-ok"><?= icon('check') ?>Nome não é prova; CPF não é usado nesta versão.</li>
                <li class="is-ok"><?= icon('check') ?>Contatos aparecem mascarados: o suficiente para comparar.</li>
                <li class="is-ok"><?= icon('check') ?>Leads fora da sua carteira atual aparecem sem dados.</li>
                <li class="is-ok"><?= icon('check') ?>Confirmar um vínculo não muda o lead, o responsável nem o Kanban.</li>
            </ul>
        </div>
    </div>

    <form class="card" data-api-form action="/api/vendas_conciliacao.php">
        <input type="hidden" name="acao" value="revisar"><input type="hidden" name="venda_id" value="<?= (int) $venda['id'] ?>">
        <div class="card-head"><div><h2>Leads encontrados no CRM</h2><p><?= $candidatos ? 'Evidência de cada lead: o que coincide com a venda.' : 'Nenhum lead com o mesmo telefone ou e-mail.' ?></p></div></div>
        <?php if ($candidatos): ?>
            <div class="table-wrap"><table class="report-table">
                <thead><tr><?php if ($podeRevisar): ?><th scope="col"><span class="sr-only">Escolher</span></th><?php endif; ?><th scope="col">Lead</th><th scope="col">Telefone</th><th scope="col">E-mail</th><th scope="col">Empreendimento de interesse</th><th scope="col">Entrou no CRM</th><th scope="col">Responsável atual</th><th scope="col">Evidência</th><th scope="col">Papel</th></tr></thead>
                <tbody>
                <?php foreach ($candidatos as $c): $l = $c['lead']; [$pp, $pr] = $papel[$c['lig']['papel']]; ?>
                    <tr>
                        <?php if ($podeRevisar): ?><td><?php if ($l): ?><input type="checkbox" name="lead_ids[]" value="<?= (int) $c['lig']['lead_id'] ?>" aria-label="Escolher o lead <?= (int) $c['lig']['lead_id'] ?>" <?= $c['lig']['papel'] === 'vinculado' ? 'checked' : '' ?>><?php endif; ?></td><?php endif; ?>
                        <?php if ($l): $entrada = min(array_filter([$l['criado_em'], $l['meta_cadastro_em']])); ?>
                            <td data-rotulo="Lead"><a href="/pages/lead.php?id=<?= (int) $l['id'] ?>"><?= e($l['nome']) ?></a> <small class="muted">#<?= (int) $l['id'] ?></small></td>
                            <td data-rotulo="Telefone"><?php if ($c['tel_var']): ?><span class="vendas-contato is-mostra is-variante">(<?= e(substr($c['tel_var'], 2, 2)) ?>) ••••-<?= e(substr($c['tel_var'], -4)) ?></span><br><small class="muted">sem o 9º dígito</small><?php else: ?><span class="vendas-contato<?= $c['tel_ok'] ? ' is-ok' : '' ?> is-mostra"><?= e(vendas_mascarar_telefone($c['tel'])) ?></span><?php endif; ?></td>
                            <td data-rotulo="E-mail"><span class="vendas-contato<?= $c['email_ok'] ? ' is-ok' : '' ?> is-mostra"><?= e(vendas_mascarar_email($c['email'])) ?></span></td>
                            <td data-rotulo="Empreendimento"><?= e($l['emp_nome'] ?? '—') ?></td>
                            <td data-rotulo="Entrou no CRM"><?= date('d/m/Y', strtotime($entrada)) ?></td>
                            <td data-rotulo="Responsável"><?= e($l['resp_nome'] ?? 'Sem responsável') ?></td>
                        <?php else: ?>
                            <td data-rotulo="Lead" colspan="6"><span class="muted">Lead #<?= (int) $c['lig']['lead_id'] ?> — fora da sua carteira atual ou excluído: dados não exibidos.</span></td>
                        <?php endif; ?>
                        <td data-rotulo="Evidência"><?= e($evid[$c['lig']['evidencia']] ?? $c['lig']['evidencia']) ?></td>
                        <td data-rotulo="Papel"><span class="pill <?= $pp ?>"><?= e($pr) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
        <?php if ($podeRevisar && $corr): ?>
            <div class="subhead">Decisão</div>
            <fieldset class="rel-exp-opcoes vendas-decisao"><legend class="sr-only">Decisão</legend>
                <?php if ($candidatos): ?><label class="radio-row"><input type="radio" name="decisao" value="confirmar" <?= $efetiva === 'ambigua' ? 'checked' : '' ?>> <b>Confirmar</b>: os leads marcados acima são do comprador</label><?php endif; ?>
                <label class="radio-row"><input type="radio" name="decisao" value="rejeitar" <?= !$candidatos ? 'checked' : '' ?>> <b>Sem vínculo</b>: nenhum destes leads é o comprador</label>
                <?php if ($corr['decisao'] !== 'automatica'): ?><label class="radio-row"><input type="radio" name="decisao" value="reabrir"> <b>Reabrir</b>: desfazer a decisão e voltar ao resultado automático</label><?php endif; ?>
            </fieldset>
            <div class="field"><label for="v-just">Justificativa (fica no histórico)</label><input type="text" id="v-just" name="justificativa" required minlength="5" maxlength="500" placeholder="Ex.: confirmado com o corretor; mesmo e-mail usado em outro cadastro"></div>
            <div class="form-alert" data-form-alerta hidden></div>
            <button type="submit" class="btn btn-primary"><?= icon('check') ?>Registrar decisão</button>
        <?php endif; ?>
    </form>

    <?php if ($revisoes || $historico): ?>
        <div class="card">
            <div class="card-head"><div><h2>Histórico</h2><p>Decisões humanas e mudanças do cruzamento automático.</p></div></div>
            <ul class="vendas-historico">
                <?php foreach ($revisoes as $r): ?>
                    <li><b><?= date('d/m/Y H:i', strtotime($r['criado_em'])) ?></b> · <?= e($r['nome'] ?? '—') ?> · <?= e(['confirmar' => 'confirmou o vínculo', 'rejeitar' => 'marcou sem vínculo', 'reabrir' => 'reabriu a revisão'][$r['decisao']]) ?><?= $r['lead_ids'] ? ' (lead ' . e(str_replace(',', ', ', $r['lead_ids'])) . ')' : '' ?>: “<?= e($r['justificativa']) ?>”</li>
                <?php endforeach; ?>
                <?php foreach ($historico as $h): if ($h['origem'] !== 'cruzamento') continue; ?>
                    <li class="muted"><b><?= date('d/m/Y H:i', strtotime($h['criado_em'])) ?></b> · cruzamento automático (regras <?= e($h['regras_versao']) ?>): <?= e($h['status_anterior'] ? ($sit[$h['status_anterior']][0] ?? $h['status_anterior']) . ' → ' : '') ?><?= e($sit[$h['status_novo']][0] ?? $h['status_novo']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/layout_end.php'; ?>
