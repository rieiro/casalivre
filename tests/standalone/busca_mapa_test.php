<?php
// /busca/mapa (MapLibre + PMTiles): a página é só o esqueleto e os pins vêm de /api/busca no navegador (GeoJSON
// com clusters). A API devolve a coordenada pública (#36) de cada imóvel; a página não roda a busca no
// servidor, então cada visita ao mapa grava exatamente 1 SEARCH (o da API).
require __DIR__ . '/lib.php';
t_temp_db();
$owner = t_seed_owner();
db()->prepare("UPDATE properties SET status = 'active' WHERE id = ?")->execute([$owner['house_id']]);
$base = t_server();
$attr = static function (string $body, string $name): ?string {
    if (!preg_match('/<div id="result-map"[^>]*\s' . preg_quote($name, '/') . '="([^"]*)"/s', $body, $m)) {
        return null;
    }
    return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
};
$loc = urlencode('Florianópolis, SC');

echo "mapa com busca\n";
$r = t_http('GET', $base . '/busca/mapa?localidade=' . $loc . '&checkin=2030-01-10&checkout=2030-01-12', null, [], null, true);
t_eq(200, $r['status'], 'GET /busca/mapa responde 200');
$api = (string) $attr($r['body'], 'data-api');
t_ok(str_starts_with($api, '/api/busca?') && str_contains($api, 'local_id=') && !str_contains($api, 'localidade=') && str_contains($api, 'checkin=2030-01-10'), 'data-api aponta para /api/busca com local_id e as mesmas datas');
t_eq('/tiles/brasil.pmtiles', $attr($r['body'], 'data-tiles'), 'data-tiles = caminho fixo do PMTiles');
t_ok(preg_match('/^-?\d+(\.\d+)?,-?\d+(\.\d+)?$/', (string) $attr($r['body'], 'data-center')) === 1, 'data-center = centro da cidade');
t_ok(!str_contains($r['body'], 'data-points') && !str_contains($r['body'], 'Casa Teste'), 'página não traz os imóveis (vêm da API)');
t_ok(str_contains($r['body'], 'role="status"') && str_contains($r['body'], 'aria-live="polite"'), 'contagem/atualizações em aria-live');
foreach (['/assets/vendor/maplibre-gl/maplibre-gl-csp.js', '/assets/vendor/pmtiles/pmtiles.js', '/assets/pages/map-data.js', '/assets/pages/map.js', '/assets/vendor/maplibre-gl/maplibre-gl.css'] as $asset) {
    t_ok(str_contains($r['body'], $asset), "página carrega $asset (local)");
}

$items = json_decode(t_http('GET', $base . '/api/busca?localidade=' . $loc)['body'], true)['items'] ?? [];
t_eq(1, count($items), 'API encontra o imóvel semeado');
t_eq($owner['hash'], $items[0]['id'] ?? null, 'item com o hash público (link /imoveis/{hash})');
t_ok(is_float($items[0]['lat'] ?? null) && is_float($items[0]['lng'] ?? null), 'item com lat/lng (coordenada pública)');
t_ok(abs($items[0]['lat'] - (-27.59)) < 0.05 && abs($items[0]['lng'] - (-48.54)) < 0.05, 'lat/lng perto do imóvel');
t_ok(($items[0]['lat'] !== -27.59 || $items[0]['lng'] !== -48.54), 'lat/lng diferente da exata');

echo "sem busca / sem resultado\n";
$r = t_http('GET', $base . '/busca/mapa');
t_ok($r['status'] === 200 && $attr($r['body'], 'data-api') === '', 'sem filtros -> sem data-api (nada a buscar)');
$r = t_http('GET', $base . '/busca/mapa?localidade=' . urlencode('Cidade Inexistente XYZ'));
t_eq([], json_decode(t_http('GET', $base . (string) $attr($r['body'], 'data-api'))['body'], true)['items'] ?? null, 'localidade sem imóveis -> API vazia');

echo "imóvel inativo fica fora\n";
db()->prepare("UPDATE properties SET status = 'paused' WHERE id = ?")->execute([$owner['house_id']]);
t_eq(0, count(json_decode(t_http('GET', $base . '/api/busca?localidade=' . $loc)['body'], true)['items'] ?? [1]), 'API (e o mapa) não lista imóvel pausado');

echo "log: cada visita ao mapa grava exatamente uma busca\n";
$count = static fn (): int => t_log_count(ActivityLog::SEARCH);
$before = $count();
$page = t_http('GET', $base . '/busca/mapa?localidade=' . $loc, null, [], null, true);
t_eq($before, $count(), 'a página /busca/mapa não grava SEARCH');
t_http('GET', $base . (string) $attr($page['body'], 'data-api'));
t_eq($before + 1, $count(), 'a chamada à API do mapa grava 1 SEARCH (total 1 por visita)');

t_done();
