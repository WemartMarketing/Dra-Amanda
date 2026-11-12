<?php
/**
 * Painel do Superadmin WeMart — fora do layout comum de uma empresa
 * (includes/layout_start.php), porque o Superadmin não tem empresa
 * ativa nem escopo de leads nesta etapa (seção 4 do prompt mestre:
 * "não deve ter acesso indiscriminado aos dados pessoais dos leads das
 * empresas"). Esta tela só administra a lista de empresas — nada de
 * Kanban/relatórios/leads de nenhuma empresa específica.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/logo.php';

$usuario = auth_require();
if (empty($usuario['eh_superadmin'])) {
    header('Location: /pages/inicio.php');
    exit;
}

$empresas = db()->query('
    SELECT empresas.*, planos.nome AS plano_nome,
        (SELECT COUNT(*) FROM usuarios WHERE usuarios.empresa_id = empresas.id AND usuarios.ativo = 1) AS total_usuarios
    FROM empresas LEFT JOIN planos ON planos.id = empresas.plano_id
    ORDER BY empresas.nome
')->fetchAll();

$avatarIniciais = mb_strtoupper(mb_substr($usuario['nome'], 0, 1) . mb_substr(strrchr($usuario['nome'], ' ') ?: '', 1, 1));
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
<title>Superadmin — <?= htmlspecialchars(APP_NAME) ?></title>
<link rel="stylesheet" href="/assets/css/app.css">
<?= favicon_tags() ?>
</head>
<body>
<div class="content" style="margin:0;width:100%;">
    <header class="topbar">
        <div class="crumb">WeMart Sales / <strong>Superadmin</strong></div>
        <div class="topright">
            <div class="who">
                <div class="avatar"><?= htmlspecialchars($avatarIniciais) ?></div>
                <div><strong><?= htmlspecialchars($usuario['nome']) ?></strong><small>Superadmin WeMart</small></div>
            </div>
            <a href="/logout.php" class="circle-btn" title="Sair"><?= icon('logout') ?></a>
        </div>
    </header>

    <main class="container">
        <div class="header">
            <div><div class="eyebrow">Plataforma WeMart</div><h1>Empresas</h1><p>Administre as empresas que usam o WeMart Sales.</p></div>
            <div class="actions"><button type="button" class="btn btn-primary" data-open-modal="modal-empresa"><?= icon('plus') ?>Nova empresa</button></div>
        </div>

        <div class="card">
            <div class="card-head"><div><h2>Empresas cadastradas</h2><p>Esta lista não dá acesso aos leads de cada empresa — só à administração de cadastro.</p></div></div>
            <table class="report-table">
                <thead><tr><th>Empresa</th><th>Plano</th><th>Usuários</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($empresas as $e): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($e['nome']) ?></strong></td>
                        <td><?= htmlspecialchars($e['plano_nome'] ?? '—') ?></td>
                        <td><?= (int) $e['total_usuarios'] ?></td>
                        <td><span class="pill <?= $e['ativa'] ? 'pill-green' : 'pill-muted' ?>"><?= $e['ativa'] ? 'Ativa' : 'Inativa' ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (!$empresas): ?><p class="empty-note">Nenhuma empresa cadastrada ainda.</p><?php endif; ?>
        </div>
    </main>
</div>

<div class="modal-overlay" id="modal-empresa" hidden>
    <div class="modal-box">
        <button type="button" class="overlay-close" data-close-modal="modal-empresa">&times;</button>
        <h2>Nova empresa</h2>
        <p style="color:#8893a4;font-size:13.5px;margin:0 0 16px;">Cria a empresa, as 7 funções padrão e o primeiro usuário (Master da empresa).</p>
        <form id="form-empresa">
            <?= csrf_field() ?>
            <div class="field"><label>Nome da empresa</label><input type="text" name="empresa_nome" required placeholder="Ex.: Construtora ABC"></div>
            <div class="field"><label>Nome do Master</label><input type="text" name="master_nome" required></div>
            <div class="field"><label>E-mail do Master</label><input type="email" name="master_email" required></div>
            <div class="field"><label>Senha inicial</label><input type="password" name="master_senha" required autocomplete="new-password"></div>
            <button type="submit" class="btn btn-primary">Criar empresa</button>
        </form>
    </div>
</div>

<script src="/assets/js/app.js"></script>
<script>
document.getElementById('form-empresa').addEventListener('submit', async function (e) {
    e.preventDefault();
    const r = await fetch('/api/empresa_criar.php', { method: 'POST', body: new FormData(this) });
    const data = await r.json().catch(function () { return {}; });
    if (r.ok && data.ok) { location.reload(); } else { alert(data.erro || 'Não foi possível criar a empresa.'); }
});
</script>
</body>
</html>
