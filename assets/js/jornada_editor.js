/* Editor visual das Jornadas Inteligentes.
 * O estado (definição da jornada) vive em `def`; a tela é redesenhada a cada
 * mudança de estrutura. Validação e simulação acontecem no servidor
 * (api/jornadas.php) — este arquivo só desenha e coleta. Todo texto do
 * usuário entra como textContent/value (nunca HTML). */
(function () {
    'use strict';
    var raiz = document.getElementById('jornada-editor');
    if (!raiz) return;
    var D = JSON.parse(document.getElementById('je-dados').textContent);
    var def = D.def;
    var leitura = raiz.dataset.somenteLeitura === '1';
    def.gatilho_config = Array.isArray(def.gatilho_config) ? {} : (def.gatilho_config || {});
    def.passos.forEach(function (p) { p.config = Array.isArray(p.config) ? {} : (p.config || {}); });
    var $ = function (id) { return document.getElementById(id); };

    function el(tag, attrs, filhos) {
        var n = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'text') n.textContent = attrs[k];
            else if (k === 'class') n.className = attrs[k];
            else if (k.indexOf('on') === 0) n.addEventListener(k.slice(2), attrs[k]);
            else if (attrs[k] === true) n.setAttribute(k, '');
            else if (attrs[k] !== false && attrs[k] != null) n.setAttribute(k, attrs[k]);
        });
        (filhos || []).forEach(function (f) { if (f) n.appendChild(typeof f === 'string' ? document.createTextNode(f) : f); });
        return n;
    }
    function opcoes(select, lista, atual) {
        select.innerHTML = '';
        lista.forEach(function (o) {
            var op = el('option', { value: o[0], text: o[1] });
            if (String(o[0]) === String(atual == null ? '' : atual)) op.selected = true;
            select.appendChild(op);
        });
    }
    function novaChave(tipo) {
        var base = tipo.split('_')[0].slice(0, 10), n = 1;
        var usadas = def.passos.map(function (p) { return p.chave; });
        while (usadas.indexOf(base + '_' + n) !== -1) n++;
        return base + '_' + n;
    }
    function tituloDe(p) { return p.titulo || (D.tipos[p.tipo] ? D.tipos[p.tipo][0] : p.tipo); }

    // ------------------------------------------------------------- cabeçalho
    $('je-nome').value = def.nome || '';
    $('je-descricao').value = def.descricao || '';
    opcoes($('je-gatilho'), Object.keys(D.gatilhos).map(function (k) { return [k, D.gatilhos[k]]; }), def.gatilho);
    opcoes($('je-emp'), [['', 'Todos os empreendimentos']].concat(D.empreendimentos.map(function (e) { return [e.id, e.nome]; })), def.empreendimento_id);
    opcoes($('je-etapa-gatilho'), [['', 'Escolha a etapa…']].concat(D.etapas.map(function (e) { return [e.id, e.nome]; })), def.gatilho_config.etapa_id);
    $('je-max').value = def.max_mensagens || 2;
    $('je-parar').checked = !!def.gatilho_config.parar_quando_responder;
    function chips(caixa, mapa, selecionados, nome) {
        caixa.innerHTML = '';
        Object.keys(mapa).forEach(function (k) {
            var cb = el('input', { type: 'checkbox', value: k, 'data-grupo': nome });
            cb.checked = (selecionados || []).indexOf(k) !== -1;
            caixa.appendChild(el('label', { class: 'dia-chip' }, [cb, el('span', { text: mapa[k] })]));
        });
    }
    chips($('je-origens'), D.origens, def.gatilho_config.origens, 'origens');
    chips($('je-categorias'), D.categorias, def.gatilho_config.somente_categorias, 'categorias');
    function mostrarGatilho() {
        var g = $('je-gatilho').value;
        $('je-origens-box').hidden = g !== 'novo_lead';
        $('je-etapa-box').hidden = g !== 'mudanca_etapa';
    }
    $('je-gatilho').addEventListener('change', mostrarGatilho);
    mostrarGatilho();

    function coletarCabecalho() {
        def.nome = $('je-nome').value.trim();
        def.descricao = $('je-descricao').value.trim();
        def.gatilho = $('je-gatilho').value;
        def.empreendimento_id = $('je-emp').value ? Number($('je-emp').value) : null;
        def.max_mensagens = Number($('je-max').value || 2);
        var marcados = function (g) { return Array.prototype.slice.call(raiz.querySelectorAll('input[data-grupo="' + g + '"]:checked')).map(function (c) { return c.value; }); };
        def.gatilho_config = { parar_quando_responder: $('je-parar').checked, origens: marcados('origens'), somente_categorias: marcados('categorias') };
        if ($('je-etapa-gatilho').value) def.gatilho_config.etapa_id = Number($('je-etapa-gatilho').value);
    }

    // ------------------------------------------------------------- blocos
    function destinoSelect(p, campo, rotulo, permitirAbaixo) {
        var s = el('select', { 'aria-label': rotulo });
        var lista = permitirAbaixo ? [['', 'O bloco de baixo']] : [['', 'Escolha…']];
        def.passos.forEach(function (o, i) { if (o.chave !== p.chave) lista.push([o.chave, (i + 1) + '. ' + tituloDe(o)]); });
        opcoes(s, lista, p[campo]);
        s.addEventListener('change', function () { p[campo] = s.value || null; });
        return el('div', { class: 'field je-destino' }, [el('label', { text: rotulo }), s]);
    }
    function campo(rotulo, input) { return el('div', { class: 'field' }, [el('label', { text: rotulo }), input]); }
    function caixa(rotulo, checked, fn) {
        var cb = el('input', { type: 'checkbox' });
        cb.checked = !!checked;
        cb.addEventListener('change', function () { fn(cb.checked); });
        return el('label', { class: 'checkbox-row' }, [cb, ' ' + rotulo]);
    }

    function camposDoTipo(p) {
        var c = p.config, f = [];
        switch (p.tipo) {
            case 'enviar_template':
                var s = el('select');
                opcoes(s, [['', '— nenhum (passo pulado) —']].concat(D.templates.map(function (t) { return [t.id, t.nome + (t.categoria === 'marketing' ? ' (marketing)' : '')]; })), c.template_id);
                s.addEventListener('change', function () { c.template_id = s.value ? Number(s.value) : null; atualizarPrevia(); });
                var previa = el('p', { class: 'je-previa' });
                function atualizarPrevia() {
                    var t = D.templates.filter(function (x) { return String(x.id) === String(c.template_id); })[0];
                    previa.textContent = t ? '“' + (t.corpo || '') + '”' : '';
                    previa.hidden = !t;
                }
                atualizarPrevia();
                f.push(campo('Template aprovado', s), previa);
                f.push(caixa('Usar a mensagem de boas-vindas do empreendimento, quando houver', c.template === 'do_empreendimento', function (v) { c.template = v ? 'do_empreendimento' : 'fixo'; }));
                f.push(caixa('Pode sair a qualquer hora (só para boas-vindas logo após o cadastro)', c.ignorar_horario, function (v) { c.ignorar_horario = v; }));
                var sb = el('select');
                opcoes(sb, [['seguir', 'Seguir para o próximo bloco'], ['encerrar', 'Encerrar a jornada']], c.se_bloqueado || 'seguir');
                sb.addEventListener('change', function () { c.se_bloqueado = sb.value; });
                f.push(campo('Se a mensagem não puder sair (sem autorização, limite…)', sb));
                break;
            case 'enviar_documento':
                f.push(el('p', { class: 'muted', text: 'Envia o book em PDF do empreendimento do lead. Sem book cadastrado, nada é enviado.' }));
                break;
            case 'esperar':
                var modo = c.horas_uteis != null ? 'horas_uteis' : 'horas';
                var n = el('input', { type: 'number', min: '0.25', max: '720', step: '0.25', value: c[modo] != null ? c[modo] : 24 });
                var u = el('select');
                opcoes(u, [['horas', 'horas corridas'], ['horas_uteis', 'horas úteis (só no horário de envio)']], modo);
                var sync = function () { delete c.horas; delete c.horas_uteis; delete c.minutos; c[u.value] = Number(String(n.value).replace(',', '.')); };
                n.addEventListener('input', sync); u.addEventListener('change', sync); sync();
                f.push(el('div', { class: 'je-linha' }, [campo('Esperar', n), campo('Contando', u)]));
                break;
            case 'condicao':
                c.se = c.se || [];
                var box = el('div', { class: 'je-regras' });
                Object.keys(D.regras).forEach(function (r) {
                    box.appendChild(caixa(D.regras[r], c.se.indexOf(r) !== -1, function (v) {
                        c.se = c.se.filter(function (x) { return x !== r; }); if (v) c.se.push(r);
                    }));
                });
                f.push(el('span', { class: 'label-like', text: 'Se pelo menos uma destas for verdade:' }), box);
                f.push(el('div', { class: 'je-linha' }, [destinoSelect(p, 'proximo_chave', 'Se SIM, vai para', false), destinoSelect(p, 'senao_chave', 'Se NÃO, vai para', false)]));
                break;
            case 'confirmar_atendimento':
                f.push(el('p', { class: 'muted', text: 'O corretor vê na central de pendências: “O cliente respondeu à sua tentativa de contato?” — sem pop-up.' }));
                f.push(caixa('Só perguntar se o corretor registrou tentativa de contato', c.so_se_tentativa, function (v) { c.so_se_tentativa = v; }));
                break;
            case 'criar_tarefa':
            case 'notificar_corretor':
                var t = el('input', { type: 'text', maxlength: '190', value: c.titulo || '', placeholder: 'Ex.: Ligar para o cliente hoje' });
                t.addEventListener('input', function () { c.titulo = t.value; });
                f.push(campo('Texto para o corretor', t));
                break;
            case 'atualizar_etapa':
                var se = el('select');
                opcoes(se, [['', 'Escolha a etapa…']].concat(D.etapas.filter(function (e) { return !e.encerrada; }).map(function (e) { return [e.id, e.nome]; })), c.etapa_id);
                se.addEventListener('change', function () { c.etapa_id = se.value ? Number(se.value) : null; });
                f.push(campo('Mover o lead para', se), el('p', { class: 'muted', text: 'A automação nunca encerra um lead (sem interesse, contrato, perdido): isso é decisão do corretor.' }));
                break;
            case 'encaminhar_fila':
                f.push(el('p', { class: 'muted', text: 'Se o lead ainda não tem corretor, vai para a roleta. Sem corretor disponível, espera na fila — ninguém indisponível recebe.' }));
                break;
            case 'encerrar':
                var m = el('input', { type: 'text', maxlength: '120', value: c.motivo || '', placeholder: 'Opcional: motivo que aparece no histórico' });
                m.addEventListener('input', function () { c.motivo = m.value; });
                f.push(campo('Motivo', m));
                break;
        }
        if (p.tipo !== 'condicao' && p.tipo !== 'encerrar') f.push(destinoSelect(p, 'proximo_chave', 'Depois, vai para', true));
        return f;
    }

    function paleta(posicao) {
        var p = el('div', { class: 'je-paleta' });
        Object.keys(D.tipos).forEach(function (tipo) {
            p.appendChild(el('button', { type: 'button', class: 'je-paleta-item je-t-' + tipo, onclick: function () {
                var novo = { chave: novaChave(tipo), tipo: tipo, titulo: D.tipos[tipo][0], config: {}, proximo_chave: null, senao_chave: null };
                if (tipo === 'esperar') novo.config = { horas: 24 };
                if (tipo === 'enviar_template') novo.config = { template: 'fixo', template_id: null, ignorar_horario: false, se_bloqueado: 'seguir' };
                if (tipo === 'confirmar_atendimento') novo.config = { so_se_tentativa: true };
                if (tipo === 'condicao') novo.config = { se: ['cliente_respondeu'] };
                def.passos.splice(posicao, 0, novo);
                desenhar();
            } }, [el('b', { text: D.tipos[tipo][0] }), el('small', { text: D.tipos[tipo][1] })]));
        });
        return p;
    }
    function adicionar(posicao) {
        var wrap = el('div', { class: 'je-add' });
        var btn = el('button', { type: 'button', class: 'je-add-btn', onclick: function () {
            var aberta = wrap.querySelector('.je-paleta');
            if (aberta) { aberta.remove(); return; }
            wrap.appendChild(paleta(posicao));
        } }, ['+ Adicionar bloco aqui']);
        wrap.appendChild(btn);
        return wrap;
    }

    function desenhar() {
        var fluxo = $('je-fluxo');
        fluxo.innerHTML = '';
        fluxo.appendChild(el('div', { class: 'je-bloco je-gatilho' }, [el('span', { class: 'jf-tipo', text: 'Gatilho' }), el('b', { text: D.gatilhos[$('je-gatilho').value] || 'Gatilho' })]));
        if (!leitura) fluxo.appendChild(adicionar(0));
        def.passos.forEach(function (p, i) {
            var titulo = el('input', { type: 'text', class: 'je-titulo', value: p.titulo || '', maxlength: '120', 'aria-label': 'Nome do bloco ' + (i + 1) });
            titulo.addEventListener('input', function () { p.titulo = titulo.value; });
            titulo.addEventListener('change', desenhar);
            var acoes = el('div', { class: 'je-acoes' }, leitura ? [] : [
                el('button', { type: 'button', title: 'Subir', 'aria-label': 'Subir bloco', disabled: i === 0, onclick: function () { def.passos.splice(i - 1, 0, def.passos.splice(i, 1)[0]); desenhar(); } }, ['↑']),
                el('button', { type: 'button', title: 'Descer', 'aria-label': 'Descer bloco', disabled: i === def.passos.length - 1, onclick: function () { def.passos.splice(i + 1, 0, def.passos.splice(i, 1)[0]); desenhar(); } }, ['↓']),
                el('button', { type: 'button', class: 'je-remover', title: 'Remover', 'aria-label': 'Remover bloco', onclick: function () {
                    if (!confirm('Remover o bloco "' + tituloDe(p) + '"?')) return;
                    def.passos.splice(i, 1);
                    def.passos.forEach(function (o) { if (o.proximo_chave === p.chave) o.proximo_chave = null; if (o.senao_chave === p.chave) o.senao_chave = null; });
                    desenhar();
                } }, ['✕'])
            ]);
            var cab = el('div', { class: 'je-bloco-cab' }, [el('span', { class: 'je-num', text: String(i + 1) }), el('span', { class: 'jf-tipo', text: D.tipos[p.tipo] ? D.tipos[p.tipo][0] : p.tipo }), acoes]);
            var corpo = el('div', { class: 'je-bloco-corpo' }, [titulo].concat(camposDoTipo(p)));
            fluxo.appendChild(el('div', { class: 'je-bloco je-t-' + p.tipo, 'data-chave': p.chave }, [cab, corpo]));
            if (!leitura) fluxo.appendChild(adicionar(i + 1));
        });
        if (leitura) fluxo.querySelectorAll('input, select, button').forEach(function (x) { x.disabled = true; });
    }
    desenhar();
    if (leitura) raiz.querySelectorAll('.je-principal input, .je-principal select, #je-btn-salvar').forEach(function (x) { x.disabled = true; });

    // ------------------------------------------------------------- servidor
    async function enviar(acao, extra) {
        coletarCabecalho();
        var fd = new FormData();
        fd.append('acao', acao);
        fd.append('csrf_token', csrfToken());
        fd.append('definicao', JSON.stringify(def));
        if (def.id) fd.append('id', def.id);
        Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
        try {
            var r = await fetch('/api/jornadas.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            return await r.json();
        } catch (e) { return { ok: false, erro: 'Sem conexão. Tente de novo.' }; }
    }
    function mostrarLista(caixaEl, itens, classe) {
        caixaEl.innerHTML = '';
        if (!itens || !itens.length) { caixaEl.hidden = true; return; }
        var ul = el('ul', { class: classe });
        itens.forEach(function (t) { ul.appendChild(el('li', { text: t })); });
        caixaEl.appendChild(ul);
        caixaEl.hidden = false;
    }

    $('je-btn-salvar').addEventListener('click', async function () {
        var b = this; b.disabled = true;
        var j = await enviar('salvar');
        b.disabled = false;
        if (!j.ok) {
            mostrarLista($('je-erros'), j.erros || [j.erro], 'je-lista-erros');
            $('je-erros').scrollIntoView({ block: 'center', behavior: 'smooth' });
            return;
        }
        $('je-erros').hidden = true;
        mostrarLista($('je-avisos'), j.avisos, 'je-lista-avisos');
        if (!def.id) {
            try { sessionStorage.setItem('wm-toast', j.mensagem); } catch (e) { /* sem storage */ }
            location.href = '/pages/jornada_editor.php?id=' + encodeURIComponent(j.id);
            return;
        }
        wmToast(j.mensagem, true);
    });

    $('je-btn-simular').addEventListener('click', async function () {
        var b = this; b.disabled = true;
        var cen = {
            consentimento: $('sim-consent').value, cliente_responde: $('sim-responde').value,
            tem_corretor: $('sim-corretor').checked, corretor_tenta: $('sim-tenta').checked, corretor_confirma: $('sim-confirma').checked,
            empreendimento_tem_template: $('sim-emp-tpl').checked, tem_book: $('sim-book').checked
        };
        var j = await enviar('simular', { cenario: JSON.stringify(cen) });
        b.disabled = false;
        var out = $('je-sim-resultado');
        out.innerHTML = '';
        out.hidden = false;
        if (!j.ok) {
            out.appendChild(el('p', { class: 'je-sim-erro', text: 'Corrija antes de simular:' }));
            var ul = el('ul', { class: 'je-lista-erros' });
            (j.erros || [j.erro]).forEach(function (t) { ul.appendChild(el('li', { text: t })); });
            out.appendChild(ul);
            return;
        }
        var ol = el('ol', { class: 'je-timeline' });
        j.linhas.forEach(function (l) {
            ol.appendChild(el('li', { class: 'tl-' + l.classe }, [el('span', { class: 'tl-quando', text: l.quando }), el('b', { text: l.bloco }), el('span', { text: l.texto })]));
        });
        out.appendChild(el('p', { class: 'je-sim-resumo', text: j.resumo }));
        out.appendChild(ol);
        if (j.avisos && j.avisos.length) {
            var av = el('ul', { class: 'je-lista-avisos' });
            j.avisos.forEach(function (t) { av.appendChild(el('li', { text: t })); });
            out.appendChild(av);
        }
    });
})();
