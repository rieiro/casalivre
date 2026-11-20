<?php

declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/I18n.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/helpers.php';

/**
 * Per-platform iCal export links for one property.
 *
 * Each link has its own 64-hex token and target platform. The export merges calendar_days into
 * contiguous VEVENT ranges with generic summaries (no guest PII). When imported events are
 * enabled, rows coming from the target platform are excluded to prevent 3-way OTA echo loops.
 */
final class IcalExportService
{
    public const PLATFORMS = ['airbnb', 'booking', 'vrbo', 'cleaner', 'other'];
    public const OTA_PLATFORMS = ['airbnb', 'booking', 'vrbo'];

    /** Test hook: fn(): int unix timestamp. Null uses time(). */
    public static ?Closure $clock = null;

    /** @return array{settings: array{ical_export_manual_blocks: bool, ical_export_imported_events: bool}, exports: list<array<string, mixed>>} */
    public static function listForProperty(int $propertyId): array
    {
        $stmt = db()->prepare(
            'SELECT ical_export_manual_blocks, ical_export_imported_events FROM properties WHERE id = ?'
        );
        $stmt->execute([$propertyId]);
        $prop = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $exports = db()->prepare(
            'SELECT id, target_platform, label, token, last_fetched_at, created_at
               FROM property_ical_exports
              WHERE property_id = ? AND revoked_at IS NULL
              ORDER BY created_at DESC, id DESC'
        );
        $exports->execute([$propertyId]);
        $rows = [];
        foreach ($exports->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $lastFetched = $row['last_fetched_at'] === null ? null : (string) $row['last_fetched_at'];
            [$label, $stale] = self::lastFetchedLabel($lastFetched);
            $rows[] = [
                'id' => (int) $row['id'],
                'target_platform' => (string) $row['target_platform'],
                'label' => $row['label'] === null ? null : (string) $row['label'],
                'token' => (string) $row['token'],
                'last_fetched_at' => $lastFetched,
                'last_fetched_label' => $label,
                'stale' => $stale,
                'created_at' => (string) $row['created_at'],
            ];
        }
        return [
            'settings' => [
                'ical_export_manual_blocks' => (int) ($prop['ical_export_manual_blocks'] ?? 1) === 1,
                'ical_export_imported_events' => (int) ($prop['ical_export_imported_events'] ?? 0) === 1,
            ],
            'exports' => $rows,
        ];
    }

