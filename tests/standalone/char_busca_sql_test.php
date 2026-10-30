<?php

declare(strict_types=1);

// ADR 001 PR1: resolved-place SQL search vs the removed PHP scan.
// The oracle below is the old filter (test only). Unresolved text is not compared to it.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/SearchService.php';
require_once CL_ROOT . '/src/Api.php';
require_once CL_ROOT . '/src/CepRepository.php';
require_once CL_ROOT . '/src/GroupRepository.php';

putenv('GEO_SALT=' . str_repeat('t', 64));

t_eq(20, SearchService::PAGE_SIZE, 'page 20');
t_eq(60, SearchService::BATCH_SIZE, 'batch 60');
t_eq(4, SearchService::MAX_QUERIES, 'max 4 listing queries');
t_ok(!method_exists(HouseRepository::class, 'byCity'), 'HouseRepository::byCity removed');
t_ok(!method_exists(GroupRepository::class, 'activePropertyStrict'), 'activePropertyStrict removed');
t_ok(!array_key_exists('Búzios', cities()), 'cities() has no stale Búzios point');
t_ok(!str_contains((string) file_get_contents(CL_ROOT . '/src/SearchService.php'), '$_GET'), 'SearchService does not read $_GET');
$where = SearchSql::where(['id' => 1, 'type' => 'city'], null, 0, null, '2026-11-01', '2026-11-03', ['key' => 315, 'id' => 9], 0.0, 'relevance');
$scoreWhere = SearchSql::where(['id' => 1, 'type' => 'city'], null, 0, null, null, null, null, 4.5, 'relevance');
t_ok(str_contains($scoreWhere[0], 'p.rating_count > 0 AND 20 * p.rating_sum + p.rating_count >= ? * p.rating_count'), 'score_min is a plain filter on the rating columns');
t_eq(90, $scoreWhere[1][array_key_last($scoreWhere[1])], '4.5 binds 2*ceil(round(45, 6))');
t_ok(str_contains($where[0], "s.status = 'active'"), 'one active rule');
t_ok(str_contains($where[0], 'NOT EXISTS') && str_contains($where[0], 'c.blocked = 1'), 'blocked night is NOT EXISTS');
t_ok(str_contains($where[0], '(s.rank_base, s.id) < (?, ?)'), 'keyset is (rank_base, id)');
t_eq('s.rank_base DESC, s.id DESC', SearchSql::orderBy(SearchQuery::SORT_RELEVANCE), 'relevance orders by rank_base, id');
t_eq('s.rating_rank DESC, s.id DESC', SearchSql::orderBy(SearchQuery::SORT_RATING), 'rating orders by rating_rank, id');
t_eq(SearchQuery::SORT_RELEVANCE, SearchQuery::fromRequest(['sort' => 'weekly'])->sort, 'unknown sort falls back to relevance');
t_ok(!str_contains((string) file_get_contents(CL_ROOT . '/views/search.php'), '<b>'), 'search view does not print facet counts');

t_temp_db();
$owner = t_seed_owner();
$userId = (int) $owner['user_id'];
$floripa = CityRepository::resolve('Florianópolis, SC')['place'];
$rio = CityRepository::resolve('Rio de Janeiro, RJ')['place'];
$buzios = CityRepository::resolve('Búzios')['place'];
$gramado = CityRepository::resolve('Gramado, RS')['place'];
t_ok(is_array($floripa) && is_array($rio) && is_array($buzios) && is_array($gramado), 'Florianópolis, Rio, Búzios and Gramado resolve');
$floripaId = (int) $floripa['id'];
$rioId = (int) $rio['id'];
$buziosId = (int) $buzios['id'];
$gramadoId = (int) $gramado['id'];
$officialBuzios = $buzios['name'] . ', ' . trim((string) $buzios['uf']);

$today = new DateTimeImmutable('today');
$in = $today->modify('+10 days')->format('Y-m-d');
$out = $today->modify('+12 days')->format('Y-m-d');
$maxDay = $today->modify('+1 month');
$overIn = $maxDay->format('Y-m-d');
$overOut = $maxDay->modify('+2 days')->format('Y-m-d');
$lat = (float) $floripa['lat'];
$lng = (float) $floripa['lng'];

