<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/estrutura.php';
require_once __DIR__ . '/../includes/lead_componentes.php';
require_once __DIR__ . '/../includes/importacao.php';

$usuario = auth_require();
$crumb = 'Oferta ativa';
$activeNav = 'oferta';
if (!usuario_tem_permissao($usuario, 'gerenciar_oferta_ativa')) {
    pagina_acesso_restrito($crumb, $activeNav, 'Importar listas e gerenciar oferta ativa');
}
$empresaId = (int) $usuario['empresa_ativa_id'];
$listaId = (int) ($_GET['id'] ?? 0);
$statusListas = ['importada' => ['Importada', 'pill-blue'], 'em_distribuicao' => ['Em distribuição', 'pill-amber'], 'encerrada' => ['Encerrada', 'pill-muted']];

/** Contadores de uma ou mais listas (só números). */
function oferta_contadores(int $empresaId, array $ids): array
{
    if (!$ids) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT l.oferta_lista_id AS id, COUNT(*) AS total,
            SUM(l.responsavel_id IS NOT NULL) AS distribuidos,
            SUM(l.responsavel_id IS NULL AND f.id IS NOT NULL) AS em_fila,
            SUM(l.etapa <> 'novo_lead' OR l.ultimo_contato_em IS NOT NULL) AS contatados
        FROM leads l
        LEFT JOIN roleta_fila f ON f.lead_id = l.id AND f.resolvido_em IS NULL
        WHERE l.empresa_id = ? AND l.excluido_em IS NULL AND l.oferta_lista_id IN ({$ph})
        GROUP BY l.oferta_lista_id");
    $stmt->execute(array_merge([$empresaId], $ids));
    $r = [];
    foreach ($stmt->fetchAll() as $row) {
        $r[(int) $row['id']] = array_map('intval', $row);
    }
    return $r;
}

