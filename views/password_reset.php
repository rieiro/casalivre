<section class="wrap">
    <form class="panel" id="reset-form" method="post" action="/senha/redefinir">
        <h1><?= e(t('auth.reset_title')) ?></h1>
        <?php $formError = (string) ($error ?? ''); /* erro do POST re-renderizado no servidor (funciona sem JS) */ ?>
        <p class="error" id="reset-error" role="alert"<?= $formError === '' ? ' hidden' : '' ?>><?= e($formError) ?></p>
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <label><?= e(t('auth.reset_new')) ?> <input type="password" name="password" minlength="8" required></label>
        <label><?= e(t('auth.password2')) ?> <input type="password" name="password_confirm" minlength="8" required></label>
        <button class="primary" type="submit"><?= e(t('auth.reset_save')) ?></button>
    </form>
</section>
<script src="<?= e(asset_url('/assets/pages/password-reset.js')) ?>"></script>