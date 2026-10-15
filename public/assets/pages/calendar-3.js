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

    // Recarrega a página; o aviso (ok ou erro) vem do flash na sessão gravado pela API (sem ?msg= na URL).
    function reloadPage() {
        window.location.reload();
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
                await CL.api('/api/imoveis/' + ref + '/calendario', { method: 'POST', body: data, error: 'Erro ao salvar calendário' });
                // Recarrega para refletir as mudanças no grid
                reloadPage();
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
                    reloadPage();
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
                reloadPage();
            }
        });
    });
})();
