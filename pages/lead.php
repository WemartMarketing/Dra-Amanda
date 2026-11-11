<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/leads.php';

$usuario = auth_require();
$empresaId = (int) $usuario['empresa_ativa_id'];
$leadId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// ---------------------------------------------------------------- Criação
if (!$leadId) {
    $erroCriacao = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $nome = trim($_POST['nome'] ?? '');
        if (!csrf_validar_requisicao()) {
            $erroCriacao = 'Sessão expirada, tente novamente.';
        } elseif ($nome === '') {
            $erroCriacao = 'Informe ao menos o nome do cliente.';
        } else {
            $stmt = db()->prepare('
                INSERT INTO leads (empresa_id, empreendimento_id, equipe_id, responsavel_id, nome, telefone, email, interesse, origem, etapa)
                VALUES (:empresa_id, :empreendimento_id, :equipe_id, :responsavel_id, :nome, :telefone, :email, :interesse, :origem, \'novo_lead\')
            ');
            $stmt->execute([
                'empresa_id' => $empresaId,
                'empreendimento_id' => $_POST['empreendimento_id'] ?: null,
                'equipe_id' => $usuario['equipe_id'] ?: null,
                'responsavel_id' => (int) $usuario['id'],
                'nome' => $nome,
                'telefone' => trim($_POST['telefone'] ?? '') ?: null,
                'email' => trim($_POST['email'] ?? '') ?: null,
                'interesse' => trim($_POST['interesse'] ?? '') ?: null,
                'origem' => $_POST['origem'] ?: 'manual',
            ]);
            $novoId = (int) db()->lastInsertId();
            lead_registrar_evento($novoId, (int) $usuario['id'], 'atribuicao', 'Lead criado manualmente e atribuído a ' . $usuario['nome']);
            header('Location: /pages/lead.php?id=' . $novoId);
            exit;
        }
    }
    $stmtEmp = db()->prepare('SELECT id, nome FROM empreendimentos WHERE empresa_id = :empresa_id AND ativo = 1 ORDER BY nome');
    $stmtEmp->execute(['empresa_id' => $empresaId]);
    $empreendimentos = $stmtEmp->fetchAll();

    $pageTitle = 'Novo lead — ' . APP_NAME;
    $crumb = 'Ficha do lead';
    $activeNav = 'ficha';
    require __DIR__ . '/../includes/layout_start.php';
    ?>
    <div class="header">
        <div><div class="eyebrow">Novo atendimento</div><h1>Cadastrar lead</h1><p>Preencha os dados básicos para começar o atendimento.</p></div>
    </div>
    <div class="card" style="max-width:560px;">
        <?php if ($erroCriacao): ?><div class="login-error"><?= htmlspecialchars($erroCriacao) ?></div><?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <div class="field"><label>Nome do cliente</label><input type="text" name="nome" required value="<?= htmlspecialchars($_POST['nome'] ?? '') ?>"></div>
            <div class="field"><label>Telefone</label><input type="text" name="telefone" placeholder="(11) 90000-0000" value="<?= htmlspecialchars($_POST['telefone'] ?? '') ?>"></div>
            <div class="field"><label>E-mail</label><input type="text" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"></div>
            <div class="field"><label>Empreendimento de interesse</label>
                <select name="empreendimento_id">
                    <option value="">Não informado</option>
                    <?php foreach ($empreendimentos as $emp): ?><option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['nome']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label>Interesse</label><input type="text" name="interesse" placeholder="Ex.: 2 dormitórios" value="<?= htmlspecialchars($_POST['interesse'] ?? '') ?>"></div>
            <div class="field"><label>Origem</label>
                <select name="origem">
                    <option value="manual">Manual</option>
                    <option value="meta_ads">Meta Ads</option>
                    <option value="indicacao">Indicação</option>
                    <option value="importacao">Importação</option>
                    <option value="outro">Outro</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary"><?= icon('plus') ?>Criar lead</button>
        </form>
    </div>
    <?php require __DIR__ . '/../includes/layout_end.php'; ?>
    <?php exit; ?>
<?php } ?>
<?php
// ---------------------------------------------------------------- Ficha
if (!rbac_pode_ver_lead($usuario, $leadId)) {
    http_response_code(403);
    $pageTitle = 'Acesso negado — ' . APP_NAME;
    $crumb = 'Ficha do lead';
    $activeNav = 'ficha';
    require __DIR__ . '/../includes/layout_start.php';
    echo '<div class="card stub-card"><div class="stub-icon">' . icon('alert') . '</div><h2>Você não tem acesso a este lead</h2><p>Ele pertence a outra carteira ou equipe.</p></div>';
    require __DIR__ . '/../includes/layout_end.php';
    exit;
}

$stmt = db()->prepare('
    SELECT leads.*, empreendimentos.nome AS empreendimento_nome, usuarios.nome AS responsavel_nome
    FROM leads
    LEFT JOIN empreendimentos ON empreendimentos.id = leads.empreendimento_id
    LEFT JOIN usuarios ON usuarios.id = leads.responsavel_id
    WHERE leads.id = :id
');
$stmt->execute(['id' => $leadId]);
$lead = $stmt->fetch();

$stmtTarefas = db()->prepare('SELECT * FROM lead_tarefas WHERE lead_id = :id ORDER BY concluida ASC, data_vencimento IS NULL, data_vencimento ASC');
$stmtTarefas->execute(['id' => $leadId]);
$tarefas = $stmtTarefas->fetchAll();

$stmtHist = db()->prepare('
    SELECT lead_eventos.*, usuarios.nome AS usuario_nome
    FROM lead_eventos LEFT JOIN usuarios ON usuarios.id = lead_eventos.usuario_id
    WHERE lead_id = :id ORDER BY criado_em DESC
');
$stmtHist->execute(['id' => $leadId]);
$historico = $stmtHist->fetchAll();

$iniciais = mb_strtoupper(mb_substr($lead['nome'], 0, 1) . mb_substr(strrchr($lead['nome'], ' ') ?: '', 1, 1));
$origemRotulo = ['meta_ads' => 'Meta Ads', 'manual' => 'Manual', 'importacao' => 'Importação', 'indicacao' => 'Indicação', 'outro' => 'Outro'][$lead['origem']] ?? $lead['origem'];

$pageTitle = htmlspecialchars($lead['nome']) . ' — ' . APP_NAME;
$crumb = 'Ficha do lead';
$activeNav = 'ficha';
require __DIR__ . '/../includes/layout_start.php';
?>

<div class="header">
    <div>
        <div class="eyebrow">Atendimento / Lead #<?= 10000 + (int) $lead['id'] ?></div>
        <h1><?= htmlspecialchars($lead['nome']) ?></h1>
        <p><?= htmlspecialchars($lead['empreendimento_nome'] ?? 'Sem produto') ?> · Origem: <?= htmlspecialchars($origemRotulo) ?> · Responsável: <?= htmlspecialchars($lead['responsavel_nome'] ?? '—') ?></p>
    </div>
    <div class="actions"><a href="/pages/kanban.php" class="btn btn-outline"><?= icon('arrow-r') ?>Voltar ao Kanban</a></div>
</div>

<div class="lead-tabs">
    <?php
    $funil = lead_etapas_funil();
    $idxAtual = array_search($lead['etapa'], $funil, true);
    foreach ($funil as $i => $etapa):
        $classe = 'lead-tab';
        if ($lead['etapa'] === 'perdido') { $classe .= ''; }
        elseif ($i < $idxAtual) $classe .= ' done';
        elseif ($i === $idxAtual) $classe .= ' current';
    ?>
        <span class="<?= $classe ?>"><?= $i < $idxAtual ? '✓ ' : ($i === $idxAtual ? '● ' : '') ?><?= htmlspecialchars(lead_etapa_rotulo($etapa)) ?></span>
    <?php endforeach; ?>
    <?php if ($lead['etapa'] === 'perdido'): ?><span class="lead-tab current" style="background:var(--red);border-color:var(--red);color:#fff;">Perdido</span><?php endif; ?>
</div>

<div class="lead-grid">
    <div class="card">
        <div class="avatar lead-side-avatar"><?= htmlspecialchars($iniciais) ?></div>
        <h2 class="lead-name"><?= htmlspecialchars($lead['nome']) ?></h2>
        <span class="pill pill-muted lead-stage-pill"><?= htmlspecialchars(lead_etapa_rotulo($lead['etapa'])) ?></span>
        <div class="lead-meta-date">Lead recebido em <?= date('d/m/Y, H\hi', strtotime($lead['criado_em'])) ?></div>

        <div class="lead-contact-btns">
            <?php if ($lead['telefone']): ?>
                <a href="tel:<?= preg_replace('/\D/', '', $lead['telefone']) ?>" class="btn btn-primary"><?= icon('phone') ?>Ligar agora</a>
                <a href="https://wa.me/55<?= preg_replace('/\D/', '', $lead['telefone']) ?>" target="_blank" rel="noopener" class="btn btn-success"><?= icon('whatsapp') ?>WhatsApp</a>
            <?php else: ?>
                <span style="color:#8893a4;font-size:13px;">Sem telefone cadastrado.</span>
            <?php endif; ?>
        </div>

        <h3 style="font-size:14px;margin:0 0 14px;">Informações do cliente</h3>
        <div class="info-block"><label>Telefone</label><div><?= htmlspecialchars(lead_formatar_telefone($lead['telefone'])) ?></div></div>
        <div class="info-block"><label>Interesse</label><div><?= htmlspecialchars($lead['interesse'] ?: '—') ?></div></div>
        <?php if ($lead['renda_informada']): ?>
            <div class="info-block"><label>Renda familiar informada</label><div>R$ <?= number_format((float) $lead['renda_informada'], 0, ',', '.') ?></div></div>
        <?php endif; ?>
        <div class="info-block"><label>Origem</label><div><?= htmlspecialchars($origemRotulo) ?></div></div>
    </div>

    <div class="card">
        <h2 style="font-size:17px;margin:0 0 4px;">Atualizar atendimento</h2>
        <p style="margin:0 0 18px;color:#8893a4;font-size:13px;">Registre o que aconteceu com o cliente.</p>

        <form method="post" action="/api/lead_atualizar.php" id="form-atendimento">
            <?= csrf_field() ?>
            <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
            <div class="field">
                <label>Etapa atual</label>
                <select name="etapa">
                    <?php foreach (lead_etapas_funil() as $etapa): ?>
                        <option value="<?= $etapa ?>" <?= $lead['etapa'] === $etapa ? 'selected' : '' ?>><?= htmlspecialchars(lead_etapa_rotulo($etapa)) ?></option>
                    <?php endforeach; ?>
                    <option value="perdido" <?= $lead['etapa'] === 'perdido' ? 'selected' : '' ?>>Perdido</option>
                </select>
            </div>
            <div class="field">
                <label>O que conversou com o cliente?</label>
                <textarea name="nota" placeholder="Ex.: Cliente interessado em 2 dormitórios. Solicitar documentos e confirmar visita."></textarea>
            </div>
            <div class="field">
                <label>Próximo passo</label>
                <select name="proximo_passo">
                    <option value="">—</option>
                    <option value="Ligar novamente" <?= $lead['proximo_passo'] === 'Ligar novamente' ? 'selected' : '' ?>>Ligar novamente</option>
                    <option value="Enviar proposta" <?= $lead['proximo_passo'] === 'Enviar proposta' ? 'selected' : '' ?>>Enviar proposta</option>
                    <option value="Confirmar visita" <?= $lead['proximo_passo'] === 'Confirmar visita' ? 'selected' : '' ?>>Confirmar visita</option>
                    <option value="Aguardar documentos" <?= $lead['proximo_passo'] === 'Aguardar documentos' ? 'selected' : '' ?>>Aguardar documentos</option>
                </select>
            </div>
            <div class="field">
                <label>Data do retorno</label>
                <input type="date" name="data_retorno" value="<?= htmlspecialchars($lead['data_retorno'] ?? '') ?>">
            </div>
            <button type="submit" class="btn btn-primary"><?= icon('check') ?>Salvar atendimento</button>
        </form>

        <h3 style="font-size:15px;margin:28px 0 12px;border-top:1px solid var(--line);padding-top:20px;">Histórico</h3>
        <div class="timeline">
            <?php foreach ($historico as $evento): ?>
                <div class="timeline-item">
                    <div class="timeline-dot"></div>
                    <div>
                        <strong><?= htmlspecialchars($evento['descricao']) ?></strong>
                        <?php if ($evento['usuario_nome']): ?><span><?= htmlspecialchars($evento['usuario_nome']) ?></span><?php endif; ?>
                        <small><?= date('d/m/Y, H\hi', strtotime($evento['criado_em'])) ?></small>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$historico): ?><p style="color:#8893a4;font-size:13px;">Sem eventos registrados ainda.</p><?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h2 style="font-size:17px;margin:0 0 16px;">Próximas ações</h2>
        <div id="lista-tarefas">
            <?php foreach ($tarefas as $t): ?>
                <div class="next-task <?= $t['concluida'] ? 'done' : '' ?>" data-tarefa="<?= $t['id'] ?>">
                    <input type="checkbox" <?= $t['concluida'] ? 'checked' : '' ?> data-toggle-tarefa="<?= $t['id'] ?>">
                    <div>
                        <strong><?= htmlspecialchars($t['titulo']) ?></strong>
                        <?php if ($t['descricao']): ?><span><?= htmlspecialchars($t['descricao']) ?></span><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <form id="form-nova-tarefa" style="display:flex;gap:8px;margin-top:4px;">
            <?= csrf_field() ?>
            <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
            <input type="text" name="titulo" placeholder="Nova tarefa..." style="flex:1;border:1px solid var(--line);border-radius:10px;padding:9px 12px;font-size:13px;" required>
            <button type="submit" class="btn btn-ghost"><?= icon('plus') ?></button>
        </form>

        <div class="rule-box"><?= icon('alert') ?> <b>Regra de atenção:</b> leads sem feedback por 4 dias podem ser redistribuídos.</div>
    </div>
</div>

<script>
document.getElementById('form-nova-tarefa').addEventListener('submit', async function (e) {
    e.preventDefault();
    const fd = new FormData(this);
    const r = await fetch('/api/tarefa_criar.php', { method: 'POST', body: fd });
    if (r.ok) location.reload();
});
document.querySelectorAll('[data-toggle-tarefa]').forEach(function (cb) {
    cb.addEventListener('change', async function () {
        await fetch('/api/tarefa_toggle.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ tarefa_id: this.dataset.toggleTarefa, concluida: this.checked ? 1 : 0, csrf_token: csrfToken() }),
        });
        this.closest('.next-task').classList.toggle('done', this.checked);
    });
});
</script>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
