<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/leads.php';

$usuario = auth_require();
$empresaId = (int) $usuario['empresa_ativa_id'];
$uid = (int) $usuario['id'];
$ehCorretor = $usuario['cargo'] === 'corretor';

// KPIs — para cargos de gestão, soma a empresa toda (ainda sem o relatório
// completo de gestor, que é a tela 04 / pages/relatorios.php).
$scope = rbac_leads_scope($usuario);

function contarLeads(array $scope, string $condicaoExtra = '', array $paramsExtra = []): int
{
    $sql = 'SELECT COUNT(*) FROM leads WHERE ' . $scope['sql'] . ($condicaoExtra !== '' ? ' AND ' . $condicaoExtra : '');
    $stmt = db()->prepare($sql);
    $stmt->execute(array_merge($scope['params'], $paramsExtra));
    return (int) $stmt->fetchColumn();
}

$totalCarteira   = contarLeads($scope, "etapa NOT IN ('contrato_assinado','perdido')");
$precisamAtencao = contarLeads($scope, "etapa NOT IN ('contrato_assinado','perdido') AND data_retorno IS NOT NULL AND data_retorno <= CURDATE()");
$visitasAgendadas = contarLeads($scope, "etapa = 'visita_agendada'");
$vendasMes = contarLeads($scope, "etapa = 'contrato_assinado' AND MONTH(atualizado_em) = MONTH(CURDATE()) AND YEAR(atualizado_em) = YEAR(CURDATE())");

