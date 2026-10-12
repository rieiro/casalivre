<section class="wrap">
    <form class="panel" id="login-form" method="post" action="/entrar">
        <h1><?= e(t('auth.login_title')) ?></h1>
        <p class="error" id="login-error" hidden></p>
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label><?= e(t('auth.email')) ?> <input type="email" name="email" required></label>
        <label><?= e(t('auth.password')) ?> <input type="password" name="password" required></label>
        <button class="primary" type="submit"><?= e(t('auth.submit')) ?></button>
        <p><a href="/senha/esqueci"><?= e(t('auth.forgot')) ?></a></p>
    </form>
</section>
<script>
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
</script>