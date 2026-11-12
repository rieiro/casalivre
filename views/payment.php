<?php
/**
 * Landing Page de Pagamento Pix para Hóspede (Reserva Direta Verificada)
 * Inspirada nos mais refinados padrões visuais (Airbnb Luxe, Booking Premium),
 * com estética que transmite solidez, confiabilidade, elegância e segurança bancária.
 */
$confirmed = !empty($confirmed);
$photos = $photos ?? [];
$primaryPhoto = !empty($property['photo']) ? photo_url($property['photo']) : photo_url(null);
$photoCount = count($photos);

$nights = (int) ($quote['nights'] ?? 1);
$nightlyPrice = (int) ($quote['nightly_price'] ?? 0);
$subtotalNights = (int) ($quote['subtotal'] ?? ($nights * $nightlyPrice));
$cleaningFee = (int) ($quote['cleaning'] ?? ($quote['cleaning_fee'] ?? 0));
$taxes = (int) ($quote['taxes'] ?? 0);
$specialOffer = !empty($quote['special_offer']) ? (int) $quote['special_offer'] : null;
$total = (int) ($quote['total'] ?? $quote['total_price']);
$pixKey = (string) ($quote['pix_key'] ?: 'rafaelrieiro@gmail.com');
?>
<section class="wrap payment-page">
<?php if ($confirmed): ?>
    <div class="confirm-hero">
        <div class="media" style="background-image:url('<?= e($primaryPhoto) ?>')"></div>
        <div class="confirm-body">
            <span class="payment-badge-confirmed">✓ Pagamento Confirmado</span>
            <h1 class="mt-8">Sua estadia está garantida!</h1>
            <p class="muted text-sm">Código da reserva: <strong class="font-mono">CL<?= strtoupper(e($quote['quote_hash'])) ?></strong></p>
            
            <div class="confirm-property-info mt-16">
                <h2><?= e($property['title']) ?></h2>
                <p class="muted text-sm"><?= e($property['address']) ?> · <?= e($property['city']) ?></p>
            </div>

            <div class="price-rows mt-16">
                <div class="price-row">
                    <span>Período</span>
                    <strong><?= e(format_date((string) $quote['checkin'])) ?> → <?= e(format_date((string) $quote['checkout'])) ?> (<?= $nights ?> <?= $nights === 1 ? 'noite' : 'noites' ?>)</strong>
                </div>
                <div class="price-row">
                    <span>Hóspedes</span>
                    <span>Até <?= (int) $property['max_guests'] ?> hóspedes</span>
                </div>
                <div class="price-row">
                    <span>Check-in</span>
                    <span>A partir das <?= e(format_time((string) $property['checkin_time'])) ?></span>
                </div>
                <div class="price-row">
                    <span>Check-out</span>
                    <span>Até <?= e(format_time((string) $property['checkout_time'])) ?></span>
                </div>
                <div class="price-row total">
                    <span>Total Pago via Pix</span>
                    <span class="text-success"><?= money($total) ?></span>
                </div>
            </div>

            <p class="text-xs text-muted mt-12">
                As datas foram bloqueadas no calendário do CasaLivre e sincronizadas via iCal oficial com todas as plataformas.
            </p>

            <div class="confirm-actions mt-16">
                <a class="btn-whatsapp-confirm" href="<?= e($whatsapp_confirm_url) ?>" target="_blank" rel="noopener noreferrer">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.582 2.128 2.182-.573c.978.58 1.911.928 3.145.929 3.178 0 5.767-2.587 5.768-5.766 0-3.18-2.587-5.771-5.764-5.771zm3.392 8.244c-.144.405-.837.774-1.17.824-.299.045-.677.063-1.092-.069-.252-.08-.575-.187-.988-.365-1.739-.751-2.874-2.502-2.961-2.617-.087-.116-.708-.94-.708-1.793s.448-1.273.607-1.446c.159-.173.346-.217.462-.217l.332.007c.106.005.249-.04.39.298.144.347.491 1.2.534 1.287.043.087.072.188.014.304-.058.116-.087.188-.173.289l-.26.304c-.087.086-.177.18-.076.354.101.174.449.741.964 1.201.662.591 1.221.774 1.394.86.173.086.275.072.376-.044.101-.116.433-.506.549-.68.116-.173.231-.145.39-.086s1.011.477 1.184.564.289.13.332.202c.045.072.045.419-.099.824z"/></svg>
                    <span>Conversar com o Anfitrião no WhatsApp</span>
                </a>
                <a class="btn-outline-back" href="/imoveis/<?= e(property_ref($property)) ?>">
                    Ver Anúncio Completo do Imóvel ↗
                </a>
            </div>
        </div>
    </div>
