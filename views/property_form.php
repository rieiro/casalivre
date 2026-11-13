<?php
$editing = (bool) ($property ?? false);
$posted = $posted ?? [];
$current = is_array($property ?? null) ? $property : [];
$titleValue = (string) ($posted['title'] ?? ($current['title'] ?? ''));
$descriptionValue = (string) ($posted['description'] ?? ($current['description'] ?? ''));
$cepValue = (string) ($posted['cep'] ?? ($current['cep'] ?? ''));
$streetValue = (string) ($posted['street'] ?? ($current['street'] ?? ''));
$numberValue = (string) ($posted['address_number'] ?? ($current['address_number'] ?? ''));
$complementValue = (string) ($posted['address_complement'] ?? ($current['address_complement'] ?? ''));
$cityValue = city_label_for_cep($cepValue);
if ($cityValue === '') {
    $cityValue = (string) ($posted['city'] ?? ($current['city'] ?? ''));
}
$titleLen = function_exists('mb_strlen') ? mb_strlen($titleValue) : strlen($titleValue);
$descriptionLen = function_exists('mb_strlen') ? mb_strlen($descriptionValue) : strlen($descriptionValue);
$priceValue = array_key_exists('nightly_price', $posted) ? (string) $posted['nightly_price'] : '';
if ($priceValue === '' && isset($current['nightly_price'])) {
    $priceValue = money_brl((int) $current['nightly_price']);
}
$cleaningValue = array_key_exists('cleaning_fee', $posted)
    ? (string) $posted['cleaning_fee']
    : money_brl((int) ($current['cleaning_fee'] ?? 0));
$calendarLimit = class_exists('Settings') ? max(1, Settings::int('calendar_months')) : 12;
$storedMonths = (int) ($current['calendar_months'] ?? 0);
$calendarMonthsValue = (string) ($posted['calendar_months'] ?? ($storedMonths >= 1 ? $storedMonths : 6));

$selectedAmenities = Amenities::selected(is_array($property ?? null) ? $property : null);
if (is_array($posted['amenity'] ?? null) || array_key_exists('noise_from', $posted)) {
    $selectedAmenities = Amenities::fromInput($posted);
    $selectedAmenities['noise_from'] = (string) ($posted['noise_from'] ?? '');
    $selectedAmenities['noise_until'] = (string) ($posted['noise_until'] ?? '');
}
$noiseFrom = Amenities::clock((string) ($selectedAmenities['noise_from'] ?? '')) ?? '08:00';
$noiseUntil = Amenities::clock((string) ($selectedAmenities['noise_until'] ?? '')) ?? '22:00';
$roomLayout = Rooms::forForm($posted, $current ?: null);

