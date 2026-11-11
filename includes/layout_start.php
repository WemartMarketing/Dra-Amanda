<?php
/**
 * Abre o layout comum (sidebar + topbar + topbar mobile).
 *
 * Variáveis esperadas, definidas pela página antes do include:
 *   $pageTitle  string  título da aba do navegador
 *   $crumb      string  rótulo da página atual na breadcrumb
 *   $activeNav  string  um de: inicio, kanban, ficha, relatorios, equipes,
 *                       empreendimentos, plantoes, roletas, usuarios, oferta,
 *                       recuperacao, integracoes
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

$ehCorretor = ($usuario['escopo'] ?? '') === 'propria_carteira';
$navItems = [
    ['key' => 'inicio',     'href' => '/pages/inicio.php',     'label' => 'Início',                                     'icon' => 'home',     'mostrar' => true],
    ['key' => 'leads',      'href' => '/pages/leads.php',      'label' => $ehCorretor ? 'Meus leads' : 'Leads',         'icon' => 'list',     'mostrar' => usuario_tem_permissao($usuario, 'visualizar_leads')],
    ['key' => 'kanban',     'href' => '/pages/kanban.php',     'label' => $ehCorretor ? 'Meu Kanban' : 'Kanban',        'icon' => 'kanban',   'mostrar' => usuario_tem_permissao($usuario, 'visualizar_leads')],
    ['key' => 'agenda',     'href' => '/pages/agenda.php',     'label' => $ehCorretor ? 'Minha agenda' : 'Agenda',      'icon' => 'calendar', 'mostrar' => true],
    ['key' => 'minha_roleta', 'href' => '/pages/minha_roleta.php', 'label' => 'Minha roleta',                           'icon' => 'pin',      'mostrar' => usuario_tem_permissao($usuario, 'participar_roleta')],
    ['key' => 'roletas',    'href' => '/pages/roletas.php',    'label' => 'Roletas',                                    'icon' => 'zap',      'mostrar' => usuario_tem_permissao($usuario, 'gerenciar_roletas') || (!$ehCorretor && usuario_tem_permissao($usuario, 'redistribuir_leads'))],
    ['key' => 'oferta',     'href' => '/pages/oferta.php',     'label' => 'Oferta ativa',                               'icon' => 'upload',   'mostrar' => usuario_tem_permissao($usuario, 'gerenciar_oferta_ativa')],
    ['key' => 'relatorios', 'href' => '/pages/relatorios.php', 'label' => 'Relatórios',                                 'icon' => 'chart',    'mostrar' => usuario_tem_permissao($usuario, 'visualizar_indicadores')],
    ['key' => 'equipes',    'href' => '/pages/equipes.php',    'label' => 'Equipes',                                    'icon' => 'users',    'mostrar' => usuario_tem_permissao($usuario, 'gerenciar_equipes')],
    ['key' => 'empreendimentos', 'href' => '/pages/empreendimentos.php', 'label' => 'Empreendimentos',                  'icon' => 'building', 'mostrar' => usuario_tem_permissao($usuario, 'gerenciar_empreendimentos') || (!$ehCorretor && usuario_tem_permissao($usuario, 'visualizar_leads'))],
    ['key' => 'plantoes',   'href' => '/pages/plantoes.php',   'label' => 'Plantões',                                   'icon' => 'map',      'mostrar' => usuario_tem_permissao($usuario, 'gerenciar_plantoes')],
    ['key' => 'usuarios',   'href' => '/pages/usuarios.php',   'label' => 'Usuários e acessos',                         'icon' => 'shield',   'mostrar' => usuario_tem_permissao($usuario, 'administrar_usuarios')],
    ['key' => 'funil',      'href' => '/pages/funil.php',      'label' => 'Etapas do funil',                            'icon' => 'settings', 'mostrar' => usuario_tem_permissao($usuario, 'configurar_funil')],
    ['key' => 'recuperacao', 'href' => '/pages/recuperacao.php', 'label' => 'Recuperação de leads',                     'icon' => 'clock',    'mostrar' => usuario_tem_permissao($usuario, 'configurar_recuperacao')],
    ['key' => 'integracoes', 'href' => '/pages/integracoes.php', 'label' => 'Integrações',                              'icon' => 'zap',      'mostrar' => usuario_tem_permissao($usuario, 'gerenciar_integracoes')],
    ['key' => 'perfil',     'href' => '/pages/perfil.php',     'label' => 'Meu perfil',                                 'icon' => 'user',     'mostrar' => true],
];
$navItems = array_values(array_filter($navItems, fn ($item) => $item['mostrar']));
// A ficha de um lead destaca "Leads" no menu.
$navAtivo = ($activeNav ?? '') === 'ficha' ? 'leads' : ($activeNav ?? '');

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
            <?php foreach ($navItems as $item): ?>
                <a href="<?= $item['href'] ?>" class="<?= $navAtivo === $item['key'] ? 'active' : '' ?>"<?= $navAtivo === $item['key'] ? ' aria-current="page"' : '' ?>><?= icon($item['icon']) ?><?= htmlspecialchars($item['label']) ?></a>
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
                <form class="search" method="get" action="/pages/leads.php" role="search">
                    <?= icon('search') ?>
                    <label class="sr-only" for="busca-global">Buscar lead por nome ou telefone</label>
                    <input id="busca-global" type="search" name="q" placeholder="Buscar lead por nome ou telefone..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
                </form>
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
            <a href="/pages/perfil.php" class="avatar" aria-label="Meu perfil"><?= htmlspecialchars($avatarIniciais) ?></a>
        </div>

        <main class="container">
