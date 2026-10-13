(function () {
    const form = document.getElementById('forgot-form');
    const ok = document.getElementById('forgot-ok');
    form.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        const data = Object.fromEntries(new FormData(form).entries());
        try {
            await fetch('/api/auth/senha/esqueci', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': data._csrf || '' },
                body: JSON.stringify(data)
            });
            ok.hidden = false;
        } catch (e) {
            alert('Falha de conexão');
        }
    });
})();