$latValue = array_key_exists('lat', $posted) ? (string) $posted['lat'] : (isset($current['lat']) ? (string) (float) $current['lat'] : '');
$lngValue = array_key_exists('lng', $posted) ? (string) $posted['lng'] : (isset($current['lng']) ? (string) (float) $current['lng'] : '');
$cityParts = explode(',', $cityValue);
$cityKnown = $cityValue !== '' && count($cityParts) >= 2 ? CityRepository::exactCity(trim($cityParts[0]), trim($cityParts[1])) : null;
$initialCityLat = isset($cityKnown['lat']) ? (string) (float) $cityKnown['lat'] : '-27.5954';
$initialCityLng = isset($cityKnown['lng']) ? (string) (float) $cityKnown['lng'] : '-48.5480';
if ($latValue === '' && $lngValue === '') {
    $latValue = $initialCityLat;
    $lngValue = $initialCityLng;
}
?>
<link rel="stylesheet" href="<?= e(asset_url('/assets/vendor/maplibre-gl/maplibre-gl.css')) ?>">
<section class="prop-form-page wrap">
    <!-- Header Hero Luxury -->
    <div class="prop-form-header">
        <a class="prop-form-back-link" href="/painel">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
            <span>Voltar ao Painel</span>
        </a>
        <div class="prop-form-header-content">
            <span class="dash-badge-eyebrow"><?= $editing ? 'CONFIGURAÇÕES DO IMÓVEL' : 'NOVO ANÚNCIO' ?></span>
            <h1 class="dash-header-title"><?= $editing ? e(t('form.edit')) : e(t('form.new')) ?></h1>
            <p class="dash-header-desc">
                <?= $editing ? 'Atualize as informações, fotos, comodidades e valores do seu imóvel com facilidade.' : 'Cadastre sua propriedade para receber hóspedes e gerenciar reservas diretamente via Pix.' ?>
            </p>
        </div>
    </div>

    <?php if (!empty($error)): ?>
        <div class="prop-form-alert error" role="alert">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <div><strong>Atenção:</strong> <?= e($error) ?></div>
        </div>
    <?php endif; ?>

    <form class="prop-form-main" id="property-form" method="post" action="<?= $editing ? '/imoveis/' . e(property_ref($property)) : '/imoveis' ?>" data-editing="<?= $editing ? '1' : '0' ?>" data-hash="<?= $editing ? e(property_ref($property)) : '' ?>" data-script-error="<?= e(t('form.script_error')) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <!-- CARD 1: Informações Básicas -->
        <div class="prop-form-card">
            <div class="prop-form-card-head">
                <span class="prop-form-card-icon" aria-hidden="true">📝</span>
                <div>
                    <h2 class="prop-form-card-title">Informações Principais</h2>
                    <p class="prop-form-card-subtitle">Defina o nome de destaque e o descritivo atrativo para os hóspedes.</p>
                </div>
            </div>
            <div class="prop-form-card-body">
                <div class="prop-form-field">
                    <label class="prop-form-label" for="field-title">
                        <span><?= e(t('form.title')) ?> <strong class="req">*</strong></span>
                        <span class="remaining" data-remaining data-remaining-label="<?= e(t('form.chars_left')) ?>"><?= e(t('form.chars_left')) . max(0, 25 - $titleLen) ?></span>
                    </label>
                    <input id="field-title" class="prop-form-input" name="title" required <?= $titleLen <= 25 ? 'maxlength="25"' : '' ?> data-count="25" value="<?= e($titleValue) ?>" placeholder="Ex: Chácara Vista Linda, Loft Beira-Mar">
                </div>

                <div class="prop-form-field">
                    <label class="prop-form-label" for="field-description">
                        <span><?= e(t('form.description')) ?> <strong class="req">*</strong></span>
                        <span class="remaining" data-remaining data-remaining-label="<?= e(t('form.chars_left')) ?>"><?= e(t('form.chars_left')) . max(0, 500 - $descriptionLen) ?></span>
                    </label>
                    <textarea id="field-description" class="prop-form-textarea" name="description" rows="5" required <?= $descriptionLen <= 500 ? 'maxlength="500"' : '' ?> data-count="500" placeholder="Conte aos hóspedes o que torna sua propriedade única, diferenciais, ambiente e comodidades..."><?= e($descriptionValue) ?></textarea>
                </div>
            </div>
        </div>

        <!-- CARD 2: Localização e Endereço -->
        <div class="prop-form-card">
            <div class="prop-form-card-head">
                <span class="prop-form-card-icon" aria-hidden="true">📍</span>
                <div>
                    <h2 class="prop-form-card-title">Localização &amp; Endereço</h2>
                    <p class="prop-form-card-subtitle">Informe o CEP para preenchimento automático do endereço.</p>
                </div>
            </div>
            <div class="prop-form-card-body">
                <div class="prop-form-grid-2">
                    <div class="prop-form-field">
                        <label class="prop-form-label" for="field-cep"><?= e(t('auth.cep')) ?> <strong class="req">*</strong></label>
                        <input id="field-cep" class="prop-form-input" name="cep" inputmode="numeric" maxlength="9" required data-cep placeholder="00000-000" value="<?= e($cepValue) ?>">
                    </div>
                    <div class="prop-form-field">
                        <label class="prop-form-label" for="field-city"><?= e(t('form.city')) ?> <strong class="req">*</strong></label>
                        <input id="field-city" class="prop-form-input is-readonly" name="city" readonly required data-city value="<?= e($cityValue) ?>" placeholder="Preenchido via CEP">
                    </div>
                </div>

                <div class="prop-form-field">
                    <label class="prop-form-label" for="field-street"><?= e(t('auth.street')) ?> <strong class="req">*</strong></label>
                    <input id="field-street" class="prop-form-input is-readonly" name="street" readonly required data-street value="<?= e($streetValue) ?>" placeholder="Preenchido via CEP">
                </div>

                <div class="prop-form-grid-2">
                    <div class="prop-form-field">
                        <label class="prop-form-label" for="field-number"><?= e(t('auth.number')) ?> <strong class="req">*</strong></label>
                        <input id="field-number" class="prop-form-input" name="address_number" required value="<?= e($numberValue) ?>" placeholder="Ex: 120">
                    </div>
                    <div class="prop-form-field">
                        <label class="prop-form-label" for="field-complement"><?= e(t('auth.complement')) ?></label>
                        <input id="field-complement" class="prop-form-input" name="address_complement" value="<?= e($complementValue) ?>" placeholder="Ex: Apto 302, Bloco B">
                    </div>
                </div>
                <p class="prop-form-hint" data-cep-status><?= e(t('auth.cep_hint')) ?></p>

                <!-- Localização Exata no Mapa -->
                <div class="prop-pinpoint-map-section">
                    <div class="prop-pinpoint-header">
                        <div>
                            <span class="prop-pinpoint-title">Localização Exata no Mapa</span>
                            <p class="prop-form-hint">Arraste o pin ou clique no mapa para posicionar a localização exata do seu imóvel. Apenas a localização aproximada será exibida nas buscas públicas para segurança.</p>
                        </div>
                        <div class="prop-pinpoint-badge" id="pinpoint-coords-badge">
                            <span>Lat: <strong id="pinpoint-lat-disp"><?= e($latValue !== '' ? number_format((float) $latValue, 5, '.', '') : '-') ?></strong></span>
                            <span>Lng: <strong id="pinpoint-lng-disp"><?= e($lngValue !== '' ? number_format((float) $lngValue, 5, '.', '') : '-') ?></strong></span>
                        </div>
                    </div>
                    <input type="hidden" name="lat" id="field-lat" value="<?= e($latValue) ?>">
                    <input type="hidden" name="lng" id="field-lng" value="<?= e($lngValue) ?>">
                    <div id="property-pinpoint-map" class="property-pinpoint-map"
                         data-tiles="/tiles/brasil.pmtiles"
                         data-style="<?= e(asset_url(Theme::mapStylePath())) ?>"
                         data-worker="<?= e(asset_url('/assets/vendor/maplibre-gl/maplibre-gl-csp-worker.js')) ?>"
                         data-lat="<?= e($latValue) ?>"
                         data-lng="<?= e($lngValue) ?>"
                         data-city="<?= e($cityValue) ?>"
                         data-city-lat="<?= e($initialCityLat) ?>"
                         data-city-lng="<?= e($initialCityLng) ?>"
                         aria-label="Mapa para ajuste fino da localização do imóvel">
                    </div>
                </div>
            </div>
        </div>

        <!-- CARD 3: Quartos e Camas -->
        <div class="prop-form-card">
            <div class="prop-form-card-head">
                <span class="prop-form-card-icon" aria-hidden="true">🛏️</span>
                <div>
                    <h2 class="prop-form-card-title"><?= e(t('form.rooms')) ?></h2>
                    <p class="prop-form-card-subtitle"><?= e(t('form.rooms_hint')) ?></p>
                </div>
            </div>
            <div class="prop-form-card-body">
                <fieldset class="rooms prop-rooms-fieldset" data-rooms data-room-label="<?= e(t('form.room')) ?>" data-bedrooms-label="<?= e(t('form.bedrooms')) ?>" data-beds-label="<?= e(t('form.beds')) ?>" data-bed-one="<?= e(t('form.bed_one')) ?>" data-bed-many="<?= e(t('form.bed_many')) ?>" data-max="999">
                    <section class="rooms-editor" aria-labelledby="rooms-editor-title">
                        <h3 class="rooms-subtitle" id="rooms-editor-title"><?= e(t('form.beds_add_title')) ?></h3>
                        <div data-room-list class="prop-room-list">
                            <?php foreach ($roomLayout as $roomIndex => $room): ?>
                                <div class="room-card prop-room-card" data-room>
                                    <div class="room-head prop-room-head">
                                        <strong data-room-title><?= e(t('form.room')) ?> <?= $roomIndex + 1 ?></strong>
                                        <button class="btn-room-del danger" type="button" data-remove-room><?= e(t('form.remove_room')) ?></button>
                                    </div>
                                    <div data-bed-list class="prop-bed-list">
                                        <?php foreach ($room['beds'] as $bedIndex => $bed): ?>
                                            <?php $type = (string) $bed['type']; $variant = (string) ($bed['variant'] ?? ''); ?>
                                            <div class="bed-row prop-bed-row" data-bed>
                                                <select class="prop-form-select" data-bed-type name="rooms[<?= $roomIndex ?>][beds][<?= $bedIndex ?>][type]">
                                                    <option value="single" <?= $type === 'single' ? 'selected' : '' ?>><?= e(t('form.bed_single')) ?></option>
                                                    <option value="double" <?= $type === 'double' ? 'selected' : '' ?>><?= e(t('form.bed_double')) ?></option>
                                                    <option value="bunk" <?= $type === 'bunk' ? 'selected' : '' ?>><?= e(t('form.bed_bunk')) ?></option>
                                                </select>
                                                <select class="prop-form-select" data-bed-variant name="rooms[<?= $roomIndex ?>][beds][<?= $bedIndex ?>][variant]" <?= $type === 'bunk' ? 'hidden' : '' ?>>
                                                    <option value="" data-for="bunk" <?= $type === 'bunk' ? 'selected' : '' ?>></option>
                                                    <option value="common" data-for="single double" <?= $type !== 'bunk' && ($variant === '' || $variant === 'common') ? 'selected' : '' ?>><?= e(t('form.bed_common')) ?></option>
                                                    <option value="widow" data-for="single" <?= $variant === 'widow' ? 'selected' : '' ?>><?= e(t('form.bed_widow')) ?></option>
                                                    <option value="queen" data-for="double" <?= $variant === 'queen' ? 'selected' : '' ?>><?= e(t('form.bed_queen')) ?></option>
                                                    <option value="king" data-for="double" <?= $variant === 'king' ? 'selected' : '' ?>><?= e(t('form.bed_king')) ?></option>
                                                </select>
                                                <button class="btn-bed-del danger" type="button" data-remove-bed title="<?= e(t('form.remove_bed')) ?>">✕</button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button class="btn-add-bed" type="button" data-add-bed>+ <?= e(t('form.add_bed')) ?></button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button class="btn-add-room" type="button" data-add-room>+ <?= e(t('form.add_room')) ?></button>
                    </section>
                    <section class="rooms-summary prop-rooms-summary" aria-labelledby="rooms-summary-title" aria-live="polite">
                        <h3 class="rooms-subtitle" id="rooms-summary-title"><?= e(t('form.room_summary_title')) ?></h3>
                        <ul data-room-summary class="prop-room-tags"></ul>
                        <p class="muted prop-room-count" data-room-count></p>
                    </section>
                    <template data-bed-template>
                        <div class="bed-row prop-bed-row" data-bed>
                            <select class="prop-form-select" data-bed-type>
                                <option value="single"><?= e(t('form.bed_single')) ?></option>
                                <option value="double"><?= e(t('form.bed_double')) ?></option>
                                <option value="bunk"><?= e(t('form.bed_bunk')) ?></option>
                            </select>
                            <select class="prop-form-select" data-bed-variant>
                                <option value="" data-for="bunk"></option>
                                <option value="common" data-for="single double"><?= e(t('form.bed_common')) ?></option>
                                <option value="widow" data-for="single"><?= e(t('form.bed_widow')) ?></option>
                                <option value="queen" data-for="double"><?= e(t('form.bed_queen')) ?></option>
                                <option value="king" data-for="double"><?= e(t('form.bed_king')) ?></option>
                            </select>
                            <button class="btn-bed-del danger" type="button" data-remove-bed title="<?= e(t('form.remove_bed')) ?>">✕</button>
                        </div>
                    </template>
                </fieldset>
            </div>
        </div>

        <!-- CARD 4: Capacidade, Preços e Regras de Estadia -->
        <div class="prop-form-card">
            <div class="prop-form-card-head">
                <span class="prop-form-card-icon" aria-hidden="true">💰</span>
                <div>
                    <h2 class="prop-form-card-title">Valores &amp; Condições de Hospedagem</h2>
                    <p class="prop-form-card-subtitle">Configure diárias, taxa de limpeza, capacidade e chave Pix para recebimento direto.</p>
                </div>
            </div>
            <div class="prop-form-card-body">
                <div class="prop-form-grid-3">
                    <div class="prop-form-field">
                        <label class="prop-form-label" for="field-guests"><?= e(t('form.guests')) ?> <strong class="req">*</strong></label>
                        <input id="field-guests" class="prop-form-input" type="number" name="max_guests" min="1" max="999" required value="<?= e((string) ($property['max_guests'] ?? '')) ?>" placeholder="Ex: 4">
                    </div>
                    <div class="prop-form-field">
                        <label class="prop-form-label" for="field-price"><?= e(t('form.price')) ?> <strong class="req">*</strong></label>
                        <input id="field-price" class="prop-form-input" name="nightly_price" required inputmode="decimal" placeholder="R$ 0,00" data-money value="<?= e($priceValue) ?>">
                    </div>
                    <div class="prop-form-field">
                        <label class="prop-form-label" for="field-cleaning"><?= e(t('form.cleaning')) ?></label>
                        <input id="field-cleaning" class="prop-form-input" name="cleaning_fee" inputmode="decimal" placeholder="R$ 0,00" data-money value="<?= e($cleaningValue) ?>">
                    </div>
                </div>

                <div class="prop-form-field">
                    <label class="prop-form-label" for="field-pix">
                        <span><?= e(t('form.pix_key')) ?></span>
                        <span class="prop-pix-badge">⚡ Recebimento Direto</span>
                    </label>
                    <input id="field-pix" class="prop-form-input" name="pix_key" maxlength="100" placeholder="E-mail, CPF/CNPJ, Telefone ou Chave Aleatória" value="<?= e((string) ($posted['pix_key'] ?? ($current['pix_key'] ?? ''))) ?>">
                    <p class="prop-form-hint">Esta chave será apresentada ao hóspede na página segura de pagamento Pix para depósito direto.</p>
                </div>

                <div class="prop-form-grid-2">
                    <div class="prop-form-field">
                        <label class="prop-form-label" for="field-checkin"><?= e(t('form.checkin')) ?></label>
                        <input id="field-checkin" class="prop-form-input" type="time" name="checkin_time" value="<?= e($property['checkin_time'] ?? '15:00') ?>">
                    </div>
                    <div class="prop-form-field">
                        <label class="prop-form-label" for="field-checkout"><?= e(t('form.checkout')) ?></label>
                        <input id="field-checkout" class="prop-form-input" type="time" name="checkout_time" value="<?= e($property['checkout_time'] ?? '11:00') ?>">
                    </div>
                </div>

                <div class="prop-form-grid-2">
                    <div class="prop-form-field">
                        <label class="prop-form-label" for="field-min-nights"><?= e(t('form.min_nights')) ?></label>
                        <input id="field-min-nights" class="prop-form-input" type="number" name="min_nights" min="1" value="<?= e((string) ($property['min_nights'] ?? 1)) ?>">
                    </div>
                    <div class="prop-form-field">
                        <label class="prop-form-label" for="field-cal-months"><?= e(t('form.calendar_months')) ?></label>
                        <input id="field-cal-months" class="prop-form-input" type="number" name="calendar_months" min="1" max="<?= $calendarLimit ?>" required value="<?= e($calendarMonthsValue) ?>">
                    </div>
                </div>
                <p class="prop-form-hint"><?= e(sprintf(t('form.calendar_months_hint'), (string) $calendarLimit)) ?></p>
            </div>
        </div>

        <!-- CARD 5: Comodidades e Regras -->
        <div class="prop-form-card">
            <div class="prop-form-card-head">
                <span class="prop-form-card-icon" aria-hidden="true">✨</span>
                <div>
                    <h2 class="prop-form-card-title"><?= e(t('form.amenities')) ?></h2>
                    <p class="prop-form-card-subtitle">Selecione todos os itens oferecidos para valorizar seu anúncio nas buscas.</p>
                </div>
            </div>
            <div class="prop-form-card-body">
                <?php foreach (Amenities::GROUPS as $group => $keys): ?>
                    <div class="amenity-block prop-amenity-block">
                        <fieldset class="amenity-set prop-amenity-set">
                            <legend class="prop-amenity-legend"><?= e(t('amenity.cat.' . $group)) ?></legend>
                            <div class="amenity-grid prop-amenity-grid">
                                <?php foreach ($keys as $key): ?>
                                    <?php if ($key === 'noise'): ?>
                                        <div class="amenity-noise prop-amenity-noise">
                                            <label class="prop-checkbox-label">
                                                <input type="checkbox" name="amenity[noise]" value="1" data-noise <?= !empty($selectedAmenities['noise']) ? 'checked' : '' ?>>
                                                <span><?= e(t('amenity.noise')) ?></span>
                                            </label>
                                            <div class="noise-hours prop-noise-hours" data-noise-hours <?= empty($selectedAmenities['noise']) ? 'hidden' : '' ?>>
                                                <div class="prop-form-grid-2">
                                                    <div>
                                                        <label class="prop-form-label"><?= e(t('amenity.noise_from')) ?></label>
                                                        <input class="prop-form-input" type="time" name="noise_from" value="<?= e($noiseFrom) ?>">
                                                    </div>
                                                    <div>
                                                        <label class="prop-form-label"><?= e(t('amenity.noise_until')) ?></label>
                                                        <input class="prop-form-input" type="time" name="noise_until" value="<?= e($noiseUntil) ?>">
                                                    </div>
                                                </div>
                                                <p class="prop-form-hint"><?= e(t('amenity.noise_hint')) ?></p>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <label class="prop-checkbox-label">
                                            <input type="checkbox" name="amenity[<?= e($key) ?>]" value="1" <?= !empty($selectedAmenities[$key]) ? 'checked' : '' ?>>
                                            <span><?= e(t('amenity.' . $key)) ?></span>
                                        </label>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Submit Bar -->
        <div class="prop-form-submit-bar">
            <button class="btn-dash-primary prop-submit-btn primary<?= $editing ? ' is-off' : '' ?>" type="submit" <?= $editing ? 'data-save-house disabled' : '' ?>>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                <span><?= e($editing ? t('form.save_changes') : t('form.save')) ?></span>
            </button>
            <a class="btn-dash-ghost prop-cancel-btn" href="/painel">Cancelar</a>
        </div>
    </form>

    <?php if ($editing): ?>
        <?php
        $uploadFile = ini_bytes((string) ini_get('upload_max_filesize'));
        $uploadPost = ini_bytes((string) ini_get('post_max_size'));
        $uploadCount = max(1, (int) ini_get('max_file_uploads'));
        $uploadBudget = max(1, $uploadPost - 1048576);
        $photoLimit = class_exists('Settings') ? max(1, Settings::int('max_photos_per_property') ?: 40) : 40;
        $photoRemaining = max(0, $photoLimit - count($photos));
        $uploadCount = min($uploadCount, max(1, $photoRemaining));
        ?>
        <!-- CARD FOTOS -->
        <div class="prop-form-card prop-photos-card">
            <div class="prop-form-card-head">
                <span class="prop-form-card-icon" aria-hidden="true">📸</span>
                <div>
                    <h2 class="prop-form-card-title"><?= e(t('form.photos')) ?></h2>
                    <p class="prop-form-card-subtitle"><?= e(sprintf(t('form.photos_property'), $photoLimit, count($photos))) ?></p>
                </div>
            </div>
            <div class="prop-form-card-body">
                <form class="prop-upload-box" method="post" action="/imoveis/<?= e(property_ref($property)) ?>/fotos" enctype="multipart/form-data" data-upload-limit data-max-post="<?= $uploadBudget ?>" data-max-file="<?= $uploadFile ?>" data-max-count="<?= $photoRemaining ?>" data-msg-count="<?= e(t('form.upload_many')) ?>" data-msg-file="<?= e(t('form.upload_file')) ?>" data-msg-total="<?= e(t('form.upload_total')) ?>">
                    <?php if (!empty($upload_notice ?? null)): ?><p class="prop-form-alert error"><?= e($upload_notice) ?></p><?php endif; ?>
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <?php if ($photoRemaining > 0): ?>
                        <div class="prop-upload-drop">
                            <label class="prop-upload-label">
                                <span class="prop-upload-icon">📤</span>
                                <span class="prop-upload-title"><?= e(t('form.add_photos')) ?></span>
                                <span class="prop-upload-info"><?= e(sprintf(t('form.photos_limit'), $uploadCount, (int) floor($uploadFile / 1048576), (int) floor($uploadBudget / 1048576))) ?></span>
                                <input class="prop-file-input" type="file" name="fotos[]" accept="image/jpeg,image/png,image/webp" multiple required>
                            </label>
                        </div>
                        <p class="error" data-upload-error hidden></p>
                        <button class="btn-dash-primary mt-12" type="submit"><?= e(t('form.add_photos')) ?></button>
                    <?php endif; ?>
                </form>

                <?php if ($photos): ?>
                    <form class="prop-gallery-form mt-24" method="post" action="/imoveis/<?= e(property_ref($property)) ?>/fotos/excluir" data-photo-select data-confirm="<?= e(t('form.delete_selected_confirm')) ?>">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <div class="gallery prop-photo-grid">
                            <?php foreach ($photos as $photo): ?>
                                <div class="photo-tile prop-photo-tile" data-photo-id="<?= (int) $photo['id'] ?>" data-photo-status="<?= e((string) ($photo['status'] ?? 'ready')) ?>">
                                    <label class="photo-pick prop-photo-pick">
                                        <input class="photo-check" type="checkbox" name="fotos[]" value="<?= (int) $photo['id'] ?>">
                                        <?php $photoStatus = (string) ($photo['status'] ?? 'ready'); ?>
                                        <?php if ($photoStatus === 'processing'): ?>
                                            <span class="photo-wait" role="img" aria-label="<?= e(t('form.photo_processing')) ?>" data-photo-wait><?= e(t('form.photo_processing')) ?></span>
                                        <?php elseif ($photoStatus === 'failed'): ?>
                                            <?php $failText = photo_fail_text($photo['fail_reason']); ?>
                                            <span class="photo-wait is-failed" role="img" aria-label="<?= e($failText) ?>"><?= e($failText) ?></span>
                                        <?php else: ?>
                                            <img src="<?= e(photo_url(photo_variant($photo['path'], 'thumb'))) ?>" alt="<?= e(t('form.photos')) ?>" loading="lazy">
                                        <?php endif; ?>
                                        <span class="tick" aria-hidden="true">✓</span>
                                    </label>
                                    <?php if ($photoStatus === 'failed'): ?>
                                        <button class="danger btn-photo-del" type="submit" data-photo-remove formaction="/imoveis/<?= e(property_ref($property)) ?>/fotos/<?= (int) $photo['id'] ?>/excluir"><?= e(t('form.photo_remove')) ?></button>
                                    <?php endif; ?>
                                    <?php if (!empty($photo['is_primary'])): ?><span class="cover-badge prop-cover-badge"><?= e(t('form.cover_current')) ?></span><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="prop-gallery-actions">
                            <span class="muted"><?= count($photos) ?> <?= e(t('form.photos_count')) ?></span>
                            <div class="prop-gallery-btns">
                                <button class="btn-dash-action primary is-off" type="button" formaction="/imoveis/<?= e(property_ref($property)) ?>/fotos/capa" data-cover-photo disabled><?= e(t('form.cover')) ?></button>
                                <button class="btn-dash-action danger" type="submit" data-delete-photos data-ready="0" disabled><?= e(t('form.delete_selected')) ?></button>
                            </div>
                        </div>
                    </form>
                <?php else: ?>
                    <p class="muted">0 <?= e(t('form.photos_count')) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Links e Exclusão -->
        <div class="prop-form-danger-zone">
            <div class="prop-zone-links">
                <a class="btn-dash-action is-cal" href="/imoveis/<?= e(property_ref($property)) ?>/calendario">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span><?= e(t('dash.calendar')) ?></span>
                </a>
                <a class="btn-dash-action is-view" href="/imoveis/<?= e(property_ref($property)) ?>" target="_blank">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    <span>Ver Anúncio</span>
                </a>
            </div>
            <form method="post" action="/imoveis/<?= e(property_ref($property)) ?>/excluir" data-confirm-submit="<?= e(t('dash.remove_confirm')) ?>">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button class="btn-dash-action is-delete" type="submit"><?= e(t('dash.remove')) ?></button>
            </form>
        </div>
    <?php endif; ?>
</section>
<script src="<?= e(asset_url('/assets/vendor/maplibre-gl/maplibre-gl-csp.js')) ?>"></script>
<script src="<?= e(asset_url('/assets/vendor/pmtiles/pmtiles.js')) ?>"></script>
<script src="<?= e(asset_url('/assets/form-data.js')) ?>"></script>
<script src="<?= e(asset_url('/assets/pages/property-form.js')) ?>"></script>
