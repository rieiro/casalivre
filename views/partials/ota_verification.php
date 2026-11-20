<?php
/**
 * Verificação de anúncio em plataformas parceiras (iCal challenge-response).
 * Incluído por views/property_form.php apenas na edição de um imóvel existente.
 * Duas seções no modelo do calendário: adicionar desafio e listar/gerenciar os existentes.
 * CSP: sem <script>/style inline; o comportamento fica em public/assets/pages/property-form.js.
 */
require_once dirname(__DIR__, 2) . '/src/OtaVerificationService.php';

$otaPropertyId = (int) ($property['id'] ?? 0);
$otaRows = $otaPropertyId > 0 ? OtaVerificationService::listForProperty($otaPropertyId) : [];
$otaStatusMeta = static function (string $status): array {
    return match ($status) {
        'pending_challenge' => ['pending', t('ota.status_pending')],
        'verified' => ['verified', t('ota.status_verified')],
        'failed' => ['failed', t('ota.status_failed')],
        default => ['unverified', t('ota.status_unverified')],
    };
};
?>
<div
    class="prop-form-card prop-ota-card"
    data-ota-root
    data-ota-checking="<?= e(t('ota.checking')) ?>"
    data-ota-cooldown="<?= e(t('ota.cooldown')) ?>"
    data-ota-check-label="<?= e(t('ota.check_btn')) ?>"
    data-ota-generate-label="<?= e(t('ota.generate_btn')) ?>"
    data-ota-remove-label="<?= e(t('ota.remove_btn')) ?>"
    data-ota-remove-confirm="<?= e(t('ota.remove_confirm')) ?>"
    data-ota-never="<?= e(t('ota.never')) ?>"
    data-ota-empty="<?= e(t('ota.empty')) ?>"
    data-ota-added="<?= e(t('ota.added')) ?>"
    data-ota-removed="<?= e(t('ota.removed')) ?>"
    data-ota-status-pending="<?= e(t('ota.status_pending')) ?>"
    data-ota-status-verified="<?= e(t('ota.status_verified')) ?>"
    data-ota-status-unverified="<?= e(t('ota.status_unverified')) ?>"
    data-ota-status-failed="<?= e(t('ota.status_failed')) ?>"
>
    <div class="prop-form-card-head">
        <span class="prop-form-card-icon" aria-hidden="true">🏅</span>
        <div>
            <h2 class="prop-form-card-title"><?= e(t('ota.title')) ?></h2>
            <p class="prop-form-card-subtitle"><?= e(t('ota.subtitle')) ?></p>
        </div>
    </div>
    <div class="prop-form-card-body">
        <!-- Seção 1: adicionar um desafio (URLs + plataforma). -->
        <div class="prop-ota-section">
            <h3 class="prop-ota-section-title"><?= e(t('ota.add_title')) ?></h3>
            <div class="prop-form-grid-2">
                <div class="prop-form-field">
                    <label class="prop-form-label" for="ota-platform"><?= e(t('ota.platform')) ?></label>
                    <select id="ota-platform" class="prop-form-input" data-ota-platform>
                        <?php foreach (['airbnb', 'booking', 'vrbo'] as $otaOption): ?>
                            <option value="<?= e($otaOption) ?>"><?= e(t('ota.platform_' . $otaOption)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="prop-form-field">
                    <label class="prop-form-label" for="ota-public-url"><?= e(t('ota.public_url')) ?></label>
                    <input id="ota-public-url" class="prop-form-input" type="url" maxlength="2048" placeholder="https://www.airbnb.com/rooms/..." data-ota-public-url>
                    <p class="prop-form-hint"><?= e(t('ota.public_url_hint')) ?></p>
                </div>
            </div>
            <div class="prop-form-field">
                <label class="prop-form-label" for="ota-ical-url"><?= e(t('ota.ical_url')) ?></label>
                <input id="ota-ical-url" class="prop-form-input" type="url" maxlength="2048" placeholder="https://.../*.ics" data-ota-ical-url>
                <p class="prop-form-hint"><?= e(t('ota.ical_url_hint')) ?></p>
            </div>
            <div class="prop-ota-actions">
                <button type="button" class="btn-dash-action primary" data-ota-add><?= e(t('ota.add_btn')) ?></button>
                <p class="prop-form-hint" data-ota-message hidden></p>
            </div>
        </div>

        <!-- Seção 2: desafios existentes, status e ações por linha. -->
        <div class="prop-ota-section">
            <h3 class="prop-ota-section-title"><?= e(t('ota.list_title')) ?></h3>
            <div class="sync-scroll">
                <table class="sync-table prop-ota-table">
                    <thead>
                        <tr>
                            <th><?= e(t('ota.col_platform')) ?></th>
                            <th><?= e(t('ota.col_status')) ?></th>
                            <th><?= e(t('ota.col_date')) ?></th>
                            <th><?= e(t('ota.col_checked')) ?></th>
                            <th><?= e(t('ota.col_actions')) ?></th>
                        </tr>
                    </thead>
                    <tbody data-ota-tbody>
                        <?php if ($otaRows === []): ?>
                            <tr><td colspan="5" class="muted"><?= e(t('ota.empty')) ?></td></tr>
                        <?php endif; ?>
                        <?php foreach ($otaRows as $otaRow): ?>
                            <?php
                            [$otaKind, $otaLabel] = $otaStatusMeta((string) $otaRow['status']);
                            $otaShowGenerate = in_array((string) $otaRow['status'], ['unverified', 'failed'], true);
                            $otaShowCheck = (string) $otaRow['status'] === 'pending_challenge';
                            ?>
                            <tr data-ota-row data-ota-verification-id="<?= (int) $otaRow['id'] ?>" data-ota-status="<?= e((string) $otaRow['status']) ?>">
                                <td>
                                    <strong><?= e((string) $otaRow['platform_label']) ?></strong>
                                    <br><span class="muted fine"><?= e((string) $otaRow['public_url']) ?></span>
                                </td>
                                <td><span class="prop-ota-status-badge is-<?= e($otaKind) ?>" data-ota-row-status><?= e($otaLabel) ?></span></td>
                                <td data-ota-row-date><?= e((string) ($otaRow['challenge_date_br'] ?? t('ota.never'))) ?></td>
                                <td data-ota-row-checked><?= e((string) ($otaRow['last_checked_at_br'] ?? t('ota.never'))) ?></td>
                                <td>
                                    <div class="sync-actions">
                                        <button type="button" class="primary" data-ota-generate<?= $otaShowGenerate ? '' : ' hidden' ?>><?= e(t('ota.generate_btn')) ?></button>
                                        <button type="button" class="primary" data-ota-check<?= $otaShowCheck ? '' : ' hidden' ?>><?= e(t('ota.check_btn')) ?></button>
                                        <button type="button" class="danger" data-ota-remove><?= e(t('ota.remove_btn')) ?></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="prop-ota-help">
            <strong><?= e(t('ota.instructions_title')) ?></strong>
            <p class="prop-form-hint"><?= e(t('ota.instructions')) ?></p>
            <p class="prop-form-hint"><?= e(sprintf(t('ota.credits'), OtaVerificationService::ONBOARDING_CREDIT_REWARD)) ?></p>
            <p class="prop-form-hint"><?= e(t('ota.saved_hint')) ?></p>
        </div>
    </div>
</div>
