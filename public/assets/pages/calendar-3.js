// Migração para API: intercepta envios do calendário e feeds
(function () {
    const ref = encodeURIComponent(CL.data().hash || '');

    // Erro na tela (sem alert): mensagem da API (json.error) no topo do formulário e, por campo, ao lado do input
    // (json.erros = {campo: msg}, os mesmos nomes do formulário web).
    function showError(form, e) {
        form.querySelectorAll('[data-api-error]').forEach(el => el.remove());
        form.querySelectorAll('[aria-invalid]').forEach(el => el.removeAttribute('aria-invalid'));
        const box = document.createElement('p');
        box.className = 'alert error';
        box.setAttribute('data-api-error', '');
        box.setAttribute('role', 'alert');
        box.textContent = e.message;
        const head = form.querySelector('h2');
        if (head) head.after(box); else form.prepend(box);
        const erros = (e.data && typeof e.data.erros === 'object' && e.data.erros) || {};
        Object.keys(erros).forEach(campo => {
            const input = form.querySelector('[name="' + CSS.escape(campo) + '"]');
            if (!input) return;
            input.setAttribute('aria-invalid', 'true');
            const msg = document.createElement('small');
            msg.className = 'alert error fine';
            msg.setAttribute('data-api-error', '');
            msg.textContent = String(erros[campo]);
            input.after(msg);
        });
    }

    // Recarrega a página com uma mensagem (ok ou erro) mostrada no topo após o reload.
    function reloadWith(msg, tone) {
        const url = new URL(window.location.href);
        url.searchParams.set('msg', msg);
        if (tone === 'error') url.searchParams.set('tone', 'error'); else url.searchParams.delete('tone');
        window.location.href = url.toString();
    }

    // Formulário principal do calendário
    const calForm = document.querySelector('[data-cal-form]');
    if (calForm) {
        calForm.addEventListener('submit', async function (ev) {
            ev.preventDefault();
            const btn = ev.submitter;
            const data = Object.fromEntries(new FormData(calForm).entries());
            if (btn && btn.name === 'acao') {
                data.acao = btn.value;
            }
            try {
                const json = await CL.api('/api/imoveis/' + ref + '/calendario', { method: 'POST', body: data, error: 'Erro ao salvar calendário' });
                // Recarrega para refletir as mudanças no grid
                reloadWith(json.message || 'Salvo');
            } catch (e) {
                showError(calForm, e);
            }
        });
    }

    // Importar feed
    const feedForm = document.querySelector('[data-feed-form]');
    if (feedForm) {
        feedForm.addEventListener('submit', async function (ev) {
            ev.preventDefault();
            const data = Object.fromEntries(new FormData(feedForm).entries());
            try {
                await CL.api('/api/imoveis/' + ref + '/ical/importar', {
                    method: 'POST',
                    // Mesmos nomes do formulário web (a API recusa url/title).
                    body: { external_ical_url: data.external_ical_url || '', feed_title: data.feed_title || '' },
                    error: 'Erro ao importar feed',
                });
                window.location.reload();
            } catch (e) {
                if (e.status === 502) {
                    // Feed salvo, mas o download falhou: recarrega para mostrar o feed (NOK) e o erro no topo.
                    reloadWith(e.message, 'error');
                    return;
                }
                showError(feedForm, e);
            }
        });
    }

    // Excluir feed (forms dentro da tabela)
    document.querySelectorAll('form[action*="/ical/"][action*="/excluir"]').forEach(form => {
        form.addEventListener('submit', async function (ev) {
            ev.preventDefault();
            const match = form.action.match(/\/ical\/(\d+)\/excluir/);
            if (!match) return;
            if (!confirm('Remover este calendário?')) return;
            try {
                await CL.api('/api/imoveis/' + ref + '/ical/' + match[1], { method: 'DELETE', error: 'Erro ao remover' });
                window.location.reload();
            } catch (e) {
                reloadWith(e.message, 'error');
            }
        });
    });

    // Mostra mensagem vinda da query string após reload
    const params = new URLSearchParams(window.location.search);
    const msg = params.get('msg');
    if (msg) {
        const alert = document.createElement('p');
        alert.className = params.get('tone') === 'error' ? 'alert error' : 'alert ok';
        alert.textContent = msg;
        document.querySelector('.wrap').insertBefore(alert, document.querySelector('.cal-board'));
        params.delete('msg');
        params.delete('tone');
        history.replaceState(null, '', window.location.pathname + (params.toString() ? '?' + params : ''));
    }
})();
