<?php
$marked = [];
foreach ($days as $day) {
    $marked[$day['day']] = $day;
}
$cursor = new DateTimeImmutable('first day of this month');
$limit = new DateTimeImmutable(max_calendar_day($property));
$monthCount = ((int) $limit->format('Y') - (int) $cursor->format('Y')) * 12 + ((int) $limit->format('n') - (int) $cursor->format('n')) + 1;
$today = date('Y-m-d');
$maxDay = max_calendar_day($property);
$feeds = $feeds ?? [];
require_once dirname(__DIR__) . '/src/IcalExportService.php';
require_once dirname(__DIR__) . '/src/OtaPlatformRegistry.php';
$icalExportData = $icalExportData ?? IcalExportService::listForProperty((int) $property['id']);
$icalExportBase = request_base() . '/ical/export/';
?>
<section class="wrap">
    <h1 class="cal-title" data-cal-title><?= e(t('cal.title')) ?> · <?= e($property['title']) ?></h1>
    <?php if ($message): ?>
        <?php
        $messages = is_array($message) ? $message : array_filter(array_map('trim', explode("
", (string) $message)));
        foreach ($messages as $msg):
            $rawTone = (string) ($message_tone ?? 'ok');
            $tone = in_array($rawTone, ['error', 'warning', 'info'], true) ? $rawTone : 'ok';
        ?>
            <div hidden data-flash-alert data-flash-tone="<?= e($tone) ?>" class="alert <?= e($tone) ?>"><?= e($msg) ?></div>
        <?php endforeach; ?>
    <?php endif; ?>
    <div class="cal-board">
        <div class="cal-months">
            <ul class="cal-legend cal-legend-desktop">
                <li><span class="swatch open"></span> <?= e(t('cal.legend_open')) ?></li>
                <li><span class="swatch sistema"></span> <?= e(t('cal.legend_system')) ?></li>
                <li><span class="swatch sync"></span> <?= e(t('cal.legend_sync')) ?></li>
                <li><span class="swatch reserva"></span> <?= e(t('cal.legend_booking')) ?></li>
                <li><span class="swatch prereserva"></span> <?= e(t('cal.legend_prereserva')) ?></li>
            </ul>
            <?php
            $weekdays = match (current_locale()) {
                'en' => ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'],
                'es' => ['Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sá', 'Do'],
                default => ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'],
            };
            ?>
            <?php for ($m = 0; $m < $monthCount; $m++): ?>
                <?php $month = $cursor->modify("+$m month"); $start = (int) $month->format('N'); $count = (int) $month->format('t'); ?>
                <section class="cal-month<?= $m === 0 ? ' is-shown' : '' ?>">
                    <div class="cal-month-head">
                        <button type="button" class="cal-nav" data-cal-prev aria-label="<?= e(t('cal.prev_month')) ?>"><<</button>
                        <h2><?= e(month_label($month)) ?></h2>
                        <button type="button" class="cal-nav" data-cal-next aria-label="<?= e(t('cal.next_month')) ?>">>></button>
                    </div>
                    <div class="cal-weekdays">
                        <?php foreach ($weekdays as $weekday): ?><span><?= e($weekday) ?></span><?php endforeach; ?>
                    </div>
                    <div class="cal-grid">
                        <?php for ($i = 1; $i < $start; $i++): ?><span></span><?php endfor; ?>
                        <?php for ($d = 1; $d <= $count; $d++): ?>
                            <?php
                            $iso = $month->format('Y-m-') . str_pad((string) $d, 2, '0', STR_PAD_LEFT);
                            $info = $marked[$iso] ?? null;
                            $disabled = $iso < $today || $iso > $maxDay;
                            $origin = (string) ($info['origin'] ?? '');
                            if ($origin === '' && !empty($info['blocked'])) {
                                $origin = 'sistema';
                            }
                            $nightPrice = (isset($info['price']) && $info['price'] !== null && $info['price'] !== '')
                                ? (int) $info['price']
                                : (int) $property['nightly_price'];
                            $nightMin = (isset($info['min_nights']) && $info['min_nights'] !== null && $info['min_nights'] !== '')
                                ? (int) $info['min_nights']
                                : (int) $property['min_nights'];
                            ?>
                            <button type="button" class="cal-day<?= $origin !== '' ? ' ' . e($origin) : '' ?>" data-cal-day="<?= e($iso) ?>" data-day-num="<?= $d ?>" data-origin="<?= e($origin) ?>" data-price="<?= e(money_input($nightPrice)) ?>" data-min="<?= $nightMin ?>" <?= $origin === 'pre-reserva' ? 'title="' . e(t('cal.legend_prereserva')) . '"' : '' ?> <?= $disabled ? 'disabled' : '' ?>><span class="cal-corner" data-cal-corner><?= $d ?></span><span class="cal-main" data-cal-main><?= $d ?></span></button>
                        <?php endfor; ?>
                    </div>
                    <ul class="cal-legend cal-legend-mobile">
                        <li><span class="swatch open"></span> <?= e(t('cal.legend_open')) ?></li>
                        <li><span class="swatch sistema"></span> <?= e(t('cal.legend_system')) ?></li>
                        <li><span class="swatch sync"></span> <?= e(t('cal.legend_sync')) ?></li>
                        <li><span class="swatch reserva"></span> <?= e(t('cal.legend_booking')) ?></li>
                        <li><span class="swatch prereserva"></span> <?= e(t('cal.legend_prereserva')) ?></li>
                    </ul>
                </section>
            <?php endfor; ?>
        </div>
        <form class="cal-controls" method="post" action="/imoveis/<?= e(property_ref($property)) ?>/calendario" data-cal-form data-months="<?= e(implode(',', current_locale() === 'en' ? ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'] : (current_locale() === 'es' ? ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'] : ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez']))) ?>" data-min-default="<?= e(t('cal.min_nights')) ?>" data-min-from="<?= e(t('cal.min_from')) ?>" data-min-range="<?= e(t('cal.min_range')) ?>" data-cleaning-fee="<?= (int) ($property['cleaning_fee'] ?? 0) ?>">
            <div class="cal-view" role="group" aria-label="<?= e(t('cal.view')) ?>">
                <button type="button" data-cal-view="day" class="is-on" aria-pressed="true"><?= e(t('cal.view_day')) ?></button>
                <button type="button" data-cal-view="price" aria-pressed="false"><?= e(t('cal.view_price')) ?></button>
                <button type="button" data-cal-view="min" aria-pressed="false"><?= e(t('cal.view_nights')) ?></button>
            </div>
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="inicio" data-cal-start>
            <input type="hidden" name="fim" data-cal-end>
            <input type="hidden" name="cal_view" value="<?= e((string) (($restore['view'] ?? 'day'))) ?>" data-cal-view-input>
            <p class="cal-summary is-hint" data-cal-summary><?= e(t('cal.range_hint')) ?></p>
            <div class="cal-fields">
                <label><?= e(t('cal.price')) ?> <input name="price" data-cal-price placeholder="<?= e(t('cal.price_hint')) ?>"></label>
                <label><span data-min-label><?= e(t('cal.min_nights')) ?></span> <input type="number" name="min_nights" min="1" data-cal-min value="<?= (int) $property['min_nights'] ?>"></label>
            </div>
            <div class="cal-summary-table" data-cal-summary-table hidden>
                <div class="cal-summary-row">
                    <span class="cal-summary-label" data-cal-stay-formula></span>
                    <span class="cal-summary-val" data-cal-stay-total></span>
                </div>
                <div class="cal-summary-row">
                    <span class="cal-summary-label"><?= e(t('cal.taxes')) ?></span>
                    <span class="cal-summary-val" data-cal-taxes-val>R$ 0</span>
                </div>
                <div class="cal-summary-row cal-summary-total">
                    <span class="cal-summary-label"><?= e(t('cal.total')) ?></span>
                    <span class="cal-summary-val" data-cal-grand-total></span>
                </div>
            </div>
            <div class="cal-actions">
                <button class="danger is-off" type="submit" name="acao" value="bloquear" data-cal-block disabled><?= e(t('cal.block')) ?></button>
                <button class="primary is-off" type="submit" name="acao" value="abrir" data-cal-open disabled><?= e(t('cal.open_action')) ?></button>
                <button class="primary is-off" type="submit" name="acao" value="salvar" data-cal-save disabled><?= e(t('cal.save_changes')) ?></button>
            </div>
            <button class="primary is-off" type="submit" name="acao" value="cotacao" data-cal-quote disabled><?= e(t('cal.generate_quote')) ?></button>
            <p class="muted cal-sync-locked"><?= e(t('cal.sync_locked')) ?></p>
        </form>
    </div>
    <div class="sync-board" id="sincronizar-calendario">
        <div class="panel">
            <h2><?= e(t('cal.sync')) ?></h2>
            <h3 class="sync-group-title"><?= e(t('cal.imported_calendars')) ?></h3>
            <div class="sync-scroll">
                <table class="sync-table">
                    <thead>
                        <tr>
                            <th><?= e(t('cal.col_title')) ?></th>
                            <th><?= e(t('cal.col_status')) ?></th>
                            <th><?= e(t('cal.col_synced')) ?></th>
                            <th><?= e(t('cal.col_actions')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$feeds): ?>
                            <tr><td colspan="4" class="muted"><?= e(t('cal.feed_empty')) ?></td></tr>
                        <?php endif; ?>
                        <?php foreach ($feeds as $feed): ?>
                            <?php
                            $feedStatus = (string) ($feed['status'] ?? 'active');
                            $feedMode = (string) ($feed['verification_mode'] ?? 'sync_only');
                            $isOta = $feedMode === 'ota';
                            $platform = (string) ($feed['source_platform'] ?? 'other');
                            $platformLabel = OtaPlatformRegistry::label($platform);
                            $reviewStatus = (string) ($feed['review_status'] ?? 'approved');
                            $isVerified = $isOta && $feed['verified_at'] !== null;
                            $isPendingReview = $isVerified && $reviewStatus === 'pending_review';
                            $isRejected = $isOta && $reviewStatus === 'rejected';
                            $statusLabel = $isRejected
                                ? t('cal.review_rejected_badge')
                                : ($isPendingReview ? t('cal.review_pending') : match ($feedStatus) {
                                    'pending_verification' => t('cal.status_pending_verification'),
                                    'error' => t('cal.status_error'),
                                    default => t('cal.status_active'),
                                });
                            $statusClass = $isRejected
                                ? 'is-error'
                                : ($isPendingReview ? 'is-review' : match ($feedStatus) {
                                    'pending_verification' => 'is-pending',
                                    'error' => 'is-error',
                                    default => 'is-active',
                                });
                            $reason = match ((string) ($feed['last_error'] ?? '')) {
                                'bad_url', 'private_ip' => t('cal.err_url'),
                                'http_4xx' => t('cal.err_http'),
                                'not_ics' => t('cal.err_ics'),
                                'too_big' => t('cal.err_size'),
                                'timeout' => t('cal.err_timeout'),
                                'dns' => t('cal.err_dns'),
                                'http_5xx' => t('cal.err_server'),
                                default => '',
                            };
                            $syncedAt = (string) ($feed['last_synced_at'] ?? '');
                            $synced = $syncedAt !== '' ? strtotime($syncedAt . ' UTC') : false;
                            $challengeBr = '';
                            if (!empty($feed['challenge_date'])) {
                                $challengeDt = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $feed['challenge_date'], new DateTimeZone('UTC'));
                                $challengeBr = $challengeDt === false ? '' : $challengeDt->format('d/m/Y');
                            }
                            $feedId = (int) $feed['id'];
                            ?>
                            <tr>
                                <td>
                                    <strong><?= e((string) ($feed['title'] ?? '')) ?></strong>
                                    <?php if ($isOta && $platformLabel !== ''): ?>
                                        <br><span class="sync-platform"><?= e($platformLabel) ?></span>
                                    <?php endif; ?>
                                    <?php if ($isOta && !empty($feed['property_url'])): ?>
                                        <br><a class="fine sync-property-link" href="<?= e((string) $feed['property_url']) ?>" target="_blank" rel="noopener noreferrer"><?= e((string) $feed['property_url']) ?></a>
                                    <?php endif; ?>
                                    <?php if ($isOta && !empty($feed['canonical_listing_id'])): ?>
                                        <br><span class="muted fine sync-canonical"><?= e((string) $feed['canonical_listing_id']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="sync-badge <?= e($statusClass) ?>"><?= e($statusLabel) ?></span>
                                    <?php if ($isPendingReview): ?>
                                        <br><span class="sync-pending-detail"><?= e($challengeBr !== ''
                                            ? t('cal.review_pending_detail', ['platform' => $platformLabel, 'date' => $challengeBr])
                                            : t('cal.review_pending_hint')) ?></span>
                                    <?php endif; ?>
                                    <?php if ($isRejected && !empty($feed['review_note'])): ?>
                                        <br><span class="muted fine"><?= e(t('cal.review_rejected_reason_label')) ?> <?= e((string) $feed['review_note']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($isVerified && !$isPendingReview && !$isRejected && $challengeBr !== ''): ?>
                                        <br><span class="muted fine"><?= e(t('cal.verified_unblock_hint', ['date' => $challengeBr])) ?></span>
                                    <?php endif; ?>
                                    <?php if ($feedStatus === 'error' && $reason !== ''): ?>
                                        <br><span class="muted fine"><?= e($reason) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= $synced === false
                                        ? '—'
                                        : max(0, (int) floor((time() - $synced) / 60)) . ' ' . e(t('cal.minutes_ago')) ?>
                                </td>
                                <td>
                                    <div class="sync-actions">
                                        <?php if ($feedStatus === 'pending_verification' && !$isVerified): ?>
                                            <form method="post" action="/imoveis/<?= e(property_ref($property)) ?>/ical/<?= $feedId ?>/verificar">
                                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                                <button class="primary btn-verify" type="submit"><?= e(t('cal.verify')) ?></button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post" action="/imoveis/<?= e(property_ref($property)) ?>/ical/<?= $feedId ?>/sincronizar">
                                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                                <button class="primary" type="submit"><?= e($feedStatus === 'error' ? t('cal.try_again') : t('cal.sync_now')) ?></button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post" action="/imoveis/<?= e(property_ref($property)) ?>/ical/<?= $feedId ?>/excluir" data-confirm-remove>
                                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                            <button class="danger btn-remove-outline" type="submit"><?= e(t('cal.remove_feed')) ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php if ($feedStatus === 'pending_verification' && $challengeBr !== '' && !$isVerified): ?>
                                <tr class="sync-challenge-row">
                                    <td colspan="4">
                                        <?= e(t('cal.challenge_before')) ?>
                                        <strong><?= e($challengeBr) ?></strong>
                                        <?= e(t('cal.challenge_between')) ?>
                                        <strong><?= e((string) ($feed['title'] ?? '')) ?></strong>
                                        <?= e(t('cal.challenge_after')) ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php if ($isOta && (($isVerified && !$isPendingReview) || $isRejected)): ?>
                                <tr class="sync-edit-row">
                                    <td colspan="4">
                                        <details class="sync-edit">
                                            <summary><?= e(t('cal.change_links')) ?></summary>
                                            <p class="muted fine"><?= e(t('cal.change_warning')) ?></p>
                                            <form method="post" action="/imoveis/<?= e(property_ref($property)) ?>/ical/<?= $feedId ?>/editar" class="sync-edit-form">
                                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                                <input type="hidden" name="mode" value="ota">
                                                <label><?= e(t('cal.platform')) ?>
                                                    <select name="platform" required>
                                                        <?php foreach (OtaPlatformRegistry::all() as $key => $item): ?>
                                                            <option value="<?= e($key) ?>"<?= $key === $platform ? ' selected' : '' ?>><?= e((string) $item['label']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </label>
                                                <label><?= e(t('cal.property_url')) ?>
                                                    <input name="property_url" value="<?= e((string) ($feed['property_url'] ?? '')) ?>" required>
                                                </label>
                                                <label><?= e(t('cal.link')) ?>
                                                    <input name="external_ical_url" placeholder="<?= e(t('cal.ical_new_placeholder')) ?>" required>
                                                </label>
                                                <label><?= e(t('cal.feed_title')) ?>
                                                    <input name="feed_title" value="<?= e((string) ($feed['title'] ?? '')) ?>" maxlength="40" required>
                                                </label>
                                                <label class="prop-checkbox-label">
                                                    <input type="checkbox" name="consent" value="1" required>
                                                    <span><?= e(t('cal.consent')) ?></span>
                                                </label>
                                                <label class="prop-checkbox-label">
                                                    <input type="checkbox" name="confirm" value="1" required>
                                                    <span><?= e(t('cal.change_confirm')) ?></span>
                                                </label>
                                                <button class="primary" type="submit"><?= e(t('cal.save_changes')) ?></button>
                                            </form>
                                        </details>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <h3 class="sync-group-title"><?= e(t('cal.exported_calendars')) ?></h3>
            <div class="sync-scroll">
                <table class="sync-table ical-export-table">
                    <thead>
                        <tr>
                            <th><?= e(t('ical.col_platform')) ?></th>
                            <th><?= e(t('ical.col_link')) ?></th>
                            <th><?= e(t('ical.col_status')) ?></th>
                            <th><?= e(t('ical.col_actions')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($icalExportData['exports'] === []): ?>
                            <tr><td colspan="4" class="muted"><?= e(t('ical.empty')) ?></td></tr>
                        <?php endif; ?>
                        <?php foreach ($icalExportData['exports'] as $icalExport): ?>
                            <?php
                            $icalPlatform = (string) $icalExport['target_platform'];
                            $icalLink = $icalExportBase . $icalExport['token'] . '.ics';
                            $icalStale = (bool) ($icalExport['stale'] ?? false);
                            ?>
                            <tr>
                                <td>
                                    <strong><?= e(t('ical.platform_' . $icalPlatform)) ?></strong>
                                    <?php if ($icalExport['label'] !== null && $icalExport['label'] !== ''): ?>
                                        <br><span class="muted fine"><?= e((string) $icalExport['label']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="ical-export-link">
                                        <input class="ical-export-input" readonly value="<?= e($icalLink) ?>" data-ical-export-link data-select-on-click>
                                    </div>
                                </td>
                                <td>
                                    <span class="sync-badge <?= $icalStale ? 'is-stale' : 'is-active' ?>"><?= e($icalStale ? t('ical.stale') : t('ical.status_active')) ?></span>
                                    <br><span class="muted fine"><?= e(t('ical.col_last')) ?>: <?= e((string) $icalExport['last_fetched_label']) ?></span>
                                </td>
                                <td>
                                    <div class="sync-actions">
                                        <button class="primary" type="button" data-ical-export-copy><?= e(t('ical.copy')) ?></button>
                                        <button class="danger" type="button" data-ical-export-revoke data-export-id="<?= (int) $icalExport['id'] ?>"><?= e(t('ical.revoke')) ?></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
        $platformMeta = json_encode(OtaPlatformRegistry::uiMeta(), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
        ?>
        <div class="panel sync-add-group" data-sync-tabs data-sync-active="import">
            <div class="sync-add-tabs" role="tablist" aria-label="<?= e(t('cal.sync')) ?>">
                <button type="button" id="sync-tab-import" role="tab" aria-controls="sync-panel-import" aria-selected="true" class="is-on" data-sync-tab="import"><?= e(t('cal.sync_import_tab')) ?></button>
                <button type="button" id="sync-tab-export" role="tab" aria-controls="sync-panel-export" aria-selected="false" data-sync-tab="export"><?= e(t('cal.sync_export_tab')) ?></button>
            </div>
            <div class="sync-add-viewport">
                <div class="sync-add-track">
                    <section class="sync-add-section" id="sync-panel-import" role="tabpanel" aria-labelledby="sync-tab-import" data-sync-panel="import">
                        <form class="sync-add-form" method="post" action="/imoveis/<?= e(property_ref($property)) ?>/ical/importar" data-feed-form data-full="<?= count($feeds) >= 5 ? '1' : '0' ?>" data-platform-meta="<?= e((string) $platformMeta) ?>">
                            <h2><?= e(t('cal.add_feed')) ?></h2>
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <div class="feed-mode">
                                <label class="prop-checkbox-label">
                                    <input type="radio" name="mode" value="ota" checked data-feed-mode>
                                    <span><?= e(t('cal.mode_ota')) ?></span>
                                </label>
                                <label class="prop-checkbox-label">
                                    <input type="radio" name="mode" value="sync_only" data-feed-mode>
                                    <span><?= e(t('cal.mode_sync')) ?></span>
                                </label>
                            </div>
                            <div data-ota-fields>
                                <label><?= e(t('cal.platform')) ?>
                                    <select name="platform" data-feed-platform <?= count($feeds) >= 5 ? 'disabled' : '' ?>>
                                        <option value=""><?= e(t('cal.platform_select')) ?></option>
                                        <?php foreach (OtaPlatformRegistry::all() as $key => $item): ?>
                                            <option value="<?= e($key) ?>"><?= e((string) $item['label']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label><?= e(t('cal.property_url')) ?>
                                    <input name="property_url" data-feed-property-url maxlength="255" placeholder="<?= e(t('cal.property_url_placeholder')) ?>" <?= count($feeds) >= 5 ? 'disabled' : '' ?>>
                                </label>
                                <p class="muted fine" data-feed-helper><?= e(t('cal.ota_hint')) ?></p>
                                <label class="prop-checkbox-label">
                                    <input type="checkbox" name="consent" value="1" data-feed-consent <?= count($feeds) >= 5 ? 'disabled' : '' ?>>
                                    <span><?= e(t('cal.consent')) ?></span>
                                </label>
                            </div>
                            <label><?= e(t('cal.feed_title')) ?>
                                <input name="feed_title" maxlength="40" placeholder="Booking" <?= count($feeds) >= 5 ? 'disabled' : '' ?>>
                            </label>
                            <label><?= e(t('cal.link')) ?>
                                <input name="external_ical_url" data-feed-ical placeholder="https://ical.booking.com/v1/export?t=..." <?= count($feeds) >= 5 ? 'disabled' : '' ?>>
                            </label>
                            <p class="muted fine" data-sync-hint hidden><?= e(t('cal.sync_only_hint')) ?></p>
                            <button class="primary is-off" type="submit" data-feed-add disabled><?= e(t('cal.add_feed')) ?></button>
                            <p class="muted fine"><?= e(t('cal.sync_hint')) ?></p>
                        </form>
                    </section>
                    <section class="sync-add-section" id="sync-panel-export" role="tabpanel" aria-labelledby="sync-tab-export" data-sync-panel="export" aria-hidden="true" inert>
                        <div class="ical-export-panel"
                            data-ical-export
                            data-saved="<?= e(t('ical.settings_saved')) ?>"
                            data-copied="<?= e(t('ical.copied')) ?>"
                            data-copy-error="<?= e(t('ical.copy_error')) ?>"
                            data-revoke-confirm="<?= e(t('ical.revoke_confirm')) ?>"
                            data-error-settings="<?= e(t('ical.error_settings')) ?>"
                            data-error-create="<?= e(t('ical.error_create')) ?>"
                            data-error-revoke="<?= e(t('ical.error_revoke')) ?>"
                        >
                            <h2><?= e(t('ical.export_title')) ?></h2>
                            <p class="muted fine"><?= e(t('ical.export_subtitle')) ?></p>

                            <div class="ical-export-settings">
                                <label class="prop-checkbox-label">
                                    <input type="checkbox" value="1" data-ical-export-setting="manual"<?= $icalExportData['settings']['ical_export_manual_blocks'] ? ' checked' : '' ?>>
                                    <span><?= e(t('ical.manual_blocks')) ?></span>
                                </label>
                                <label class="prop-checkbox-label">
                                    <input type="checkbox" value="1" data-ical-export-setting="imported"<?= $icalExportData['settings']['ical_export_imported_events'] ? ' checked' : '' ?>>
                                    <span><?= e(t('ical.imported_events')) ?></span>
                                </label>
                                <p class="muted fine"><?= e(t('ical.imported_hint')) ?></p>
                            </div>

                            <form class="ical-export-form" data-ical-export-form>
                                <label><?= e(t('ical.platform')) ?>
                                    <select name="target_platform">
                                        <?php foreach (IcalExportService::PLATFORMS as $icalPlatform): ?>
                                            <option value="<?= e($icalPlatform) ?>"><?= e(t('ical.platform_' . $icalPlatform)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label><?= e(t('ical.label')) ?>
                                    <input name="label" maxlength="60" placeholder="<?= e(t('ical.label')) ?>">
                                </label>
                                <button class="primary" type="submit"><?= e(t('ical.generate')) ?></button>
                            </form>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>
    <?php if (is_array($restore ?? null)): ?>
        <div hidden data-cal-restore data-start="<?= e((string) ($restore['inicio'] ?? '')) ?>" data-end="<?= e((string) ($restore['fim'] ?? '')) ?>" data-view="<?= e((string) ($restore['view'] ?? 'day')) ?>"></div>
    <?php endif; ?>
</section>
<?php page_data(['hash' => property_ref($property)]); ?>
<script src="<?= e(asset_url('/assets/pages/calendar.js')) ?>"></script>
<script src="<?= e(asset_url('/assets/pages/calendar-2.js')) ?>"></script>
<script src="<?= e(asset_url('/assets/pages/calendar-3.js')) ?>"></script>
