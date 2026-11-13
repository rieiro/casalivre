<?php
$formError = (string) ($error ?? '');
?>
<section class="account-page wrap">
    <!-- Header Hero Luxury -->
    <div class="account-header">
        <a class="account-back-link" href="/painel">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
            <span>Voltar ao Painel</span>
        </a>
        <div class="account-header-content">
            <span class="dash-badge-eyebrow">PORTAL DO ANFITRIÃO</span>
            <h1 class="dash-header-title"><?= e(t('account.title')) ?></h1>
            <p class="dash-header-desc">
                Gerencie suas informações cadastrais, endereço do titular, canais de contato e segurança de acesso.
            </p>
        </div>

        <!-- Perfil Resumo -->
        <div class="account-profile-summary">
            <div class="account-avatar" aria-hidden="true">👤</div>
            <div class="account-profile-info">
                <strong class="account-profile-name"><?= e($user['name']) ?></strong>
                <span class="account-profile-email">
                    <?= e($user['email']) ?>
                    <span class="account-verified-badge">✓ Verificado</span>
                </span>
                <span class="account-profile-created"><?= e(t('account.created')) ?> <?= e(format_date((string) $user['created_at'])) ?></span>
            </div>
        </div>
    </div>

    <!-- Alertas Dinâmicos / SSR -->
    <div class="account-alert error" id="account-error" role="alert"<?= $formError === '' ? ' hidden' : '' ?>><?= e($formError) ?></div>
    <div class="account-alert ok" id="account-ok" hidden>✓ Alterações salvas com sucesso.</div>

    <!-- Formulário Principal -->
    <form class="account-form-main" id="account-form" method="post" action="/conta">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <!-- CARD 1: Identificação e Contato -->
        <div class="account-card">
            <div class="account-card-head">
                <span class="account-card-icon" aria-hidden="true">👤</span>
                <div>
                    <h2 class="account-card-title">Dados de Identificação &amp; Contato</h2>
                    <p class="account-card-subtitle">Seus dados oficiais de titular da conta e canais para contato de hóspedes.</p>
                </div>
            </div>
            <div class="account-card-body">
                <div class="account-grid-2">
                    <div class="account-field">
                        <label class="account-label" for="field-name"><?= e(t('auth.name')) ?> <strong class="req">*</strong></label>
                        <input id="field-name" class="account-input" name="name" required value="<?= e($user['name']) ?>" placeholder="Seu nome completo">
                    </div>
                    <div class="account-field">
                        <label class="account-label" for="field-cpf"><?= e(t('auth.cpf')) ?> <strong class="req">*</strong></label>
                        <input id="field-cpf" class="account-input" name="cpf" required value="<?= e($user['cpf']) ?>" placeholder="000.000.000-00">
                    </div>
                </div>

                <div class="account-grid-2">
                    <div class="account-field">
                        <label class="account-label" for="field-phone"><?= e(t('auth.phone')) ?> <strong class="req">*</strong></label>
                        <input id="field-phone" class="account-input" name="phone" required placeholder="(00) 90000-0000" value="<?= e($user['phone']) ?>">
                        <p class="account-hint">Número com DDD para contato direto e notificações de reservas via WhatsApp.</p>
                    </div>
                    <div class="account-field">
                        <label class="account-label"><?= e(t('auth.email')) ?></label>
                        <div class="account-input-readonly-wrap">
                            <input class="account-input is-readonly" type="email" readonly value="<?= e($user['email']) ?>">
                            <span class="account-lock-badge">🔒 Login Principal</span>
                        </div>
                        <p class="account-hint">O e-mail de acesso é o identificador seguro da sua conta.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- CARD 2: Endereço Cadastral -->
        <div class="account-card">
            <div class="account-card-head">
                <span class="account-card-icon" aria-hidden="true">📍</span>
                <div>
                    <h2 class="account-card-title">Endereço Residencial / Comercial</h2>
                    <p class="account-card-subtitle">Endereço cadastral para emissão de comprovantes e conferência de titularidade.</p>
                </div>
            </div>
            <div class="account-card-body">
                <div class="account-grid-2">
                    <div class="account-field">
                        <label class="account-label" for="field-cep"><?= e(t('auth.cep')) ?> <strong class="req">*</strong></label>
                        <input id="field-cep" class="account-input" name="cep" inputmode="numeric" maxlength="9" required data-cep placeholder="00000-000" value="<?= e((string) ($user['cep'] ?? '')) ?>">
                    </div>
                    <div class="account-field">
                        <label class="account-label" for="field-city"><?= e(t('form.city')) ?> <strong class="req">*</strong></label>
                        <input id="field-city" class="account-input is-readonly" name="city" readonly required data-city value="<?= e(city_label_for_cep((string) ($user['cep'] ?? ''))) ?>" placeholder="Preenchido via CEP">
                    </div>
                </div>

                <div class="account-field">
                    <label class="account-label" for="field-street"><?= e(t('auth.street')) ?> <strong class="req">*</strong></label>
                    <input id="field-street" class="account-input is-readonly" name="street" readonly required data-street value="<?= e((string) ($user['street'] ?? '')) ?>" placeholder="Preenchido via CEP">
                </div>

                <div class="account-grid-2">
                    <div class="account-field">
                        <label class="account-label" for="field-number"><?= e(t('auth.number')) ?> <strong class="req">*</strong></label>
                        <input id="field-number" class="account-input" name="address_number" required value="<?= e((string) ($user['address_number'] ?? '')) ?>" placeholder="Ex: 150">
                    </div>
                    <div class="account-field">
                        <label class="account-label" for="field-complement"><?= e(t('auth.complement')) ?></label>
                        <input id="field-complement" class="account-input" name="address_complement" value="<?= e((string) ($user['address_complement'] ?? '')) ?>" placeholder="Ex: Sala 4, Bloco A">
                    </div>
                </div>
                <p class="account-hint" data-cep-status><?= e(t('auth.cep_hint')) ?></p>
            </div>
        </div>

        <!-- Submit Bar -->
        <div class="account-submit-bar">
            <button class="btn-dash-primary account-submit-btn primary" type="submit">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                <span><?= e(t('account.save')) ?></span>
            </button>
            <a class="btn-dash-ghost account-cancel-btn" href="/painel">Voltar ao Painel</a>
        </div>
    </form>

    <!-- CARD 3: Zona de Perigo / Exclusão de Conta -->
    <div class="account-danger-box">
        <div class="account-danger-info">
            <h3 class="account-danger-title">Zona de Segurança</h3>
            <p class="account-danger-desc">
                Caso deseje encerrar suas atividades no CasaLivre, seus imóveis serão inativados e seus dados serão desativados conforme as diretrizes de privacidade.
            </p>
        </div>
        <form id="delete-account" method="post" action="/conta/excluir" class="account-delete-form">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button class="btn-dash-action is-delete" type="submit">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                <span><?= e(t('account.delete')) ?></span>
            </button>
        </form>
    </div>
</section>
<?php page_data(['deleteConfirm' => t('account.delete_confirm')]); ?>
<script src="<?= e(asset_url('/assets/pages/account.js')) ?>"></script>
