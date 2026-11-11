<?php
/**
 * Empreendimentos — grade de cards. Leitura para quem tem visualizar_leads
 * (corretores precisam conhecer o que vendem); criar/editar exige
 * gerenciar_empreendimentos (ver pages/empreendimento.php).
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
if (!usuario_tem_permissao($usuario, 'visualizar_leads')) {
    pagina_acesso_restrito('Empreendimentos', 'empreendimentos', 'Visualizar leads');
}
$empresaId = (int) $usuario['empresa_ativa_id'];
$podeGerenciar = usuario_tem_permissao($usuario, 'gerenciar_empreendimentos');
$mostrarInativos = $podeGerenciar && !empty($_GET['inativos']);

$stmt = db()->prepare('
    SELECT e.*,
        (SELECT COUNT(*) FROM plantao_empreendimentos pe INNER JOIN plantoes p ON p.id = pe.plantao_id WHERE pe.empreendimento_id = e.id AND p.ativo = 1) AS total_plantoes,
        (SELECT COUNT(*) FROM roleta_empreendimentos re WHERE re.empreendimento_id = e.id) AS total_roletas,
        (SELECT COUNT(*) FROM equipe_empreendimentos ee WHERE ee.empreendimento_id = e.id) AS total_equipes
    FROM empreendimentos e
    WHERE e.empresa_id = :empresa_id' . ($mostrarInativos ? '' : ' AND e.ativo = 1') . '
    ORDER BY e.ativo DESC, e.nome
');
$stmt->execute(['empresa_id' => $empresaId]);
$empreendimentos = $stmt->fetchAll();

$totalInativos = 0;
if ($podeGerenciar) {
    $stmt = db()->prepare('SELECT COUNT(*) FROM empreendimentos WHERE empresa_id = :empresa_id AND ativo = 0');
    $stmt->execute(['empresa_id' => $empresaId]);
    $totalInativos = (int) $stmt->fetchColumn();
}

$rotulosStatus = empreendimento_status_rotulos();

$pageTitle = 'Empreendimentos — ' . APP_NAME;
$crumb = 'Empreendimentos';
$activeNav = 'empreendimentos';
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div><div class="eyebrow">Produtos</div><h1>Empreendimentos</h1><p><?= $podeGerenciar ? 'Cadastre e gerencie seus produtos e vinculações.' : 'Conheça os empreendimentos que você pode oferecer.' ?></p></div>
    <?php if ($podeGerenciar): ?>
        <div class="actions"><a href="/pages/empreendimento.php" class="btn btn-primary"><?= icon('plus') ?>Novo empreendimento</a></div>
    <?php endif; ?>
</div>

<?php if ($podeGerenciar && $totalInativos): ?>
    <div class="filters-row">
        <a class="btn btn-outline btn-sm" href="/pages/empreendimentos.php<?= $mostrarInativos ? '' : '?inativos=1' ?>"><?= $mostrarInativos ? 'Ocultar inativos' : 'Mostrar inativos (' . $totalInativos . ')' ?></a>
    </div>
<?php endif; ?>

<div class="emp-grid">
    <?php foreach ($empreendimentos as $e):
        $img = empreendimento_imagem_url($e['imagem']);
        $local = implode(' · ', array_filter([$e['bairro'], $e['cidade'] ? $e['cidade'] . ($e['estado'] ? '/' . $e['estado'] : '') : null]));
    ?>
        <a class="emp-card <?= $e['ativo'] ? '' : 'is-inactive' ?>" href="/pages/empreendimento.php?id=<?= $e['id'] ?>">
            <div class="emp-thumb">
                <?php if ($img): ?>
                    <img src="<?= htmlspecialchars($img) ?>" alt="Imagem de <?= htmlspecialchars($e['nome']) ?>" loading="lazy">
                <?php else: ?>
                    <div class="emp-placeholder" aria-hidden="true"><?= icon('building') ?><span><?= htmlspecialchars(mb_strtoupper(mb_substr($e['nome'], 0, 1))) ?></span></div>
                <?php endif; ?>
                <span class="pill <?= $e['ativo'] ? empreendimento_status_pill($e['status_comercial']) : 'pill-muted' ?> emp-status"><?= $e['ativo'] ? htmlspecialchars($rotulosStatus[$e['status_comercial']] ?? $e['status_comercial']) : 'Inativo' ?></span>
            </div>
            <div class="emp-body">
                <strong><?= htmlspecialchars($e['nome']) ?></strong>
                <span><?= $local ? htmlspecialchars($local) : 'Localização não informada' ?></span>
                <small><?= (int) $e['total_plantoes'] ?> plantão(ões) · <?= (int) $e['total_roletas'] ?> roleta(s) · <?= (int) $e['total_equipes'] ?> equipe(s)</small>
            </div>
        </a>
    <?php endforeach; ?>
</div>
<?php if (!$empreendimentos): ?>
    <div class="card stub-card"><div class="stub-icon"><?= icon('building') ?></div><h2>Nenhum empreendimento cadastrado</h2><p><?= $podeGerenciar ? 'Cadastre o primeiro produto para vinculá-lo a plantões, roletas e equipes.' : 'Ainda não há empreendimentos ativos nesta empresa.' ?></p></div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
