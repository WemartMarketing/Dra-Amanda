/* Central de Relatórios: dica do gráfico de linha (mouse, toque e teclado). */
(function () {
    'use strict';
    document.querySelectorAll('[data-rel-linha]').forEach(function (fig) {
        var pts;
        try { pts = JSON.parse(fig.dataset.pontos || '[]'); } catch (e) { return; }
        if (!pts.length) return;
        var svg = fig.querySelector('svg');
        var alvo = fig.querySelector('.rel-alvo');
        var mira = fig.querySelector('.rel-mira');
        var marca = fig.querySelector('.rel-marca');
        var dica = fig.querySelector('.rel-dica');
        var traco = fig.querySelector('.rel-traco').getAttribute('points').trim().split(/\s+/).map(function (p) { return p.split(',').map(Number); });
        var unidade = fig.dataset.unidade || '';
        var atual = -1;
        function mostrar(i) {
            if (i < 0 || i >= pts.length) return;
            atual = i;
            var x = traco[i][0], y = traco[i][1];
            mira.setAttribute('x1', x); mira.setAttribute('x2', x); mira.removeAttribute('hidden');
            var vb = svg.viewBox.baseVal;
            var px = x / vb.width * svg.clientWidth;
            marca.style.left = px + 'px'; marca.style.top = (y / vb.height * svg.clientHeight) + 'px'; marca.hidden = false;
            dica.textContent = '';
            var b = document.createElement('b');
            b.textContent = Number(pts[i][1]).toLocaleString('pt-BR') + ' ' + unidade;
            var s = document.createElement('span');
            s.textContent = pts[i][0];
            dica.appendChild(b); dica.appendChild(s);
            dica.hidden = false;
            var meia = dica.offsetWidth / 2;
            dica.style.left = Math.min(Math.max(px, meia), svg.clientWidth - meia) + 'px';
        }
        function esconder() { mira.setAttribute('hidden', ''); marca.hidden = true; dica.hidden = true; }
        function maisPerto(ev) {
            var r = svg.getBoundingClientRect();
            var x = (ev.clientX - r.left) / r.width * svg.viewBox.baseVal.width;
            var melhor = 0, d = Infinity;
            traco.forEach(function (p, i) { var dd = Math.abs(p[0] - x); if (dd < d) { d = dd; melhor = i; } });
            return melhor;
        }
        alvo.addEventListener('pointermove', function (ev) { mostrar(maisPerto(ev)); });
        alvo.addEventListener('pointerdown', function (ev) { mostrar(maisPerto(ev)); });
        alvo.addEventListener('pointerleave', function (ev) { if (ev.pointerType === 'mouse') esconder(); });
        alvo.addEventListener('focus', function () { mostrar(atual < 0 ? pts.length - 1 : atual); });
        alvo.addEventListener('blur', esconder);
        alvo.addEventListener('keydown', function (ev) {
            if (ev.key === 'ArrowRight') { mostrar(Math.min(pts.length - 1, atual + 1)); ev.preventDefault(); }
            if (ev.key === 'ArrowLeft') { mostrar(Math.max(0, atual - 1)); ev.preventDefault(); }
        });
    });
})();

/* Exportar: o aviso da LGPD aparece (e é obrigatório) só na exportação de dados; PDF não serve para dados. */
(function () {
    'use strict';
    document.querySelectorAll('form[data-rel-exportar]').forEach(function (form) {
        var aviso = form.querySelector('[data-lgpd-dados]');
        var ciente = form.querySelector('input[name="ciente_lgpd"]');
        var pdf = form.querySelector('input[name="formato"][value="pdf"]');
        function atualizar() {
            var nivel = (form.querySelector('input[name="nivel"]:checked') || {}).value;
            var dados = nivel === 'dados';
            aviso.hidden = !dados;
            ciente.required = dados;
            if (pdf) {
                pdf.disabled = dados;
                if (dados && pdf.checked) form.querySelector('input[name="formato"][value="xlsx"]').checked = true;
            }
        }
        form.addEventListener('change', atualizar);
        atualizar();
    });
})();
