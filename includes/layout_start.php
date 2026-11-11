<?php
/**
 * Abre o layout comum (sidebar + topbar + topbar mobile).
 *
 * Variáveis esperadas, definidas pela página antes do include:
 *   $pageTitle  string  título da aba do navegador
 *   $crumb      string  rótulo da página atual na breadcrumb
 *   $activeNav  string  um de: inicio, kanban, ficha, relatorios, equipes,
 *                       empreendimentos, plantoes, roletas, usuarios
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/leads.php';

$usuario = auth_require();

// Superadmin não tem empresa/escopo de leads nesta etapa — vai sempre
// para a tela própria (ver pages/superadmin.php), nunca para o layout
// comum de uma empresa (seção 4 do prompt mestre).
if (!empty($usuario['eh_superadmin'])) {
    header('Location: /pages/superadmin.php');
    exit;
}

$navItems = [
    ['key' => 'inicio',     'href' => '/pages/inicio.php',     'label' => 'Início',           'icon' => 'home',   'permissao' => null],
    ['key' => 'kanban',     'href' => '/pages/kanban.php',     'label' => 'Meus leads',        'icon' => 'kanban', 'permissao' => null],
    ['key' => 'ficha',      'href' => '/pages/kanban.php',     'label' => 'Ficha do lead',     'icon' => 'user',   'permissao' => null],
    ['key' => 'relatorios', 'href' => '/pages/relatorios.php', 'label' => 'Relatórios',        'icon' => 'chart',  'permissao' => 'visualizar_indicadores'],
    ['key' => 'equipes',    'href' => '/pages/equipes.php',    'label' => 'Equipes',            'icon' => 'users',  'permissao' => 'gerenciar_equipes'],
    ['key' => 'empreendimentos', 'href' => '/pages/empreendimentos.php', 'label' => 'Empreendimentos', 'icon' => 'building', 'permissao' => 'visualizar_leads'],
    ['key' => 'plantoes',   'href' => '/pages/plantoes.php',   'label' => 'Plantões',           'icon' => 'map',    'permissao' => 'gerenciar_plantoes'],
    ['key' => 'roletas',    'href' => '/pages/roletas.php',    'label' => 'Roletas',            'icon' => 'pin',    'permissao' => null],
    ['key' => 'usuarios',   'href' => '/pages/usuarios.php',   'label' => 'Usuários e acessos', 'icon' => 'shield', 'permissao' => 'administrar_usuarios'],
];
$navItems = array_filter($navItems, function ($item) use ($usuario) {
    return $item['permissao'] === null || usuario_tem_permissao($usuario, $item['permissao']);
});

$avatarIniciais = mb_strtoupper(mb_substr($usuario['nome'], 0, 1) . mb_substr(strrchr($usuario['nome'], ' ') ?: '', 1, 1));
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
<title><?= htmlspecialchars($pageTitle ?? APP_NAME) ?></title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="shell">
    <aside class="side">
        <a href="/pages/inicio.php" class="brand"><span class="brandmark">W</span>WeMart <span class="hl">Sales</span></a>
        <div class="company-switch">
            <?php
            $stmtEmpresa = db()->prepare('SELECT nome FROM empresas WHERE id = :id');
            $stmtEmpresa->execute(['id' => $usuario['empresa_ativa_id']]);
            $empresaNome = $stmtEmpresa->fetchColumn() ?: '—';
            ?>
            <div class="company"><?= icon('building') ?>
                <?= htmlspecialchars($empresaNome) ?>
                <small><?= icon('chevron') ?></small>
            </div>
        </div>
        <div class="navtitle">Área de trabalho</div>
        <nav class="nav">
            <?php foreach ($navItems as $item): if ($item['key'] === 'ficha') continue; ?>
                <a href="<?= $item['href'] ?>" class="<?= $activeNav === $item['key'] ? 'active' : '' ?>"><?= icon($item['icon']) ?><?= htmlspecialchars($item['label']) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="sidefooter">
            <div class="avatar"><?= htmlspecialchars($avatarIniciais) ?></div>
            <div>
                <b><?= htmlspecialchars($usuario['nome']) ?></b>
                <small><?= htmlspecialchars($usuario['funcao_nome']) ?></small>
            </div>
            <a href="/logout.php" class="logout-link" title="Sair"><?= icon('logout') ?></a>
        </div>
    </aside>

    <div class="content">
        <header class="topbar">
            <div class="crumb">WeMart Sales / <strong><?= htmlspecialchars($crumb ?? '') ?></strong></div>
            <div class="topright">
                <div class="search"><?= icon('search') ?> Buscar lead, telefone ou produto...</div>
                <button class="circle-btn" type="button" aria-label="Notificações"><?= icon('bell') ?></button>
                <div class="who">
                    <div class="avatar"><?= htmlspecialchars($avatarIniciais) ?></div>
                    <div>
                        <strong><?= htmlspecialchars($usuario['nome']) ?></strong>
                        <small><?= htmlspecialchars($empresaNome) ?></small>
                    </div>
                </div>
            </div>
        </header>

        <div class="mobile-topbar">
            <div class="crumb">WeMart Sales / <strong><?= htmlspecialchars($crumb ?? '') ?></strong></div>
            <div class="avatar"><?= htmlspecialchars($avatarIniciais) ?></div>
        </div>

        <main class="container">
