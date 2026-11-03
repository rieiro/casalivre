<?php

declare(strict_types=1);

// F3: with no neighborhood, group, or dates, totalAprox counts listing_search and does not read properties.status.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/SearchSql.php';
require_once CL_ROOT . '/src/HouseRepository.php';
require_once CL_ROOT . '/src/ListingSearch.php';

t_temp_db();
$owner = t_seed_owner();
$userId = (int) $owner['user_id'];
$city = CityRepository::resolve('Gramado, RS')['place'];
t_ok(is_array($city), 'Gramado resolves');
$cityId = (int) $city['id'];
$lat = (float) $city['lat'];
$lng = (float) $city['lng'];
$insert = static function (int $placeId, array $over = []) use ($userId, $lat, $lng): int {
    return HouseRepository::insert(array_merge([
        'user_id' => $userId, 'title' => 'Casa', 'description' => 'Descricao de teste do imovel.',
        'summary' => 'Resumo', 'city_place_id' => $placeId, 'address' => 'Rua A, 1',
        'lat' => $lat, 'lng' => $lng, 'bedrooms' => 1, 'beds' => 1, 'max_guests' => 8,
        'nightly_price' => 200, 'checkin_time' => '15:00', 'checkout_time' => '11:00',
        'min_nights' => 1, 'pool' => 0, 'bbq' => 0, 'garage' => 0,
    ], $over));
};
$small = $insert($cityId, ['title' => 'Poucos', 'max_guests' => 2]);
$scored = $insert($cityId, ['title' => 'Nota']);
HouseRepository::addReview($scored, 'Ana', 10, '');
$plain = $insert($cityId, ['title' => 'Comum']);
$far = $insert($cityId, ['title' => 'Longe', 'lat' => $lat + 2]);
$bbox = SearchSql::bbox($lat, $lng, 10);

$capped = static function (?array $box, int $guests, ?string $checkin, ?string $checkout, float $score) use ($city): int|string {
    [$where, $params] = SearchSql::where($city, null, $guests, $box, $checkin, $checkout, null, $score, SearchQuery::SORT_RELEVANCE);
    $stmt = db()->prepare('SELECT 1 FROM listing_search s JOIN properties p ON p.id = s.id WHERE ' . $where . ' LIMIT 101');
    $stmt->execute($params);
    $n = count($stmt->fetchAll());
    return $n > 100 ? '100+' : $n;
};
$same = static function (string $label, ?array $box, int $guests, ?string $checkin, ?string $checkout, float $score) use ($city, $capped): void {
    t_eq(
        $capped($box, $guests, $checkin, $checkout, $score),
        SearchSql::totalAprox($city, null, $guests, $box, $checkin, $checkout, $score),
        $label
    );
};

echo "same count\n";
$same('city', null, 0, null, null, 0.0);
$same('guests', null, 4, null, null, 0.0);
$same('score', null, 0, null, null, 9.0);
$same('bbox', $bbox, 0, null, null, 0.0);
t_eq(4, SearchSql::totalAprox($city, null, 0, null, null, null, 0.0), 'four active houses');
t_eq(3, SearchSql::totalAprox($city, null, 4, null, null, null, 0.0), 'guests 4 drops max_guests 2');
t_eq(1, SearchSql::totalAprox($city, null, 0, null, null, null, 9.0), 'score 9 keeps the 10');
t_eq(3, SearchSql::totalAprox($city, null, 0, $bbox, null, null, 0.0), 'bbox drops the far house');

echo "cap\n";
$santos = CityRepository::resolve('Santos, SP')['place'];
t_ok(is_array($santos), 'Santos resolves');
for ($i = 0; $i < 101; $i++) {
    $insert((int) $santos['id'], ['title' => 'L' . $i, 'lat' => (float) $santos['lat'], 'lng' => (float) $santos['lng']]);
}
t_eq('100+', SearchSql::totalAprox($santos, null, 0, null, null, null, 0.0), '101 houses cap at 100+');

echo "stale projection\n";
db()->prepare("UPDATE properties SET status = 'paused' WHERE id = ?")->execute([$plain]);
t_eq('active', (string) db()->query('SELECT status FROM listing_search WHERE id = ' . $plain)->fetchColumn(), 'listing_search stays active');
t_eq(4, SearchSql::totalAprox($city, null, 0, null, null, null, 0.0), 'no-join count still includes the stale row');
t_eq(3, $capped(null, 0, null, null, 0.0), 'a properties join does not');
$ids = array_map(static fn (array $row): int => (int) $row['id'], SearchSql::batch($city, null, 0, null, null, null, null, 20, 0.0, SearchQuery::SORT_RELEVANCE));
t_ok(!in_array($plain, $ids, true), 'batch drops the stale row on properties.status');
$in = (new DateTimeImmutable('today'))->modify('+10 days')->format('Y-m-d');
$out = (new DateTimeImmutable('today'))->modify('+12 days')->format('Y-m-d');
t_eq(3, SearchSql::totalAprox($city, null, 0, null, $in, $out, 0.0), 'dates join properties and drop the stale row');

t_done();
