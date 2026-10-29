<?php

declare(strict_types=1);

// ADR 001 PR4b: listing_search is written with the property, and rank-verify repairs drift.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/HouseRepository.php';
require_once CL_ROOT . '/src/ListingSearch.php';

t_temp_db();
$owner = t_seed_owner();
$id = (int) $owner['house_id'];
$userId = (int) $owner['user_id'];

$rankOf = static function (int $sum, int $count): array {
    $c = Settings::int('rank_c');
    $m = Settings::int('rank_m');
    $weight = Settings::int('rank_w_rating');
    $rating = (int) round(1000 * ($c * $m + $sum) / ($c + $count));
    return [$rating, (int) round($rating / 10 * $weight / 100)];
};

$project = static function (array $row): array {
    return [
        'status' => (string) $row['status'],
        'city_place_id' => (int) $row['city_place_id'],
        'lat' => (float) $row['lat'],
        'lng' => (float) $row['lng'],
        'max_guests' => (int) $row['max_guests'],
        'rating_rank' => (int) $row['rating_rank'],
        'rank_base' => (int) $row['rank_base'],
    ];
};

$stored = static function (int $propertyId) use ($project): array {
    $stmt = db()->prepare('SELECT status, city_place_id, lat, lng, max_guests, rating_rank, rank_base FROM listing_search WHERE id = ?');
    $stmt->execute([$propertyId]);
    return $project($stmt->fetch());
};

$fresh = static function (int $propertyId) use ($rankOf, $project): array {
    $stmt = db()->prepare('SELECT status, city_place_id, lat, lng, max_guests, rating_sum, rating_count FROM properties WHERE id = ?');
    $stmt->execute([$propertyId]);
    $row = $stmt->fetch();
    [$row['rating_rank'], $row['rank_base']] = $rankOf((int) $row['rating_sum'], (int) $row['rating_count']);
    return $project($row);
};

echo "formula\n";
t_eq(5, Settings::int('rank_c'), 'rank_c is 5');
t_eq(9, Settings::int('rank_m'), 'rank_m is 9');
t_eq(35, Settings::int('rank_w_rating'), 'rank_w_rating is 35');
t_eq([9000, 315], $rankOf(0, 0), 'no reviews is round(1000*m) and rank_base 315');
t_eq($fresh($id), $stored($id), 'insert matches the projection');

$house = HouseRepository::find($id);
$house['lat'] = -27.6;
$house['lng'] = -48.5;
$house['max_guests'] = 4;
HouseRepository::update($id, $userId, $house);
HouseRepository::updateMinNights($id, 3);
HouseRepository::addReview($id, 'Ana', 10, '');
HouseRepository::addReview($id, 'Bia', 10, '');
HouseRepository::addReview($id, 'Caio', 10, '');
t_eq([9375, 328], $rankOf(30, 3), 'three 10s');
t_eq($fresh($id), $stored($id), 'edit, min nights and reviews match the projection');

$many = HouseRepository::insert([
    'user_id' => $userId, 'title' => 'Muitas', 'description' => 'Descrição de teste do imóvel.',
    'summary' => 'Resumo', 'city_place_id' => (int) $house['city_place_id'], 'address' => 'Rua B, 2',
    'cep' => '88000000', 'street' => 'Rua B', 'address_number' => '2', 'address_complement' => '',
    'lat' => -27.59, 'lng' => -48.54, 'bedrooms' => 1, 'beds' => 1, 'max_guests' => 2,
    'nightly_price' => 200, 'checkin_time' => '15:00', 'checkout_time' => '11:00', 'min_nights' => 1,
    'pool' => 0, 'bbq' => 0, 'garage' => 0,
]);
db()->prepare('UPDATE properties SET rating_sum = 1960, rating_count = 200 WHERE id = ?')->execute([$many]);
ListingSearch::sync($many);
t_eq([9780, 342], $rankOf(1960, 200), '200 reviews at 9.8');
t_ok($stored($many)['rating_rank'] > $stored($id)['rating_rank'], '200 reviews at 9.8 rank above three 10s');
t_eq($fresh($many), $stored($many), 'explicit sync after a raw total matches the projection');

HouseRepository::inactivate($id, $userId, date('Y-m-d H:i:s'));
t_eq('inactive', $stored($id)['status'], 'inactivate copies status');
t_eq($fresh($id), $stored($id), 'inactive row still matches the projection');

$verify = static function (array $args = []): array {
    $cmd = t_php_shell() . ' ' . escapeshellarg(CL_ROOT . '/scripts/rank-verify.php');
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
};

echo "verify\n";
[$code, $text] = $verify();
t_eq(0, $code, "clean verify exits 0 ($text)");
t_ok(str_contains($text, 'rewritten 0'), 'clean verify rewrites nothing');

db()->prepare('UPDATE listing_search SET rank_base = 0 WHERE id = ?')->execute([$many]);
$held = db()->query('SELECT pg_try_advisory_lock(' . ListingSearch::ADVISORY_LOCK . ')')->fetchColumn();
t_ok($held, 'test holds the verify lock');
[$code, $text] = $verify();
t_eq(0, $code, "a second verify exits 0 ($text)");
t_eq(0, $stored($many)['rank_base'], 'the locked run does not rewrite');
db()->query('SELECT pg_advisory_unlock(' . ListingSearch::ADVISORY_LOCK . ')');

[$code, $text] = $verify();
t_eq(1, $code, "a nightly mismatch exits 1 ($text)");
t_ok(str_contains($text, 'rewritten 1'), 'the nightly run rewrites one row');
t_eq($fresh($many), $stored($many), 'the tampered row is restored');

db()->prepare('UPDATE listing_search SET rank_base = 0 WHERE id = ?')->execute([$many]);
[$code, $text] = $verify(['--recompute']);
t_eq(0, $code, "--recompute exits 0 ($text)");
t_eq($fresh($many), $stored($many), '--recompute restores the row');

echo "rollback\n";
$before = $stored($many)['max_guests'];
$edit = HouseRepository::find($many);
$edit['max_guests'] = 40000;
$failed = false;
try {
    HouseRepository::update($many, $userId, $edit);
} catch (Throwable) {
    $failed = true;
}
t_ok($failed, 'a projection error fails the write');
t_eq($before, (int) HouseRepository::find($many)['max_guests'], 'the property write rolls back');
t_eq($before, $stored($many)['max_guests'], 'listing_search is unchanged');

t_done();
