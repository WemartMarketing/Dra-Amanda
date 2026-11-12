/* Nova campanha: monta os filtros, mostra a prévia (quem recebe, quem fica
 * de fora e por quê, custo, limites) e cria a campanha em rascunho.
 * Textos vindos do servidor entram como textContent. */
(function () {
    'use strict';
    var raiz = document.getElementById('camp-nova');
    if (!raiz) return;
    var $ = function (id) { return document.getElementById(id); };
    var lista = [];
    var previaFeita = false;

    function filtros() {
        var f = { lista: lista };
        raiz.querySelectorAll('input[data-filtro]:checked').forEach(function (cb) {
            (f[cb.dataset.filtro] = f[cb.dataset.filtro] || []).push(cb.value);
        });
        raiz.querySelectorAll('[data-filtro-valor]').forEach(function (i) { if (i.value !== '') f[i.dataset.filtroValor] = i.value; });
        return f;
    }
    async function postar(fd) {
        fd.append('csrf_token', csrfToken());
        try {
            var r = await fetch('/api/campanhas.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            return await r.json();
        } catch (e) { return { ok: false, erro: 'Sem conexão. Tente de novo.' }; }
    }
    function invalidar() { previaFeita = false; $('camp-criar-btn').disabled = true; }
    raiz.addEventListener('change', function (e) { if (e.target.id !== 'camp-agendar' && e.target.id !== 'camp-nome') invalidar(); });

    $('camp-template').addEventListener('change', function () {
        var op = this.selectedOptions[0];
        $('camp-corpo').textContent = op && op.dataset.corpo ? '“' + op.dataset.corpo + '”' : '';
        $('camp-corpo').hidden = !(op && op.dataset.corpo);
    });

    $('camp-planilha').addEventListener('change', async function () {
        var info = $('camp-planilha-info');
        if (!this.files[0]) { lista = []; info.textContent = ''; return; }
        info.textContent = 'Lendo a planilha…';
        var fd = new FormData();
        fd.append('acao', 'lista');
        fd.append('arquivo', this.files[0]);
        var j = await postar(fd);
        if (!j.ok) { lista = []; info.textContent = j.erro || 'Não foi possível ler a planilha.'; return; }
        lista = j.telefones;
        info.textContent = j.validos + ' telefone(s) válido(s)' + (j.repetidos ? ', ' + j.repetidos + ' repetido(s) ignorado(s)' : '') + (j.invalidos ? ', ' + j.invalidos + ' inválido(s)' : '') + '.';
        invalidar();
    });

    $('camp-previa-btn').addEventListener('click', async function () {
        var b = this; b.disabled = true;
        var fd = new FormData();
        fd.append('acao', 'previa');
        fd.append('template_id', $('camp-template').value);
        fd.append('filtros', JSON.stringify(filtros()));
        var j = await postar(fd);
        b.disabled = false;
        var box = $('camp-previa');
        box.hidden = false;
        $('pv-elegiveis').textContent = j.elegiveis != null ? j.elegiveis : '—';
        $('pv-total').textContent = j.total != null ? j.total + ' lead(s) encontrados com esses filtros' + (j.lista_sem_lead ? '; ' + j.lista_sem_lead + ' telefone(s) da planilha não são leads da base (ficam de fora)' : '') + '.' : '';
        var ex = $('pv-excluidos'); ex.innerHTML = '';
        (j.excluidos || []).forEach(function (x) {
            var li = document.createElement('li'); li.className = 'is-no';
            li.textContent = x.quantidade + ' — ' + x.motivo; ex.appendChild(li);
        });
        $('pv-custo').textContent = j.custo_estimado != null ? 'R$ ' + Number(j.custo_estimado).toFixed(2).replace('.', ',') + (j.modo_teste ? ' (no teste: R$ 0,00)' : '') : 'Tarifa não cadastrada';
        var li = $('pv-limites'); li.innerHTML = '';
        (j.limites || []).forEach(function (t) { var x = document.createElement('li'); x.textContent = t; li.appendChild(x); });
        $('pv-amostra').textContent = j.amostra && j.amostra.length ? 'Exemplos: ' + j.amostra.join(', ') + (j.elegiveis > j.amostra.length ? '…' : '') : '';
        var erro = $('camp-erro');
        erro.hidden = j.ok; erro.textContent = (j.erros || []).join(' ') || j.erro || '';
        previaFeita = j.ok && j.elegiveis > 0;
        $('camp-criar-btn').disabled = !previaFeita;
        if (j.ok && !j.elegiveis) { erro.hidden = false; erro.textContent = 'Ninguém com autorização nesses filtros: nada a enviar.'; }
        box.scrollIntoView({ block: 'start', behavior: 'smooth' });
    });

    $('camp-criar-btn').disabled = true;
    $('camp-criar-btn').addEventListener('click', async function () {
        if (!previaFeita) return;
        var b = this; b.disabled = true;
        var fd = new FormData();
        fd.append('acao', 'criar');
        fd.append('nome', $('camp-nome').value);
        fd.append('template_id', $('camp-template').value);
        fd.append('filtros', JSON.stringify(filtros()));
        fd.append('agendada_para', $('camp-agendar').value);
        var j = await postar(fd);
        b.disabled = false;
        if (!j.ok) { $('camp-erro').hidden = false; $('camp-erro').textContent = j.erro; return; }
        try { sessionStorage.setItem('wm-toast', j.mensagem); } catch (e) { /* sem storage */ }
        location.href = '/pages/campanhas.php?id=' + encodeURIComponent(j.id);
    });
})();
