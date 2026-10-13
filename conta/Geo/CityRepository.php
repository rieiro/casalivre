<?php

declare(strict_types=1);

if (!class_exists('JsonCache', false)) {
    require_once __DIR__ . '/JsonCache.php';
}

final class CityRepository
{
    public static function ensure(PDO $pdo): void
    {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM cities')->fetchColumn();
        if ($count >= 5000) {
            return;
        }
        if ($count > 0) {
            $pdo->exec('DELETE FROM cities');
        }
        $path = dirname(__DIR__, 2) . '/database/cities.json';
        $rows = json_decode((string) file_get_contents($path), true);
        if (!is_array($rows)) {
            return;
        }
        // Pode rodar dentro do BEGIN IMMEDIATE de Db::pdo() (DEF-008): só abre transação própria se não houver uma.
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        $stmt = $pdo->prepare('INSERT INTO cities (name, uf, label, lat, lng, name_fold, label_fold) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($rows as $row) {
            $stmt->execute([
                $row['name'],
                $row['uf'],
                $row['label'],
                $row['lat'],
                $row['lng'],
                fold((string) $row['name']),
                fold((string) $row['label']),
            ]);
        }
        if ($own) {
            $pdo->commit();
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
        foreach (db()->query('SELECT name, uf, label, lat, lng, name_fold, label_fold FROM cities ORDER BY name, uf') as $row) {
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
        if (is_array($decoded) && isset($decoded['cities'], $decoded['states'])) {
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