// Prioridades de hoje: retorno vencido primeiro, depois leads novos sem
// contato, limitado a uma lista curta (ver seção "Prioridades de hoje").
$stmt = db()->prepare('
    SELECT leads.*, empreendimentos.nome AS empreendimento_nome
    FROM leads
    LEFT JOIN empreendimentos ON empreendimentos.id = leads.empreendimento_id
    WHERE ' . $scope['sql'] . " AND leads.etapa NOT IN ('contrato_assinado','perdido')
    ORDER BY
        (leads.data_retorno IS NOT NULL AND leads.data_retorno <= CURDATE()) DESC,
        leads.data_retorno ASC,
        leads.criado_em ASC
    LIMIT 6
");
$stmt->execute($scope['params']);
$prioridades = $stmt->fetchAll();

$totalPendencias = contarLeads($scope, "etapa NOT IN ('contrato_assinado','perdido') AND (data_retorno IS NULL OR data_retorno <= CURDATE())");

// Etapa -> rótulo de ação e ícone, para o botão de cada prioridade.
$acaoPorEtapa = [
    'novo_lead'        => ['label' => 'Ligar', 'icon' => 'phone'],
    'primeiro_contato' => ['label' => 'Atender', 'icon' => 'arrow-r'],
    'em_atendimento'   => ['label' => 'Atender', 'icon' => 'arrow-r'],
    'visita_agendada'  => ['label' => 'Confirmar', 'icon' => 'check'],
    'visita_realizada' => ['label' => 'Atualizar', 'icon' => 'arrow-r'],
];

function formatarQuando(?string $dataRetorno, string $criadoEm): string
{
    if ($dataRetorno) {
        $dias = (strtotime($dataRetorno) - strtotime(date('Y-m-d'))) / 86400;
        if ($dias < 0) return 'Atrasado';
        if ($dias == 0) return 'Hoje';
        if ($dias == 1) return 'Amanhã';
        return date('d/m', strtotime($dataRetorno));
    }
    $minutos = (int) round((time() - strtotime($criadoEm)) / 60);
    if ($minutos < 60) return "Aguardando há {$minutos} min";
    $horas = (int) round($minutos / 60);
    return "Aguardando há {$horas} h";
}

// Plantão/roleta do usuário (leitura simples — a lógica completa de
// geofencing/horário fica na tela Roletas, fora do escopo desta etapa).
$plantao = null;
if ($ehCorretor) {
    $stmt = db()->prepare('
        SELECT roletas.*,
            (SELECT dentro_do_raio FROM roleta_presencas rp WHERE rp.roleta_id = roletas.id AND rp.usuario_id = :uid ORDER BY confirmado_em DESC LIMIT 1) AS presenca_ativa
        FROM roletas
        INNER JOIN roleta_corretores rc ON rc.roleta_id = roletas.id
        WHERE rc.usuario_id = :uid2 AND roletas.empresa_id = :empresa_id
        LIMIT 1
    ');
    $stmt->execute(['uid' => $uid, 'uid2' => $uid, 'empresa_id' => $empresaId]);
    $plantao = $stmt->fetch();
}

$pageTitle = 'Início — ' . APP_NAME;
$crumb = 'Início';
$activeNav = 'inicio';
require __DIR__ . '/../includes/layout_start.php';
?>

<div class="header">
    <div>
        <div class="eyebrow">Visão do corretor</div>
        <h1>Bom dia, <?= htmlspecialchars(explode(' ', $usuario['nome'])[0]) ?>! 👋</h1>
        <p>Tudo o que você precisa fazer hoje, em um só lugar.</p>
    </div>
    <div class="actions">
        <a href="/pages/kanban.php" class="btn btn-primary"><?= icon('arrow-r') ?>Abrir meus leads</a>
    </div>
</div>

<div class="kpi-grid">
    <div class="kpi">
        <div class="kpi-top"><span class="label">Meus leads</span><div class="kpi-icon"><?= icon('user') ?></div></div>
        <div class="kpi-value"><?= $totalCarteira ?></div>
        <div class="kpi-sub">na sua carteira</div>
    </div>
    <div class="kpi">
        <div class="kpi-top"><span class="label">Precisam de atenção</span><div class="kpi-icon"><?= icon('clock') ?></div></div>
        <div class="kpi-value"><?= $precisamAtencao ?></div>
        <div class="kpi-sub">acompanhar hoje</div>
    </div>
    <div class="kpi">
        <div class="kpi-top"><span class="label">Visitas agendadas</span><div class="kpi-icon"><?= icon('calendar') ?></div></div>
        <div class="kpi-value"><?= $visitasAgendadas ?></div>
        <div class="kpi-sub">para esta semana</div>
    </div>
    <div class="kpi">
        <div class="kpi-top"><span class="label">Vendas do mês</span><div class="kpi-icon"><?= icon('check') ?></div></div>
        <div class="kpi-value"><?= $vendasMes ?></div>
        <div class="kpi-sub">parabéns pelo resultado</div>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-head">
            <div>
                <h2>Prioridades de hoje</h2>
                <p>Comece por aqui. Os clientes mais urgentes aparecem primeiro.</p>
            </div>
            <span class="pill pill-amber"><?= $totalPendencias ?> pendências</span>
        </div>

        <?php if (!$prioridades): ?>
            <p style="color:#8893a4;font-size:14px;">Nenhuma pendência agora — bom trabalho!</p>
        <?php endif; ?>

        <?php foreach ($prioridades as $lead):
            $acao = $acaoPorEtapa[$lead['etapa']] ?? ['label' => 'Abrir', 'icon' => 'arrow-r'];
            $iniciais = mb_strtoupper(mb_substr($lead['nome'], 0, 1) . mb_substr(strrchr($lead['nome'], ' ') ?: '', 1, 1));
            $atrasado = $lead['data_retorno'] && strtotime($lead['data_retorno']) < strtotime(date('Y-m-d'));
        ?>
            <div class="task-row">
                <div class="avatar"><?= htmlspecialchars($iniciais) ?></div>
                <div class="who-info">
                    <strong><?= htmlspecialchars($lead['nome']) ?></strong>
                    <span><?= htmlspecialchars($lead['empreendimento_nome'] ?? 'Sem produto') ?> • <?= htmlspecialchars(lead_etapa_rotulo($lead['etapa'])) ?></span>
                </div>
                <div class="when <?= $atrasado ? 'urgent' : '' ?>"><?= htmlspecialchars(formatarQuando($lead['data_retorno'], $lead['criado_em'])) ?></div>
                <a href="/pages/lead.php?id=<?= $lead['id'] ?>" class="btn btn-primary btn-sm"><?= icon($acao['icon']) ?><?= $acao['label'] ?></a>
            </div>
        <?php endforeach; ?>

        <a href="/pages/kanban.php" class="btn btn-outline" style="width:100%;justify-content:center;margin-top:6px;"><?= icon('arrow-r') ?>Ver todos os atendimentos</a>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <h2>Meu plantão</h2>
                <p>Presença e disponibilidade na roleta</p>
            </div>
        </div>

        <?php if ($plantao): ?>
            <div class="plantao-card">
                <div class="plantao-icon"><?= icon('pin') ?></div>
                <div>
                    <strong><?= htmlspecialchars($plantao['nome']) ?></strong>
                    <span><?= $plantao['presenca_ativa'] ? 'Presença confirmada' : 'Presença não confirmada' ?><?= $plantao['raio_presenca_metros'] ? ' · dentro de ' . (int) $plantao['raio_presenca_metros'] . ' m' : '' ?></span>
                </div>
                <span class="pill <?= $plantao['presenca_ativa'] ? 'pill-green' : 'pill-muted' ?>"><?= $plantao['presenca_ativa'] ? 'Ativo' : 'Inativo' ?></span>
            </div>
        <?php else: ?>
            <p style="color:#8893a4;font-size:14px;margin-bottom:18px;">Você ainda não está vinculado a nenhuma roleta.</p>
        <?php endif; ?>

        <div style="border-top:1px solid var(--line);padding-top:16px;">
            <strong style="font-size:14px;">Acesso rápido</strong>
            <div class="quick-grid">
                <a href="/pages/kanban.php" class="quick-link"><?= icon('kanban') ?>Meu Kanban</a>
                <a href="/pages/kanban.php" class="quick-link"><?= icon('user') ?>Registrar contato</a>
                <a href="/pages/kanban.php" class="quick-link"><?= icon('calendar') ?>Minha agenda</a>
            </div>
        </div>

        <div class="tip-box"><b>Dica rápida:</b> atualize a etapa do lead logo após cada contato.</div>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
