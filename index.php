<?php
/**
 * AURÉA — Harmonização Facial
 * Página única. PHP é usado apenas para montar HTML a partir de
 * includes/config.php e includes/procedures.php — nenhum processamento
 * dinâmico ocorre a cada rolagem; tudo roda no navegador (Canvas + JS).
 */
declare(strict_types=1);

$config     = require __DIR__ . '/includes/config.php';
$procedures = require __DIR__ . '/includes/procedures.php';

$totalScrollWeight = array_sum(array_column($procedures, 'scrollWeight'));
$scrollVh = max(600, (int) round($totalScrollWeight * 100));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?php echo htmlspecialchars(SITE_NAME); ?> — <?php echo htmlspecialchars(SITE_TAGLINE); ?></title>
<meta name="description" content="Experiência interativa de harmonização facial: olheiras, preenchimento labial, contorno mandibular, queixo, perfiloplastia, toxina botulínica e harmonização global.">
<meta name="theme-color" content="<?php echo COLOR_BACKGROUND_VIDEO; ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Playfair+Display:ital,wght@0,400;0,500;0,600;1,400&family=Jost:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/variables.css">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/responsive.css">
<link rel="icon" href="data:,">
</head>
<body id="top">

<?php include __DIR__ . '/includes/header.php'; ?>

<main id="main-content">
    <div class="experience-wrapper" id="experience-wrapper" style="height:<?php echo $scrollVh; ?>vh;">
        <section class="experience" id="experience" aria-label="Experiência de harmonização facial controlada por rolagem">

            <div class="experience__stage">
                <img id="face-poster" class="face-poster" src="" alt="" aria-hidden="true">
                <canvas id="face-canvas" aria-hidden="true"></canvas>
                <div class="hotspot-layer" id="hotspot-layer" aria-hidden="true"></div>
                <div class="jaw-guide" id="jaw-guide" aria-hidden="true"></div>
                <div class="profile-guide" id="profile-guide" aria-hidden="true"></div>
            </div>

            <div class="text-layer" id="text-layer">
                <?php foreach ($procedures as $proc): ?>
                <div class="procedure-text procedure-text--<?php echo htmlspecialchars($proc['text']['side']); ?> procedure-text--anchor-<?php echo htmlspecialchars($proc['text']['anchor']); ?>"
                     data-procedure-text="<?php echo htmlspecialchars($proc['id']); ?>" hidden>
                    <?php if (!empty($proc['isHero'])): ?>
                        <h1 class="procedure-title procedure-title--hero"><?php echo htmlspecialchars($proc['title']); ?></h1>
                        <?php if (!empty($proc['subtitle'])): ?><p class="procedure-subtitle"><?php echo htmlspecialchars($proc['subtitle']); ?></p><?php endif; ?>
                    <?php else: ?>
                        <h2 class="procedure-title"><?php echo htmlspecialchars($proc['title']); ?></h2>
                        <?php if (!empty($proc['description'])): ?><p class="procedure-description"><?php echo htmlspecialchars($proc['description']); ?></p><?php endif; ?>
                        <?php if (!empty($proc['pendingAsset'])): ?>
                            <p class="procedure-pending" data-interactive-demo="<?php echo htmlspecialchars($proc['interactiveDemo'] ?? ''); ?>" tabindex="0" role="button"
                               aria-label="Demonstração ilustrativa pendente: <?php echo htmlspecialchars($proc['pendingAsset']); ?>">
                                <span class="pending-badge">Pendente</span> Representação ilustrativa ainda não disponível.
                            </p>
                        <?php endif; ?>
                        <?php if (!empty($proc['isBooking'])): ?>
                            <button type="button" class="btn-whatsapp" data-booking-cta data-whatsapp-context="<?php echo htmlspecialchars($proc['id']); ?>">
                                <?php echo htmlspecialchars($proc['ctaLabel'] ?? 'Agendar avaliação'); ?>
                            </button>
                            <p class="booking-pending-note" data-booking-pending hidden>
                                Agendamento via WhatsApp em configuração — número ainda não cadastrado.
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="scroll-cue" id="scroll-cue" aria-hidden="true">
                <span class="scroll-cue__icon">&darr;</span>
                <span class="scroll-cue__label">SCROLL PARA EXPLORAR</span>
            </div>

            <nav class="progress-indicator" id="progress-indicator" aria-label="Progresso da experiência">
                <?php $i = 1; foreach ($procedures as $proc): if (!empty($proc['isHero'])) continue; ?>
                <button type="button" class="progress-dot" data-progress-dot="<?php echo htmlspecialchars($proc['id']); ?>" aria-label="Ir para <?php echo htmlspecialchars($proc['title']); ?>"></button>
                <?php $i++; endforeach; ?>
            </nav>

        </section>
    </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script id="procedures-data" type="application/json"><?php echo json_encode($procedures, JSON_UNESCAPED_UNICODE); ?></script>
<script>
window.AUREA_CONFIG = {
    whatsappNumber: <?php echo json_encode(WHATSAPP_NUMBER); ?>,
    whatsappDefaultMessage: <?php echo json_encode(WHATSAPP_DEFAULT_MESSAGE); ?>,
    frames: {
        desktop: { path: 'assets/media/frames/desktop/frame_', count: 120, ext: '.webp', pad: 3 },
        mobile:  { path: 'assets/media/frames/mobile/frame_',  count: 60,  ext: '.webp', pad: 3 }
    },
    posters: {
        desktop: 'assets/media/posters/poster_desktop.webp',
        mobile: 'assets/media/posters/poster_mobile.webp',
        lqip: 'assets/media/posters/poster_lqip.webp'
    },
    mobileBreakpoint: 760
};
</script>

<script src="assets/js/face-player.js"></script>
<script src="assets/js/camera-controller.js"></script>
<script src="assets/js/hotspots.js"></script>
<script src="assets/js/procedure-controller.js"></script>
<script src="assets/js/scroll-controller.js"></script>
<script src="assets/js/navigation.js"></script>
<script src="assets/js/main.js"></script>
</body>
</html>
