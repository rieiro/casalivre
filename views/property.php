<?php
require_once dirname(__DIR__) . '/src/Amenities.php';
require_once dirname(__DIR__) . '/src/ImageWorker.php';

$selectedAmenities = Amenities::selected($property);
$nightPrice = (int) ($quote['nightly'] ?? $property['nightly_price']);
$cleaningFee = (int) ($property['cleaning_fee'] ?? 0);
$backUrl = safe_search_return((string) ($_GET['volta'] ?? ''));

$amenityIcons = Amenities::ICONS;
?>
<section class="wrap prop-view-page" id="property-detail">
    <?php if (!empty($use_api)): ?>
        <p class="muted">Carregando…</p>
    <?php else: ?>
        <!-- Top Navigation -->
        <div class="prop-view-top-nav">
            <a class="prop-view-back" href="<?= e($backUrl) ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
                <span><?= e(t('home.back_search')) ?></span>
            </a>
        </div>

        <!-- Header Hero -->
        <header class="prop-view-header">
            <span class="prop-view-eyebrow">ALUGUEL POR TEMPORADA · <?= strtoupper(e($property['city'])) ?></span>
            <h1 class="prop-view-title"><?= e($property['title']) ?></h1>
            <div class="prop-view-meta">
                <?php if ($score !== null): ?>
                    <span class="prop-view-score-badge">★ <?= number_format((float) $score, 1, ',', '.') ?> <span class="prop-view-score-count">(<?= (int) ($property['rating_count'] ?? 0) ?>)</span></span>
                    <span class="prop-view-meta-dot" aria-hidden="true">·</span>
                <?php endif; ?>
                <span class="prop-view-meta-item">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    <?= e($property['city']) ?><?= !empty($property['neighborhood']) ? ', ' . e($property['neighborhood']) : '' ?>
                </span>
                <span class="prop-view-meta-dot" aria-hidden="true">·</span>
                <span class="prop-view-meta-item">👥 até <?= (int) $property['max_guests'] ?> hóspedes</span>
                <span class="prop-view-meta-dot" aria-hidden="true">·</span>
                <span class="prop-view-meta-item">🚪 <?= (int) $property['bedrooms'] ?> quartos</span>
                <span class="prop-view-meta-dot" aria-hidden="true">·</span>
                <span class="prop-view-meta-item">🛏️ <?= (int) $property['beds'] ?> camas</span>
                <span class="prop-view-meta-dot" aria-hidden="true">·</span>
                <span class="prop-view-meta-item">🚿 <?= (int) $property['rooms'] ?> banheiros</span>
            </div>
        </header>

        <!-- Luxury Photo Mosaic Gallery -->
        <div class="prop-view-mosaic">
            <?php if (empty($photos)): ?>
                <div class="prop-view-photo-single">
                    <img src="/assets/placeholder.svg" alt="Foto de <?= e($property['title']) ?>" loading="eager">
                </div>
            <?php elseif (count($photos) === 1): ?>
                <div class="prop-view-photo-single">
                    <img src="<?= e(photo_url(photo_variant($photos[0]['path'], 'card'))) ?>"<?= photo_srcset_attr($photos[0]['path'], '(max-width: 900px) 100vw, 1200px') ?> alt="Foto de <?= e($property['title']) ?>" loading="eager">
                </div>
            <?php else: ?>
                <div class="prop-view-photo-main">
                    <img src="<?= e(photo_url(photo_variant($photos[0]['path'], 'card'))) ?>"<?= photo_srcset_attr($photos[0]['path'], '(max-width: 900px) 100vw, 700px') ?> alt="Foto principal de <?= e($property['title']) ?>" loading="eager">
                </div>
                <div class="prop-view-photo-grid">
                    <?php foreach (array_slice($photos, 1, 4) as $idx => $photo): ?>
                        <div class="prop-view-photo-item<?= ($idx === 3 && count($photos) > 5) ? ' has-more' : '' ?>">
                            <img src="<?= e(photo_url(photo_variant($photo['path'], 'card'))) ?>"<?= photo_srcset_attr($photo['path'], '(max-width: 600px) 50vw, 350px') ?> alt="Foto de <?= e($property['title']) ?>" loading="lazy">
                            <?php if ($idx === 3 && count($photos) > 5): ?>
                                <div class="prop-view-more-badge">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                                    <span>+<?= count($photos) - 5 ?> fotos</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Main Content 2-Column Grid -->
        <div class="prop-view-content">
            <!-- Left Column: Details, Description, Amenities, Rules -->
            <div class="prop-view-main-col">
                <!-- Host & Highlights -->
                <div class="prop-view-host-card">
                    <div class="prop-view-host-avatar" aria-hidden="true">🏡</div>
                    <div class="prop-view-host-info">
                        <strong class="prop-view-host-title">Espaço inteiro em <?= e($property['city']) ?></strong>
                        <p class="prop-view-host-desc">Imóvel verificado e preparado para uma estadia inesquecível com privacidade total.</p>
                    </div>
                </div>

                <!-- Highlights Bar -->
                <div class="prop-view-perks-bar">
                    <div class="prop-view-perk">
                        <span class="prop-view-perk-icon" aria-hidden="true">🕒</span>
                        <div>
                            <strong>Check-in facilitado</strong>
                            <span>a partir das <?= e(format_time((string) $property['checkin_time'])) ?></span>
                        </div>
                    </div>
                    <div class="prop-view-perk">
                        <span class="prop-view-perk-icon" aria-hidden="true">🛡️</span>
                        <div>
                            <strong>Reserva Direta</strong>
                            <span>sem intermediários ou taxas abusivas</span>
                        </div>
                    </div>
                    <div class="prop-view-perk">
                        <span class="prop-view-perk-icon" aria-hidden="true">✨</span>
                        <div>
                            <strong>Ambiente Cuidado</strong>
                            <span>espaço higienizado e equipado</span>
                        </div>
                    </div>
                </div>

                <!-- Description Card -->
                <div class="prop-view-card">
                    <h2 class="prop-view-card-title">Sobre este imóvel</h2>
                    <p class="prop-view-description"><?= nl2br(e($property['description'])) ?></p>
                </div>

                <!-- Amenities Showcase -->
                <div class="prop-view-card prop-view-amenities">
                    <h2 class="prop-view-card-title">O que este espaço oferece</h2>
                    <div class="prop-view-amenities-grid">
                        <?php
                        $hasAnyAmenity = false;
                        foreach (Amenities::keys() as $k):
                            if (in_array($k, ['noise', 'noise_from', 'noise_until'], true) || empty($selectedAmenities[$k])) continue;
                            $hasAnyAmenity = true;
                            $icon = Amenities::icon($k);
                        ?>
                            <div class="prop-view-amenity-pill">
                                <span class="prop-view-amenity-icon" aria-hidden="true"><?= $icon ?></span>
                                <span><?= e(t('amenity.' . $k)) ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$hasAnyAmenity): ?>
                            <p class="muted">Comodidades completas e essenciais para sua hospedagem.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Rules & Quiet Hours Card -->
                <div class="prop-view-card">
                    <h2 class="prop-view-card-title">Regras da casa &amp; Convivência</h2>
                    <ul class="prop-view-rules-list">
                        <li>🕒 <strong>Check-in:</strong> a partir das <?= e(format_time((string) $property['checkin_time'])) ?></li>
                        <li>🕚 <strong>Check-out:</strong> até às <?= e(format_time((string) $property['checkout_time'])) ?></li>
                        <li>📅 <strong>Estadia mínima:</strong> <?= (int) $property['min_nights'] ?> noite(s)</li>
                        <?php if (!empty($selectedAmenities['noise'])): ?>
                            <li class="prop-view-quiet-rule">
                                🌙 <strong>Lei do Silêncio:</strong> das <?= e($selectedAmenities['noise_from'] ?? '22:00') ?> às <?= e($selectedAmenities['noise_until'] ?? '07:00') ?> (não é permitido fazer barulho).
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>


            </div>

            <!-- Right Column: Sticky Booking Card -->
            <aside class="prop-view-side-col">
                <div class="prop-view-booking-card">
                    <div class="prop-view-card-price-row">
                        <div class="prop-view-card-price-group">
                            <span class="prop-view-price-val">R$ <?= money_input($nightPrice) ?></span>
                            <span class="prop-view-price-unit">/ noite</span>
                        </div>
                        <?php if ($score !== null): ?>
                            <span class="prop-view-card-score">★ <?= number_format((float) $score, 1, ',', '.') ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="prop-view-date-inputs">
                        <div class="prop-view-date-box active">
                            <span class="prop-view-date-label">Check-in</span>
                            <span class="prop-view-date-val" id="display-checkin">Selecione</span>
                        </div>
                        <div class="prop-view-date-box">
                            <span class="prop-view-date-label">Check-out</span>
                            <span class="prop-view-date-val" id="display-checkout">Selecione</span>
                        </div>
                    </div>

                    <div class="prop-view-cal-header">
                        <span class="prop-view-cal-title">Selecione as datas</span>
                        <div class="prop-view-cal-nav">
                            <button class="prop-view-cal-nav-btn" type="button" id="cal-nav-prev" disabled title="Mês anterior">‹</button>
                            <button class="prop-view-cal-nav-btn" type="button" id="cal-nav-next" title="Próximo mês">›</button>
                        </div>
                    </div>

                    <div class="prop-view-double-cal" id="booking-double-cal">
                        <div class="cal-month-box">
                            <h4>Mês 1</h4>
                            <div class="cal-mini-weekdays"><span>S</span><span>T</span><span>Q</span><span>Q</span><span>S</span><span>S</span><span>D</span></div>
                            <div class="cal-mini-grid"></div>
                        </div>
                        <div class="cal-month-box">
                            <h4>Mês 2</h4>
                            <div class="cal-mini-weekdays"><span>S</span><span>T</span><span>Q</span><span>Q</span><span>S</span><span>S</span><span>D</span></div>
                            <div class="cal-mini-grid"></div>
                        </div>
                    </div>

                    <div class="prop-view-cal-legend">
                        <div class="prop-view-cal-legend-item"><span class="prop-view-cal-legend-dot avail"></span><span>Disponível</span></div>
                        <div class="prop-view-cal-legend-item"><span class="prop-view-cal-legend-dot blocked"></span><span>Bloqueado</span></div>
                        <div class="prop-view-cal-legend-item"><span class="prop-view-cal-legend-dot selected"></span><span>Selecionado</span></div>
                    </div>

                    <form class="prop-view-booking-form mt-16" method="post" action="/imoveis/<?= e(property_ref($property)) ?>/reservar">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="checkin" id="booking-checkin" value="">
                        <input type="hidden" name="checkout" id="booking-checkout" value="">
                        <input type="hidden" name="volta" value="<?= e($backUrl) ?>">

                        <div class="prop-view-card-details">
                            <div class="prop-view-card-row">
                                <span>Check-in</span>
                                <strong>a partir das <?= e(format_time((string) $property['checkin_time'])) ?></strong>
                            </div>
                            <div class="prop-view-card-row">
                                <span>Check-out</span>
                                <strong>até <?= e(format_time((string) $property['checkout_time'])) ?></strong>
                            </div>
                            <div class="prop-view-card-row">
                                <span>Estadia mínima</span>
                                <strong><?= (int) $property['min_nights'] ?> <?= e(t('stay.night_one')) ?></strong>
                            </div>
                            <?php if ($cleaningFee > 0): ?>
                                <div class="prop-view-card-row">
                                    <span>Taxa de limpeza</span>
                                    <strong>R$ <?= money_input($cleaningFee) ?></strong>
                                </div>
                            <?php endif; ?>
                        </div>

                        <p class="price" style="display:none;"><?= $quote ? money((int) $quote['nightly']) . ' a diária no período (' . (int) $quote['nights'] . ' noites, total ' . money((int) $quote['total']) . ')' . (!empty($quote['cleaning']) ? ', ' . t('form.cleaning') . ' ' . money((int) $quote['cleaning']) : '') : 'Diária base ' . money((int) $property['nightly_price']) . ((int) ($property['cleaning_fee'] ?? 0) > 0 ? ', ' . t('form.cleaning') . ' ' . money((int) $property['cleaning_fee']) : '') ?></p>

                        <button class="btn-dash-primary prop-view-cta-btn" id="btn-confirm-booking" type="submit">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
                            <span>Confirmar reserva</span>
                        </button>
                    </form>

                    <div class="prop-view-card-trust">
                        <div class="prop-view-trust-item">
                            <span aria-hidden="true">🛡️</span>
                            <span>Reserva segura e direta com o anfitrião</span>
                        </div>
                        <div class="prop-view-trust-item">
                            <span aria-hidden="true">⚡</span>
                            <span>Confirmação sem intermediários ou sobretaxas</span>
                        </div>
                        <div class="prop-view-trust-item">
                            <span aria-hidden="true">💬</span>
                            <span>Suporte e contato direto com o proprietário</span>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    <?php endif; ?>
</section>
<?php if (!empty($use_api)): ?>
<?php page_data(['hash' => $hash ?? '', 'csrf' => $csrf ?? '']); ?>
<script src="<?= e(asset_url('/assets/pages/property.js')) ?>"></script>
<?php endif; ?>
