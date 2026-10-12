<section class="wrap">
    <?php $editing = (bool) $property; ?>
    <?php
    $posted = $posted ?? [];
    $current = is_array($property) ? $property : [];
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
    ?>
    <form class="panel" method="post" action="<?= $editing ? '/imoveis/' . e(property_ref($property)) : '/imoveis' ?>">
        <h1><?= $editing ? e(t('form.edit')) : e(t('form.new')) ?></h1>
        <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label><?= e(t('form.title')) ?>
            <input name="title" required <?= $titleLen <= 25 ? 'maxlength="25"' : '' ?> data-count="25" value="<?= e($titleValue) ?>">
            <span class="remaining" data-remaining data-remaining-label="<?= e(t('form.chars_left')) ?>"><?= e(t('form.chars_left')) . max(0, 25 - $titleLen) ?></span>
        </label>
        <label><?= e(t('form.description')) ?>
            <textarea name="description" rows="5" required <?= $descriptionLen <= 500 ? 'maxlength="500"' : '' ?> data-count="500"><?= e($descriptionValue) ?></textarea>
            <span class="remaining" data-remaining data-remaining-label="<?= e(t('form.chars_left')) ?>"><?= e(t('form.chars_left')) . max(0, 500 - $descriptionLen) ?></span>
        </label>
        <label><?= e(t('auth.cep')) ?> <input name="cep" inputmode="numeric" maxlength="9" required data-cep placeholder="00000-000" value="<?= e($cepValue) ?>"></label>
        <label><?= e(t('auth.street')) ?> <input name="street" readonly required data-street value="<?= e($streetValue) ?>"></label>
        <label><?= e(t('form.city')) ?>
            <input name="city" readonly required data-city value="<?= e($cityValue) ?>">
        </label>
        <div class="row">
            <label><?= e(t('auth.number')) ?> <input name="address_number" required value="<?= e($numberValue) ?>"></label>
            <label><?= e(t('auth.complement')) ?> <input name="address_complement" value="<?= e($complementValue) ?>"></label>
        </div>
        <p class="muted" data-cep-status><?= e(t('auth.cep_hint')) ?></p>
        <?php $roomLayout = Rooms::forForm($posted, $current ?: null); ?>
        <fieldset class="rooms" data-rooms data-room-label="<?= e(t('form.room')) ?>" data-bedrooms-label="<?= e(t('form.bedrooms')) ?>" data-beds-label="<?= e(t('form.beds')) ?>" data-max="999">
            <legend><?= e(t('form.rooms')) ?></legend>
            <p class="muted"><?= e(t('form.rooms_hint')) ?></p>
            <div data-room-list>
                <?php foreach ($roomLayout as $roomIndex => $room): ?>
                    <div class="room-card" data-room>
                        <div class="room-head">
                            <strong data-room-title><?= e(t('form.room')) ?> <?= $roomIndex + 1 ?></strong>
                            <button class="danger" type="button" data-remove-room><?= e(t('form.remove_room')) ?></button>
                        </div>
                        <div data-bed-list>
                            <?php foreach ($room['beds'] as $bedIndex => $bed): ?>
                                <?php $type = (string) $bed['type']; $variant = (string) ($bed['variant'] ?? ''); ?>
                                <div class="bed-row" data-bed>
                                    <select data-bed-type name="rooms[<?= $roomIndex ?>][beds][<?= $bedIndex ?>][type]">
                                        <option value="single" <?= $type === 'single' ? 'selected' : '' ?>><?= e(t('form.bed_single')) ?></option>
                                        <option value="double" <?= $type === 'double' ? 'selected' : '' ?>><?= e(t('form.bed_double')) ?></option>
                                        <option value="bunk" <?= $type === 'bunk' ? 'selected' : '' ?>><?= e(t('form.bed_bunk')) ?></option>
                                    </select>
                                    <select data-bed-variant name="rooms[<?= $roomIndex ?>][beds][<?= $bedIndex ?>][variant]" <?= $type === 'bunk' ? 'hidden' : '' ?>>
                                        <option value="" data-for="bunk" <?= $type === 'bunk' ? 'selected' : '' ?>></option>
                                        <option value="common" data-for="single double" <?= $type !== 'bunk' && ($variant === '' || $variant === 'common') ? 'selected' : '' ?>><?= e(t('form.bed_common')) ?></option>
                                        <option value="widow" data-for="single" <?= $variant === 'widow' ? 'selected' : '' ?>><?= e(t('form.bed_widow')) ?></option>
                                        <option value="queen" data-for="double" <?= $variant === 'queen' ? 'selected' : '' ?>><?= e(t('form.bed_queen')) ?></option>
                                        <option value="king" data-for="double" <?= $variant === 'king' ? 'selected' : '' ?>><?= e(t('form.bed_king')) ?></option>
                                    </select>
                                    <button class="danger" type="button" data-remove-bed><?= e(t('form.remove_bed')) ?></button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button class="danger" type="button" data-add-bed><?= e(t('form.add_bed')) ?></button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button class="danger" type="button" data-add-room><?= e(t('form.add_room')) ?></button>
            <p class="muted" data-room-count></p>
            <template data-bed-template>
                <div class="bed-row" data-bed>
                    <select data-bed-type>
                        <option value="single"><?= e(t('form.bed_single')) ?></option>
                        <option value="double"><?= e(t('form.bed_double')) ?></option>
                        <option value="bunk"><?= e(t('form.bed_bunk')) ?></option>
                    </select>
                    <select data-bed-variant>
                        <option value="" data-for="bunk"></option>
                        <option value="common" data-for="single double"><?= e(t('form.bed_common')) ?></option>
                        <option value="widow" data-for="single"><?= e(t('form.bed_widow')) ?></option>
                        <option value="queen" data-for="double"><?= e(t('form.bed_queen')) ?></option>
                        <option value="king" data-for="double"><?= e(t('form.bed_king')) ?></option>
                    </select>
                    <button class="danger" type="button" data-remove-bed><?= e(t('form.remove_bed')) ?></button>
                </div>
            </template>
        </fieldset>
        <?php
        $priceValue = array_key_exists('nightly_price', $posted) ? (string) $posted['nightly_price'] : '';
        if ($priceValue === '' && isset($current['nightly_price'])) {
            $priceValue = money_brl((int) $current['nightly_price']);
        }
        $cleaningValue = array_key_exists('cleaning_fee', $posted)
            ? (string) $posted['cleaning_fee']
            : money_brl((int) ($current['cleaning_fee'] ?? 0));
        ?>
        <div class="row">
            <label><?= e(t('form.guests')) ?> <input type="number" name="max_guests" min="1" max="999" required value="<?= e((string) ($property['max_guests'] ?? '')) ?>"></label>
            <label><?= e(t('form.price')) ?> <input name="nightly_price" required inputmode="decimal" placeholder="R$ 0,00" data-money value="<?= e($priceValue) ?>"></label>
        </div>
        <label><?= e(t('form.cleaning')) ?> <input name="cleaning_fee" inputmode="decimal" placeholder="R$ 0,00" data-money value="<?= e($cleaningValue) ?>"></label>
        <div class="row">
            <label><?= e(t('form.checkin')) ?> <input type="time" name="checkin_time" value="<?= e($property['checkin_time'] ?? '15:00') ?>"></label>
            <label><?= e(t('form.checkout')) ?> <input type="time" name="checkout_time" value="<?= e($property['checkout_time'] ?? '11:00') ?>"></label>
        </div>
        <label><?= e(t('form.min_nights')) ?> <input type="number" name="min_nights" min="1" value="<?= e((string) ($property['min_nights'] ?? 1)) ?>"></label>
        <?php $calendarLimit = class_exists('Settings') ? max(1, Settings::int('calendar_months')) : 12; ?>
        <?php $storedMonths = (int) ($current['calendar_months'] ?? 0); ?>
        <?php $calendarMonthsValue = (string) ($posted['calendar_months'] ?? ($storedMonths >= 1 ? $storedMonths : 6)); ?>
        <label><?= e(t('form.calendar_months')) ?>
            <input type="number" name="calendar_months" min="1" max="<?= $calendarLimit ?>" required value="<?= e($calendarMonthsValue) ?>">
        </label>
        <p class="muted"><?= e(sprintf(t('form.calendar_months_hint'), (string) $calendarLimit)) ?></p>
        <?php $selectedAmenities = Amenities::selected(is_array($property) ? $property : null); ?>
        <?php if (is_array($posted['amenity'] ?? null) || array_key_exists('noise_from', $posted)): ?>
            <?php $selectedAmenities = Amenities::fromInput($posted); ?>
            <?php $selectedAmenities['noise_from'] = (string) ($posted['noise_from'] ?? ''); ?>
            <?php $selectedAmenities['noise_until'] = (string) ($posted['noise_until'] ?? ''); ?>
        <?php endif; ?>
        <?php $noiseFrom = Amenities::clock((string) ($selectedAmenities['noise_from'] ?? '')) ?? '08:00'; ?>
        <?php $noiseUntil = Amenities::clock((string) ($selectedAmenities['noise_until'] ?? '')) ?? '22:00'; ?>
        <h2><?= e(t('form.amenities')) ?></h2>
        <?php foreach (Amenities::GROUPS as $group => $keys): ?>
            <div class="amenity-block">
            <fieldset class="amenity-set">
                <legend><?= e(t('amenity.cat.' . $group)) ?></legend>
                <div class="amenity-grid">
                    <?php foreach ($keys as $key): ?>
                        <?php if ($key === 'noise'): ?>
                            <div class="amenity-noise">
                                <label><input type="checkbox" name="amenity[noise]" value="1" data-noise <?= !empty($selectedAmenities['noise']) ? 'checked' : '' ?>> <?= e(t('amenity.noise')) ?></label>
                                <div class="noise-hours" data-noise-hours <?= empty($selectedAmenities['noise']) ? 'hidden' : '' ?>>
                                    <label><?= e(t('amenity.noise_from')) ?> <input type="time" name="noise_from" value="<?= e($noiseFrom) ?>"></label>
                                    <label><?= e(t('amenity.noise_until')) ?> <input type="time" name="noise_until" value="<?= e($noiseUntil) ?>"></label>
                                    <p class="muted"><?= e(t('amenity.noise_hint')) ?></p>
                                </div>
                            </div>
                        <?php else: ?>
                        <label><input type="checkbox" name="amenity[<?= e($key) ?>]" value="1" <?= !empty($selectedAmenities[$key]) ? 'checked' : '' ?>> <?= e(t('amenity.' . $key)) ?></label>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            </div>
        <?php endforeach; ?>
        <button class="primary<?= $editing ? ' is-off' : '' ?>" type="submit" <?= $editing ? 'data-save-house disabled' : '' ?>><?= e($editing ? t('form.save_changes') : t('form.save')) ?></button>
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
        <form class="panel" style="margin-top:16px" method="post" action="/imoveis/<?= e(property_ref($property)) ?>/fotos" enctype="multipart/form-data" data-upload-limit data-max-post="<?= $uploadBudget ?>" data-max-file="<?= $uploadFile ?>" data-max-count="<?= $photoRemaining ?>" data-msg-count="<?= e(t('form.upload_many')) ?>" data-msg-file="<?= e(t('form.upload_file')) ?>" data-msg-total="<?= e(t('form.upload_total')) ?>">
            <h2><?= e(t('form.photos')) ?></h2>
            <?php if (!empty($upload_notice ?? null)): ?><p class="error"><?= e($upload_notice) ?></p><?php endif; ?>
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <p class="muted"><?= e(sprintf(t('form.photos_property'), $photoLimit, count($photos))) ?></p>
            <?php if ($photoRemaining > 0): ?>
            <label><?= e(t('form.add_photos')) ?> <input type="file" name="fotos[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple required></label>
            <p class="muted"><?= e(sprintf(t('form.photos_limit'), $uploadCount, (int) floor($uploadFile / 1048576), (int) floor($uploadBudget / 1048576))) ?></p>
            <p class="error" data-upload-error hidden></p>
            <button class="primary" type="submit"><?= e(t('form.add_photos')) ?></button>
            <?php endif; ?>
        </form>
        <?php if ($photos): ?>
        <form class="panel" style="margin-top:16px" method="post" action="/imoveis/<?= e(property_ref($property)) ?>/fotos/excluir" data-photo-select data-confirm="<?= e(t('form.delete_selected_confirm')) ?>">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <div class="gallery">
                <?php foreach ($photos as $photo): ?>
                    <div class="photo-tile">
                        <label class="photo-pick">
                            <input class="photo-check" type="checkbox" name="fotos[]" value="<?= (int) $photo['id'] ?>">
                            <img src="<?= e($photo['path']) ?>" alt="<?= e(t('form.photos')) ?>" loading="lazy">
                            <span class="tick" aria-hidden="true">✓</span>
                        </label>
                        <?php if (!empty($photo['is_primary'])): ?><span class="cover-badge"><?= e(t('form.cover_current')) ?></span><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="muted"><?= count($photos) ?> <?= e(t('form.photos_count')) ?></p>
            <button class="primary is-off" type="button" formaction="/imoveis/<?= e(property_ref($property)) ?>/fotos/capa" data-cover-photo disabled><?= e(t('form.cover')) ?></button>
            <button class="danger" type="submit" data-delete-photos data-ready="0" disabled><?= e(t('form.delete_selected')) ?></button>
        </form>
        <?php else: ?>
            <p class="muted">0 <?= e(t('form.photos_count')) ?></p>
        <?php endif; ?>
        <form method="post" action="/imoveis/<?= e(property_ref($property)) ?>/excluir" onsubmit="return confirm(<?= json_encode(t('dash.remove_confirm'), JSON_UNESCAPED_UNICODE) ?>)" style="margin-top:12px">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button class="danger" type="submit"><?= e(t('dash.remove')) ?></button>
        </form>
        <p><a href="/imoveis/<?= e(property_ref($property)) ?>/calendario"><?= e(t('dash.calendar')) ?></a></p>
    <?php endif; ?>
</section>
