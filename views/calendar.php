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
?>
<section class="wrap">
    <h1><?= e(t('cal.title')) ?> · <?= e($property['title']) ?></h1>
    <?php if ($message): ?><p class="alert <?= ($message_tone ?? 'ok') === 'error' ? 'error' : 'ok' ?>"><?= e($message) ?></p><?php endif; ?>
    <div class="cal-board">
        <div class="cal-months">
            <ul class="cal-legend">
                <li><span class="swatch open"></span> <?= e(t('cal.legend_open')) ?></li>
                <li><span class="swatch sistema"></span> <?= e(t('cal.legend_system')) ?></li>
                <li><span class="swatch sync"></span> <?= e(t('cal.legend_sync')) ?></li>
                <li><span class="swatch reserva"></span> <?= e(t('cal.legend_booking')) ?></li>
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
                            <button type="button" class="cal-day<?= $origin !== '' ? ' ' . e($origin) : '' ?>" data-cal-day="<?= e($iso) ?>" data-day-num="<?= $d ?>" data-origin="<?= e($origin) ?>" data-price="<?= e(money_input($nightPrice)) ?>" data-min="<?= $nightMin ?>" <?= $disabled ? 'disabled' : '' ?>><span class="cal-corner" data-cal-corner><?= $d ?></span><span class="cal-main" data-cal-main><?= $d ?></span></button>
                        <?php endfor; ?>
                    </div>
                </section>
            <?php endfor; ?>
        </div>
        <form class="cal-controls" method="post" action="/imoveis/<?= e(property_ref($property)) ?>/calendario" data-cal-form data-months="<?= e(implode(',', current_locale() === 'en' ? ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'] : (current_locale() === 'es' ? ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'] : ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez']))) ?>" data-min-default="<?= e(t('form.min_nights')) ?>" data-min-from="<?= e(t('cal.min_from')) ?>" data-min-range="<?= e(t('cal.min_range')) ?>">
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
            <label><span data-min-label><?= e(t('form.min_nights')) ?></span> <input type="number" name="min_nights" min="1" data-cal-min value="<?= (int) $property['min_nights'] ?>"></label>
            <label><?= e(t('cal.price')) ?> <input name="price" data-cal-price placeholder="<?= e(t('cal.price_hint')) ?>"></label>
            <button class="danger is-off" type="submit" name="acao" value="bloquear" data-cal-block disabled><?= e(t('cal.block')) ?></button>
            <button class="primary is-off" type="submit" name="acao" value="abrir" data-cal-open disabled><?= e(t('cal.open_action')) ?></button>
            <button class="primary is-off" type="submit" name="acao" value="salvar" data-cal-save disabled><?= e(t('cal.save_changes')) ?></button>
            <p class="muted"><?= e(t('cal.sync_locked')) ?></p>
        </form>
    </div>
    <div class="sync-board">
        <div class="panel">
            <h2><?= e(t('cal.sync')) ?></h2>
            <div class="sync-scroll">
                <table class="sync-table">
                    <thead>
                        <tr>
                            <th><?= e(t('cal.col_title')) ?></th>
                            <th><?= e(t('cal.col_status')) ?></th>
                            <th><?= e(t('cal.col_synced')) ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$feeds): ?>
                            <tr><td colspan="4" class="muted"><?= e(t('cal.feed_empty')) ?></td></tr>
                        <?php endif; ?>
                        <?php foreach ($feeds as $feed): ?>
                            <?php
                            $status = (string) ($feed['last_status'] ?? '');
                            $statusLabel = $status === 'OK' ? t('cal.status_ok') : ($status === 'NOK' ? t('cal.status_nok') : t('cal.status_pending'));
                            ?>
                            <tr>
                                <td><strong><?= e((string) ($feed['title'] ?? '')) ?></strong></td>
                                <td><?= e($statusLabel) ?></td>
                                <td><?php
                                    $syncedAt = (string) ($feed['last_synced_at'] ?? '');
                                    $synced = $syncedAt !== '' ? strtotime($syncedAt) : false;
                                    echo $synced === false ? '—' : max(0, (int) floor((time() - $synced) / 60)) . ' ' . e(t('cal.minutes_ago'));
                                ?></td>
                                <td>
                                    <form method="post" action="/imoveis/<?= e(property_ref($property)) ?>/ical/<?= (int) $feed['id'] ?>/excluir">
                                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                        <button class="danger" type="submit"><?= e(t('cal.remove_feed')) ?></button>
                                    </form>
                                </td>
                            </tr>
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
    <?php $exportUrl = request_base() . '/ical/' . HouseRepository::ensureIcalHash($property); ?>
    <div class="panel mt-16">
        <label><?= e(t('cal.export_link')) ?>
            <input readonly value="<?= e($exportUrl) ?>" data-select-on-click>
        </label>
    </div>
    <?php if (is_array($restore ?? null)): ?>
        <div hidden data-cal-restore data-start="<?= e((string) ($restore['inicio'] ?? '')) ?>" data-end="<?= e((string) ($restore['fim'] ?? '')) ?>" data-view="<?= e((string) ($restore['view'] ?? 'day')) ?>"></div>
    <?php endif; ?>
</section>
<?php page_data(['hash' => property_ref($property), 'csrf' => $csrf]); ?>
<script src="/assets/pages/calendar.js?v=1"></script>
<script src="/assets/pages/calendar-2.js?v=1"></script>
<script src="/assets/pages/calendar-3.js?v=1"></script>
