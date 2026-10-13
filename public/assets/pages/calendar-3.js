// Migração para API: intercepta envios do calendário e feeds
(function () {
    const ref = encodeURIComponent(CL.data().hash || '');

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
                const url = new URL(window.location.href);
                url.searchParams.set('msg', json.message || 'Salvo');
                window.location.href = url.toString();
            } catch (e) {
                alert(e.message);
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
                    body: { url: data.external_ical_url || '', title: data.feed_title || '' },
                    error: 'Erro ao importar feed',
                });
                window.location.reload();
            } catch (e) {
                alert(e.message);
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
                alert(e.message);
            }
        });
    });

    // Mostra mensagem vinda da query string após reload
    const params = new URLSearchParams(window.location.search);
    const msg = params.get('msg');
    if (msg) {
        const alert = document.createElement('p');
        alert.className = 'alert ok';
        alert.textContent = msg;
        document.querySelector('.wrap').insertBefore(alert, document.querySelector('.cal-board'));
        params.delete('msg');
        history.replaceState(null, '', window.location.pathname + (params.toString() ? '?' + params : ''));
    }
})();
