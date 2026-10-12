<section class="wrap">
    <form class="panel" method="post" action="/entrar">
        <h1><?= e(t('auth.login_title')) ?></h1>
        <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label><?= e(t('auth.email')) ?> <input type="email" name="email" required></label>
        <label><?= e(t('auth.password')) ?> <input type="password" name="password" required></label>
        <button class="primary" type="submit"><?= e(t('auth.submit')) ?></button>
        <p><a href="/senha/esqueci"><?= e(t('auth.forgot')) ?></a></p>
    </form>
</section>
