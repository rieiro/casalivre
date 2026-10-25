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

    /**
     * Nomes alternativos (dobrados) que match() resolve para "name_fold, uf" da cidade oficial. O alias
     * nunca é exibido nem vira linha em places; não há busca por sufixo, para "Cabo" não achar Arraial do Cabo.
     */
    private const ALIASES = [
        'buzios' => 'armacao dos buzios, rj',
        'buzios, rj' => 'armacao dos buzios, rj',
    ];

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

    public static function suggest(string $query, int $limit = 8): array
    {
        $fold = fold($query);
        if (mb_strlen($fold) < 3) {
            return [];
        }
        $seen = [];
        $rows = [];
        foreach (self::list() as $row) {
            if (!str_starts_with((string) $row['name_fold'], $fold)) {
                continue;
            }
            $key = $row['name'] . '|' . $row['uf'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $rows[] = [
                'text' => $row['name'] . ', ' . $row['uf'],
                'name' => $row['name'],
                'uf' => $row['uf'],
            ];
            if (count($rows) >= $limit) {
                break;
            }
        }
        return $rows;
    }

    public static function match(string $query): ?array
    {
        $fold = fold($query);
        if ($fold === '') {
            return null;
        }
        $fold = self::ALIASES[preg_replace('/\s*,\s*/', ', ', $fold)] ?? $fold;
        if (preg_match('/^(.+),\s*([a-z]{2})$/', $fold, $parts)) {
            foreach (self::list() as $row) {
                if ($row['name_fold'] === $parts[1] && strtolower((string) $row['uf']) === $parts[2]) {
                    return $row;
                }
            }
        }
        $hits = [];
        foreach (self::list() as $row) {
            if ($row['label_fold'] === $fold || $row['name_fold'] === $fold) {
                $hits[] = $row;
            }
        }
        if (count($hits) === 1) {
            return $hits[0];
        }
        foreach ($hits as $row) {
            if ($row['label_fold'] === $fold) {
                return $row;
            }
        }
        return null;
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
