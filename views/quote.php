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
<section class="quote-page wrap">
    <!-- Header Hero Luxury -->
    <div class="quote-header">
        <a class="quote-back-link" href="/imoveis/<?= e(property_ref($property)) ?>/calendario">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
            <span>Voltar ao Calendário</span>
        </a>
        <div class="quote-header-content">
            <span class="dash-badge-eyebrow">PRÉ-RESERVA &amp; LINK PIX</span>
            <h1 class="dash-header-title"><?= e(t('quote.title')) ?></h1>
            <p class="dash-header-desc">
                Crie um link de pagamento Pix exclusivo com bloqueio temporário de 30 minutos na agenda do CasaLivre para envio direto ao hóspede.
            </p>
        </div>

        <!-- Resumo do Imóvel -->
        <div class="quote-prop-summary">
            <div class="quote-prop-icon" aria-hidden="true">🏡</div>
            <div class="quote-prop-details">
                <strong class="quote-prop-title"><?= e($property['title']) ?></strong>
                <span class="quote-prop-city">📍 <?= e($property['city']) ?></span>
            </div>
            <div class="quote-prop-rates">
                <span class="quote-rate-pill">Diária: <?= money($baseNightly) ?></span>
                <span class="quote-rate-pill">Limpeza: <?= money($cleaningFee) ?></span>
            </div>
        </div>
    </div>

    <!-- Alerta de Erro -->
    <?php if (!empty($error)): ?>
        <div class="quote-alert error" role="alert">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <div><strong>Atenção:</strong> <?= e($error) ?></div>
        </div>
    <?php endif; ?>

    <!-- Card de Sucesso: Link Gerado -->
    <?php if (!empty($quote)): ?>
        <div class="quote-success-card">
            <div class="quote-success-head">
                <span class="quote-success-badge">✓ Link Pix Gerado com Sucesso</span>
                <span class="quote-timer-badge">⏳ Válido por 30 minutos</span>
            </div>

            <p class="quote-success-desc">
                As datas foram pré-reservadas na sua agenda por 30 minutos. Compartilhe o link com o hóspede para depósito Pix imediato e liberação automática do voucher.
            </p>

            <!-- Campo Link -->
            <div class="quote-link-box">
                <label class="quote-link-label" for="quote-link"><?= e(t('quote.payment_link')) ?></label>
                <div class="quote-link-input-wrap">
                    <input type="text" id="quote-link" class="quote-link-input" readonly value="<?= e($payment_url) ?>">
                </div>
            </div>

            <!-- Detalhamento de Valores -->
            <div class="quote-breakdown-card">
                <div class="quote-dates-strip">
                    <div class="quote-date-col">
                        <span class="quote-date-lbl">Check-in</span>
                        <strong class="quote-date-val"><?= format_date((string) $quote['checkin']) ?></strong>
                    </div>
                    <div class="quote-nights-pill">
                        <?= (int) $quote['nights'] ?> <?= e(t('quote.nights')) ?>
                    </div>
                    <div class="quote-date-col">
                        <span class="quote-date-lbl">Check-out</span>
                        <strong class="quote-date-val"><?= format_date((string) $quote['checkout']) ?></strong>
                    </div>
                </div>

                <div class="quote-calc-table">
                    <div class="quote-calc-row">
                        <span>Subtotal (<?= (int) $quote['nights'] ?> noites):</span>
                        <strong><?= money((int) $quote['subtotal']) ?></strong>
                    </div>
                    <div class="quote-calc-row">
                        <span>Taxa de limpeza:</span>
                        <strong><?= money((int) $quote['cleaning']) ?></strong>
                    </div>
                    <?php if (!empty($quote['taxes'])): ?>
                        <div class="quote-calc-row">
                            <span>Taxas / Impostos:</span>
                            <strong><?= money((int) $quote['taxes']) ?></strong>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($quote['special_offer'])): ?>
                        <div class="quote-calc-row quote-special-row">
                            <span>Oferta especial aplicada:</span>
                            <strong><?= money((int) $quote['special_offer']) ?></strong>
                        </div>
                    <?php endif; ?>
                    <div class="quote-calc-total">
                        <span>Total da Reserva:</span>
                        <span class="quote-total-price"><?= money((int) $quote['total']) ?></span>
                    </div>
                </div>
            </div>

            <!-- Botões de Ação -->
            <div class="quote-share-actions">
                <button type="button" class="btn-res-whatsapp" id="btn-copy-wa" data-msg="<?= e($whatsapp_msg) ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>
                    <span><?= e(t('quote.copy_wa')) ?></span>
                </button>
                <a href="https://wa.me/?text=<?= urlencode($whatsapp_msg) ?>" target="_blank" class="btn-res-link" style="border-color: #25d366; color: #128c7e;">
                    <span>Abrir no WhatsApp ↗</span>
                </a>
                <a href="<?= e($payment_url) ?>" target="_blank" class="btn-res-link">
                    <span><?= e(t('quote.open_guest_page')) ?></span>
                </a>
            </div>
            <p class="quote-copy-status" id="copy-status"></p>
        </div>
    <?php endif; ?>

    <!-- Formulário de Criação da Cotação -->
    <form class="quote-form-card" method="post" action="/imoveis/<?= e(property_ref($property)) ?>/cotacao">
        <div class="quote-form-card-head">
            <span class="quote-form-card-icon" aria-hidden="true">📅</span>
            <div>
                <h2 class="quote-form-card-title"><?= e(t('quote.title')) ?></h2>
                <p class="quote-form-card-subtitle">Confirme o período e aplique descontos ou taxas opcionais para gerar o link.</p>
            </div>
        </div>

        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="checkin" value="<?= e($checkin) ?>">
        <input type="hidden" name="checkout" value="<?= e($checkout) ?>">

        <div class="quote-form-card-body">
            <!-- Box de Datas Verificadas -->
            <div class="quote-verified-box">
                <div class="quote-verified-head">
                    <strong class="quote-verified-label">✓ <?= e(t('quote.verified_dates')) ?></strong>
                    <span class="quote-nights-pill"><?= $nights ?> <?= e(t('quote.nights')) ?></span>
                </div>
                <div class="quote-dates-strip" style="background: transparent; padding: 0.5rem 0;">
                    <div class="quote-date-col">
                        <span class="quote-date-lbl"><?= e(t('quote.checkin')) ?></span>
                        <strong class="quote-date-val"><?= format_date($checkin) ?></strong>
                    </div>
                    <div class="quote-date-col">
                        <span class="quote-date-lbl"><?= e(t('quote.checkout')) ?></span>
                        <strong class="quote-date-val"><?= format_date($checkout) ?></strong>
                    </div>
                </div>
                <div class="quote-verified-rates">
                    <span>Diária base: <strong><?= money($baseNightly) ?></strong></span>
                    <span>·</span>
                    <span>Subtotal: <strong><?= money($subtotal) ?></strong></span>
                    <span>·</span>
                    <span>Limpeza: <strong><?= money($cleaningFee) ?></strong></span>
                </div>
                <div class="quote-pix-info">
                    <span><?= e(t('quote.pix_key_label')) ?>:</span>
                    <code><?= e($propPixKey) ?></code>
                    <span class="muted">(<?= e(t('quote.pix_key_desc')) ?>)</span>
                </div>
            </div>

            <!-- Campos Opcionais -->
            <div class="quote-grid-2">
                <div class="quote-field">
                    <label class="quote-label" for="field-tax">
                        <span><?= e(t('quote.taxes')) ?></span>
                        <span class="muted">(opcional)</span>
                    </label>
                    <input id="field-tax" class="quote-input" type="number" name="tax_percent" min="0" max="100" value="<?= e($posted['tax_percent'] ?? '0') ?>" placeholder="0%">
                </div>

                <div class="quote-field">
                    <label class="quote-label" for="field-special">
                        <span><?= e(t('quote.special_offer')) ?></span>
                        <span class="muted">(preço fechado R$)</span>
                    </label>
                    <input id="field-special" class="quote-input" type="number" name="special_offer" min="1" step="1" placeholder="Ex: 500" value="<?= e($posted['special_offer'] ?? '') ?>">
                </div>
            </div>

            <div class="quote-submit-bar">
                <button type="submit" class="btn-dash-primary quote-submit-btn">
                    <span>⚡ <?= e(t('quote.btn_generate')) ?></span>
                </button>
            </div>
        </div>
    </form>
</section>

<script src="<?= e(asset_url('/assets/pages/quote-admin.js')) ?>"></script>
