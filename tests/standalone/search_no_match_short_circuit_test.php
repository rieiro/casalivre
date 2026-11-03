<?php

declare(strict_types=1);

// F5: hospedes above 999 returns 0 without a listing query. A real miss skips the batch loop.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/SearchService.php';
require_once CL_ROOT . '/src/HouseRepository.php';

putenv('GEO_SALT=' . str_repeat('t', 64));
t_temp_db();
$owner = t_seed_owner();
$city = CityRepository::resolve('Gramado, RS')['place'];
t_ok(is_array($city), 'Gramado resolves');
HouseRepository::insert([
    'user_id' => (int) $owner['user_id'], 'title' => 'Casa', 'description' => 'Descricao de teste do imovel.',
    'summary' => 'Resumo', 'city_place_id' => (int) $city['id'], 'address' => 'Rua A, 1',
    'lat' => (float) $city['lat'], 'lng' => (float) $city['lng'], 'bedrooms' => 1, 'beds' => 1, 'max_guests' => 4,
    'nightly_price' => 200, 'checkin_time' => '15:00', 'checkout_time' => '11:00',
    'min_nights' => 1, 'pool' => 0, 'bbq' => 0, 'garage' => 0,
]);

$search = static function (int $guests, float $score = 0.0): array {
    return SearchService::search(SearchQuery::fromRequest([
        'localidade' => 'Gramado, RS',
        'hospedes' => (string) $guests,
        'score_min' => (string) $score,
    ]), null);
};

echo "no match\n";
$miss = $search(16);
t_eq([], $miss['items'], 'hospedes 16 returns no items');
t_eq(0, $miss['iteracoes'], 'hospedes 16 runs no batch');
t_eq(0, $miss['linhas_lidas'], 'hospedes 16 reads no rows');
t_eq(0, $miss['total_aprox'], 'hospedes 16 total is 0');
$hit = $search(4);
t_eq(1, count($hit['items']), 'hospedes 4 keeps the house');
t_eq(1, $hit['iteracoes'], 'a hit still runs the batch');

echo "above the cap\n";
$holder = new PDO((string) getenv('DB_DSN'), getenv('DB_USER') ?: null, getenv('DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$holder->beginTransaction();
$holder->exec('LOCK TABLE listing_search IN ACCESS EXCLUSIVE MODE');
$started = microtime(true);
$huge = $search(1000);
$elapsed = microtime(true) - $started;
$holder->rollBack();
t_eq([], $huge['items'], 'hospedes 1000 returns no items');
t_eq(0, $huge['iteracoes'], 'hospedes 1000 runs no batch');
t_eq(0, $huge['total_aprox'], 'hospedes 1000 total is 0');
t_ok($elapsed < 1, 'hospedes 1000 does not wait on listing_search (' . round($elapsed, 3) . 's)');
$score = $search(0, 10.1);
t_eq(0, $score['iteracoes'], 'score above 10 still skips the listing query');
t_eq(0, $score['total_aprox'], 'score above 10 total is 0');

t_done();
