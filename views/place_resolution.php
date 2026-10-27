<?php
/** @var string $resolution @var list<array<string, mixed>> $options @var string $base @var array<string, mixed> $filters */
$typed = (string) ($filters['localidade'] ?? '');
$shown = array_slice($options, 0, 8);
$hasPoi = false;
foreach ($shown as $option) {
    if (($option['type'] ?? '') === 'poi') {
        $hasPoi = true;
        break;
    }
}
$mapFilters = $filters;
unset($mapFilters['localidade'], $mapFilters['local_id']);
$mapFilters = array_filter($mapFilters, static fn (mixed $value): bool => $value !== '' && $value !== 0 && $value !== 0.0 && $value !== []);
$mapQuery = search_query($mapFilters);
$keep = $filters;
unset($keep['localidade']);
?>
<section class="place-resolution">
<?php if ($resolution === 'not_found'): ?>
    <?php $parts = explode('%s', e(t('place.not_found')), 2); ?>
    <h1 id="place-resolution-title"><?= $parts[0] . e($typed) . ($parts[1] ?? '') ?></h1>
    <p><a class="place-resolution-map" href="<?= e($base . '/busca/mapa' . ($mapQuery !== '' ? '?' . $mapQuery : '')) ?>"><?= e(t('place.search_map')) ?></a></p>
<?php else: ?>
    <h1 id="place-resolution-title" tabindex="-1" data-focus="1"><?= e(t('place.did_you_mean')) ?></h1>
    <ul>
        <?php foreach ($shown as $option): ?>
            <?php
            $linkFilters = $keep;
            unset($linkFilters['local_id']);
            $linkFilters = ['local_id' => (int) $option['id']] + $linkFilters;
            ?>
            <li><a href="<?= e($base . '/busca?' . search_query($linkFilters)) ?>"><?= e(place_option_text($option)) ?></a></li>
        <?php endforeach; ?>
        <?php if ($resolution === 'ambiguous'): ?>
            <li class="place-resolution-hint"><?= e(t('place.keep_typing')) ?></li>
        <?php endif; ?>
    </ul>
    <?php if ($resolution === 'ambiguous'): ?>
        <p id="place-resolution-live" class="muted" role="status" aria-live="polite" data-announce="<?= e(t('place.many')) ?>"></p>
    <?php endif; ?>
<?php endif; ?>
<?php if ($hasPoi) {
    osm_attribution();
} ?>
</section>