$house = static function (int $placeId, array $over = []) use ($userId, $lat, $lng): int {
    return HouseRepository::insert(array_merge([
        'user_id' => $userId, 'title' => 'Casa', 'description' => 'Descricao de teste do imovel.',
        'summary' => 'Resumo', 'city_place_id' => $placeId, 'address' => 'Rua A, 1',
        'lat' => $lat, 'lng' => $lng, 'bedrooms' => 1, 'beds' => 1, 'max_guests' => 4,
        'nightly_price' => 200, 'checkin_time' => '15:00', 'checkout_time' => '11:00',
        'min_nights' => 1, 'pool' => 0, 'bbq' => 0, 'garage' => 0,
    ], $over));
};
$day = static function (int $id, string $day, ?int $blocked = 0, ?int $price = null, ?int $minNights = null): void {
    db()->prepare('INSERT INTO calendar_days (property_id, day, blocked, price, min_nights) VALUES (?, ?, ?, ?, ?)')
        ->execute([$id, $day, $blocked, $price, $minNights]);
};

$guests1 = $house($floripaId, ['title' => 'Poucos', 'max_guests' => 1]);
$pricey = $house($floripaId, ['title' => 'Caro', 'nightly_price' => 5000]);
$cheapEdge = $house($floripaId, ['title' => 'Barato', 'nightly_price' => 50]);
$scored = $house($floripaId, ['title' => 'Nota']);
HouseRepository::addReview($scored, 'Ana', 4, '');
HouseRepository::addReview($scored, 'Bia', 5, '');
$pool = $house($floripaId, ['title' => 'Piscina', 'pool' => 1]);
$blocked = $house($floripaId, ['title' => 'Bloqueado']);
$day($blocked, $in, 1);
$minZero = $house($floripaId, ['title' => 'Min zero', 'min_nights' => 3]);
$day($minZero, $in, 0, null, 0);
$minNull = $house($floripaId, ['title' => 'Min null', 'min_nights' => 3]);
$day($minNull, $in, 0, null, null);
$custom = $house($floripaId, ['title' => 'Min custom', 'min_nights' => 5]);
$day($custom, $in, 0, null, 1);
$special = $house($floripaId, ['title' => 'Preco especial', 'nightly_price' => 200]);
$day($special, $in, 0, 100, null);
$horizon = $house($floripaId, ['title' => 'Horizonte', 'calendar_months' => 1]);
$emptyStatus = $house($floripaId, ['title' => 'Status vazio']);
db()->prepare("UPDATE properties SET status = '' WHERE id = ?")->execute([$emptyStatus]);
ListingSearch::sync($emptyStatus);
$inactive = $house($floripaId, ['title' => 'Inativo']);
db()->prepare("UPDATE properties SET status = 'inactive' WHERE id = ?")->execute([$inactive]);
ListingSearch::sync($inactive);
$near = $house($rioId, ['title' => 'Perto', 'lat' => $lat + (2 / 111.32), 'lng' => $lng]);
$far = $house($rioId, ['title' => 'Longe', 'lat' => (float) $rio['lat'], 'lng' => (float) $rio['lng']]);
$buziosHouse = $house($buziosId, ['title' => 'Buzios', 'lat' => (float) $buzios['lat'], 'lng' => (float) $buzios['lng']]);

$bulk = static function (int $placeId, int $n, int $price, float $rowLat, float $rowLng) use ($userId): array {
    $st = db()->prepare('INSERT INTO properties (user_id, title, description, summary, city_place_id, address, lat, lng, bedrooms, beds, max_guests, nightly_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id');
    $ids = [];
    for ($i = 0; $i < $n; $i++) {
        $st->execute([$userId, 'Lote', 'Descricao de teste do imovel.', 'Resumo', $placeId, 'Rua A, 1', $rowLat, $rowLng, 1, 1, 4, $price]);
        $id = (int) $st->fetchColumn();
        ListingSearch::sync($id);
        $ids[] = $id;
    }
    return $ids;
};
$activeFloripa = static function () use ($floripaId): int {
    $st = db()->prepare("SELECT COUNT(*) FROM properties WHERE city_place_id = ? AND status = 'active'");
    $st->execute([$floripaId]);
    return (int) $st->fetchColumn();
};
$pad = 240 - $activeFloripa();
if ($pad > 0) {
    $bulk($floripaId, $pad, 1, $lat, $lng);
}
$expensive = $bulk($floripaId, 3, 9000, $lat, $lng);
$bulk($gramadoId, 45, 200, (float) $gramado['lat'], (float) $gramado['lng']);

