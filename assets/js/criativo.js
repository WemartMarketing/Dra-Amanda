/* "Ver criativo": janela com o anúncio que gerou o lead, sem sair do Kanban
 * (botão [data-criativo-lead]) e ampliação da prévia na ficha
 * ([data-criativo-ampliar]). Tudo vindo do servidor entra como textContent;
 * imagem só do nosso endpoint e link só https (já validado no servidor). */
(function () {
    'use strict';
    var overlay = null;

    function el(tag, cls, texto) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (texto != null) n.textContent = texto;
        return n;
    }
    function fechar() { if (overlay) { overlay.hidden = true; overlay.querySelector('.criativo-modal-corpo').innerHTML = ''; } }
    function abrirBase() {
        if (!overlay) {
            overlay = el('div', 'modal-overlay criativo-overlay');
            overlay.hidden = true;
            var box = el('div', 'modal-box criativo-modal');
            box.setAttribute('role', 'dialog');
            box.setAttribute('aria-modal', 'true');
            box.setAttribute('aria-label', 'Anúncio que gerou este lead');
            var x = el('button', 'overlay-close', '×');
            x.type = 'button';
            x.setAttribute('aria-label', 'Fechar');
            x.addEventListener('click', fechar);
            box.appendChild(x);
            box.appendChild(el('div', 'criativo-modal-corpo'));
            overlay.appendChild(box);
            overlay.addEventListener('click', function (e) { if (e.target === overlay) fechar(); });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape') fechar(); });
            document.body.appendChild(overlay);
        }
        overlay.hidden = false;
        var corpo = overlay.querySelector('.criativo-modal-corpo');
        corpo.innerHTML = '';
        return corpo;
    }
    function imagemSegura(src) { return typeof src === 'string' && src.indexOf('/api/criativo_midia.php?') === 0; }
    function linkSeguro(u) { return typeof u === 'string' && /^https:\/\/([a-z0-9-]+\.)*(facebook\.com|fb\.me|fb\.com|instagram\.com)\//i.test(u); }

    function desenhar(corpo, c, leadId) {
        corpo.appendChild(el('div', 'eyebrow', 'Anúncio que gerou este lead'));
        if (imagemSegura(c.imagem)) {
            var fig = el('div', 'criativo-modal-midia');
            var img = el('img');
            img.src = c.imagem;
            img.alt = 'Criativo do anúncio';
            fig.appendChild(img);
            if (c.midia_tipo === 'video') fig.appendChild(el('span', 'criativo-tag', 'Vídeo — assista no link oficial'));
            corpo.appendChild(fig);
        } else {
            corpo.appendChild(el('div', 'criativo-vazio', 'Criativo indisponível'));
        }
        var dl = el('dl', 'criativo-dados');
        [['Campanha', c.campanha], ['Conjunto de anúncios', c.conjunto], ['Anúncio', c.anuncio || (c.tem_anuncio ? 'Aguardando dados da Meta' : 'Cadastro sem anúncio (orgânico)')],
         ['Plataforma', c.plataforma], ['Cadastro', c.cadastro_em ? new Date(c.cadastro_em.replace(' ', 'T')).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : null]]
            .forEach(function (p) { var d = el('div'); d.appendChild(el('dt', null, p[0])); d.appendChild(el('dd', null, p[1] || '—')); dl.appendChild(d); });
        corpo.appendChild(dl);
        if (c.titulo) corpo.appendChild(el('p', 'criativo-titulo-anuncio', c.titulo));
        if (c.texto) corpo.appendChild(el('p', 'criativo-texto', c.texto.length > 600 ? c.texto.slice(0, 600) + '…' : c.texto));
        (c.avisos || []).forEach(function (a) { corpo.appendChild(el('div', 'rule-box', a)); });
        var acoes = el('div', 'criativo-acoes');
        if (linkSeguro(c.link_oficial)) {
            var a = el('a', 'btn btn-primary', c.link_rotulo || 'Ver anúncio original');
            a.href = c.link_oficial;
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            acoes.appendChild(a);
        }
        if (leadId) {
            var f = el('a', 'btn btn-outline', 'Abrir ficha do lead');
            f.href = '/pages/lead.php?id=' + encodeURIComponent(leadId);
            acoes.appendChild(f);
        }
        corpo.appendChild(acoes);
    }

    document.addEventListener('click', async function (e) {
        var btn = e.target.closest('[data-criativo-lead]');
        if (btn) {
            e.preventDefault();
            var corpo = abrirBase();
            corpo.appendChild(el('p', 'muted', 'Carregando o anúncio…'));
            var j;
            try {
                var r = await fetch('/api/lead_criativo.php?lead_id=' + encodeURIComponent(btn.dataset.criativoLead), { credentials: 'same-origin' });
                j = await r.json();
            } catch (err) { j = { ok: false, erro: 'Sem conexão. Tente de novo.' }; }
            corpo.innerHTML = '';
            if (!j.ok) { corpo.appendChild(el('p', 'form-alert', j.erro || 'Não foi possível carregar.')); return; }
            if (!j.tem_origem) { corpo.appendChild(el('p', 'muted', 'Este lead não veio de um anúncio do Meta Ads.')); return; }
            desenhar(corpo, j, btn.dataset.criativoLead);
            return;
        }
        var amp = e.target.closest('[data-criativo-ampliar]');
        if (amp) {
            var card = amp.closest('[data-criativo-dados]');
            var dados = {};
            try { dados = JSON.parse(card.dataset.criativoDados); } catch (err) { /* nada */ }
            desenhar(abrirBase(), dados, null);
        }
    });
})();
