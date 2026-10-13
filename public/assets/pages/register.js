(function () {
    const form = document.getElementById('register-form');
    const errorEl = document.getElementById('register-error');
    const successEl = document.getElementById('register-success');
    const submitBtn = document.getElementById('register-submit');
    form.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        errorEl.hidden = true;
        successEl.hidden = true;
        submitBtn.disabled = true;
        const data = Object.fromEntries(new FormData(form).entries());
        let json;
        try {
            json = await CL.api('/api/users', { method: 'POST', body: data, error: 'Erro ao cadastrar.' });
        } catch (e) {
            errorEl.textContent = e.message;
            errorEl.hidden = false;
            submitBtn.disabled = false;
            return;
        }
        form.querySelectorAll('input, button').forEach(el => { if (el.id !== 'register-submit') el.disabled = true; });
        if (json.verification_link) {
            // Só em APP_ENV=dev/local a API devolve o link.
            document.getElementById('verify-link').href = json.verification_link;
            document.getElementById('verify-link-row').hidden = false;
            document.getElementById('verify-body').hidden = false;
            document.getElementById('verify-email').hidden = true;
        }
        document.getElementById('verify-expires').textContent = json.expires || '';
        successEl.hidden = false;
        submitBtn.hidden = true;
    });
})();
