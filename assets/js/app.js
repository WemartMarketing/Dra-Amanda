// WeMart Sales — JS vanilla (sem dependências externas)

(function () {
    async function moverLead(leadId, destino, cardEl) {
        const r = await fetch('/api/lead_mover_etapa.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ lead_id: leadId, etapa: destino }),
        });
        if (r.ok) {
            location.reload();
        } else {
            alert('Não foi possível mover este lead.');
        }
    }

    // Botões "Voltar"/"Avançar" nos cards (funcionam em qualquer dispositivo,
    // inclusive sem suporte a drag-and-drop no celular).
    document.querySelectorAll('[data-move]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            moverLead(this.dataset.move, this.dataset.destino, this.closest('.kcard'));
        });
    });

    // Drag-and-drop (desktop) entre colunas do Kanban.
    let arrastando = null;
    document.querySelectorAll('.kcard').forEach(function (card) {
        card.addEventListener('dragstart', function () {
            arrastando = card;
            card.classList.add('dragging');
        });
        card.addEventListener('dragend', function () {
            card.classList.remove('dragging');
            arrastando = null;
        });
    });
    document.querySelectorAll('.kcol').forEach(function (col) {
        col.addEventListener('dragover', function (e) {
            e.preventDefault();
            col.classList.add('drag-over');
        });
        col.addEventListener('dragleave', function () {
            col.classList.remove('drag-over');
        });
        col.addEventListener('drop', function (e) {
            e.preventDefault();
            col.classList.remove('drag-over');
            if (!arrastando) return;
            const destino = col.dataset.etapa;
            if (destino && destino !== arrastando.dataset.etapa) {
                moverLead(arrastando.dataset.id, destino, arrastando);
            }
        });
    });
})();
