<?php
/**
 * Registro de auditoria (seções 15/16) — só da empresa ativa, para quem
 * tem "Ver registro de auditoria". Somente leitura.
 * Os detalhes saem escapados; chaves com cara de segredo (token, senha,
 * secret...) são mascaradas na tela mesmo que algum dia sejam gravadas.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/estrutura.php';
require_once __DIR__ . '/../includes/lead_componentes.php';
require_once __DIR__ . '/../includes/auditoria.php';

$usuario = auth_require();
$crumb = 'Auditoria';
$activeNav = 'auditoria';
if (!empty($usuario['eh_superadmin'])) {
    header('Location: /pages/superadmin.php');
    exit;
}
if (!usuario_tem_permissao($usuario, 'visualizar_auditoria')) {
    pagina_acesso_restrito($crumb, $activeNav, 'Ver registro de auditoria');
}
$empresaId = (int) $usuario['empresa_ativa_id'];

$data = fn ($k, $padrao) => (($d = DateTime::createFromFormat('!Y-m-d', (string) ($_GET[$k] ?? ''))) && $d->format('Y-m-d') === ($_GET[$k] ?? '')) ? $_GET[$k] : $padrao;
$de = $data('de', date('Y-m-d', strtotime('-30 days')));
$ate = $data('ate', date('Y-m-d'));
if ($ate < $de) {
    [$de, $ate] = [$ate, $de];
}
$fUsuario = (int) ($_GET['usuario'] ?? 0) ?: null;
$fAcao = preg_match('/^[a-z_]+(\.[a-z_]*)?$/', (string) ($_GET['acao'] ?? '')) ? $_GET['acao'] : null;
$fEntidade = preg_match('/^[a-z_]+$/', (string) ($_GET['entidade'] ?? '')) ? $_GET['entidade'] : null;
$porPagina = 50;

$where = 'a.empresa_id = :e AND a.criado_em >= :de AND a.criado_em < :ate';
$params = ['e' => $empresaId, 'de' => $de . ' 00:00:00', 'ate' => date('Y-m-d', strtotime($ate . ' +1 day')) . ' 00:00:00'];
if ($fUsuario) { $where .= ' AND a.usuario_id = :u'; $params['u'] = $fUsuario; }
if ($fAcao) {
    if (substr($fAcao, -1) === '.' || strpos($fAcao, '.') === false) { $where .= ' AND a.acao LIKE :ac'; $params['ac'] = rtrim($fAcao, '.') . '.%'; }
    else { $where .= ' AND a.acao = :ac'; $params['ac'] = $fAcao; }
}
if ($fEntidade) { $where .= ' AND a.entidade = :en'; $params['en'] = $fEntidade; }

$stmt = db()->prepare("SELECT COUNT(*) FROM auditoria a WHERE {$where}");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$paginas = max(1, (int) ceil($total / $porPagina));
$pagina = min($paginas, max(1, (int) ($_GET['pagina'] ?? 1)));
$stmt = db()->prepare("SELECT a.*, u.nome AS usuario_nome FROM auditoria a LEFT JOIN usuarios u ON u.id = a.usuario_id AND u.empresa_id = a.empresa_id
    WHERE {$where} ORDER BY a.criado_em DESC, a.id DESC LIMIT {$porPagina} OFFSET " . (($pagina - 1) * $porPagina));
$stmt->execute($params);
$registros = $stmt->fetchAll();

$stmt = db()->prepare('SELECT id, nome FROM usuarios WHERE empresa_id = :e AND eh_superadmin = 0 ORDER BY nome');
$stmt->execute(['e' => $empresaId]);
$usuarios = $stmt->fetchAll();
$stmt = db()->prepare('SELECT DISTINCT entidade FROM auditoria WHERE empresa_id = :e ORDER BY entidade');
$stmt->execute(['e' => $empresaId]);
$entidades = $stmt->fetchAll(PDO::FETCH_COLUMN);
$stmt = db()->prepare('SELECT DISTINCT acao FROM auditoria WHERE empresa_id = :e ORDER BY acao');
$stmt->execute(['e' => $empresaId]);
$acoes = $stmt->fetchAll(PDO::FETCH_COLUMN);

$qs = fn (array $extra) => '?' . http_build_query(array_filter(['de' => $de, 'ate' => $ate, 'usuario' => $fUsuario, 'acao' => $fAcao, 'entidade' => $fEntidade] + $extra));

$pageTitle = 'Auditoria — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div><div class="eyebrow">Administração</div><h1>Auditoria</h1><p>Quem fez o quê e quando, na sua empresa. Somente leitura.</p></div>
</div>

<form class="filters-row" method="get" aria-label="Filtros da auditoria">
    <label class="sr-only" for="au-de">De</label><input class="select-filter" type="date" id="au-de" name="de" value="<?= e($de) ?>">
    <label class="sr-only" for="au-ate">Até</label><input class="select-filter" type="date" id="au-ate" name="ate" value="<?= e($ate) ?>">
    <label class="sr-only" for="au-u">Usuário</label>
    <select class="select-filter" id="au-u" name="usuario"><option value="">Todos os usuários</option>
        <?php foreach ($usuarios as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $fUsuario === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['nome']) ?></option><?php endforeach; ?>
    </select>
    <label class="sr-only" for="au-a">Ação</label>
    <select class="select-filter" id="au-a" name="acao"><option value="">Todas as ações</option>
        <?php foreach ($acoes as $ac): ?><option value="<?= e($ac) ?>" <?= $fAcao === $ac ? 'selected' : '' ?>><?= e(auditoria_rotulo_acao($ac)) ?></option><?php endforeach; ?>
    </select>
    <label class="sr-only" for="au-en">Item</label>
    <select class="select-filter" id="au-en" name="entidade"><option value="">Todos os itens</option>
        <?php foreach ($entidades as $en): ?><option value="<?= e($en) ?>" <?= $fEntidade === $en ? 'selected' : '' ?>><?= e(auditoria_rotulo_entidade($en)) ?></option><?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary btn-sm"><?= icon('filter') ?>Filtrar</button>
    <span class="filters-note"><?= number_format($total, 0, ',', '.') ?> registro<?= $total === 1 ? '' : 's' ?></span>
</form>

<div class="card">
    <?php if (!$registros): ?>
        <p class="empty-note">Nenhum registro com esses filtros.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="report-table dash-table audit-table">
                <thead><tr><th scope="col">Quando</th><th scope="col">Quem</th><th scope="col">Ação</th><th scope="col">Item</th><th scope="col">Detalhes</th></tr></thead>
                <tbody>
                <?php foreach ($registros as $r): ?>
                    <tr>
                        <td data-rotulo="Quando"><?= date('d/m/Y H:i', strtotime($r['criado_em'])) ?></td>
                        <td data-rotulo="Quem"><?= e($r['usuario_nome'] ?? ($r['usuario_id'] ? 'Usuário #' . $r['usuario_id'] : 'Sistema (automático)')) ?></td>
                        <td class="nome" data-rotulo="Ação"><?= e(auditoria_rotulo_acao($r['acao'])) ?></td>
                        <td data-rotulo="Item"><?= e(auditoria_rotulo_entidade($r['entidade'])) ?><?= $r['entidade_id'] ? ' #' . (int) $r['entidade_id'] : '' ?></td>
                        <td data-rotulo="Detalhes"><?php $det = auditoria_detalhes_seguros($r['detalhes_json']); ?><?= $det === '' ? '—' : '<pre class="audit-detalhes">' . e($det) . '</pre>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($paginas > 1): ?>
            <nav class="pager" aria-label="Páginas">
                <?php if ($pagina > 1): ?><a class="btn btn-outline btn-sm" href="<?= e($qs(['pagina' => $pagina - 1])) ?>">Anterior</a><?php endif; ?>
                <span>Página <?= $pagina ?> de <?= $paginas ?></span>
                <?php if ($pagina < $paginas): ?><a class="btn btn-outline btn-sm" href="<?= e($qs(['pagina' => $pagina + 1])) ?>">Próxima</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