db()->prepare('INSERT INTO groups (slug, name, theme) VALUES (?, ?, ?)')->execute(['ga', 'Grupo A', 'padrao']);
$groupId = (int) db()->query("SELECT id FROM groups WHERE slug = 'ga'")->fetchColumn();
db()->prepare('INSERT INTO group_domains (group_id, domain, verified_at) VALUES (?, ?, CURRENT_TIMESTAMP)')->execute([$groupId, 'ga.example.com']);
db()->prepare('UPDATE properties SET group_id = ? WHERE id = ?')->execute([$groupId, $pool]);
db()->prepare('UPDATE properties SET group_id = ? WHERE id = ?')->execute([$groupId, $scored]);
db()->prepare('UPDATE properties SET group_id = ? WHERE id = ?')->execute([$groupId, $emptyStatus]);
GrupoResolvedor::clearCache();

/** Old PHP scan (status active, id ASC). Unresolved text still runs the removed substring branch here. @return list<int> */
function legacy_ids(array $request, mixed $host): array
{
    $filters = [
        'localidade' => trim((string) ($request['localidade'] ?? '')),
        'checkin' => (string) ($request['checkin'] ?? ''),
        'checkout' => (string) ($request['checkout'] ?? ''),
        'hospedes' => (int) ($request['hospedes'] ?? 0),
        'raio' => (int) ($request['raio'] ?? 0),
        'preco_min' => (int) ($request['preco_min'] ?? 0),
        'preco_max' => (int) ($request['preco_max'] ?? 0),
        'score_min' => (float) ($request['score_min'] ?? 0),
        'amenidades' => array_values(array_filter((array) ($request['amenidade'] ?? []), 'is_string')),
    ];
    if (array_key_exists('grupo', $request)) {
        $filters['grupo'] = is_string($request['grupo']) ? $request['grupo'] : '';
    }
    if (array_key_exists('grupo', $filters)) {
        $group = GrupoResolvedor::grupoDoSlugNoHost($filters['grupo'], $host);
        $source = $group === null ? [] : legacy_group((int) $group['id']);
    } elseif (($hostGroup = GrupoResolvedor::doDominio($host)) !== null) {
        $source = legacy_group((int) $hostGroup['id']);
    } else {
        $source = HouseRepository::all();
    }
    $localidade = $filters['localidade'];
    $center = $localidade !== '' ? CityRepository::resolve($localidade)['place'] : null;
    $nights = nights_between($filters['checkin'], $filters['checkout']);
    $centerFold = $center ? fold((string) $center['name']) : '';
    $labelFold = $center ? fold((string) $center['label']) : '';
    $displayFold = $center ? fold($center['name'] . ', ' . $center['uf']) : '';
    $pool = [];
    foreach ($source as $property) {
        $cityFold = fold((string) $property['city']);
        if ($center && $filters['raio'] > 0) {
            if (public_distance_km($center, $property) > $filters['raio']) {
                continue;
            }
        } elseif ($center && $cityFold !== $centerFold && $cityFold !== $labelFold && $cityFold !== $displayFold) {
            continue;
        } elseif (!$center && $localidade !== '' && !str_contains($cityFold . ' ' . fold((string) (house_neighborhood($property) ?? '')), fold($localidade))) {
            continue;
        }
        if ($filters['hospedes'] > 0 && (int) $property['max_guests'] < $filters['hospedes']) {
            continue;
        }
        $pool[] = $property;
    }
    $ids = array_map(static fn (array $property): int => (int) $property['id'], $pool);
    $calendars = $nights !== [] ? HouseRepository::calendarForProperties($ids, $nights) : [];
    $quoted = [];
    foreach ($pool as $property) {
        $id = (int) $property['id'];
        $quote = $nights !== []
            ? stay_quote($property, $nights, $calendars[$id] ?? [])
            : ['nights' => 0, 'total' => (int) $property['nightly_price'], 'nightly' => (int) $property['nightly_price']];
        if ($nights !== [] && $quote === null) {
            continue;
        }
        if ($filters['preco_min'] > 0 && $quote['nightly'] < $filters['preco_min']) {
            continue;
        }
        if ($filters['preco_max'] > 0 && $quote['nightly'] > $filters['preco_max']) {
            continue;
        }
        $quoted[] = $property;
    }
    $scores = [];
    $scoreIds = array_map(static fn (array $property): int => (int) $property['id'], $quoted);
    if ($scoreIds !== []) {
        $marks = implode(',', array_fill(0, count($scoreIds), '?'));
        $scoreStmt = db()->prepare("SELECT property_id, AVG(score) AS avg_score FROM reviews WHERE property_id IN ($marks) GROUP BY property_id");
        $scoreStmt->execute($scoreIds);
        foreach ($scoreStmt as $row) {
            $scores[(int) $row['property_id']] = round((float) $row['avg_score'], 1);
        }
    }
    $wanted = array_values(array_filter($filters['amenidades'], static fn (string $key): bool => in_array($key, Amenities::keys(), true)));
    $rows = [];
    foreach ($quoted as $property) {
        $id = (int) $property['id'];
        $score = $scores[$id] ?? null;
        if ($filters['score_min'] > 0 && ($score === null || $score < $filters['score_min'])) {
            continue;
        }
        $selected = Amenities::selected($property);
        foreach ($wanted as $key) {
            if (empty($selected[$key])) {
                continue 2;
            }
        }
        $rows[] = $id;
    }
    return $rows;
}