<?php else: ?>
    <!-- Top Trust Bar -->
    <div class="payment-trust-bar">
        <div class="payment-trust-info">
            <span class="payment-trust-shield" aria-hidden="true">🛡️</span>
            <div>
                <strong>Reserva Direta Verificada & Segura</strong>
                <p class="text-xs text-white-80">Garantia de menor preço sem taxas extras de intermediação · Pagamento oficial via Pix</p>
            </div>
        </div>
        <div class="payment-hold-timer-box">
            <span class="payment-pulse-dot" aria-hidden="true"></span>
            <span>Garantia de reserva por: <strong id="hold-timer" data-expires="<?= strtotime((string) $quote['expires_at']) ?>">30:00</strong></span>
        </div>
    </div>

    <!-- Main 2-Column Grid -->
    <div class="payment-grid">
        <!-- Coluna Esquerda: Detalhes do Imóvel & Conforto -->
        <div class="payment-prop-showcase">
            <!-- Galeria de Fotos em Mosaico -->
            <div class="payment-photo-mosaic">
                <?php if ($photoCount >= 3): ?>
                    <div class="payment-photo-grid">
                        <div class="payment-photo-main">
                            <img src="<?= e(photo_url(photo_variant($photos[0]['path'], 'card'))) ?>" alt="Foto principal de <?= e($property['title']) ?>" loading="eager">
                        </div>
                        <div class="payment-photo-side">
                            <div class="payment-photo-thumb">
                                <img src="<?= e(photo_url(photo_variant($photos[1]['path'], 'thumb'))) ?>" alt="Foto 2 de <?= e($property['title']) ?>" loading="lazy">
                            </div>
                            <div class="payment-photo-thumb">
                                <img src="<?= e(photo_url(photo_variant($photos[2]['path'], 'thumb'))) ?>" alt="Foto 3 de <?= e($property['title']) ?>" loading="lazy">
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="payment-photo-grid is-single">
                        <div class="payment-photo-main">
                            <img src="<?= e($primaryPhoto) ?>" alt="Foto de <?= e($property['title']) ?>" loading="eager">
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($photoCount > 1): ?>
                    <div class="payment-photo-badge" aria-hidden="true">
                        📷 <?= $photoCount ?> fotos do imóvel
                    </div>
                <?php endif; ?>
            </div>

            <!-- Card de Detalhes do Imóvel -->
            <article class="payment-card">
                <div class="payment-prop-eyebrow">Hospedagem Exclusiva · <?= e($property['city']) ?></div>
                <h1 class="payment-prop-title"><?= e($property['title']) ?></h1>
                <div class="payment-prop-location">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 0 1 0-5 2.5 2.5 0 0 1 0 5z"/></svg>
                    <span><?= e($property['address']) ?> · <?= e($property['city']) ?></span>
                </div>

                <!-- Specs Ribbon -->
                <div class="payment-specs-ribbon">
                    <span class="payment-spec-pill">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Até <?= (int) $property['max_guests'] ?> hóspedes
                    </span>
                    <span class="payment-spec-pill">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 20v-8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v8"/><path d="M5 10V6a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v4"/><path d="M2 18h20"/></svg>
                        <?= (int) $property['bedrooms'] ?> <?= (int) $property['bedrooms'] === 1 ? 'quarto' : 'quartos' ?>
                    </span>
                    <span class="payment-spec-pill">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 4v16"/><path d="M2 8h18a2 2 0 0 1 2 2v10"/><path d="M2 17h20"/><circle cx="6" cy="11" r="2"/></svg>
                        <?= (int) $property['beds'] ?> <?= (int) $property['beds'] === 1 ? 'cama' : 'camas' ?>
                    </span>
                    <span class="payment-spec-pill">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        Check-in: <?= e(format_time((string) $property['checkin_time'])) ?> · Check-out: <?= e(format_time((string) $property['checkout_time'])) ?>
                    </span>
                </div>

                <!-- Comodidades / Destaques -->
                <h3 class="payment-section-subheading">Comodidades em Destaque</h3>
                <div class="payment-amenities-list">
                    <?php if (!empty($property['pool'])): ?>
                        <span class="payment-amenity-tag">🏊 Piscina privativa</span>
                    <?php endif; ?>
                    <?php if (!empty($property['bbq'])): ?>
                        <span class="payment-amenity-tag">🥩 Churrasqueira</span>
                    <?php endif; ?>
                    <?php if (!empty($property['garage'])): ?>
                        <span class="payment-amenity-tag">🚗 Garagem / Estacionamento</span>
                    <?php endif; ?>
                    <span class="payment-amenity-tag">📶 Wi-Fi incluso</span>
                    <span class="payment-amenity-tag">❄️ Cozinha / Ambiente Equipado</span>
                    <span class="payment-amenity-tag">🧹 Higienização e Enxoval Completo</span>
                </div>

                <?php if (!empty($property['summary']) || !empty($property['description'])): ?>
                    <h3 class="payment-section-subheading mt-16">Sobre o Imóvel</h3>
                    <p class="payment-description-text">
                        <?= nl2br(e((string) ($property['summary'] ?: $property['description']))) ?>
                    </p>
                <?php endif; ?>
            </article>

            <!-- Card de Garantia CasaLivre -->
            <div class="payment-guarantee-box">
                <div class="payment-guarantee-item">
                    <span class="icon" aria-hidden="true">🔒</span>
                    <div>
                        <strong>Garantia de Reserva Direta</strong>
                        <span>Você negocia diretamente com anfitriões verificados, garantindo o melhor valor e isenção de taxas abusivas.</span>
                    </div>
                </div>
                <div class="payment-guarantee-item">
                    <span class="icon" aria-hidden="true">📅</span>
                    <div>
                        <strong>Calendário Blindado</strong>
                        <span>Bloqueio imediato com sincronização iCal em tempo real, eliminando riscos de overbooking.</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Coluna Direita (Sticky): Resumo da Estadia & Pagamento Pix -->
        <aside class="payment-sidebar">
            <!-- Card 1: Resumo da Estadia -->
            <div class="payment-summary-card">
                <div class="payment-card-heading">
                    <span>Resumo da Estadia</span>
                    <span class="payment-pill-nights"><?= $nights ?> <?= $nights === 1 ? 'noite' : 'noites' ?></span>
                </div>

                <!-- Datas em destaque -->
                <div class="payment-dates-grid">
                    <div class="payment-date-block">
                        <div class="payment-date-label">Check-in</div>
                        <div class="payment-date-val"><?= e(format_date((string) $quote['checkin'])) ?></div>
                        <div class="text-xs muted">a partir das <?= e(format_time((string) $property['checkin_time'])) ?></div>
                    </div>
                    <div class="payment-date-arrow" aria-hidden="true">➔</div>
                    <div class="payment-date-block">
                        <div class="payment-date-label">Check-out</div>
                        <div class="payment-date-val"><?= e(format_date((string) $quote['checkout'])) ?></div>
                        <div class="text-xs muted">até <?= e(format_time((string) $property['checkout_time'])) ?></div>
                    </div>
                </div>

                <!-- Breakdown Financeiro -->
                <div class="price-rows">
                    <div class="price-row">
                        <span>Subtotal (<?= $nights ?> <?= $nights === 1 ? 'noite' : 'noites' ?>)</span>
                        <span><?= money($subtotalNights) ?></span>
                    </div>
                    <div class="price-row">
                        <span>Taxa de limpeza</span>
                        <span><?= $cleaningFee > 0 ? money($cleaningFee) : '<span class="payment-tag-free">Inclusa</span>' ?></span>
                    </div>
                    <?php if (!empty($taxes)): ?>
                        <div class="price-row">
                            <span>Taxas</span>
                            <span><?= money($taxes) ?></span>
                        </div>
                    <?php else: ?>
                        <div class="price-row">
                            <span>Taxa de serviço da plataforma</span>
                            <span class="payment-tag-free">R$ 0 (Isento na Reserva Direta)</span>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($specialOffer)): ?>
                        <div class="price-row payment-highlight-offer">
                            <span>🎉 Oferta Especial Aplicada</span>
                            <span><?= money($specialOffer) ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="price-row total">
                        <span>Total a pagar via Pix</span>
                        <span class="payment-total-amount"><?= money($total) ?></span>
                    </div>
                </div>
                <div class="payment-total-sub mt-8">Preço final garantido, sem cobranças adicionais ou surpresas.</div>
            </div>

            <!-- Card 2: Centro de Pagamento Pix Oficial -->
            <div class="payment-pix-card">
                <div class="payment-pix-header">
                    <span class="payment-pix-badge">PIX</span>
                    <h2 class="payment-pix-title">Pagamento Instantâneo Pix</h2>
                </div>

                <div class="payment-steps">
                    <div class="payment-step-item">
                        <span class="payment-step-num">1</span>
                        <span>Copie o código Pix abaixo.</span>
                    </div>
                    <div class="payment-step-item">
                        <span class="payment-step-num">2</span>
                        <span>Abra o app do seu banco e escolha <strong>Pix Copia e Cola</strong>.</span>
                    </div>
                    <div class="payment-step-item">
                        <span class="payment-step-num">3</span>
                        <span>Confira o valor de <strong><?= money($total) ?></strong> e confirme.</span>
                    </div>
                </div>

                <!-- Chave e Favorecido -->
                <div class="payment-key-details">
                    <div class="text-xs muted">CHAVE PIX (DIRETA):</div>
                    <div class="font-bold text-sm text-slate-800"><?= e($pixKey) ?></div>
                    <div class="text-xs text-slate-500">Beneficiário: Rafael Rieiro · Pagamento Seguro Oficial</div>
                </div>

                <!-- Textarea com Código Pix Copia e Cola -->
                <div class="payment-code-wrapper mt-8">
                    <label for="pix-payload" class="text-xs font-semibold muted">CÓDIGO PIX COPIA E COLA:</label>
                    <textarea id="pix-payload" class="payment-pix-textarea" rows="3" readonly aria-label="Código Pix Copia e Cola"><?= e($pix_payload) ?></textarea>
                </div>

                <!-- Botão Copiar Pix -->
                <button type="button" class="btn-copy-pix" id="btn-copy-pix">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                    <span>Copiar Código Pix</span>
                </button>
                <div id="pix-copy-status" class="payment-copy-feedback" role="status" aria-live="polite"></div>

                <!-- Botão WhatsApp com Anfitrião -->
                <a class="btn-whatsapp-confirm" href="<?= e($whatsapp_confirm_url) ?>" target="_blank" rel="noopener noreferrer">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.582 2.128 2.182-.573c.978.58 1.911.928 3.145.929 3.178 0 5.767-2.587 5.768-5.766 0-3.18-2.587-5.771-5.764-5.771zm3.392 8.244c-.144.405-.837.774-1.17.824-.299.045-.677.063-1.092-.069-.252-.08-.575-.187-.988-.365-1.739-.751-2.874-2.502-2.961-2.617-.087-.116-.708-.94-.708-1.793s.448-1.273.607-1.446c.159-.173.346-.217.462-.217l.332.007c.106.005.249-.04.39.298.144.347.491 1.2.534 1.287.043.087.072.188.014.304-.058.116-.087.188-.173.289l-.26.304c-.087.086-.177.18-.076.354.101.174.449.741.964 1.201.662.591 1.221.774 1.394.86.173.086.275.072.376-.044.101-.116.433-.506.549-.68.116-.173.231-.145.39-.086s1.011.477 1.184.564.289.13.332.202c.045.072.045.419-.099.824z"/></svg>
                    <span>Confirmar Pagamento no WhatsApp</span>
                </a>

                <!-- Autoconfirmação do Hóspede -->
                <form class="mt-8" method="post" action="/pagamento/<?= e($quote['quote_hash']) ?>/confirmar">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <button type="submit" class="btn-self-confirm">
                        ✓ Já realizei o pagamento (Garantir Reserva)
                    </button>
                </form>
            </div>

            <!-- Selos de Segurança no Rodapé -->
            <div class="payment-security-footer">
                <div class="payment-security-footer-item">
                    <span>🔒 Criptografia SSL</span>
                </div>
                <div class="payment-security-footer-item">
                    <span>🏦 Pix Banco Central</span>
                </div>
                <div class="payment-security-footer-item">
                    <span>🛡️ Reserva Garantida</span>
                </div>
            </div>
        </aside>
    </div>
<?php endif; ?>
</section>
<script src="<?= e(asset_url('/assets/pages/payment.js')) ?>"></script>
