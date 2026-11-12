<?php
/**
 * Minha roleta — check-in do corretor no plantão (Etapa 4).
 * Mobile-first: um status grande (verde = pode entrar, vermelho = bloqueado,
 * com o motivo), três botões e a reverificação automática da localização.
 * Quem decide é o servidor (api/roleta_presenca.php → includes/roleta.php).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/estrutura.php';
require_once __DIR__ . '/../includes/roleta.php';
require_once __DIR__ . '/../includes/lead_componentes.php';

$usuario = auth_require();
$empresaId = (int) $usuario['empresa_ativa_id'];
$uid = (int) $usuario['id'];
$crumb = 'Minha roleta';
$activeNav = 'minha_roleta';

if (!usuario_tem_permissao($usuario, 'participar_roleta')) {
    pagina_acesso_restrito($crumb, $activeNav, 'Participar de roletas');
}

roleta_fechar_expiradas($empresaId);

$stmt = db()->prepare('SELECT roletas.id, roletas.nome, roletas.ativa,
        (SELECT COUNT(*) FROM roleta_sessoes s WHERE s.roleta_id = roletas.id AND s.usuario_id = rc.usuario_id AND s.saiu_em IS NULL) AS aberta
    FROM roletas INNER JOIN roleta_corretores rc ON rc.roleta_id = roletas.id
    WHERE rc.usuario_id = :u AND roletas.empresa_id = :e
    ORDER BY aberta DESC, roletas.ativa DESC, roletas.nome');
$stmt->execute(['u' => $uid, 'e' => $empresaId]);
$minhas = $stmt->fetchAll();

$selId = (int) ($_GET['roleta'] ?? 0);
if (!in_array($selId, array_map('intval', array_column($minhas, 'id')), true)) {
    $selId = (int) ($minhas[0]['id'] ?? 0);
}
$roleta = $selId ? roleta_carregar($empresaId, $selId) : null;

$estado = null;
if ($roleta) {
    $vinculo = roleta_vinculo($selId, $uid);
    $sessao = roleta_sessao_aberta($selId, $uid);
    $naRoleta = roleta_sessao_valida($sessao, $roleta);
    $posicao = null;
    if ($naRoleta) {
        $fila = array_map('intval', array_column(roleta_elegiveis($roleta), 'usuario_id'));
        $p = array_search($uid, $fila, true);
        $posicao = $p === false ? null : $p + 1;
    }
    // Avaliação sem GPS: só regras que não dependem da posição (vínculo, pausa, horário).
    $previa = roleta_avaliar_posicao($roleta, $vinculo, null, null, null);
    $bloqueioFixo = !$previa['pode'] && !in_array($previa['codigo'], ['posicao_invalida'], true) ? $previa : null;
    $estado = [
        'roleta_id' => $selId,
        'exigir_presenca' => (bool) $roleta['exigir_presenca'],
        'na_roleta' => $naRoleta,
        'desde' => $naRoleta ? date('H:i', strtotime($sessao['entrou_em'])) : null,
        'ultima_verificacao' => $naRoleta ? strtotime($sessao['ultima_verificacao_em']) * 1000 : null,
        'posicao' => $posicao,
        'recebidos_hoje' => roleta_recebidos_hoje($selId, $uid)[$uid] ?? 0,
        'intervalo_min' => (int) $roleta['intervalo_verificacao_min'],
        'validade_min' => (int) $roleta['validade_presenca_min'],
        'bloqueio' => $bloqueioFixo ? ['codigo' => $bloqueioFixo['codigo'], 'mensagem' => $bloqueioFixo['mensagem']] : null,
    ];
}

$pageTitle = 'Minha roleta — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>
<div class="header">
    <div><div class="eyebrow">Plantão</div><h1>Minha roleta</h1><p>Entre na roleta quando estiver no plantão para receber leads.</p></div>
</div>

<?php if (!$roleta): ?>
    <div class="card stub-card">
        <div class="stub-icon"><?= icon('pin') ?></div>
        <h2>Você ainda não está em nenhuma roleta</h2>
        <p>Peça ao seu gestor para vincular você à roleta do seu plantão.</p>
    </div>
<?php else: ?>
    <?php if (count($minhas) > 1): ?>
        <div class="roleta-tabs" role="tablist">
            <?php foreach ($minhas as $m): ?>
                <a href="/pages/minha_roleta.php?roleta=<?= (int) $m['id'] ?>" class="chip-btn <?= (int) $m['id'] === $selId ? 'is-active' : '' ?>"><?= e($m['nome']) ?><?= $m['aberta'] ? ' · dentro' : '' ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="checkin" data-checkin data-estado="<?= e(json_encode($estado)) ?>">
        <div class="checkin-status is-neutro" data-status aria-live="polite">
            <strong data-status-titulo><?= $estado['na_roleta'] ? 'VOCÊ ESTÁ NA ROLETA' : 'VERIFICANDO SUA LOCALIZAÇÃO…' ?></strong>
            <span data-status-motivo><?= $estado['na_roleta'] ? 'Desde ' . e($estado['desde']) . '.' : 'Permita o acesso à localização quando o celular pedir.' ?></span>
        </div>

        <p class="checkin-linha" data-linha><?= $estado['na_roleta'] ? 'Na roleta desde ' . e($estado['desde']) : 'Fora da roleta' ?></p>

        <div class="checkin-botoes">
            <button type="button" class="btn btn-outline btn-xl" data-acao="verificar"><?= icon('pin') ?>VERIFICAR LOCALIZAÇÃO</button>
            <button type="button" class="btn btn-success btn-xl" data-acao="entrar" <?= $estado['na_roleta'] ? 'hidden' : 'disabled' ?>><?= icon('zap') ?>ENTRAR NA ROLETA</button>
            <button type="button" class="btn btn-danger-outline btn-xl" data-acao="sair" <?= $estado['na_roleta'] ? '' : 'hidden' ?>><?= icon('logout') ?>SAIR DA ROLETA</button>
        </div>

        <div class="checkin-numeros">
            <div><span>Sua posição no rodízio</span><strong data-posicao><?= $estado['posicao'] ? (int) $estado['posicao'] . 'º' : '—' ?></strong></div>
            <div><span>Leads recebidos hoje</span><strong data-hoje><?= (int) $estado['recebidos_hoje'] ?></strong></div>
        </div>

        <div class="checkin-aviso" data-inseguro hidden>
            Este endereço não usa HTTPS, e o celular só libera a localização em páginas seguras. Peça ao administrador para ativar o HTTPS (SSL) do sistema.
        </div>
    </div>

    <div class="card checkin-regras">
        <h2><?= e($roleta['nome']) ?></h2>
        <div class="roleta-detail-row"><span class="label">Plantão</span><strong><?= e($roleta['plantao_nome'] ?? 'Sem plantão') ?></strong></div>
        <?php if ($roleta['exigir_presenca']): ?>
            <div class="roleta-detail-row"><span class="label">Precisa estar a até</span><strong><?= (int) ($roleta['plantao_raio'] ?? 0) ?> m do plantão</strong></div>
        <?php else: ?>
            <div class="roleta-detail-row"><span class="label">Presença</span><strong>Esta roleta não exige GPS</strong></div>
        <?php endif; ?>
        <?php if (!roleta_horarios_personalizados((int) $roleta['id'])): ?>
            <div class="roleta-detail-row"><span class="label">Dias</span><strong><?= e(roleta_dias_texto($roleta['dias_semana'])) ?></strong></div>
            <div class="roleta-detail-row"><span class="label">Horário</span><strong><?= e(roleta_horario_texto($roleta)) ?></strong></div>
        <?php endif; ?>
        <div class="subhead" style="margin-top:12px;">Próximos dias</div>
        <div class="dias-proximos">
            <?php foreach (roleta_proximos_dias($roleta, 7) as $d): ?>
                <div class="dia-proximo <?= $d['texto'] === 'Fechado' ? 'is-fechado' : '' ?>"><span class="dia-rotulo"><?= e($d['rotulo']) ?></span><strong><?= e($d['texto']) ?></strong><?php if ($d['exc']): ?><span class="pill pill-amber"><?= e($d['exc']) ?></span><?php endif; ?></div>
            <?php endforeach; ?>
        </div>
        <p class="tip-box">Deixe esta página aberta enquanto estiver no plantão: ela confirma sua localização sozinha a cada <?= (int) $roleta['intervalo_verificacao_min'] ?> min. Se o celular bloquear a tela ou você fechar o navegador, a confirmação para e você sai da roleta depois de <?= (int) $roleta['validade_presenca_min'] ?> min sem confirmar.</p>
    </div>
<?php endif; ?>

<script src="/assets/js/minha_roleta.js" defer></script>
<?php require __DIR__ . '/../includes/layout_end.php'; ?>