function legacy_group(int $groupId): array
{
    $st = db()->prepare(HouseRepository::SQL_WITH_CITY . ' WHERE p.group_id = ? AND ' . SearchSql::active('p') . ' ORDER BY p.id ASC');
    $st->execute([$groupId]);
    return $st->fetchAll();
}

/** @return array{ids:list<int>,linhas:int} */
function walk(array $request, mixed $host = null): array
{
    $ids = [];
    $linhas = 0;
    $cursor = null;
    $seen = [];
    for ($page = 0; $page < 40; $page++) {
        $query = SearchQuery::fromRequest($request);
        $after = $cursor === null ? null : SearchQuery::position($cursor, $query->filtersHash(), $query->sort);
        $result = SearchService::search($query, $host, false, $after);
        t_ok($result['iteracoes'] <= SearchService::MAX_QUERIES && $result['linhas_lidas'] <= SearchService::MAX_QUERIES * SearchService::BATCH_SIZE, 'page stays within 4 queries and 240 rows');
        $linhas += $result['linhas_lidas'];
        foreach ($result['items'] as $item) {
            $ids[] = (int) $item['id'];
        }
        $cursor = $result['next_cursor'];
        if ($cursor === null) {
            return ['ids' => $ids, 'linhas' => $linhas];
        }
        t_ok(!isset($seen[$cursor]), 'cursor does not repeat');
        $seen[$cursor] = true;
    }
    t_ok(false, 'walk did not finish');
    return ['ids' => $ids, 'linhas' => $linhas];
}

$sameIds = static function (array $a, array $b, string $msg): void {
    sort($a);
    sort($b);
    t_eq($a, $b, $msg);
};

$same = static function (array $request, mixed $host, string $msg) use ($sameIds): array {
    $got = walk($request, $host);
    $sameIds(legacy_ids($request, $host), $got['ids'], $msg);
    t_eq(count($got['ids']), count(array_unique($got['ids'])), $msg . ': no repeat');
    return $got;
};

echo "resolved city and filters\n";
$city = ['localidade' => 'Florianópolis, SC'];
$all = $same($city, null, 'city walk matches the old scan');
t_eq('100+', SearchService::search(SearchQuery::fromRequest($city), null)['total_aprox'], 'total_aprox caps at 100+');
t_eq($activeFloripa(), $all['linhas'], 'city walk reads each SQL candidate once');
t_ok(!in_array($emptyStatus, $all['ids'], true) && !in_array($inactive, $all['ids'], true), 'empty and inactive status stay out');
t_ok(in_array($pricey, $all['ids'], true) && in_array($cheapEdge, $all['ids'], true), 'price edges stay in the unfiltered city');
t_ok(!in_array($near, $all['ids'], true) && !in_array($far, $all['ids'], true), 'other cities stay out of the place filter');

$priced = $same(['localidade' => 'Florianópolis, SC', 'preco_min' => 9000], null, 'preco_min walks the whole set');
$sameIds($expensive, $priced['ids'], 'only the three expensive houses');
t_eq($activeFloripa(), $priced['linhas'], 'price filter reads each candidate once, no skip');
$first = SearchService::search(SearchQuery::fromRequest(['localidade' => 'Florianópolis, SC', 'preco_min' => 9000]), null);
t_eq(4, $first['iteracoes'], 'first expensive page uses 4 listing queries');
t_eq(240, $first['linhas_lidas'], 'first expensive page reads 240 rows');
t_eq(3, count($first['items']), 'newest matches are on the first page');
t_ok(count($first['items']) < SearchService::PAGE_SIZE, 'first expensive page is not full');
t_ok(is_string($first['next_cursor']), 'first expensive page has a cursor');

