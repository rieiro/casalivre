(function () {
    const form = document.getElementById('account-form');
    const errorEl = document.getElementById('account-error');
    const okEl = document.getElementById('account-ok');
    form.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        errorEl.hidden = true;
        okEl.hidden = true;
        const data = Object.fromEntries(new FormData(form).entries());
        try {
            const res = await CL.api('/api/users/eu', { method: 'PUT', body: data, error: 'Erro ao salvar' });
            okEl.textContent = (res && res.aviso) || 'Salvo.';
            okEl.hidden = false;
        } catch (e) {
            errorEl.textContent = e.message;
            errorEl.hidden = false;
        }
    });
    document.getElementById('delete-account').addEventListener('submit', async function (ev) {
        ev.preventDefault();
        if (!confirm(CL.data().deleteConfirm)) return;
        try {
            await CL.api('/api/users/eu', { method: 'DELETE', error: 'Erro ao excluir conta' });
            window.location.href = '/';
        } catch (e) {
            alert(e.message);
        }
    });
})();
