<section class="wrap">
    <?php /* GET /verificar só mostra este form; o token é consumido no POST com CSRF (scanners de e-mail fazem GET/HEAD). */ ?>
    <form class="panel" id="verify-form" method="post" action="/verificar">
        <h1><?= e(t('auth.verify_title')) ?></h1>
        <p><?= e(t('auth.verify_hint')) ?></p>
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <button class="primary" type="submit"><?= e(t('auth.verify_button')) ?></button>
    </form>
</section>
