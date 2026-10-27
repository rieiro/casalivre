<?php

declare(strict_types=1);

// SCHEMA 25: OSM POIs (database/places_pois.json.bz2, ODbL) and exact-alias-first suggest.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/CityRepository.php';

t_temp_db();
$pdo = db();

$poiCount = (new ReflectionClass(CityRepository::class))->getReflectionConstant('POI_COUNT')->getValue();
t_eq(8030, $poiCount, 'POI_COUNT');
t_eq(25, Db::SCHEMA_VERSION, 'SCHEMA_VERSION = 25');
t_eq('25', (string) $pdo->query("SELECT value FROM schema_meta WHERE skey = 'schema_version'")->fetchColumn(), 'schema_meta = 25');

echo "counts\n";
t_eq(5571, (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'city'")->fetchColumn(), 'cidades');
t_eq(61543, (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'neighborhood'")->fetchColumn(), 'bairros');
t_eq($poiCount, (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'poi'")->fetchColumn(), 'POIs');
t_eq(75144, (int) $pdo->query('SELECT COUNT(*) FROM places')->fetchColumn(), 'places no total');
t_eq(0, (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'poi' AND (category IS NULL OR osm_ref IS NULL)")->fetchColumn(), 'todo POI tem category e osm_ref');
t_eq(0, (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type <> 'poi' AND (category IS NOT NULL OR osm_ref IS NOT NULL OR wikidata IS NOT NULL)")->fetchColumn(), 'cidade e bairro sem metadados OSM');
t_eq(5, (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'poi' AND popularity = 100")->fetchColumn(), '5 POIs com popularidade 100');
$categories = [
    'airport' => 326, 'aquarium' => 46, 'bay' => 172, 'beach' => 2144, 'fort' => 31, 'lagoon' => 172, 'lake' => 175,
    'monument' => 102, 'national_park' => 74, 'nature_reserve' => 524, 'park' => 182, 'peak' => 549,
    'protected_area' => 335, 'theme_park' => 258, 'viewpoint' => 20, 'waterfall' => 2814, 'zoo' => 106,
];
$got = [];
foreach ($pdo->query("SELECT category, COUNT(*) AS n FROM places WHERE type = 'poi' GROUP BY category") as $row) {
    $got[(string) $row['category']] = (int) $row['n'];
}
ksort($got);
t_eq($categories, $got, 'contagem por categoria');
t_eq(166681, (int) $pdo->query("SELECT COUNT(*) FROM place_search_keys WHERE kind = 'name'")->fetchColumn(), 'chaves name');
t_eq(774, (int) $pdo->query("SELECT COUNT(*) FROM place_search_keys WHERE kind = 'alias'")->fetchColumn(), 'chaves alias');
t_eq(167455, (int) $pdo->query('SELECT COUNT(*) FROM place_search_keys')->fetchColumn(), 'chaves no total');
$pouso = $pdo->query("SELECT k.kind FROM place_search_keys k JOIN places p ON p.id = k.place_id JOIN places c ON c.id = p.parent_id WHERE p.name = 'Praia do Pouso' AND c.name_fold = 'angra dos reis' AND rtrim(c.uf) = 'RJ' AND k.\"key\" = 'pouso'");
t_eq('name', (string) $pouso->fetchColumn(), 'alias Pouso não troca a chave name de Praia do Pouso');
t_eq(0, (int) $pdo->query("SELECT COUNT(*) FROM place_search_keys k JOIN places p ON p.id = k.place_id WHERE p.category = 'airport' AND k.\"key\" LIKE '(%'")->fetchColumn(), 'aeroporto não tem chave começando com (');

echo "popularidade das cidades\n";
$pop = static function (string $name, string $uf) use ($pdo): int {
    $stmt = $pdo->prepare("SELECT popularity FROM places WHERE type = 'city' AND name_fold = ? AND uf = ?");
    $stmt->execute([fold($name), $uf]);
    return (int) $stmt->fetchColumn();
};
t_eq(80, $pop('Rio de Janeiro', 'RJ'), 'Rio de Janeiro 80');
t_eq(80, $pop('São Paulo', 'SP'), 'São Paulo 80');
t_eq(80, $pop('Arraial do Cabo', 'RJ'), 'Arraial do Cabo 80');
t_eq(80, $pop('Armação dos Búzios', 'RJ'), 'Armação dos Búzios 80');
t_eq(81, $pop('Cabo Frio', 'RJ'), 'Cabo Frio 81');
t_eq(70, $pop('Niterói', 'RJ'), 'Niterói 70');
t_eq(5541, (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'city' AND popularity = 70")->fetchColumn(), 'demais cidades 70');
t_eq(29, (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'city' AND popularity = 80")->fetchColumn(), '29 cidades a 80');
t_eq(1, (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'city' AND popularity = 81")->fetchColumn(), 'só Cabo Frio a 81');
t_eq(0, (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'neighborhood' AND popularity <> 0")->fetchColumn(), 'bairros com popularidade 0');

echo "suggest\n";
$parentOf = static function (int $id) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT c.name, rtrim(c.uf) AS uf FROM places p JOIN places c ON c.id = p.parent_id WHERE p.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : ['name' => '', 'uf' => ''];
};
$categoryOf = static function (int $id) use ($pdo): string {
    $stmt = $pdo->prepare('SELECT category FROM places WHERE id = ?');
    $stmt->execute([$id]);
    return (string) $stmt->fetchColumn();
};
$gru = CityRepository::suggest('gru');
t_eq('Aeroporto Internacional Governador André Franco Montoro (GRU)', $gru[0]['name'] ?? null, 'gru é o aeroporto');
t_eq('airport', $categoryOf((int) $gru[0]['id']), 'gru é category airport');
t_eq('SP', $gru[0]['uf'] ?? null, 'gru é SP');
t_eq('alias', (string) $pdo->query('SELECT kind FROM place_search_keys WHERE "key" = \'gru\' AND place_id = ' . (int) $gru[0]['id'])->fetchColumn(), 'gru é alias exato');
$rio = array_column(CityRepository::suggest('rio'), 'name');
t_ok(in_array('Rio de Janeiro', array_slice($rio, 0, 2), true), 'rio traz Rio de Janeiro no top 2');
$sao = array_column(CityRepository::suggest('sao'), 'name');
t_ok(in_array('São Paulo', array_slice($sao, 0, 2), true), 'sao traz São Paulo no top 2');
$forte = CityRepository::suggest('forte');
t_eq('Praia do Forte', $forte[0]['name'] ?? null, 'forte é Praia do Forte');
$forteParent = $parentOf((int) $forte[0]['id']);
t_eq('Mata de São João', $forteParent['name'], 'Praia do Forte fica em Mata de São João');
t_eq('BA', $forteParent['uf'], 'Praia do Forte fica na Bahia');
$buzios = CityRepository::suggest('buzios');
t_eq('Armação dos Búzios', $buzios[0]['name'] ?? null, 'buzios é Armação dos Búzios');
t_eq('city', $buzios[0]['type'] ?? null, 'buzios é a cidade');
$cristo = CityRepository::suggest('cristo');
t_eq('Cristo Redentor', $cristo[0]['name'] ?? null, 'cristo é Cristo Redentor');
t_eq('RJ', $cristo[0]['uf'] ?? null, 'Cristo Redentor é RJ');
t_eq('Rio de Janeiro', $parentOf((int) $cristo[0]['id'])['name'], 'Cristo Redentor fica no Rio de Janeiro');
$cabo = CityRepository::suggest('cabo');
t_eq('Cabo Frio', $cabo[0]['name'] ?? null, 'cabo é Cabo Frio');
t_eq('Arraial do Cabo', $cabo[1]['name'] ?? null, 'cabo depois é Arraial do Cabo');

echo "ensure\n";
$counts = static fn (): array => [
    (int) $pdo->query('SELECT COUNT(*) FROM places')->fetchColumn(),
    (int) $pdo->query('SELECT COUNT(*) FROM place_search_keys')->fetchColumn(),
    (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'poi'")->fetchColumn(),
];
$before = $counts();
CityRepository::ensure($pdo);
t_eq($before, $counts(), 'ensure de novo não insere');

echo "plano\n";
$pdo->exec('SET random_page_cost = 1.1');
// A plain VACUUM after the seed still reports Heap Fetches 1 on the wide 'sa' prefix. VACUUM FULL clears it.
$pdo->exec('VACUUM FULL place_search_keys');
$pdo->exec('VACUUM place_search_keys');
$prefix = (new ReflectionClass(CityRepository::class))->getReflectionConstant('PREFIX_SQL')->getValue();
$indexOnly = static function (array $node) use (&$indexOnly): array {
    $hits = [];
    if (($node['Node Type'] ?? '') === 'Index Only Scan' && ($node['Index Name'] ?? '') === 'place_search_keys_pkey') {
        $hits[] = $node;
    }
    foreach ($node['Plans'] ?? [] as $child) {
        $hits = array_merge($hits, $indexOnly($child));
    }
    return $hits;
};
$explain = static function (string $text, string $uf = '') use ($pdo, $prefix, $indexOnly): void {
    $join = $uf === '' ? '' : 'JOIN places p ON p.id = k.place_id AND p.uf = ?';
    $like = strtr($text, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
    $params = $uf === '' ? [$text, $like] : [$text, $uf, $like];
    $stmt = $pdo->prepare('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) ' . sprintf($prefix, $join));
    $stmt->execute($params);
    $plan = json_decode((string) $stmt->fetchColumn(), true);
    $nodes = $indexOnly($plan[0]['Plan'] ?? []);
    $label = $uf === '' ? $text : "$text, $uf";
    t_eq(1, count($nodes), "$label usa Index Only Scan em place_search_keys_pkey");
    t_eq(0, (int) ($nodes[0]['Heap Fetches'] ?? -1), "$label Heap Fetches 0");
};
foreach (['sa', 'sao', 'gru', 'forte'] as $text) {
    $explain($text);
}
$explain('centro', 'RJ');

t_done();