    /** @return array{ical_export_manual_blocks: bool, ical_export_imported_events: bool} */
    public static function updateSettings(int $propertyId, int $userId, bool $manualBlocks, bool $importedEvents): array
    {
        return Db::transactional(db(), static function () use ($propertyId, $userId, $manualBlocks, $importedEvents): array {
            self::assertOwner($propertyId, $userId, true);
            $stmt = db()->prepare(
                'SELECT ical_export_manual_blocks, ical_export_imported_events
                   FROM properties WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([$propertyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $old = [
                'ical_export_manual_blocks' => (int) ($row['ical_export_manual_blocks'] ?? 1),
                'ical_export_imported_events' => (int) ($row['ical_export_imported_events'] ?? 0),
            ];
            $new = [
                'ical_export_manual_blocks' => $manualBlocks ? 1 : 0,
                'ical_export_imported_events' => $importedEvents ? 1 : 0,
            ];
            if ($old !== $new) {
                db()->prepare(
                    'UPDATE properties SET ical_export_manual_blocks = ?, ical_export_imported_events = ? WHERE id = ?'
                )->execute([$new['ical_export_manual_blocks'], $new['ical_export_imported_events'], $propertyId]);
                ActivityLog::record(
                    ActivityLog::ICAL_EXPORT_SETTINGS,
                    [
                        'imovel_id' => $propertyId,
                        'ical_export_manual_blocks' => $new['ical_export_manual_blocks'],
                        'ical_export_imported_events' => $new['ical_export_imported_events'],
                    ],
                    $old,
                    $new
                );
            }
            return [
                'ical_export_manual_blocks' => $new['ical_export_manual_blocks'] === 1,
                'ical_export_imported_events' => $new['ical_export_imported_events'] === 1,
            ];
        });
    }

    /** @return array{id: int, target_platform: string, label: ?string, token: string} */
    public static function createExport(int $propertyId, int $userId, string $targetPlatform, ?string $label = null): array
    {
        self::assertOwner($propertyId, $userId);
        $targetPlatform = strtolower(trim($targetPlatform));
        if (!in_array($targetPlatform, self::PLATFORMS, true)) {
            throw new InvalidArgumentException('Plataforma de exportação inválida.');
        }
        $label = $label === null ? null : trim($label);
        if ($label === '') {
            $label = null;
        }
        if ($label !== null) {
            $len = function_exists('mb_strlen') ? mb_strlen($label) : strlen($label);
            if ($len > 60) {
                throw new InvalidArgumentException('O rótulo deve ter no máximo 60 caracteres.');
            }
        }

        $token = bin2hex(random_bytes(32));
        $stmt = db()->prepare(
            'INSERT INTO property_ical_exports (property_id, target_platform, label, token)
             VALUES (?, ?, ?, ?) RETURNING id'
        );
        $stmt->execute([$propertyId, $targetPlatform, $label, $token]);
        $exportId = (int) $stmt->fetchColumn();

        ActivityLog::record(ActivityLog::ICAL_EXPORT_CREATE, [
            'imovel_id' => $propertyId,
            'plataforma' => $targetPlatform,
            'label' => (string) ($label ?? ''),
        ]);

        return [
            'id' => $exportId,
            'target_platform' => $targetPlatform,
            'label' => $label,
            'token' => $token,
        ];
    }

    public static function revokeExport(int $propertyId, int $userId, int $exportId): bool
    {
        self::assertOwner($propertyId, $userId);
        $stmt = db()->prepare(
            'UPDATE property_ical_exports
                SET revoked_at = to_timestamp(?)
              WHERE id = ? AND property_id = ? AND revoked_at IS NULL
              RETURNING target_platform'
        );
        $stmt->execute([self::now(), $exportId, $propertyId]);
        $platform = $stmt->fetchColumn();
        if ($platform === false) {
            return false;
        }
        ActivityLog::record(ActivityLog::ICAL_EXPORT_REVOKE, [
            'imovel_id' => $propertyId,
            'export_id' => $exportId,
            'plataforma' => (string) $platform,
        ]);
        return true;
    }

    /**
     * Days for one export link, honoring the property toggles and echo-loop prevention.
     *
     * @param array<string, mixed> $exportRow
     * @param array<string, mixed> $property
     * @return list<array{day: string, summary: string}>
     */
    public static function exportDaysForToken(array $exportRow, array $property): array
    {
        $propertyId = (int) $property['id'];
        $target = (string) ($exportRow['target_platform'] ?? 'other');
        $manual = (int) ($property['ical_export_manual_blocks'] ?? 1) === 1;
        $imported = (int) ($property['ical_export_imported_events'] ?? 0) === 1;
        $excludeTarget = $imported && in_array($target, self::OTA_PLATFORMS, true);

        $sql = 'SELECT d.day, d.origin, d.feed_id
                  FROM calendar_days d
                  LEFT JOIN calendar_feeds f ON f.id = d.feed_id
                 WHERE d.property_id = ?
                   AND d.blocked = 1
                   AND d.day >= ?
                   AND COALESCE(d.origin, \'\') <> \'pre-reserva\'
                   AND (
                        (d.feed_id IS NULL AND d.origin = \'reserva\')
                        OR (? = 1 AND d.feed_id IS NULL AND COALESCE(d.origin, \'\') IN (\'\', \'sistema\'))
                        OR (? = 1 AND d.feed_id IS NOT NULL
                            AND (? = 0 OR f.source_platform IS NULL OR f.source_platform <> ?))
                   )
                 ORDER BY d.day';
        $stmt = db()->prepare($sql);
        $stmt->execute([
            $propertyId,
            gmdate('Y-m-d', self::now()),
            $manual ? 1 : 0,
            $imported ? 1 : 0,
            $excludeTarget ? 1 : 0,
            $target,
        ]);

        $days = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $kind = (string) ($row['origin'] ?? '');
            $source = $row['feed_id'] === null ? 'manual' : 'imported';
            if ($row['feed_id'] === null && $kind === 'reserva') {
                $summary = 'Reservado - CasaLivre';
            } else {
                $summary = 'Indisponível';
            }
            $days[] = ['day' => (string) $row['day'], 'summary' => $summary];
        }
        return $days;
    }

    /**
     * @return array{ics: string, property: array<string, mixed>, export: array<string, mixed>}|null
     */
    public static function renderIcsForToken(string $token): ?array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            return null;
        }
        $stmt = db()->prepare(
            'SELECT e.id AS export_id, e.target_platform, e.label, e.token, e.last_fetched_at, p.*
               FROM property_ical_exports e
               JOIN properties p ON p.id = e.property_id
              WHERE e.token = ? AND e.revoked_at IS NULL AND p.status = \'active\'
              LIMIT 1'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $now = self::now();
        db()->prepare('UPDATE property_ical_exports SET last_fetched_at = to_timestamp(?) WHERE id = ?')
            ->execute([$now, (int) $row['export_id']]);
        ActivityLog::record(ActivityLog::CALENDAR_EXPORT, ['imovel_id' => (int) $row['id']]);

        $export = [
            'id' => (int) $row['export_id'],
            'target_platform' => (string) $row['target_platform'],
            'label' => $row['label'] === null ? null : (string) $row['label'],
            'token' => (string) $row['token'],
        ];
        $days = self::exportDaysForToken($export, $row);
        return [
            'ics' => self::buildIcs($row, $days),
            'property' => $row,
            'export' => $export,
        ];
    }

    /**
     * @param array<string, mixed> $property
     * @param list<array{day: string, summary: string}> $days
     */
    public static function buildIcs(array $property, array $days): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//CasaLivre//Exportacao//PT',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . ics_text((string) ($property['title'] ?? 'CasaLivre')),
        ];
        $ranges = [];
        foreach ($days as $day) {
            $start = (string) $day['day'];
            $summary = (string) $day['summary'];
            $last = $ranges === [] ? null : $ranges[count($ranges) - 1];
            $end = $last === null ? null : (new DateTimeImmutable($last['end'], new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
            if ($last !== null && $last['summary'] === $summary && $end === $start) {
                $ranges[count($ranges) - 1]['end'] = $start;
                continue;
            }
            $ranges[] = ['start' => $start, 'end' => $start, 'summary' => $summary];
        }
        $stamp = gmdate('Ymd\THis\Z', self::now());
        foreach ($ranges as $range) {
            $start = new DateTimeImmutable($range['start'], new DateTimeZone('UTC'));
            $end = (new DateTimeImmutable($range['end'], new DateTimeZone('UTC')))->modify('+1 day');
            $startYmd = $start->format('Ymd');
            $endYmd = $end->format('Ymd');
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:casalivre-' . (int) ($property['id'] ?? 0) . '-' . $startYmd . '-' . $endYmd . '@casalivre.local';
            $lines[] = 'DTSTAMP:' . $stamp;
            $lines[] = 'DTSTART;VALUE=DATE:' . $startYmd;
            $lines[] = 'DTEND;VALUE=DATE:' . $endYmd;
            $lines[] = 'SUMMARY:' . ics_text($range['summary']);
            $lines[] = 'STATUS:CONFIRMED';
            $lines[] = 'TRANSP:OPAQUE';
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", $lines) . "\r\n";
    }

    /** @return array{0: string, 1: bool} */
    private static function lastFetchedLabel(?string $value): array
    {
        if ($value === null || $value === '') {
            return [t('ical.never_accessed'), false];
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return [t('ical.never_accessed'), false];
        }
        $seconds = max(0, self::now() - $ts);
        if ($seconds > 86400) {
            return [t('ical.stale'), true];
        }
        if ($seconds < 3600) {
            return [sprintf(t('ical.accessed_minutes'), max(1, intdiv($seconds, 60))), false];
        }
        if ($seconds < 86400) {
            return [sprintf(t('ical.accessed_hours'), intdiv($seconds, 3600)), false];
        }
        return [sprintf(t('ical.accessed_days'), intdiv($seconds, 86400)), false];
    }

    private static function assertOwner(int $propertyId, int $userId, bool $forUpdate = false): void
    {
        $sql = 'SELECT 1 FROM properties
                 WHERE id = ? AND (user_id = ? OR group_id IN (
                     SELECT group_id FROM group_members WHERE user_id = ? AND role = \'gestor\'))'
            . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = db()->prepare($sql);
        $stmt->execute([$propertyId, $userId, $userId]);
        if ($stmt->fetchColumn() === false) {
            throw new InvalidArgumentException('Imóvel não encontrado.');
        }
    }

    private static function now(): int
    {
        return self::$clock !== null ? (int) (self::$clock)() : time();
    }
}