$guests = $same(['localidade' => 'Florianópolis, SC', 'hospedes' => 2], null, 'guests');
t_ok(!in_array($guests1, $guests['ids'], true), 'max_guests 1 drops out');
$stay = ['localidade' => 'Florianópolis, SC', 'checkin' => $in, 'checkout' => $out];
$stayed = $same($stay, null, 'stay quote, min nights and blocked night');
t_ok(!in_array($blocked, $stayed['ids'], true), 'blocked night drops out');
t_ok(!in_array($minZero, $stayed['ids'], true) && !in_array($minNull, $stayed['ids'], true), 'min_nights 0 and NULL on check-in keep the property minimum');
t_ok(in_array($custom, $stayed['ids'], true), 'custom min_nights 1 allows the stay');
t_ok(in_array($horizon, $stayed['ids'], true), 'stay inside the 1-month horizon stays');
$over = $same(['localidade' => 'Florianópolis, SC', 'checkin' => $overIn, 'checkout' => $overOut], null, 'night past the horizon');
t_ok(!in_array($horizon, $over['ids'], true), 'calendar_months 1 drops a night after max_calendar_day');
$pricedNight = $same($stay + ['preco_min' => 150, 'preco_max' => 150], null, 'special price on one night');
t_eq([$special], $pricedNight['ids'], 'nightly is round((100+200)/2)');
$scoreKeep = $same($city + ['score_min' => '4.5'], null, 'score 4.5 keeps the x.5 average');
t_eq([$scored], $scoreKeep['ids'], 'only the reviewed house at 4.5');
$scoreDrop = $same($city + ['score_min' => '4.6'], null, 'score 4.6 drops the x.5 average');
t_eq([], $scoreDrop['ids'], '4.6 drops the house and houses with no reviews');
$petropolis = CityRepository::resolve('Petrópolis, RJ')['place'];
t_ok(is_array($petropolis), 'Petrópolis resolves');
$petropolisId = (int) $petropolis['id'];
$half = $house($petropolisId, ['title' => 'Meio']);
HouseRepository::addReview($half, 'Ana', 4, '');
HouseRepository::addReview($half, 'Bia', 5, '');
$edge = $house($petropolisId, ['title' => 'Limite']);
for ($i = 0; $i < 19; $i++) {
    HouseRepository::addReview($edge, 'N' . $i, 8, '');
}
HouseRepository::addReview($edge, 'Baixa', 7, '');
$bare = $house($petropolisId, ['title' => 'Sem nota']);
$scoreCity = ['localidade' => 'Petrópolis, RJ'];
$keep80 = $same($scoreCity + ['score_min' => '8'], null, 'score 8.0 keeps the 7.95 average');
t_eq([$edge], $keep80['ids'], '7.95 rounds to 8.0 and an empty rating stays out');
$drop81 = $same($scoreCity + ['score_min' => '8.1'], null, 'score 8.1 drops the 7.95 average');
t_eq([], $drop81['ids'], '8.1 drops 7.95');
$keep45g = $same($scoreCity + ['score_min' => '4.5'], null, 'score 4.5 keeps 4.5 and 7.95');
t_eq([$edge, $half], $keep45g['ids'], '4.5 keeps both rounded averages, newest first');
t_ok(!in_array($bare, $keep45g['ids'], true), 'a house with no reviews stays out');
$card = SearchService::search(SearchQuery::fromRequest($scoreCity + ['score_min' => '8']), null)['items'][0] ?? [];
t_eq(8.0, $card['score'] ?? null, 'card score is round(rating_sum/rating_count, 1)');
$stored = db()->query('SELECT rating_sum, rating_count FROM properties WHERE id = ' . $edge)->fetch();
t_eq(159, (int) $stored['rating_sum'], 'rating_sum matches SUM after 20 reviews');
t_eq(20, (int) $stored['rating_count'], 'rating_count matches COUNT after 20 reviews');
t_eq(9, (int) db()->query('SELECT rating_sum FROM properties WHERE id = ' . $scored)->fetchColumn(), 'seed-style addReview keeps rating_sum');
t_eq(2, (int) db()->query('SELECT rating_count FROM properties WHERE id = ' . $scored)->fetchColumn(), 'seed-style addReview keeps rating_count');
$santos = CityRepository::resolve('Santos, SP')['place'];
t_ok(is_array($santos), 'Santos resolves');
$santosId = (int) $santos['id'];
$perfect = $house($santosId, ['title' => 'Dez']);
HouseRepository::addReview($perfect, 'Ana', 10, '');
HouseRepository::addReview($perfect, 'Bia', 10, '');
$almost = $house($santosId, ['title' => 'Nove e meio']);
HouseRepository::addReview($almost, 'Ana', 9, '');
HouseRepository::addReview($almost, 'Bia', 10, '');
$santosQ = ['localidade' => 'Santos, SP'];
$score10 = $same($santosQ + ['score_min' => '10'], null, 'score 10 keeps only an all-10 average');
t_eq([$perfect], $score10['ids'], 'score 10 drops the 9.5 average');
$score101 = SearchService::search(SearchQuery::fromRequest($santosQ + ['score_min' => '10.1']), null);
t_eq([], $score101['items'], 'score 10.1 matches nothing');
t_eq(0, $score101['iteracoes'], 'score 10.1 runs no listing query');
t_eq(0, $score101['linhas_lidas'], 'score 10.1 reads no rows');
t_eq(0, $score101['total_aprox'], 'score 10.1 total_aprox matches an empty page');
t_eq(0.0, SearchQuery::fromRequest(['score_min' => 'nan'])->scoreMin, 'nan score_min is 0');
t_eq(0.0, SearchQuery::fromRequest(['score_min' => '-1'])->scoreMin, 'negative score_min is 0');
t_eq(
    SearchQuery::fromRequest($santosQ)->filtersHash(),
    SearchQuery::fromRequest($santosQ + ['score_min' => 'nan'])->filtersHash(),
    'nan score_min hashes as zero'
);
$amenity = $same($city + ['amenidade' => ['pool']], null, 'amenity');
t_eq([$pool], $amenity['ids'], 'only the pool house');

