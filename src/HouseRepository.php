<?php

declare(strict_types=1);

require_once __DIR__ . '/ReturningWrite.php';
require_once __DIR__ . '/ImovelNaoEncontrado.php';
require_once __DIR__ . '/ListingSearch.php';

final class HouseRepository
{
    /** Display city "Name, UF" from places aliased as c. */
    public const CITY_LABEL_SQL = "(c.name || ', ' || rtrim(c.uf))";

    /** properties p.city_place_id → places c. */
    public const JOIN_CITY_SQL = 'JOIN places c ON c.id = p.city_place_id';

    /** properties.* plus the display city. city_place_id replaced properties.city. */
    public const SQL_WITH_CITY = 'SELECT p.*, ' . self::CITY_LABEL_SQL . ' AS city FROM properties p ' . self::JOIN_CITY_SQL;

    public static function insert(array $house): int
    {
        return (int) self::insertRow($house)['id'];
    }

    public static function insertRow(array $house): array
    {
        return Db::transactional(db(), static function () use ($house): array {
            $hash = bin2hex(random_bytes(8));
            $placeId = self::cityPlaceId($house);
            $neighborhoodId = self::optionalPlaceId($house, 'neighborhood_place_id');
            $stmt = db()->prepare('INSERT INTO properties (
                user_id, title, description, summary, city_place_id, neighborhood_place_id, address, cep, street, address_number, address_complement, lat, lng, bedrooms, beds, rooms, max_guests,
                nightly_price, checkin_time, checkout_time, min_nights, calendar_months, cleaning_fee, pool, bbq, garage, amenities, public_hash, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id, title, description, summary, city_place_id, address, cep, nightly_price, cleaning_fee, bedrooms, beds, rooms, max_guests, min_nights, calendar_months, checkin_time, checkout_time, pool, bbq, garage, amenities, status');
            $stmt->execute([
                $house['user_id'], $house['title'], $house['description'], $house['summary'], $placeId, $neighborhoodId,
                $house['address'], $house['cep'] ?? '', $house['street'] ?? '', $house['address_number'] ?? '', $house['address_complement'] ?? '',
                $house['lat'], $house['lng'], $house['bedrooms'], $house['beds'], $house['rooms'] ?? '[]', $house['max_guests'],
                $house['nightly_price'], $house['checkin_time'], $house['checkout_time'], $house['min_nights'],
                self::calendarMonths($house),
                max(0, (int) ($house['cleaning_fee'] ?? 0)),
                $house['pool'], $house['bbq'], $house['garage'], $house['amenities'] ?? '{}', $hash, date('Y-m-d H:i:s'),
            ]);
            $row = $stmt->fetch();
            $row['city'] = self::cityText($placeId);
            ListingSearch::sync((int) $row['id']);
            return $row;
        });
    }

    /** city_place_id already resolved by the caller. */
    private static function cityPlaceId(array $house): int
    {
        $id = (int) ($house['city_place_id'] ?? 0);
        if ($id > 0) {
            return $id;
        }
        throw new RuntimeException('Cidade não encontrada.');
    }

    private static function optionalPlaceId(array $house, string $key): ?int
    {
        if (!array_key_exists($key, $house) || $house[$key] === null || $house[$key] === '') {
            return null;
        }
        return (int) $house[$key];
    }

    private static function cityText(int $placeId): string
    {
        static $cache = [];
        if (isset($cache[$placeId])) {
            return $cache[$placeId];
        }
        $stmt = db()->prepare("SELECT name || ', ' || rtrim(uf) FROM places WHERE id = ?");
        $stmt->execute([$placeId]);
        return $cache[$placeId] = rtrim((string) $stmt->fetchColumn());
    }

    /** @param list<array{old: array, new: array}> $pairs */
    private static function exposeCity(array $pairs): array
    {
        foreach ($pairs as &$pair) {
            foreach (['old', 'new'] as $side) {
                if (!array_key_exists('city_place_id', $pair[$side])) {
                    continue;
                }
                $pair[$side]['city'] = self::cityText((int) $pair[$side]['city_place_id']);
                unset($pair[$side]['city_place_id']);
            }
        }
        unset($pair);
        return $pairs;
    }

    private static function calendarMonths(array $house): int
    {
        $cap = class_exists('Settings') ? max(1, Settings::int('calendar_months')) : 6;
        $own = (int) ($house['calendar_months'] ?? 0);
        if ($own < 1) {
            $own = 6;
        }
        return min($own, $cap);
    }

    /** Property write and its listing_search refresh, in one transaction. */
    private static function synced(int $id, Closure $write): mixed
    {
        return Db::transactional(db(), static function () use ($id, $write): mixed {
            $result = $write();
            ListingSearch::sync($id);
            return $result;
        });
    }

    public static function update(int $id, int $userId, array $house): array
    {
        return self::synced($id, static function () use ($id, $userId, $house): array {
            $months = self::calendarMonths($house);
            $fee = max(0, (int) ($house['cleaning_fee'] ?? 0));
            return self::exposeCity(ReturningWrite::update('properties', [
                'title' => $house['title'],
                'description' => $house['description'],
                'summary' => $house['summary'],
                'city_place_id' => self::cityPlaceId($house),
                'neighborhood_place_id' => self::optionalPlaceId($house, 'neighborhood_place_id'),
                'address' => $house['address'],
                'cep' => $house['cep'] ?? '',
                'street' => $house['street'] ?? '',
                'address_number' => $house['address_number'] ?? '',
                'address_complement' => $house['address_complement'] ?? '',
                'lat' => $house['lat'],
                'lng' => $house['lng'],
                'bedrooms' => $house['bedrooms'],
                'beds' => $house['beds'],
                'rooms' => $house['rooms'] ?? '[]',
                'max_guests' => $house['max_guests'],
                'nightly_price' => $house['nightly_price'],
                'checkin_time' => $house['checkin_time'],
                'checkout_time' => $house['checkout_time'],
                'min_nights' => $house['min_nights'],
                'calendar_months' => $months,
                'cleaning_fee' => $fee,
                'pool' => $house['pool'],
                'bbq' => $house['bbq'],
                'garage' => $house['garage'],
                'amenities' => $house['amenities'] ?? '{}',
            ], 'id = ? AND user_id = ?', [$id, $userId], ['id'], [
                'title', 'description', 'summary', 'city_place_id', 'address', 'cep', 'nightly_price', 'cleaning_fee',
                'bedrooms', 'beds', 'rooms', 'max_guests', 'min_nights', 'calendar_months', 'checkin_time', 'checkout_time',
                'pool', 'bbq', 'garage', 'amenities', 'status',
            ]));
        });
    }

    public static function clearBlockedAfter(int $propertyId, string $day): array
    {
        return ReturningWrite::remove(
            'calendar_days',
            "property_id = ? AND day > ? AND (blocked = 1 OR origin IN ('sistema', 'sync', 'reserva'))",
            [$propertyId, $day],
            ['day', 'blocked', 'price', 'min_nights', 'origin']
        );
    }

    public static function find(int $id): ?array
    {
        $stmt = db()->prepare(self::SQL_WITH_CITY . ' WHERE p.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findByUser(int $userId, int $id): ?array
    {
        $stmt = db()->prepare(self::SQL_WITH_CITY . " WHERE p.id = ? AND p.user_id = ? AND COALESCE(NULLIF(p.status, ''), 'active') = 'active'");
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function ensurePublicHash(array $property): string
    {
        $hash = trim((string) ($property['public_hash'] ?? ''));
        if (preg_match('/^[a-f0-9]{16}$/', $hash)) {
            return $hash;
        }
        $hash = bin2hex(random_bytes(8));
        db()->prepare("UPDATE properties SET public_hash = ? WHERE id = ? AND (public_hash IS NULL OR public_hash = '')")
            ->execute([$hash, (int) $property['id']]);
        $stored = db()->prepare('SELECT public_hash FROM properties WHERE id = ?');
        $stored->execute([(int) $property['id']]);
        return (string) $stored->fetchColumn();
    }

    public static function findByPublicHash(string $hash): ?array
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $hash)) {
            return null;
        }
        $stmt = db()->prepare(self::SQL_WITH_CITY . ' WHERE p.public_hash = ?');
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function ensureIcalHash(array $property): string
    {
        $hash = trim((string) ($property['ical_hash'] ?? ''));
        if ($hash !== '') {
            return $hash;
        }
        $hash = bin2hex(random_bytes(16));
        db()->prepare("UPDATE properties SET ical_hash = ? WHERE id = ? AND (ical_hash IS NULL OR ical_hash = '')")
            ->execute([$hash, (int) $property['id']]);
        $stored = db()->prepare('SELECT ical_hash FROM properties WHERE id = ?');
        $stored->execute([(int) $property['id']]);
        return (string) $stored->fetchColumn();
    }

    public static function findByIcalHash(string $hash): ?array
    {
        $stmt = db()->prepare(self::SQL_WITH_CITY . ' WHERE p.ical_hash = ?');
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function listByUser(int $userId): array
    {
        $stmt = db()->prepare(self::SQL_WITH_CITY . " WHERE p.user_id = ? AND p.status = 'active' ORDER BY p.id DESC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public static function all(): array
    {
        return db()->query(self::SQL_WITH_CITY . " WHERE p.status = 'active' ORDER BY p.id ASC")->fetchAll();
    }

    public static function inactivate(int $id, int $userId, string $at): array
    {
        return self::synced($id, static function () use ($id, $userId, $at): array {
            return self::exposeCity(ReturningWrite::update(
                'properties',
                ['status' => 'inactive', 'inactivated_at' => $at],
                "id = ? AND user_id = ? AND status = 'active'",
                [$id, $userId],
                ['id'],
                ['title', 'description', 'summary', 'city_place_id', 'address', 'cep', 'nightly_price', 'cleaning_fee', 'bedrooms', 'beds', 'max_guests', 'min_nights', 'calendar_months', 'checkin_time', 'checkout_time', 'status', 'inactivated_at']
            ));
        });
    }

    public static function updateMinNights(int $id, int $minNights): array
    {
        return self::synced($id, static fn (): array => ReturningWrite::update(
            'properties',
            ['min_nights' => $minNights],
            'id = ?',
            [$id],
            ['id'],
            ['min_nights']
        ));
    }

    public static function calendarDays(int $propertyId): array
    {
        $stmt = db()->prepare('SELECT day, blocked, price, min_nights, origin FROM calendar_days WHERE property_id = ? ORDER BY day');
        $stmt->execute([$propertyId]);
        return $stmt->fetchAll();
    }

    /** Leitura do calendário até o horizonte permitido, sem escrever no banco. */
    public static function calendarDaysUntil(int $propertyId, string $maxDay): array
    {
        $stmt = db()->prepare('SELECT day, blocked, price, min_nights, origin FROM calendar_days WHERE property_id = ? AND day <= ? ORDER BY day');
        $stmt->execute([$propertyId, $maxDay]);
        return $stmt->fetchAll();
    }

    public static function calendarForNights(int $propertyId, array $nights): array
    {
        if ($nights === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($nights), '?'));
        $stmt = db()->prepare("SELECT day, blocked, price, min_nights, origin FROM calendar_days WHERE property_id = ? AND day IN ($placeholders)");
        $stmt->execute(array_merge([$propertyId], $nights));
        $byDay = [];
        foreach ($stmt->fetchAll() as $row) {
            $byDay[$row['day']] = $row;
        }
        return $byDay;
    }

    public static function calendarForProperties(array $ids, array $nights): array
    {
        $map = [];
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === [] || $nights === []) {
            return $map;
        }
        $dayPlaceholders = implode(',', array_fill(0, count($nights), '?'));
        foreach (array_chunk($ids, 300) as $chunk) {
            $idPlaceholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = db()->prepare("SELECT property_id, day, blocked, price, min_nights, origin FROM calendar_days WHERE property_id IN ($idPlaceholders) AND day IN ($dayPlaceholders)");
            $stmt->execute(array_merge($chunk, $nights));
            foreach ($stmt->fetchAll() as $row) {
                $map[(int) $row['property_id']][$row['day']] = $row;
            }
        }
        return $map;
    }

    public static function exportDays(int $propertyId): array
    {
        $stmt = db()->prepare("SELECT day, blocked, origin FROM calendar_days
            WHERE property_id = ? AND blocked = 1 AND COALESCE(origin, '') NOT IN ('sync', 'reserva')
            ORDER BY day");
        $stmt->execute([$propertyId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $day = (string) ($row['day'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $day)) {
                continue;
            }
            $rows[] = ['day' => substr($day, 0, 10), 'origin' => (string) ($row['origin'] ?? ''), 'blocked' => (int) ($row['blocked'] ?? 0)];
        }
        return $rows;
    }

    public static function blockedDays(int $propertyId): array
    {
        $stmt = db()->prepare('SELECT day, origin FROM calendar_days WHERE property_id = ? AND blocked = 1 ORDER BY day');
        $stmt->execute([$propertyId]);
        return $stmt->fetchAll();
    }

    public static function upsertDay(int $propertyId, string $day, int $blocked, ?int $price, ?int $minNights = null): void
    {
        db()->prepare('INSERT INTO calendar_days (property_id, day, blocked, price, min_nights) VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(property_id, day) DO UPDATE SET blocked = excluded.blocked, price = excluded.price, min_nights = excluded.min_nights')
            ->execute([$propertyId, $day, $blocked, $price, $minNights]);
    }

    public static function applyRange(int $propertyId, string $start, string $end, string $action, ?int $price): array
    {
        $guard = $action !== 'preco';
        $where = 'property_id = ? AND day >= ? AND day <= ?';
        $params = [$propertyId, $start, $end];
        if ($guard) {
            $where .= " AND NOT EXISTS (SELECT 1 FROM calendar_days locked WHERE locked.property_id = ? AND locked.day >= ? AND locked.day <= ? AND locked.origin IN ('sync', 'reserva'))";
            array_push($params, $propertyId, $start, $end);
        }
        $values = $action === 'preco'
            ? ['price' => $price]
            : ['blocked' => $action === 'bloquear' ? 1 : 0, 'origin' => $action === 'bloquear' ? 'sistema' : ''];
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $updated = ReturningWrite::update('calendar_days', $values, $where, $params, ['property_id', 'day'], ['day', 'blocked', 'price', 'min_nights', 'origin']);
            $inserted = self::insertMissingDays(
                $propertyId,
                $start,
                $end,
                $action === 'bloquear' ? 1 : 0,
                $action === 'preco' ? $price : null,
                null,
                $action === 'bloquear' ? 'sistema' : '',
                $guard
            );
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $audit = self::nightAudit([...$updated, ...$inserted]);
        return ['count' => count($updated) + count($inserted), 'anterior' => $audit['anterior'], 'atual' => $audit['atual']];
    }

    public static function applyMinNights(int $propertyId, string $start, string $end, int $minNights): array
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $updated = ReturningWrite::update(
                'calendar_days',
                ['min_nights' => $minNights],
                'property_id = ? AND day >= ? AND day <= ?',
                [$propertyId, $start, $end],
                ['property_id', 'day'],
                ['day', 'blocked', 'price', 'min_nights', 'origin']
            );
            $inserted = self::insertMissingDays($propertyId, $start, $end, 0, null, $minNights, '', false);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return self::nightAudit([...$updated, ...$inserted]);
    }

    public static function blockDay(int $propertyId, string $day, string $origin = 'sync'): void
    {
        db()->prepare('INSERT INTO calendar_days (property_id, day, blocked, price, min_nights, origin) VALUES (?, ?, 1, NULL, NULL, ?)
            ON CONFLICT(property_id, day) DO UPDATE SET blocked = 1, origin = excluded.origin')
            ->execute([$propertyId, $day, $origin]);
    }

    public static function tagOriginIfEmpty(int $propertyId, string $day, string $origin): void
    {
        db()->prepare("UPDATE calendar_days SET origin = ? WHERE property_id = ? AND day = ? AND blocked = 1 AND (origin IS NULL OR origin = '')")
            ->execute([$origin, $propertyId, $day]);
    }

    /**
     * Feeds para exibição/API: só campos seguros e URL mascarada (esquema + host + ••••).
     * A URL real pode conter token; para sincronizar use feedsWithUrl().
     */
    public static function feeds(int $propertyId): array
    {
        $stmt = db()->prepare('SELECT id, property_id, title, url, last_status, last_synced_at FROM calendar_feeds WHERE property_id = ? ORDER BY id');
        $stmt->execute([$propertyId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $row['url'] = self::maskFeedUrl((string) $row['url']);
            $rows[] = $row;
        }
        return $rows;
    }

    /** Uso interno (sincronização/comparação): inclui a URL real. Nunca devolver ao cliente. */
    public static function feedsWithUrl(int $propertyId): array
    {
        $stmt = db()->prepare('SELECT * FROM calendar_feeds WHERE property_id = ? ORDER BY id');
        $stmt->execute([$propertyId]);
        return $stmt->fetchAll();
    }

    /** https://www.airbnb.com/calendar/ical/123.ics?s=TOKEN -> https://www.airbnb.com/•••• */
    public static function maskFeedUrl(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '••••';
        }
        return (is_string($scheme) ? strtolower($scheme) : 'https') . '://' . strtolower($host) . '/••••';
    }

    public static function feedsForActiveAccounts(): array
    {
        return db()->query("SELECT f.id, f.property_id, f.url, f.last_status, f.last_synced_at
            FROM calendar_feeds f
            JOIN properties p ON p.id = f.property_id
            JOIN users o ON o.id = p.user_id
            WHERE o.status = 'active' AND p.status = 'active'
            ORDER BY f.property_id, f.id")->fetchAll();
    }

    public static function addFeed(int $propertyId, int $userId, string $url, string $title): string|array
    {
        if (!self::findByUser($userId, $propertyId)) {
            return 'missing';
        }
        $title = trim($title);
        $titleLen = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
        if ($title === '' || $titleLen > 40) {
            return 'title';
        }
        $exists = db()->prepare('SELECT 1 FROM calendar_feeds WHERE property_id = ? AND url = ?');
        $exists->execute([$propertyId, $url]);
        if ($exists->fetchColumn()) {
            return 'duplicate';
        }
        $stmt = db()->prepare('SELECT COUNT(*) FROM calendar_feeds WHERE property_id = ?');
        $stmt->execute([$propertyId]);
        if ((int) $stmt->fetchColumn() >= 5) {
            return 'limit';
        }
        $created = ReturningWrite::insert('calendar_feeds', [
            'property_id' => $propertyId,
            'url' => $url,
            'title' => $title,
        ], ['id', 'url', 'title']);
        if ($created === []) {
            return 'duplicate';
        }
        return ['feed' => $created[0]['new'], 'link' => self::syncExternalUrl($propertyId, $userId)];
    }

    public static function removeFeed(int $propertyId, int $userId, int $feedId): array
    {
        $deleted = ReturningWrite::remove('calendar_feeds', 'id = ? AND property_id = ?', [$feedId, $propertyId], ['id', 'url', 'title']);
        if ($deleted === []) {
            // Feed inexistente: não toca em properties (antes gravava external_ical_url NULL -> '' e audit_hold).
            return ['feed' => null, 'link' => []];
        }
        return ['feed' => $deleted[0]['old'] ?? null, 'link' => self::syncExternalUrl($propertyId, $userId)];
    }

    public static function releaseImported(int $propertyId): array
    {
        return ReturningWrite::update(
            'calendar_days',
            ['blocked' => 0, 'origin' => ''],
            "property_id = ? AND origin IN ('sync', 'reserva')",
            [$propertyId],
            ['property_id', 'day'],
            ['day', 'blocked', 'price', 'min_nights', 'origin']
        );
    }

    public static function syncExternalUrl(int $propertyId, int $userId): array
    {
        $json = 'json_build_object';
        $sql = 'UPDATE ' . ReturningWrite::q('properties')
            . ' SET ' . ReturningWrite::q('audit_hold') . ' = ' . $json . "('external_ical_url', " . ReturningWrite::q('external_ical_url') . '), '
            . ReturningWrite::q('external_ical_url') . " = COALESCE((SELECT url FROM calendar_feeds WHERE property_id = ? ORDER BY id LIMIT 1), '')"
            . ' WHERE id = ? AND user_id = ? RETURNING ' . ReturningWrite::q('audit_hold') . ', ' . ReturningWrite::q('external_ical_url');
        $rows = ReturningWrite::query($sql, [$propertyId, $propertyId, $userId]);
        if ($rows === []) {
            return [];
        }
        $old = json_decode((string) ($rows[0]['audit_hold'] ?? ''), true);
        return [[
            'old' => ['external_ical_url' => is_array($old) ? ($old['external_ical_url'] ?? null) : null],
            'new' => ['external_ical_url' => $rows[0]['external_ical_url'] ?? ''],
        ]];
    }

    /** Marca o feed para a próxima sincronização do cron, sem alterar last_synced_at. */
    public static function markFeedPending(int $id): void
    {
        db()->prepare("UPDATE calendar_feeds SET last_status = 'PENDENTE' WHERE id = ?")->execute([$id]);
    }

    public static function markFeed(int $id, string $status, string $at): void
    {
        db()->prepare('UPDATE calendar_feeds SET last_status = ?, last_synced_at = ? WHERE id = ?')->execute([$status, $at, $id]);
    }

    public static function findFeed(int $propertyId, int $feedId): ?array
    {
        $stmt = db()->prepare('SELECT * FROM calendar_feeds WHERE id = ? AND property_id = ?');
        $stmt->execute([$feedId, $propertyId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function addReview(int $propertyId, string $author, int $score, string $comment): void
    {
        Db::transactional(db(), static function (PDO $pdo) use ($propertyId, $author, $score, $comment): void {
            $pdo->prepare('INSERT INTO reviews (property_id, author, score, comment, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$propertyId, $author, $score, $comment, date('Y-m-d H:i:s')]);
            $pdo->prepare('UPDATE properties SET rating_sum = rating_sum + ?, rating_count = rating_count + 1 WHERE id = ?')
                ->execute([$score, $propertyId]);
            ListingSearch::sync($propertyId);
        });
    }

    /** round(rating_sum/rating_count, 1), or null when the listing has no reviews. */
    public static function score(array $property): ?float
    {
        $count = (int) $property['rating_count'];
        return $count > 0 ? round((int) $property['rating_sum'] / $count, 1) : null;
    }

    public static function nightAudit(array $pairs): array
    {
        $before = [];
        $after = [];
        foreach ($pairs as $pair) {
            $day = substr((string) (($pair['new']['day'] ?? '') !== '' && $pair['new']['day'] !== null ? $pair['new']['day'] : ($pair['old']['day'] ?? '')), 0, 10);
            if ($day === '') {
                continue;
            }
            $pack = static function (array $row): array {
                return [
                    'diaria' => $row['price'] ?? null,
                    'bloqueado' => array_key_exists('blocked', $row) && $row['blocked'] !== null ? (int) $row['blocked'] : null,
                    'minimo_noites' => $row['min_nights'] ?? null,
                    'origem' => $row['origin'] ?? null,
                ];
            };
            if ($pair['old'] !== []) {
                $before[$day] = $pack($pair['old']);
            }
            if ($pair['new'] !== []) {
                $after[$day] = $pack($pair['new']);
            }
        }
        return ['anterior' => $before, 'atual' => $after];
    }

    private static function insertMissingDays(int $propertyId, string $start, string $end, int $blocked, ?int $price, ?int $minNights, string $origin, bool $guard): array
    {
        $series = "WITH RECURSIVE days(day) AS (SELECT CAST(? AS date) UNION ALL SELECT CAST(day + 1 AS date) FROM days WHERE day < CAST(? AS date))"; // calendar_days.day é DATE
        $guardSql = $guard
            ? " AND NOT EXISTS (SELECT 1 FROM calendar_days locked WHERE locked.property_id = ? AND locked.day >= ? AND locked.day <= ? AND locked.origin IN ('sync', 'reserva'))"
            : '';
        $sql = $series . ' INSERT INTO calendar_days (property_id, day, blocked, price, min_nights, origin) SELECT ?, days.day, ?, ?, ?, ? FROM days WHERE NOT EXISTS (SELECT 1 FROM calendar_days have WHERE have.property_id = ? AND have.day = days.day)' . $guardSql . ' RETURNING day, blocked, price, min_nights, origin';
        $params = [$start, $end, $propertyId, $blocked, $price, $minNights, $origin, $propertyId];
        if ($guard) {
            array_push($params, $propertyId, $start, $end);
        }
        $pairs = [];
        foreach (ReturningWrite::query($sql, $params) as $row) {
            $day = substr((string) ($row['day'] ?? ''), 0, 10);
            $row['day'] = $day;
            $pairs[] = ['old' => [], 'new' => $row];
        }
        return $pairs;
    }

    // Resolução de imóvel para as rotas web: lança ImovelNaoEncontrado; o 404 é renderizado pela camada web.
    public static function idFromPublicRefOrFail(string $ref): int
    {
        static $cache = [];
        if (isset($cache[$ref])) {
            return $cache[$ref];
        }
        $row = HouseRepository::findByPublicHash($ref);
        if (!$row || ($row['status'] ?? 'active') !== 'active') {
            throw ImovelNaoEncontrado::publico();
        }
        $cache[$ref] = (int) $row['id'];
        return $cache[$ref];
    }

    public static function findByUserOrFail(int $userId, int $id): array
    {
        $row = self::findByUser($userId, $id);
        if (!$row) {
            throw ImovelNaoEncontrado::dono();
        }
        return $row;
    }
}
