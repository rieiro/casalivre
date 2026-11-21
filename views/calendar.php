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
$icalExportData = IcalExportService::listForProperty((int) $property['id']);
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
                            $statusLabel = match ($feedStatus) {
                                'pending_verification' => t('cal.status_pending_verification'),
                                'error' => t('cal.status_error'),
                                default => t('cal.status_active'),
                            };
                            $statusClass = match ($feedStatus) {
                                'pending_verification' => 'is-pending',
                                'error' => 'is-error',
                                default => 'is-active',
                            };
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
                            if ($feedStatus === 'pending_verification' && !empty($feed['challenge_date'])) {
                                $challengeDt = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $feed['challenge_date'], new DateTimeZone('UTC'));
                                $challengeBr = $challengeDt === false ? '' : $challengeDt->format('d/m/Y');
                            }
                            $feedId = (int) $feed['id'];
                            ?>
                            <tr>
                                <td><strong><?= e((string) ($feed['title'] ?? '')) ?></strong></td>
                                <td>
                                    <span class="sync-badge <?= e($statusClass) ?>"><?= e($statusLabel) ?></span>
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
                                        <?php if ($feedStatus === 'pending_verification'): ?>
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
                                        <form method="post" action="/imoveis/<?= e(property_ref($property)) ?>/ical/<?= $feedId ?>/excluir">
                                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                            <button class="danger btn-remove-outline" type="submit"><?= e(t('cal.remove_feed')) ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php if ($feedStatus === 'pending_verification' && $challengeBr !== ''): ?>
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
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <form class="panel" method="post" action="/imoveis/<?= e(property_ref($property)) ?>/ical/importar" data-feed-form data-full="<?= count($feeds) >= 5 ? '1' : '0' ?>">
            <h2><?= e(t('cal.add_feed')) ?></h2>
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <label><?= e(t('cal.feed_title')) ?>
                <input name="feed_title" maxlength="40" placeholder="Booking" <?= count($feeds) >= 5 ? 'disabled' : '' ?>>
            </label>
            <label><?= e(t('cal.link')) ?>
                <input name="external_ical_url" placeholder="https://ical.booking.com/v1/export?t=..." <?= count($feeds) >= 5 ? 'disabled' : '' ?>>
            </label>
            <button class="primary is-off" type="submit" data-feed-add disabled><?= e(t('cal.add_feed')) ?></button>
            <p class="muted fine"><?= e(t('cal.sync_hint')) ?></p>
        </form>
    </div>
    <div
        class="panel mt-16 ical-export-panel"
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

        <div class="sync-scroll">
            <table class="sync-table ical-export-table">
                <thead>
                    <tr>
                        <th><?= e(t('ical.col_platform')) ?></th>
                        <th><?= e(t('ical.col_link')) ?></th>
                        <th><?= e(t('ical.col_last')) ?></th>
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
                                    <input readonly value="<?= e($icalLink) ?>" data-ical-export-link data-select-on-click>
                                    <button class="primary" type="button" data-ical-export-copy><?= e(t('ical.copy')) ?></button>
                                </div>
                            </td>
                            <td class="<?= $icalExport['stale'] ? 'ical-export-stale' : '' ?>"><?= e((string) $icalExport['last_fetched_label']) ?></td>
                            <td>
                                <button class="danger" type="button" data-ical-export-revoke data-export-id="<?= (int) $icalExport['id'] ?>"><?= e(t('ical.revoke')) ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
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
