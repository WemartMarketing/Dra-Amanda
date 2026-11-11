// WeMart Sales — JS vanilla (sem dependências externas)

// Token CSRF da sessão atual (ver <meta name="csrf-token"> em
// includes/layout_start.php e login.php) — toda escrita que não parte
// de um <form> com csrf_field() precisa anexar isto manualmente.
function csrfToken() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.content : '';
}

(function () {
    async function moverLead(leadId, destino, cardEl) {
        const r = await fetch('/api/lead_mover_etapa.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ lead_id: leadId, etapa: destino, csrf_token: csrfToken() }),
        });
        if (r.ok) {
            location.reload();
        } else {
            alert('Não foi possível mover este lead.');
        }
    }

    // Botões "Voltar"/"Avançar" nos cards (funcionam em qualquer dispositivo,
    // inclusive sem suporte a drag-and-drop no celular).
    document.querySelectorAll('[data-move]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            moverLead(this.dataset.move, this.dataset.destino, this.closest('.kcard'));
        });
    });

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
                alert(data.erro || 'Não foi possível salvar a roleta.');
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
        return new URLSearchParams(location.search).get('id') || '';
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
