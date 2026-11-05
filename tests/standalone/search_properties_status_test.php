<?php

declare(strict_types=1);

// Security 3.1: listing_search.status active with properties.status not active does not appear.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/SearchService.php';
require_once CL_ROOT . '/src/SearchSql.php';
require_once CL_ROOT . '/src/HouseRepository.php';
require_once CL_ROOT . '/src/ListingSearch.php';

putenv('GEO_SALT=' . str_repeat('t', 64));
t_temp_db();
$owner = t_seed_owner();
$userId = (int) $owner['user_id'];
$city = CityRepository::resolve('Gramado, RS')['place'];
t_ok(is_array($city), 'Gramado resolves');
$insert = static function (int $placeId, float $lat, float $lng, string $title) use ($userId): int {
    return HouseRepository::insert([
        'user_id' => $userId, 'title' => $title, 'description' => 'Descricao de teste do imovel.',
        'summary' => 'Resumo', 'city_place_id' => $placeId, 'address' => 'Rua A, 1',
        'lat' => $lat, 'lng' => $lng, 'bedrooms' => 1, 'beds' => 1, 'max_guests' => 4,
        'nightly_price' => 200, 'checkin_time' => '15:00', 'checkout_time' => '11:00',
        'min_nights' => 1, 'pool' => 0, 'bbq' => 0, 'garage' => 0,
    ]);
};
$pause = static function (int $id): void {
    db()->prepare("UPDATE properties SET status = 'paused' WHERE id = ?")->execute([$id]);
};
$lat = (float) $city['lat'];
$lng = (float) $city['lng'];
$good = $insert((int) $city['id'], $lat, $lng, 'Ativo');
$bad = $insert((int) $city['id'], $lat, $lng, 'Stale');
$pause($bad);
t_eq('active', (string) db()->query('SELECT status FROM listing_search WHERE id = ' . $bad)->fetchColumn(), 'projection stays active');
t_eq('paused', (string) db()->query('SELECT status FROM properties WHERE id = ' . $bad)->fetchColumn(), 'property is paused');

$ids = static fn (array $rows): array => array_map(static fn (array $row): int => (int) $row['id'], $rows);
$bbox = SearchSql::bbox($lat, $lng, 10);
$cityBatch = SearchSql::batch($city, null, 0, null, null, null, null, 20, 0.0, SearchQuery::SORT_RELEVANCE);
$boxBatch = SearchSql::batch($city, null, 0, $bbox, null, null, null, 20, 0.0, SearchQuery::SORT_RELEVANCE);
t_eq([$good], $ids($cityBatch), 'city batch keeps the active house only');
t_eq([$good], $ids($boxBatch), 'bbox batch keeps the active house only');

$poi = db()->query("SELECT p.id, p.lat, p.lng, c.id AS city_id FROM places p JOIN places c ON c.id = p.parent_id WHERE p.type = 'poi' AND p.name = 'Praia de Geribá'")->fetch();
t_ok(is_array($poi), 'Praia de Geribá is seeded');
$poiPlace = CityRepository::byId((int) $poi['id']);
t_ok(is_array($poiPlace), 'POI loads');
$poiGood = $insert((int) $poi['city_id'], (float) $poi['lat'], (float) $poi['lng'], 'Na praia');
$poiBad = $insert((int) $poi['city_id'], (float) $poi['lat'], (float) $poi['lng'], 'Praia stale');
$pause($poiBad);
$poiBox = SearchSql::bbox((float) $poi['lat'], (float) $poi['lng'], 3);
$poiBatch = SearchSql::batch($poiPlace, null, 0, $poiBox, null, null, null, 20, 0.0, SearchQuery::SORT_RELEVANCE);
t_ok(in_array($poiGood, $ids($poiBatch), true) && !in_array($poiBad, $ids($poiBatch), true), 'POI batch drops the stale row');

echo "search\n";
$cityPage = SearchService::search(SearchQuery::fromRequest(['localidade' => 'Gramado, RS']), null);
$boxPage = SearchService::search(SearchQuery::fromRequest(['localidade' => 'Gramado, RS', 'raio' => '10']), null);
$poiPage = SearchService::search(SearchQuery::fromRequest(['local_id' => (string) $poi['id']]), null);
$pageIds = static fn (array $page): array => array_map(static fn (array $row): int => (int) $row['id'], $page['items']);
t_eq([$good], $pageIds($cityPage), 'city search hides the stale property');
t_eq([$good], $pageIds($boxPage), 'bbox search hides the stale property');
t_ok(in_array($poiGood, $pageIds($poiPage), true) && !in_array($poiBad, $pageIds($poiPage), true), 'POI search hides the stale property');

t_done();
