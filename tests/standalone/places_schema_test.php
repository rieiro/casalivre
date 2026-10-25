<?php
// ADR 001 first slice (SCHEMA 21): places + place_search_keys replace cities, properties.city becomes
// city_place_id, and CityRepository match/suggest still resolve a city name.
// SCHEMA 22: bairros OpenCEP (database/places_neighborhoods.json.bz2) e places_city_uf_uk.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/CityRepository.php';
require_once CL_ROOT . '/src/HouseRepository.php';
require_once CL_ROOT . '/src/SearchService.php';
require_once CL_ROOT . '/src/Amenities.php';
require_once CL_ROOT . '/src/PhotoRepository.php';
require_once CL_ROOT . '/src/helpers.php';

t_eq(22, Db::SCHEMA_VERSION, 'SCHEMA_VERSION = 22');
t_eq(['praia do forte', 'forte'], CityRepository::searchKeys('Praia do Forte'), 'sufixos: stopword "do" não começa chave');
t_eq(['arraial do cabo', 'cabo'], CityRepository::searchKeys('Arraial do Cabo'), 'sufixos de Arraial do Cabo');
t_eq(['praia de geriba', 'geriba'], CityRepository::searchKeys('Praia de Geribá'), 'fold + sufixo geriba');

t_temp_db();
$pdo = db();
$cities = json_decode((string) file_get_contents(CL_ROOT . '/database/cities.json'), true);

echo "schema\n";
t_eq(null, $pdo->query("SELECT to_regclass('public.cities')")->fetchColumn(), 'tabela cities não existe');
t_ok($pdo->query("SELECT to_regclass('public.places')")->fetchColumn() === 'places', 'tabela places');
t_ok($pdo->query("SELECT to_regclass('public.place_search_keys')")->fetchColumn() === 'place_search_keys', 'tabela place_search_keys');
t_eq(false, $pdo->query("SELECT 1 FROM pg_indexes WHERE indexname = 'idx_properties_city'")->fetchColumn(), 'sem idx_properties_city');
t_eq(false, $pdo->query("SELECT 1 FROM pg_indexes WHERE indexname = 'places_root_uk'")->fetchColumn(), 'sem places_root_uk (homônimos do cities.json)');
$defs = [];
foreach ($pdo->query("SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public' AND indexname IN ('idx_places_name_fold', 'idx_prop_place_active', 'idx_prop_geo_active', 'place_search_keys_pkey', 'places_child_uk', 'places_city_uf_uk', 'idx_properties_status')") as $row) {
    $defs[$row['indexname']] = $row['indexdef'];
}
t_ok(isset($defs['idx_places_name_fold']) && str_contains($defs['idx_places_name_fold'], 'name_fold'), 'idx_places_name_fold');
t_ok(isset($defs['idx_prop_place_active']) && str_contains($defs['idx_prop_place_active'], 'INCLUDE (max_guests)') && str_contains($defs['idx_prop_place_active'], "status = 'active'"), 'idx_prop_place_active parcial com INCLUDE');
t_ok(isset($defs['idx_prop_geo_active']) && str_contains($defs['idx_prop_geo_active'], 'gist') && str_contains($defs['idx_prop_geo_active'], 'point(lng, lat)'), 'idx_prop_geo_active');
t_ok(isset($defs['place_search_keys_pkey']) && str_contains($defs['place_search_keys_pkey'], 'INCLUDE (popularity)'), 'PK de place_search_keys com INCLUDE (popularity)');
t_ok(isset($defs['places_child_uk']), 'places_child_uk');
t_ok(isset($defs['places_city_uf_uk']) && str_contains($defs['places_city_uf_uk'], 'UNIQUE') && str_contains($defs['places_city_uf_uk'], 'uf') && str_contains($defs['places_city_uf_uk'], 'name_fold') && str_contains($defs['places_city_uf_uk'], "type = 'city'"), 'places_city_uf_uk');
t_ok(isset($defs['idx_properties_status']), 'idx_properties_status permanece (fora deste PR)');
$coll = static fn (string $table, string $col): string => (string) $pdo->query("SELECT c.collname FROM pg_attribute a JOIN pg_class r ON r.oid = a.attrelid JOIN pg_collation c ON c.oid = a.attcollation WHERE r.relname = '$table' AND a.attname = '$col'")->fetchColumn();
t_eq('C', $coll('places', 'name_fold'), 'places.name_fold COLLATE C');
t_eq('C', $coll('place_search_keys', 'key'), 'place_search_keys.key COLLATE C');
t_eq('22', (string) $pdo->query("SELECT value FROM schema_meta WHERE skey = 'schema_version'")->fetchColumn(), 'schema_meta = 22');