echo "radius, group, buzios, unresolved\n";
$radius = $same(['localidade' => 'Florianópolis, SC', 'raio' => 25], null, 'radius matches the old haversine');
t_ok(in_array($near, $radius['ids'], true), 'bbox + haversine keeps a house 2 km away in another city');
t_ok(!in_array($far, $radius['ids'], true), 'a house at the other city center stays out of the radius');
$byGroup = $same(['localidade' => 'Florianópolis, SC', 'grupo' => 'ga'], null, 'group param matches the old group source');
$withGroup = SearchQuery::fromRequest(['localidade' => 'Florianópolis, SC', 'grupo' => 'ga']);
$withoutGrupo = $withGroup->filters();
unset($withoutGrupo['grupo']);
t_eq($withoutGrupo, $withGroup->pageFilters(), 'pageFilters drops grupo');
$byHost = $same(['localidade' => 'Florianópolis, SC'], 'ga.example.com', 'verified host matches the old group source');
t_eq($byGroup['ids'], $byHost['ids'], 'storefront and public search return the same rows');
t_eq([$pool, $scored], $byGroup['ids'], 'group is the two linked houses, newest first');
$homeIds = array_map('intval', array_column(GroupRepository::properties($groupId), 'id'));
t_ok(!in_array($emptyStatus, $homeIds, true), 'home does not list empty status');
t_ok(in_array($pool, $homeIds, true), 'home still lists an active house of the group');

$buziosIds = [];
foreach (['Búzios', 'Búzios, RJ', $officialBuzios] as $name) {
    $buziosIds[$name] = walk(['localidade' => $name])['ids'];
    t_eq([$buziosHouse], $buziosIds[$name], $name . ' finds the official city');
}
t_eq($buziosIds['Búzios'], $buziosIds[$officialBuzios], 'Búzios and the official name are the same set');

