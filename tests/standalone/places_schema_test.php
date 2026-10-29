<?php
// ADR 001 first slice (SCHEMA 21): places + place_search_keys replace cities, properties.city becomes
// city_place_id, and CityRepository resolve/suggest still resolve a city name.
// SCHEMA 22: bairros OpenCEP (database/places_neighborhoods.json.bz2) e places_city_uf_uk.
// SCHEMA 23: idx_prop_latlng_active (lat, lng) WHERE status = 'active' replaces unused gist idx_prop_geo_active.
// SCHEMA 24: idx_prop_nb_active; idx_properties_status removed.
// SCHEMA 25: POIs OSM (category/osm_ref/wikidata), PK INCLUDE (popularity, kind), city popularity.
// SCHEMA 27: properties.rating_sum and rating_count.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/CityRepository.php';
require_once CL_ROOT . '/src/HouseRepository.php';
require_once CL_ROOT . '/src/SearchService.php';
require_once CL_ROOT . '/src/Amenities.php';
require_once CL_ROOT . '/src/PhotoRepository.php';
require_once CL_ROOT . '/src/helpers.php';

t_eq(28, Db::SCHEMA_VERSION, 'SCHEMA_VERSION = 28');
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
t_eq(false, $pdo->query("SELECT 1 FROM pg_indexes WHERE indexname = 'idx_prop_geo_active'")->fetchColumn(), 'sem idx_prop_geo_active');
t_eq(false, $pdo->query("SELECT 1 FROM pg_indexes WHERE indexname = 'idx_properties_status'")->fetchColumn(), 'sem idx_properties_status');
$defs = [];
foreach ($pdo->query("SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public' AND indexname IN ('idx_places_name_fold', 'idx_prop_place_active', 'idx_prop_latlng_active', 'idx_prop_nb_active', 'place_search_keys_pkey', 'places_child_uk', 'places_city_uf_uk')") as $row) {
    $defs[$row['indexname']] = $row['indexdef'];
}
t_ok(isset($defs['idx_places_name_fold']) && str_contains($defs['idx_places_name_fold'], 'name_fold'), 'idx_places_name_fold');
t_ok(isset($defs['idx_prop_place_active']) && str_contains($defs['idx_prop_place_active'], 'INCLUDE (max_guests)') && str_contains($defs['idx_prop_place_active'], "status = 'active'"), 'idx_prop_place_active parcial com INCLUDE');
t_ok(isset($defs['idx_prop_latlng_active']) && str_contains($defs['idx_prop_latlng_active'], 'btree') && str_contains($defs['idx_prop_latlng_active'], '(lat, lng)') && str_contains($defs['idx_prop_latlng_active'], "status = 'active'"), 'idx_prop_latlng_active btree (lat, lng) parcial');
t_eq(
    "CREATE INDEX idx_prop_nb_active ON public.properties USING btree (neighborhood_place_id, id) INCLUDE (max_guests) WHERE ((status = 'active'::text) AND (neighborhood_place_id IS NOT NULL))",
    (string) $pdo->query("SELECT pg_get_indexdef('idx_prop_nb_active'::regclass)")->fetchColumn(),
    'idx_prop_nb_active'
);
t_ok(isset($defs['place_search_keys_pkey']) && str_contains($defs['place_search_keys_pkey'], 'INCLUDE (popularity, kind)'), 'PK de place_search_keys com INCLUDE (popularity, kind)');
t_ok(isset($defs['places_child_uk']), 'places_child_uk');
t_ok(isset($defs['places_city_uf_uk']) && str_contains($defs['places_city_uf_uk'], 'UNIQUE') && str_contains($defs['places_city_uf_uk'], 'uf') && str_contains($defs['places_city_uf_uk'], 'name_fold') && str_contains($defs['places_city_uf_uk'], "type = 'city'"), 'places_city_uf_uk');
$coll = static fn (string $table, string $col): string => (string) $pdo->query("SELECT c.collname FROM pg_attribute a JOIN pg_class r ON r.oid = a.attrelid JOIN pg_collation c ON c.oid = a.attcollation WHERE r.relname = '$table' AND a.attname = '$col'")->fetchColumn();
t_eq('C', $coll('places', 'name_fold'), 'places.name_fold COLLATE C');
t_eq('C', $coll('place_search_keys', 'key'), 'place_search_keys.key COLLATE C');
t_eq('28', (string) $pdo->query("SELECT value FROM schema_meta WHERE skey = 'schema_version'")->fetchColumn(), 'schema_meta = 28');
$rating = [];
foreach ($pdo->query("SELECT a.attname, a.attnotnull, pg_get_expr(d.adbin, d.adrelid) AS def FROM pg_attribute a LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum WHERE a.attrelid = 'properties'::regclass AND a.attname IN ('rating_sum', 'rating_count') AND NOT a.attisdropped") as $row) {
    $rating[$row['attname']] = $row;
}
t_eq('0', $rating['rating_sum']['def'] ?? null, 'rating_sum defaults to 0');
t_eq('0', $rating['rating_count']['def'] ?? null, 'rating_count defaults to 0');
t_eq(true, (bool) $rating['rating_sum']['attnotnull'], 'rating_sum is NOT NULL');
t_eq(true, (bool) $rating['rating_count']['attnotnull'], 'rating_count is NOT NULL');
$checks = $pdo->query("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = 'properties'::regclass AND contype = 'c'")->fetchAll(PDO::FETCH_COLUMN);
t_ok(in_array('CHECK ((rating_sum >= 0))', $checks, true), 'rating_sum >= 0');
t_ok(in_array('CHECK ((rating_count >= 0))', $checks, true), 'rating_count >= 0');
$ls = [];
foreach ($pdo->query("SELECT indexname, pg_get_indexdef(c.oid) AS def FROM pg_indexes i JOIN pg_class c ON c.relname = i.indexname WHERE i.schemaname = 'public' AND i.indexname IN ('idx_ls_place_rank', 'idx_ls_place_rating', 'idx_ls_geo')") as $row) {
    $ls[$row['indexname']] = $row['def'];
}
t_ok(isset($ls['idx_ls_place_rank']) && str_contains($ls['idx_ls_place_rank'], '(city_place_id, rank_base DESC, id DESC)') && str_contains($ls['idx_ls_place_rank'], 'INCLUDE (max_guests)') && str_contains($ls['idx_ls_place_rank'], "status = 'active'"), 'idx_ls_place_rank');
t_ok(isset($ls['idx_ls_place_rating']) && str_contains($ls['idx_ls_place_rating'], '(city_place_id, rating_rank DESC, id DESC)') && str_contains($ls['idx_ls_place_rating'], 'INCLUDE (max_guests)') && str_contains($ls['idx_ls_place_rating'], "status = 'active'"), 'idx_ls_place_rating');
t_ok(isset($ls['idx_ls_geo']) && str_contains($ls['idx_ls_geo'], 'USING gist') && str_contains($ls['idx_ls_geo'], 'point(lng, lat)') && str_contains($ls['idx_ls_geo'], 'INCLUDE (id, lat, lng, rank_base, rating_rank, max_guests)') && str_contains($ls['idx_ls_geo'], "status = 'active'"), 'idx_ls_geo');
$opts = (string) $pdo->query("SELECT reloptions FROM pg_class WHERE relname = 'listing_search'")->fetchColumn();
t_ok(str_contains($opts, 'autovacuum_vacuum_scale_factor=0.02') && str_contains($opts, 'autovacuum_vacuum_threshold=1000') && str_contains($opts, 'autovacuum_analyze_scale_factor=0.02'), 'listing_search autovacuum');
$poiCols = $pdo->query("SELECT attname FROM pg_attribute WHERE attrelid = 'places'::regclass AND attname IN ('category', 'osm_ref', 'wikidata') AND NOT attisdropped")->fetchAll(PDO::FETCH_COLUMN);
sort($poiCols);
t_eq(['category', 'osm_ref', 'wikidata'], $poiCols, 'places tem category, osm_ref e wikidata');

