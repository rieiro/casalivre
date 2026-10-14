<?php
// Mapa (MapLibre GL + PMTiles) sem navegador: CSP 'self' (tudo vendorizado, sem data:/blob:/host externo),
// sem Google, sem tiles raster do OSM, atribuição OSM/Protomaps, .pmtiles fora do git e o GeoJSON dos pins
// (map-data.js, via Node) a partir dos itens de /api/busca. Cobre a parte sem browser de CT-MAPA-008..012.
require __DIR__ . '/lib.php';
t_temp_db();
$owner = t_seed_owner();
$base = t_server();
$read = static fn (string $f): string => (string) file_get_contents(CL_ROOT . '/' . $f);

echo "CSP da página do mapa\n";
$r = t_http('GET', $base . '/busca/mapa?localidade=' . urlencode('Florianópolis, SC'));
preg_match('/^Content-Security-Policy:\s*(.*?)\r?$/mi', $r['headers'], $m);
$policy = $m[1] ?? '';
t_eq(csp_header(), $policy, 'mapa usa a CSP única do site');
t_ok(!preg_match('#https?://|data:|blob:|unsafe|\*#', $policy), "CSP sem host externo, data:, blob:, unsafe ou *");
preg_match_all('/<script\b([^>]*)>/i', $r['body'], $scripts);
foreach ($scripts[1] as $attrs) {
    if (str_contains($attrs, 'application/json')) {
        continue;
    }
    t_ok(preg_match('#\bsrc="/assets/[^"]+"#', $attrs) === 1, 'script local: ' . trim($attrs));
}
preg_match_all('/<link\b[^>]*href="([^"]+)"/i', $r['body'], $links);
foreach ($links[1] as $href) {
    t_ok(str_starts_with($href, '/'), "link local: $href");
}

echo "arquivos vendorizados\n";
foreach ([
    'public/assets/vendor/maplibre-gl/maplibre-gl-csp.js', 'public/assets/vendor/maplibre-gl/maplibre-gl-csp-worker.js',
    'public/assets/vendor/maplibre-gl/maplibre-gl.css', 'public/assets/vendor/maplibre-gl/LICENSE.txt',
    'public/assets/vendor/pmtiles/pmtiles.js', 'public/assets/vendor/pmtiles/LICENSE', 'public/assets/vendor/README.md',
    'public/assets/map/style-light.json', 'public/assets/map/sprites/light.json', 'public/assets/map/sprites/light.png',
    'public/assets/map/fonts/NotoSans-Regular/0-255.pbf', 'public/assets/map/fonts/OFL.txt',
] as $f) {
    t_ok(is_file(CL_ROOT . '/' . $f) && filesize(CL_ROOT . '/' . $f) > 0, "$f existe");
}
$css = $read('public/assets/vendor/maplibre-gl/maplibre-gl.css');
t_ok(!str_contains($css, 'data:'), 'maplibre-gl.css sem data: (ícones viraram arquivos; img-src continua só self)');
preg_match_all('/url\(([^)]+)\)/', $css, $urls);
$missing = array_filter(array_unique($urls[1]), static fn (string $u): bool => !is_file(CL_ROOT . '/public/assets/vendor/maplibre-gl/' . trim($u, '"\'')));
t_eq([], array_values($missing), 'todos os url() do CSS existem localmente');
$worker = $read('views/map.php');
t_ok(str_contains($worker, 'maplibre-gl-csp-worker.js') && str_contains($read('public/assets/pages/map.js'), 'setWorkerUrl'), 'worker do MapLibre local (build CSP; sem worker blob:)');

echo "estilo: só recursos locais\n";
$style = json_decode($read('public/assets/map/style-light.json'), true) ?: [];
t_eq('/assets/map/fonts/{fontstack}/{range}.pbf', $style['glyphs'] ?? null, 'glyphs locais');
t_eq('/assets/map/sprites/light', $style['sprite'] ?? null, 'sprites locais');
t_eq('pmtiles:///tiles/brasil.pmtiles', $style['sources']['protomaps']['url'] ?? null, 'fonte vetorial = PMTiles local');
t_ok(count($style['layers'] ?? []) > 20, 'camadas do basemap Protomaps light');
$fonts = [];
array_walk_recursive($style, static function ($v) use (&$fonts): void {
    if (is_string($v) && str_starts_with($v, 'NotoSans-')) {
        $fonts[$v] = true;
    }
});
foreach (array_keys($fonts) as $font) {
    t_ok(is_file(CL_ROOT . '/public/assets/map/fonts/' . $font . '/0-255.pbf'), "glifos de $font presentes");
}

