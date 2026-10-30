<?php

declare(strict_types=1);

// Listing filter follows the resolved place type: city id, exact neighborhood id, POI radius.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/SearchService.php';
require_once CL_ROOT . '/src/HouseService.php';
require_once CL_ROOT . '/src/CepRepository.php';

putenv('GEO_SALT=' . str_repeat('t', 64));

$cityWhere = SearchSql::where(['id' => 1, 'type' => 'city'], null, 0, null, null, null, null, 0.0);
t_ok(str_contains($cityWhere[0], 's.city_place_id = ?') && str_contains($cityWhere[0], '(s.rank_base, s.id) <') === false, 'city predicate is city_place_id');
$cityKey = SearchSql::where(['id' => 1, 'type' => 'city'], null, 0, null, null, null, ['key' => 315, 'id' => 9], 0.0);
t_ok(str_contains($cityKey[0], 'ORDER BY') === false && str_contains($cityKey[0], '(s.rank_base, s.id) < (?, ?)'), 'keyset is (rank_base, id)');
$nbWhere = SearchSql::where(['id' => 2, 'type' => 'neighborhood', 'parent_id' => 1], null, 0, null, null, null, null, 0.0);
t_ok(str_contains($nbWhere[0], 's.city_place_id = ?') && str_contains($nbWhere[0], "p.status = 'active'") && str_contains($nbWhere[0], 'p.neighborhood_place_id = ?'), 'neighborhood predicate is city and the exact id');
$poiWhere = SearchSql::where(['id' => 3, 'type' => 'poi'], null, 0, ['s' => 0.0, 'n' => 1.0, 'w' => 2.0, 'e' => 3.0], null, null, null, 0.0);
t_ok(!str_contains($poiWhere[0], 'place_id') && str_contains($poiWhere[0], 'point(s.lng, s.lat) <@ box(point(?, ?), point(?, ?))'), 'POI predicate is the geo box');
$cityRadius = SearchSql::where(['id' => 1, 'type' => 'city'], null, 0, ['s' => 0.0, 'n' => 1.0, 'w' => 2.0, 'e' => 3.0], null, null, null, 0.0);
t_ok(!str_contains($cityRadius[0], 'city_place_id') && str_contains($cityRadius[0], 's.lat BETWEEN'), 'city radius still drops the city id');
t_ok(!str_contains((string) file_get_contents(CL_ROOT . '/src/SearchSql.php'), 'ORDER BY p.id ASC'), 'order is no longer id ASC');

t_temp_db();
$owner = t_seed_owner();
$userId = (int) $owner['user_id'];
$rio = CityRepository::exactCity('Rio de Janeiro', 'RJ');
$floripa = CityRepository::exactCity('Florianópolis', 'SC');
t_ok(is_array($rio) && is_array($floripa), 'Rio and Florianópolis resolve');
$rioId = (int) $rio['id'];
$floripaId = (int) $floripa['id'];
$nbStmt = db()->prepare("SELECT id, name, name_fold FROM places WHERE type = 'neighborhood' AND parent_id = ? ORDER BY id LIMIT 2");
$nbStmt->execute([$rioId]);
$neighborhoods = $nbStmt->fetchAll();
t_eq(2, count($neighborhoods), 'Rio has two seeded neighborhoods');
$firstNb = (int) $neighborhoods[0]['id'];
$secondNb = (int) $neighborhoods[1]['id'];
t_eq($firstNb, CityRepository::neighborhoodId($rioId, (string) $neighborhoods[0]['name']), 'exact folded name in the same city');
$onlyRio = db()->prepare("SELECT name FROM places p WHERE p.type = 'neighborhood' AND p.parent_id = ? AND NOT EXISTS (SELECT 1 FROM places n WHERE n.type = 'neighborhood' AND n.parent_id = ? AND n.name_fold = p.name_fold) LIMIT 1");
$onlyRio->execute([$rioId, $floripaId]);
$onlyRioName = (string) $onlyRio->fetchColumn();
t_ok($onlyRioName !== '', 'Rio has a neighborhood name that Florianópolis does not');
t_eq(null, CityRepository::neighborhoodId($floripaId, $onlyRioName), 'exact name under another city stays null');

