<?php
// Mapa da busca: MapLibre GL + PMTiles (basemap Protomaps light, arquivos locais; CSP só 'self').
// Os pins vêm de /api/busca (coordenada pública do #36) em GeoJSON com clusters; a página não roda a
// busca no servidor (a API registra a busca uma vez). Sem JS/WebGL/arquivo de tiles: aviso + lista.
// Centro inicial = centro da cidade com 2 casas (~1 km): só enquadra o mapa, não é coordenada de imóvel.
$query = search_query($filters);
$place = $filters['localidade'] !== '' ? CityRepository::match($filters['localidade']) : null;
$center = isset($place['lat'], $place['lng']) ? round((float) $place['lat'], 2) . ',' . round((float) $place['lng'], 2) : '';
?>
<link rel="stylesheet" href="/assets/vendor/maplibre-gl/maplibre-gl.css?v=5.24.0">
<section class="wrap map-page">
    <p><a href="/busca?<?= e($query) ?>"><?= e(t('home.back_results')) ?></a></p>
    <h1><?= e(t('home.map_title')) ?><?= $filters['localidade'] !== '' ? ' · ' . e($filters['localidade']) : '' ?></h1>
    <p class="muted" id="map-status" role="status" aria-live="polite" data-map-count><?= $searched ? 'Carregando mapa…' : e(t('home.empty')) ?></p>
    <p class="map-notice" id="map-notice" role="alert" hidden></p>
    <div id="result-map" class="result-map"
         data-api="<?= $searched ? e('/api/busca?' . $query) : '' ?>"
         data-query="<?= e($query) ?>"
         data-center="<?= e($center) ?>"
         data-tiles="/tiles/brasil.pmtiles"
         data-style="/assets/map/style-light.json?v=1"
         data-worker="/assets/vendor/maplibre-gl/maplibre-gl-csp-worker.js?v=5.24.0"
         aria-label="Mapa dos imóveis encontrados. A lista de resultados é a alternativa pelo teclado."></div>
    <noscript><p class="muted">O mapa precisa de JavaScript. <a href="/busca?<?= e($query) ?>">Ver a lista de resultados</a>.</p></noscript>
</section>
<script src="/assets/vendor/maplibre-gl/maplibre-gl-csp.js?v=5.24.0"></script>
<script src="/assets/vendor/pmtiles/pmtiles.js?v=4.5.0"></script>
<script src="/assets/pages/map-data.js?v=1"></script>
<script src="/assets/pages/map.js?v=4"></script>
