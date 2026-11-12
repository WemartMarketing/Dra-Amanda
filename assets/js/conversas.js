/* Central de conversas do WhatsApp — lista, conversa, envio e atualização periódica.
 * Sem WebSocket: enquanto a aba está visível, busca novidades a cada poucos segundos.
 * Todo texto vindo do cliente entra como textContent (nunca HTML). */
(function () {
    'use strict';
    var raiz = document.getElementById('chat');
    if (!raiz) return;

    var API = '/api/conversas.php';
    var estado = { selecionada: Number(raiz.dataset.selecionada) || 0, filtro: 'todas', q: '', ultimoId: 0, conversa: null, enviando: false };
    var el = function (id) { return document.getElementById(id); };
    var lista = el('chat-itens'), msgs = el('chat-msgs'), rodape = el('chat-rodape'), info = el('chat-info');

    function csrf() { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; }
    async function api(acao, dados, arquivo) {
        var fd = new FormData();
        fd.append('acao', acao);
        fd.append('csrf_token', csrf());
        Object.keys(dados || {}).forEach(function (k) { fd.append(k, dados[k]); });
        if (arquivo) fd.append('arquivo', arquivo);
        try {
            var r = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' });
            return await r.json().catch(function () { return { ok: false, erro: 'Resposta inválida do servidor.' }; });
        } catch (e) { return { ok: false, erro: 'Sem conexão. Verifique a internet e tente de novo.' }; }
    }
    function avisar(msg) { if (window.wmToast) window.wmToast(msg); else alert(msg); }
    function criar(tag, classe, texto) {
        var n = document.createElement(tag);
        if (classe) n.className = classe;
        if (texto !== undefined && texto !== null) n.textContent = texto;
        return n;
    }
    function data(s) { return s ? new Date(s.replace(' ', 'T')) : null; }
    function hora(s) { var d = data(s); return d ? d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) : ''; }
    function diaRotulo(s) {
        var d = data(s); if (!d) return '';
        var hoje = new Date(); var ontem = new Date(); ontem.setDate(hoje.getDate() - 1);
        if (d.toDateString() === hoje.toDateString()) return 'Hoje';
        if (d.toDateString() === ontem.toDateString()) return 'Ontem';
        return d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: d.getFullYear() === hoje.getFullYear() ? undefined : 'numeric' });
    }
    function quandoLista(s) { var d = data(s); if (!d) return ''; return d.toDateString() === new Date().toDateString() ? hora(s) : diaRotulo(s); }
    function iniciais(nome) {
        var p = String(nome || '?').replace(/[^\p{L}\s]/gu, '').trim().split(/\s+/);
        return ((p[0] || '?')[0] + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase();
    }
    var STATUS = {
        na_fila: ['🕓', 'Na fila'], enviando: ['🕓', 'Enviando'], enviada: ['✓', 'Enviada'], entregue: ['✓✓', 'Entregue'],
        lida: ['✓✓', 'Lida'], falhou: ['⚠', 'Não enviada'], simulada: ['🧪', 'Simulada (modo de teste)'], cancelada: ['⚠', 'Não enviada'], recebida: ['', '']
    };

    // ------------------------------------------------------------ lista
    async function carregarLista() {
        var r = await api('lista', { q: estado.q, filtro: estado.filtro, selecionada: estado.selecionada || '' });
        if (!r.ok) return;
        lista.textContent = '';
        if (!r.conversas.length) {
            lista.appendChild(criar('li', 'chat-lista-vazia', estado.q || estado.filtro !== 'todas' ? 'Nenhuma conversa com esse filtro.' : 'Nenhuma conversa ainda. Elas aparecem aqui quando um cliente escreve ou quando você inicia pelo botão “Conversar no WhatsApp” do lead.'));
        }
        r.conversas.forEach(function (c) {
            var li = criar('li');
            var b = criar('button', 'chat-item' + (c.id === estado.selecionada ? ' is-atual' : '') + (c.nao_lidas ? ' tem-novas' : ''));
            b.type = 'button';
            b.dataset.id = c.id;
            b.appendChild(criar('span', 'avatar', iniciais(c.nome)));
            var meio = criar('span', 'chat-item-meio');
            var l1 = criar('span', 'chat-item-l1');
            l1.appendChild(criar('strong', null, c.nome));
            l1.appendChild(criar('time', null, quandoLista(c.quando)));
            meio.appendChild(l1);
            var l2 = criar('span', 'chat-item-l2');
            l2.appendChild(criar('span', 'chat-item-previa', c.previa || c.telefone));
            if (c.nao_lidas) { var badge = criar('b', 'chat-badge', String(c.nao_lidas)); badge.setAttribute('aria-label', c.nao_lidas + ' não lida(s)'); l2.appendChild(badge); }
            meio.appendChild(l2);
            if (c.sem_lead) meio.appendChild(criar('span', 'chat-item-tag', 'Sem lead'));
            else if (c.aguardando) meio.appendChild(criar('span', 'chat-item-tag is-aguardando', 'Aguardando resposta'));
            b.appendChild(meio);
            b.addEventListener('click', function () { abrir(c.id); });
            li.appendChild(b);
            lista.appendChild(li);
        });
    }

    // ------------------------------------------------------------ conversa
    async function abrir(id) {
        estado.selecionada = id;
        estado.ultimoId = 0;
        raiz.classList.add('is-aberta');
        history.replaceState(null, '', '/pages/conversas.php?c=' + id);
        el('chat-vazio').hidden = true;
        el('chat-ativa').hidden = false;
        msgs.textContent = '';
        delete msgs.dataset.ultimoDia;
        msgs.appendChild(criar('p', 'chat-carregando', 'Carregando…'));
        var r = await api('abrir', { id: id });
        if (!r.ok) { avisar(r.erro || 'Não foi possível abrir a conversa.'); return; }
        msgs.textContent = '';
        estado.conversa = r;
        cabecalho(r.conversa);
        acrescentar(r.mensagens, true);
        montarRodape();
        montarInfo();
        document.querySelectorAll('.chat-item').forEach(function (b) {
            b.classList.toggle('is-atual', Number(b.dataset.id) === id);
            if (Number(b.dataset.id) === id) { b.classList.remove('tem-novas'); var bd = b.querySelector('.chat-badge'); if (bd) bd.remove(); }
        });
    }

    function cabecalho(c) {
        el('chat-nome').textContent = c.nome;
        el('chat-tel').textContent = c.telefone;
        var j = el('chat-janela');
        if (c.opt_out && !c.reabriu) { j.textContent = 'Não quer receber mensagens'; j.className = 'pill pill-red'; }
        else if (c.janela_aberta) { j.textContent = 'Conversa aberta até ' + hora(c.janela_expira); j.className = 'pill pill-green'; }
        else { j.textContent = 'Fora das 24 h: só template'; j.className = 'pill pill-amber'; }
    }

    function acrescentar(lista_, inicial) {
        var pertoDoFim = msgs.scrollHeight - msgs.scrollTop - msgs.clientHeight < 120;
        lista_.forEach(function (m) {
            if (document.getElementById('msg-' + m.id)) return;
            var dia = diaRotulo(m.quando);
            if (msgs.dataset.ultimoDia !== dia) {
                msgs.appendChild(criar('div', 'chat-dia', dia));
                msgs.dataset.ultimoDia = dia;
            }
            msgs.appendChild(bolha(m));
            if (m.id > estado.ultimoId) estado.ultimoId = m.id;
        });
        if (inicial || pertoDoFim) msgs.scrollTop = msgs.scrollHeight;
    }

    function bolha(m) {
        var b = criar('div', 'chat-bolha ' + (m.direcao === 'entrada' ? 'is-cliente' : 'is-nossa') + (m.status === 'falhou' || m.status === 'cancelada' ? ' is-erro' : ''));
        b.id = 'msg-' + m.id;
        if (m.direcao === 'saida' && (m.automatica || m.autor)) b.appendChild(criar('span', 'chat-autor', m.automatica ? 'Mensagem automática' : m.autor));
        if (m.tipo === 'reacao') b.classList.add('is-reacao');
        var t = criar('p', 'chat-texto', m.texto || (m.tipo === 'template' ? '(template)' : ''));
        b.appendChild(t);
        if (m.tem_arquivo) {
            var a = criar('a', 'chat-arquivo', m.direcao === 'entrada' ? 'Abrir arquivo' : 'Ver arquivo enviado');
            a.href = '/api/whatsapp_midia.php?id=' + encodeURIComponent(m.id);
            a.target = '_blank'; a.rel = 'noopener';
            b.appendChild(a);
        }
        var rod = criar('span', 'chat-meta');
        rod.appendChild(criar('time', null, hora(m.quando)));
        if (m.direcao === 'saida') {
            var st = STATUS[m.status] || ['', m.status];
            var s = criar('span', 'chat-status st-' + m.status, st[0]);
            s.title = st[1]; s.setAttribute('aria-label', st[1]);
            rod.appendChild(s);
        }
        b.appendChild(rod);
        if ((m.status === 'falhou' || m.status === 'cancelada') && m.erro) b.appendChild(criar('span', 'chat-erro', m.erro));
        if (m.status === 'simulada') b.appendChild(criar('span', 'chat-simulada', 'Modo de teste: não foi enviada ao cliente.'));
        return b;
    }

    function atualizarStatus(lista_) {
        (lista_ || []).forEach(function (s) {
            var b = document.getElementById('msg-' + s.id);
            var atual = b && b.querySelector('.chat-status');
            if (!atual || atual.classList.contains('st-' + s.status)) return;
            var st = STATUS[s.status] || ['', s.status];
            atual.className = 'chat-status st-' + s.status;
            atual.textContent = st[0]; atual.title = st[1]; atual.setAttribute('aria-label', st[1]);
            b.classList.toggle('is-erro', s.status === 'falhou' || s.status === 'cancelada');
            if (s.status === 'falhou' && s.erro_texto && !b.querySelector('.chat-erro')) b.appendChild(criar('span', 'chat-erro', s.erro_texto));
        });
    }

    // ------------------------------------------------------------ rodapé (o que dá para enviar)
    function montarRodape() {
        var r = estado.conversa, c = r.conversa;
        rodape.textContent = '';
        if (c.conta_status !== 'ativa') {
            rodape.appendChild(criar('div', 'chat-bloqueio', c.conta_status === 'pausada' ? 'O WhatsApp da empresa está pausado: nenhuma mensagem sai agora.' : 'O WhatsApp da empresa está com problema de conexão. Avise o responsável pela configuração.'));
            return;
        }
        if (c.opt_out && !c.reabriu) {
            rodape.appendChild(criar('div', 'chat-bloqueio', 'O cliente pediu para não receber mensagens. Só é possível responder se ele voltar a escrever.'));
            return;
        }
        if (c.janela_aberta) {
            var f = criar('form', 'chat-compor');
            var ta = criar('textarea');
            ta.rows = 2; ta.maxLength = 4000; ta.placeholder = 'Escreva sua mensagem…'; ta.setAttribute('aria-label', 'Mensagem');
            ta.addEventListener('input', function () { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 180) + 'px'; });
            ta.addEventListener('keydown', function (e) { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); f.requestSubmit(); } });
            var anexo = criar('label', 'btn btn-outline chat-anexar');
            anexo.title = 'Enviar PDF ou foto';
            anexo.appendChild(criar('span', null, '📎'));
            anexo.appendChild(criar('span', 'sr-only', 'Anexar PDF ou foto'));
            var inp = criar('input'); inp.type = 'file'; inp.accept = 'application/pdf,image/jpeg,image/png'; inp.hidden = true;
            anexo.appendChild(inp);
            inp.addEventListener('change', async function () {
                if (!inp.files[0]) return;
                if (inp.files[0].size > 15 * 1024 * 1024) { avisar('Arquivo maior que 15 MB.'); inp.value = ''; return; }
                if (!confirm('Enviar "' + inp.files[0].name + '" para o cliente?')) { inp.value = ''; return; }
                await enviar('enviar_arquivo', { id: c.id, legenda: ta.value.trim() }, inp.files[0]);
                inp.value = ''; ta.value = '';
            });
            var bt = criar('button', 'btn btn-primary chat-enviar', 'Enviar'); bt.type = 'submit';
            f.appendChild(anexo); f.appendChild(ta); f.appendChild(bt);
            f.addEventListener('submit', async function (e) {
                e.preventDefault();
                var texto = ta.value.trim();
                if (!texto) { ta.focus(); return; }
                if (await enviar('enviar_texto', { id: c.id, texto: texto })) { ta.value = ''; ta.style.height = ''; }
                ta.focus();
            });
            rodape.appendChild(f);
            return;
        }
        // Fora da janela: só template aprovado.
        var box = criar('div', 'chat-template');
        box.appendChild(criar('p', 'chat-template-ajuda', 'Faz mais de 24 horas que o cliente não escreve. Para retomar o contato, envie uma mensagem aprovada pela Meta:'));
        var prontos = r.templates.filter(function (t) { return t.pronto; });
        if (!prontos.length) {
            box.appendChild(criar('p', 'chat-bloqueio', r.templates.length ? 'Os templates aprovados ainda não estão configurados para este cliente (faltam dados). Fale com o responsável.' : 'Nenhum template aprovado disponível. Fale com o responsável pela configuração do WhatsApp.'));
            rodape.appendChild(box);
            return;
        }
        var sel = criar('select'); sel.setAttribute('aria-label', 'Mensagem aprovada');
        prontos.forEach(function (t) { var o = criar('option', null, t.nome + (t.categoria === 'marketing' ? ' (marketing)' : '')); o.value = t.id; sel.appendChild(o); });
        var previa = criar('div', 'wa-bolha chat-template-previa');
        function mostrar() { var t = prontos.find(function (x) { return String(x.id) === sel.value; }); previa.textContent = t ? t.previa : ''; }
        sel.addEventListener('change', mostrar); mostrar();
        var semAut = !r.consentimento.atendimento.concedido;
        var bt2 = criar('button', 'btn btn-primary', 'Enviar esta mensagem'); bt2.type = 'button';
        bt2.addEventListener('click', function () { enviar('enviar_template', { id: c.id, template_id: sel.value }); });
        box.appendChild(sel); box.appendChild(previa);
        if (semAut) box.appendChild(criar('p', 'chat-template-aviso', 'Atenção: não há autorização registrada deste cliente para contato pelo WhatsApp. Registre em “Dados do cliente” antes de enviar.'));
        box.appendChild(bt2);
        rodape.appendChild(box);
    }

    async function enviar(acao, dados, arquivo) {
        if (estado.enviando) return false;
        estado.enviando = true;
        rodape.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
        var r = await api(acao, dados, arquivo);
        estado.enviando = false;
        rodape.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
        if (r.mensagem && r.mensagem.id) acrescentar([r.mensagem], true);
        if (!r.ok) { avisar(r.erro || 'Mensagem não enviada.'); return false; }
        carregarLista();
        return true;
    }

    // ------------------------------------------------------------ painel do cliente
    function montarInfo() {
        var r = estado.conversa;
        info.textContent = '';
        var fechar = criar('button', 'chat-info-fechar', '×'); fechar.type = 'button'; fechar.setAttribute('aria-label', 'Fechar dados do cliente');
        fechar.addEventListener('click', function () { alternarInfo(false); });
        info.appendChild(fechar);
        if (r.lead) {
            var l = r.lead;
            info.appendChild(criar('h2', null, l.nome));
            var dl = criar('dl', 'chat-dados');
            [['Telefone', l.telefone], ['Empreendimento', l.empreendimento || '—'], ['Corretor', l.corretor || 'Sem responsável'], ['Etapa', l.etapa || '—']].forEach(function (p) {
                dl.appendChild(criar('dt', null, p[0])); dl.appendChild(criar('dd', null, p[1]));
            });
            info.appendChild(dl);
            var a = criar('a', 'btn btn-outline btn-block', 'Abrir ficha do lead'); a.href = '/pages/lead.php?id=' + l.id;
            info.appendChild(a);
            info.appendChild(criar('h3', null, 'Próximas atividades'));
            if (l.proximos.length) {
                var ul = criar('ul', 'chat-proximos');
                l.proximos.forEach(function (p) { ul.appendChild(criar('li', null, diaRotulo(p.quando) + ' ' + hora(p.quando) + ' — ' + p.titulo)); });
                info.appendChild(ul);
            } else info.appendChild(criar('p', 'muted', 'Nada agendado.'));
        } else {
            info.appendChild(criar('h2', null, 'Contato sem lead'));
            info.appendChild(criar('p', 'muted', 'Este número ainda não está ligado a nenhum lead.'));
            if (r.pode_vincular) info.appendChild(formVincular());
        }
        info.appendChild(criar('h3', null, 'Autorizações (LGPD)'));
        [['atendimento', 'Contato pelo WhatsApp'], ['marketing', 'Ofertas e novidades']].forEach(function (p) {
            var c = r.consentimento[p[0]];
            var linha = criar('div', 'chat-autorizacao ' + (c.concedido ? 'is-ok' : 'is-no'));
            linha.appendChild(criar('b', null, (c.concedido ? '✓ ' : '✗ ') + p[1]));
            linha.appendChild(criar('span', null, c.concedido ? 'Autorizado (' + ({ formulario_meta: 'formulário', mensagem_cliente: 'mensagem do cliente', manual: 'registrado pela equipe', importacao: 'importação', sistema: 'sistema' }[c.origem] || c.origem) + ')' : 'Sem autorização registrada'));
            info.appendChild(linha);
        });
        info.appendChild(formAutorizacao());
    }

    function formAutorizacao() {
        var d = criar('details', 'chat-aut-form');
        d.appendChild(criar('summary', null, 'Registrar autorização do cliente'));
        var f = criar('form');
        var sel = criar('select'); sel.setAttribute('aria-label', 'Tipo de autorização');
        [['atendimento', 'Contato pelo WhatsApp'], ['marketing', 'Ofertas e novidades (marketing)']].forEach(function (p) { var o = criar('option', null, p[1]); o.value = p[0]; sel.appendChild(o); });
        var ev = criar('textarea'); ev.rows = 2; ev.maxLength = 400; ev.required = true; ev.placeholder = 'Como o cliente autorizou? Ex.: autorizou por telefone em 12/11 às 10h.';
        ev.setAttribute('aria-label', 'Como o cliente autorizou');
        var bt = criar('button', 'btn btn-outline btn-sm', 'Registrar'); bt.type = 'submit';
        f.appendChild(sel); f.appendChild(ev); f.appendChild(bt);
        f.addEventListener('submit', async function (e) {
            e.preventDefault();
            var r = await api('consentimento', { id: estado.selecionada, finalidade: sel.value, acao_consentimento: 'concedido', evidencia: ev.value.trim() });
            avisar(r.ok ? r.mensagem : r.erro);
            if (r.ok) abrir(estado.selecionada);
        });
        d.appendChild(f);
        return d;
    }

    function formVincular() {
        var box = criar('div', 'chat-vincular');
        var inp = criar('input'); inp.type = 'search'; inp.placeholder = 'Buscar lead por nome ou telefone'; inp.setAttribute('aria-label', 'Buscar lead');
        var res = criar('ul', 'chat-vincular-res');
        var t;
        inp.addEventListener('input', function () {
            clearTimeout(t);
            t = setTimeout(async function () {
                var r = await api('buscar_leads', { q: inp.value });
                res.textContent = '';
                (r.leads || []).forEach(function (l) {
                    var li = criar('li'); var b = criar('button', 'btn btn-outline btn-sm', 'Ligar a ' + l.nome + ' · ' + l.telefone); b.type = 'button';
                    b.addEventListener('click', async function () {
                        var v = await api('vincular_lead', { id: estado.selecionada, lead_id: l.id });
                        avisar(v.ok ? v.mensagem : v.erro);
                        if (v.ok) { abrir(estado.selecionada); carregarLista(); }
                    });
                    li.appendChild(b); res.appendChild(li);
                });
            }, 300);
        });
        box.appendChild(inp); box.appendChild(res);
        return box;
    }

    function alternarInfo(mostrar) {
        raiz.classList.toggle('mostra-info', mostrar);
        el('chat-info-btn').setAttribute('aria-expanded', mostrar ? 'true' : 'false');
    }

    // ------------------------------------------------------------ atualização periódica
    async function novidades() {
        if (document.hidden || !estado.selecionada || estado.enviando) return;
        var r = await api('abrir', { id: estado.selecionada, depois_id: estado.ultimoId });
        if (!r.ok) return;
        var antes = estado.conversa && estado.conversa.conversa.janela_aberta;
        estado.conversa.conversa = r.conversa;
        cabecalho(r.conversa);
        if (r.mensagens.length) acrescentar(r.mensagens, false);
        atualizarStatus(r.status);
        if (antes !== r.conversa.janela_aberta) montarRodape();   // cliente escreveu: libera o texto livre
    }
    setInterval(novidades, 5000);
    setInterval(function () { if (!document.hidden) carregarLista(); }, 15000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { novidades(); carregarLista(); } });

    // ------------------------------------------------------------ eventos da tela
    el('chat-voltar').addEventListener('click', function () {
        raiz.classList.remove('is-aberta'); alternarInfo(false);
        estado.selecionada = 0; history.replaceState(null, '', '/pages/conversas.php'); carregarLista();
    });
    el('chat-info-btn').addEventListener('click', function () { alternarInfo(!raiz.classList.contains('mostra-info')); });
    var busca;
    el('chat-q').addEventListener('input', function (e) { clearTimeout(busca); busca = setTimeout(function () { estado.q = e.target.value; carregarLista(); }, 300); });
    document.querySelectorAll('.chat-filtro').forEach(function (b) {
        b.addEventListener('click', function () {
            document.querySelectorAll('.chat-filtro').forEach(function (x) { x.classList.toggle('is-ativo', x === b); });
            estado.filtro = b.dataset.filtro; carregarLista();
        });
    });

    carregarLista();
    if (estado.selecionada) abrir(estado.selecionada);
})();