$insert = static function (int $cityId, ?int $neighborhoodId, float $lat, float $lng, string $title) use ($userId): int {
    return HouseRepository::insert([
        'user_id' => $userId, 'title' => $title, 'description' => 'Descricao de teste do imovel.',
        'summary' => 'Resumo', 'city_place_id' => $cityId, 'neighborhood_place_id' => $neighborhoodId,
        'address' => 'Rua A, 1', 'lat' => $lat, 'lng' => $lng, 'bedrooms' => 1, 'beds' => 1,
        'max_guests' => 4, 'nightly_price' => 200, 'checkin_time' => '15:00', 'checkout_time' => '11:00',
        'min_nights' => 1, 'pool' => 0, 'bbq' => 0, 'garage' => 0,
    ]);
};
$rioLat = (float) $rio['lat'];
$rioLng = (float) $rio['lng'];
$inFirst = $insert($rioId, $firstNb, $rioLat, $rioLng, 'Bairro A');
$inSecond = $insert($rioId, $secondNb, $rioLat, $rioLng, 'Bairro B');
$noNb = $insert($rioId, null, $rioLat, $rioLng, 'Sem bairro');
$farFirst = $insert($rioId, $firstNb, $rioLat + (40 / 111.32), $rioLng, 'Bairro A longe');

$ids = static function (array $request): array {
    $result = SearchService::search(SearchQuery::fromRequest($request), null);
    $got = array_map(static fn (array $row): int => (int) $row['id'], $result['items']);
    $newest = $got;
    rsort($newest);
    t_eq($newest, $got, 'tied rank breaks by newest id');
    t_eq(null, $result['next_cursor'], 'small page has no cursor');
    return $got;
};

echo "city\n";
t_eq([$farFirst, $noNb, $inSecond, $inFirst], $ids(['local_id' => (string) $rioId]), 'city search returns every house in the city');

echo "neighborhood\n";
t_eq([$farFirst, $inFirst], $ids(['local_id' => (string) $firstNb]), 'neighborhood search is that id only');
t_eq([$inSecond], $ids(['local_id' => (string) $secondNb]), 'the other neighborhood of the same city stays out');
t_eq([$farFirst, $inFirst], $ids(['local_id' => (string) $firstNb, 'raio' => '5']), 'neighborhood search ignores raio');

echo "poi\n";
$poiStmt = db()->query("SELECT p.id, p.lat, p.lng, c.id AS city_id FROM places p JOIN places c ON c.id = p.parent_id WHERE p.type = 'poi' AND p.name = 'Praia de Geribá'");
$poi = $poiStmt->fetch();
t_ok(is_array($poi), 'Praia de Geribá is seeded');
$poiId = (int) $poi['id'];
$poiLat = (float) $poi['lat'];
$poiLng = (float) $poi['lng'];
$poiCity = (int) $poi['city_id'];
$inside = $insert($poiCity, null, $poiLat, $poiLng, 'Na praia');
$atPublicKm = static function (float $km, string $title) use ($insert, $poiCity, $poiLat, $poiLng): int {
    $id = $insert($poiCity, null, $poiLat, $poiLng, $title);
    $lat = $poiLat + ($km / 111.32);
    $got = 0.0;
    for ($i = 0; $i < 12 && abs($got - $km) >= 0.05; $i++) {
        db()->prepare('UPDATE properties SET lat = ?, lng = ? WHERE id = ?')->execute([$lat, $poiLng, $id]);
        ListingSearch::sync($id);
        $public = public_coord($id, $lat, $poiLng);
        $got = haversine_km($poiLat, $poiLng, $public['lat'], $public['lng']);
        $lat += ($km - $got) / 111.32;
    }
    t_ok(abs($got - $km) < 0.05, $title);
    return $id;
};
$near = $atPublicKm(2.9, 'house sits at 2.9 km');
$past = $atPublicKm(3.6, 'house sits at 3.6 km');
$outside = $insert($poiCity, null, $poiLat + (8 / 111.32), $poiLng, 'Fora');
$otherCityInside = $insert($rioId, null, $poiLat, $poiLng, 'Outra cidade');
$defaultIds = $ids(['local_id' => (string) $poiId]);
t_eq([$otherCityInside, $near, $inside], $defaultIds, '2.9 km stays inside the default radius, with no city filter');
t_ok(!in_array($past, $defaultIds, true), '3.6 km is outside the default radius');
t_ok(!in_array($outside, $defaultIds, true), 'a house in the POI city but 8 km away is excluded');
t_eq([$otherCityInside, $outside, $past, $near, $inside], $ids(['local_id' => (string) $poiId, 'raio' => '10']), 'an explicit raio replaces the 3 km default');
$poiResult = SearchService::search(SearchQuery::fromRequest(['local_id' => (string) $poiId]), null);
t_ok(isset($poiResult['items'][0]['distance_km']), 'POI rows carry distance_km');

