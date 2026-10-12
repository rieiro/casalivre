<section class="wrap">
    <form class="panel" method="post" action="/senha/esqueci">
        <h1><?= e(t('auth.reset_title')) ?></h1>
        <p class="muted"><?= e(t('auth.reset_ask')) ?></p>
        <?php if (!empty($notice)): ?><p class="ok"><?= e($notice) ?></p><?php endif; ?>
        <?php if (!empty($link)): ?>
            <p><a href="<?= e($link) ?>"><?= e(t('auth.open_link')) ?></a></p>
            <p class="muted"><?= e(t('auth.expires')) ?> <?= e(format_datetime($expires)) ?></p>
        <?php endif; ?>
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label><?= e(t('auth.email')) ?> <input type="email" name="email" required></label>
        <button class="primary" type="submit"><?= e(t('auth.reset_send')) ?></button>
        <p><a href="/entrar"><?= e(t('auth.back_login')) ?></a></p>
    </form>
</section>
