<?php
/**
 * Abre o layout comum (sidebar + topbar + topbar mobile).
 *
 * Variáveis esperadas, definidas pela página antes do include:
 *   $pageTitle  string  título da aba do navegador
 *   $crumb      string  rótulo da página atual na breadcrumb
 *   $activeNav  string  um de: dashboard, inicio, kanban, ficha, relatorios, equipes,
 *                       empreendimentos, plantoes, roletas, usuarios, oferta,
 *                       recuperacao, integracoes, auditoria
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/leads.php';
require_once __DIR__ . '/logo.php';

$usuario = auth_require();

// Superadmin não tem empresa/escopo de leads nesta etapa — vai sempre
// para a tela própria (ver pages/superadmin.php), nunca para o layout
// comum de uma empresa (seção 4 do prompt mestre).
if (!empty($usuario['eh_superadmin'])) {
    header('Location: /pages/superadmin.php');
    exit;
}

$ehCorretor = ($usuario['escopo'] ?? '') === 'propria_carteira';
// Gestores (quem vê indicadores) começam pelo Dashboard; corretores, pelo Início.
$ehGestor = !$ehCorretor && usuario_tem_permissao($usuario, 'visualizar_indicadores');
$inicioHref = $ehGestor ? '/pages/dashboard.php' : '/pages/inicio.php';
// WhatsApp: quem atende vai para as conversas (com o número de não lidas); quem só configura, para a configuração.
require_once __DIR__ . '/whatsapp_conversas.php';
$waAtende = empty($usuario['eh_superadmin']) && wa_pode_atender($usuario) && wa_central_ativa((int) $usuario['empresa_ativa_id']);
$waHref = $waAtende ? '/pages/conversas.php' : (usuario_tem_permissao($usuario, 'whatsapp_configurar') ? '/pages/whatsapp.php'
    : (usuario_tem_permissao($usuario, 'gerenciar_campanhas_wa') ? '/pages/campanhas.php' : null));
$waNaoLidas = $waAtende ? wa_conversas_nao_lidas($usuario) : 0;
require_once __DIR__ . '/pendencias.php';
$pendCount = pendencias_contar($usuario);

$navItems = [
    ['key' => 'dashboard',  'href' => '/pages/dashboard.php',  'label' => 'Dashboard',                                  'icon' => 'chart',    'mostrar' => $ehGestor],
    ['key' => 'inicio',     'href' => '/pages/inicio.php',     'label' => 'Início',                                     'icon' => 'home',     'mostrar' => true],
    ['key' => 'leads',      'href' => '/pages/leads.php',      'label' => $ehCorretor ? 'Meus leads' : 'Leads',         'icon' => 'list',     'mostrar' => usuario_tem_permissao($usuario, 'visualizar_leads')],
    ['key' => 'kanban',     'href' => '/pages/kanban.php',     'label' => $ehCorretor ? 'Meu Kanban' : 'Kanban',        'icon' => 'kanban',   'mostrar' => usuario_tem_permissao($usuario, 'visualizar_leads')],
    ['key' => 'agenda',     'href' => '/pages/agenda.php',     'label' => $ehCorretor ? 'Minha agenda' : 'Agenda',      'icon' => 'calendar', 'mostrar' => true],
    ['key' => 'minha_roleta', 'href' => '/pages/minha_roleta.php', 'label' => 'Minha roleta',                           'icon' => 'pin',      'mostrar' => usuario_tem_permissao($usuario, 'participar_roleta')],
    ['key' => 'roletas',    'href' => '/pages/roletas.php',    'label' => 'Roletas',                                    'icon' => 'zap',      'mostrar' => usuario_tem_permissao($usuario, 'gerenciar_roletas') || (!$ehCorretor && usuario_tem_permissao($usuario, 'redistribuir_leads'))],
    ['key' => 'oferta',     'href' => '/pages/oferta.php',     'label' => 'Oferta ativa',                               'icon' => 'upload',   'mostrar' => usuario_tem_permissao($usuario, 'gerenciar_oferta_ativa')],
    ['key' => 'relatorios', 'href' => '/pages/relatorios.php', 'label' => 'Perdas e transferências',                    'icon' => 'alert',    'mostrar' => usuario_tem_permissao($usuario, 'visualizar_indicadores')],
    ['key' => 'equipes',    'href' => '/pages/equipes.php',    'label' => 'Equipes',                                    'icon' => 'users',    'mostrar' => usuario_tem_permissao($usuario, 'gerenciar_equipes')],
    ['key' => 'empreendimentos', 'href' => '/pages/empreendimentos.php', 'label' => 'Empreendimentos',                  'icon' => 'building', 'mostrar' => usuario_tem_permissao($usuario, 'gerenciar_empreendimentos') || (!$ehCorretor && usuario_tem_permissao($usuario, 'visualizar_leads'))],
    ['key' => 'plantoes',   'href' => '/pages/plantoes.php',   'label' => 'Plantões',                                   'icon' => 'map',      'mostrar' => usuario_tem_permissao($usuario, 'gerenciar_plantoes')],
    ['key' => 'usuarios',   'href' => '/pages/usuarios.php',   'label' => 'Usuários e acessos',                         'icon' => 'shield',   'mostrar' => usuario_tem_permissao($usuario, 'administrar_usuarios')],
    ['key' => 'funil',      'href' => '/pages/funil.php',      'label' => 'Etapas do funil',                            'icon' => 'settings', 'mostrar' => usuario_tem_permissao($usuario, 'configurar_funil')],
    ['key' => 'recuperacao', 'href' => '/pages/recuperacao.php', 'label' => 'Recuperação de leads',                     'icon' => 'clock',    'mostrar' => usuario_tem_permissao($usuario, 'configurar_recuperacao')],
    ['key' => 'whatsapp',   'href' => $waHref,                 'label' => 'WhatsApp',                                   'icon' => 'whatsapp', 'mostrar' => $waHref !== null, 'contador' => $waNaoLidas],
    ['key' => 'integracoes', 'href' => '/pages/integracoes.php', 'label' => 'Integrações',                              'icon' => 'zap',      'mostrar' => usuario_tem_permissao($usuario, 'gerenciar_integracoes')],
    ['key' => 'auditoria',  'href' => '/pages/auditoria.php',  'label' => 'Auditoria',                                  'icon' => 'shield',   'mostrar' => usuario_tem_permissao($usuario, 'visualizar_auditoria')],
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
<?= favicon_tags() ?>
</head>
<body class="pg-<?= htmlspecialchars($navAtivo ?? '') ?>">
<div class="shell">
    <aside class="side">
        <a href="<?= $inicioHref ?>" class="brand" aria-label="WeMart Sales — página inicial"><?= logo_html() ?></a>
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
                <a href="<?= $item['href'] ?>" class="<?= $navAtivo === $item['key'] ? 'active' : '' ?>"<?= $navAtivo === $item['key'] ? ' aria-current="page"' : '' ?>><?= icon($item['icon']) ?><?= htmlspecialchars($item['label']) ?><?php if (!empty($item['contador'])): ?><b class="nav-badge" aria-label="<?= (int) $item['contador'] ?> com mensagem nova"><?= (int) $item['contador'] > 99 ? '99+' : (int) $item['contador'] ?></b><?php endif; ?></a>
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
                <a class="circle-btn sino" href="/pages/pendencias.php" aria-label="Pendências<?= $pendCount ? ': ' . $pendCount . ' aberta(s)' : '' ?>" title="Pendências"><?= icon('bell') ?><?php if ($pendCount): ?><b class="sino-badge"><?= $pendCount > 99 ? '99+' : $pendCount ?></b><?php endif; ?></a>
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
            <a href="<?= $inicioHref ?>" class="mobile-brand" aria-label="WeMart Sales — página inicial"><?= logo_compacta_html() ?></a>
            <div class="crumb mobile-crumb"><strong><?= htmlspecialchars($crumb ?? '') ?></strong></div>
            <?php if ($pendCount): ?><a class="sino mobile-sino" href="/pages/pendencias.php" aria-label="Pendências: <?= $pendCount ?> aberta(s)"><?= icon('bell') ?><b class="sino-badge"><?= $pendCount > 99 ? '99+' : $pendCount ?></b></a><?php endif; ?>
            <a href="/pages/perfil.php" class="avatar" aria-label="Meu perfil"><?= htmlspecialchars($avatarIniciais) ?></a>
        </div>

        <main class="container">
