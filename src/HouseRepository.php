<?php

declare(strict_types=1);

require_once __DIR__ . '/ReturningWrite.php';
require_once __DIR__ . '/ImovelNaoEncontrado.php';
require_once __DIR__ . '/ListingSearch.php';
require_once __DIR__ . '/CalendarSync.php';

final class HouseRepository
{
    /** Display city "Name, UF" from places aliased as c. */
    public const CITY_LABEL_SQL = "(c.name || ', ' || rtrim(c.uf))";

    /** properties p.city_place_id → places c. */
    public const JOIN_CITY_SQL = 'JOIN places c ON c.id = p.city_place_id';

    /** properties.* plus the display city. city_place_id replaced properties.city. */
    public const SQL_WITH_CITY = 'SELECT p.*, ' . self::CITY_LABEL_SQL . ' AS city FROM properties p ' . self::JOIN_CITY_SQL;

    /** Two-int advisory lock namespace: sync vs sync and feed removal. Bookings do not take it. */
    public const SYNC_ADVISORY_NS = 48001403;
    public const FEED_SLOTS = 5;
    public const ADD_FEED_ATTEMPTS = 5;
    /** The 16th consecutive failure stops the feed (next_sync_at = infinity). */
    public const FAIL_STOP = 16;
    /** Added to a scheduled next_sync_at so a restart does not sync every feed at once. */
    public const JITTER_MAX_SECONDS = 300;
    public const PERMANENT_FEED_ERRORS = ['bad_url', 'private_ip', 'http_4xx', 'not_ics', 'too_big'];
    public const TEMPORARY_FEED_ERRORS = ['timeout', 'dns', 'http_5xx'];

    /**
     * One due feed, oldest first. 10-minute lease when the account is active, otherwise +1 day.
     * The worker skips active=false. SKIP LOCKED: two workers cannot claim the same row.
     */
    public const CLAIM_DUE_FEED_SQL = <<<'SQL'
UPDATE calendar_feeds f SET next_sync_at = now() + CASE WHEN c.active THEN interval '10 minutes' ELSE interval '1 day' END
FROM (SELECT f2.id, (p.status = 'active' AND u.status = 'active') AS active,
             f2.next_sync_at AS prev_due, f2.last_synced_at
      FROM calendar_feeds f2
      JOIN properties p ON p.id = f2.property_id
      JOIN users u ON u.id = p.user_id
      WHERE f2.next_sync_at <= now()
      ORDER BY f2.next_sync_at
      LIMIT 1
      FOR UPDATE OF f2 SKIP LOCKED) c
WHERE f.id = c.id
RETURNING f.id, f.property_id, f.url, c.active, c.prev_due, c.last_synced_at
SQL;

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

