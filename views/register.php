<section class="wrap">
    <form class="panel" method="post" action="/cadastro">
        <h1><?= e(t('auth.register_title')) ?></h1>
        <p class="muted"><?= e(t('auth.register_lead')) ?></p>
        <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
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
        <button class="primary" type="submit"><?= e(t('auth.create')) ?></button>
    </form>
</section>
