<?php

declare(strict_types=1);

if (!class_exists('JsonCache', false)) {
    require_once __DIR__ . '/JsonCache.php';
}

final class CityRepository
{
    /**
     * Bairros DISTINCT do OpenCEP opencep-2.0.1 (database/cep.csv.bz2), no artefato
     * database/places_neighborhoods.json.bz2. Com as cidades já semeadas, o ensure
     * não recarrega os bairros quando a tabela já tem esta quantidade.
     */
    private const NEIGHBORHOOD_COUNT = 61543;

    /** Cópia em processo de storage/cache/cities.json. Some depois de recriar as cidades, para um id de outro banco não ficar. */
    private static ?array $citiesCache = null;

    public static function ensure(PDO $pdo): void
    {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'city'")->fetchColumn();
        if ($count >= 5000) {
            self::ensureNeighborhoods($pdo);
            return;
        }
        $path = dirname(__DIR__, 2) . '/database/cities.json';
        $rows = json_decode((string) file_get_contents($path), true);
        if (!is_array($rows)) {
            return;
        }
        // Pode rodar dentro da transação do migrate() (DEF-008): Db::transactional usa SAVEPOINT quando já há
        // uma transação aberta.
        Db::transactional($pdo, static function (PDO $pdo) use ($rows): void {
            $pdo->exec('DELETE FROM places WHERE parent_id IS NOT NULL');
            $pdo->exec('DELETE FROM places');
            foreach ($rows as $row) {
                self::insertPlace($pdo, 'city', null, (string) $row['name'], (string) $row['label'], (string) $row['uf'], $row['lat'], $row['lng'], 0);
            }
            self::seedPilot($pdo);
            self::seedNeighborhoods($pdo);
            self::seedAliases($pdo);
        });
        // O reseed troca os ids. O cache cities.json de outro banco não pode sobreviver.
        self::$citiesCache = null;
        JsonCache::delete('cities.json');
    }

    /** Cidades já semeadas: carrega bairros só se a contagem ainda não chegou no artefato. */
    private static function ensureNeighborhoods(PDO $pdo): void
    {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'neighborhood'")->fetchColumn();
        if ($count >= self::NEIGHBORHOOD_COUNT) {
            return;
        }
        Db::transactional($pdo, static function (PDO $pdo): void {
            self::seedNeighborhoods($pdo);
        });
    }

    /**
     * Inserts one place and its name keys. Statements stay prepared for the PDO that first called this.
     *
     * @param int|float|string $lat
     * @param int|float|string $lng
     */
    private static function insertPlace(PDO $pdo, string $type, ?int $parentId, string $name, string $label, string $uf, int|float|string $lat, int|float|string $lng, int $popularity): void
    {
        static $bound = null;
        static $insert = null;
        static $keyIns = null;
        if ($bound !== $pdo) {
            $bound = $pdo;
            $insert = $pdo->prepare('INSERT INTO places (type, parent_id, name, name_fold, label, uf, lat, lng, popularity) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id');
            $keyIns = $pdo->prepare('INSERT INTO place_search_keys (place_id, "key", kind, popularity) VALUES (?, ?, \'name\', ?)');
        }
        $insert->execute([$type, $parentId, $name, fold($name), $label, $uf, $lat, $lng, $popularity]);
        $id = (int) $insert->fetchColumn();
        foreach (self::searchKeys($name) as $key) {
            $keyIns->execute([$id, $key, $popularity]);
        }
    }

