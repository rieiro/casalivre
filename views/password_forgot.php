<section class="wrap">
    <form class="panel" id="forgot-form" method="post" action="/senha/esqueci">
        <h1><?= e(t('auth.reset_title')) ?></h1>
        <p class="muted"><?= e(t('auth.reset_ask')) ?></p>
        <p class="ok" id="forgot-ok" hidden>Se o e-mail existir, enviaremos as instruções.</p>
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label><?= e(t('auth.email')) ?> <input type="email" name="email" required></label>
        <button class="primary" type="submit"><?= e(t('auth.reset_send')) ?></button>
        <p><a href="/entrar"><?= e(t('auth.back_login')) ?></a></p>
    </form>
</section>
<script src="/assets/pages/password-forgot.js?v=1"></script>