echo "seed\n";
$cityUk = [];
foreach ($cities as $city) {
    $cityUk[$city['uf'] . "\0" . fold((string) $city['name'])] = true;
}
t_eq(count($cityUk), (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'city'")->fetchColumn(), 'uma place city por (uf, name_fold)');
t_eq(count($cities), count($cityUk), 'cities.json não repete (uf, name_fold)');
t_eq(1, (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'city' AND rtrim(uf) = 'RJ' AND name_fold = 'armacao dos buzios'")->fetchColumn(), 'um só Armação dos Búzios/RJ');
$buzios = CityRepository::resolve('Armação dos Búzios, RJ')['place'];
t_eq('Armação dos Búzios', (string) ($buzios['name'] ?? ''), 'resolve do nome oficial de Armação dos Búzios');
foreach (['Búzios', 'buzios', 'Búzios, RJ', 'BUZIOS,rj'] as $alias) {
    t_eq((int) $buzios['id'], (int) (CityRepository::resolve($alias)['place']['id'] ?? 0), "alias \"$alias\" resolve o mesmo place id de Armação dos Búzios");
}
t_eq('Armação dos Búzios', (string) CityRepository::resolve('Búzios')['place']['label'], 'rótulo exibido segue o oficial');
t_eq(null, CityRepository::resolve('Búzios, SP')['place'], 'alias só vale para RJ');
t_eq('alias', (string) $pdo->query("SELECT kind FROM place_search_keys WHERE \"key\" = 'buzios' AND place_id = " . (int) $buzios['id'])->fetchColumn(), 'chave buzios da cidade oficial é alias');
$beaches = $pdo->prepare("SELECT name FROM places WHERE type = 'poi' AND parent_id = ? AND name IN ('Praia de Geribá', 'Praia da Ferradura') ORDER BY name");
$beaches->execute([(int) $buzios['id']]);
t_eq(['Praia da Ferradura', 'Praia de Geribá'], $beaches->fetchAll(PDO::FETCH_COLUMN), 'Geribá e Ferradura ficam sob Armação dos Búzios');
t_eq(8030, (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'poi'")->fetchColumn(), '8030 POIs OSM');
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
t_eq(8030, (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'poi'")->fetchColumn(), 'ensure de novo mantém os 8030 POIs');
$pdo->exec("DELETE FROM places WHERE id = (SELECT p.id FROM places p JOIN places c ON c.id = p.parent_id WHERE p.type = 'neighborhood' AND p.name = 'Terra Indígena Mamoadate' AND c.name_fold = 'assis brasil' AND rtrim(c.uf) = 'AC')");
t_eq(61542, (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'neighborhood'")->fetchColumn(), 'um bairro removido');
CityRepository::ensure($pdo);
t_eq(61543, (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'neighborhood'")->fetchColumn(), 'cidades já semeadas: ensure completa o bairro que faltava');
t_eq(1, (int) $pdo->query("SELECT COUNT(*) FROM places p JOIN places c ON c.id = p.parent_id WHERE p.type = 'neighborhood' AND p.name = 'Terra Indígena Mamoadate' AND c.name_fold = 'assis brasil' AND rtrim(c.uf) = 'AC'")->fetchColumn(), 'bairro removido voltou, sem duplicar');
t_eq(count($cityUk), (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'city'")->fetchColumn(), 'completar bairros não recria as cidades');
t_eq(8030, (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'poi'")->fetchColumn(), 'completar bairros mantém os 8030 POIs');

echo "CityRepository\n";
$floripa = CityRepository::resolve('Florianópolis, SC')['place'];
t_ok(is_array($floripa) && (int) ($floripa['id'] ?? 0) > 0, 'resolve Florianópolis, SC');
t_eq('Florianópolis', (string) ($floripa['name'] ?? ''), 'resolve devolve o nome');
t_eq('SC', (string) ($floripa['uf'] ?? ''), 'resolve devolve a UF');
t_eq(null, CityRepository::resolve('Xyzabc')['place'], 'resolve sem hit');
$sug = CityRepository::suggest('florianop');
t_ok($sug !== [] && in_array('Florianópolis', array_column($sug, 'name'), true), 'suggest "florianop" inclui Florianópolis');
t_eq([], CityRepository::suggest('f'), 'suggest com menos de 2 letras continua vazio');

echo "imóvel\n";
$owner = t_seed_owner();
$house = HouseRepository::find((int) $owner['house_id']);
t_eq('Florianópolis, SC', (string) ($house['city'] ?? ''), 'find devolve a cidade em texto');
t_eq((int) $floripa['id'], (int) ($house['city_place_id'] ?? 0), 'city_place_id aponta para a place');
t_eq(null, $house['neighborhood_place_id'], 'neighborhood_place_id nulo');
t_eq(1, count(SearchSql::batch($floripa, null, 0, null, null, null, null, 60, 0.0)), 'builder acha a casa pelo city_place_id');
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
