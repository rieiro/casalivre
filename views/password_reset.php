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
<script src="/assets/pages/password-reset.js?v=2"></script>