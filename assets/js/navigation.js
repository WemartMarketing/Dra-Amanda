/**
 * Navigation — overlays acessíveis (Sobre nós / Agendamento / Contato) e
 * botão de agendamento via WhatsApp (seções 6 e 11 do briefing).
 */
(function () {
    let lastFocused = null;

    function openOverlay(name) {
        const panel = document.querySelector(`[data-overlay-panel="${name}"]`);
        if (!panel) return;
        lastFocused = document.activeElement;
        panel.classList.add('is-open');
        panel.setAttribute('aria-hidden', 'false');
        const closeBtn = panel.querySelector('[data-overlay-close]');
        (closeBtn || panel).focus();
        document.addEventListener('keydown', onKeydown);
    }

    function closeOverlay(panel) {
        panel.classList.remove('is-open');
        panel.setAttribute('aria-hidden', 'true');
        document.removeEventListener('keydown', onKeydown);
        if (lastFocused) lastFocused.focus();
    }

    function onKeydown(e) {
        if (e.key !== 'Escape') return;
        const open = document.querySelector('.overlay-panel.is-open');
        if (open) closeOverlay(open);
    }

    document.querySelectorAll('[data-overlay]').forEach((btn) => {
        btn.addEventListener('click', () => openOverlay(btn.dataset.overlay));
    });

    document.querySelectorAll('[data-overlay-close]').forEach((btn) => {
        btn.addEventListener('click', () => closeOverlay(btn.closest('.overlay-panel')));
    });

    document.querySelectorAll('.overlay-panel').forEach((panel) => {
        panel.addEventListener('click', (e) => {
            if (e.target === panel) closeOverlay(panel);
        });
    });

    // --------------------------------------------------------------
    // Agendamento via WhatsApp
    // --------------------------------------------------------------
    // O botão [data-booking-cta] só existe no HTML quando WHATSAPP_NUMBER
    // está configurado (ver index.php); sem número, o CTA abre o overlay
    // de Agendamento ([data-overlay="agendamento"], tratado acima) em vez
    // de mostrar um link falso ou um aviso técnico ao visitante.
    const cfg = window.AUREA_CONFIG || {};

    document.querySelectorAll('[data-booking-cta]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const context = btn.dataset.whatsappContext || 'geral';
            const message = `${cfg.whatsappDefaultMessage} (${context})`;
            const url = `https://wa.me/${cfg.whatsappNumber}?text=${encodeURIComponent(message)}`;
            window.open(url, '_blank', 'noopener');
        });
    });
})();
