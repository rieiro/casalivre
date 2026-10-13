<section class="wrap">
    <form class="panel" id="account-form" method="post" action="/conta">
        <h1><?= e(t('account.title')) ?></h1>
        <p class="error" id="account-error" hidden></p>
        <p class="ok" id="account-ok" hidden>Salvo.</p>
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label><?= e(t('auth.name')) ?> <input name="name" required value="<?= e($user['name']) ?>"></label>
        <label><?= e(t('auth.cpf')) ?> <input name="cpf" required value="<?= e($user['cpf']) ?>"></label>
        <label><?= e(t('auth.cep')) ?> <input name="cep" inputmode="numeric" maxlength="9" required data-cep placeholder="00000-000" value="<?= e((string) ($user['cep'] ?? '')) ?>"></label>
        <label><?= e(t('auth.street')) ?> <input name="street" readonly required data-street value="<?= e((string) ($user['street'] ?? '')) ?>"></label>
        <label><?= e(t('form.city')) ?> <input name="city" readonly required data-city value="<?= e(city_label_for_cep((string) ($user['cep'] ?? ''))) ?>"></label>
        <div class="row">
            <label><?= e(t('auth.number')) ?> <input name="address_number" required value="<?= e((string) ($user['address_number'] ?? '')) ?>"></label>
            <label><?= e(t('auth.complement')) ?> <input name="address_complement" value="<?= e((string) ($user['address_complement'] ?? '')) ?>"></label>
        </div>
        <p class="muted" data-cep-status><?= e(t('auth.cep_hint')) ?></p>
        <label><?= e(t('auth.phone')) ?> <input name="phone" required placeholder="(00) 90000-0000" value="<?= e($user['phone']) ?>"></label>
        <label><?= e(t('auth.email')) ?> <input type="email" name="email" required value="<?= e($user['email']) ?>"></label>
        <p class="muted"><?= e(t('account.created')) ?> <?= e(format_date((string) $user['created_at'])) ?>.</p>
        <button class="primary" type="submit"><?= e(t('account.save')) ?></button>
    </form>
    <form id="delete-account" method="post" action="/conta/excluir" class="mt-16">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button class="danger" type="submit"><?= e(t('account.delete')) ?></button>
    </form>
</section>
<?php page_data(['deleteConfirm' => t('account.delete_confirm')]); ?>
<script src="/assets/pages/account.js?v=2"></script>