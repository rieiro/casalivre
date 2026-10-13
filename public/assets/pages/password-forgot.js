(function () {
    const form = document.getElementById('forgot-form');
    const ok = document.getElementById('forgot-ok');
    form.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        const data = Object.fromEntries(new FormData(form).entries());
        try {
            await CL.api('/api/auth/senha/esqueci', { method: 'POST', body: data });
            ok.hidden = false;
        } catch (e) {
            // Resposta neutra (não revela se o e-mail existe); só falha de rede vira alerta.
            if (e.status) {
                ok.hidden = false;
            } else {
                alert(e.message);
            }
        }
    });
})();
