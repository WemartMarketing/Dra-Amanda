<?php
/**
 * Estrutura comercial — Superintendência → Equipes → Gerente + Corretores.
 * Permissão: gerenciar_equipes (tela inteira; sem modo somente leitura).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/estrutura.php';

$usuario = auth_require();
if (!empty($usuario['eh_superadmin'])) {
    header('Location: /pages/superadmin.php');
    exit;
}
if (!usuario_tem_permissao($usuario, 'gerenciar_equipes')) {
    pagina_acesso_restrito('Equipes', 'equipes', 'Gerenciar equipes e estrutura');
}
$empresaId = (int) $usuario['empresa_ativa_id'];

$stmt = db()->prepare('
    SELECT s.*, u.nome AS superintendente_nome
    FROM superintendencias s LEFT JOIN usuarios u ON u.id = s.superintendente_id
    WHERE s.empresa_id = :empresa_id ORDER BY s.nome
');
$stmt->execute(['empresa_id' => $empresaId]);
$superintendencias = $stmt->fetchAll();

$stmt = db()->prepare('
    SELECT e.*, u.nome AS gerente_nome
    FROM equipes e LEFT JOIN usuarios u ON u.id = e.gerente_id
    WHERE e.empresa_id = :empresa_id ORDER BY e.nome
');
$stmt->execute(['empresa_id' => $empresaId]);
$equipes = $stmt->fetchAll();

$stmt = db()->prepare('
    SELECT ee.equipe_id, emp.id, emp.nome FROM equipe_empreendimentos ee
    INNER JOIN empreendimentos emp ON emp.id = ee.empreendimento_id
    WHERE emp.empresa_id = :empresa_id ORDER BY emp.nome
');
$stmt->execute(['empresa_id' => $empresaId]);
$empreendimentosPorEquipe = [];
foreach ($stmt->fetchAll() as $row) {
    $empreendimentosPorEquipe[$row['equipe_id']][] = $row;
}

// Membros = usuários ativos de função com escopo "propria_carteira" (corretores).
$stmt = db()->prepare("
    SELECT u.id, u.nome, u.equipe_id, f.nome AS funcao_nome
    FROM usuarios u INNER JOIN funcoes f ON f.id = u.funcao_id
    WHERE u.empresa_id = :empresa_id AND u.ativo = 1 AND f.escopo = 'propria_carteira'
    ORDER BY u.nome
");
$stmt->execute(['empresa_id' => $empresaId]);
$corretores = $stmt->fetchAll();
$corretoresPorEquipe = [];
$corretoresSemEquipe = [];
foreach ($corretores as $c) {
    if ($c['equipe_id']) {
        $corretoresPorEquipe[$c['equipe_id']][] = $c;
    } else {
        $corretoresSemEquipe[] = $c;
    }
}

$opcoesUsuarios = function (string $escopo) use ($empresaId): array {
    $stmt = db()->prepare('
        SELECT u.id, u.nome FROM usuarios u INNER JOIN funcoes f ON f.id = u.funcao_id
        WHERE u.empresa_id = :empresa_id AND u.ativo = 1 AND f.escopo = :escopo ORDER BY u.nome
    ');
    $stmt->execute(['empresa_id' => $empresaId, 'escopo' => $escopo]);
    return $stmt->fetchAll();
};
$superintendentesOpt = $opcoesUsuarios('superintendencia');
$gerentesOpt = $opcoesUsuarios('equipe');

$stmt = db()->prepare('SELECT id, nome FROM empreendimentos WHERE empresa_id = :empresa_id AND ativo = 1 ORDER BY nome');
$stmt->execute(['empresa_id' => $empresaId]);
$empreendimentosOpt = $stmt->fetchAll();

$equipesPorSuper = [];
$equipesSemSuper = [];
foreach ($equipes as $e) {
    if ($e['superintendencia_id']) {
        $equipesPorSuper[$e['superintendencia_id']][] = $e;
    } else {
        $equipesSemSuper[] = $e;
    }
}

function iniciais(string $nome): string
{
    return mb_strtoupper(mb_substr($nome, 0, 1) . mb_substr(strrchr($nome, ' ') ?: '', 1, 1));
}

/** Bloco de uma equipe (gerente, empreendimentos, corretores e ações). */
function renderEquipe(array $e, array $empreendimentosPorEquipe, array $corretoresPorEquipe, array $corretoresSemEquipe, array $equipes): void
{
    $membros = $corretoresPorEquipe[$e['id']] ?? [];
    $emps = $empreendimentosPorEquipe[$e['id']] ?? [];
    ?>
    <div class="team-card">
        <div class="team-head">
            <div>
                <h3><?= htmlspecialchars($e['nome']) ?></h3>
                <span class="team-sub"><?= icon('user') ?>Gerente: <strong><?= $e['gerente_nome'] ? htmlspecialchars($e['gerente_nome']) : 'não definido' ?></strong></span>
            </div>
            <button type="button" class="btn btn-outline btn-sm" data-open-modal="modal-equipe" data-modo="editar"
                data-id="<?= $e['id'] ?>" data-nome="<?= htmlspecialchars($e['nome']) ?>"
                data-superintendencia-id="<?= (int) $e['superintendencia_id'] ?>" data-gerente-id="<?= (int) $e['gerente_id'] ?>"
                data-empreendimentos="<?= implode(',', array_column($emps, 'id')) ?>">Editar equipe</button>
        </div>
        <div class="chip-row">
            <?php foreach ($emps as $emp): ?><span class="pill pill-muted"><?= icon('building') ?><?= htmlspecialchars($emp['nome']) ?></span><?php endforeach; ?>
            <?php if (!$emps): ?><span class="muted">Nenhum empreendimento vinculado</span><?php endif; ?>
        </div>
        <div class="member-list">
            <?php foreach ($membros as $m): ?>
                <div class="member-row">
                    <div class="avatar"><?= htmlspecialchars(iniciais($m['nome'])) ?></div>
                    <div class="who-info"><strong><?= htmlspecialchars($m['nome']) ?></strong><span><?= htmlspecialchars($m['funcao_nome']) ?></span></div>
                    <form class="member-move" data-form-membro>
                        <input type="hidden" name="usuario_id" value="<?= $m['id'] ?>">
                        <label class="sr-only" for="mover-<?= $m['id'] ?>">Mover <?= htmlspecialchars($m['nome']) ?></label>
                        <select name="equipe_id" id="mover-<?= $m['id'] ?>" data-auto-submit>
                            <?php foreach ($equipes as $outra): ?>
                                <option value="<?= $outra['id'] ?>" <?= (int) $outra['id'] === (int) $e['id'] ? 'selected' : '' ?>><?= (int) $outra['id'] === (int) $e['id'] ? 'Nesta equipe' : 'Mover para ' . htmlspecialchars($outra['nome']) ?></option>
                            <?php endforeach; ?>
                            <option value="">Tirar da equipe</option>
                        </select>
                    </form>
                </div>
            <?php endforeach; ?>
            <?php if (!$membros): ?><p class="empty-note">Nenhum corretor nesta equipe ainda.</p><?php endif; ?>
        </div>
        <?php if ($corretoresSemEquipe): ?>
            <form class="add-row" data-form-membro>
                <input type="hidden" name="equipe_id" value="<?= $e['id'] ?>">
                <select name="usuario_id" required aria-label="Adicionar corretor à equipe <?= htmlspecialchars($e['nome']) ?>">
                    <option value="">Adicionar corretor sem equipe...</option>
                    <?php foreach ($corretoresSemEquipe as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nome']) ?></option><?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-outline btn-sm">Adicionar</button>
            </form>
        <?php endif; ?>
    </div>
    <?php
}

$pageTitle = 'Equipes — ' . APP_NAME;
$crumb = 'Equipes';
$activeNav = 'equipes';
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div><div class="eyebrow">Estrutura comercial</div><h1>Equipes e superintendências</h1><p>Organize quem lidera cada equipe e quais corretores fazem parte dela.</p></div>
    <div class="actions">
        <button type="button" class="btn btn-outline" data-open-modal="modal-super" data-modo="criar"><?= icon('plus') ?>Nova superintendência</button>
        <button type="button" class="btn btn-primary" data-open-modal="modal-equipe" data-modo="criar"><?= icon('plus') ?>Nova equipe</button>
    </div>
</div>

<div class="kpi-grid">
    <div class="kpi"><div class="kpi-top"><span class="label">Superintendências</span><div class="kpi-icon"><?= icon('building') ?></div></div><div class="kpi-value"><?= count($superintendencias) ?></div><div class="kpi-sub">hierarquias comerciais</div></div>
    <div class="kpi"><div class="kpi-top"><span class="label">Equipes</span><div class="kpi-icon"><?= icon('users') ?></div></div><div class="kpi-value"><?= count($equipes) ?></div><div class="kpi-sub">com gerente responsável</div></div>
    <div class="kpi"><div class="kpi-top"><span class="label">Corretores ativos</span><div class="kpi-icon"><?= icon('user') ?></div></div><div class="kpi-value"><?= count($corretores) ?></div><div class="kpi-sub">em toda a empresa</div></div>
    <div class="kpi"><div class="kpi-top"><span class="label">Sem equipe</span><div class="kpi-icon"><?= icon('alert') ?></div></div><div class="kpi-value"><?= count($corretoresSemEquipe) ?></div><div class="kpi-sub">corretores para organizar</div></div>
</div>

<?php foreach ($superintendencias as $s): ?>
    <section class="card super-card">
        <div class="card-head">
            <div>
                <div class="eyebrow">Superintendência</div>
                <h2><?= htmlspecialchars($s['nome']) ?></h2>
                <p>Superintendente: <strong><?= $s['superintendente_nome'] ? htmlspecialchars($s['superintendente_nome']) : 'não definido' ?></strong></p>
            </div>
            <div class="actions">
                <button type="button" class="btn btn-outline btn-sm" data-open-modal="modal-super" data-modo="editar"
                    data-id="<?= $s['id'] ?>" data-nome="<?= htmlspecialchars($s['nome']) ?>" data-superintendente-id="<?= (int) $s['superintendente_id'] ?>">Editar</button>
                <?php if (empty($equipesPorSuper[$s['id']])): ?>
                    <button type="button" class="link-btn" data-excluir-super="<?= $s['id'] ?>">Excluir</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="team-grid">
            <?php foreach ($equipesPorSuper[$s['id']] ?? [] as $e) { renderEquipe($e, $empreendimentosPorEquipe, $corretoresPorEquipe, $corretoresSemEquipe, $equipes); } ?>
            <?php if (empty($equipesPorSuper[$s['id']])): ?><p class="empty-note">Nenhuma equipe nesta superintendência ainda.</p><?php endif; ?>
        </div>
    </section>
<?php endforeach; ?>

<?php if ($equipesSemSuper): ?>
    <section class="card super-card">
        <div class="card-head"><div><div class="eyebrow">Sem superintendência</div><h2>Equipes avulsas</h2><p>Edite a equipe para colocá-la em uma superintendência.</p></div></div>
        <div class="team-grid">
            <?php foreach ($equipesSemSuper as $e) { renderEquipe($e, $empreendimentosPorEquipe, $corretoresPorEquipe, $corretoresSemEquipe, $equipes); } ?>
        </div>
    </section>
<?php endif; ?>

<?php if (!$superintendencias && !$equipes): ?>
    <div class="card stub-card"><div class="stub-icon"><?= icon('users') ?></div><h2>Comece pela estrutura</h2><p>Crie uma superintendência e depois as equipes que ficam nela.</p></div>
<?php endif; ?>

<?php if ($corretoresSemEquipe): ?>
    <section class="card">
        <div class="card-head"><div><h2>Corretores sem equipe</h2><p>Use "Adicionar corretor" dentro de uma equipe para organizar estas pessoas.</p></div><span class="pill pill-amber"><?= count($corretoresSemEquipe) ?></span></div>
        <div class="chip-row">
            <?php foreach ($corretoresSemEquipe as $c): ?><span class="pill pill-muted"><?= htmlspecialchars($c['nome']) ?></span><?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<div class="modal-overlay" id="modal-super" hidden>
    <div class="modal-box">
        <button type="button" class="overlay-close" data-close-modal="modal-super" aria-label="Fechar">&times;</button>
        <h2 data-modal-titulo data-titulo-novo="Nova superintendência" data-titulo-editar="Editar superintendência">Nova superintendência</h2>
        <form data-form-super>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="">
            <input type="hidden" name="acao" value="salvar">
            <div class="field"><label for="super-nome">Nome</label><input type="text" id="super-nome" name="nome" required placeholder="Ex.: Zona Norte"></div>
            <div class="field"><label for="super-resp">Superintendente</label>
                <select name="superintendente_id" id="super-resp">
                    <option value="">Definir depois</option>
                    <?php foreach ($superintendentesOpt as $u): ?><option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['nome']) ?></option><?php endforeach; ?>
                </select>
                <small class="field-hint">Aparecem aqui pessoas com função de escopo "Sua superintendência".</small>
            </div>
            <button type="submit" class="btn btn-primary">Salvar</button>
        </form>
    </div>
