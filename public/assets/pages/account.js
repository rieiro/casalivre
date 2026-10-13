(function () {
    const csrf = CL.data().csrf;
    const form = document.getElementById('account-form');
    const errorEl = document.getElementById('account-error');
    const okEl = document.getElementById('account-ok');
    form.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        errorEl.hidden = true;
        okEl.hidden = true;
        const data = Object.fromEntries(new FormData(form).entries());
        try {
            const res = await fetch('/api/users/eu', {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify(data)
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                errorEl.textContent = json.error || 'Erro ao salvar';
                errorEl.hidden = false;
                return;
            }
            okEl.hidden = false;
        } catch (e) {
            errorEl.textContent = 'Falha de conexão';
            errorEl.hidden = false;
        }
    });
    document.getElementById('delete-account').addEventListener('submit', async function (ev) {
        ev.preventDefault();
        if (!confirm(CL.data().deleteConfirm)) return;
        try {
            const res = await fetch('/api/users/eu', {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: { 'X-CSRF-Token': csrf }
            });
            if (res.ok) {
                window.location.href = '/';
            } else {
                alert('Erro ao excluir conta');
            }
        } catch (e) {
            alert('Falha de conexão');
        }
    });
})();
