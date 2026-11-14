<?php
/**
 * Campanhas de remarketing pelo WhatsApp (Fase 7): lista, nova campanha
 * (filtros → prévia → criar) e detalhe (confirmar, pausar, retomar,
 * cancelar, resultados). Regras em includes/campanhas.php.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/lead_componentes.php';
require_once __DIR__ . '/../includes/campanhas.php';
require_once __DIR__ . '/../includes/whatsapp_conversas.php';

$usuario = auth_require();
$crumb = 'Campanhas';
$activeNav = 'whatsapp';
if (!usuario_tem_permissao($usuario, 'gerenciar_campanhas_wa')) {
    pagina_acesso_restrito($crumb, $activeNav, 'Criar e disparar campanhas de WhatsApp');
}
$empresaId = (int) $usuario['empresa_ativa_id'];
$conta = wa_conta_da_empresa($empresaId);
$modo = isset($_GET['nova']) ? 'nova' : (isset($_GET['id']) ? 'detalhe' : 'lista');
$statusC = ['rascunho' => ['Aguardando confirmação', 'pill-amber'], 'agendada' => ['Agendada', 'pill-blue'], 'enviando' => ['Enviando', 'pill-blue'],
            'pausada' => ['Pausada', 'pill-amber'], 'concluida' => ['Concluída', 'pill-green'], 'cancelada' => ['Cancelada', 'pill-muted']];
$fmtMoeda = fn ($v) => $v === null ? '—' : 'R$ ' . number_format((float) $v, 2, ',', '.');

if ($modo === 'detalhe') {
    $camp = campanha_carregar($empresaId, (int) $_GET['id']);
    if (!$camp) {
        http_response_code(404);
        pagina_acesso_restrito($crumb, $activeNav, 'Campanha não encontrada');
    }
    $res = campanha_resultados($empresaId, (int) $camp['id']);
} elseif ($modo === 'nova') {
    $templates = campanha_templates($empresaId);
    $q = fn (string $sql) => (function () use ($sql, $empresaId) { $s = db()->prepare($sql); $s->execute(['e' => $empresaId]); return $s->fetchAll(); })();
    $empreendimentos = $q('SELECT id, nome FROM empreendimentos WHERE empresa_id = :e AND ativo = 1 ORDER BY nome');
    $etapas = array_filter(funil_etapas($empresaId), fn ($e) => !in_array($e['categoria'], funil_categorias_encerradas(), true));
    $corretores = $q('SELECT id, nome FROM usuarios WHERE empresa_id = :e AND ativo = 1 ORDER BY nome');
    $equipes = $q('SELECT id, nome FROM equipes WHERE empresa_id = :e ORDER BY nome');
    $gerentes = $q('SELECT DISTINCT u.id, u.nome FROM equipes eq JOIN usuarios u ON u.id = eq.gerente_id WHERE eq.empresa_id = :e ORDER BY u.nome');
    $supers = $q('SELECT id, nome FROM superintendencias WHERE empresa_id = :e ORDER BY nome');
} else {
    $stmt = db()->prepare('SELECT c.*, t.nome AS template_nome,
            (SELECT COUNT(*) FROM whatsapp_campanha_destinatarios d WHERE d.campanha_id = c.id AND d.status = \'enviada\') AS enviadas
        FROM whatsapp_campanhas c LEFT JOIN whatsapp_templates t ON t.id = c.template_id WHERE c.empresa_id = :e ORDER BY c.id DESC LIMIT 100');
    $stmt->execute(['e' => $empresaId]);
    $campanhas = $stmt->fetchAll();
}

/** Chips de múltipla escolha. */
function campanha_chips(string $nome, array $itens, string $chaveId = 'id', string $chaveRotulo = 'nome'): void
{
    echo '<div class="chips-escolha">';
    foreach ($itens as $k => $i) {
        $id = is_array($i) ? $i[$chaveId] : $k;
        $rot = is_array($i) ? $i[$chaveRotulo] : $i;
        echo '<label class="dia-chip"><input type="checkbox" data-filtro="' . e($nome) . '" value="' . e((string) $id) . '"><span>' . e($rot) . '</span></label>';
    }
    echo '</div>';
}

$pageTitle = 'Campanhas — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div>
        <div class="eyebrow">WhatsApp</div>
        <h1><?= $modo === 'nova' ? 'Nova campanha' : ($modo === 'detalhe' ? e($camp['nome']) : 'Campanhas de remarketing') ?></h1>
        <p>Mensagens de ofertas e novidades só para quem <b>autorizou</b> receber — com template de marketing aprovado pela Meta, em lotes e dentro das regras.</p>
    </div>
    <?php if ($modo === 'lista'): ?><div class="actions"><a class="btn btn-primary" href="/pages/campanhas.php?nova=1"><?= icon('plus') ?>Nova campanha</a></div><?php endif; ?>
