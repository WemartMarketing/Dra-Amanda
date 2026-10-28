/**
 * FacePlayer — reprodutor de sequência de quadros em Canvas.
 *
 * Mantém a proporção original do material (equivalente a object-fit:
 * contain) e nunca deforma ou corta o enquadramento. O "zoom" da câmera
 * virtual é simulado desenhando o quadro em escala maior e deslocando-o
 * para que o ponto focal configurado fique centralizado — o canvas corta
 * naturalmente o que sai de seus limites, sem CSS de recorte adicional.
 *
 * PONTO DE TROCA PARA A SEQUÊNCIA REAL:
 * Este player já consome a sequência real de 120 quadros (desktop) e 60
 * quadros (mobile) extraída dos dois vídeos-fonte — não é mais um
 * placeholder abstrato. Para trocar por uma sequência nova (outra
 * modelo, nova sessão de vídeo), basta substituir os arquivos em
 * assets/media/frames/{desktop,mobile}/frame_XXX.webp mantendo a mesma
 * numeração sequencial e atualizar `count` em window.AUREA_CONFIG.frames
 * (em index.php) — nenhuma outra mudança de código é necessária.
 */
class FacePlayer {
    constructor(canvas, posterEl, config) {
        this.canvas = canvas;
        this.ctx = canvas.getContext('2d');
        this.posterEl = posterEl;
        this.config = config;

        this.isMobile = window.innerWidth <= config.mobileBreakpoint;
        this.frameSet = this.isMobile ? config.frames.mobile : config.frames.desktop;
        this.poster = this.isMobile ? config.posters.mobile : config.posters.desktop;
        // Os índices de quadro em includes/procedures.php são definidos na
        // numeração do conjunto desktop (1–120); reescalamos para o
        // conjunto ativo (ex.: 1–60 no mobile) para que a mesma
        // configuração de câmera aponte sempre para a mesma pose relativa,
        // independentemente da resolução carregada.
        this.referenceCount = config.frames.desktop.count;

        this.cache = new Map();
        this.loading = new Set();
        this.dpr = Math.min(window.devicePixelRatio || 1, 2);
        this.stageW = 0;
        this.stageH = 0;

        this._lastDrawn = null;

        this._onResize = this._onResize.bind(this);
        window.addEventListener('resize', this._onResize, { passive: true });
        this._onResize();

        this.posterEl.src = this.poster;
        this.posterEl.onload = () => this.posterEl.classList.remove('is-loaded');

        this._preloadInitial();
    }

    _onResize() {
        const stillMobile = window.innerWidth <= this.config.mobileBreakpoint;
        if (stillMobile !== this.isMobile) {
            this.isMobile = stillMobile;
            this.frameSet = this.isMobile ? this.config.frames.mobile : this.config.frames.desktop;
            this.poster = this.isMobile ? this.config.posters.mobile : this.config.posters.desktop;
            this.cache.clear();
            this.posterEl.src = this.poster;
            this._preloadInitial();
        }
        const rect = this.canvas.parentElement.getBoundingClientRect();
        this.stageW = rect.width;
        this.stageH = rect.height;
        this.dpr = Math.min(window.devicePixelRatio || 1, 2);
        this.canvas.width = Math.round(this.stageW * this.dpr);
        this.canvas.height = Math.round(this.stageH * this.dpr);
        this.canvas.style.width = this.stageW + 'px';
        this.canvas.style.height = this.stageH + 'px';
        this.ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
        if (this._lastDrawn) this.draw(this._lastDrawn.index, this._lastDrawn.fx, this._lastDrawn.fy, this._lastDrawn.zoom);
    }

    _frameUrl(index) {
        const n = Math.min(Math.max(index, 1), this.frameSet.count);
        const padded = String(n).padStart(this.frameSet.pad, '0');
        return this.frameSet.path + padded + this.frameSet.ext;
    }

    _loadFrame(index) {
        const n = Math.min(Math.max(Math.round(index), 1), this.frameSet.count);
        if (this.cache.has(n) || this.loading.has(n)) return;
        this.loading.add(n);
        const img = new Image();
        img.decoding = 'async';
        img.onload = () => {
            this.cache.set(n, img);
            this.loading.delete(n);
            if (this._lastDrawn && Math.round(this._lastDrawn.index) === n) {
                this.draw(this._lastDrawn.index, this._lastDrawn.fx, this._lastDrawn.fy, this._lastDrawn.zoom);
            }
            if (!this._firstFramePainted) {
                this._firstFramePainted = true;
                this.posterEl.classList.add('is-loaded');
            }
        };
        img.onerror = () => { this.loading.delete(n); };
        img.src = this._frameUrl(n);
    }

