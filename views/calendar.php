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
    <div class="panel" style="margin-top:16px">
        <label><?= e(t('cal.export_link')) ?>
            <input readonly value="<?= e($exportUrl) ?>" onclick="this.select()">
        </label>
    </div>
    <?php if (is_array($restore ?? null)): ?>
        <div hidden data-cal-restore data-start="<?= e((string) ($restore['inicio'] ?? '')) ?>" data-end="<?= e((string) ($restore['fim'] ?? '')) ?>" data-view="<?= e((string) ($restore['view'] ?? 'day')) ?>"></div>
    <?php endif; ?>
</section>
<script>
(function () {
    const form = document.querySelector('[data-feed-form]');
    if (!form) {
        return;
    }
    const title = form.querySelector('[name="feed_title"]');
    const url = form.querySelector('[name="external_ical_url"]');
    const button = form.querySelector('[data-feed-add]');
    const full = form.getAttribute('data-full') === '1';
    function ready() {
        const ok = !full && title.value.trim() !== '' && url.value.trim() !== '';
        button.disabled = !ok;
        button.classList.toggle('is-off', !ok);
    }
    title.addEventListener('input', ready);
    url.addEventListener('input', ready);
    ready();
})();
</script>
<script>
(function () {
    const buttons = Array.from(document.querySelectorAll('[data-cal-day]'));
    const start = document.querySelector('[data-cal-start]');
    const end = document.querySelector('[data-cal-end]');
    const summary = document.querySelector('[data-cal-summary]');
    const price = document.querySelector('[data-cal-price]');
    const minInput = document.querySelector('[data-cal-min]');
    const minLabel = document.querySelector('[data-min-label]');
    const form = document.querySelector('[data-cal-form]');
    const months = (form && form.dataset.months ? form.dataset.months : '').split(',');
    function stamp(iso) {
        const parts = iso.split('-');
        return parts[2] + '-' + (months[Number(parts[1]) - 1] || parts[1]);
    }
    function fill(template, values) {
        let index = 0;
        return String(template || '').replace(/%s/g, () => values[index++] || '');
    }
    let anchor = null;
    let complete = false;
    const saveButton = document.querySelector('[data-cal-save]');
    function resetEdited() {
        if (!saveButton) {
            return;
        }
        saveButton.disabled = true;
        saveButton.classList.add('is-off');
    }
    function markEdited() {
        if (!saveButton) {
            return;
        }
        saveButton.disabled = false;
        saveButton.classList.remove('is-off');
    }
    if (price) {
        price.addEventListener('input', markEdited);
    }
    if (minInput) {
        minInput.addEventListener('input', markEdited);
    }
    function paint(from, to) {
        const first = from < to ? from : to;
        const last = from < to ? to : from;
        buttons.forEach((button) => {
            const day = button.getAttribute('data-cal-day');
            button.classList.toggle('picked', !button.disabled && day >= first && day <= last);
        });
        start.value = first;
        end.value = last;
        summary.textContent = first === last ? stamp(first) : stamp(first) + ' — ' + stamp(last);
        summary.classList.remove('is-hint');
        let openCount = 0;
        let systemCount = 0;
        let importedCount = 0;
        buttons.forEach((button) => {
            const day = button.getAttribute('data-cal-day');
            if (button.disabled || day < first || day > last) {
                return;
            }
            const origin = button.getAttribute('data-origin') || '';
            if (origin === 'sync' || origin === 'reserva') {
                importedCount++;
            } else if (origin === 'sistema') {
                systemCount++;
            } else {
                openCount++;
            }
        });
        const blockButton = document.querySelector('[data-cal-block]');
        const openButton = document.querySelector('[data-cal-open]');
        const locked = importedCount > 0;
        if (blockButton) {
            blockButton.disabled = locked || openCount === 0;
            blockButton.classList.toggle('is-off', locked || openCount === 0);
        }
        if (openButton) {
            openButton.disabled = locked || systemCount === 0;
            openButton.classList.toggle('is-off', locked || systemCount === 0);
        }
        if (price) {
            const chosenDays = buttons.filter((button) => {
                const day = button.getAttribute('data-cal-day');
                return !button.disabled && day >= first && day <= last;
            });
            const prices = chosenDays.map((button) => button.getAttribute('data-price') || '');
            const same = prices.length > 0 && prices.every((value) => value === prices[0]);
            price.value = same ? prices[0] : '';
        }
        const chosenDays = buttons.filter((button) => {
            const day = button.getAttribute('data-cal-day');
            return !button.disabled && day >= first && day <= last;
        });
        if (minInput) {
            const mins = chosenDays.map((button) => button.getAttribute('data-min') || '');
            const sameMin = mins.length > 0 && mins.every((value) => value === mins[0]);
            minInput.value = sameMin ? mins[0] : '';
        }
        if (minLabel && form) {
            minLabel.textContent = first === last
                ? fill(form.dataset.minFrom, [stamp(first)])
                : fill(form.dataset.minRange, [stamp(first), stamp(last)]);
        }
        resetEdited();
    }
    buttons.forEach((button) => {
        button.addEventListener('click', () => {
            if (button.disabled) {
                return;
            }
            const day = button.getAttribute('data-cal-day');
            if (!anchor || complete) {
                anchor = day;
                complete = false;
                paint(day, day);
                return;
            }
            paint(anchor, day);
            complete = true;
        });
    });
    const board = document.querySelector('.cal-board');
    const viewButtons = Array.from(document.querySelectorAll('[data-cal-view]'));
    const desktop = window.matchMedia('(min-width: 801px)');
    let viewMode = 'day';
    function applyView() {
        const mode = desktop.matches ? viewMode : 'day';
        if (board) {
            board.setAttribute('data-cal-mode', mode);
        }
        const viewInput = document.querySelector('[data-cal-view-input]');
        if (viewInput) {
            viewInput.value = viewMode;
        }
        buttons.forEach((button) => {
            const main = button.querySelector('[data-cal-main]');
            const corner = button.querySelector('[data-cal-corner]');
            const dayNum = button.getAttribute('data-day-num') || '';
            if (corner) {
                corner.textContent = dayNum;
            }
            if (!main) {
                return;
            }
            if (mode === 'price') {
                main.textContent = button.getAttribute('data-price') || '';
            } else if (mode === 'min') {
                main.textContent = button.getAttribute('data-min') || '';
            } else {
                main.textContent = dayNum;
            }
        });
        viewButtons.forEach((item) => {
            const on = item.getAttribute('data-cal-view') === viewMode;
            item.classList.toggle('is-on', on);
            item.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }
    viewButtons.forEach((item) => {
        item.addEventListener('click', () => {
            viewMode = item.getAttribute('data-cal-view') || 'day';
            applyView();
        });
    });
    desktop.addEventListener('change', applyView);
    const monthSections = Array.from(document.querySelectorAll('.cal-month'));
    let monthIndex = 0;
    function showMonth(index) {
        if (!monthSections.length) {
            return;
        }
        monthIndex = Math.max(0, Math.min(monthSections.length - 1, index));
        monthSections.forEach((section, i) => section.classList.toggle('is-shown', i === monthIndex));
        const atStart = monthIndex === 0;
        const atEnd = monthIndex >= monthSections.length - 1;
        document.querySelectorAll('[data-cal-prev]').forEach((button) => {
            button.disabled = atStart;
            button.classList.toggle('is-off', atStart);
        });
        document.querySelectorAll('[data-cal-next]').forEach((button) => {
            button.disabled = atEnd;
            button.classList.toggle('is-off', atEnd);
        });
    }
    showMonth(0);
    document.querySelectorAll('[data-cal-prev]').forEach((button) => {
        button.addEventListener('click', () => showMonth(monthIndex - 1));
    });
    document.querySelectorAll('[data-cal-next]').forEach((button) => {
        button.addEventListener('click', () => showMonth(monthIndex + 1));
    });
    const restore = document.querySelector('[data-cal-restore]');
    if (restore) {
        const savedView = restore.getAttribute('data-view') || 'day';
        if (savedView === 'price' || savedView === 'min' || savedView === 'day') {
            viewMode = savedView;
        }
        applyView();
        const savedStart = restore.getAttribute('data-start') || '';
        const savedEnd = restore.getAttribute('data-end') || '';
        if (savedStart && savedEnd) {
            anchor = savedStart;
            complete = savedStart !== savedEnd;
            paint(savedStart, savedEnd);
            const savedButton = buttons.find((button) => button.getAttribute('data-cal-day') === savedStart);
            const savedMonth = savedButton ? savedButton.closest('.cal-month') : null;
            if (savedMonth) {
                showMonth(monthSections.indexOf(savedMonth));
            }
        }
    }
})();
</script>
<script>
// Migração para API: intercepta envios do calendário e feeds
(function () {
    const hash = <?= json_encode(property_ref($property)) ?>;
    const csrf = <?= json_encode($csrf) ?>;

    // Formulário principal do calendário
    const calForm = document.querySelector('[data-cal-form]');
    if (calForm) {
        calForm.addEventListener('submit', async function (ev) {
            ev.preventDefault();
            const btn = ev.submitter;
            const data = Object.fromEntries(new FormData(calForm).entries());
            if (btn && btn.name === 'acao') {
                data.acao = btn.value;
            }
            try {
                const res = await fetch('/api/imoveis/' + hash + '/calendario', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify(data)
                });
                const json = await res.json().catch(() => ({}));
                if (!res.ok) {
                    alert(json.error || 'Erro ao salvar calendário');
                    return;
                }
                // Recarrega para refletir as mudanças no grid
                const url = new URL(window.location.href);
                url.searchParams.set('msg', json.message || 'Salvo');
                window.location.href = url.toString();
            } catch (e) {
                alert('Falha de conexão');
            }
        });
    }

    // Importar feed
    const feedForm = document.querySelector('[data-feed-form]');
    if (feedForm) {
        feedForm.addEventListener('submit', async function (ev) {
            ev.preventDefault();
            const data = Object.fromEntries(new FormData(feedForm).entries());
            try {
                const res = await fetch('/api/imoveis/' + hash + '/ical/importar', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify({
                        url: data.external_ical_url || '',
                        title: data.feed_title || ''
                    })
                });
                const json = await res.json().catch(() => ({}));
                if (!res.ok) {
                    alert(json.error || 'Erro ao importar feed');
                    return;
                }
                window.location.reload();
            } catch (e) {
                alert('Falha de conexão');
            }
        });
    }

    // Excluir feed (forms dentro da tabela)
    document.querySelectorAll('form[action*="/ical/"][action*="/excluir"]').forEach(form => {
        form.addEventListener('submit', async function (ev) {
            ev.preventDefault();
            const match = form.action.match(/\/ical\/(\d+)\/excluir/);
            if (!match) return;
            if (!confirm('Remover este calendário?')) return;
            try {
                const res = await fetch('/api/imoveis/' + hash + '/ical/' + match[1], {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    headers: { 'X-CSRF-Token': csrf }
                });
                const json = await res.json().catch(() => ({}));
                if (!res.ok) {
                    alert(json.error || 'Erro ao remover');
                    return;
                }
                window.location.reload();
            } catch (e) {
                alert('Falha de conexão');
            }
        });
    });

    // Mostra mensagem vinda da query string após reload
    const params = new URLSearchParams(window.location.search);
    const msg = params.get('msg');
    if (msg) {
        const alert = document.createElement('p');
        alert.className = 'alert ok';
        alert.textContent = msg;
        document.querySelector('.wrap').insertBefore(alert, document.querySelector('.cal-board'));
        params.delete('msg');
        history.replaceState(null, '', window.location.pathname + (params.toString() ? '?' + params : ''));
    }
})();
</script>
