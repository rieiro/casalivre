<section class="wrap">
    <div class="panel">
        <h1><?= e(t('auth.pending_title')) ?></h1>
        <p><?= e(t('auth.pending_body')) ?></p>
        <p><a href="<?= e($link) ?>"><?= e(t('auth.open_link')) ?></a></p>
        <p class="muted"><?= e(t('auth.expires')) ?> <?= e(format_datetime($expires)) ?></p>
    </div>
</section>