if ($listaId) {
    $lista = oferta_lista_carregar($usuario, $listaId);
    if (!$lista) {
        http_response_code(404);
        $pageTitle = 'Lista não encontrada — ' . APP_NAME;
        require __DIR__ . '/../includes/layout_start.php';
        echo '<div class="card stub-card"><div class="stub-icon">' . icon('alert') . '</div><h2>Lista não encontrada</h2><p><a class="link-btn" href="/pages/oferta.php">Voltar para a oferta ativa</a></p></div>';
        require __DIR__ . '/../includes/layout_end.php';
        exit;
    }
    $cont = oferta_contadores($empresaId, [$listaId])[$listaId] ?? ['total' => 0, 'distribuidos' => 0, 'em_fila' => 0, 'contatados' => 0];
    $semCorretor = $cont['total'] - $cont['distribuidos'];

    $stmt = db()->prepare('SELECT etapa, COUNT(*) AS n FROM leads WHERE empresa_id = :e AND oferta_lista_id = :l AND excluido_em IS NULL GROUP BY etapa');
    $stmt->execute(['e' => $empresaId, 'l' => $listaId]);
    $porEtapa = array_map('intval', array_column($stmt->fetchAll(), 'n', 'etapa'));

    // Por corretor: só leads dentro do escopo de quem olha.
    $scope = rbac_leads_scope($usuario);
    $stmt = db()->prepare("SELECT u.nome, COUNT(*) AS recebidos,
            SUM(leads.etapa <> 'novo_lead' OR leads.ultimo_contato_em IS NOT NULL) AS contatados,
            SUM(leads.etapa IN ('visita_agendada','visita_realizada','contrato_assinado')) AS visitas
        FROM leads INNER JOIN usuarios u ON u.id = leads.responsavel_id
        WHERE leads.oferta_lista_id = :rl_lista AND {$scope['sql']}
        GROUP BY u.id, u.nome ORDER BY recebidos DESC, u.nome");
    $stmt->execute($scope['params'] + ['rl_lista' => $listaId]);
    $porCorretor = $stmt->fetchAll();
    $foraDoRecorte = $cont['distribuidos'] - array_sum(array_map('intval', array_column($porCorretor, 'recebidos')));

    $stmt = db()->prepare("SELECT a.acao, a.detalhes_json, a.criado_em, u.nome AS usuario_nome FROM auditoria a LEFT JOIN usuarios u ON u.id = a.usuario_id
        WHERE a.empresa_id = :e AND a.entidade = 'oferta_lista' AND a.entidade_id = :l ORDER BY a.id DESC LIMIT 30");
    $stmt->execute(['e' => $empresaId, 'l' => $listaId]);
    $historico = $stmt->fetchAll();
    $corretores = oferta_corretores($usuario);
    $categorias = funil_categorias();
} else {
    $sql = 'SELECT ol.*, emp.nome AS empreendimento_nome, u.nome AS criado_por_nome FROM oferta_listas ol
        LEFT JOIN empreendimentos emp ON emp.id = ol.empreendimento_id LEFT JOIN usuarios u ON u.id = ol.criado_por
        WHERE ol.empresa_id = :e';
    $params = ['e' => $empresaId];
    if (!oferta_ve_todas($usuario)) {
        $sql .= ' AND ol.criado_por = :u';
        $params['u'] = (int) $usuario['id'];
    }
    $stmt = db()->prepare($sql . ' ORDER BY ol.id DESC LIMIT 100');
    $stmt->execute($params);
    $listas = $stmt->fetchAll();
    $contadores = oferta_contadores($empresaId, array_map('intval', array_column($listas, 'id')));
}

$pageTitle = 'Oferta ativa — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>

<?php if (!$listaId): ?>
    <div class="header">
        <div>
            <div class="eyebrow">Prospecção</div>
            <h1>Oferta ativa</h1>
            <p>Listas importadas de planilhas, para a equipe ligar e transformar em atendimento.</p>
        </div>
        <div class="actions"><a href="/pages/importar.php" class="btn btn-primary"><?= icon('upload') ?>Importar planilha</a></div>
    </div>

    <?php if (!$listas): ?>
        <div class="card stub-card"><div class="stub-icon"><?= icon('list') ?></div><h2>Nenhuma lista ainda</h2><p>Importe uma planilha CSV ou XLSX com nome e telefone dos contatos.</p><a href="/pages/importar.php" class="btn btn-primary"><?= icon('upload') ?>Importar a primeira lista</a></div>
    <?php else: ?>
        <div class="oferta-lista">
            <?php foreach ($listas as $l): $c = $contadores[(int) $l['id']] ?? ['total' => 0, 'distribuidos' => 0, 'em_fila' => 0, 'contatados' => 0]; [$rot, $pill] = $statusListas[$l['status']];
                $pct = $c['total'] ? round($c['contatados'] / $c['total'] * 100) : 0; ?>
                <a class="card oferta-card" href="/pages/oferta.php?id=<?= (int) $l['id'] ?>">
                    <div class="oferta-card-top">
                        <div><h2><?= e($l['nome']) ?></h2><p class="muted"><?= e($l['empreendimento_nome'] ?? 'Sem empreendimento') ?> · <?= e(date('d/m/Y', strtotime($l['criado_em']))) ?> · por <?= e($l['criado_por_nome'] ?? '—') ?></p></div>
                        <span class="pill <?= $pill ?>"><?= e($rot) ?></span>
                    </div>
                    <div class="oferta-numeros">
                        <span><b><?= $c['total'] ?></b>leads</span>
                        <span><b><?= $c['distribuidos'] ?></b>com corretor</span>
                        <span><b><?= $c['em_fila'] ?></b>na fila</span>
                        <span><b><?= $c['contatados'] ?></b>contatados</span>
                    </div>
                    <div class="funnel-track" aria-label="<?= $pct ?>% contatados"><div class="funnel-fill" style="width: <?= max(2, $pct) ?>%"></div></div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php else: [$rot, $pill] = $statusListas[$lista['status']]; ?>
    <div class="header">
        <div>
            <a class="back-link" href="/pages/oferta.php"><?= icon('arrow-r') ?>Voltar para a oferta ativa</a>
            <div class="eyebrow">Lista de oferta ativa · <span class="pill <?= $pill ?>"><?= e($rot) ?></span></div>
            <h1><?= e($lista['nome']) ?></h1>
            <p><?= e($lista['empreendimento_nome'] ?? 'Sem empreendimento') ?> · importada em <?= e(date('d/m/Y H:i', strtotime($lista['criado_em']))) ?> por <?= e($lista['criado_por_nome'] ?? '—') ?> · arquivo <?= e($lista['arquivo_nome']) ?></p>
        </div>
        <div class="actions">
            <a href="/pages/leads.php?lista=<?= (int) $lista['id'] ?>" class="btn btn-outline"><?= icon('list') ?>Ver leads da lista</a>
            <a href="/pages/kanban.php?lista=<?= (int) $lista['id'] ?>" class="btn btn-outline"><?= icon('kanban') ?>Kanban da lista</a>
        </div>
    </div>

    <div class="kpi-grid kpi-grid-5">
        <div class="kpi"><div class="kpi-top"><span class="label">Leads na lista</span><div class="kpi-icon"><?= icon('list') ?></div></div><div class="kpi-value"><?= $cont['total'] ?></div><div class="kpi-sub"><?= (int) $lista['duplicados'] + (int) $lista['invalidos'] ?> linha(s) recusada(s) na importação</div></div>
        <div class="kpi"><div class="kpi-top"><span class="label">Com corretor</span><div class="kpi-icon"><?= icon('user') ?></div></div><div class="kpi-value"><?= $cont['distribuidos'] ?></div><div class="kpi-sub">distribuídos</div></div>
        <div class="kpi"><div class="kpi-top"><span class="label">Sem corretor</span><div class="kpi-icon"><?= icon('clock') ?></div></div><div class="kpi-value"><?= $semCorretor ?></div><div class="kpi-sub"><?= $cont['em_fila'] ?> na fila da roleta</div></div>
        <div class="kpi"><div class="kpi-top"><span class="label">Contatados</span><div class="kpi-icon"><?= icon('phone') ?></div></div><div class="kpi-value"><?= $cont['contatados'] ?></div><div class="kpi-sub"><?= $cont['total'] ? round($cont['contatados'] / $cont['total'] * 100) : 0 ?>% da lista</div></div>
        <div class="kpi"><div class="kpi-top"><span class="label">Visitas e vendas</span><div class="kpi-icon"><?= icon('check') ?></div></div><div class="kpi-value"><?= ($porEtapa['visita_agendada'] ?? 0) + ($porEtapa['visita_realizada'] ?? 0) + ($porEtapa['contrato_assinado'] ?? 0) ?></div><div class="kpi-sub"><?= $porEtapa['contrato_assinado'] ?? 0 ?> contrato(s)</div></div>
    </div>

    <div class="grid-2">
        <div class="card">
            <div class="card-head"><div><h2>Distribuir</h2><p><?= $semCorretor ? "<b>{$semCorretor}</b> lead" . ($semCorretor === 1 ? '' : 's') . ' sem corretor.' : 'Todos os leads já têm corretor.' ?></p></div></div>
            <?php if ($lista['status'] === 'encerrada'): ?>
                <p class="empty-note">Lista encerrada.</p>
            <?php elseif ($semCorretor): ?>
                <div class="distrib-opcao">
                    <h3><?= icon('zap') ?>Pela roleta</h3>
                    <p class="muted">Cada lead vai para o próximo corretor de plantão na roleta do empreendimento. Sem ninguém disponível, espera na fila.</p>
                    <button type="button" class="btn btn-primary" data-api-acao="/api/oferta_distribuir.php" data-acao="roleta" data-lista_id="<?= (int) $lista['id'] ?>" data-confirmar="Enviar <?= $semCorretor ?> lead(s) para a roleta?" <?= $lista['empreendimento_id'] ? '' : 'disabled' ?>>Distribuir pela roleta</button>
                    <?php if (!$lista['empreendimento_id']): ?><small class="field-hint">A lista não tem empreendimento — use a divisão igual.</small><?php endif; ?>
                </div>
                <form class="distrib-opcao" data-api-form action="/api/oferta_distribuir.php">
                    <h3><?= icon('users') ?>Dividir igualmente</h3>
                    <p class="muted">Os leads são repartidos em rodízio entre os corretores marcados.</p>
                    <input type="hidden" name="acao" value="dividir"><input type="hidden" name="lista_id" value="<?= (int) $lista['id'] ?>">
                    <?php if ($corretores): ?>
                        <div class="checkbox-grid">
                            <?php foreach ($corretores as $c): ?><label class="checkbox-row"><input type="checkbox" name="usuario_ids[]" value="<?= (int) $c['id'] ?>"> <?= e($c['nome']) ?><small class="muted"><?= e($c['equipe_nome'] ?? '') ?></small></label><?php endforeach; ?>
                        </div>
                        <div class="form-alert" data-form-alerta hidden></div>
                        <button type="submit" class="btn btn-outline" style="margin-top:12px;">Dividir entre os marcados</button>
                    <?php else: ?>
                        <p class="empty-note">Nenhum corretor ativo na sua estrutura.</p>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
            <?php if ($lista['status'] !== 'encerrada'): ?>
                <button type="button" class="link-btn" data-api-acao="/api/oferta_distribuir.php" data-acao="encerrar" data-lista_id="<?= (int) $lista['id'] ?>" data-confirmar="Encerrar esta lista? Os leads continuam com os corretores.">Encerrar lista</button>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-head"><div><h2>Andamento por etapa</h2><p>Todos os leads da lista.</p></div></div>
            <?php $maxEt = max(1, ...array_values($porEtapa ?: [1])); ?>
            <div class="funnel">
                <?php foreach ($categorias as $cat => $rotCat): if (empty($porEtapa[$cat])) continue; ?>
                    <div class="funnel-row"><span class="funnel-label"><?= e($rotCat) ?></span><div class="funnel-track"><div class="funnel-fill" style="width: <?= max(2, round($porEtapa[$cat] / $maxEt * 100)) ?>%"></div></div><span class="funnel-value"><?= $porEtapa[$cat] ?></span></div>
                <?php endforeach; ?>
                <?php if (!$porEtapa): ?><p class="empty-note">Sem leads.</p><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="grid-2" style="margin-top:20px;">
        <div class="card">
            <div class="card-head"><div><h2>Por corretor</h2><p>Dentro da sua estrutura.</p></div></div>
            <?php if ($porCorretor): ?>
                <div class="table-wrap"><table class="report-table">
                    <thead><tr><th>Corretor</th><th>Recebidos</th><th>Contatados</th><th>Visitas/vendas</th></tr></thead>
                    <tbody><?php foreach ($porCorretor as $r): ?><tr><td><?= e($r['nome']) ?></td><td><?= (int) $r['recebidos'] ?></td><td><?= (int) $r['contatados'] ?></td><td><?= (int) $r['visitas'] ?></td></tr><?php endforeach; ?></tbody>
                </table></div>
            <?php else: ?><p class="empty-note">Nenhum lead distribuído ainda.</p><?php endif; ?>
            <?php if ($foraDoRecorte > 0): ?><p class="muted">+ <?= $foraDoRecorte ?> lead(s) com corretores fora da sua estrutura.</p><?php endif; ?>
        </div>
        <div class="card">
            <div class="card-head"><div><h2>Histórico da lista</h2></div></div>
            <?php foreach ($historico as $h): $d = json_decode((string) $h['detalhes_json'], true) ?: []; ?>
                <div class="hist-row">
                    <b><?= e(date('d/m H:i', strtotime($h['criado_em']))) ?></b>
                    <span><?php
                        if ($h['acao'] === 'oferta.lista_importada') {
                            echo 'Importada por ' . e($h['usuario_nome'] ?? '—') . ': ' . (int) ($d['importados'] ?? 0) . ' leads de ' . (int) ($d['lidas'] ?? 0) . ' linhas';
                        } elseif ($h['acao'] === 'oferta.distribuida') {
                            echo e($h['usuario_nome'] ?? '—') . ' distribuiu ' . (int) ($d['distribuidos'] ?? 0) . ' lead(s) ' . (($d['modo'] ?? '') === 'roleta' ? 'pela roleta' : 'em divisão igual') . (!empty($d['em_fila']) ? ' · ' . (int) $d['em_fila'] . ' na fila' : '');
                        } elseif ($h['acao'] === 'oferta.lista_encerrada') {
                            echo 'Encerrada por ' . e($h['usuario_nome'] ?? '—');
                        } else {
                            echo e($h['acao']);
                        }
                    ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
