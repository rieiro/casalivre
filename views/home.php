<?php $base = (string) ($vitrine_base ?? ''); // prefixo da vitrine (/g/<slug>) ou '' na plataforma ?>
<section class="hero">
    <div class="wrap wrap-flush">
        <h1><?= e(t('home.title')) ?></h1>
        <p><?= e(t('home.lead')) ?></p>
        <form class="search" method="get" action="<?= e($base) ?>/busca">
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
        <?php osm_attribution_template(); ?>
    </div>
</section>
<?php if (isset($vitrine_grupo)): ?>
<section class="wrap">
    <h2><?= e((string) $vitrine_grupo['name']) ?></h2>
    <?php if (($vitrine_imoveis ?? []) === []): ?><p class="muted"><?= e(t('home.group_empty')) ?></p><?php endif; ?>
    <ul class="group-properties">
        <?php foreach ($vitrine_imoveis ?? [] as $p): ?>
            <li><a href="<?= e($base) ?>/imoveis/<?= e(property_ref($p)) ?>"><?= e((string) $p['title']) ?></a> <span class="muted"><?= e((string) $p['city']) ?></span></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