</div>

<div class="modal-overlay" id="modal-equipe" hidden>
    <div class="modal-box modal-wide">
        <button type="button" class="overlay-close" data-close-modal="modal-equipe" aria-label="Fechar">&times;</button>
        <h2 data-modal-titulo data-titulo-novo="Nova equipe" data-titulo-editar="Editar equipe">Nova equipe</h2>
        <form data-form-equipe>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="">
            <input type="hidden" name="acao" value="salvar">
            <div class="field"><label for="equipe-nome">Nome da equipe</label><input type="text" id="equipe-nome" name="nome" required placeholder="Ex.: Equipe Zona Norte"></div>
            <div class="form-grid">
                <div class="field"><label for="equipe-super">Superintendência</label>
                    <select name="superintendencia_id" id="equipe-super">
                        <option value="">Sem superintendência</option>
                        <?php foreach ($superintendencias as $s): ?><option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['nome']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label for="equipe-gerente">Gerente</label>
                    <select name="gerente_id" id="equipe-gerente">
                        <option value="">Definir depois</option>
                        <?php foreach ($gerentesOpt as $u): ?><option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['nome']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <label>Empreendimentos que esta equipe atende</label>
                <div class="checkbox-grid">
                    <?php foreach ($empreendimentosOpt as $emp): ?>
                        <label class="checkbox-row"><input type="checkbox" name="empreendimentos[]" value="<?= $emp['id'] ?>"> <?= htmlspecialchars($emp['nome']) ?></label>
                    <?php endforeach; ?>
                    <?php if (!$empreendimentosOpt): ?><span class="muted">Cadastre empreendimentos primeiro.</span><?php endif; ?>
                </div>
            </div>
            <div class="modal-actions">
                <button type="submit" class="btn btn-primary">Salvar equipe</button>
                <button type="button" class="link-btn danger" data-excluir-equipe hidden>Excluir equipe</button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
