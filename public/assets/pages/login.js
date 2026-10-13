(function () {
    const form = document.getElementById('login-form');
    const errorEl = document.getElementById('login-error');
    form.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        errorEl.hidden = true;
        const data = Object.fromEntries(new FormData(form).entries());
        try {
            await CL.api('/api/sessao', { method: 'POST', body: data, error: 'Erro no login' });
            window.location.href = '/painel';
        } catch (e) {
            errorEl.textContent = e.message;
            errorEl.hidden = false;
        }
    });
})();
