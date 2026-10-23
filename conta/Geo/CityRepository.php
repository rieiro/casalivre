<?php

declare(strict_types=1);

if (!class_exists('JsonCache', false)) {
    require_once __DIR__ . '/JsonCache.php';
}

final class CityRepository
{
    public static function ensure(PDO $pdo): void
    {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'city'")->fetchColumn();
        if ($count >= 5000) {
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
            $insert = $pdo->prepare("INSERT INTO places (type, parent_id, name, name_fold, label, uf, lat, lng, popularity) VALUES ('city', NULL, ?, ?, ?, ?, ?, ?, 0) RETURNING id");
            $keyIns = $pdo->prepare('INSERT INTO place_search_keys (place_id, "key", kind, popularity) VALUES (?, ?, \'name\', 0)');
            foreach ($rows as $row) {
                $name = (string) $row['name'];
                $insert->execute([
                    $name,
                    fold($name),
                    (string) $row['label'],
                    (string) $row['uf'],
                    $row['lat'],
                    $row['lng'],
                ]);
                $id = (int) $insert->fetchColumn();
                foreach (self::searchKeys($name) as $key) {
                    $keyIns->execute([$id, $key]);
                }
            }
            self::seedPilot($pdo);
        });
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
        $insert = $pdo->prepare("INSERT INTO places (type, parent_id, name, name_fold, label, uf, lat, lng, popularity) VALUES ('poi', ?, ?, ?, ?, ?, ?, ?, 100) RETURNING id");
        $keyIns = $pdo->prepare('INSERT INTO place_search_keys (place_id, "key", kind, popularity) VALUES (?, ?, \'name\', 100)');
        foreach ($pilots as $poi) {
            $parent->execute([fold($poi['parent']), $poi['uf']]);
            $parentId = $parent->fetchColumn();
            if ($parentId === false) {
                continue;
            }
            $name = $poi['name'];
            $insert->execute([(int) $parentId, $name, fold($name), $name, $poi['uf'], $poi['lat'], $poi['lng']]);
            $id = (int) $insert->fetchColumn();
            foreach (self::searchKeys($name) as $key) {
                $keyIns->execute([$id, $key]);
            }
        }
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
        static $data = null;
        if (is_array($data)) {
            return $data;
        }
        $decoded = JsonCache::read('cities.json');
        $first = is_array($decoded) ? ($decoded['cities'][0] ?? null) : null;
        if (is_array($decoded) && isset($decoded['cities'], $decoded['states'])
            && is_array($first) && isset($first['id'], $first['name_fold'], $first['label_fold'])) {
            $data = $decoded;
            return $data;
        }
        $data = self::refresh();
        return $data;
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
