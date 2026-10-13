// Migração para API: intercepta envios do calendário e feeds
(function () {
    const hash = CL.data().hash;
    const csrf = CL.data().csrf;

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
                const res = await fetch('/api/imoveis/' + hash + '/calendario', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify(data)
                });
                const json = await res.json().catch(() => ({}));
                if (!res.ok) {
                    alert(json.error || 'Erro ao salvar calendário');
                    return;
                }
                // Recarrega para refletir as mudanças no grid
                const url = new URL(window.location.href);
                url.searchParams.set('msg', json.message || 'Salvo');
                window.location.href = url.toString();
            } catch (e) {
                alert('Falha de conexão');
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
                const res = await fetch('/api/imoveis/' + hash + '/ical/importar', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify({
                        url: data.external_ical_url || '',
                        title: data.feed_title || ''
                    })
                });
                const json = await res.json().catch(() => ({}));
                if (!res.ok) {
                    alert(json.error || 'Erro ao importar feed');
                    return;
                }
                window.location.reload();
            } catch (e) {
                alert('Falha de conexão');
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
                const res = await fetch('/api/imoveis/' + hash + '/ical/' + match[1], {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    headers: { 'X-CSRF-Token': csrf }
                });
                const json = await res.json().catch(() => ({}));
                if (!res.ok) {
                    alert(json.error || 'Erro ao remover');
                    return;
                }
                window.location.reload();
            } catch (e) {
                alert('Falha de conexão');
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
