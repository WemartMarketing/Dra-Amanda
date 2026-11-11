<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/lead_componentes.php';

$usuario = auth_require();
$empresaId = (int) $usuario['empresa_ativa_id'];
$uid = (int) $usuario['id'];
$ehCorretor = $usuario['escopo'] === 'propria_carteira';
$scope = rbac_leads_scope($usuario);
$encerradas = "'sem_interesse','contrato_assinado','perdido'";

function contarLeads(array $scope, string $condicaoExtra = '', array $paramsExtra = []): int
{
    $sql = 'SELECT COUNT(*) FROM leads WHERE ' . $scope['sql'] . ($condicaoExtra !== '' ? ' AND ' . $condicaoExtra : '');
    $stmt = db()->prepare($sql);
    $stmt->execute(array_merge($scope['params'], $paramsExtra));
    return (int) $stmt->fetchColumn();
}

$hora = (int) date('G');
$saudacao = $hora < 12 ? 'Bom dia' : ($hora < 18 ? 'Boa tarde' : 'Boa noite');
$primeiroNome = explode(' ', $usuario['nome'])[0];
$vendasMes = contarLeads($scope, "etapa = 'contrato_assinado' AND atualizado_em >= :inicio_mes", ['inicio_mes' => date('Y-m-01')]);

// Atividades pendentes: atrasados primeiro (mesma regra do Kanban/lista).
$pendentes = leads_listar($usuario, leads_filtros_da_requisicao(['situacao' => 'atrasados']), 6);
$totalAtrasados = leads_contar($usuario, leads_filtros_da_requisicao(['situacao' => 'atrasados']));
$proximosCompromissos = agenda_listar($usuario, ['status' => ['agendado'], 'de' => date('Y-m-d H:i:s'), 'limite' => 5] + ($ehCorretor ? ['usuario_id' => $uid] : []));
$compromissosVencidos = count(agenda_listar($usuario, ['status' => ['agendado'], 'ate' => date('Y-m-d H:i:s'), 'limite' => 100] + ($ehCorretor ? ['usuario_id' => $uid] : [])));

// Leads em aberto sem nenhuma atualização há 3 dias ou mais.
$semAtualizacao = contarLeads($scope, "etapa NOT IN ({$encerradas}) AND COALESCE(ultima_atividade_em, criado_em) < :limite", ['limite' => date('Y-m-d H:i:s', strtotime('-3 days'))]);

$pageTitle = 'Início — ' . APP_NAME;
$crumb = 'Início';
$activeNav = 'inicio';

