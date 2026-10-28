/**
 * ScrollController — converte a rolagem nativa da página em progresso
 * normalizado (0–1) e desacopla a renderização do evento de scroll: o
 * scroll apenas atualiza um alvo (`targetProgress`); um laço próprio de
 * requestAnimationFrame interpola (`renderedProgress`) e redesenha a
 * cada quadro, mantendo fluidez em scroll rápido, lento e reverso
 * (seção 7 e 8 do briefing). Nenhum evento wheel é bloqueado — a
 * rolagem continua nativa, com suporte íntegro a mouse, trackpad,
 * teclado e toque.
 */
class ScrollController {
    constructor({ wrapperEl, cameraController, facePlayer, procedureController, hotspotLayer, scrollCueEl, progressDots }) {
        this.wrapperEl = wrapperEl;
        this.cameraController = cameraController;
        this.facePlayer = facePlayer;
        this.procedureController = procedureController;
        this.hotspotLayer = hotspotLayer;
        this.scrollCueEl = scrollCueEl;

        this.targetProgress = 0;
        this.renderedProgress = 0;
        this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        this._onScroll = this._onScroll.bind(this);
        this._raf = this._raf.bind(this);

        window.addEventListener('scroll', this._onScroll, { passive: true });
        window.addEventListener('resize', this._onScroll, { passive: true });

        progressDots.forEach((btn) => {
            btn.addEventListener('click', () => this._jumpTo(btn.dataset.progressDot));
        });

        this._onScroll();
        this.renderedProgress = this.targetProgress;
        requestAnimationFrame(this._raf);
    }

    _computeProgress() {
        const total = this.wrapperEl.offsetHeight - window.innerHeight;
        if (total <= 0) return 0;
        const scrolled = window.scrollY - this.wrapperEl.offsetTop;
        return Math.min(Math.max(scrolled / total, 0), 1);
    }

    _onScroll() {
        this.targetProgress = this._computeProgress();
        if (this.scrollCueEl) {
            this.scrollCueEl.classList.toggle('is-hidden', this.targetProgress > 0.03);
        }
    }

    _raf() {
        const lerpFactor = this.reducedMotion ? 1 : 0.16;
        const delta = this.targetProgress - this.renderedProgress;
        this.renderedProgress += delta * lerpFactor;
        if (Math.abs(delta) < 0.0004) this.renderedProgress = this.targetProgress;

        const state = this.cameraController.getState(this.renderedProgress);
        this.facePlayer.draw(state.frameIndex, state.fx, state.fy, state.zoom, state.procedure.faceExclusion || null);
        this.procedureController.update(state);
        this.hotspotLayer.render(state.procedure);

        requestAnimationFrame(this._raf);
    }

    _jumpTo(procedureId) {
        const idx = this.cameraController.procedures.findIndex((p) => p.id === procedureId);
        if (idx < 0) return;
        const b = this.cameraController.boundaries[idx];
        const targetProgress = (b.start + b.end) / 2;
        const total = this.wrapperEl.offsetHeight - window.innerHeight;
        const targetY = this.wrapperEl.offsetTop + targetProgress * total;
        window.scrollTo({ top: targetY, behavior: this.reducedMotion ? 'auto' : 'smooth' });
    }
}
