<?php

declare(strict_types=1);

// F2: bbox + group reads the group first (MATERIALIZED, BETWEEN). Same ids as the join predicate.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/SearchSql.php';
require_once CL_ROOT . '/src/HouseRepository.php';
require_once CL_ROOT . '/src/ListingSearch.php';

$src = (string) file_get_contents(CL_ROOT . '/src/SearchSql.php');
$schema = (string) file_get_contents(CL_ROOT . '/database/schema.pgsql.sql');
t_ok(str_contains($src, 'WITH g AS MATERIALIZED (SELECT id FROM properties WHERE group_id = ?)'), 'group-first materializes the group');
t_ok(str_contains($src, 's.lng BETWEEN ? AND ?') && str_contains($src, 's.lat BETWEEN ? AND ?'), 'group box is BETWEEN');
t_ok(!preg_match('/CREATE INDEX[^;]*calendar_days[^;]*WHERE blocked/s', $schema), 'no partial index on calendar_days');

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
        'lat' => $lat, 'lng' => $lng, 'bedrooms' => 1, 'beds' => 1, 'max_guests' => 4,
        'nightly_price' => 200, 'checkin_time' => '15:00', 'checkout_time' => '11:00',
        'min_nights' => 1, 'pool' => 0, 'bbq' => 0, 'garage' => 0,
    ], $over));
};
db()->prepare('INSERT INTO groups (slug, name, theme) VALUES (?, ?, ?)')->execute(['gbox', 'Grupo Box', 'padrao']);
$groupId = (int) db()->query("SELECT id FROM groups WHERE slug = 'gbox'")->fetchColumn();
$link = db()->prepare('UPDATE properties SET group_id = ? WHERE id = ?');
$inside = [];
for ($i = 0; $i < 8; $i++) {
    $id = $insert(['title' => 'G' . $i]);
    $link->execute([$groupId, $id]);
    $inside[] = $id;
}
$outside = $insert(['title' => 'Fora', 'lat' => $lat + 2]);
$link->execute([$groupId, $outside]);
$other = $insert(['title' => 'Outro']);
$today = new DateTimeImmutable('today');
$in = $today->modify('+10 days')->format('Y-m-d');
$out = $today->modify('+12 days')->format('Y-m-d');
db()->prepare('INSERT INTO calendar_days (property_id, day, blocked) VALUES (?, ?, 1)')->execute([$inside[0], $in]);
HouseRepository::addReview($inside[1], 'Ana', 10, '');
HouseRepository::addReview($inside[2], 'Ana', 4, '');
HouseRepository::addReview($inside[2], 'Bia', 4, '');

$bbox = SearchSql::bbox($lat, $lng, 10);
$ordered = static fn (array $rows): array => array_map(static fn (array $row): int => (int) $row['id'], $rows);
$joinRows = static function (?string $checkin, ?string $checkout, float $score, ?array $after, int $limit) use ($city, $groupId, $bbox): array {
    [$where, $params] = SearchSql::where($city, $groupId, 0, $bbox, $checkin, $checkout, $after, $score, SearchQuery::SORT_RELEVANCE);
    $stmt = db()->prepare(
        'SELECT p.id, s.rank_base FROM listing_search s JOIN properties p ON p.id = s.id WHERE '
        . $where . ' ORDER BY ' . SearchSql::orderBy(SearchQuery::SORT_RELEVANCE) . ' LIMIT ' . $limit
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
};
$capped = static function (?string $checkin, ?string $checkout, float $score) use ($city, $groupId, $bbox): int|string {
    [$where, $params] = SearchSql::where($city, $groupId, 0, $bbox, $checkin, $checkout, null, $score, SearchQuery::SORT_RELEVANCE);
    $stmt = db()->prepare('SELECT 1 FROM listing_search s JOIN properties p ON p.id = s.id WHERE ' . $where . ' LIMIT 101');
    $stmt->execute($params);
    $n = count($stmt->fetchAll());
    return $n > 100 ? '100+' : $n;
};
$same = static function (string $label, ?string $checkin, ?string $checkout, float $score, int $limit) use ($city, $groupId, $bbox, $joinRows, $ordered, $capped): array {
    $first = $joinRows($checkin, $checkout, $score, null, $limit);
    $batch = SearchSql::batch($city, $groupId, 0, $bbox, $checkin, $checkout, null, $limit, $score, SearchQuery::SORT_RELEVANCE);
    t_eq($ordered($first), $ordered($batch), $label . ' page');
    t_eq($capped($checkin, $checkout, $score), SearchSql::totalAprox($city, $groupId, 0, $bbox, $checkin, $checkout, $score), $label . ' total');
    return $first;
};

echo "group bbox\n";
$plain = $same('plain', null, null, 0.0, 3);
t_ok(count($plain) === 3, 'plain page is 3');
t_ok(!in_array($outside, $ordered($plain), true) && !in_array($other, $ordered($plain), true), 'outside the box and outside the group stay off the first page');
$last = $plain[array_key_last($plain)];
$after = ['key' => (int) $last['rank_base'], 'id' => (int) $last['id']];
$secondJoin = $joinRows(null, null, 0.0, $after, 20);
$secondBatch = SearchSql::batch($city, $groupId, 0, $bbox, null, null, $after, 20, 0.0, SearchQuery::SORT_RELEVANCE);
t_eq($ordered($secondJoin), $ordered($secondBatch), 'plain keyset');
t_ok($secondBatch !== [], 'plain keyset has rows');
$all = array_merge($ordered($plain), $ordered($secondBatch));
t_ok(in_array($inside[1], $all, true) && !in_array($outside, $all, true) && !in_array($other, $all, true), 'the group inside the box is the whole result');

$dates = $same('dates', $in, $out, 0.0, 3);
t_ok(!in_array($inside[0], $ordered($dates), true), 'a blocked night drops out of the group bbox');
$score = $same('score', null, null, 9.0, 5);
t_eq([$inside[1]], $ordered($score), 'score 9 keeps only the 10');

$noBox = SearchSql::batch($city, $groupId, 0, null, null, null, null, 20, 0.0, SearchQuery::SORT_RELEVANCE);
[$where, $params] = SearchSql::where($city, $groupId, 0, null, null, null, null, 0.0, SearchQuery::SORT_RELEVANCE);
$stmt = db()->prepare('SELECT p.id FROM listing_search s JOIN properties p ON p.id = s.id WHERE ' . $where . ' ORDER BY ' . SearchSql::orderBy(SearchQuery::SORT_RELEVANCE) . ' LIMIT 20');
$stmt->execute($params);
t_eq($ordered($stmt->fetchAll()), $ordered($noBox), 'group without a bbox stays the join');
t_ok(in_array($outside, $ordered($noBox), true), 'group without a bbox keeps the house outside the box');

t_done();
