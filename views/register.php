<section class="wrap">
    <form class="panel" id="register-form" method="post" action="/cadastro">
        <h1><?= e(t('auth.register_title')) ?></h1>
        <p class="muted"><?= e(t('auth.register_lead')) ?></p>
        <p class="error" id="register-error" hidden></p>
        <div id="register-success" hidden>
            <h2><?= e(t('auth.pending_title')) ?></h2>
            <p id="verify-body" hidden><?= e(t('auth.pending_body')) ?></p>
            <p id="verify-email"><?= e(t('auth.pending_email')) ?></p>
            <p id="verify-link-row" hidden><a id="verify-link" href="#"><?= e(t('auth.open_link')) ?></a></p>
            <p class="muted"><?= e(t('auth.expires')) ?> <span id="verify-expires"></span></p>
        </div>
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <?php $old = $old ?? []; ?>
        <label><?= e(t('auth.name')) ?> <input name="name" required value="<?= e((string) ($old['name'] ?? '')) ?>"></label>
        <label><?= e(t('auth.cpf')) ?> <input name="cpf" required placeholder="000.000.000-00" value="<?= e((string) ($old['cpf'] ?? '')) ?>"></label>
        <label><?= e(t('auth.cep')) ?> <input name="cep" inputmode="numeric" maxlength="9" required data-cep placeholder="00000-000" value="<?= e((string) ($old['cep'] ?? '')) ?>"></label>
        <label><?= e(t('auth.street')) ?> <input name="street" readonly required data-street value=""></label>
        <label><?= e(t('form.city')) ?> <input name="city" readonly required data-city value="<?= e(city_label_for_cep((string) ($old['cep'] ?? ''))) ?>"></label>
        <div class="row">
            <label><?= e(t('auth.number')) ?> <input name="address_number" required value="<?= e((string) ($old['address_number'] ?? '')) ?>"></label>
            <label><?= e(t('auth.complement')) ?> <input name="address_complement" value="<?= e((string) ($old['address_complement'] ?? '')) ?>"></label>
        </div>
        <p class="muted" data-cep-status><?= e(t('auth.cep_hint')) ?></p>
        <label><?= e(t('auth.phone')) ?> <input name="phone" required placeholder="(00) 90000-0000" value="<?= e((string) ($old['phone'] ?? '')) ?>"></label>
        <label><?= e(t('auth.email')) ?> <input type="email" name="email" required value="<?= e((string) ($old['email'] ?? '')) ?>"></label>
        <label><?= e(t('auth.password')) ?> <input type="password" name="password" minlength="8" required></label>
        <label><?= e(t('auth.password2')) ?> <input type="password" name="password_confirm" minlength="8" required></label>
        <p class="muted"><?= e(password_phrase('auth.password_rules')) ?></p>
        <button class="primary" type="submit" id="register-submit"><?= e(t('auth.create')) ?></button>
    </form>
</section>
<script>
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
        try {
            const res = await fetch('/api/users', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': data._csrf || '' },
                body: JSON.stringify(data)
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                errorEl.textContent = json.error || 'Erro ao cadastrar.';
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
        } catch (e) {
            errorEl.textContent = 'Falha de conexão.';
            errorEl.hidden = false;
            submitBtn.disabled = false;
        }
    });
})();
</script>