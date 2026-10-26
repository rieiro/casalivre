<?php
// Coordenada pública (Segurança #36): arredondada a 3 casas + deslocamento fixo de 300-500 m por imóvel
// (HMAC de 'property:'+id com GEO_SALT); sem GEO_SALT em produção: 2 casas, sem deslocamento, log
// geo_salt_missing; mapa, centro da busca e API nunca levam a coordenada exata; distance_km inteiro.
require __DIR__ . '/lib.php';
t_temp_db();
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/helpers.php';

$lat = -27.594321;
$lng = -48.548765;

echo "unidade\n";
putenv('APP_ENV=production');
putenv('GEO_SALT=' . str_repeat('ab', 32));
$ok = true;
for ($id = 1; $id <= 500; $id++) {
    $pc = public_coord($id, $lat, $lng);
    $m = haversine_km($lat, $lng, $pc['lat'], $pc['lng']) * 1000;
    if ($m < 300 || $m > 500 || round($pc['lat'], 3) !== $pc['lat'] || round($pc['lng'], 3) !== $pc['lng']) {
        $ok = false;
        echo "    id $id: {$m} m\n";
    }
}
t_ok($ok, '500 imóveis: 3 casas e 300-500 m do ponto real');
t_eq(public_coord(7, $lat, $lng), public_coord(7, $lat, $lng), 'estável para o mesmo imóvel');
t_ok(public_coord(7, $lat, $lng) !== public_coord(8, $lat, $lng), 'difere entre imóveis no mesmo ponto');
$a = public_coord(7, $lat, $lng);
putenv('GEO_SALT=' . str_repeat('cd', 32));
t_ok(public_coord(7, $lat, $lng) !== $a, 'depende do GEO_SALT');

$log = tempnam(sys_get_temp_dir(), 'geo');
ini_set('error_log', $log);
putenv('GEO_SALT=');
t_eq(['lat' => -27.59, 'lng' => -48.55], public_coord(7, $lat, $lng), 'produção sem GEO_SALT: 2 casas, sem deslocamento');
public_coord(8, $lat, $lng);
t_eq(1, substr_count((string) file_get_contents($log), 'geo_salt_missing'), 'geo_salt_missing registrado uma vez');
putenv('APP_ENV=dev');
$d = public_coord(7, $lat, $lng);
t_ok(haversine_km($lat, $lng, $d['lat'], $d['lng']) * 1000 >= 300, 'dev sem GEO_SALT: sal fixo, com deslocamento');
t_ok(is_int(public_distance_km(['lat' => -27.6, 'lng' => -48.5], ['id' => 7, 'lat' => $lat, 'lng' => $lng])), 'distância pública é int');

echo "HTTP\n";
putenv('APP_ENV=production');
putenv('GEO_SALT=' . str_repeat('ab', 32));
$owner = t_seed_owner();
db()->prepare("UPDATE properties SET status = 'active', lat = ?, lng = ? WHERE id = ?")->execute([$lat, $lng, $owner['house_id']]);
$expected = public_coord((int) $owner['house_id'], $lat, $lng);
$base = t_server();
$loc = urlencode('Florianópolis, SC');
$exact = ['-27.594321', '-48.548765', '-27.594', '-48.548', '-48.549', '27.594321', '48.548765'];
$noExact = static function (string $body) use ($exact): bool {
    foreach ($exact as $e) {
        if (str_contains($body, $e)) {
            return false;
        }
    }
    return true;
};
// Pins do mapa: lat/lng de /api/busca (GeoJSON montado no navegador).
$r = t_http('GET', $base . '/api/busca?localidade=' . $loc);
$p = json_decode($r['body'], true)['items'] ?? [];
t_eq([$expected['lat'], $expected['lng']], [$p[0]['lat'] ?? null, $p[0]['lng'] ?? null], 'mapa (API) usa a coordenada pública');
t_ok($noExact($r['body']), 'API do mapa sem a coordenada exata');
$r = t_http('GET', $base . '/busca/mapa?localidade=' . $loc, null, [], null, true);
t_ok($r['status'] === 200 && $noExact($r['body']), 'página do mapa sem a coordenada exata');
foreach (['/busca?localidade=' . $loc, '/busca?localidade=' . $loc . '&raio=50', '/api/busca?localidade=' . $loc . '&raio=50', '/api/imoveis/' . $owner['hash'], '/imoveis/' . $owner['hash']] as $u) {
    $follow = str_starts_with($u, '/busca?');
    $r = t_http('GET', $base . $u, null, [], null, $follow);
    t_ok($r['status'] === 200 && $noExact($r['body']), "$u sem coordenada exata");
}
$j = json_decode(t_http('GET', $base . '/api/busca?localidade=' . $loc . '&raio=50')['body'], true) ?: [];
$dk = $j['items'][0]['distance_km'] ?? 'x';
t_ok(is_int($dk), 'distance_km inteiro na API (' . var_export($dk, true) . ')');
t_done();
