// WeMart Sales — JS vanilla (sem dependências externas)

// Token CSRF da sessão atual (ver <meta name="csrf-token"> em
// includes/layout_start.php e login.php) — toda escrita que não parte
// de um <form> com csrf_field() precisa anexar isto manualmente.
function csrfToken() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.content : '';
}

(function () {
    async function moverLead(leadId, destino) {
        const fd = new FormData();
        fd.append('lead_id', leadId);
        fd.append('etapa_id', destino);
        fd.append('csrf_token', csrfToken());
        const r = await fetch('/api/lead_mover_etapa.php', { method: 'POST', body: fd });
        const j = await r.json().catch(function () { return {}; });
        if (r.ok && j.ok) {
            wmToast(j.mensagem || 'Etapa atualizada.', true);
            location.reload();
        } else {
            wmToast(j.erro || 'Não foi possível mover este lead.');
        }
    }
    window.wmMoverLead = moverLead;

    // Drag-and-drop (desktop) entre colunas do Kanban.
    let arrastando = null;
    document.querySelectorAll('.kcard').forEach(function (card) {
        card.addEventListener('dragstart', function () {
            arrastando = card;
            card.classList.add('dragging');
        });
        card.addEventListener('dragend', function () {
            card.classList.remove('dragging');
            arrastando = null;
        });
    });
    // Modais genéricos (Relatórios, Roletas, Usuários e acessos) — abrir/
    // fechar por atributo, clique fora ou Esc. Nenhuma dependência externa.
    document.querySelectorAll('[data-open-modal]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const el = document.getElementById(btn.dataset.openModal);
            if (el) el.hidden = false;
        });
    });
    document.querySelectorAll('[data-close-modal]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const el = document.getElementById(btn.dataset.closeModal);
            if (el) el.hidden = true;
        });
    });
    document.querySelectorAll('.modal-overlay').forEach(function (overlay) {
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) overlay.hidden = true;
        });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay:not([hidden])').forEach(function (o) { o.hidden = true; });
        }
    });

    document.querySelectorAll('.kcol').forEach(function (col) {
        col.addEventListener('dragover', function (e) {
            e.preventDefault();
            col.classList.add('drag-over');
        });
        col.addEventListener('dragleave', function () {
            col.classList.remove('drag-over');
        });
        col.addEventListener('drop', function (e) {
            e.preventDefault();
            col.classList.remove('drag-over');
            if (!arrastando) return;
            const destino = col.dataset.etapa;
            if (destino && destino !== arrastando.dataset.etapa) {
                moverLead(arrastando.dataset.id, destino, arrastando);
            }
        });
    });

    // --------------------------------------------------------------
    // Roletas — criar/editar (formulário dentro de um modal), vincular
    // e remover empreendimentos/corretores. Todos recarregam a página
    // ao concluir, para refletir o estado real vindo do banco (o
    // servidor é a única fonte de verdade — nunca atualizamos a lista
    // só no JS).
    // --------------------------------------------------------------
    document.querySelectorAll('[data-form-roleta]').forEach(function (form) {
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            const r = await fetch('/api/roleta_salvar.php', { method: 'POST', body: new FormData(form) });
            const data = await r.json().catch(function () { return {}; });
            if (r.ok && data.ok) {
                location.href = '/pages/roletas.php' + (data.id ? '?id=' + data.id : '');
            } else {
                var alerta = form.querySelector('[data-form-alerta]');
                if (alerta) { alerta.textContent = data.erro || 'Não foi possível salvar a roleta.'; alerta.hidden = false; }
                else alert(data.erro || 'Não foi possível salvar a roleta.');
            }
        });
    });

    document.querySelectorAll('[data-add-corretor]').forEach(function (form) {
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            const usuarioId = form.usuario_id.value;
            if (!usuarioId) return;
            await roletaCorretorAcao(currentRoletaId(), usuarioId, 'add');
        });
    });
    document.querySelectorAll('[data-corretor-acao]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (btn.dataset.confirmar && !confirm(btn.dataset.confirmar)) return;
            roletaCorretorAcao(currentRoletaId(), btn.dataset.usuario, btn.dataset.corretorAcao);
        });
    });
    document.querySelectorAll('[data-prioridade-empreendimento]').forEach(function (form) {
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            const body = new URLSearchParams({ roleta_id: currentRoletaId(), empreendimento_id: form.dataset.prioridadeEmpreendimento, acao: 'prioridade', prioridade: form.prioridade.value, csrf_token: csrfToken() });
            const r = await fetch('/api/roleta_empreendimento.php', { method: 'POST', body: body });
            const data = await r.json().catch(function () { return {}; });
            if (r.ok && data.ok) { location.reload(); } else { alert(data.erro || 'Não foi possível salvar a prioridade.'); }
        });
    });
    document.querySelectorAll('[data-fila-processar]').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            btn.disabled = true;
            const body = new URLSearchParams({ acao: 'processar', roleta_id: btn.dataset.filaProcessar, csrf_token: csrfToken() });
            const r = await fetch('/api/roleta_fila.php', { method: 'POST', body: body });
            const data = await r.json().catch(function () { return {}; });
            btn.disabled = false;
            alert(data.mensagem || data.erro || 'Não foi possível processar a fila.');
            if (r.ok && data.ok) location.reload();
        });
    });
    document.querySelectorAll('[data-remover-corretor]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('Remover este corretor da roleta?')) return;
            roletaCorretorAcao(currentRoletaId(), btn.dataset.removerCorretor, 'remover');
        });
    });
    document.querySelectorAll('[data-add-empreendimento]').forEach(function (form) {
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            const empId = form.empreendimento_id.value;
            if (!empId) return;
            await roletaEmpreendimentoAcao(currentRoletaId(), empId, 'add');
        });
    });
    document.querySelectorAll('[data-remover-empreendimento]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('Remover este empreendimento da roleta?')) return;
            roletaEmpreendimentoAcao(currentRoletaId(), btn.dataset.removerEmpreendimento, 'remover');
        });
    });

    // --------------------------------------------------------------
    // Usuários e acessos — um único modal reaproveitado para criar e
    // editar (preenchido via atributos data-* do botão "Editar" de
    // cada linha); criar sempre parte zerado.
    // --------------------------------------------------------------
    var modalUsuario = document.getElementById('modal-usuario');
    if (modalUsuario) {
        var formUsuario = modalUsuario.querySelector('[data-form-usuario]');
        var tituloUsuario = document.getElementById('modal-usuario-titulo');
        var campoAtivo = document.getElementById('campo-ativo-usuario');
        var campoEmpreendimentos = document.getElementById('campo-empreendimentos-usuario');

        // Mostra a lista de empreendimentos só quando a função escolhida
        // tem escopo "produtos_vinculados" (GP do Produto e afins).
        function atualizarCampoEmpreendimentos() {
            if (!campoEmpreendimentos) return;
            var opcao = formUsuario.funcao_id.options[formUsuario.funcao_id.selectedIndex];
            campoEmpreendimentos.hidden = !opcao || opcao.dataset.escopo !== 'produtos_vinculados';
        }
        formUsuario.funcao_id.addEventListener('change', atualizarCampoEmpreendimentos);

        document.querySelectorAll('[data-open-modal="modal-usuario"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                formUsuario.reset();
                if (btn.dataset.modo === 'editar') {
                    tituloUsuario.textContent = 'Editar usuário';
                    formUsuario.id.value = btn.dataset.id;
                    formUsuario.nome.value = btn.dataset.nome;
                    formUsuario.email.value = btn.dataset.email;
                    formUsuario.funcao_id.value = btn.dataset.funcaoId;
                    formUsuario.equipe_id.value = btn.dataset.equipeId || '';
                    formUsuario.ativo.checked = btn.dataset.ativo === '1';
                    campoAtivo.style.display = 'flex';
                    var vinculados = (btn.dataset.empreendimentos || '').split(',').filter(Boolean);
                    formUsuario.querySelectorAll('input[name="empreendimentos[]"]').forEach(function (cb) {
                        cb.checked = vinculados.indexOf(cb.value) !== -1;
                    });
                } else {
                    tituloUsuario.textContent = 'Novo usuário';
                    formUsuario.id.value = '';
                    campoAtivo.style.display = 'none';
                }
                atualizarCampoEmpreendimentos();
            });
        });

        formUsuario.addEventListener('submit', async function (e) {
            e.preventDefault();
            const r = await fetch('/api/usuario_salvar.php', { method: 'POST', body: new FormData(formUsuario) });
            const data = await r.json().catch(function () { return {}; });
            if (r.ok && data.ok) { location.reload(); } else { alert(data.erro || 'Não foi possível salvar o usuário.'); }
        });
    }

    // --------------------------------------------------------------
    // Funções e permissões — mesmo padrão do modal de usuário: um
    // único modal reaproveitado para criar e editar, incluindo as
    // funções padrão do sistema (editar permissões é sempre permitido).
    // --------------------------------------------------------------
    var modalFuncao = document.getElementById('modal-funcao');
    if (modalFuncao) {
        var formFuncao = modalFuncao.querySelector('[data-form-funcao]');
        var tituloFuncao = document.getElementById('modal-funcao-titulo');

        document.querySelectorAll('[data-open-modal="modal-funcao"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                formFuncao.reset();
                formFuncao.querySelectorAll('input[name="permissoes[]"]').forEach(function (cb) { cb.checked = false; });
                if (btn.dataset.modo === 'editar') {
                    tituloFuncao.textContent = 'Editar função';
                    formFuncao.id.value = btn.dataset.id;
                    formFuncao.nome.value = btn.dataset.nome;
                    formFuncao.escopo.value = btn.dataset.escopo;
                    var permissoesAtuais = (btn.dataset.permissoes || '').split(',').filter(Boolean);
                    permissoesAtuais.forEach(function (chave) {
                        var cb = formFuncao.querySelector('input[name="permissoes[]"][value="' + chave + '"]');
                        if (cb) cb.checked = true;
                    });
                } else {
                    tituloFuncao.textContent = 'Criar função';
                    formFuncao.id.value = '';
                }
            });
        });

        formFuncao.addEventListener('submit', async function (e) {
            e.preventDefault();
            const r = await fetch('/api/funcao_salvar.php', { method: 'POST', body: new FormData(formFuncao) });
            const data = await r.json().catch(function () { return {}; });
            if (r.ok && data.ok) { location.reload(); } else { alert(data.erro || 'Não foi possível salvar a função.'); }
        });
    }

    document.querySelectorAll('[data-toggle-ativo]').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const acao = btn.dataset.ativoAtual === '1' ? 'desativar' : 'ativar';
            if (!confirm('Tem certeza que deseja ' + acao + ' este usuário?')) return;
            const r = await fetch('/api/usuario_toggle_ativo.php', { method: 'POST', body: new URLSearchParams({ id: btn.dataset.toggleAtivo, csrf_token: csrfToken() }) });
            const data = await r.json().catch(function () { return {}; });
            if (r.ok && data.ok) { location.reload(); } else { alert(data.erro || 'Não foi possível atualizar.'); }
        });
    });

    // --------------------------------------------------------------
    // Estrutura comercial (Etapa 2): equipes, superintendências,
    // empreendimentos e plantões. Mesmo padrão: envia, mostra o erro do
    // servidor ou recarrega com o estado real do banco.
    // --------------------------------------------------------------
    async function enviar(url, dados) {
        if (!dados.has('csrf_token')) dados.append('csrf_token', csrfToken());
        let r;
        try {
            r = await fetch(url, { method: 'POST', body: dados });
        } catch (e) {
            alert('Sem conexão com o servidor. Verifique a internet e tente de novo.');
            return null;
        }
        const data = await r.json().catch(function () { return {}; });
        if (r.ok && data.ok) return data;
        alert(data.erro || 'Não foi possível salvar. Tente novamente.');
        return null;
    }

    function travarBotao(form, travar) {
        const btn = form.querySelector('button[type="submit"]');
        if (!btn) return;
        if (travar) { btn.dataset.textoOriginal = btn.innerHTML; btn.disabled = true; btn.textContent = 'Salvando...'; }
        else if (btn.dataset.textoOriginal) { btn.disabled = false; btn.innerHTML = btn.dataset.textoOriginal; }
    }

    // Preenche um formulário de modal a partir dos data-* do botão que o abriu.
    function prepararModal(modalId, campos, aoPreencher) {
        const modal = document.getElementById(modalId);
        if (!modal) return null;
        const form = modal.querySelector('form');
        const titulo = modal.querySelector('[data-modal-titulo]');
        document.querySelectorAll('[data-open-modal="' + modalId + '"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                form.reset();
                form.querySelectorAll('input[type="checkbox"][name$="[]"]').forEach(function (cb) { cb.checked = false; });
                const editar = btn.dataset.modo === 'editar';
                form.elements.id.value = editar ? btn.dataset.id : '';
                if (titulo) titulo.textContent = editar ? titulo.dataset.tituloEditar : titulo.dataset.tituloNovo;
                if (editar) {
                    Object.keys(campos).forEach(function (campo) {
                        const valor = btn.dataset[campos[campo]];
                        if (form.elements[campo] && valor !== undefined) form.elements[campo].value = valor === '0' && campo.endsWith('_id') ? '' : valor;
                    });
                    (btn.dataset.empreendimentos || '').split(',').filter(Boolean).forEach(function (id) {
                        const cb = form.querySelector('input[name="empreendimentos[]"][value="' + id + '"]');
                        if (cb) cb.checked = true;
                    });
                }
                if (aoPreencher) aoPreencher(form, btn, editar);
                const primeiro = form.querySelector('input[type="text"]');
                if (primeiro) setTimeout(function () { primeiro.focus(); }, 30);
            });
        });
        return form;
    }

    const formSuper = prepararModal('modal-super', { nome: 'nome', superintendente_id: 'superintendenteId' });
    const formEquipe = prepararModal('modal-equipe', { nome: 'nome', superintendencia_id: 'superintendenciaId', gerente_id: 'gerenteId' }, function (form, btn, editar) {
        const excluir = form.querySelector('[data-excluir-equipe]');
        if (excluir) excluir.hidden = !editar;
    });
    const formPlantao = prepararModal('modal-plantao', {
        nome: 'nome', endereco: 'endereco', bairro: 'bairro', cidade: 'cidade', estado: 'estado',
        latitude: 'latitude', longitude: 'longitude', raio_metros: 'raio'
    }, function (form, btn, editar) {
        const campoAtivo = form.querySelector('[data-campo-ativo]');
        if (campoAtivo) campoAtivo.hidden = !editar;
        form.elements.ativo.checked = !editar || btn.dataset.ativo === '1';
        if (!editar) form.elements.raio_metros.value = 300;
        const status = form.querySelector('[data-geo-status]');
        if (status) status.textContent = 'Use quando estiver fisicamente no plantão. Também é possível copiar as coordenadas de um mapa.';
    });

    [[formSuper, '/api/superintendencia_salvar.php'], [formEquipe, '/api/equipe_salvar.php'], [formPlantao, '/api/plantao_salvar.php']].forEach(function (par) {
        const form = par[0];
        if (!form) return;
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            travarBotao(form, true);
            const data = await enviar(par[1], new FormData(form));
            travarBotao(form, false);
            if (!data) return;
            if (par[1].indexOf('plantao') !== -1 && data.id) { location.href = '/pages/plantoes.php?id=' + data.id; }
            else { location.reload(); }
        });
    });

    document.querySelectorAll('[data-excluir-super]').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            if (!confirm('Excluir esta superintendência? Ela não tem equipes.')) return;
            const dados = new FormData();
            dados.append('acao', 'excluir');
            dados.append('id', btn.dataset.excluirSuper);
            if (await enviar('/api/superintendencia_salvar.php', dados)) location.reload();
        });
    });
    document.querySelectorAll('[data-excluir-equipe]').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            if (!confirm('Excluir esta equipe? Só é possível se ela não tiver corretores nem leads.')) return;
            const dados = new FormData();
            dados.append('acao', 'excluir');
            dados.append('id', btn.closest('form').elements.id.value);
            if (await enviar('/api/equipe_salvar.php', dados)) location.reload();
        });
    });

    // Adicionar/mover/tirar corretor de equipe.
    document.querySelectorAll('[data-form-membro]').forEach(function (form) {
        async function salvarMembro() {
            if (!form.elements.usuario_id.value) return;
            const select = form.querySelector('[data-auto-submit]');
            if (select) {
                const texto = select.options[select.selectedIndex].text;
                if (!confirm(texto + '?')) { select.value = select.querySelector('option[selected]').value; return; }
            }
            if (await enviar('/api/equipe_membro.php', new FormData(form))) location.reload();
        }
        form.addEventListener('submit', function (e) { e.preventDefault(); salvarMembro(); });
        const auto = form.querySelector('[data-auto-submit]');
        if (auto) auto.addEventListener('change', salvarMembro);
    });

    // "Usar minha localização atual" (Geolocation API do navegador).
    document.querySelectorAll('[data-usar-localizacao]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const form = btn.closest('form');
            const status = form.querySelector('[data-geo-status]');
            if (!('geolocation' in navigator)) { status.textContent = 'Este navegador não informa localização. Digite as coordenadas.'; return; }
            if (!window.isSecureContext) { status.textContent = 'A localização só funciona com o site em HTTPS. Digite as coordenadas.'; return; }
            status.textContent = 'Buscando sua localização...';
            btn.disabled = true;
            navigator.geolocation.getCurrentPosition(function (pos) {
                btn.disabled = false;
                form.elements.latitude.value = pos.coords.latitude.toFixed(7);
                form.elements.longitude.value = pos.coords.longitude.toFixed(7);
                status.textContent = 'Localização preenchida (precisão de aproximadamente ' + Math.round(pos.coords.accuracy) + ' m). Confira antes de salvar.';
            }, function (err) {
                btn.disabled = false;
                status.textContent = err.code === 1
                    ? 'Permissão de localização negada. Libere nas configurações do navegador ou digite as coordenadas.'
                    : 'Não foi possível obter a localização agora. Tente de novo ou digite as coordenadas.';
            }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
        });
    });

    // Empreendimento: prévia da imagem, limite de tamanho e envio multipart.
    document.querySelectorAll('[data-form-empreendimento]').forEach(function (form) {
        const input = form.querySelector('[data-imagem-input]');
        if (input) {
            input.addEventListener('change', function () {
                const arquivo = input.files[0];
                if (!arquivo) return;
                const limite = parseInt(input.dataset.limite, 10);
                if (limite && arquivo.size > limite) {
                    alert('A imagem tem ' + (arquivo.size / 1048576).toFixed(1) + ' MB. O limite é ' + (limite / 1048576).toFixed(1) + ' MB.');
                    input.value = '';
                    return;
                }
                const nomeArquivo = form.querySelector('[data-file-name]');
                if (nomeArquivo) nomeArquivo.textContent = arquivo.name;
                const img = form.querySelector('[data-preview-img]');
                const vazio = form.querySelector('[data-preview-vazio]');
                if (img) { img.src = URL.createObjectURL(arquivo); img.hidden = false; }
                if (vazio) vazio.hidden = true;
            });
        }
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            travarBotao(form, true);
            const data = await enviar('/api/empreendimento_salvar.php', new FormData(form));
            travarBotao(form, false);
            if (data) location.href = '/pages/empreendimento.php?id=' + data.id + '&salvo=1';
        });
    });

    function currentRoletaId() {
        var el = document.querySelector('[data-roleta-id]');
        return el ? el.dataset.roletaId : (new URLSearchParams(location.search).get('id') || '');
    }
    async function roletaCorretorAcao(roletaId, usuarioId, acao) {
        const body = new URLSearchParams({ roleta_id: roletaId, usuario_id: usuarioId, acao: acao, csrf_token: csrfToken() });
        const r = await fetch('/api/roleta_corretor.php', { method: 'POST', body: body });
        const data = await r.json().catch(function () { return {}; });
        if (r.ok && data.ok) { location.reload(); } else { alert(data.erro || 'Não foi possível atualizar.'); }
    }
    async function roletaEmpreendimentoAcao(roletaId, empreendimentoId, acao) {
        const body = new URLSearchParams({ roleta_id: roletaId, empreendimento_id: empreendimentoId, acao: acao, csrf_token: csrfToken() });
        const r = await fetch('/api/roleta_empreendimento.php', { method: 'POST', body: body });
        const data = await r.json().catch(function () { return {}; });
        if (r.ok && data.ok) { location.reload(); } else { alert(data.erro || 'Não foi possível atualizar.'); }
    }
})();

