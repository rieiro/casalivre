<?php
$base = (string) ($vitrine_base ?? ''); // prefixo da vitrine (/g/<slug>) ou '' na plataforma
// Mapa da busca: MapLibre GL + PMTiles (basemap Protomaps light, arquivos locais; CSP só 'self').
// Os pins vêm de /api/busca (coordenada pública do #36) em GeoJSON com clusters; a página não roda a
// busca no servidor (a API registra a busca uma vez). Sem JS/WebGL/arquivo de tiles: aviso + lista.
// Centro inicial = centro da cidade com 2 casas (~1 km): só enquadra o mapa, não é coordenada de imóvel.
$query = search_query($filters);
$place = !empty($filters['local_id']) ? CityRepository::byId((int) $filters['local_id']) : null;
$placeLabel = place_label($place, (string) ($filters['localidade'] ?? ''));
$center = isset($place['lat'], $place['lng']) ? round((float) $place['lat'], 2) . ',' . round((float) $place['lng'], 2) : '';
?>
<link rel="stylesheet" href="<?= e(asset_url('/assets/vendor/maplibre-gl/maplibre-gl.css')) ?>">
<section class="wrap map-page">
    <p><a href="<?= e($base) ?>/busca?<?= e($query) ?>"><?= e(t('home.back_results')) ?></a></p>
    <h1><?= e(t('home.map_title')) ?><?= $placeLabel !== '' ? ' · ' . e($placeLabel) : '' ?></h1>
    <p class="muted" id="map-status" role="status" aria-live="polite" data-map-count><?= $searched ? 'Carregando mapa…' : e(t('home.empty')) ?></p>
    <p class="map-notice" id="map-notice" role="alert" hidden></p>
    <div id="result-map" class="result-map"
         data-api="<?= $searched ? e($vitrine_api ?? '/api/busca?' . $query) : '' ?>"
         data-query="<?= e($query) ?>"
<?php if ($base !== ''): ?>
         data-base="<?= e($base) ?>"
<?php endif; ?>
         data-center="<?= e($center) ?>"
         data-tiles="/tiles/brasil.pmtiles"
         data-style="<?= e(asset_url(Theme::mapStylePath())) ?>"
         data-worker="<?= e(asset_url('/assets/vendor/maplibre-gl/maplibre-gl-csp-worker.js')) ?>"
         aria-label="Mapa dos imóveis encontrados. A lista de resultados é a alternativa pelo teclado."></div>
    <noscript><p class="muted">O mapa precisa de JavaScript. <a href="<?= e($base) ?>/busca?<?= e($query) ?>">Ver a lista de resultados</a>.</p></noscript>
</section>
<script src="<?= e(asset_url('/assets/vendor/maplibre-gl/maplibre-gl-csp.js')) ?>"></script>
<script src="<?= e(asset_url('/assets/vendor/pmtiles/pmtiles.js')) ?>"></script>
<script src="<?= e(asset_url('/assets/pages/map-data.js')) ?>"></script>
<script src="<?= e(asset_url('/assets/pages/map.js')) ?>"></script>
