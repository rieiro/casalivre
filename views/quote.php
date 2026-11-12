<?php
$checkin = (string) ($posted['checkin'] ?? ($checkin ?? ''));
$checkout = (string) ($posted['checkout'] ?? ($checkout ?? ''));
$nights = (int) ($nights ?? (new DateTimeImmutable($checkin))->diff(new DateTimeImmutable($checkout))->days);
$baseNightly = (int) $property['nightly_price'];
$cleaningFee = (int) ($property['cleaning_fee'] ?? 0);
$subtotal = $baseNightly * $nights;
$estimatedTotal = $subtotal + $cleaningFee;
$propPixKey = (string) (!empty($property['pix_key']) ? $property['pix_key'] : 'rafaelrieiro@gmail.com');
?>
<section class="wrap">
    <p><a href="/imoveis/<?= e(property_ref($property)) ?>/calendario">← <?= e(t('quote.back_cal')) ?></a></p>
    <h1><?= e(t('quote.title')) ?></h1>
    <p class="muted"><strong><?= e(t('quote.property')) ?>:</strong> <?= e($property["title"]) ?> (<?= e($property["city"]) ?>)</p>

    <?php if (!empty($error)): ?>
        <p class="alert error"><?= e($error) ?></p>
    <?php endif; ?>

    <?php if (!empty($quote)): ?>
        <div class="panel notice mt-16">
            <h2>✓ <?= e(t('quote.link_success')) ?></h2>
            <p><?= e(t('quote.link_desc')) ?></p>
            
            <div class="mt-12">
                <label><?= e(t('quote.payment_link')) ?>
                    <input type="text" id="quote-link" readonly value="<?= e($payment_url) ?>">
                </label>
            </div>

            <div class="mt-12">
                <p><strong><?= e(t('home.checkin')) ?> / <?= e(t('home.checkout')) ?>:</strong> <?= format_date((string) $quote["checkin"]) ?> a <?= format_date((string) $quote["checkout"]) ?> (<?= (int) $quote["nights"] ?> <?= e(t('quote.nights')) ?>)</p>
                <p><strong><?= e(t('quote.subtotal')) ?>:</strong> <?= money((int) $quote["subtotal"]) ?></p>
                <p><strong><?= e(t('quote.cleaning_fee')) ?>:</strong> <?= money((int) $quote["cleaning"]) ?></p>
                <?php if (!empty($quote["taxes"])): ?>
                    <p><strong><?= e(t('quote.taxes')) ?>:</strong> <?= money((int) $quote["taxes"]) ?></p>
                <?php endif; ?>
                <?php if (!empty($quote["special_offer"])): ?>
                    <p><strong><?= e(t('quote.special_offer_applied')) ?>:</strong> <?= money((int) $quote["special_offer"]) ?></p>
                <?php endif; ?>
                <p class="price"><strong><?= e(t('quote.total')) ?>:</strong> <?= money((int) $quote["total"]) ?></p>
            </div>

            <div class="cover-actions mt-16">
                <button type="button" class="primary" id="btn-copy-wa" data-msg="<?= e($whatsapp_msg) ?>">📲 <?= e(t('quote.copy_wa')) ?></button>
                <a href="<?= e($payment_url) ?>" target="_blank" class="button"><?= e(t('quote.open_guest_page')) ?></a>
            </div>
            <p class="muted mt-12" id="copy-status"></p>
        </div>
    <?php endif; ?>

    <form class="panel mt-16" method="post" action="/imoveis/<?= e(property_ref($property)) ?>/cotacao">
        <h2><?= e(t('quote.title')) ?></h2>
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="checkin" value="<?= e($checkin) ?>">
        <input type="hidden" name="checkout" value="<?= e($checkout) ?>">

        <div class="panel subtle mt-12">
            <p><strong><?= e(t('quote.verified_dates')) ?>:</strong></p>
            <p><strong><?= e(t('quote.checkin')) ?>:</strong> <?= format_date($checkin) ?> · <strong><?= e(t('quote.checkout')) ?>:</strong> <?= format_date($checkout) ?> (<?= $nights ?> <?= e(t('quote.nights')) ?>)</p>
            <p><strong><?= e(t('quote.base_rate')) ?>:</strong> <?= money($baseNightly) ?> · <strong><?= e(t('quote.subtotal')) ?>:</strong> <?= money($subtotal) ?> · <strong><?= e(t('quote.cleaning_fee')) ?>:</strong> <?= money($cleaningFee) ?></p>
            <p><strong><?= e(t('quote.pix_key_label')) ?>:</strong> <code><?= e($propPixKey) ?></code> <span class="muted">(<?= e(t('quote.pix_key_desc')) ?>)</span></p>
        </div>

        <div class="grid mt-12">
            <label><?= e(t('quote.taxes')) ?>
                <input type="number" name="tax_percent" min="0" max="100" value="<?= e($posted["tax_percent"] ?? "0") ?>">
            </label>
            <label><?= e(t('quote.special_offer')) ?>
                <input type="number" name="special_offer" min="1" step="1" placeholder="Ex: 500" value="<?= e($posted["special_offer"] ?? "") ?>">
            </label>
        </div>

        <button type="submit" class="primary mt-16">⚡ <?= e(t('quote.btn_generate')) ?></button>
    </form>
</section>

<script src="<?= e(asset_url('/assets/pages/quote-admin.js')) ?>"></script>
