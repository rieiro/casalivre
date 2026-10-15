<section class="wrap">
    <form class="panel" id="forgot-form" method="post" action="/senha/esqueci">
        <h1><?= e(t('auth.reset_title')) ?></h1>
        <p class="muted"><?= e(t('auth.reset_ask')) ?></p>
<?php
        // Renderizado no servidor (funciona sem JS). O aviso do POST é neutro (auth.reset_missing, igual exista ou
        // não a conta); sem aviso, #forgot-ok fica hidden com o texto que o JS mostra.
        $formError = (string) ($error ?? '');
        $formNotice = (string) ($notice ?? '');
        ?>
        <p class="error" id="forgot-error" role="alert"<?= $formError === '' ? ' hidden' : '' ?>><?= e($formError) ?></p>
        <p class="ok" id="forgot-ok" role="alert"<?= $formNotice === '' ? ' hidden' : '' ?>><?= e($formNotice !== '' ? $formNotice : 'Se o e-mail existir, enviaremos as instruções.') ?></p>
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label><?= e(t('auth.email')) ?> <input type="email" name="email" required></label>
        <button class="primary" type="submit"><?= e(t('auth.reset_send')) ?></button>
        <p><a href="/entrar"><?= e(t('auth.back_login')) ?></a></p>
    </form>
</section>
<script src="<?= e(asset_url('/assets/pages/password-forgot.js')) ?>"></script>