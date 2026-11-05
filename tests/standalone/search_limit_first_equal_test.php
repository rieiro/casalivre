<?php

declare(strict_types=1);

// F1/F4: limit-first (empty property filters) returns the same ids, in the same order, as the join predicate.
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

$insert = static function (array $over = []) use ($userId, $cityId, $lat, $lng): int {
    return HouseRepository::insert(array_merge([
        'user_id' => $userId, 'title' => 'Casa', 'description' => 'Descricao de teste do imovel.',
        'summary' => 'Resumo', 'city_place_id' => $cityId, 'address' => 'Rua A, 1',
        'lat' => $lat, 'lng' => $lng, 'bedrooms' => 1, 'beds' => 1, 'max_guests' => 8,
        'nightly_price' => 200, 'checkin_time' => '15:00', 'checkout_time' => '11:00',
        'min_nights' => 1, 'pool' => 0, 'bbq' => 0, 'garage' => 0,
    ], $over));
};

$ids = [];
for ($i = 0; $i < 12; $i++) {
    $ids[] = $insert(['title' => 'C' . $i, 'max_guests' => $i === 0 ? 2 : 8]);
}
$far = $insert(['title' => 'Longe', 'lat' => $lat + 2, 'lng' => $lng]);
$paused = $insert(['title' => 'Pausado']);
db()->prepare("UPDATE properties SET status = 'paused' WHERE id = ?")->execute([$paused]);
ListingSearch::sync($paused);
HouseRepository::addReview($ids[3], 'Ana', 10, '');
HouseRepository::addReview($ids[7], 'Ana', 8, '');
HouseRepository::addReview($ids[7], 'Bia', 8, '');
HouseRepository::addReview($ids[11], 'Ana', 4, '');
HouseRepository::addReview($ids[11], 'Bia', 5, '');

$bbox = SearchSql::bbox($lat, $lng, 10);
$ordered = static fn (array $rows): array => array_map(static fn (array $row): int => (int) $row['id'], $rows);
$join = static function (?array $box, int $guests, float $score, ?array $after, string $sort, int $limit) use ($city): array {
    [$where, $params] = SearchSql::where($city, null, $guests, $box, null, null, $after, $score, $sort);
    $stmt = db()->prepare(
        'SELECT p.id, s.rank_base, s.rating_rank FROM listing_search s JOIN properties p ON p.id = s.id WHERE '
        . $where . ' ORDER BY ' . SearchSql::orderBy($sort) . ' LIMIT ' . $limit
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
};
$equal = static function (string $label, ?array $box, int $guests, float $score, string $sort, int $limit) use ($city, $join, $ordered): void {
    $firstJoin = $join($box, $guests, $score, null, $sort, $limit);
    $firstBatch = SearchSql::batch($city, null, $guests, $box, null, null, null, $limit, $score, $sort);
    t_eq($ordered($firstJoin), $ordered($firstBatch), $label . ' page');
    t_ok(count($firstBatch) === $limit, $label . ' fills the page');
    $column = SearchSql::sortColumn($sort);
    $last = $firstJoin[array_key_last($firstJoin)];
    $after = ['key' => (int) $last[$column], 'id' => (int) $last['id']];
    $secondJoin = $join($box, $guests, $score, $after, $sort, $limit);
    $secondBatch = SearchSql::batch($city, null, $guests, $box, null, null, $after, $limit, $score, $sort);
    t_eq($ordered($secondJoin), $ordered($secondBatch), $label . ' keyset');
    t_ok($secondBatch !== [], $label . ' keyset has rows');
};

echo "city and bbox\n";
foreach ([SearchQuery::SORT_RELEVANCE, SearchQuery::SORT_RATING] as $sort) {
    $equal('city ' . $sort, null, 0, 0.0, $sort, 4);
    $equal('bbox ' . $sort, $bbox, 0, 0.0, $sort, 4);
}
$equal('city guests', null, 4, 0.0, SearchQuery::SORT_RELEVANCE, 4);
$equal('bbox guests', $bbox, 4, 0.0, SearchQuery::SORT_RELEVANCE, 4);
$equal('city score', null, 0, 4.5, SearchQuery::SORT_RELEVANCE, 2);
$equal('bbox score', $bbox, 0, 4.5, SearchQuery::SORT_RELEVANCE, 2);

$cityIds = $ordered(SearchSql::batch($city, null, 0, null, null, null, null, 50, 0.0, SearchQuery::SORT_RELEVANCE));
t_ok(!in_array($paused, $cityIds, true), 'a synced paused property stays out of the city page');
t_ok(in_array($far, $cityIds, true), 'a far house stays in the city page');
$boxIds = $ordered(SearchSql::batch($city, null, 0, $bbox, null, null, null, 50, 0.0, SearchQuery::SORT_RELEVANCE));
t_ok(!in_array($far, $boxIds, true) && !in_array($paused, $boxIds, true), 'bbox drops the far house and the paused house');

t_done();
