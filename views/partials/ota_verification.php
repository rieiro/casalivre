<?php
/**
 * Verificação de anúncio em plataformas parceiras (iCal challenge-response).
 * Incluído por views/property_form.php apenas na edição de um imóvel existente.
 * CSP: sem <script>/style inline; o comportamento fica em public/assets/pages/property-form.js.
 */
require_once dirname(__DIR__, 2) . '/src/OtaVerificationService.php';

$otaPropertyId = (int) ($property['id'] ?? 0);
$otaRows = $otaPropertyId > 0 ? OtaVerificationService::listForProperty($otaPropertyId) : [];
$otaCurrent = $otaRows[0] ?? null;
$otaStatus = (string) ($otaCurrent['status'] ?? 'unverified');
$otaPending = $otaStatus === 'pending_challenge';
$otaVerified = $otaStatus === 'verified';
$otaPlatform = (string) ($otaCurrent['platform'] ?? 'airbnb');
if (!in_array($otaPlatform, ['airbnb', 'booking', 'vrbo'], true)) {
    $otaPlatform = 'airbnb';
}
$otaVerificationId = (int) ($otaCurrent['id'] ?? 0);
$otaChallengeBr = (string) ($otaCurrent['challenge_date_br'] ?? '');
$otaExpiresBr = '';
if (!empty($otaCurrent['challenge_expires_at'])) {
    $otaExpiresAt = strtotime((string) $otaCurrent['challenge_expires_at']);
    $otaExpiresBr = $otaExpiresAt === false ? '' : gmdate('d/m/Y H:i', $otaExpiresAt) . ' UTC';
}
$otaPublicUrl = (string) ($otaCurrent['public_url'] ?? '');
$otaStatusLabel = match ($otaStatus) {
    'pending_challenge' => t('ota.status_pending'),
    'verified' => t('ota.status_verified'),
    default => t('ota.status_unverified'),
};
$otaStatusClass = match ($otaStatus) {
    'pending_challenge' => 'pending',
    'verified' => 'verified',
    default => 'unverified',
};
$otaPlatformLabel = t('ota.platform_' . $otaPlatform);
$otaStepText = $otaPending && $otaChallengeBr !== ''
    ? sprintf(t('ota.step'), $otaChallengeBr, $otaPlatformLabel)
    : '';
?>
<div
    class="prop-form-card prop-ota-card"
    data-ota-root
    data-ota-verification-id="<?= $otaVerificationId ?>"
    data-ota-checking="<?= e(t('ota.checking')) ?>"
    data-ota-cooldown="<?= e(t('ota.cooldown')) ?>"
    data-ota-check-label="<?= e(t('ota.check_btn')) ?>"
    data-ota-status-pending="<?= e(t('ota.status_pending')) ?>"
    data-ota-status-verified="<?= e(t('ota.status_verified')) ?>"
    data-ota-status-unverified="<?= e(t('ota.status_unverified')) ?>"
>
    <div class="prop-form-card-head">
        <span class="prop-form-card-icon" aria-hidden="true">🏅</span>
        <div>
            <h2 class="prop-form-card-title"><?= e(t('ota.title')) ?></h2>
            <p class="prop-form-card-subtitle"><?= e(t('ota.subtitle')) ?></p>
        </div>
    </div>
    <div class="prop-form-card-body">
        <div class="prop-ota-status-row">
            <span class="prop-ota-status-label"><?= e(t('ota.status')) ?>:</span>
            <span class="prop-ota-status-badge is-<?= e($otaStatusClass) ?>" data-ota-status-label><?= e($otaStatusLabel) ?></span>
        </div>

        <div class="prop-form-grid-2">
            <div class="prop-form-field">
                <label class="prop-form-label" for="ota-platform"><?= e(t('ota.platform')) ?></label>
                <select id="ota-platform" class="prop-form-input" data-ota-platform>
                    <?php foreach (['airbnb', 'booking', 'vrbo'] as $otaOption): ?>
                        <option value="<?= e($otaOption) ?>"<?= $otaOption === $otaPlatform ? ' selected' : '' ?>><?= e(t('ota.platform_' . $otaOption)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="prop-form-field">
                <label class="prop-form-label" for="ota-public-url"><?= e(t('ota.public_url')) ?></label>
                <input id="ota-public-url" class="prop-form-input" type="url" maxlength="2048" placeholder="https://www.airbnb.com/rooms/..." value="<?= e($otaPublicUrl) ?>" data-ota-public-url>
                <p class="prop-form-hint"><?= e(t('ota.public_url_hint')) ?></p>
            </div>
        </div>

        <div class="prop-form-field">
            <label class="prop-form-label" for="ota-ical-url"><?= e(t('ota.ical_url')) ?></label>
            <input id="ota-ical-url" class="prop-form-input" type="url" maxlength="2048" placeholder="https://.../*.ics" data-ota-ical-url>
            <p class="prop-form-hint"><?= e(t('ota.ical_url_hint')) ?></p>
        </div>

        <div class="prop-ota-actions">
            <button type="button" class="btn-dash-action primary" data-ota-start><?= e(t('ota.start_btn')) ?></button>
            <p class="prop-form-hint" data-ota-message hidden></p>
        </div>

        <div class="prop-ota-challenge-box" data-ota-challenge-box<?= $otaPending ? '' : ' hidden' ?>>
            <p class="prop-ota-step" data-ota-step><?= e($otaStepText) ?></p>
            <?php if ($otaExpiresBr !== ''): ?>
                <p class="prop-form-hint"><?= e(sprintf(t('ota.expires'), $otaExpiresBr)) ?></p>
            <?php endif; ?>
            <button type="button" class="btn-dash-action" data-ota-check><?= e(t('ota.check_btn')) ?></button>
        </div>

        <div class="prop-ota-verified-box" data-ota-verified-box<?= $otaVerified ? '' : ' hidden' ?>>
            <strong class="prop-ota-verified-title"><?= e(t('ota.verified_title')) ?></strong>
            <p class="prop-form-hint"><?= e(t('ota.verified_reminder')) ?></p>
        </div>

        <div class="prop-ota-help">
            <strong><?= e(t('ota.instructions_title')) ?></strong>
            <p class="prop-form-hint"><?= e(t('ota.instructions')) ?></p>
            <p class="prop-form-hint"><?= e(sprintf(t('ota.credits'), OtaVerificationService::ONBOARDING_CREDIT_REWARD)) ?></p>
            <p class="prop-form-hint"><?= e(t('ota.saved_hint')) ?></p>
        </div>
    </div>
</div>