echo "cep\n";
$floripaNb = db()->prepare("SELECT id, name FROM places WHERE type = 'neighborhood' AND parent_id = ? ORDER BY id LIMIT 1");
$floripaNb->execute([$floripaId]);
$localNb = $floripaNb->fetch();
t_ok(is_array($localNb), 'Florianópolis has a seeded neighborhood');
$foreignName = (string) db()->query("SELECT p.name FROM places p WHERE p.type = 'neighborhood' AND p.parent_id <> $floripaId AND NOT EXISTS (SELECT 1 FROM places n WHERE n.type = 'neighborhood' AND n.parent_id = $floripaId AND n.name_fold = p.name_fold) LIMIT 1")->fetchColumn();
t_ok($foreignName !== '', 'some neighborhood name is not in Florianópolis');
db()->prepare('INSERT INTO ceps (cep, street, neighborhood, city, uf) VALUES (?, ?, ?, ?, ?)')->execute(['88010000', 'Rua do Bairro', $localNb['name'], 'Florianópolis', 'SC']);
db()->prepare('INSERT INTO ceps (cep, street, neighborhood, city, uf) VALUES (?, ?, ?, ?, ?)')->execute(['88020000', 'Rua Sem Bairro', $foreignName, 'Florianópolis', 'SC']);
$houseInput = static function (string $cep): array {
    return [
        'title' => 'Casa CEP', 'description' => 'Descrição de teste do imóvel.', 'cep' => $cep,
        'address_number' => '10', 'max_guests' => '2', 'nightly_price' => '200', 'bedrooms' => '1', 'beds' => '1',
    ];
};
$created = HouseService::create($userId, $houseInput('88010000'));
t_ok(!isset($created['error']), 'create with a seeded neighborhood');
$createdId = (int) $created['id'];
t_eq((int) $localNb['id'], (int) HouseRepository::find($createdId)['neighborhood_place_id'], 'CEP neighborhood stores neighborhood_place_id');
$updated = HouseService::update($userId, $createdId, $houseInput('88020000'));
t_ok(!isset($updated['error']), 'update to a CEP whose neighborhood is not in the city');
t_eq(null, HouseRepository::find($createdId)['neighborhood_place_id'], 'unmatched neighborhood stays NULL');
$restored = HouseService::update($userId, $createdId, $houseInput('88010000'));
t_ok(!isset($restored['error']), 'update back to the seeded neighborhood');
t_eq((int) $localNb['id'], (int) HouseRepository::find($createdId)['neighborhood_place_id'], 'CEP change writes the new neighborhood_place_id');

echo "map and api\n";
$base = t_server();
$attr = static function (string $body, string $name): ?string {
    if (!preg_match('/<div id="result-map"[^>]*\s' . preg_quote($name, '/') . '="([^"]*)"/s', $body, $m)) {
        return null;
    }
    return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
};
$centerOf = static function (float $lat, float $lng): string {
    return round($lat, 2) . ',' . round($lng, 2);
};
$nbPage = t_http('GET', $base . '/busca/mapa?local_id=' . $firstNb);
t_eq(200, $nbPage['status'], 'neighborhood map responds');
t_eq($centerOf($rioLat, $rioLng), (string) $attr($nbPage['body'], 'data-center'), 'neighborhood map centers on the stored place point');
$hash = static fn (int $id): string => HouseRepository::ensurePublicHash(HouseRepository::find($id));
$nbApi = json_decode(t_http('GET', $base . '/api/busca?local_id=' . $firstNb)['body'], true);
t_eq([$hash($farFirst), $hash($inFirst)], array_column($nbApi['items'], 'id'), 'neighborhood API lists those houses');
t_eq(null, $nbApi['items'][0]['distance_km'], 'neighborhood API has no radius distance');
t_ok(!str_contains(json_encode($nbApi), 'Rua A'), 'neighborhood API has no address');
t_eq($firstNb, $nbApi['filters']['local_id'], 'API keeps local_id');
$poiPage = t_http('GET', $base . '/busca/mapa?local_id=' . $poiId);
t_eq($centerOf($poiLat, $poiLng), (string) $attr($poiPage['body'], 'data-center'), 'POI map centers on the POI');
$poiParent = db()->prepare('SELECT lat, lng FROM places WHERE id = ?');
$poiParent->execute([$poiCity]);
$poiParentRow = $poiParent->fetch();
t_ok($centerOf($poiLat, $poiLng) !== $centerOf((float) $poiParentRow['lat'], (float) $poiParentRow['lng']), 'POI center is not the parent city');
$poiApi = json_decode(t_http('GET', $base . '/api/busca?local_id=' . $poiId)['body'], true);
t_eq([$hash($otherCityInside), $hash($near), $hash($inside)], array_column($poiApi['items'], 'id'), 'POI API lists houses inside the radius');
t_ok(is_int($poiApi['items'][0]['distance_km']) && is_float($poiApi['items'][0]['lat']), 'POI API keeps distance and public coordinates');

t_done();