// ======================================================================
// CRM (Etapa 3) — formulários de API, ações rápidas, modais, abas.
// Todas as escritas vão como FormData (nunca JSON) com csrf_token.
// ======================================================================

// Aviso rápido no rodapé. persistir=true guarda para depois do reload.
function wmToast(msg, persistir) {
    if (persistir) {
        try { sessionStorage.setItem('wm-toast', msg); } catch (e) { /* sem storage */ }
        return;
    }
    var t = document.getElementById('toast');
    if (!t) { alert(msg); return; }
    t.textContent = msg;
    t.hidden = false;
    clearTimeout(wmToast._timer);
    wmToast._timer = setTimeout(function () { t.hidden = true; }, 4200);
}

(function () {
    try {
        var pend = sessionStorage.getItem('wm-toast');
        if (pend) { sessionStorage.removeItem('wm-toast'); wmToast(pend); }
    } catch (e) { /* sem storage */ }

    function abrirModal(id) { var el = document.getElementById(id); if (el) { el.hidden = false; var f = el.querySelector('input:not([type=hidden]):not([type=radio]), select, textarea'); if (f) setTimeout(function () { f.focus(); }, 30); } }
    function fecharModal(el) { var o = el.closest('.modal-overlay'); if (o) o.hidden = true; }

    async function postar(url, fd) {
        if (!fd.has('csrf_token')) fd.append('csrf_token', csrfToken());
        var r;
        try { r = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }); }
        catch (e) { return { status: 0, json: { ok: false, erro: 'Sem conexão. Tente de novo.' } }; }
        var j = await r.json().catch(function () { return { ok: false, erro: 'Resposta inválida do servidor (' + r.status + ').' }; });
        return { status: r.status, json: j };
    }

    // ---------------------------------------------------- [data-api-form]
    document.querySelectorAll('form[data-api-form]').forEach(function (form) {
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            var alerta = form.querySelector('[data-form-alerta]');
            if (alerta) { alerta.hidden = true; alerta.innerHTML = ''; }
            var arquivo = form.querySelector('input[type=file][data-limite]');
            if (arquivo && arquivo.files[0] && arquivo.files[0].size > Number(arquivo.dataset.limite)) {
                wmToast('Arquivo maior que o limite permitido.');
                return;
            }
            var btn = form.querySelector('[type=submit]');
            if (btn) btn.disabled = true;
            var res = await postar(form.getAttribute('action'), new FormData(form));
            if (btn) btn.disabled = false;
            var j = res.json;
            if (j.ok && j.roleta) {
                // Lead enviado para a roleta: mostra quem recebeu (ou a fila) aqui mesmo.
                var caixa = form.querySelector('[data-form-resultado]');
                if (caixa) {
                    caixa.innerHTML = '';
                    caixa.className = 'roleta-resultado ' + (j.roleta === 'distribuido' ? 'is-ok' : 'is-fila');
                    var p = document.createElement('p');
                    p.textContent = j.mensagem;
                    caixa.appendChild(p);
                    if (j.redirecionar) {
                        var link = document.createElement('a');
                        link.href = j.redirecionar; link.className = 'btn btn-secondary';
                        link.textContent = 'Abrir a ficha do lead';
                        caixa.appendChild(link);
                    }
                    caixa.hidden = false;
                    caixa.scrollIntoView({ block: 'center' });
                }
                if (!caixa) wmToast(j.mensagem);
                form.reset();
                form.dispatchEvent(new Event('change'));
                return;
            }
            if (j.ok) {
                var destino = form.dataset.redirecionar;
                if (destino) {
                    wmToast(j.mensagem || 'Salvo.', true);
                    location.href = destino.replace('{id}', encodeURIComponent(j.id || ''));
                } else {
                    wmToast(j.mensagem || 'Salvo.', true);
                    location.reload();
                }
                return;
            }
            var msg = j.erro || 'Não foi possível salvar.';
            if (alerta) {
                alerta.textContent = msg;
                if (res.status === 409 && j.lead_id) {
                    var a = document.createElement('a');
                    a.href = '/pages/lead.php?id=' + encodeURIComponent(j.lead_id);
                    a.textContent = ' Abrir o cadastro existente';
                    alerta.appendChild(a);
                }
                alerta.hidden = false;
            } else {
                wmToast(msg);
            }
        });
    });


    // ---------------------------------- Novo lead: "Distribuir pela roleta"
    (function () {
        var opcao = document.querySelector('[data-opcao-roleta]');
        if (!opcao) return;
        var form = opcao.closest('form');
        var emp = form.querySelector('[name=empreendimento_id]');
        var caixa = opcao.querySelector('[data-distribuir-roleta]');
        var resp = form.querySelector('[data-campo-responsavel]');
        var ultimoEmp = null;
        function atualizar() {
            var op = emp.options[emp.selectedIndex];
            var tem = !!(op && op.dataset.temRoleta);
            opcao.hidden = !tem;
            // Padrão para gestores: ligado quando o empreendimento tem roleta ativa.
            if (emp.value !== ultimoEmp) { caixa.checked = tem; ultimoEmp = emp.value; }
            if (!tem) caixa.checked = false;
            if (resp) resp.hidden = caixa.checked;
        }
        emp.addEventListener('change', atualizar);
        caixa.addEventListener('change', function () { if (resp) resp.hidden = caixa.checked; });
        form.addEventListener('change', function (e) { if (e.target === form) { ultimoEmp = null; atualizar(); } });
        atualizar();
    })();

    // ------------------------------------- [data-api-acao] (botões simples)
    var reservados = ['apiAcao', 'confirmar', 'irPara'];
    document.querySelectorAll('[data-api-acao]').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            if (btn.dataset.confirmar && !confirm(btn.dataset.confirmar)) return;
            var fd = new FormData();
            Object.keys(btn.dataset).forEach(function (k) { if (reservados.indexOf(k) === -1) fd.append(k, btn.dataset[k]); });
            btn.disabled = true;
            var res = await postar(btn.dataset.apiAcao, fd);
            btn.disabled = false;
            if (res.json.ok) {
                wmToast(res.json.mensagem || 'Feito.', true);
                if (btn.dataset.irPara) location.href = btn.dataset.irPara; else location.reload();
            } else {
                wmToast(res.json.erro || 'Não foi possível concluir.');
            }
        });
    });

    // --------------------------- Registro automático de ligação/WhatsApp
    document.querySelectorAll('[data-registrar]').forEach(function (link) {
        link.addEventListener('click', function () {
            var fd = new FormData();
            fd.append('lead_id', link.dataset.lead);
            fd.append('tipo', link.dataset.registrar);
            fd.append('csrf_token', csrfToken());
            if (!(navigator.sendBeacon && navigator.sendBeacon('/api/lead_registrar.php', fd))) {
                fetch('/api/lead_registrar.php', { method: 'POST', body: fd, keepalive: true });
            }
        });
    });

    // ----------------------------------------------- Modal "Mover etapa"
    var leadMovendo = null;
    document.querySelectorAll('[data-mover-lead]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            leadMovendo = btn.dataset.moverLead;
            var modal = document.getElementById('modal-mover');
            if (!modal) return;
            var nome = modal.querySelector('[data-mover-nome]');
            if (nome) nome.textContent = btn.dataset.leadNome || '';
            modal.querySelectorAll('[data-destino-etapa]').forEach(function (op) {
                var atual = op.dataset.destinoEtapa === btn.dataset.etapaAtual;
                op.classList.toggle('is-current', atual);
                op.disabled = atual;
            });
            modal.hidden = false;
        });
    });
    document.querySelectorAll('[data-destino-etapa]').forEach(function (op) {
        op.addEventListener('click', function () {
            if (!leadMovendo) return;
            op.disabled = true;
            window.wmMoverLead(leadMovendo, op.dataset.destinoEtapa);
        });
    });

    // ------------------------------------------------- Transferir lead(s)
    function abrirTransferencia(ids) {
        var modal = document.getElementById('modal-transferir');
        if (!modal || !ids.length) return;
        var box = modal.querySelector('[data-transferir-ids]');
        box.innerHTML = '';
        ids.forEach(function (id) {
            var i = document.createElement('input');
            i.type = 'hidden'; i.name = 'lead_ids[]'; i.value = id;
            box.appendChild(i);
        });
        modal.querySelector('h2').textContent = ids.length > 1 ? 'Transferir ' + ids.length + ' leads' : 'Transferir lead';
        modal.hidden = false;
    }
    document.querySelectorAll('[data-transferir-lead]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var d = btn.closest('details'); if (d) d.open = false;
            abrirTransferencia([btn.dataset.transferirLead]);
        });
    });
    // Vindo da fila de espera ("Atribuir manualmente"): abre a transferência.
    if (new URLSearchParams(location.search).get('transferir') === '1') {
        var btnT = document.querySelector('[data-transferir-lead]');
        if (btnT) abrirTransferencia([btnT.dataset.transferirLead]);
    }
    var bulk = document.querySelector('[data-bulk-bar]');
    function selecionados() { return Array.prototype.map.call(document.querySelectorAll('[data-selecionar-lead]:checked'), function (c) { return c.value; }); }
    function atualizarBulk() {
        if (!bulk) return;
        var n = selecionados().length;
        bulk.hidden = n === 0;
        bulk.querySelector('[data-bulk-contagem]').textContent = n + (n === 1 ? ' selecionado' : ' selecionados');
    }
    document.querySelectorAll('[data-selecionar-lead]').forEach(function (c) { c.addEventListener('change', atualizarBulk); });
    var todos = document.querySelector('[data-selecionar-todos]');
    if (todos) todos.addEventListener('change', function () {
        document.querySelectorAll('[data-selecionar-lead]').forEach(function (c) { c.checked = todos.checked; });
        atualizarBulk();
    });
    var btnBulk = document.querySelector('[data-transferir-selecionados]');
    if (btnBulk) btnBulk.addEventListener('click', function () { abrirTransferencia(selecionados()); });

    // ------------------------------------------------------------ Agenda
    document.querySelectorAll('[data-agenda-acao]').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            var acao = btn.dataset.agendaAcao;
            var fd = new FormData();
            fd.append('id', btn.dataset.id);
            fd.append('acao', acao);
            if (acao === 'cancelar' && !confirm('Cancelar este compromisso?')) return;
            if (acao === 'cliente_ausente' && !confirm('Registrar que o cliente não compareceu?')) return;
            if (acao === 'realizado' && btn.dataset.tipo === 'visita' && btn.dataset.comLead === '1') {
                if (confirm('Visita realizada! Mover o lead para "Visita realizada"?')) fd.append('mover_etapa', '1');
            }
            btn.disabled = true;
            var res = await postar('/api/agenda_status.php', fd);
            btn.disabled = false;
            if (res.json.ok) { wmToast(res.json.mensagem || 'Compromisso atualizado.', true); location.reload(); }
            else wmToast(res.json.erro || 'Não foi possível atualizar.');
        });
    });
    document.querySelectorAll('[data-reagendar]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var modal = document.getElementById('modal-reagendar');
            if (!modal) return;
            modal.querySelector('input[name=id]').value = btn.dataset.reagendar;
            modal.querySelector('[data-reagendar-titulo]').textContent = btn.dataset.titulo || '';
            modal.querySelector('input[name=novo_inicio]').value = btn.dataset.inicio || '';
            modal.hidden = false;
        });
    });
    function atualizarSoVisita(form) {
        var marcado = form.querySelector('[data-tipo-agenda]:checked');
        form.querySelectorAll('[data-so-visita]').forEach(function (el) {
            var visita = marcado && marcado.value === 'visita';
            el.hidden = !visita;
            var cb = el.querySelector('input'); if (cb) cb.disabled = !visita;
        });
    }
    document.querySelectorAll('[data-tipo-agenda]').forEach(function (r) {
        r.addEventListener('change', function () { atualizarSoVisita(r.form); });
    });
    document.querySelectorAll('[data-agendar-tipo]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var modal = document.getElementById('modal-agendar');
            if (!modal) return;
            var r = modal.querySelector('[data-tipo-agenda][value="' + btn.dataset.agendarTipo + '"]');
            if (r) { r.checked = true; atualizarSoVisita(r.form); }
        });
    });
    document.querySelectorAll('#modal-agendar form').forEach(atualizarSoVisita);

    // Atalhos de data ("Amanhã 10h"…) para inputs datetime-local.
    function dtLocal(d) { var p = function (n) { return String(n).padStart(2, '0'); }; return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes()); }
    document.querySelectorAll('[data-data-rapida]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var d = new Date(); var tipo = btn.dataset.dataRapida;
            if (tipo === 'hoje18') d.setHours(18, 0, 0, 0);
            if (tipo === 'amanha10') { d.setDate(d.getDate() + 1); d.setHours(10, 0, 0, 0); }
            if (tipo === '2dias10') { d.setDate(d.getDate() + 2); d.setHours(10, 0, 0, 0); }
            if (tipo === 'semana10') { d.setDate(d.getDate() + 7); d.setHours(10, 0, 0, 0); }
            var input = btn.closest('.field').querySelector('input[type=datetime-local]');
            if (input) input.value = dtLocal(d);
            btn.parentNode.querySelectorAll('.chip-btn').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
        });
    });

    // ------------------------------------------------ Registro rápido (ficha)
    document.querySelectorAll('[data-registro-tipo]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var d = btn.closest('details'); if (d) d.open = false;
            var modal = document.getElementById('modal-registro');
            if (!modal) return;
            var nota = btn.dataset.registroTipo === 'nota';
            modal.querySelector('input[name=tipo]').value = btn.dataset.registroTipo;
            modal.querySelector('[data-registro-titulo]').textContent = nota ? 'Adicionar observação' : 'Registrar contato';
            modal.querySelector('[data-registro-label]').textContent = nota ? 'Observação' : 'O que aconteceu no contato?';
        });
    });
    document.querySelectorAll('.more-menu [data-open-modal]').forEach(function (btn) {
        btn.addEventListener('click', function () { var d = btn.closest('details'); if (d) d.open = false; });
    });

    // ------------------------------------------------------- Abas da ficha
    var abas = document.querySelectorAll('[data-aba]');
    function mostrarAba(chave, empurrar) {
        var achou = false;
        document.querySelectorAll('[data-painel]').forEach(function (p) { var ok = p.dataset.painel === chave; p.hidden = !ok; achou = achou || ok; });
        if (!achou) return false;
        abas.forEach(function (a) { var ok = a.dataset.aba === chave; a.classList.toggle('is-current', ok); a.setAttribute('aria-selected', ok ? 'true' : 'false'); if (ok && a.scrollIntoView) a.scrollIntoView({ block: 'nearest', inline: 'center' }); });
        if (empurrar) { var u = new URL(location.href); u.searchParams.set('aba', chave); u.hash = ''; history.pushState({ aba: chave }, '', u); }
        return true;
    }
    abas.forEach(function (a) {
        a.addEventListener('click', function (e) { if (mostrarAba(a.dataset.aba, true)) e.preventDefault(); });
    });
    document.querySelectorAll('[data-ir-aba]').forEach(function (a) {
        a.addEventListener('click', function (e) { if (mostrarAba(a.dataset.irAba, true)) { e.preventDefault(); window.scrollTo({ top: 0, behavior: 'smooth' }); } });
    });
    if (abas.length) window.addEventListener('popstate', function () { mostrarAba(new URL(location.href).searchParams.get('aba') || 'resumo', false); });

    // Filtro do histórico por tipo.
    document.querySelectorAll('[data-filtro-hist]').forEach(function (chip) {
        chip.addEventListener('click', function () {
            var tipo = chip.dataset.filtroHist;
            document.querySelectorAll('[data-filtro-hist]').forEach(function (c) { c.classList.toggle('is-active', c === chip); });
            document.querySelectorAll('[data-tipo-hist]').forEach(function (it) { it.hidden = tipo !== '' && it.dataset.tipoHist !== tipo; });
        });
    });

    // Nome do arquivo escolhido.
    document.querySelectorAll('input[type=file][data-mostrar-nome]').forEach(function (inp) {
        inp.addEventListener('change', function () {
            var alvo = inp.form.querySelector('[data-file-name]');
            if (alvo) alvo.textContent = inp.files[0] ? inp.files[0].name : 'Nenhum arquivo escolhido';
        });
    });

    // ----------------------------------------------- Kanban: abas no celular
    document.querySelectorAll('[data-coluna-tab]').forEach(function (tab) {
        tab.addEventListener('click', function () {
            var id = tab.dataset.colunaTab;
            document.querySelectorAll('[data-coluna-tab]').forEach(function (t) { var ok = t === tab; t.classList.toggle('is-current', ok); t.setAttribute('aria-selected', ok ? 'true' : 'false'); });
            document.querySelectorAll('.kcol[data-coluna]').forEach(function (c) { c.classList.toggle('is-current', c.dataset.coluna === id); });
            tab.scrollIntoView({ block: 'nearest', inline: 'center' });
            var u = new URL(location.href); u.searchParams.set('coluna', id); history.replaceState(null, '', u);
        });
    });

    document.querySelectorAll('[data-toggle-filtros]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var box = btn.parentNode.querySelector('[data-filtros-extra]');
            if (!box) return;
            var aberto = box.classList.toggle('is-open');
            btn.setAttribute('aria-expanded', aberto ? 'true' : 'false');
        });
    });

    // Filtros que se aplicam sozinhos ao mudar.
    document.querySelectorAll('[data-auto-filtro]').forEach(function (sel) {
        sel.addEventListener('change', function () { if (sel.form) sel.form.submit(); });
    });
})();
