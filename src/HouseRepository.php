<?php

declare(strict_types=1);

require_once __DIR__ . '/ReturningWrite.php';

final class HouseRepository
{
    public static function insert(array $house): int
    {
        return (int) self::insertRow($house)['id'];
    }

    public static function insertRow(array $house): array
    {
        $hash = bin2hex(random_bytes(8));
        $stmt = db()->prepare('INSERT INTO properties (
            user_id, title, description, summary, city, address, cep, street, address_number, address_complement, lat, lng, bedrooms, beds, rooms, max_guests,
            nightly_price, checkin_time, checkout_time, min_nights, calendar_months, cleaning_fee, pool, bbq, garage, amenities, public_hash, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        RETURNING id, title, description, summary, city, address, cep, nightly_price, cleaning_fee, bedrooms, beds, rooms, max_guests, min_nights, calendar_months, checkin_time, checkout_time, pool, bbq, garage, amenities, status');
        $stmt->execute([
            $house['user_id'], $house['title'], $house['description'], $house['summary'], $house['city'],
            $house['address'], $house['cep'] ?? '', $house['street'] ?? '', $house['address_number'] ?? '', $house['address_complement'] ?? '',
            $house['lat'], $house['lng'], $house['bedrooms'], $house['beds'], $house['rooms'] ?? '[]', $house['max_guests'],
            $house['nightly_price'], $house['checkin_time'], $house['checkout_time'], $house['min_nights'],
            self::calendarMonths($house),
            max(0, (int) ($house['cleaning_fee'] ?? 0)),
            $house['pool'], $house['bbq'], $house['garage'], $house['amenities'] ?? '{}', $hash, date('Y-m-d H:i:s'),
        ]);
        return $stmt->fetch() ?: [];
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

    public static function update(int $id, int $userId, array $house): array
    {
        $months = self::calendarMonths($house);
        $fee = max(0, (int) ($house['cleaning_fee'] ?? 0));
        return ReturningWrite::update('properties', [
            'title' => $house['title'],
            'description' => $house['description'],
            'summary' => $house['summary'],
            'city' => $house['city'],
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
            'title', 'description', 'summary', 'city', 'address', 'cep', 'nightly_price', 'cleaning_fee',
            'bedrooms', 'beds', 'rooms', 'max_guests', 'min_nights', 'calendar_months', 'checkin_time', 'checkout_time',
            'pool', 'bbq', 'garage', 'amenities', 'status',
        ]);
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

    public static function updateStayRules(int $id, string $checkin, string $checkout, int $minNights): void
    {
        db()->prepare('UPDATE properties SET checkin_time = ?, checkout_time = ?, min_nights = ? WHERE id = ?')
            ->execute([$checkin, $checkout, $minNights, $id]);
    }

    public static function setExternalCalendar(int $id, int $userId, string $url): void
    {
        db()->prepare('UPDATE properties SET external_ical_url = ? WHERE id = ? AND user_id = ?')
            ->execute([$url, $id, $userId]);
    }

    public static function find(int $id): ?array
    {
        $stmt = db()->prepare('SELECT * FROM properties WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findByUser(int $userId, int $id): ?array
    {
        $stmt = db()->prepare("SELECT * FROM properties WHERE id = ? AND user_id = ? AND COALESCE(NULLIF(status, ''), 'active') = 'active'");
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
        $stmt = db()->prepare('SELECT * FROM properties WHERE public_hash = ?');
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
        $stmt = db()->prepare('SELECT * FROM properties WHERE ical_hash = ?');
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function listByUser(int $userId): array
    {
        $stmt = db()->prepare("SELECT * FROM properties WHERE user_id = ? AND status = 'active' ORDER BY id DESC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public static function all(): array
    {
        return db()->query("SELECT * FROM properties WHERE status = 'active' ORDER BY id ASC")->fetchAll();
    }

    public static function byCity(string $city): array
    {
        $stmt = db()->prepare("SELECT * FROM properties WHERE city = ? AND status = 'active' ORDER BY id ASC");
        $stmt->execute([$city]);
        return $stmt->fetchAll();
    }

    public static function inactivate(int $id, int $userId, string $at): array
    {
        return ReturningWrite::update(
            'properties',
            ['status' => 'inactive', 'inactivated_at' => $at],
            "id = ? AND user_id = ? AND status = 'active'",
            [$id, $userId],
            ['id'],
            ['title', 'description', 'summary', 'city', 'address', 'cep', 'nightly_price', 'cleaning_fee', 'bedrooms', 'beds', 'max_guests', 'min_nights', 'calendar_months', 'checkin_time', 'checkout_time', 'status', 'inactivated_at']
        );
    }

    public static function updateMinNights(int $id, int $minNights): array
    {
        return ReturningWrite::update('properties', ['min_nights' => $minNights], 'id = ?', [$id], ['id'], ['min_nights']);
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

    public static function feeds(int $propertyId): array
    {
        $stmt = db()->prepare('SELECT * FROM calendar_feeds WHERE property_id = ? ORDER BY id');
        $stmt->execute([$propertyId]);
        return $stmt->fetchAll();
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
        $json = (string) db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'json_build_object' : 'json_object';
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
        db()->prepare('INSERT INTO reviews (property_id, author, score, comment, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$propertyId, $author, $score, $comment, date('Y-m-d H:i:s')]);
    }

    public static function averageScore(int $propertyId): ?float
    {
        $stmt = db()->prepare('SELECT AVG(score) FROM reviews WHERE property_id = ?');
        $stmt->execute([$propertyId]);
        $avg = $stmt->fetchColumn();
        return $avg === null ? null : round((float) $avg, 1);
    }

    public static function averageScores(array $ids): array
    {
        $scores = [];
        $ids = array_values(array_unique(array_map('intval', $ids)));
        foreach (array_chunk($ids, 400) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = db()->prepare("SELECT property_id, AVG(score) AS avg_score FROM reviews WHERE property_id IN ($placeholders) GROUP BY property_id");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll() as $row) {
                $scores[(int) $row['property_id']] = round((float) $row['avg_score'], 1);
            }
        }
        return $scores;
    }

    public static function reviewCounts(array $ids): array
    {
        $counts = [];
        $ids = array_values(array_unique(array_map('intval', $ids)));
        foreach (array_chunk($ids, 400) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = db()->prepare("SELECT property_id, COUNT(*) AS total FROM reviews WHERE property_id IN ($placeholders) GROUP BY property_id");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll() as $row) {
                $counts[(int) $row['property_id']] = (int) $row['total'];
            }
        }
        return $counts;
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
        $driver = (string) db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        $series = $driver === 'pgsql'
            ? "WITH RECURSIVE days(day) AS (SELECT CAST(? AS text) UNION ALL SELECT to_char(CAST(day AS date) + INTERVAL '1 day', 'YYYY-MM-DD') FROM days WHERE day < CAST(? AS text))"
            : "WITH RECURSIVE days(day) AS (SELECT ? UNION ALL SELECT date(day, '+1 day') FROM days WHERE day < ?)";
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
}
