<?php
/**
 * Card "Anúncio que gerou este lead" (ficha do lead). Recebe o resultado de
 * meta_criativo_do_lead() — tudo passa por e(); a imagem vem do nosso
 * próprio endpoint e o link só se foi validado como domínio da Meta.
 */
require_once __DIR__ . '/meta_criativos.php';

function render_criativo_card(array $c): void
{
    $data = $c['cadastro_em'] ? date('d/m/Y \à\s H\hi', strtotime($c['cadastro_em'])) : '—';
    ?>
    <section class="card criativo-card" aria-labelledby="criativo-titulo" data-criativo-dados='<?= e(json_encode($c, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_TAG)) ?>'>
        <div class="criativo-midia">
            <?php if ($c['imagem']): ?>
                <button type="button" class="criativo-preview" data-criativo-ampliar aria-label="Ampliar o criativo do anúncio">
                    <img src="<?= e($c['imagem']) ?>" alt="Criativo do anúncio<?= $c['anuncio'] ? ' ' . e($c['anuncio']) : '' ?>" loading="lazy">
                    <?php if ($c['midia_tipo'] === 'video'): ?><span class="criativo-play" aria-hidden="true"><?= icon('play') ?></span><span class="criativo-tag">Vídeo</span>
                    <?php elseif ($c['midia_tipo'] === 'carrossel'): ?><span class="criativo-tag">Carrossel</span><?php endif; ?>
                </button>
            <?php else: ?>
                <div class="criativo-vazio"><?= icon('anuncio') ?><span>Criativo indisponível</span></div>
            <?php endif; ?>
        </div>
        <div class="criativo-info">
            <div class="eyebrow" id="criativo-titulo"><?= icon('anuncio') ?>Anúncio que gerou este lead</div>
            <dl class="criativo-dados">
                <div><dt>Campanha</dt><dd><?= e($c['campanha'] ?: '—') ?></dd></div>
                <div><dt>Conjunto de anúncios</dt><dd><?= e($c['conjunto'] ?: '—') ?></dd></div>
                <div><dt>Anúncio</dt><dd><?= e($c['anuncio'] ?: ($c['tem_anuncio'] ? 'Aguardando dados da Meta' : 'Cadastro sem anúncio (orgânico)')) ?></dd></div>
                <div><dt>Plataforma</dt><dd><?= e($c['plataforma'] ?: '—') ?></dd></div>
                <div><dt>Cadastro</dt><dd><?= e($data) ?></dd></div>
                <?php if ($c['formulario']): ?><div><dt>Formulário</dt><dd><?= e($c['formulario']) ?></dd></div><?php endif; ?>
            </dl>
            <?php if ($c['titulo']): ?><p class="criativo-titulo-anuncio"><?= e($c['titulo']) ?></p><?php endif; ?>
            <?php if ($c['texto']): ?>
                <p class="criativo-texto"><?= nl2br(e(mb_strimwidth($c['texto'], 0, 600, '…'))) ?></p>
            <?php endif; ?>
            <?php if ($c['cta']): ?><span class="pill pill-muted">Botão do anúncio: <?= e($c['cta']) ?></span><?php endif; ?>
            <?php foreach ($c['avisos'] as $a): ?><div class="rule-box"><?= icon('alert') ?><span><?= e($a) ?></span></div><?php endforeach; ?>
            <div class="criativo-acoes">
                <?php if ($c['link_oficial']): ?>
                    <a class="btn btn-primary" href="<?= e($c['link_oficial']) ?>" target="_blank" rel="noopener noreferrer"><?= icon('externo') ?><?= e($c['link_rotulo']) ?></a>
                <?php elseif ($c['tem_anuncio']): ?>
                    <span class="muted">A Meta não informou um link de visualização para este anúncio.</span>
                <?php endif; ?>
            </div>
            <p class="criativo-nota">Origem registrada pelo formulário que o cliente enviou — não é o histórico completo de cliques e visualizações dele.</p>
        </div>
    </section>
    <?php
}