echo "seed\n";
$cityUk = [];
foreach ($cities as $city) {
    $cityUk[$city['uf'] . "\0" . fold((string) $city['name'])] = true;
}
t_eq(count($cityUk), (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'city'")->fetchColumn(), 'uma place city por (uf, name_fold)');
t_eq(count($cities), count($cityUk), 'cities.json não repete (uf, name_fold)');
t_eq(1, (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'city' AND rtrim(uf) = 'RJ' AND name_fold = 'armacao dos buzios'")->fetchColumn(), 'um só Armação dos Búzios/RJ');
$buzios = CityRepository::match('Armação dos Búzios, RJ');
t_eq('Armação dos Búzios', (string) ($buzios['name'] ?? ''), 'match do nome oficial de Armação dos Búzios');
foreach (['Búzios', 'buzios', 'Búzios, RJ', 'BUZIOS,rj'] as $alias) {
    t_eq((int) $buzios['id'], (int) (CityRepository::match($alias)['id'] ?? 0), "alias \"$alias\" resolve o mesmo place id de Armação dos Búzios");
}
t_eq('Armação dos Búzios', (string) CityRepository::match('Búzios')['label'], 'rótulo exibido segue o oficial');
t_eq(null, CityRepository::match('Búzios, SP'), 'alias só vale para RJ');
t_eq(2, (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'poi' AND parent_id = " . (int) $buzios['id'])->fetchColumn(), 'Geribá e Ferradura ficam sob a mesma cidade do alias');
t_eq(3, (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'poi'")->fetchColumn(), '3 POIs piloto (Geribá, Ferradura, Praia do Forte)');
t_eq(61543, (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'neighborhood'")->fetchColumn(), '61543 bairros OpenCEP');
t_ok((int) $pdo->query("SELECT COUNT(*) FROM place_search_keys k JOIN places p ON p.id = k.place_id WHERE p.type = 'neighborhood'")->fetchColumn() >= 61543, 'cada bairro tem chave de busca');
t_ok((int) $pdo->query('SELECT COUNT(*) FROM place_search_keys')->fetchColumn() > count($cities), 'chaves de busca semeadas');
$keysOf = static function (string $name) use ($pdo): array {
    $st = $pdo->prepare('SELECT DISTINCT k."key" FROM place_search_keys k JOIN places p ON p.id = k.place_id WHERE p.name = ? AND k.kind = \'name\' ORDER BY 1');
    $st->execute([$name]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
};
t_eq(['arraial do cabo', 'cabo'], $keysOf('Arraial do Cabo'), 'chaves de Arraial do Cabo no banco');
t_eq(['forte', 'praia do forte'], $keysOf('Praia do Forte'), 'chaves de Praia do Forte');
t_eq(['geriba', 'praia de geriba'], $keysOf('Praia de Geribá'), 'chaves de Geribá');
t_eq(0, (int) $pdo->query("SELECT COUNT(*) FROM place_search_keys WHERE \"key\" LIKE 'do %' OR \"key\" LIKE 'de %' OR \"key\" LIKE 'da %' OR \"key\" IN ('de', 'da', 'do', 'das', 'dos', 'e')")->fetchColumn(), 'stopword não começa chave');
$parent = (string) $pdo->query("SELECT rtrim(c.uf) || ' ' || c.name FROM places p JOIN places c ON c.id = p.parent_id WHERE p.name = 'Praia de Geribá'")->fetchColumn();
t_eq('RJ Armação dos Búzios', $parent, 'Geribá é POI de Armação dos Búzios');
$acre = (string) $pdo->query("SELECT rtrim(c.uf) || ' ' || c.name FROM places p JOIN places c ON c.id = p.parent_id WHERE p.type = 'neighborhood' AND p.name = 'Centro' AND rtrim(p.uf) = 'AC' AND c.name_fold = 'acrelandia'")->fetchColumn();
t_eq('AC Acrelândia', $acre, 'Centro/AC resolve o pai por uf + name_fold (Acrelândia)');
CityRepository::ensure($pdo);
t_eq(61543, (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'neighborhood'")->fetchColumn(), 'ensure de novo não recarrega os bairros');
t_eq(3, (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'poi'")->fetchColumn(), 'ensure de novo mantém os 3 POIs');
$pdo->exec("DELETE FROM places WHERE id = (SELECT p.id FROM places p JOIN places c ON c.id = p.parent_id WHERE p.type = 'neighborhood' AND p.name = 'Terra Indígena Mamoadate' AND c.name_fold = 'assis brasil' AND rtrim(c.uf) = 'AC')");
t_eq(61542, (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'neighborhood'")->fetchColumn(), 'um bairro removido');
CityRepository::ensure($pdo);
t_eq(61543, (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'neighborhood'")->fetchColumn(), 'cidades já semeadas: ensure completa o bairro que faltava');
t_eq(1, (int) $pdo->query("SELECT COUNT(*) FROM places p JOIN places c ON c.id = p.parent_id WHERE p.type = 'neighborhood' AND p.name = 'Terra Indígena Mamoadate' AND c.name_fold = 'assis brasil' AND rtrim(c.uf) = 'AC'")->fetchColumn(), 'bairro removido voltou, sem duplicar');
t_eq(count($cityUk), (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'city'")->fetchColumn(), 'completar bairros não recria as cidades');
t_eq(3, (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'poi'")->fetchColumn(), 'completar bairros mantém os 3 POIs');

echo "CityRepository\n";
$floripa = CityRepository::match('Florianópolis, SC');
t_ok(is_array($floripa) && (int) ($floripa['id'] ?? 0) > 0, 'match Florianópolis, SC');
t_eq('Florianópolis', (string) ($floripa['name'] ?? ''), 'match devolve o nome');
t_eq('SC', (string) ($floripa['uf'] ?? ''), 'match devolve a UF');
t_eq(null, CityRepository::match('Xyzabc'), 'match sem hit');
$sug = CityRepository::suggest('florianop');
t_ok($sug !== [] && in_array('Florianópolis', array_column($sug, 'name'), true), 'suggest "florianop" inclui Florianópolis');
t_eq([], CityRepository::suggest('fo'), 'suggest com menos de 3 letras continua vazio');

echo "imóvel\n";
$owner = t_seed_owner();
$house = HouseRepository::find((int) $owner['house_id']);
t_eq('Florianópolis, SC', (string) ($house['city'] ?? ''), 'find devolve a cidade em texto');
t_eq((int) $floripa['id'], (int) ($house['city_place_id'] ?? 0), 'city_place_id aponta para a place');
t_eq(null, $house['neighborhood_place_id'], 'neighborhood_place_id nulo');
t_eq(1, count(SearchSql::batch((int) $floripa['id'], null, 0, null, null, null, null, 60)), 'builder acha a casa pelo city_place_id');
$byId = HouseRepository::insert([
    'user_id' => $owner['user_id'], 'title' => 'Casa Place', 'description' => 'Descrição de teste do imóvel.',
    'summary' => 'Resumo', 'city_place_id' => (int) $floripa['id'], 'address' => 'Rua B, 2', 'cep' => '88000000',
    'street' => 'Rua B', 'address_number' => '2', 'address_complement' => '', 'lat' => -27.59, 'lng' => -48.54,
    'bedrooms' => 1, 'beds' => 1, 'max_guests' => 2, 'nightly_price' => 200, 'checkin_time' => '15:00',
    'checkout_time' => '11:00', 'min_nights' => 1, 'pool' => 0, 'bbq' => 0, 'garage' => 0,
]);
t_eq('Florianópolis, SC', HouseRepository::find($byId)['city'] ?? null, 'insert por city_place_id devolve o texto');
$found = SearchService::search(SearchQuery::fromRequest([
    'localidade' => 'Florianópolis, SC',
]), null);
t_eq(2, count($found['items']), 'busca por cidade resolvida');

t_done();
