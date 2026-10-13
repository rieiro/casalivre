(function () {
    const form = document.getElementById('reset-form');
    const errorEl = document.getElementById('reset-error');
    form.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        errorEl.hidden = true;
        const data = Object.fromEntries(new FormData(form).entries());
        try {
            await CL.api('/api/auth/senha/redefinir', { method: 'POST', body: data, error: 'Erro ao redefinir' });
            alert('Senha alterada. Faça login.');
            window.location.href = '/entrar';
        } catch (e) {
            errorEl.textContent = e.message;
            errorEl.hidden = false;
        }
    });
})();
