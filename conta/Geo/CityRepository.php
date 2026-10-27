<?php

declare(strict_types=1);

if (!class_exists('JsonCache', false)) {
    require_once __DIR__ . '/JsonCache.php';
}
require_once dirname(__DIR__, 2) . '/src/helpers/geo.php';

final class CityRepository
{
    /**
     * Bairros DISTINCT do OpenCEP opencep-2.0.1 (database/cep.csv.bz2), no artefato
     * database/places_neighborhoods.json.bz2. Com as cidades já semeadas, o ensure
     * não recarrega os bairros quando a tabela já tem esta quantidade.
     */
    private const NEIGHBORHOOD_COUNT = 61543;

    /** OSM POIs in database/places_pois.json.bz2. ensure skips the file once the table has this many. */
    private const POI_COUNT = 8030;

    /** City popularity by "UF|name_fold". Every other city is 70. Neighborhoods stay 0. Produto 2026-10-25. */
    private const CITY_POPULARITY_DEFAULT = 70;

    private const CITY_POPULARITY = [
        'AC|rio branco' => 80, 'AL|maceio' => 80, 'AP|macapa' => 80, 'AM|manaus' => 80, 'BA|salvador' => 80,
        'CE|fortaleza' => 80, 'DF|brasilia' => 80, 'ES|vitoria' => 80, 'GO|goiania' => 80, 'MA|sao luis' => 80,
        'MT|cuiaba' => 80, 'MS|campo grande' => 80, 'MG|belo horizonte' => 80, 'PA|belem' => 80, 'PB|joao pessoa' => 80,
        'PR|curitiba' => 80, 'PE|recife' => 80, 'PI|teresina' => 80, 'RJ|rio de janeiro' => 80, 'RN|natal' => 80,
        'RS|porto alegre' => 80, 'RO|porto velho' => 80, 'RR|boa vista' => 80, 'SC|florianopolis' => 80, 'SP|sao paulo' => 80,
        'SE|aracaju' => 80, 'TO|palmas' => 80,
        'RJ|cabo frio' => 81, 'RJ|armacao dos buzios' => 80, 'RJ|arraial do cabo' => 80, // Cabo Frio 81: 'cabo' ranks it first
    ];

    public static function ensure(PDO $pdo): void
    {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'city'")->fetchColumn();
        if ($count >= 5000) {
            self::ensureChildren($pdo, 'neighborhood', self::NEIGHBORHOOD_COUNT, self::seedNeighborhoods(...));
            self::ensureChildren($pdo, 'poi', self::POI_COUNT, self::seedPois(...));
            return;
        }
        $rows = self::readJson('database/cities.json');
        if (!is_array($rows)) {
            return;
        }
        // Pode rodar dentro da transação do migrate() (DEF-008): Db::transactional usa SAVEPOINT quando já há
        // uma transação aberta.
        Db::transactional($pdo, static function (PDO $pdo) use ($rows): void {
            $pdo->exec('DELETE FROM places WHERE parent_id IS NOT NULL');
            $pdo->exec('DELETE FROM places');
            foreach ($rows as $row) {
                $uf = (string) $row['uf'];
                $name = (string) $row['name'];
                self::insertPlace(
                    $pdo,
                    'city',
                    null,
                    $name,
                    (string) $row['label'],
                    $uf,
                    $row['lat'],
                    $row['lng'],
                    self::CITY_POPULARITY[$uf . '|' . fold($name)] ?? self::CITY_POPULARITY_DEFAULT
                );
            }
            self::seedNeighborhoods($pdo);
            self::seedPois($pdo);
            self::seedAliases($pdo);
        });
        // O reseed troca os ids. O cache cities.json de outro banco não pode sobreviver.
        JsonCache::delete('cities.json');
    }

