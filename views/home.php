<section class="hero">
    <div class="wrap" style="padding-top:0;padding-bottom:0">
        <h1><?= e(t('home.title')) ?></h1>
        <p><?= e(t('home.lead')) ?></p>
        <form class="search" method="get" action="/busca">
            <label class="field"><?= e(t('home.place')) ?>
                <input name="localidade" data-city-hints value="<?= e($filters['localidade']) ?>" required autocomplete="off">
            </label>
            <label class="field"><?= e(t('home.checkin')) ?>
                <input name="checkin" data-stay-date="checkin" data-date-order="<?= current_locale() === 'en' ? 'mdy' : 'dmy' ?>" value="<?= e($filters['checkin'] !== '' ? (format_date(parse_date($filters['checkin']) ?? '') ?: $filters['checkin']) : '') ?>" autocomplete="off">
            </label>
            <label class="field"><?= e(t('home.checkout')) ?>
                <input name="checkout" data-stay-date="checkout" data-date-order="<?= current_locale() === 'en' ? 'mdy' : 'dmy' ?>" value="<?= e($filters['checkout'] !== '' ? (format_date(parse_date($filters['checkout']) ?? '') ?: $filters['checkout']) : '') ?>" autocomplete="off">
            </label>
            <label class="field"><?= e(t('home.guests')) ?>
                <input type="number" min="1" name="hospedes" value="<?= $filters['hospedes'] ?: '' ?>">
            </label>
            <button type="submit"><?= e(t('home.search')) ?></button>
        </form>
    </div>
</section>
