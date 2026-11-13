// Migração para API: intercepta envios do calendário e feeds com suporte a notificações amigáveis (toast)
(function () {
    const ref = encodeURIComponent(CL.data().hash || '');

    // Notificações amigáveis (toast) para mensagens do calendário (flash / SSR)
    function checkFlashAlerts() {
        document.querySelectorAll('[data-flash-alert]').forEach(flashEl => {
            if (!window.CL || typeof window.CL.toast !== 'function') return;
            const toneAttr = (flashEl.getAttribute('data-flash-tone') || 'ok').toLowerCase();
            const type = toneAttr === 'error' ? 'error' : (toneAttr === 'warning' ? 'warning' : (toneAttr === 'info' ? 'info' : 'success'));
            const text = flashEl.textContent.trim();
            if (!text) return;

            let title = 'Calendário';
            if (type === 'error') {
                title = 'Atenção';
            } else if (type === 'warning') {
                title = 'Aviso';
            } else if (text.toLowerCase().includes('sincroniza') || text.toLowerCase().includes('sync')) {
                title = 'Sincronização';
            } else if (text.toLowerCase().includes('feed') || text.toLowerCase().includes('link') || text.toLowerCase().includes('externo')) {
                title = 'Calendário externo';
            } else {
                title = 'Alterações salvas';
            }

            CL.toast(type, title, text);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', checkFlashAlerts);
    } else {
        checkFlashAlerts();
    }

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
            const btn = ev.submitter || document.activeElement;
            const data = Object.fromEntries(new FormData(calForm).entries());
            if (btn && btn.name === 'acao') {
                data.acao = btn.value;
            }
            if (data.acao === 'cotacao' || (btn && btn.hasAttribute && btn.hasAttribute('data-cal-quote'))) {
                return;
            }
            ev.preventDefault();
            try {
                await CL.api('/api/imoveis/' + ref + '/calendario', { method: 'POST', body: data, error: 'Erro ao salvar calendário' });
                // Recarrega para refletir as mudanças no grid (a mensagem flash virá com a página e abrirá o toast)
                reloadPage();
            } catch (e) {
                showError(calForm, e);
                if (window.CL && typeof window.CL.toast === 'function') {
                    const title = (e.status === 409) ? 'Conflito de datas' : 'Erro ao salvar calendário';
                    CL.toast('error', title, e.message || 'Verifique as informações do calendário.');
                }
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
                if (window.CL && typeof window.CL.toast === 'function') {
                    CL.toast('error', 'Erro ao importar feed', e.message || 'Verifique o link informado.');
                }
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
                if (window.CL && typeof window.CL.toast === 'function') {
                    CL.toast('error', 'Erro ao remover calendário', e.message || 'Não foi possível remover o calendário.');
                } else {
                    reloadPage();
                }
            }
        });
    });

    // Sincronizar feed agora via API com notificação imediata
    document.querySelectorAll('form[action*="/ical/"][action*="/sincronizar"]').forEach(form => {
        form.addEventListener('submit', async function (ev) {
            ev.preventDefault();
            const match = form.action.match(/\/ical\/(\d+)\/sincronizar/);
            if (!match) return;
            try {
                const res = await CL.api('/api/imoveis/' + ref + '/ical/' + match[1] + '/sincronizar', { method: 'POST', error: 'Erro ao sincronizar' });
                if (window.CL && typeof window.CL.toast === 'function') {
                    CL.toast('success', 'Sincronização', res.message || 'Sincronização iniciada. Os dados serão atualizados em alguns segundos.');
                }
            } catch (e) {
                if (window.CL && typeof window.CL.toast === 'function') {
                    CL.toast('error', 'Erro na sincronização', e.message || 'Falha ao iniciar sincronização.');
                }
            }
        });
    });
})();