    private static function readJson(string $relativePath): mixed
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        return json_decode((string) file_get_contents($path), true);
    }

    /** @return array{id:int,popularity:int}|null */
    private static function cityRow(PDO $pdo, string $nameFold, string $uf): ?array
    {
        static $bound = null;
        static $stmt = null;
        if ($bound !== $pdo) {
            $bound = $pdo;
            $stmt = $pdo->prepare(self::CITY_SQL);
        }
        $stmt->execute([$nameFold, $uf]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        return ['id' => (int) $row['id'], 'popularity' => (int) $row['popularity']];
    }

    /** Cities already seeded: load this child type only while it is still short of the artifact. */
    private static function ensureChildren(PDO $pdo, string $type, int $expected, callable $seed): void
    {
        $count = $pdo->prepare('SELECT COUNT(*) FROM places WHERE type = ?');
        $count->execute([$type]);
        if ((int) $count->fetchColumn() >= $expected) {
            return;
        }
        Db::transactional($pdo, $seed);
    }

    /**
     * Inserts one place, its name keys, and any alias keys. Airports key the name without the trailing
     * IATA code; the stored name keeps it. Statements stay prepared for the PDO that first called this.
     *
     * @param int|float|string $lat
     * @param int|float|string $lng
     * @param list<string> $aliases
     */
    private static function insertPlace(PDO $pdo, string $type, ?int $parentId, string $name, string $label, string $uf, int|float|string $lat, int|float|string $lng, int $popularity, ?string $category = null, ?string $osmRef = null, ?string $wikidata = null, array $aliases = []): void
    {
        static $bound = null;
        static $insert = null;
        static $keyIns = null;
        if ($bound !== $pdo) {
            $bound = $pdo;
            $insert = $pdo->prepare('INSERT INTO places (type, parent_id, name, name_fold, label, uf, lat, lng, popularity, category, osm_ref, wikidata) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id');
            $keyIns = $pdo->prepare('INSERT INTO place_search_keys (place_id, "key", kind, popularity) VALUES (?, ?, ?, ?)');
        }
        $insert->execute([$type, $parentId, $name, fold($name), $label, $uf, $lat, $lng, $popularity, $category, $osmRef, $wikidata]);
        $id = (int) $insert->fetchColumn();
        $kinds = array_fill_keys(self::searchKeys(self::keyName($name, $category)), 'name');
        foreach ($aliases as $alias) {
            $kinds[self::foldKey($alias)] ??= 'alias';
        }
        foreach ($kinds as $key => $kind) {
            $keyIns->execute([$id, $key, $kind, $popularity]);
        }
    }

    private static function foldKey(string $value): string
    {
        return preg_replace('/\s+/', ' ', fold($value)) ?? '';
    }

    /** Display name keeps "Name (GRU)". Search keys use the name with that suffix removed. */
    private static function keyName(string $name, ?string $category): string
    {
        return $category === 'airport' ? (preg_replace('/\s*\([A-Z]{3}\)$/', '', $name) ?? $name) : $name;
    }

    /**
     * Word-suffix keys of a folded name (ADR 001 §11.2). Stopwords never start a key; they stay inside one.
     *
     * @return list<string>
     */
    public static function searchKeys(string $name): array
    {
        $fold = self::foldKey($name);
        if ($fold === '') {
            return [];
        }
        $words = explode(' ', $fold);
        $stop = ['de' => true, 'da' => true, 'do' => true, 'das' => true, 'dos' => true, 'e' => true];
        $keys = [];
        $count = count($words);
        for ($i = 0; $i < $count; $i++) {
            if ($words[$i] === '' || isset($stop[$words[$i]])) {
                continue;
            }
            $keys[] = implode(' ', array_slice($words, $i));
        }
        return array_values(array_unique($keys));
    }

    /**
     * Bairros do artefato OpenCEP. parent_id vem de
     * SELECT id FROM places WHERE type='city' AND uf=? AND name_fold=?
     * (parent_uf + parent_name_fold; nunca um place_id gravado no arquivo).
     * lat/lng são o ponto da cidade-pai. Sem cidade, ou em conflito com places_child_uk, a linha é pulada.
     */
    private static function seedNeighborhoods(PDO $pdo): void
    {
        self::seedUnderCity($pdo, 'neighborhood', self::bz2JsonRows('places_neighborhoods.json.bz2', 'bairros'), static function (int $parentId, array $row) use ($pdo): void {
            $name = (string) $row['name'];
            self::insertPlace(
                $pdo,
                'neighborhood',
                $parentId,
                $name,
                (string) ($row['label'] ?? $name),
                (string) ($row['uf'] ?? $row['parent_uf']),
                $row['lat'] ?? 0,
                $row['lng'] ?? 0,
                (int) ($row['popularity'] ?? 0)
            );
        });
    }

    /** POIs OSM (database/places_pois.json.bz2). Same parent lookup and places_child_uk skip as the neighborhoods. */
    private static function seedPois(PDO $pdo): void
    {
        self::seedUnderCity($pdo, 'poi', self::bz2JsonRows('places_pois.json.bz2', 'POIs'), static function (int $parentId, array $row) use ($pdo): void {
            $name = (string) $row['name'];
            $aliases = $row['aliases'] ?? [];
            if (isset($row['iata'])) {
                $aliases[] = $row['iata'];
            }
            self::insertPlace(
                $pdo,
                'poi',
                $parentId,
                $name,
                (string) $row['label'],
                (string) $row['uf'],
                $row['lat'],
                $row['lng'],
                (int) $row['popularity'],
                (string) $row['category'],
                (string) $row['osm_ref'],
                isset($row['wikidata']) ? (string) $row['wikidata'] : null,
                $aliases
            );
        });
    }

    /**
     * Children of a city (neighborhood or POI). Parent is the city with this uf + name_fold.
     * A row with no city, an empty name, or a (parent, folded name) already stored is skipped.
     *
     * @param list<array<string, mixed>> $rows
     * @param callable(int, array<string, mixed>): void $insert
     */
    private static function seedUnderCity(PDO $pdo, string $type, array $rows, callable $insert): void
    {
        $parents = [];
        $taken = [];
        $existing = $pdo->prepare("SELECT parent_id, name_fold FROM places WHERE type = ? AND parent_id IS NOT NULL");
        $existing->execute([$type]);
        foreach ($existing as $row) {
            $taken[(int) $row['parent_id'] . "\0" . $row['name_fold']] = true;
        }
        foreach ($rows as $row) {
            $parentUf = (string) $row['parent_uf'];
            $parentFold = (string) $row['parent_name_fold'];
            $pkey = $parentUf . "\0" . $parentFold;
            if (!array_key_exists($pkey, $parents)) {
                $city = self::cityRow($pdo, $parentFold, $parentUf);
                $parents[$pkey] = $city === null ? null : $city['id'];
            }
            $parentId = $parents[$pkey];
            if ($parentId === null) {
                continue;
            }
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $uk = $parentId . "\0" . fold($name);
            if (isset($taken[$uk])) {
                continue;
            }
            $taken[$uk] = true;
            $insert($parentId, $row);
        }
    }

    /**
     * Alias rows from database/city_aliases.json. Upsert on the name-suffix key: the same (key, place_id)
     * stays one row and becomes kind alias. Name-suffix keys stay autocomplete-only.
     */
    private static function seedAliases(PDO $pdo): void
    {
        $rows = self::readJson('database/city_aliases.json');
        $upsert = $pdo->prepare('INSERT INTO place_search_keys (place_id, "key", kind, popularity) VALUES (?, ?, \'alias\', ?) ON CONFLICT ("key", place_id) DO UPDATE SET kind = \'alias\'');
        foreach ($rows as $row) {
            $name = (string) $row['name'];
            $uf = (string) $row['uf'];
            $city = self::cityRow($pdo, fold($name), $uf);
            if ($city === null) {
                throw new RuntimeException("Alias sem cidade: $name/$uf");
            }
            $upsert->execute([$city['id'], fold((string) $row['key']), $city['popularity']]);
        }
    }

    /** @return list<array<string, mixed>> */
    private static function bz2JsonRows(string $file, string $what): array
    {
        $path = dirname(__DIR__, 2) . '/database/' . $file;
        if (!is_file($path)) {
            throw new RuntimeException("Arquivo de $what ausente: $path");
        }
        if (!function_exists('bzopen')) {
            throw new RuntimeException(
                'Não foi possível descompactar ' . $path
                . ': o PHP não tem a extensão bz2. Rode com php -d extension=bz2'
                . ' (o php.ini de produção não carrega a extensão), como scripts/load-ceps.php.'
            );
        }
        $bz = bzopen($path, 'r');
        if ($bz === false) {
            throw new RuntimeException("Não foi possível abrir $path com a extensão bz2 do PHP.");
        }
        $json = '';
        while (!feof($bz)) {
            $chunk = bzread($bz, 1048576);
            if ($chunk === false) {
                $detail = function_exists('bzerrstr') ? (string) bzerrstr($bz) : '';
                bzclose($bz);
                throw new RuntimeException('Falha ao descompactar ' . $path . ($detail !== '' ? ': ' . $detail : '.'));
            }
            $json .= $chunk;
        }
        bzclose($bz);
        try {
            $rows = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Arquivo de $what inválido: $path", 0, $e);
        }
        unset($json);
        if (!is_array($rows)) {
            throw new RuntimeException("Arquivo de $what inválido: $path");
        }
        return $rows;
    }

    /** Shared select list for place rows. uf is CHAR(2) and always two letters. */
    private const PLACE_COLUMNS = 'id, name, label, type, uf, lat, lng';

    /**
     * ADR 001 §11.2 autocomplete. The inner scan reads key, place_id, popularity and kind
     * (index-only on the PK, which INCLUDE popularity and kind). An exact alias ranks
     * before popularity. %s is an optional UF join, filled in prefixPlaces().
     * Placeholders stay before ESCAPE: folded query, then the UF bind, then the LIKE.
     */
    private const PREFIX_SQL = 'SELECT ' . self::PLACE_COLUMNS . <<<'SQL'
 FROM (
    SELECT k.place_id, bool_or(k.kind = 'alias' AND k.key = ?) AS exact, MAX(k.popularity) AS pop
    FROM place_search_keys k
    %s
    WHERE k.key LIKE ? ESCAPE '\'
    GROUP BY k.place_id
    ORDER BY exact DESC, pop DESC, k.place_id
    LIMIT 9
) k
JOIN places ON places.id = k.place_id
ORDER BY k.exact DESC, k.pop DESC, id
SQL;

    private const ALIAS_SQL = 'SELECT ' . self::PLACE_COLUMNS . <<<'SQL'
 FROM place_search_keys k
JOIN places ON places.id = k.place_id
WHERE k.key = ? AND k.kind = 'alias' AND (? = '' OR uf = ?)
LIMIT 2
SQL;

    // City, then neighborhood, then poi, then popularity. LIMIT 9 must not hide a neighborhood behind POIs.
    private const NAME_EXACT_SQL = 'SELECT ' . self::PLACE_COLUMNS . <<<'SQL'
 FROM places
WHERE name_fold = ? AND (? = '' OR uf = ?)
ORDER BY (type = 'city') DESC, (type = 'neighborhood') DESC, popularity DESC, id
LIMIT 9
SQL;

    // Known exact id (places.id is INTEGER) plus any other type with this search key.
    // The known id sorts first so LIMIT 9 cannot drop it behind a more popular place.
    private const EXACT_OR_OTHER_SQL = 'SELECT ' . self::PLACE_COLUMNS . <<<'SQL'
 FROM places
WHERE id IN (
    SELECT CAST(? AS integer)
    UNION
    SELECT o.id FROM place_search_keys k JOIN places o ON o.id = k.place_id
    WHERE k.key = ? AND o.type <> ? AND (? = '' OR o.uf = ?)
)
ORDER BY (id = CAST(? AS integer)) DESC, popularity DESC, id
LIMIT 9
SQL;

    private const CITY_SQL = 'SELECT ' . self::PLACE_COLUMNS . ', popularity' . <<<'SQL'
 FROM places
WHERE type = 'city' AND name_fold = ? AND uf = ?
SQL;

    // UF binds come before ESCAPE '\': PDO drops placeholders that follow that quote.
    private const NAME_PREFIX_SQL = 'SELECT ' . self::PLACE_COLUMNS . <<<'SQL'
 FROM places
WHERE (? = '' OR uf = ?) AND name_fold LIKE ? ESCAPE '\'
LIMIT 2
SQL;

    private const BY_ID_SQL = 'SELECT ' . self::PLACE_COLUMNS . <<<'SQL'
 FROM places
WHERE id = ?
SQL;

    public static function suggest(string $query): array
    {
        [$text] = self::splitPlaceQuery($query);
        if (mb_strlen($text) < 2) {
            return [];
        }
        return self::options(self::prefixPlaces($text));
    }

    /** City by folded name and UF. */
    public static function exactCity(string $name, string $uf): ?array
    {
        return self::placeRows(self::CITY_SQL, [fold($name), $uf])[0] ?? null;
    }

    public static function byId(int $id): ?array
    {
        return self::placeRows(self::BY_ID_SQL, [$id])[0] ?? null;
    }

    /** Neighborhood with this folded name under the city, or null. No prefix and no other city. */
    public static function neighborhoodId(int $cityId, string $name): ?int
    {
        $stmt = db()->prepare("SELECT id FROM places WHERE type = 'neighborhood' AND parent_id = ? AND name_fold = ?");
        $stmt->execute([$cityId, fold($name)]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Submitted text. Fetch 9 and show at most 8. 2–8 → did_you_mean. 9 → ambiguous. 0 → not_found.
     * A unique alias resolves. Exactly one city with that exact name resolves, even when
     * neighborhoods share it. Two or more exact names are that list, not the prefix list.
     * One exact non-city also lists other types with the same search key, exact row first.
     * A unique full-name prefix wins before the key list ("Rio de Jan" is one city).
     * suggest() stays on the popularity prefix and is not this path.
     *
     * @return array{place:?array,resolution:?string,options:list<array>}
     */
    public static function resolve(string $query): array
    {
        $none = ['place' => null, 'resolution' => 'not_found', 'options' => []];
        [$text, $uf] = self::splitPlaceQuery($query);
        if ($text === '') {
            return $none;
        }
        $alias = self::placeRows(self::ALIAS_SQL, [$text, $uf, $uf]);
        if (count($alias) === 1) {
            return self::found($alias[0]);
        }
        $exact = self::placeRows(self::NAME_EXACT_SQL, [$text, $uf, $uf]);
        $cities = [];
        foreach ($exact as $row) {
            if ($row['type'] === 'city') {
                $cities[] = $row;
            }
        }
        if (count($cities) === 1) {
            return self::found($cities[0]);
        }
        if (count($exact) === 1) {
            $id = $exact[0]['id'];
            $group = self::placeRows(self::EXACT_OR_OTHER_SQL, [$id, $text, $exact[0]['type'], $uf, $uf, $id]);
            return count($group) === 1 ? self::found($group[0]) : self::choices($group);
        }
        if (count($exact) > 1) {
            return self::choices($exact);
        }
        if (mb_strlen($text) < 2) {
            return $none;
        }
        $prefixed = self::placeRows(self::NAME_PREFIX_SQL, [$uf, $uf, self::likePrefix($text)]);
        if (count($prefixed) === 1) {
            return self::found($prefixed[0]);
        }
        $hits = self::prefixPlaces($text, $uf);
        if (count($hits) === 1) {
            return self::found($hits[0]);
        }
        if ($hits === []) {
            return $none;
        }
        return self::choices($hits);
    }

    /** @param array{id:int,name:string,label:string,type:string,uf:string,lat:float,lng:float} $place */
    private static function found(array $place): array
    {
        return ['place' => $place, 'resolution' => null, 'options' => []];
    }

    /** @param list<array> $hits @return array{place:null,resolution:string,options:list<array>} */
    private static function choices(array $hits): array
    {
        return [
            'place' => null,
            'resolution' => count($hits) >= 9 ? 'ambiguous' : 'did_you_mean',
            'options' => self::options($hits),
        ];
    }

    /** @return array{0:string,1:string} folded name and optional UF */
    private static function splitPlaceQuery(string $query): array
    {
        $fold = self::foldKey($query);
        $fold = preg_replace('/\s*,\s*/', ', ', $fold) ?? $fold;
        if (preg_match('/^(.+), ([a-z]{2})$/', $fold, $parts) === 1) {
            return [$parts[1], strtoupper($parts[2])];
        }
        return [$fold, ''];
    }

    private static function likePrefix(string $text): string
    {
        return strtr($text, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
    }

    /** @return list<array> */
    private static function prefixPlaces(string $text, string $uf = ''): array
    {
        $prefix = self::likePrefix($text);
        $join = $uf === '' ? '' : 'JOIN places p ON p.id = k.place_id AND p.uf = ?';
        return self::placeRows(sprintf(self::PREFIX_SQL, $join), $uf === '' ? [$text, $prefix] : [$text, $uf, $prefix]);
    }

    /** @param list<int|string> $params @return list<array> */
    private static function placeRows(string $sql, array $params): array
    {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'label' => (string) $row['label'],
                'type' => (string) $row['type'],
                'uf' => (string) $row['uf'],
                'lat' => (float) $row['lat'],
                'lng' => (float) $row['lng'],
            ];
        }
        return $rows;
    }

    /** @param list<array> $hits @return list<array> */
    private static function options(array $hits): array
    {
        return array_map(self::option(...), array_slice($hits, 0, 8));
    }

    /** @param array{id:int,name:string,label:string,type:string,uf:string} $place */
    private static function option(array $place): array
    {
        return [
            'id' => $place['id'],
            'text' => place_label($place),
            'name' => $place['name'],
            'label' => $place['label'],
            'type' => $place['type'],
            'uf' => $place['uf'],
        ];
    }

    public static function refresh(): array
    {
        self::ensure(db());
        $cities = [];
        $seenStates = [];
        foreach (db()->query("SELECT id, name, uf, label, lat, lng, name_fold FROM places WHERE type = 'city' ORDER BY name, uf, id") as $row) {
            $row['uf'] = (string) $row['uf'];
            $cities[] = $row;
            $seenStates[$row['uf']] = self::STATES[$row['uf']] ?? $row['uf'];
        }
        $states = [];
        foreach ($seenStates as $uf => $name) {
            $states[] = ['uf' => $uf, 'name' => $name];
        }
        usort($states, static fn (array $a, array $b): int => strcmp($a['uf'], $b['uf']));
        $payload = ['states' => $states, 'cities' => $cities];
        JsonCache::write('cities.json', $payload, false);
        return $payload;
    }

    private const STATES = [
        'AC' => 'Acre',
        'AL' => 'Alagoas',
        'AP' => 'Amapá',
        'AM' => 'Amazonas',
        'BA' => 'Bahia',
        'CE' => 'Ceará',
        'DF' => 'Distrito Federal',
        'ES' => 'Espírito Santo',
        'GO' => 'Goiás',
        'MA' => 'Maranhão',
        'MT' => 'Mato Grosso',
        'MS' => 'Mato Grosso do Sul',
        'MG' => 'Minas Gerais',
        'PA' => 'Pará',
        'PB' => 'Paraíba',
        'PR' => 'Paraná',
        'PE' => 'Pernambuco',
        'PI' => 'Piauí',
        'RJ' => 'Rio de Janeiro',
        'RN' => 'Rio Grande do Norte',
        'RS' => 'Rio Grande do Sul',
        'RO' => 'Rondônia',
        'RR' => 'Roraima',
        'SC' => 'Santa Catarina',
        'SP' => 'São Paulo',
        'SE' => 'Sergipe',
        'TO' => 'Tocantins',
    ];
}

function fold(string $value): string
{
    $value = mb_strtolower(trim($value));
    $map = [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n',
    ];
    return strtr($value, $map);
}