</div>
<?php wa_render_abas($usuario, 'campanhas'); ?>
<?php if ($conta && (int) $conta['modo_teste'] === 1): ?>
    <div class="wa-modo is-teste" role="status"><?= icon('alert') ?><div><b>Modo de teste ligado</b><span>As campanhas rodam de verdade no sistema, mas nenhuma mensagem sai para os clientes (ficam “simuladas”).</span></div></div>
<?php elseif (!$conta): ?>
    <div class="wa-modo is-parado" role="status"><?= icon('alert') ?><div><b>WhatsApp não conectado</b><span>Conecte o WhatsApp da empresa (ou ligue o modo de teste) para criar campanhas.</span></div></div>
<?php endif; ?>

<?php if ($modo === 'lista'): ?>
    <div class="card">
        <?php if ($campanhas): ?>
            <div class="table-wrap"><table class="report-table">
                <thead><tr><th>Campanha</th><th>Template</th><th>Situação</th><th>Contatos</th><th>Enviadas</th><th>Criada em</th></tr></thead>
                <tbody>
                <?php foreach ($campanhas as $c): [$rot, $pill] = $statusC[$c['status']] ?? [$c['status'], 'pill-muted']; ?>
                    <tr>
                        <td data-rotulo="Campanha"><a class="lead-link" href="/pages/campanhas.php?id=<?= (int) $c['id'] ?>"><?= e($c['nome']) ?></a></td>
                        <td data-rotulo="Template"><?= e($c['template_nome'] ?? '—') ?></td>
                        <td data-rotulo="Situação"><span class="pill <?= $pill ?>"><?= e($rot) ?></span></td>
                        <td data-rotulo="Contatos"><?= (int) $c['total_elegiveis'] ?></td>
                        <td data-rotulo="Enviadas"><?= (int) $c['enviadas'] ?></td>
                        <td data-rotulo="Criada em"><?= e(date('d/m/Y H:i', strtotime($c['criado_em']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php else: ?>
            <div class="stub-card" style="box-shadow:none;">
                <h2>Nenhuma campanha ainda</h2>
                <p>Crie uma campanha para falar com clientes da base que autorizaram receber ofertas — por empreendimento, etapa, corretor ou uma planilha.</p>
                <a class="btn btn-primary" href="/pages/campanhas.php?nova=1"><?= icon('plus') ?>Nova campanha</a>
            </div>
        <?php endif; ?>
    </div>

<?php elseif ($modo === 'nova'): ?>
    <div class="camp-layout" id="camp-nova">
        <div class="card">
            <div class="card-head"><div><h2>1. Mensagem</h2></div></div>
            <div class="form-grid">
                <div class="field"><label for="camp-nome">Nome da campanha</label><input type="text" id="camp-nome" maxlength="120" placeholder="Ex.: Lançamento — torre 2"></div>
                <div class="field"><label for="camp-template">Template de marketing aprovado</label>
                    <select id="camp-template"><option value="">Escolha…</option>
                        <?php foreach ($templates as $t): ?><option value="<?= (int) $t['id'] ?>" data-corpo="<?= e($t['corpo']) ?>"><?= e($t['nome']) ?></option><?php endforeach; ?>
                    </select>
                    <?php if (!$templates): ?><small class="field-hint">Nenhum template de MARKETING aprovado. Crie na Meta e sincronize em WhatsApp → Templates.</small><?php endif; ?>
                </div>
            </div>
            <p class="je-previa" id="camp-corpo" hidden></p>

            <div class="card-head" style="margin-top:10px;"><div><h2>2. Quem recebe</h2><p>Deixe em branco o que não quiser filtrar. Só entra quem autorizou ofertas.</p></div></div>
            <?php if ($empreendimentos): ?><div class="field"><span class="label-like">Empreendimento</span><?php campanha_chips('empreendimento_ids', $empreendimentos); ?></div><?php endif; ?>
            <div class="field"><span class="label-like">Etapa do Kanban</span><?php campanha_chips('etapa_ids', $etapas); ?></div>
            <div class="field"><span class="label-like">Origem do lead</span><?php campanha_chips('origens', lead_origens()); ?></div>
            <div class="form-grid">
                <div class="field"><label for="f-de">Cadastrado a partir de</label><input type="date" id="f-de" data-filtro-valor="cadastro_de"></div>
                <div class="field"><label for="f-ate">Cadastrado até</label><input type="date" id="f-ate" data-filtro-valor="cadastro_ate"></div>
                <div class="field"><label for="f-sa">Sem atendimento há pelo menos (dias)</label><input type="number" min="1" max="3650" id="f-sa" data-filtro-valor="sem_atendimento_dias"></div>
                <div class="field"><label for="f-sr">Sem resposta do cliente há (dias)</label><input type="number" min="1" max="3650" id="f-sr" data-filtro-valor="sem_resposta_dias"></div>
                <div class="field"><label for="f-rc">Não repetir quem recebeu campanha nos últimos (dias)</label><input type="number" min="0" max="90" id="f-rc" value="7" data-filtro-valor="excluir_campanha_dias"></div>
            </div>
            <details class="ajuste-box"><summary><?= icon('users') ?>Corretor, equipe, gerente, superintendência</summary>
                <?php if ($corretores): ?><div class="field"><span class="label-like">Corretor responsável</span><?php campanha_chips('corretor_ids', $corretores); ?></div><?php endif; ?>
                <?php if ($equipes): ?><div class="field"><span class="label-like">Equipe</span><?php campanha_chips('equipe_ids', $equipes); ?></div><?php endif; ?>
                <?php if ($gerentes): ?><div class="field"><span class="label-like">Gerente</span><?php campanha_chips('gerente_ids', $gerentes); ?></div><?php endif; ?>
                <?php if ($supers): ?><div class="field"><span class="label-like">Superintendência</span><?php campanha_chips('superintendencia_ids', $supers); ?></div><?php endif; ?>
            </details>
            <details class="ajuste-box"><summary><?= icon('upload') ?>Usar uma planilha de telefones (opcional)</summary>
                <p class="muted">Só os telefones da planilha que já são leads <b>com autorização registrada</b> recebem. Estar na planilha não é autorização.</p>
                <input type="file" id="camp-planilha" accept=".csv,.xlsx">
                <p class="muted" id="camp-planilha-info"></p>
            </details>
            <button type="button" class="btn btn-secondary" id="camp-previa-btn"><?= icon('users') ?>Ver quem vai receber</button>
        </div>

        <aside class="card camp-previa" id="camp-previa" hidden>
            <div class="card-head"><div><h2>3. Confira antes de criar</h2></div></div>
            <div class="camp-numero"><b id="pv-elegiveis">0</b><span>contato(s) vão receber</span></div>
            <p class="muted" id="pv-total"></p>
            <ul class="regra-lista" id="pv-excluidos"></ul>
            <div class="roleta-detail-row"><span class="label">Custo estimado</span><strong id="pv-custo">—</strong></div>
            <div class="subhead">Limites que valem</div>
            <ul class="camp-limites" id="pv-limites"></ul>
            <p class="muted" id="pv-amostra"></p>
            <div class="field"><label for="camp-agendar">Agendar início <small class="muted">(opcional)</small></label><input type="datetime-local" id="camp-agendar"></div>
            <div class="form-alert" id="camp-erro" hidden></div>
            <button type="button" class="btn btn-primary" id="camp-criar-btn"><?= icon('check') ?>Criar campanha</button>
            <small class="field-hint">Criar não envia nada: na próxima tela você confirma.</small>
        </aside>
    </div>
    <script src="<?= asset('/assets/js/campanhas.js') ?>" defer></script>

<?php else: [$rot, $pill] = $statusC[$camp['status']] ?? [$camp['status'], 'pill-muted']; $dados = $camp['dados']; ?>
    <a class="back-link" href="/pages/campanhas.php">← Todas as campanhas</a>
    <div class="grid-2 camp-detalhe">
        <div class="card">
            <div class="card-head"><div><h2>Situação</h2></div><span class="pill <?= $pill ?>"><?= e($rot) ?></span></div>
            <div class="roleta-detail-row"><span class="label">Template</span><strong><?= e($camp['template_nome'] ?? '—') ?></strong></div>
            <?php if ($camp['template_corpo']): ?><p class="je-previa">“<?= e($camp['template_corpo']) ?>”</p><?php endif; ?>
            <div class="roleta-detail-row"><span class="label">Contatos autorizados</span><strong><?= (int) $camp['total_elegiveis'] ?></strong></div>
            <div class="roleta-detail-row"><span class="label">Custo estimado</span><strong><?= $fmtMoeda($camp['custo_estimado']) ?></strong></div>
            <?php if ($camp['agendada_para']): ?><div class="roleta-detail-row"><span class="label">Início agendado</span><strong><?= e(date('d/m/Y H:i', strtotime($camp['agendada_para']))) ?></strong></div><?php endif; ?>
            <div class="roleta-detail-row"><span class="label">Criada por</span><strong><?= e(($camp['criado_por_nome'] ?? '—') . ' em ' . date('d/m H:i', strtotime($camp['criado_em']))) ?></strong></div>
            <?php if ($camp['confirmada_em']): ?><div class="roleta-detail-row"><span class="label">Confirmada por</span><strong><?= e(($camp['confirmada_por_nome'] ?? '—') . ' em ' . date('d/m H:i', strtotime($camp['confirmada_em']))) ?></strong></div><?php endif; ?>
            <?php if (!empty($dados['excluidos'])): ?>
                <div class="subhead">Ficaram de fora na criação</div>
                <ul class="regra-lista"><?php foreach ($dados['excluidos'] as $x): ?><li class="is-no"><?= icon('x') ?><span><?= (int) $x['quantidade'] ?> — <?= e($x['motivo']) ?></span></li><?php endforeach; ?></ul>
            <?php endif; ?>

            <?php if ($camp['status'] === 'rascunho'): ?>
                <form data-api-form action="/api/campanhas.php" class="camp-confirmar">
                    <input type="hidden" name="acao" value="confirmar"><input type="hidden" name="id" value="<?= (int) $camp['id'] ?>">
                    <div class="rule-box"><?= icon('alert') ?><span>Ao confirmar, <b><?= (int) $camp['total_elegiveis'] ?> contato(s)</b> vão receber esta mensagem<?= $camp['agendada_para'] ? ' a partir de ' . e(date('d/m H:i', strtotime($camp['agendada_para']))) : '' ?>, em lotes, dentro do horário de envio.</span></div>
                    <div class="field"><label for="conf-qtd">Para confirmar, digite o número de contatos (<?= (int) $camp['total_elegiveis'] ?>)</label>
                        <input type="number" id="conf-qtd" name="confirmacao" required inputmode="numeric"></div>
                    <div class="form-alert" data-form-alerta hidden></div>
                    <button type="submit" class="btn btn-primary"><?= icon('check') ?>Confirmar e iniciar</button>
                </form>
            <?php endif; ?>
            <div class="actions" style="margin-top:14px;">
                <?php if (in_array($camp['status'], ['enviando', 'agendada'], true)): ?>
                    <button type="button" class="btn btn-outline" data-api-acao="/api/campanhas.php" data-acao="pausar" data-id="<?= (int) $camp['id'] ?>">Pausar</button>
                <?php elseif ($camp['status'] === 'pausada'): ?>
                    <button type="button" class="btn btn-primary" data-api-acao="/api/campanhas.php" data-acao="retomar" data-id="<?= (int) $camp['id'] ?>">Retomar</button>
                <?php endif; ?>
                <?php if (in_array($camp['status'], ['rascunho', 'agendada', 'enviando', 'pausada'], true)): ?>
                    <button type="button" class="remove-link" data-api-acao="/api/campanhas.php" data-acao="cancelar" data-id="<?= (int) $camp['id'] ?>" data-confirmar="Cancelar a campanha? O que ainda não saiu é cancelado e não pode ser retomado.">Cancelar campanha</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="card">
            <div class="card-head"><div><h2>Resultados</h2><p>Atualizado a cada rodada da rotina automática. “Respondeu” = o cliente escreveu em até 7 dias.</p></div></div>
            <div class="kpi-grid kpi-grid-3 camp-kpis">
                <div class="kpi"><span class="label">Enviadas</span><div class="kpi-value"><?= $res['enviadas'] ?></div><div class="kpi-sub"><?= $res['simuladas'] ? $res['simuladas'] . ' simulada(s) no teste' : 'pela Meta' ?></div></div>
                <div class="kpi"><span class="label">Entregues</span><div class="kpi-value"><?= $res['entregues'] ?></div><div class="kpi-sub"><?= $res['lidas'] ?> lida(s)</div></div>
                <div class="kpi"><span class="label">Responderam</span><div class="kpi-value"><?= $res['respostas'] ?></div><div class="kpi-sub">custo/resposta <?= $fmtMoeda($res['custo_por_resposta']) ?></div></div>
            </div>
            <div class="roleta-detail-row"><span class="label">Aguardando o próximo lote</span><strong><?= $res['aguardando'] ?></strong></div>
            <div class="roleta-detail-row"><span class="label">Na fila de envio</span><strong><?= $res['na_fila'] ?></strong></div>
            <div class="roleta-detail-row"><span class="label">Não enviadas (regras: limite, descadastro…)</span><strong><?= $res['nao_enviadas'] ?></strong></div>
            <div class="roleta-detail-row"><span class="label">Falharam na Meta</span><strong><?= $res['falharam'] ?></strong></div>
            <div class="roleta-detail-row"><span class="label">Canceladas</span><strong><?= $res['canceladas'] ?></strong></div>
            <div class="roleta-detail-row"><span class="label">Pediram para sair</span><strong><?= $res['descadastros'] ?></strong></div>
            <div class="roleta-detail-row"><span class="label">Custo (mensagens cobradas)</span><strong><?= $fmtMoeda($res['custo']) ?></strong></div>
        </div>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/layout_end.php'; ?>
