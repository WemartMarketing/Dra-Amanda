<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/estrutura.php';
require_once __DIR__ . '/../includes/lead_componentes.php';
require_once __DIR__ . '/../includes/whatsapp_conversas.php';

$usuario = auth_require();
$crumb = 'Conversas';
$activeNav = 'whatsapp';
if (!wa_pode_atender($usuario)) {
    pagina_acesso_restrito($crumb, $activeNav, 'Conversar com clientes pelo WhatsApp');
}
$empresaId = (int) $usuario['empresa_ativa_id'];

// "Conversar no WhatsApp" na ficha/cards: abre (ou cria) a conversa do lead.
$aviso = null;
if (isset($_GET['lead'])) {
    $r = wa_conversa_do_lead($usuario, (int) $_GET['lead']);
    if ($r['ok']) {
        header('Location: /pages/conversas.php?c=' . $r['conversa_id']);
        exit;
    }
    $aviso = $r['erro'];
}
$selecionada = (int) ($_GET['c'] ?? 0);
if ($selecionada && !wa_conversa_carregar($usuario, $selecionada)) {
    $selecionada = 0;
    $aviso = 'Conversa não encontrada ou fora da sua carteira.';
}
$ativa = wa_central_ativa($empresaId);
$conta = wa_conta_da_empresa($empresaId);

$pageTitle = 'Conversas — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>

<div class="header chat-header">
    <div>
        <div class="eyebrow">WhatsApp</div>
        <h1>Conversas</h1>
    </div>
</div>

<?php wa_render_abas($usuario, 'conversas'); ?>

<?php if ($aviso): ?><div class="alert-strip" role="alert"><?= icon('alert') ?><span><?= e($aviso) ?></span></div><?php endif; ?>

<?php if (!$ativa): ?>
    <div class="card stub-card">
        <div class="stub-icon"><?= icon('whatsapp') ?></div>
        <h2>O WhatsApp da empresa ainda não está ligado</h2>
        <p>Enquanto isso, o botão de WhatsApp nos leads continua abrindo o WhatsApp do seu aparelho.</p>
        <?php if (usuario_tem_permissao($usuario, 'whatsapp_configurar')): ?><a class="btn btn-primary" href="/pages/whatsapp.php" style="margin-top:16px;"><?= icon('settings') ?>Configurar o WhatsApp</a><?php endif; ?>
    </div>
<?php else: ?>
    <?php if ($conta && (int) $conta['modo_teste']): ?>
        <div class="wa-modo is-teste chat-aviso-teste" role="status"><?= icon('alert') ?><div><b>Modo de teste</b><span>As mensagens ficam registradas como “simuladas”: nada chega ao cliente.</span></div></div>
    <?php endif; ?>
    <div class="chat<?= $selecionada ? ' is-aberta' : '' ?>" id="chat" data-selecionada="<?= $selecionada ?>" data-sem-lead="<?= wa_ve_conversas_sem_lead($usuario) ? '1' : '0' ?>">
        <aside class="chat-lista" aria-label="Conversas">
            <div class="chat-busca">
                <label class="sr-only" for="chat-q">Buscar conversa</label>
                <input type="search" id="chat-q" placeholder="Buscar nome ou telefone" autocomplete="off">
            </div>
            <div class="chat-filtros" role="group" aria-label="Filtrar conversas">
                <button type="button" class="chat-filtro is-ativo" data-filtro="todas">Todas</button>
                <button type="button" class="chat-filtro" data-filtro="nao_lidas">Não lidas</button>
                <button type="button" class="chat-filtro" data-filtro="aguardando">Aguardando você</button>
                <?php if (wa_ve_conversas_sem_lead($usuario)): ?><button type="button" class="chat-filtro" data-filtro="sem_lead">Sem lead</button><?php endif; ?>
            </div>
            <ul class="chat-itens" id="chat-itens" aria-live="polite"></ul>
        </aside>

        <section class="chat-conversa" aria-label="Conversa">
            <div class="chat-vazio" id="chat-vazio"><?= icon('whatsapp') ?><p>Escolha uma conversa ao lado.</p></div>
            <div class="chat-ativa" id="chat-ativa" hidden>
                <header class="chat-topo">
                    <button type="button" class="chat-voltar" id="chat-voltar" aria-label="Voltar para a lista"><?= icon('arrow-r') ?></button>
                    <div class="chat-quem"><strong id="chat-nome"></strong><span id="chat-tel"></span></div>
                    <span class="pill" id="chat-janela"></span>
                    <button type="button" class="btn btn-outline btn-sm chat-info-btn" id="chat-info-btn" aria-expanded="false"><?= icon('user') ?>Dados do cliente</button>
                </header>
                <div class="chat-msgs" id="chat-msgs" role="log" aria-live="polite"></div>
                <div class="chat-rodape" id="chat-rodape"></div>
            </div>
        </section>

        <aside class="chat-info" id="chat-info" aria-label="Dados do cliente"></aside>
    </div>
<?php endif; ?>

<script src="<?= asset('/assets/js/conversas.js') ?>" defer></script>
<?php require __DIR__ . '/../includes/layout_end.php'; ?>