$pageCity = ['localidade' => 'Gramado, RS'];
$pageWalk = walk($pageCity);
$sameIds(legacy_ids($pageCity, null), $pageWalk['ids'], 'Gramado walk');
$tieDesc = $pageWalk['ids'];
rsort($tieDesc);
t_eq($tieDesc, $pageWalk['ids'], 'relevance ties break by newest id');
t_eq($pageWalk['ids'], walk($pageCity + ['sort' => 'rating'])['ids'], 'rating walk matches relevance while only the rating factor exists');
t_eq($pageWalk['ids'], walk($pageCity + ['sort' => 'weekly'])['ids'], 'unknown sort falls back to relevance');
t_eq(45, count($pageWalk['ids']), 'Mostrar mais walks 20 + 20 + 5');
t_eq(45, SearchService::search(SearchQuery::fromRequest($pageCity), null)['total_aprox'], 'total_aprox is the capped SQL count');
$pageFirst = SearchService::search(SearchQuery::fromRequest($pageCity), null);
t_eq(20, count($pageFirst['items']), 'first page is 20');
t_eq(1, $pageFirst['iteracoes'], 'a full first page is one listing query');
t_ok(is_string($pageFirst['next_cursor']), 'first page has a cursor');

$none = SearchService::search(SearchQuery::fromRequest(['localidade' => 'Xyzabc']), null);
t_eq(true, $none['unresolved'], 'unknown text is unresolved');
t_eq([], $none['items'], 'unknown text has no items');
t_eq(0, $none['iteracoes'], 'unknown text runs no listing query');
t_eq(0, $none['linhas_lidas'], 'unknown text reads no rows');
$datesOnly = SearchService::search(SearchQuery::fromRequest(['checkin' => $in, 'checkout' => $out]), null);
t_eq(false, $datesOnly['unresolved'], 'dates without a place are not unresolved text');
t_eq([], $datesOnly['items'], 'dates without a place do not scan every house');
t_eq(0, $datesOnly['iteracoes'], 'dates without a place run no listing query');

echo "cursor and request log\n";
$cursorHash = SearchQuery::fromRequest(['localidade' => 'Gramado, RS'])->filtersHash();
$cursorOf = static fn (int $key, int $id, string $sort = 'relevance'): string => SearchQuery::cursor($key, $id, $sort, $cursorHash);
t_eq(2147483647, SearchQuery::position($cursorOf(9000, 2147483647), $cursorHash, 'relevance')['id'], 'int4 max id is accepted');
$cursorError = static function (string $cursor, string $sort = 'relevance') use ($cursorHash): string {
    try {
        SearchQuery::position($cursor, $cursorHash, $sort);
    } catch (InvalidSearchCursor $e) {
        return $e::class;
    }
    return '';
};
$v1 = rtrim(strtr(base64_encode((string) json_encode(['v' => 1, 'sort' => 'id', 'last_id' => 1, 'filters_hash' => $cursorHash])), '+/', '-_'), '=');
$tamperedError = $cursorError($cursorOf(9000, 1) . 'x');
t_eq(InvalidSearchCursor::class, $tamperedError, 'tampered cursor is InvalidSearchCursor');
t_eq($tamperedError, $cursorError($v1), 'cursor v1 is rejected');
t_eq($tamperedError, $cursorError($cursorOf(9000, 1), 'rating'), 'changed sort is rejected');
t_eq($tamperedError, $cursorError($cursorOf(9000, 2147483648)), 'id above int4 max is rejected');
t_eq($tamperedError, $cursorError($cursorOf(9000, 9999999999)), 'a much larger id is rejected');
$base = t_server();
$pageUrl = $base . '/api/busca?localidade=' . rawurlencode('Gramado, RS');
$open = t_http('GET', $pageUrl);
$openBody = json_decode($open['body'], true);
t_eq(200, $open['status'], 'search 200');
$cursor = (string) ($openBody['next_cursor'] ?? '');
t_ok($cursor !== '', 'HTTP page has next_cursor');
$tamperedHttp = t_http('GET', $pageUrl . '&cursor=' . rawurlencode($cursor . 'x'));
t_eq(400, $tamperedHttp['status'], 'tampered cursor is 400');
$overflowHttp = t_http('GET', $pageUrl . '&cursor=' . rawurlencode($cursorOf(9000, 2147483648)));
t_eq(400, $overflowHttp['status'], 'last_id above int4 max is 400');
t_eq($tamperedHttp['body'], $overflowHttp['body'], 'overflow cursor returns the same error as a tampered cursor');
t_eq(400, t_http('GET', $base . '/api/busca?localidade=' . rawurlencode('Florianópolis, SC') . '&cursor=' . rawurlencode($cursor))['status'], 'cursor with different filters is 400');
$next = t_http('GET', $pageUrl . '&cursor=' . rawurlencode($cursor));
t_eq(200, $next['status'], 'same filters accept the cursor');
$nextItems = json_decode($next['body'], true)['items'] ?? [];
t_eq([], array_values(array_intersect(array_column($openBody['items'], 'id'), array_column($nextItems, 'id'))), 'next page does not repeat the first');

