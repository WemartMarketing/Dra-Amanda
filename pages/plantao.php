<?php
/**
 * Detalhes do plantão: foto, endereço completo, rotas (Google Maps / Waze),
 * mapa, horário, empreendimentos e "Entrar na roleta". A entrada na roleta
 * leva para Minha roleta, que aplica as regras que já existem (vínculo,
 * pausa, horário, presença por GPS dentro do raio) — ir até o plantão e
 * entrar na roleta são coisas independentes.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/plantoes_ui.php';

$usuario = auth_require();
$crumb = 'Plantões';
$activeNav = 'plantoes';
plantao_exigir_modulo($crumb, $activeNav);
$p = plantao_carregar($usuario, (int) ($_GET['id'] ?? 0));
if (!$p) {
    http_response_code(404);
    pagina_acesso_restrito($crumb, $activeNav, 'Plantão não encontrado');
}
$empresaId = (int) $usuario['empresa_ativa_id'];
$uid = (int) $usuario['id'];
$podeGerenciar = plantao_pode_gerenciar($usuario);
$foto = plantao_foto_url($p['foto']);
$coord = plantao_coordenadas($p);
$emps = plantao_empreendimentos($empresaId, (int) $p['id']);
$roletas = array_values(array_filter(plantao_roletas($empresaId, (int) $p['id']), fn ($r) => $r['ativa'] || $podeGerenciar));
$meu = plantao_usuario_vinculado((int) $p['id'], $uid);
$participa = usuario_tem_permissao($usuario, 'participar_roleta');
$autorizados = $podeGerenciar ? count(plantao_usuarios((int) $p['id'])) : 0;

$pageTitle = $p['nome'] . ' — ' . APP_NAME;
require __DIR__ . '/../includes/layout_start.php';
?>
<a class="back-link" href="/pages/plantoes.php">← Todos os plantões</a>
<div class="header">
    <div>
        <div class="eyebrow">Plantão de vendas</div>
        <h1><?= e($p['nome']) ?></h1>
        <div class="chip-row">
            <?php if ($meu): ?><span class="pill pill-green"><?= icon('check') ?>Você está neste plantão</span><?php endif; ?>
            <?php if (!$p['ativo']): ?><span class="pill pill-muted">Inativo</span><?php endif; ?>
        </div>
    </div>
    <?php if ($podeGerenciar): ?><div class="actions"><a class="btn btn-outline" href="/pages/plantao_editar.php?id=<?= (int) $p['id'] ?>"><?= icon('edit') ?>Editar plantão</a></div><?php endif; ?>
</div>

<div class="plantao-detalhe">
    <section class="card plantao-hero">
        <div class="plantao-hero-foto">
            <?php if ($foto): ?><img src="<?= e($foto) ?>" alt="Foto do stand <?= e($p['nome']) ?>">
            <?php else: ?><span class="plantao-foto-vazia"><?= icon('map') ?><small>Sem foto do stand</small></span><?php endif; ?>
        </div>
        <div class="plantao-hero-info">
            <span class="label-like">Endereço</span>
            <p class="plantao-endereco-grande"><?= e(plantao_endereco_completo($p) ?: 'Endereço não cadastrado') ?></p>
            <?php if ($p['observacoes']): ?><div class="tip-box"><?= icon('alert') ?><span><?= e($p['observacoes']) ?></span></div><?php endif; ?>
            <?php render_plantao_rotas($p, true); ?>
            <p class="muted plantao-nota-gps">Abrir a rota não pede a sua localização: o próprio aplicativo de mapas calcula o caminho.</p>
        </div>
    </section>

    <?php if ($coord): ?>
        <section class="card plantao-mapa">
            <iframe title="Mapa do plantão <?= e($p['nome']) ?>" loading="lazy" referrerpolicy="no-referrer"
                src="https://www.openstreetmap.org/export/embed.html?bbox=<?= e(rawurlencode(((float) $coord[1] - 0.006) . ',' . ((float) $coord[0] - 0.004) . ',' . ((float) $coord[1] + 0.006) . ',' . ((float) $coord[0] + 0.004))) ?>&amp;layer=mapnik&amp;marker=<?= e(rawurlencode($coord[0] . ',' . $coord[1])) ?>"></iframe>
        </section>
    <?php endif; ?>

    <section class="card">
        <div class="card-head"><div><h2>Roleta e horário</h2><p>Ir até o plantão e entrar na roleta são coisas separadas: ao entrar, o sistema confere sua localização.</p></div></div>
        <?php foreach ($roletas as $r): [$aberta, $motivo] = roleta_no_horario($r); $vinc = roleta_vinculo((int) $r['id'], $uid); ?>
            <div class="plantao-roleta">
                <div class="plantao-roleta-topo">
                    <strong><?= e($r['nome']) ?></strong>
                    <span class="pill <?= !$r['ativa'] ? 'pill-muted' : ($aberta ? 'pill-green' : 'pill-amber') ?>"><?= !$r['ativa'] ? 'Roleta pausada' : ($aberta ? 'Funcionando agora' : 'Fora do horário') ?></span>
                </div>
                <?php if ($r['ativa'] && !$aberta && $motivo): ?><p class="muted"><?= e($motivo) ?></p><?php endif; ?>
                <div class="dias-proximos">
                    <?php foreach (roleta_proximos_dias($r, 7) as $d): ?>
                        <div class="dia-proximo <?= $d['texto'] === 'Fechado' ? 'is-fechado' : '' ?>"><span class="dia-rotulo"><?= e($d['rotulo']) ?></span><strong><?= e($d['texto']) ?></strong><?php if ($d['exc']): ?><span class="pill pill-amber"><?= e($d['exc']) ?></span><?php endif; ?></div>
                    <?php endforeach; ?>
                </div>
                <?php if ($participa && $vinc && $r['ativa']): ?>
                    <a class="btn btn-primary btn-lg plantao-entrar" href="/pages/minha_roleta.php?roleta=<?= (int) $r['id'] ?>"><?= icon('target') ?>ENTRAR NA ROLETA</a>
                    <small class="field-hint"><?= $r['exigir_presenca'] ? 'Você precisa estar a até ' . (int) $p['raio_metros'] . ' m do plantão, no horário da roleta. O celular vai pedir sua localização nessa hora.' : 'Esta roleta não exige presença no plantão.' ?></small>
                <?php elseif ($participa && $r['ativa']): ?>
                    <p class="plantao-sem-rota"><?= icon('alert') ?>Você não está habilitado nesta roleta. Fale com seu gestor.</p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if (!$roletas): ?>
            <p><?= icon('clock') ?> <?= e($p['horario_descricao'] ?: 'Horário não informado.') ?></p>
            <p class="muted">Este plantão não tem roleta de distribuição de leads.</p>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="card-head"><div><h2>Empreendimentos atendidos</h2></div></div>
        <?php if ($emps): ?>
            <div class="chip-row"><?php foreach ($emps as $em): ?><a class="pill pill-muted" href="/pages/empreendimento.php?id=<?= (int) $em['id'] ?>"><?= e($em['nome']) ?></a><?php endforeach; ?></div>
        <?php else: ?><p class="empty-note">Nenhum empreendimento vinculado.</p><?php endif; ?>
        <?php if ($podeGerenciar): ?>
            <div class="roleta-detail-row" style="margin-top:12px;"><span class="label">Raio de presença</span><strong><?= (int) $p['raio_metros'] ?> m</strong></div>
            <div class="roleta-detail-row"><span class="label">Coordenadas</span><strong><?= $coord ? e($coord[0] . ', ' . $coord[1]) : 'Não informadas — a roleta não consegue conferir a presença' ?></strong></div>
            <div class="roleta-detail-row"><span class="label">Corretores autorizados no plantão</span><strong><?= $autorizados ?></strong></div>
        <?php endif; ?>
    </section>
</div>
<?php require __DIR__ . '/../includes/layout_end.php'; ?>
