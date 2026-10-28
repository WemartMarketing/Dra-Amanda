/**
 * HotspotLayer — desenha os marcadores dourados discretos e as linhas de
 * conexão para o procedimento ativo. Hotspots desaparecem (opacity 0)
 * em vez de serem removidos, para permitir transição suave entre
 * procedimentos (seção 10 do briefing).
 */
class HotspotLayer {
    constructor(container, jawGuideEl, profileGuideEl, facePlayer) {
        this.container = container;
        this.jawGuideEl = jawGuideEl;
        this.profileGuideEl = profileGuideEl;
        this.facePlayer = facePlayer;
        this.pool = [];
        this._currentProcedureId = null;
    }

    render(procedure) {
        const hotspots = procedure.hotspots || [];

        // Garante elementos suficientes no pool (reaproveitados entre procedimentos)
        while (this.pool.length < hotspots.length) {
            const dot = document.createElement('div');
            dot.className = 'hotspot';
            this.container.appendChild(dot);
            this.pool.push(dot);
        }

        this.pool.forEach((dot, i) => {
            const spot = hotspots[i];
            if (!spot) {
                dot.classList.remove('is-visible');
                dot.removeAttribute('data-hotspot-interactive');
                return;
            }
            // As coordenadas do hotspot estão no espaço da imagem original
            // (mesmo sistema de focalX/focalY); projeta para a tela
            // aplicando a câmera virtual atual, para que o marcador
            // acompanhe corretamente o zoom/deslocamento.
            const projected = this.facePlayer && this.facePlayer.project(spot.x, spot.y);
            const pos = projected || { leftPct: spot.x, topPct: spot.y };
            const outOfView = pos.leftPct < -2 || pos.leftPct > 102 || pos.topPct < -2 || pos.topPct > 102;
            if (outOfView) {
                // Ponto anatômico fora do enquadramento atual — o hotspot desaparece.
                dot.classList.remove('is-visible');
                dot.removeAttribute('data-hotspot-interactive');
                return;
            }
            dot.style.left = pos.leftPct + '%';
            dot.style.top = pos.topPct + '%';
            dot.title = spot.label || '';
            dot.classList.add('is-visible');
            if (procedure.interactiveDemo) {
                dot.setAttribute('data-hotspot-interactive', procedure.interactiveDemo);
                dot.onclick = () => {
                    document.dispatchEvent(new CustomEvent('aurea:hotspot-interact', {
                        detail: { demoId: procedure.interactiveDemo, procedureId: procedure.id },
                    }));
                };
            } else {
                dot.removeAttribute('data-hotspot-interactive');
                dot.onclick = null;
            }
        });

        this.jawGuideEl.classList.toggle('is-visible', !!procedure.jawGuideLine);
        this.profileGuideEl.classList.toggle('is-visible', !!procedure.profileGuideLine);

        this._currentProcedureId = procedure.id;
    }
}
