<section class="wrap">
    <form class="panel" method="post" action="/conta/email/confirmar">
        <h1><?= e(t('email.confirm_title')) ?></h1>
        <?php if (!empty($error)): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <?php if ($token !== ''): ?><button class="primary" type="submit"><?= e(t('email.confirm_button')) ?></button><?php endif; ?>
    </form>
</section>
