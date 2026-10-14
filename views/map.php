<?php
$query = search_query($filters);
$points = [];
foreach ($results as $item) {
    $pc = public_coord((int) $item['id'], (float) $item['lat'], (float) $item['lng']);
    $points[] = [
        'title' => (string) $item['title'],
        'lat' => $pc['lat'],
        'lng' => $pc['lng'],
        'href' => '/imoveis/' . property_ref($item) . '?' . http_build_query([
            'checkin' => $filters['checkin'] ?? '',
            'checkout' => $filters['checkout'] ?? '',
            'volta' => '/busca?' . $query,
        ]),
    ];
}
$payload = json_encode($points, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<section class="wrap map-page">
    <p><a href="/busca?<?= e($query) ?>"><?= e(t('home.back_results')) ?></a></p>
    <h1><?= e(t('home.map_title')) ?><?= $filters['localidade'] !== '' ? ' · ' . e($filters['localidade']) : '' ?></h1>
    <?php if ($searched && $results): ?><p class="muted" data-map-count><?= count($results) ?> <?= e(t('home.found')) ?></p><?php endif; ?>
    <div id="result-map" data-points="<?= e((string) $payload) ?>"></div>
    <?php if (!$results): ?><p class="muted"><?= e(t('home.empty')) ?></p><?php endif; ?>
</section>
<script src="/assets/pages/map.js?v=3"></script>
