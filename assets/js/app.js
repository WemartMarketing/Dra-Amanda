// WeMart Sales — JS vanilla (sem dependências externas)

(function () {
    async function moverLead(leadId, destino, cardEl) {
        const r = await fetch('/api/lead_mover_etapa.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ lead_id: leadId, etapa: destino }),
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

        document.querySelectorAll('[data-open-modal="modal-usuario"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                formUsuario.reset();
                if (btn.dataset.modo === 'editar') {
                    tituloUsuario.textContent = 'Editar usuário';
                    formUsuario.id.value = btn.dataset.id;
                    formUsuario.nome.value = btn.dataset.nome;
                    formUsuario.email.value = btn.dataset.email;
                    formUsuario.cargo.value = btn.dataset.cargo;
                    formUsuario.equipe_id.value = btn.dataset.equipeId || '';
                    formUsuario.ativo.checked = btn.dataset.ativo === '1';
                    campoAtivo.style.display = 'flex';
                } else {
                    tituloUsuario.textContent = 'Novo usuário';
                    formUsuario.id.value = '';
                    campoAtivo.style.display = 'none';
                }
            });
        });

        formUsuario.addEventListener('submit', async function (e) {
            e.preventDefault();
            const r = await fetch('/api/usuario_salvar.php', { method: 'POST', body: new FormData(formUsuario) });
            const data = await r.json().catch(function () { return {}; });
            if (r.ok && data.ok) { location.reload(); } else { alert(data.erro || 'Não foi possível salvar o usuário.'); }
        });
    }

    document.querySelectorAll('[data-toggle-ativo]').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const acao = btn.dataset.ativoAtual === '1' ? 'desativar' : 'ativar';
            if (!confirm('Tem certeza que deseja ' + acao + ' este usuário?')) return;
            const r = await fetch('/api/usuario_toggle_ativo.php', { method: 'POST', body: new URLSearchParams({ id: btn.dataset.toggleAtivo }) });
            const data = await r.json().catch(function () { return {}; });
            if (r.ok && data.ok) { location.reload(); } else { alert(data.erro || 'Não foi possível atualizar.'); }
        });
    });

    function currentRoletaId() {
        return new URLSearchParams(location.search).get('id') || '';
    }
    async function roletaCorretorAcao(roletaId, usuarioId, acao) {
        const body = new URLSearchParams({ roleta_id: roletaId, usuario_id: usuarioId, acao: acao });
        const r = await fetch('/api/roleta_corretor.php', { method: 'POST', body: body });
        const data = await r.json().catch(function () { return {}; });
        if (r.ok && data.ok) { location.reload(); } else { alert(data.erro || 'Não foi possível atualizar.'); }
    }
    async function roletaEmpreendimentoAcao(roletaId, empreendimentoId, acao) {
        const body = new URLSearchParams({ roleta_id: roletaId, empreendimento_id: empreendimentoId, acao: acao });
        const r = await fetch('/api/roleta_empreendimento.php', { method: 'POST', body: body });
        const data = await r.json().catch(function () { return {}; });
        if (r.ok && data.ok) { location.reload(); } else { alert(data.erro || 'Não foi possível atualizar.'); }
    }
})();
