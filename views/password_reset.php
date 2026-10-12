<section class="wrap">
    <form class="panel" id="reset-form" method="post" action="/senha/redefinir">
        <h1><?= e(t('auth.reset_title')) ?></h1>
        <p class="error" id="reset-error" hidden></p>
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <label><?= e(t('auth.reset_new')) ?> <input type="password" name="password" minlength="8" required></label>
        <label><?= e(t('auth.password2')) ?> <input type="password" name="password_confirm" minlength="8" required></label>
        <button class="primary" type="submit"><?= e(t('auth.reset_save')) ?></button>
    </form>
</section>
<script>
(function () {
    const form = document.getElementById('reset-form');
    const errorEl = document.getElementById('reset-error');
    form.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        errorEl.hidden = true;
        const data = Object.fromEntries(new FormData(form).entries());
        try {
            const res = await fetch('/api/auth/senha/redefinir', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': data._csrf || '' },
                body: JSON.stringify(data)
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                errorEl.textContent = json.error || 'Erro ao redefinir';
                errorEl.hidden = false;
                return;
            }
            alert('Senha alterada. Faça login.');
            window.location.href = '/entrar';
        } catch (e) {
            errorEl.textContent = 'Falha de conexão';
            errorEl.hidden = false;
        }
    });
})();
</script>