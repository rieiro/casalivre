<section class="wrap">
    <div class="panel">
        <h1><?= e(t('auth.pending_title')) ?></h1>
        <?php if (!empty($link)): ?>
        <p><?= e(t('auth.pending_body')) ?></p>
        <p><a href="<?= e($link) ?>"><?= e(t('auth.open_link')) ?></a></p>
        <?php else: ?>
        <p><?= e(t('auth.pending_email')) ?></p>
        <?php endif; ?>
        <p class="muted"><?= e(t('auth.expires')) ?> <?= e(format_datetime($expires)) ?></p>
    </div>
</section>
