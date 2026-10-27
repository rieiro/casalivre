<?php
/** @var string $resolution @var list<array<string, mixed>> $options @var string $base @var array<string, mixed> $filters @var string $query */
$typed = (string) ($filters['localidade'] ?? '');
$hasPoi = false;
foreach ($options as $option) {
    if (($option['type'] ?? '') === 'poi') {
        $hasPoi = true;
        break;
    }
}
$keep = $filters;
unset($keep['localidade']);
?>
<section class="place-resolution">
<?php if ($resolution === 'not_found'): ?>
    <?php $parts = explode('%s', e(t('place.not_found')), 2); ?>
    <h1 id="place-resolution-title"><?= $parts[0] . e($typed) . ($parts[1] ?? '') ?></h1>
    <p><a href="<?= e($base) ?>/busca/mapa?<?= e($query) ?>"><?= e(t('place.search_map')) ?></a></p>
<?php else: ?>
    <h1 id="place-resolution-title" tabindex="-1" data-focus="1"><?= e(t('place.did_you_mean')) ?></h1>
    <ul>
        <?php foreach ($options as $option): ?>
            <li><a href="<?= e($base . '/busca?' . search_query(['local_id' => (int) $option['id']] + $keep)) ?>"><?= e(place_option_text($option)) ?></a></li>
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