if ($ehCorretor) {
    $recebidos = contarLeads($scope);
    $aguardando = contarLeads($scope, "etapa = 'novo_lead'");
    $emAtendimento = contarLeads($scope, "etapa IN ('primeiro_contato','em_atendimento','visita_realizada')");
    $stmt = db()->prepare("SELECT COUNT(*) FROM agenda_compromissos WHERE empresa_id = :e AND usuario_id = :u AND tipo = 'visita' AND status = 'agendado' AND inicio >= NOW()");
    $stmt->execute(['e' => $empresaId, 'u' => $uid]);
    $visitas = (int) $stmt->fetchColumn();

    // Estado real da roleta: sessão aberta e ainda válida?
    require_once __DIR__ . '/../includes/roleta.php';
    roleta_fechar_expiradas($empresaId);
    $stmt = db()->prepare('SELECT roletas.id, roletas.nome, roletas.ativa, s.entrou_em
        FROM roletas INNER JOIN roleta_corretores rc ON rc.roleta_id = roletas.id
        LEFT JOIN roleta_sessoes s ON s.roleta_id = roletas.id AND s.usuario_id = rc.usuario_id AND s.saiu_em IS NULL
        WHERE rc.usuario_id = :uid AND roletas.empresa_id = :e
        ORDER BY (s.id IS NULL), roletas.ativa DESC, roletas.nome LIMIT 1');
    $stmt->execute(['uid' => $uid, 'e' => $empresaId]);
    $roleta = $stmt->fetch();
    $roletaTexto = !$roleta ? 'Você ainda não está vinculado a uma roleta'
        : ($roleta['entrou_em'] ? 'Você está na roleta ' . $roleta['nome'] . ' desde ' . date('H:i', strtotime($roleta['entrou_em']))
            : ($roleta['ativa'] ? $roleta['nome'] . ' · toque para verificar sua presença' : $roleta['nome'] . ' · roleta pausada'));

    require __DIR__ . '/../includes/layout_start.php';
    ?>
    <div class="header">
        <div>
            <div class="eyebrow"><?= e(ucfirst(strftime_pt(time()))) ?></div>
            <h1><?= $saudacao ?>, <?= e($primeiroNome) ?>!</h1>
            <p><?= $totalAtrasados ? "Você tem <b>{$totalAtrasados} atendimento" . ($totalAtrasados === 1 ? '' : 's') . ' atrasado' . ($totalAtrasados === 1 ? '' : 's') . '</b>. Comece por eles.' : 'Nenhum atendimento atrasado. Bom trabalho!' ?></p>
        </div>
    </div>

    <div class="big-actions">
        <a href="/pages/leads.php" class="big-action"><?= icon('users') ?><span>MEUS LEADS</span></a>
        <a href="/pages/kanban.php" class="big-action"><?= icon('kanban') ?><span>MEU KANBAN</span></a>
        <a href="/pages/agenda.php" class="big-action"><?= icon('calendar') ?><span>MINHA AGENDA</span></a>
        <a href="/pages/minha_roleta.php" class="big-action big-action-primary <?= $roleta && $roleta['entrou_em'] ? 'is-in' : '' ?>"><?= icon('pin') ?><span><?= $roleta && $roleta['entrou_em'] ? 'VOCÊ ESTÁ NA ROLETA' : 'ENTRAR NA ROLETA' ?></span><small><?= e($roletaTexto) ?></small></a>
    </div>

    <div class="kpi-grid kpi-grid-5">
        <a class="kpi" href="/pages/leads.php"><div class="kpi-top"><span class="label">Leads recebidos</span><div class="kpi-icon"><?= icon('user') ?></div></div><div class="kpi-value"><?= $recebidos ?></div><div class="kpi-sub">na sua carteira</div></a>
        <a class="kpi" href="/pages/kanban.php?etapa=<?= (int) (funil_etapa_por_categoria($empresaId, 'novo_lead')['id'] ?? 0) ?>"><div class="kpi-top"><span class="label">Aguardando 1º contato</span><div class="kpi-icon"><?= icon('phone') ?></div></div><div class="kpi-value"><?= $aguardando ?></div><div class="kpi-sub">ligue o quanto antes</div></a>
        <a class="kpi" href="/pages/leads.php?situacao=abertos"><div class="kpi-top"><span class="label">Em atendimento</span><div class="kpi-icon"><?= icon('zap') ?></div></div><div class="kpi-value"><?= $emAtendimento ?></div><div class="kpi-sub">conversas em andamento</div></a>
        <a class="kpi" href="/pages/agenda.php"><div class="kpi-top"><span class="label">Visitas agendadas</span><div class="kpi-icon"><?= icon('calendar') ?></div></div><div class="kpi-value"><?= $visitas ?></div><div class="kpi-sub">a partir de agora</div></a>
        <div class="kpi"><div class="kpi-top"><span class="label">Vendas do mês</span><div class="kpi-icon"><?= icon('check') ?></div></div><div class="kpi-value"><?= $vendasMes ?></div><div class="kpi-sub">contratos assinados</div></div>
    </div>
    <?php
} else {
    $totalCarteira = contarLeads($scope, "etapa NOT IN ({$encerradas})");
    $visitasAgendadas = contarLeads($scope, "etapa = 'visita_agendada'");
    require __DIR__ . '/../includes/layout_start.php';
    ?>
    <div class="header">
        <div>
            <div class="eyebrow">Visão da equipe</div>
            <h1><?= $saudacao ?>, <?= e($primeiroNome) ?>!</h1>
            <p>O que precisa de atenção hoje na sua estrutura.</p>
        </div>
        <div class="actions">
            <a href="/pages/kanban.php" class="btn btn-primary"><?= icon('kanban') ?>Abrir Kanban</a>
        </div>
    </div>
    <div class="kpi-grid">
        <a class="kpi" href="/pages/leads.php?situacao=abertos"><div class="kpi-top"><span class="label">Leads em aberto</span><div class="kpi-icon"><?= icon('user') ?></div></div><div class="kpi-value"><?= $totalCarteira ?></div><div class="kpi-sub">no seu escopo</div></a>
        <a class="kpi" href="/pages/leads.php?situacao=atrasados"><div class="kpi-top"><span class="label">Atendimentos atrasados</span><div class="kpi-icon"><?= icon('clock') ?></div></div><div class="kpi-value"><?= $totalAtrasados ?></div><div class="kpi-sub">retorno vencido ou sem contato</div></a>
        <div class="kpi"><div class="kpi-top"><span class="label">Visitas agendadas</span><div class="kpi-icon"><?= icon('calendar') ?></div></div><div class="kpi-value"><?= $visitasAgendadas ?></div><div class="kpi-sub">leads nesta etapa</div></div>
        <div class="kpi"><div class="kpi-top"><span class="label">Vendas do mês</span><div class="kpi-icon"><?= icon('check') ?></div></div><div class="kpi-value"><?= $vendasMes ?></div><div class="kpi-sub">contratos assinados</div></div>
    </div>
    <?php
}
?>

<?php if ($semAtualizacao || $compromissosVencidos): ?>
    <div class="alert-strip" role="status">
        <?= icon('alert') ?>
        <span>
            <?php if ($semAtualizacao): ?><b><?= $semAtualizacao ?> lead<?= $semAtualizacao === 1 ? '' : 's' ?></b> sem atualização há 3 dias ou mais. <?php endif; ?>
            <?php if ($compromissosVencidos): ?><b><?= $compromissosVencidos ?> compromisso<?= $compromissosVencidos === 1 ? '' : 's' ?></b> <?= $compromissosVencidos === 1 ? 'passou' : 'passaram' ?> do horário sem ser marcado<?= $compromissosVencidos === 1 ? '' : 's' ?>.<?php endif; ?>
        </span>
        <a class="link-btn" href="/pages/leads.php?situacao=atrasados">Ver agora</a>
    </div>
<?php endif; ?>

<div class="grid-2">
    <div class="card">
        <div class="card-head">
            <div><h2>Atividades pendentes</h2><p>Retorno vencido, compromisso atrasado ou lead novo sem contato há mais de 48 h.</p></div>
            <span class="pill <?= $totalAtrasados ? 'pill-red' : 'pill-green' ?>"><?= $totalAtrasados ?></span>
        </div>
        <?php foreach ($pendentes as $lead): $whats = lead_link_whatsapp($lead['telefone']); ?>
            <div class="task-row">
                <div class="avatar"><?= e(lead_iniciais($lead['nome'])) ?></div>
                <div class="who-info">
                    <strong><a href="/pages/lead.php?id=<?= (int) $lead['id'] ?>"><?= e($lead['nome']) ?></a></strong>
                    <span><?= e($lead['etapa_nome'] ?? lead_etapa_rotulo($lead['etapa'])) ?> · <?= e(lead_proxima_acao($lead)) ?><?= $ehCorretor ? '' : ' · ' . e($lead['responsavel_nome'] ?? '') ?></span>
                </div>
                <?php if ($whats): ?><a class="icon-btn icon-btn-whats" href="<?= e($whats) ?>" target="_blank" rel="noopener" data-registrar="whatsapp" data-lead="<?= (int) $lead['id'] ?>" aria-label="WhatsApp de <?= e($lead['nome']) ?>"><?= icon('whatsapp') ?></a><?php endif; ?>
                <a href="/pages/lead.php?id=<?= (int) $lead['id'] ?>&aba=atendimento" class="btn btn-primary btn-sm"><?= icon('arrow-r') ?>Atender</a>
            </div>
        <?php endforeach; ?>
        <?php if (!$pendentes): ?><p class="empty-note">Nenhuma pendência agora — bom trabalho!</p><?php endif; ?>
        <a href="/pages/leads.php?situacao=atrasados" class="btn btn-outline btn-block"><?= icon('list') ?>Ver todos os atrasados</a>
    </div>

    <div class="card">
        <div class="card-head">
            <div><h2>Próximos compromissos</h2><p>Visitas, retornos e tarefas.</p></div>
            <a class="link-btn" href="/pages/agenda.php">Abrir agenda</a>
        </div>
        <?php foreach ($proximosCompromissos as $c) render_compromisso($c, !$ehCorretor); ?>
        <?php if (!$proximosCompromissos): ?><p class="empty-note">Nada agendado. Que tal marcar uma visita?</p><?php endif; ?>
        <div class="tip-box"><b>Dica rápida:</b> registre o atendimento logo após cada contato — etapa, o que aconteceu e o próximo passo.</div>
    </div>
</div>

<?php render_modal_compromisso(); ?>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
