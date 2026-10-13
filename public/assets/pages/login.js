(function () {
    const form = document.getElementById('login-form');
    const errorEl = document.getElementById('login-error');
    form.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        errorEl.hidden = true;
        const data = Object.fromEntries(new FormData(form).entries());
        try {
            const res = await fetch('/api/sessao', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': data._csrf || '' },
                body: JSON.stringify(data)
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                errorEl.textContent = json.error || 'Erro no login';
                errorEl.hidden = false;
                return;
            }
            window.location.href = '/painel';
        } catch (e) {
            errorEl.textContent = 'Falha de conexão';
            errorEl.hidden = false;
        }
    });
})();