    _preloadInitial() {
        // Carrega o primeiro quadro com prioridade, depois preenche o
        // restante progressivamente para não travar o carregamento inicial.
        this._loadFrame(1);
        this.preloadAround(1, 4);
        let i = 1;
        const step = () => {
            if (i > this.frameSet.count) return;
            this._loadFrame(i);
            i += 3; // preenchimento progressivo, não bloqueante
            if (i <= this.frameSet.count) requestIdleCallbackShim(step);
        };
        requestIdleCallbackShim(step);
    }

    preloadAround(index, radius) {
        for (let d = -radius; d <= radius; d++) {
            this._loadFrame(index + d);
        }
    }

    _nearestLoaded(index) {
        if (this.cache.has(index)) return this.cache.get(index);
        for (let d = 1; d < this.frameSet.count; d++) {
            if (this.cache.has(index - d)) return this.cache.get(index - d);
            if (this.cache.has(index + d)) return this.cache.get(index + d);
        }
        return null;
    }

    /**
     * @param {number} floatIndex índice de quadro (pode ter fração, usada
     *   para crossfade suave entre os dois quadros mais próximos).
     * @param {number} fx posição focal X em % (0-100)
     * @param {number} fy posição focal Y em % (0-100)
     * @param {number} zoom intensidade do zoom (1 = contido sem corte)
     */
    draw(floatIndexRef, fx = 50, fy = 50, zoom = 1) {
        this._lastDrawn = { index: floatIndexRef, fx, fy, zoom };

        // Com a câmera afastada (zoom baixo), a borda do quadro original
        // fica visível dentro da viewport, sempre sobre fundo vazio —
        // nesse caso, esmaece a borda (ver .is-framed em style.css).
        const framed = zoom < 0.9;
        this.canvas.classList.toggle('is-framed', framed);
        this.posterEl.classList.toggle('is-framed', framed);

        // floatIndexRef chega na numeração de referência (1–120, desktop);
        // reescala para o conjunto de quadros realmente ativo.
        const floatIndex = floatIndexRef * (this.frameSet.count / this.referenceCount);
        const clamped = Math.min(Math.max(floatIndex, 1), this.frameSet.count);
        const i0 = Math.floor(clamped);
        const i1 = Math.min(i0 + 1, this.frameSet.count);
        const t = clamped - i0;

        this.preloadAround(Math.round(clamped), 3);

        const img0 = this.cache.get(i0) || this._nearestLoaded(i0);
        if (!img0) return;
        this._refImg = img0;

        const ctx = this.ctx;
        ctx.clearRect(0, 0, this.stageW, this.stageH);

        this._drawImageFocused(img0, fx, fy, zoom, 1);

        if (t > 0.02) {
            const img1 = this.cache.get(i1);
            if (img1 && img1 !== img0) {
                this._drawImageFocused(img1, fx, fy, zoom, t);
            }
        }
    }

    /**
     * Converte uma coordenada no espaço da imagem original (xPercent/
     * yPercent, 0-100 — o mesmo sistema usado por focalX/focalY) para a
     * posição correspondente na tela, aplicando a mesma transformação de
     * zoom/foco usada no desenho. Usado por HotspotLayer para que os
     * marcadores acompanhem corretamente a câmera virtual.
     */
    project(xPercent, yPercent) {
        if (!this._refImg || !this._lastDrawn) return null;
        const img = this._refImg;
        const { fx, fy, zoom } = this._lastDrawn;
        const scale = Math.min(this.stageW / img.naturalWidth, this.stageH / img.naturalHeight) * zoom;
        const drawW = img.naturalWidth * scale;
        const drawH = img.naturalHeight * scale;
        const dx = this.stageW / 2 - (fx / 100) * drawW;
        const dy = this.stageH / 2 - (fy / 100) * drawH;
        const screenX = dx + (xPercent / 100) * drawW;
        const screenY = dy + (yPercent / 100) * drawH;
        return {
            leftPct: (screenX / this.stageW) * 100,
            topPct: (screenY / this.stageH) * 100,
        };
    }

    _drawImageFocused(img, fx, fy, zoom, alpha) {
        const ctx = this.ctx;
        const scale = Math.min(this.stageW / img.naturalWidth, this.stageH / img.naturalHeight) * zoom;
        const drawW = img.naturalWidth * scale;
        const drawH = img.naturalHeight * scale;
        const dx = this.stageW / 2 - (fx / 100) * drawW;
        const dy = this.stageH / 2 - (fy / 100) * drawH;
        ctx.save();
        ctx.globalAlpha = alpha;
        ctx.drawImage(img, dx, dy, drawW, drawH);
        ctx.restore();
    }
}

function requestIdleCallbackShim(fn) {
    if ('requestIdleCallback' in window) {
        window.requestIdleCallback(fn, { timeout: 500 });
    } else {
        setTimeout(fn, 60);
    }
}