foreach (['1e8', '1e18', '1e999'] as $raw) {
    $blocked = SearchService::search(SearchQuery::fromRequest(['localidade' => 'Gramado, RS', 'score_min' => $raw]), null);
    t_eq([], $blocked['items'], "score_min $raw returns no items");
    t_eq(0, $blocked['iteracoes'], "score_min $raw runs no listing query");
    t_eq(0, $blocked['total_aprox'], "score_min $raw total_aprox matches an empty page");
    $huge = t_http('GET', $base . '/api/busca?localidade=' . rawurlencode('Gramado, RS') . '&score_min=' . rawurlencode($raw));
    $hugeBody = json_decode($huge['body'], true);
    t_eq(200, $huge['status'], "score_min $raw is 200");
    t_eq([], $hugeBody['items'] ?? null, "score_min $raw API returns no items");
}
$nanHttp = t_http('GET', $base . '/api/busca?localidade=' . rawurlencode('Gramado, RS') . '&score_min=nan');
$nanBody = json_decode($nanHttp['body'], true);
t_eq(200, $nanHttp['status'], 'score_min nan is 200');
t_eq(count($openBody['items'] ?? []), count($nanBody['items'] ?? []), 'nan score_min does not filter');
$miss = t_http('GET', $base . '/api/busca?localidade=' . rawurlencode('Xyzabc'));
$missLog = log_entry(CL_ROOT . '/storage/logs/api', $miss['headers']);
t_eq(['iteracoes' => 0, 'linhas_lidas' => 0], $missLog['busca'] ?? null, 'unresolved logs iteracoes and linhas_lidas');
t_ok(is_array($missLog['query'] ?? null) && array_key_exists('localidade', $missLog['query']) && $missLog['query']['localidade'] === null, 'request log does not store the place text');
$hit = t_http('GET', $pageUrl . '&hospedes=2');
$hitLog = log_entry(CL_ROOT . '/storage/logs/api', $hit['headers']);
t_ok(is_int($hitLog['busca']['iteracoes'] ?? null) && is_int($hitLog['busca']['linhas_lidas'] ?? null), 'a city search logs both counters as integers');
$bad = t_http('GET', $pageUrl . '&cursor=' . rawurlencode('nao-e-cursor'));
t_eq(400, $bad['status'], 'garbage cursor is 400');
$badLog = log_entry(CL_ROOT . '/storage/logs/api', $bad['headers']);
t_eq(['iteracoes' => 0, 'linhas_lidas' => 0], $badLog['busca'] ?? null, '400 still records the counter');
$sortHttp = t_http('GET', $pageUrl . '&sort=rating&cursor=' . rawurlencode($cursor));
t_eq(400, $sortHttp['status'], 'changed sort on the HTTP cursor is 400');

echo "statement timeout\n";
$holder = new PDO((string) getenv('DB_DSN'), getenv('DB_USER') ?: null, getenv('DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$holder->beginTransaction();
$holder->exec('LOCK TABLE listing_search IN ACCESS EXCLUSIVE MODE');
$logFile = tempnam(sys_get_temp_dir(), 'cl-search-log');
$prevLog = ini_get('error_log');
ini_set('error_log', $logFile);
$timedOut = false;
try {
    SearchService::search(SearchQuery::fromRequest(['localidade' => 'Gramado, RS']), null);
} catch (SearchTimeout) {
    $timedOut = true;
}
ini_set('error_log', (string) $prevLog);
$slow = t_http('GET', $pageUrl);
$holder->rollBack();
t_ok($timedOut, 'statement timeout is thrown');
t_eq(1, substr_count((string) file_get_contents($logFile), 'search statement timeout'), 'statement timeout logs one line');
@unlink($logFile);
$slowBody = json_decode($slow['body'], true);
t_eq(503, $slow['status'], 'statement timeout is 503');
t_eq(true, $slowBody['retry'] ?? null, 'statement timeout asks for a retry');
t_ok(isset($slowBody['error']) && is_string($slowBody['error']), 'statement timeout has an error');

t_done();
