<section class="wrap">
    <div class="panel">
        <h1><?= e(t('auth.pending_title')) ?></h1>
        <p><?= e(t('auth.pending_email')) ?></p>
        <p class="muted"><?= e(t('auth.expires')) ?> <?= e(format_datetime($expires)) ?></p>
    </div>
</section>
