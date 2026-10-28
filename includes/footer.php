<?php
/** @var array $config Retorno de includes/config.php (profissional + contato). */
$professional = $config['professional'] ?? [];
$contact      = $config['contact'] ?? [];
$hasWhatsapp  = defined('WHATSAPP_NUMBER') && WHATSAPP_NUMBER !== '';
?>
<div class="overlay-layer" aria-hidden="true">

    <section id="overlay-sobre" class="overlay-panel" data-overlay-panel="sobre" role="dialog" aria-modal="true" aria-labelledby="overlay-sobre-title" tabindex="-1">
        <div class="overlay-panel__inner">
            <button type="button" class="overlay-close" data-overlay-close aria-label="Fechar">&times;</button>
            <h2 id="overlay-sobre-title">Sobre nós</h2>
            <?php if (empty($professional['name']) && empty($professional['bio'])): ?>
                <p class="overlay-pending">
                    Conteúdo pendente de configuração. Preencha os dados da profissional e da
                    clínica em <code>includes/config.php</code> (nome, especialidade, registro
                    profissional, biografia, endereço e foto) — nenhuma informação foi
                    inventada nesta versão.
                </p>
            <?php else: ?>
                <?php if (!empty($professional['photo'])): ?>
                    <img class="overlay-photo" src="<?php echo htmlspecialchars($professional['photo']); ?>" alt="<?php echo htmlspecialchars($professional['name']); ?>">
                <?php endif; ?>
                <?php if (!empty($professional['name'])): ?><p class="overlay-name"><?php echo htmlspecialchars($professional['name']); ?></p><?php endif; ?>
                <?php if (!empty($professional['specialty'])): ?><p class="overlay-specialty"><?php echo htmlspecialchars($professional['specialty']); ?></p><?php endif; ?>
                <?php if (!empty($professional['license'])): ?><p class="overlay-license"><?php echo htmlspecialchars($professional['license']); ?></p><?php endif; ?>
                <?php if (!empty($professional['bio'])): ?><p class="overlay-bio"><?php echo nl2br(htmlspecialchars($professional['bio'])); ?></p><?php endif; ?>
                <?php if (!empty($professional['clinic_name']) || !empty($professional['address'])): ?>
                    <p class="overlay-clinic">
                        <?php echo htmlspecialchars($professional['clinic_name']); ?><br>
                        <?php echo htmlspecialchars($professional['address']); ?>
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>

    <section id="overlay-agendamento" class="overlay-panel" data-overlay-panel="agendamento" role="dialog" aria-modal="true" aria-labelledby="overlay-agendamento-title" tabindex="-1">
        <div class="overlay-panel__inner">
            <button type="button" class="overlay-close" data-overlay-close aria-label="Fechar">&times;</button>
            <h2 id="overlay-agendamento-title">Agendamento</h2>
            <?php if ($hasWhatsapp): ?>
                <p>Fale conosco pelo WhatsApp e agende sua avaliação.</p>
                <a class="btn-whatsapp" data-whatsapp-link data-whatsapp-context="geral"
                   href="https://wa.me/<?php echo htmlspecialchars(WHATSAPP_NUMBER); ?>?text=<?php echo rawurlencode(WHATSAPP_DEFAULT_MESSAGE); ?>"
                   target="_blank" rel="noopener">Agendar pelo WhatsApp</a>
            <?php else: ?>
                <p class="overlay-pending">
                    Integração com WhatsApp pendente de configuração. Defina
                    <code>WHATSAPP_NUMBER</code> em <code>includes/config.php</code> para
                    ativar o agendamento — nenhum número de exemplo foi utilizado.
                </p>
            <?php endif; ?>
        </div>
    </section>

    <section id="overlay-contato" class="overlay-panel" data-overlay-panel="contato" role="dialog" aria-modal="true" aria-labelledby="overlay-contato-title" tabindex="-1">
        <div class="overlay-panel__inner">
            <button type="button" class="overlay-close" data-overlay-close aria-label="Fechar">&times;</button>
            <h2 id="overlay-contato-title">Contato</h2>
            <?php if (empty($contact['whatsapp_display']) && empty($contact['instagram']) && empty($contact['address']) && empty($contact['hours'])): ?>
                <p class="overlay-pending">
                    Informações de contato pendentes de configuração em
                    <code>includes/config.php</code> (WhatsApp, Instagram, endereço, horário de
                    atendimento) — nenhum dado foi inventado.
                </p>
            <?php else: ?>
                <ul class="contact-list">
                    <?php if (!empty($contact['whatsapp_display'])): ?><li>WhatsApp: <?php echo htmlspecialchars($contact['whatsapp_display']); ?></li><?php endif; ?>
                    <?php if (!empty($contact['instagram'])): ?><li>Instagram: <?php echo htmlspecialchars($contact['instagram']); ?></li><?php endif; ?>
                    <?php if (!empty($contact['address'])): ?><li><?php echo htmlspecialchars($contact['address']); ?></li><?php endif; ?>
                    <?php if (!empty($contact['hours'])): ?><li><?php echo htmlspecialchars($contact['hours']); ?></li><?php endif; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>

</div>