    /**
     * Word-suffix keys of a folded name (ADR 001 §11.2). Stopwords never start a key; they stay inside one.
     *
     * @return list<string>
     */
    public static function searchKeys(string $name): array
    {
        $fold = preg_replace('/\s+/', ' ', fold($name)) ?? '';
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

    /** Beaches named in the place spec (Geribá, Ferradura, Praia do Forte). Parents are cities from cities.json. */
    private static function seedPilot(PDO $pdo): void
    {
        $pilots = [
            ['name' => 'Praia de Geribá', 'parent' => 'Armação dos Búzios', 'uf' => 'RJ', 'lat' => -22.7783, 'lng' => -41.9164],
            ['name' => 'Praia da Ferradura', 'parent' => 'Armação dos Búzios', 'uf' => 'RJ', 'lat' => -22.7712, 'lng' => -41.8848],
            ['name' => 'Praia do Forte', 'parent' => 'Mata de São João', 'uf' => 'BA', 'lat' => -12.5766, 'lng' => -38.0002],
        ];
        $parent = $pdo->prepare("SELECT id FROM places WHERE type = 'city' AND name_fold = ? AND uf = ? ORDER BY id LIMIT 1");
        foreach ($pilots as $poi) {
            $parent->execute([fold($poi['parent']), $poi['uf']]);
            $parentId = $parent->fetchColumn();
            if ($parentId === false) {
                continue;
            }
            self::insertPlace($pdo, 'poi', (int) $parentId, $poi['name'], $poi['name'], $poi['uf'], $poi['lat'], $poi['lng'], 100);
        }
    }

    /**
     * Bairros do artefato OpenCEP. parent_id vem de
     * SELECT id FROM places WHERE type='city' AND uf=? AND name_fold=?
     * (parent_uf + parent_name_fold; nunca um place_id gravado no arquivo).
     * lat/lng são o ponto da cidade-pai. Sem cidade, ou em conflito com places_child_uk, a linha é pulada.
     */
    private static function seedNeighborhoods(PDO $pdo): void
    {
        $rows = self::neighborhoodRows();
        $parent = $pdo->prepare("SELECT id FROM places WHERE type = 'city' AND uf = ? AND name_fold = ?");
        $parents = [];
        $taken = [];
        foreach ($pdo->query("SELECT parent_id, name_fold FROM places WHERE type = 'neighborhood' AND parent_id IS NOT NULL") as $existing) {
            $taken[(int) $existing['parent_id'] . "\0" . $existing['name_fold']] = true;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $parentUf = (string) ($row['parent_uf'] ?? '');
            $parentFold = (string) ($row['parent_name_fold'] ?? '');
            if ($parentUf === '' || $parentFold === '') {
                continue;
            }
            $pkey = $parentUf . "\0" . $parentFold;
            if (!array_key_exists($pkey, $parents)) {
                $parent->execute([$parentUf, $parentFold]);
                $id = $parent->fetchColumn();
                $parents[$pkey] = $id === false ? null : (int) $id;
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
            self::insertPlace(
                $pdo,
                'neighborhood',
                $parentId,
                $name,
                (string) ($row['label'] ?? $name),
                (string) ($row['uf'] ?? $parentUf),
                $row['lat'] ?? 0,
                $row['lng'] ?? 0,
                (int) ($row['popularity'] ?? 0)
            );
        }
    }

    /**
     * Alias rows from database/city_aliases.json. Upsert on the name-suffix key: the same (key, place_id)
     * stays one row and becomes kind alias. Name-suffix keys stay autocomplete-only.
     */
    private static function seedAliases(PDO $pdo): void
    {
        $path = dirname(__DIR__, 2) . '/database/city_aliases.json';
        if (!is_file($path)) {
            throw new RuntimeException('Missing alias file: ' . $path);
        }
        $rows = json_decode((string) file_get_contents($path), true);
        if (!is_array($rows)) {
            throw new RuntimeException('Invalid alias file: ' . $path);
        }
        $place = $pdo->prepare("SELECT id, popularity FROM places WHERE type = 'city' AND name_fold = ? AND rtrim(uf) = ?");
        $upsert = $pdo->prepare('INSERT INTO place_search_keys (place_id, "key", kind, popularity) VALUES (?, ?, \'alias\', ?) ON CONFLICT ("key", place_id) DO UPDATE SET kind = \'alias\'');
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = fold((string) ($row['key'] ?? ''));
            $name = fold((string) ($row['name'] ?? ''));
            $uf = strtoupper(trim((string) ($row['uf'] ?? '')));
            if ($key === '' || $name === '' || strlen($uf) !== 2) {
                continue;
            }
            $place->execute([$name, $uf]);
            $found = $place->fetch();
            if ($found === false) {
                continue;
            }
            $upsert->execute([(int) $found['id'], $key, (int) $found['popularity']]);
        }
    }

    /** @return list<array<string, mixed>> */
    private static function neighborhoodRows(): array
    {
        $path = dirname(__DIR__, 2) . '/database/places_neighborhoods.json.bz2';
        if (!is_file($path)) {
            throw new RuntimeException("Arquivo de bairros ausente: $path");
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
            throw new RuntimeException("Arquivo de bairros inválido: $path", 0, $e);
        }
        unset($json);
        if (!is_array($rows)) {
            throw new RuntimeException("Arquivo de bairros inválido: $path");
        }
        return $rows;
    }

    /**
     * ADR 001 §11.2 autocomplete. Inner scan reads only key, place_id and popularity
     * (index-only on the PK). It does not read kind and does not order aliases first.
     */
    private const PREFIX_SQL = <<<'SQL'
SELECT p.id, p.name, p.label, p.type, rtrim(p.uf) AS uf, p.lat, p.lng
FROM (
    SELECT place_id, MAX(popularity) AS pop
    FROM place_search_keys
    WHERE key LIKE ? ESCAPE '\'
    GROUP BY place_id
    ORDER BY pop DESC, place_id
    LIMIT 9
) k
JOIN places p ON p.id = k.place_id
ORDER BY k.pop DESC, p.id
SQL;

    private const ALIAS_SQL = <<<'SQL'
SELECT p.id, p.name, p.label, p.type, rtrim(p.uf) AS uf, p.lat, p.lng
FROM place_search_keys k
JOIN places p ON p.id = k.place_id
WHERE k.key = ? AND k.kind = 'alias' AND (? = '' OR rtrim(p.uf) = ?)
LIMIT 2
SQL;

    private const NAME_EXACT_SQL = <<<'SQL'
SELECT id, name, label, type, rtrim(uf) AS uf, lat, lng
FROM places
WHERE name_fold = ? AND type IN ('city', 'neighborhood', 'poi') AND (? = '' OR rtrim(uf) = ?)
ORDER BY id
LIMIT 2
SQL;

    // UF binds come before ESCAPE '\': PDO drops placeholders that follow that quote.
    private const NAME_PREFIX_SQL = <<<'SQL'
SELECT id, name, label, type, rtrim(uf) AS uf, lat, lng
FROM places
WHERE (? = '' OR rtrim(uf) = ?) AND type IN ('city', 'neighborhood', 'poi') AND name_fold LIKE ? ESCAPE '\'
ORDER BY id
LIMIT 2
SQL;

    private const BY_ID_SQL = <<<'SQL'
SELECT id, name, label, type, rtrim(uf) AS uf, lat, lng
FROM places
WHERE id = ? AND type IN ('city', 'neighborhood', 'poi')
SQL;

    public static function suggest(string $query, int $limit = 8): array
    {
        [$text] = self::splitPlaceQuery($query);
        if (mb_strlen($text) < 2) {
            return [];
        }
        $limit = max(0, min(8, $limit));
        $out = [];
        foreach (self::prefixPlaces($text) as $row) {
            if (count($out) >= $limit) {
                break;
            }
            $out[] = self::option($row);
        }
        return $out;
    }

    /**
     * One place, or null when the text is empty, unknown, or matches more than one place.
     * Alias spellings resolve here; a name-suffix key alone does not.
     */
    public static function match(string $query): ?array
    {
        if (fold($query) === '') {
            return null;
        }
        return self::resolve($query)['place'];
    }

    public static function byId(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        return self::placeRows(self::BY_ID_SQL, [$id])[0] ?? null;
    }

    /**
     * Submitted text, via the autocomplete query (fetch 9).
     * 1 hit → place. 2–8 → did_you_mean. 9 → ambiguous (show 8). 0 → not_found.
     * A unique full-name prefix wins before that list: word-suffix keys also match
     * neighborhoods that merely contain the city name ("Rio de Jan" is one city).
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
            return ['place' => $alias[0], 'resolution' => null, 'options' => []];
        }
        $exact = self::placeRows(self::NAME_EXACT_SQL, [$text, $uf, $uf]);
        if (count($exact) === 1) {
            return ['place' => $exact[0], 'resolution' => null, 'options' => []];
        }
        if (mb_strlen($text) < 2) {
            return $none;
        }
        if ($exact === []) {
            $prefixed = self::placeRows(self::NAME_PREFIX_SQL, [$uf, $uf, self::likePrefix($text)]);
            if (count($prefixed) === 1) {
                return ['place' => $prefixed[0], 'resolution' => null, 'options' => []];
            }
        }
        $hits = self::prefixPlaces($text);
        $count = count($hits);
        if ($count === 1) {
            return ['place' => $hits[0], 'resolution' => null, 'options' => []];
        }
        if ($count === 0) {
            return $none;
        }
        return [
            'place' => null,
            'resolution' => $count >= 9 ? 'ambiguous' : 'did_you_mean',
            'options' => array_map(self::option(...), array_slice($hits, 0, 8)),
        ];
    }

    /** @return array{0:string,1:string} folded name and optional UF */
    private static function splitPlaceQuery(string $query): array
    {
        $fold = preg_replace('/\s+/', ' ', fold($query)) ?? '';
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
    private static function prefixPlaces(string $text): array
    {
        return self::placeRows(self::PREFIX_SQL, [self::likePrefix($text)]);
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
                'uf' => rtrim((string) $row['uf']),
                'lat' => (float) $row['lat'],
                'lng' => (float) $row['lng'],
            ];
        }
        return $rows;
    }

