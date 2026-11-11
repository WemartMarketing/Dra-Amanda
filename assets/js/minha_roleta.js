// Minha roleta — check-in por geolocalização (Etapa 4).
// O navegador só mede a posição; quem decide se o corretor está dentro do
// plantão é o servidor (api/roleta_presenca.php). Enquanto o corretor está
// na roleta, a página reconfirma a posição a cada intervalo configurado.
(function () {
    'use strict';
    var raiz = document.querySelector('[data-checkin]');
    if (!raiz) return;

    var estado = JSON.parse(raiz.dataset.estado || '{}');
    var box = raiz.querySelector('[data-status]');
    var titulo = raiz.querySelector('[data-status-titulo]');
    var motivo = raiz.querySelector('[data-status-motivo]');
    var linha = raiz.querySelector('[data-linha]');
    var btnVerificar = raiz.querySelector('[data-acao=verificar]');
    var btnEntrar = raiz.querySelector('[data-acao=entrar]');
    var btnSair = raiz.querySelector('[data-acao=sair]');
    var elPosicao = raiz.querySelector('[data-posicao]');
    var elHoje = raiz.querySelector('[data-hoje]');
    var intervaloMs = Math.max(1, estado.intervalo_min || 5) * 60000;
    var proxima = null;      // timestamp da próxima reverificação automática
    var ocupado = false;
    var podeEntrar = false;

    function pintar(tipo, t, m) {
        box.className = 'checkin-status is-' + tipo;
        titulo.textContent = t;
        motivo.textContent = m || '';
    }

    function atualizarLinha() {
        if (estado.na_roleta) {
            var txt = 'Na roleta desde ' + estado.desde;
            if (proxima) {
                var min = Math.max(0, Math.ceil((proxima - Date.now()) / 60000));
                txt += ' · próxima verificação ' + (min <= 0 ? 'agora' : 'em ' + min + ' min');
            }
            linha.textContent = txt;
        } else {
            linha.textContent = 'Fora da roleta';
        }
        btnEntrar.hidden = !!estado.na_roleta;
        btnEntrar.disabled = !podeEntrar || ocupado;
        btnSair.hidden = !estado.na_roleta;
        btnVerificar.disabled = ocupado;
        btnSair.disabled = ocupado;
        elPosicao.textContent = estado.posicao ? estado.posicao + 'º' : '—';
        elHoje.textContent = estado.recebidos_hoje || 0;
    }

    function mensagemErroGps(err) {
        if (!err) return 'Não foi possível obter sua localização.';
        if (err.code === 1) return 'Você não permitiu o acesso à localização. Libere a localização deste site nas configurações do navegador e toque em VERIFICAR LOCALIZAÇÃO.';
        if (err.code === 3) return 'O GPS demorou demais para responder. Vá para uma área aberta e tente de novo.';
        return 'O celular não conseguiu determinar sua localização. Verifique se o GPS está ligado.';
    }

    function obterPosicao() {
        return new Promise(function (resolve, reject) {
            if (!estado.exigir_presenca) { resolve(null); return; }
            if (!window.isSecureContext) { reject({ code: 'inseguro' }); return; }
            if (!navigator.geolocation) { reject({ code: 2 }); return; }
            navigator.geolocation.getCurrentPosition(resolve, reject, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
        });
    }

    async function enviar(acao, pos) {
        var fd = new FormData();
        fd.append('csrf_token', csrfToken());
        fd.append('roleta_id', estado.roleta_id);
        fd.append('acao', acao);
        if (pos && pos.coords) {
            fd.append('latitude', pos.coords.latitude);
            fd.append('longitude', pos.coords.longitude);
            fd.append('precisao', pos.coords.accuracy);
        }
        var r;
        try { r = await fetch('/api/roleta_presenca.php', { method: 'POST', body: fd, credentials: 'same-origin' }); }
        catch (e) { return { ok: false, erro: 'Sem conexão com o servidor. Verifique a internet.' }; }
        var j = await r.json().catch(function () { return { ok: false, erro: 'Resposta inválida do servidor (' + r.status + ').' }; });
        if (r.status === 401) { location.href = '/login.php'; }
        return j;
    }

    function aplicar(j, acao) {
        if (!j.ok) {
            podeEntrar = false;
            pintar('fora', 'NÃO FOI POSSÍVEL VERIFICAR', j.erro || 'Tente de novo.');
            return;
        }
        var estavaDentro = estado.na_roleta;
        estado.na_roleta = !!j.na_roleta;
        estado.desde = j.desde;
        estado.posicao = j.posicao;
        estado.recebidos_hoje = j.recebidos_hoje;
        if (j.intervalo_min) intervaloMs = j.intervalo_min * 60000;
        podeEntrar = !!j.pode;
        if (acao === 'sair') {
            proxima = null;
            pintar('neutro', 'VOCÊ SAIU DA ROLETA', 'Toque em VERIFICAR LOCALIZAÇÃO para entrar de novo.');
            return;
        }
        if (estado.na_roleta) {
            proxima = Date.now() + intervaloMs;
            var extra = j.distribuidos ? ' Você já recebeu ' + j.distribuidos + ' lead(s) que estavam na fila.' : '';
            pintar('dentro', 'VOCÊ ESTÁ NA ROLETA', j.mensagem + extra);
        } else if (j.pode) {
            proxima = null;
            pintar('dentro', 'DENTRO DO PLANTÃO — PODE ENTRAR NA ROLETA', j.mensagem);
        } else {
            proxima = null;
            var t = 'FORA DO PLANTÃO — ENTRADA BLOQUEADA';
            if (j.codigo === 'gps_impreciso') t = 'GPS IMPRECISO — ENTRADA BLOQUEADA';
            else if (j.codigo === 'fora_do_horario') t = 'FORA DO HORÁRIO — ENTRADA BLOQUEADA';
            else if (j.codigo === 'pausado' || j.codigo === 'roleta_pausada') t = 'ROLETA PAUSADA — ENTRADA BLOQUEADA';
            var m = j.mensagem;
            if (estavaDentro) m += ' Você saiu da roleta.';
            pintar('fora', t, m);
        }
    }

    async function executar(acao) {
        if (ocupado) return;
        ocupado = true; atualizarLinha();
        if (acao !== 'sair') pintar(estado.na_roleta ? 'dentro' : 'neutro', estado.na_roleta ? 'VOCÊ ESTÁ NA ROLETA' : 'VERIFICANDO SUA LOCALIZAÇÃO…', 'Obtendo sua posição pelo GPS…');
        try {
            var pos = acao === 'sair' ? null : await obterPosicao();
            aplicar(await enviar(acao, pos), acao);
        } catch (err) {
            podeEntrar = false;
            if (err && err.code === 'inseguro') {
                raiz.querySelector('[data-inseguro]').hidden = false;
                pintar('fora', 'LOCALIZAÇÃO INDISPONÍVEL', 'Esta página precisa estar em HTTPS para o celular liberar o GPS.');
            } else {
                // Sem posição, o servidor registra a tentativa recusada; na
                // renovação a sessão continua até vencer pela validade.
                var j = await enviar(acao === 'entrar' ? 'verificar' : acao, null);
                if (j.ok) { estado.na_roleta = !!j.na_roleta; estado.posicao = j.posicao; }
                if (estado.na_roleta) proxima = Date.now() + 60000; // tenta de novo em 1 min
                pintar('fora', estado.na_roleta ? 'NÃO CONSEGUIMOS CONFIRMAR SUA LOCALIZAÇÃO' : (err && err.code === 1 ? 'LOCALIZAÇÃO BLOQUEADA — ENTRADA BLOQUEADA' : 'SEM LOCALIZAÇÃO — ENTRADA BLOQUEADA'), mensagemErroGps(err));
            }
        }
        ocupado = false; atualizarLinha();
    }

    btnVerificar.addEventListener('click', function () { executar(estado.na_roleta ? 'renovar' : 'verificar'); });
    btnEntrar.addEventListener('click', function () { executar('entrar'); });
    btnSair.addEventListener('click', function () {
        if (confirm('Sair da roleta? Você deixa de receber leads até entrar de novo.')) executar('sair');
    });

    // Relógio: atualiza o "próxima verificação em N min" e dispara a renovação.
    setInterval(function () {
        if (estado.na_roleta && proxima && Date.now() >= proxima && !ocupado) executar('renovar');
        else atualizarLinha();
    }, 15000);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible' && estado.na_roleta && proxima && Date.now() >= proxima) executar('renovar');
    });

    if (estado.bloqueio) {
        // Bloqueio que não depende do GPS (pausado, fora do horário…): mostra direto.
        pintar('fora', estado.bloqueio.codigo === 'fora_do_horario' ? 'FORA DO HORÁRIO — ENTRADA BLOQUEADA'
            : (estado.bloqueio.codigo === 'pausado' || estado.bloqueio.codigo === 'roleta_pausada') ? 'ROLETA PAUSADA — ENTRADA BLOQUEADA'
            : 'ENTRADA BLOQUEADA', estado.bloqueio.mensagem);
        atualizarLinha();
    } else {
        if (estado.na_roleta && estado.ultima_verificacao) proxima = estado.ultima_verificacao + intervaloMs;
        atualizarLinha();
        if (!window.isSecureContext && estado.exigir_presenca) raiz.querySelector('[data-inseguro]').hidden = false;
        executar(estado.na_roleta ? 'renovar' : 'verificar');
    }
})();
