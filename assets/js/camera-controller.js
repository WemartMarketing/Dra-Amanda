/**
 * CameraController — converte o progresso global de scroll (0–1) em:
 *   - procedimento ativo e progresso local dentro dele
 *   - estado de câmera virtual interpolado (quadro, foco X/Y, zoom)
 *
 * A transição entre procedimentos interpola suavemente do estado de
 * câmera anterior para o novo ao longo dos primeiros 35% do segmento,
 * evitando cortes bruscos (seção 8 do briefing). O estado "clímax"
 * (harmonização global) varre um intervalo de quadros em vez de um
 * quadro fixo, revelando a rotação completa já capturada no material.
 */
class CameraController {
    constructor(procedures) {
        this.procedures = procedures;
        this._computeBoundaries();
    }

    _computeBoundaries() {
        const total = this.procedures.reduce((sum, p) => sum + p.scrollWeight, 0);
        let acc = 0;
        this.boundaries = this.procedures.map((p) => {
            const start = acc / total;
            acc += p.scrollWeight;
            const end = acc / total;
            return { start, end };
        });
    }

    _activeIndex(progress) {
        for (let i = 0; i < this.boundaries.length; i++) {
            const b = this.boundaries[i];
            if (progress < b.end || i === this.boundaries.length - 1) return i;
        }
        return this.boundaries.length - 1;
    }

    _endCameraOf(proc) {
        if (proc.camera.frameRange) {
            return {
                frame: proc.camera.frameRange[1],
                focalX: proc.camera.focalX,
                focalY: proc.camera.focalY,
                zoom: proc.camera.zoom,
            };
        }
        return proc.camera;
    }

    getState(progress) {
        const i = this._activeIndex(progress);
        const b = this.boundaries[i];
        const span = Math.max(b.end - b.start, 0.0001);
        const localT = Math.min(Math.max((progress - b.start) / span, 0), 1);
        const proc = this.procedures[i];
        const prevProc = this.procedures[i - 1] || proc;

        let frameIndex, fx, fy, zoom;

        if (proc.camera.frameRange) {
            const [a, z] = proc.camera.frameRange;
            frameIndex = lerp(a, z, localT);
            fx = proc.camera.focalX;
            fy = proc.camera.focalY;
            zoom = proc.camera.zoom;
        } else {
            const prevCam = this._endCameraOf(prevProc);
            const transitionT = Math.min(localT / 0.35, 1);
            const eased = easeInOutCubic(transitionT);
            frameIndex = lerp(prevCam.frame, proc.camera.frame, eased);
            fx = lerp(prevCam.focalX, proc.camera.focalX, eased);
            fy = lerp(prevCam.focalY, proc.camera.focalY, eased);
            zoom = lerp(prevCam.zoom, proc.camera.zoom, eased);
        }

        return { index: i, localT, frameIndex, fx, fy, zoom, procedure: proc };
    }
}

function lerp(a, b, t) { return a + (b - a) * t; }
function easeInOutCubic(t) { return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2; }