    /** @param array{id:int,name:string,label:string,type:string,uf:string} $place */
    private static function option(array $place): array
    {
        return [
            'id' => $place['id'],
            'text' => $place['name'] . ', ' . $place['uf'],
            'name' => $place['name'],
            'label' => $place['label'],
            'type' => $place['type'],
            'uf' => $place['uf'],
        ];
    }

    public static function random(): array
    {
        $cities = self::list();
        if (!$cities) {
            throw new RuntimeException('Nenhuma cidade no banco.');
        }
        return $cities[random_int(0, count($cities) - 1)];
    }

    public static function refresh(): array
    {
        self::ensure(db());
        $cities = [];
        $seenStates = [];
        foreach (db()->query("SELECT id, name, uf, label, lat, lng, name_fold FROM places WHERE type = 'city' ORDER BY name, uf, id") as $row) {
            $row['uf'] = rtrim((string) $row['uf']);
            $row['label_fold'] = fold((string) $row['label']);
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

    private static function list(): array
    {
        $data = self::cached();
        return $data['cities'] ?? [];
    }

    private static function cached(): array
    {
        if (is_array(self::$citiesCache)) {
            return self::$citiesCache;
        }
        $decoded = JsonCache::read('cities.json');
        $first = is_array($decoded) ? ($decoded['cities'][0] ?? null) : null;
        if (is_array($decoded) && isset($decoded['cities'], $decoded['states'])
            && is_array($first) && isset($first['id'], $first['name_fold'], $first['label_fold'])) {
            self::$citiesCache = $decoded;
            return self::$citiesCache;
        }
        self::$citiesCache = self::refresh();
        return self::$citiesCache;
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