    /**
     * Feeds para exibição/API: só campos seguros e URL mascarada (esquema + host + ••••).
     * A URL real pode conter token; para sincronizar use feedsWithUrl().
     * stopped and last_error are the coarse status the owner sees (the code, never the URL).
     */
    public static function feeds(int $propertyId): array
    {
        $stmt = db()->prepare("SELECT id, property_id, title, url, last_status, last_synced_at, last_error, fail_count,
            next_sync_at = 'infinity' AS stopped
            FROM calendar_feeds WHERE property_id = ? ORDER BY id");
        $stmt->execute([$propertyId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $row['url'] = self::maskFeedUrl((string) $row['url']);
            $row['fail_count'] = (int) $row['fail_count'];
            $row['stopped'] = self::pgBool($row['stopped']);
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
        if (strlen($url) > CalendarSync::MAX_URL_LENGTH || !CalendarSync::isHttpsUrl($url)) {
            return 'url';
        }
        for ($attempt = 0; $attempt < self::ADD_FEED_ATTEMPTS; $attempt++) {
            $exists = db()->prepare('SELECT 1 FROM calendar_feeds WHERE property_id = ? AND url = ?');
            $exists->execute([$propertyId, $url]);
            if ($exists->fetchColumn()) {
                return 'duplicate';
            }
            $stmt = db()->prepare('SELECT COUNT(*) FROM calendar_feeds WHERE property_id = ?');
            $stmt->execute([$propertyId]);
            if ((int) $stmt->fetchColumn() >= self::FEED_SLOTS) {
                return 'limit';
            }
            $row = self::insertFeedSlot($propertyId, $url, $title);
            if ($row !== null) {
                return ['feed' => $row, 'link' => self::syncExternalUrl($propertyId, $userId)];
            }
        }
        return 'limit';
    }

    /**
     * Deletes the feed and frees only the nights it holds. Other feeds of the property become due.
     * One transaction, advisory lock first. Returns the night pairs for the audit.
     *
     * @return array{feed: ?array, link: array, nights: array}
     */
    public static function removeFeed(int $propertyId, int $userId, int $feedId): array
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('SELECT pg_advisory_xact_lock(?, ?)')->execute([self::SYNC_ADVISORY_NS, $propertyId]);
            $deleted = ReturningWrite::remove('calendar_feeds', 'id = ? AND property_id = ?', [$feedId, $propertyId], ['id', 'url', 'title']);
            if ($deleted === []) {
                $pdo->rollBack();
                return ['feed' => null, 'link' => [], 'nights' => []];
            }
            $nights = ReturningWrite::update(
                'calendar_days',
                ['blocked' => 0, 'origin' => '', 'feed_id' => null],
                'property_id = ? AND feed_id = ?',
                [$propertyId, $feedId],
                ['property_id', 'day'],
                ['day', 'blocked', 'price', 'min_nights', 'origin']
            );
            $pdo->prepare("UPDATE calendar_feeds SET next_sync_at = now()
                WHERE property_id = ? AND next_sync_at > now() AND next_sync_at < 'infinity'")
                ->execute([$propertyId]);
            $link = self::syncExternalUrl($propertyId, $userId);
            $pdo->commit();
            return ['feed' => $deleted[0]['old'] ?? null, 'link' => $link, 'nights' => $nights];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
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

    /** Owner re-save / retry: due now, failure counters cleared. Not called when the owner unblocks a night. */
    public static function markFeedPending(int $propertyId, int $feedId): void
    {
        db()->prepare("UPDATE calendar_feeds SET next_sync_at = now(), fail_count = 0, last_error = NULL, last_status = 'PENDENTE'
            WHERE id = ? AND property_id = ?")->execute([$feedId, $propertyId]);
    }

    /**
     * @return array{id: int, property_id: int, url: string, active: bool, prev_due: ?string, last_synced_at: ?string}|null
     */
    public static function claimDueFeed(): ?array
    {
        $stmt = db()->prepare(self::CLAIM_DUE_FEED_SQL);
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'property_id' => (int) $row['property_id'],
            'url' => (string) $row['url'],
            'active' => self::pgBool($row['active']),
            'prev_due' => $row['prev_due'] !== null ? (string) $row['prev_due'] : null,
            'last_synced_at' => $row['last_synced_at'] !== null ? (string) $row['last_synced_at'] : null,
        ];
    }

    /**
     * Diff-import one feed. Unchanged nights are not written. The first holder keeps a night.
     * Null when the feed was removed after the claim (caller rolls nothing else back).
     *
     * @param list<string> $nights
     * @param list<string> $origins
     * @return array{freed: int, written: int}|null
     */
    public static function importFeed(int $propertyId, int $feedId, array $nights, array $origins, string $syncedAt, int $jitterSeconds): ?array
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('SELECT pg_advisory_xact_lock(?, ?)')->execute([self::SYNC_ADVISORY_NS, $propertyId]);
            $check = $pdo->prepare('SELECT 1 FROM calendar_feeds WHERE id = ? AND property_id = ? FOR KEY SHARE');
            $check->execute([$feedId, $propertyId]);
            if ($check->fetchColumn() === false) {
                $pdo->rollBack();
                return null;
            }
            $today = date('Y-m-d');
            $free = $pdo->prepare("UPDATE calendar_days SET blocked = 0, origin = '', feed_id = NULL
                WHERE property_id = ? AND feed_id = ? AND day >= ? AND day <> ALL (CAST(? AS date[]))");
            $free->execute([$propertyId, $feedId, $today, self::pgDateArray($nights)]);
            $freed = $free->rowCount();
            $ins = $pdo->prepare('INSERT INTO calendar_days AS c (property_id, day, blocked, origin, feed_id)
                SELECT ?, n.day, 1, n.origin, ? FROM unnest(CAST(? AS date[]), CAST(? AS text[])) AS n(day, origin)
                LEFT JOIN calendar_days o ON o.property_id = ? AND o.day = n.day
                WHERE o.day IS NULL OR o.blocked = 0 OR (o.feed_id = ? AND o.origin <> n.origin)
                ON CONFLICT (property_id, day) DO UPDATE SET blocked = 1, origin = EXCLUDED.origin, feed_id = EXCLUDED.feed_id
                WHERE c.blocked = 0 OR (c.feed_id = EXCLUDED.feed_id AND c.origin <> EXCLUDED.origin)');
            $ins->execute([
                $propertyId,
                $feedId,
                self::pgDateArray($nights),
                self::pgTextArray($origins),
                $propertyId,
                $feedId,
            ]);
            $written = $ins->rowCount();
            if ($freed > 0) {
                $pdo->prepare("UPDATE calendar_feeds SET next_sync_at = now()
                    WHERE property_id = ? AND id <> ? AND next_sync_at > now() AND next_sync_at < 'infinity'")
                    ->execute([$propertyId, $feedId]);
            }
            $pdo->prepare("UPDATE calendar_feeds SET next_sync_at = now() + interval '30 minutes' + (? * interval '1 second'),
                fail_count = 0, last_error = NULL, last_status = 'OK', last_synced_at = ?
                WHERE id = ?")->execute([$jitterSeconds, $syncedAt, $feedId]);
            $pdo->commit();
            return ['freed' => $freed, 'written' => $written];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array{fail_count: int, stopped: bool}
     */
    public static function recordFeedFailure(int $feedId, string $code, string $syncedAt, int $jitterSeconds): array
    {
        if (in_array($code, self::PERMANENT_FEED_ERRORS, true)) {
            $sql = "UPDATE calendar_feeds SET fail_count = fail_count + 1, last_error = ?, last_status = 'NOK', last_synced_at = ?,
                next_sync_at = 'infinity' WHERE id = ? RETURNING fail_count, next_sync_at = 'infinity' AS stopped";
            $params = [$code, $syncedAt, $feedId];
        } else {
            $sql = "UPDATE calendar_feeds SET fail_count = fail_count + 1, last_error = ?, last_status = 'NOK', last_synced_at = ?,
                next_sync_at = CASE WHEN fail_count + 1 >= " . self::FAIL_STOP . " THEN 'infinity'
                    ELSE now() + interval '30 minutes' * LEAST(power(2, fail_count), 12) + (? * interval '1 second') END
                WHERE id = ? RETURNING fail_count, next_sync_at = 'infinity' AS stopped";
            $params = [$code, $syncedAt, $jitterSeconds, $feedId];
        }
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch() ?: ['fail_count' => 0, 'stopped' => false];
        return ['fail_count' => (int) $row['fail_count'], 'stopped' => self::pgBool($row['stopped'])];
    }

    /** @param list<string> $days */
    private static function pgDateArray(array $days): string
    {
        foreach ($days as $day) {
            if (!is_string($day) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                throw new InvalidArgumentException('day');
            }
        }
        return '{' . implode(',', $days) . '}';
    }

    /** @param list<string> $origins */
    private static function pgTextArray(array $origins): string
    {
        foreach ($origins as $origin) {
            if ($origin !== 'sync' && $origin !== 'reserva') {
                throw new InvalidArgumentException('origin');
            }
        }
        return '{' . implode(',', $origins) . '}';
    }

    private static function pgBool(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === '1' || $value === 1;
    }

    /** @return array{id: int|string, url: string, title: string}|null */
    private static function insertFeedSlot(int $propertyId, string $url, string $title): ?array
    {
        $sql = 'INSERT INTO calendar_feeds (property_id, slot, url, title)
            SELECT ?, s.slot, ?, ?
            FROM (SELECT min(g)::smallint AS slot FROM generate_series(1, ' . self::FEED_SLOTS . ') g
                  WHERE NOT EXISTS (SELECT 1 FROM calendar_feeds f WHERE f.property_id = ? AND f.slot = g)) s
            WHERE s.slot IS NOT NULL
            ON CONFLICT DO NOTHING
            RETURNING id, url, title';
        $rows = ReturningWrite::query($sql, [$propertyId, $url, $title, $propertyId]);
        return $rows[0] ?? null;
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
