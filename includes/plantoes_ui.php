<?php
/**
 * Componentes da tela de plantões: botões de rota (Google Maps, Waze,
 * copiar endereço) e o card da lista. Tudo passa por e(); as URLs de rota
 * são montadas só em includes/plantoes.php, com as coordenadas codificadas.
 */
require_once __DIR__ . '/plantoes.php';
require_once __DIR__ . '/lead_componentes.php';

/** Botões de navegação. $grande: versão da página de detalhes. */
function render_plantao_rotas(array $p, bool $grande = false): void
{
    $links = plantao_links_navegacao($p);
    $endereco = plantao_endereco_busca($p);
    if (!$links) {
        echo '<p class="plantao-sem-rota">' . icon('alert') . 'Endereço ainda não cadastrado. Peça ao gestor para completar o cadastro deste plantão.</p>';
        return;
    }
    ?>
    <div class="plantao-rotas<?= $grande ? ' is-grande' : '' ?>">
        <a class="btn plantao-btn-google" href="<?= e($links['google']) ?>" data-rota rel="noopener noreferrer"><?= icon('map') ?>Google Maps</a>
        <a class="btn plantao-btn-waze" href="<?= e($links['waze']) ?>" data-rota rel="noopener noreferrer"><?= icon('navegar') ?>Waze</a>
        <?php if ($endereco !== ''): ?>
            <button type="button" class="btn btn-outline" data-copiar="<?= e($endereco) ?>"><?= icon('copiar') ?>Copiar endereço</button>
        <?php endif; ?>
    </div>
    <?php if (!$links['por_coordenada']): ?>
        <small class="plantao-aviso-rota">Sem coordenadas cadastradas: a rota busca pelo endereço. Confira o destino no aplicativo.</small>
    <?php endif;
}

function render_plantao_card(array $p): void
{
    $foto = plantao_foto_url($p['foto'] ?? null);
    $emps = $p['empreendimentos'] ?? [];
    ?>
    <article class="plantao-cartao<?= $p['ativo'] ? '' : ' is-inativo' ?>">
        <a class="plantao-foto" href="/pages/plantao.php?id=<?= (int) $p['id'] ?>" aria-label="Ver detalhes do plantão <?= e($p['nome']) ?>">
            <?php if ($foto): ?><img src="<?= e($foto) ?>" alt="Foto do stand <?= e($p['nome']) ?>" loading="lazy">
            <?php else: ?><span class="plantao-foto-vazia"><?= icon('map') ?></span><?php endif; ?>
        </a>
        <div class="plantao-corpo">
            <div class="plantao-topo">
                <h2><a href="/pages/plantao.php?id=<?= (int) $p['id'] ?>"><?= e($p['nome']) ?></a></h2>
                <?php if (!empty($p['meu'])): ?><span class="pill pill-green"><?= icon('check') ?>Você está neste plantão</span><?php endif; ?>
                <?php if (!$p['ativo']): ?><span class="pill pill-muted">Inativo</span><?php endif; ?>
            </div>
            <p class="plantao-endereco"><?= e(trim(($p['endereco'] ?? '') . (!empty($p['complemento']) ? ' — ' . $p['complemento'] : '')) ?: 'Endereço não cadastrado') ?></p>
            <p class="plantao-local"><?= e(implode(' · ', array_filter([$p['bairro'] ?? '', trim(($p['cidade'] ?? '') . (!empty($p['estado']) ? '/' . $p['estado'] : ''), '/'), !empty($p['cep']) ? 'CEP ' . $p['cep'] : '']))) ?></p>
            <?php if (!empty($p['horario'])): ?><p class="plantao-horario"><?= icon('clock') ?><span><?= e($p['horario']) ?></span></p><?php endif; ?>
            <?php if ($emps): ?>
                <div class="plantao-emps">
                    <?php foreach (array_slice($emps, 0, 4) as $em): ?><span class="pill pill-muted"><?= e($em['nome']) ?></span><?php endforeach; ?>
                    <?php if (count($emps) > 4): ?><span class="pill pill-muted">+<?= count($emps) - 4 ?></span><?php endif; ?>
                </div>
            <?php endif; ?>
            <?php render_plantao_rotas($p); ?>
            <a class="btn btn-secondary plantao-detalhes" href="/pages/plantao.php?id=<?= (int) $p['id'] ?>">Ver detalhes<?= icon('arrow-r') ?></a>
        </div>
    </article>
    <?php
}
