/**
 * main.js — bootstrap da experiência AURÉA.
 * Lê os dados dos procedimentos (embutidos pelo PHP em um <script
 * type="application/json">) e inicializa os controladores.
 */
(function () {
    const dataEl = document.getElementById('procedures-data');
    const procedures = JSON.parse(dataEl.textContent);

    const canvas = document.getElementById('face-canvas');
    const posterEl = document.getElementById('face-poster');
    const hotspotLayerEl = document.getElementById('hotspot-layer');
    const jawGuideEl = document.getElementById('jaw-guide');
    const profileGuideEl = document.getElementById('profile-guide');
    const textLayer = document.getElementById('text-layer');
    const progressIndicator = document.getElementById('progress-indicator');
    const scrollCueEl = document.getElementById('scroll-cue');
    const wrapperEl = document.getElementById('experience-wrapper');

    // Linhas-guia discretas (aproximação estilizada; não são marcação
    // clínica). Injetadas uma única vez; a visibilidade é controlada via
    // classe `is-visible` por HotspotLayer conforme o procedimento ativo.
    jawGuideEl.innerHTML = '<svg viewBox="0 0 100 100" preserveAspectRatio="none">' +
        '<path d="M 38 55 Q 50 78 66 66" /></svg>';
    profileGuideEl.innerHTML = '<svg viewBox="0 0 100 100" preserveAspectRatio="none">' +
        '<path d="M 62 25 Q 70 33 69 40 Q 64 48 61 54 Q 59 61 56 68" /></svg>';

    const facePlayer = new FacePlayer(canvas, posterEl, window.AUREA_CONFIG);
    const cameraController = new CameraController(procedures);
    const hotspotLayer = new HotspotLayer(hotspotLayerEl, jawGuideEl, profileGuideEl, facePlayer);
    const procedureController = new ProcedureController(textLayer, progressIndicator);

    new ScrollController({
        wrapperEl,
        cameraController,
        facePlayer,
        procedureController,
        hotspotLayer,
        scrollCueEl,
        progressDots: Array.from(progressIndicator.querySelectorAll('[data-progress-dot]')),
    });
})();