echo "sem Google, sem tiles raster do OSM\n";
$appFiles = array_merge(
    glob(CL_ROOT . '/views/*.php') ?: [], glob(CL_ROOT . '/src/*.php') ?: [], glob(CL_ROOT . '/public/assets/*.js') ?: [],
    glob(CL_ROOT . '/public/assets/pages/*.js') ?: [], [CL_ROOT . '/public/index.php', CL_ROOT . '/public/assets/app.css', CL_ROOT . '/public/assets/map/style-light.json'],
    glob(CL_ROOT . '/public/assets/vendor/*/*.{js,css}', GLOB_BRACE) ?: []
);
$google = $osmTiles = [];
foreach ($appFiles as $f) {
    $text = (string) file_get_contents($f);
    if (preg_match('/googleapis|gstatic|maps\.google|google\.com\/maps|GOOGLE_MAPS/i', $text)) {
        $google[] = basename($f);
    }
    if (str_contains($text, 'tile.openstreetmap.org')) {
        $osmTiles[] = basename($f);
    }
}
t_eq([], $google, 'nenhum arquivo do app/vendor chama Google');
t_eq([], $osmTiles, 'tiles raster do OSM removidos (substituídos pelo PMTiles)');
t_ok(!is_file(CL_ROOT . '/public/assets/pages/search-tiles.js'), 'search-tiles.js (prévia com tiles OSM) removido');
$s = t_http('GET', $base . '/busca?localidade=x')['body'];
t_ok(!str_contains($s, 'openstreetmap.org/') || !str_contains($s, '.png'), 'busca sem imagem de tile externa');

echo "atribuição\n";
$js = $read('public/assets/pages/map.js');
t_ok(str_contains($js, '© OpenStreetMap contributors</a> · <a href="https://protomaps.com"') && str_contains($js, 'https://www.openstreetmap.org/copyright'), "atribuição '© OpenStreetMap contributors · Protomaps' com links");
t_ok(str_contains($js, 'compact: false'), 'atribuição sempre visível (não compacta)');

echo ".pmtiles fora do git\n";
t_ok(preg_match('/^\*\.pmtiles$/m', $read('.gitignore')) === 1, '.gitignore tem *.pmtiles');
$tracked = (string) shell_exec('cd ' . escapeshellarg(CL_ROOT) . ' && git ls-files 2>/dev/null');
t_ok(!str_contains($tracked, '.pmtiles'), 'nenhum .pmtiles versionado');

echo "GeoJSON dos pins (map-data.js no Node)\n";
$node = trim((string) shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    echo "  (node ausente: casos do map-data.js pulados)\n";
} else {
    $items = [
        ['id' => 'aaaaaaaaaaaaaaaa', 'title' => 'A <b>', 'lat' => -27.591, 'lng' => -48.541, 'nightly_price' => 200, 'quote' => ['nights' => 2, 'total' => 1450]],
        ['id' => 'bbbbbbbbbbbbbbbb', 'title' => 'B', 'lat' => -27.6, 'lng' => -48.5, 'nightly_price' => 1500, 'quote' => ['nights' => 0, 'total' => 1500]],
        ['id' => 'cccccccccccccccc', 'title' => 'sem coordenada', 'lat' => null, 'lng' => null, 'nightly_price' => 100],
        ['id' => '../x', 'title' => 'id inválido', 'lat' => 1.0, 'lng' => 1.0, 'nightly_price' => 100],
        ['id' => 'dddddddddddddddd', 'title' => 'fora do mundo', 'lat' => 95.0, 'lng' => 1.0, 'nightly_price' => 100],
    ];
    $script = 'const m=require(' . json_encode(CL_ROOT . '/public/assets/pages/map-data.js') . ');'
        . 'const fc=m.toGeoJSON(' . json_encode($items) . ',"localidade=Florian%C3%B3polis");'
        . 'process.stdout.write(JSON.stringify({fc:fc,b:m.bounds(fc),e:m.bounds(m.toGeoJSON([],""))}));';
    $out = json_decode((string) shell_exec(escapeshellarg($node) . ' -e ' . escapeshellarg($script)), true) ?: [];
    $f = $out['fc']['features'] ?? [];
    t_eq('FeatureCollection', $out['fc']['type'] ?? null, 'FeatureCollection');
    t_eq(2, count($f), 'só itens com hash válido e coordenada válida viram pontos');
    t_eq([-48.541, -27.591], $f[0]['geometry']['coordinates'] ?? null, 'coordenadas [lng, lat] = as da API (públicas)');
    t_eq('/imoveis/aaaaaaaaaaaaaaaa?localidade=Florian%C3%B3polis', $f[0]['properties']['href'] ?? null, 'link /imoveis/{hash} com a busca');
    t_eq('R$ 1.450', $f[0]['properties']['price'] ?? null, 'pin com o total do período');
    t_eq('R$ 1.450 total · 2 noites · taxas inclusas', $f[0]['properties']['priceText'] ?? null, "texto 'R\$ X total · N noites · taxas inclusas'");
    t_eq('R$ 1.500/noite', $f[1]['properties']['price'] ?? null, 'sem datas: diária');
    t_eq('A <b>', $f[0]['properties']['title'] ?? null, 'título como dado (a UI usa textContent)');
    t_eq([[-48.541, -27.6], [-48.5, -27.591]], $out['b'] ?? null, 'bounds dos pontos');
    t_ok(array_key_exists('e', $out) && $out['e'] === null, 'sem pontos: bounds null');
}

t_done();
