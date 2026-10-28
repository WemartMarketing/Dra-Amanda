/**
 * ProcedureController — alterna qual bloco de texto/qual ponto de
 * progresso está ativo, garantindo que textos de etapas diferentes
 * nunca fiquem visíveis simultaneamente (seção 10 do briefing).
 */
class ProcedureController {
    constructor(textLayer, progressIndicator) {
        this.textEls = {};
        textLayer.querySelectorAll('[data-procedure-text]').forEach((el) => {
            this.textEls[el.dataset.procedureText] = el;
        });
        this.dotEls = {};
        progressIndicator.querySelectorAll('[data-progress-dot]').forEach((el) => {
            this.dotEls[el.dataset.progressDot] = el;
        });
        this._activeId = null;
    }

    update(state) {
        const proc = state.procedure;
        if (proc.id === this._activeId) return;

        Object.entries(this.textEls).forEach(([id, el]) => {
            const active = id === proc.id;
            el.classList.toggle('is-active', active);
            el.hidden = !active;
        });

        Object.entries(this.dotEls).forEach(([id, el]) => {
            el.classList.toggle('is-active', id === proc.id);
        });

        this._activeId = proc.id;
    }
}
