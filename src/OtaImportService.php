<?php

declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/LogPrivacy.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/CalendarSync.php';
require_once __DIR__ . '/OtaFeedGate.php';
require_once __DIR__ . '/OtaPlatformRegistry.php';
require_once __DIR__ . '/OtaListingExtractor.php';
require_once __DIR__ . '/HouseRepository.php';
require_once __DIR__ . '/ListingSearch.php';
require_once __DIR__ . '/PhotoRepository.php';
require_once __DIR__ . '/PhotoStorage.php';
require_once __DIR__ . '/ImageQueue.php';
require_once __DIR__ . '/PhotoFail.php';
require_once __DIR__ . '/Acoes/Resultado.php';
require_once __DIR__ . '/Acoes/TextoBruto.php';

/**
 * OTA listing URL content import: creates a private draft (or merges into an existing property),
 * extracts descriptive content, and enqueues CDN photos for the async image worker.
 *
 * This service never proves ownership: only the iCal challenge in calendar_feeds does. The imported
 * content is a convenience, not a substitute for the OTA verification.
 */
final class OtaImportService
{
    public const MAX_UNVERIFIED_DRAFTS = 3;
    public const EXPIRES_DAYS = 14;
    public const MAX_IMPORT_PHOTOS = 20;

    /** Active import statuses counted against the per-user draft cap. */
    private const UNVERIFIED = ['fetching', 'draft_ready', 'rejected'];
    /** Statuses that lock the listing URL (anti-bait-and-switch). */
    private const URL_LOCKED = ['fetching', 'draft_ready', 'rejected'];

    public static ?Closure $clock = null;

    private static function now(): int
    {
        return self::$clock !== null ? (int) (self::$clock)() : OtaVerificationService::now();
    }

    /**
     * New-property entry point: create the private draft plus the import row, then extract.
     *
     * @return Resultado dados: property, import, counts, warning
     */
    public static function startNewDraft(int $userId, mixed $platform, mixed $url, mixed $consent): Resultado
    {
        if (!TextoBruto::ok($platform) || !TextoBruto::ok($url)) {
            return new Resultado('invalido', t('ota.import.invalid'), ['field' => 'property_url']);
        }
        $consentOk = in_array($consent, [true, 1, '1', 'true', 'on'], true);
        if (!$consentOk) {
            return new Resultado('invalido', t('ota.err_consent'), ['field' => 'consent']);
        }
        try {
            $public = OtaFeedGate::preparePublicUrl(0, $userId, (string) $platform, (string) $url, true);
        } catch (OtaFeedGateException $e) {
            return new Resultado('invalido', $e->getMessage(), ['field' => $e->field]);
        }

        // The cap is checked under a user row lock, atomically with draft creation (no TOCTOU).
        $created = Db::transactional(db(), static function () use ($userId, $public): ?array {
            db()->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$userId]);
            if (self::unverifiedDrafts($userId) >= self::MAX_UNVERIFIED_DRAFTS) {
                return null;
            }
            $property = HouseRepository::insertDraft($userId);
            $import = self::insertImport((int) $property['id'], $userId, $public, 'fetching');
            return ['property' => $property, 'import' => $import];
        });
        if ($created === null) {
            return new Resultado('limite', t('ota.import.cap_reached'), ['field' => 'property_url']);
        }

        return self::runImport((int) $created['property']['id'], $userId, (string) $public['platform'], (array) $created['import']);
    }

    /**
     * Existing-property entry point (host edit page / API). Non-destructive: empty fields are filled,
     * non-empty fields keep their content and receive "use imported text" suggestions.
     */
    public static function startExisting(array $property, int $userId, mixed $platform, mixed $url, mixed $consent): Resultado
    {
        if (!TextoBruto::ok($platform) || !TextoBruto::ok($url)) {
            return new Resultado('invalido', t('ota.import.invalid'), ['field' => 'property_url']);
        }
        $consentOk = in_array($consent, [true, 1, '1', 'true', 'on'], true);
        if (!$consentOk) {
            return new Resultado('invalido', t('ota.err_consent'), ['field' => 'consent']);
        }
        $propertyId = (int) $property['id'];
        $status = (string) ($property['status'] ?? 'active');
        $platform = strtolower(trim((string) $platform));
        if ($status === 'inactive') {
            return new Resultado('invalido', t('err.house_missing'), ['field' => 'property_url']);
        }
        $feed = $status === 'active' ? self::verifiedFeedLock($propertyId, $platform) : null;
        try {
            $public = OtaFeedGate::preparePublicUrl(
                $propertyId,
                $userId,
                $platform,
                (string) $url,
                true,
                $feed !== null ? (int) $feed['id'] : 0
            );
        } catch (OtaFeedGateException $e) {
            return new Resultado('invalido', $e->getMessage(), ['field' => $e->field]);
        }
        if ($status === 'active') {
            // Published guard: only after an approved OTA feed, and the import is locked to its URL.
            if ($feed === null) {
                return new Resultado('invalido', t('ota.import.published_locked'), ['field' => 'platform']);
            }
            $lock = self::canonicalFromFeed($feed, $platform);
            if ($lock !== (string) $public['canonical_listing_id']) {
                return new Resultado('invalido', t('ota.err_import_url_locked'), ['field' => 'property_url']);
            }
        }

        $existing = self::activeForProperty($propertyId, (string) $public['platform']);
        if ($existing !== null && (string) $existing['canonical_listing_id'] === (string) $public['canonical_listing_id']) {
            $import = self::touchImport((int) $existing['id'], $public);
        } else {
            $import = self::insertImport($propertyId, $userId, $public, 'fetching');
        }
        return self::runImport($propertyId, $userId, (string) $public['platform'], $import);
    }

    /** @param array<string, mixed> $public */
    private static function insertImport(int $propertyId, int $userId, array $public, string $status): array
    {
        $now = self::now();
        $stmt = db()->prepare(
            "INSERT INTO property_ota_imports
                (property_id, user_id, platform, canonical_listing_id, canonical_property_url,
                 status, extracted_payload, sanitization_flags, consent_at, import_ip, expires_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, '{}'::jsonb, '{}'::jsonb, to_timestamp(?), ?, to_timestamp(?), to_timestamp(?), to_timestamp(?))
             RETURNING *"
        );
        $stmt->execute([
            $propertyId,
            $userId,
            (string) $public['platform'],
            (string) $public['canonical_listing_id'],
            (string) $public['property_url'],
            $status,
            (int) $public['consent_at'],
            $public['verification_ip'] ?? null,
            $now + self::EXPIRES_DAYS * 86400,
            $now,
            $now,
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array<string, mixed> $public */
    private static function touchImport(int $importId, array $public): array
    {
        $now = self::now();
        $stmt = db()->prepare(
            "UPDATE property_ota_imports
                SET status = 'fetching', consent_at = to_timestamp(?), import_ip = ?,
                    expires_at = to_timestamp(?), updated_at = to_timestamp(?)
              WHERE id = ? RETURNING *"
        );
        $stmt->execute([(int) $public['consent_at'], $public['verification_ip'] ?? null, $now + self::EXPIRES_DAYS * 86400, $now, $importId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array<string, mixed> $import */
    private static function runImport(int $propertyId, int $userId, string $platform, array $import): Resultado
    {
        $importId = (int) ($import['id'] ?? 0);
        $property = HouseRepository::find($propertyId);
        if ($property === null || $importId < 1) {
            return new Resultado('invalido', t('err.house_missing'));
        }
        $html = CalendarSync::fetchListingHtml((string) $import['canonical_property_url']);
        if (isset($html['error'])) {
            self::finishImport($importId, 'failed', ['error' => (string) $html['error']], ['fetch' => 'failed']);
            return new Resultado('parcial', t('ota.import.fetch_failed'), [
                'property_id' => $propertyId,
                'public_hash' => HouseRepository::ensurePublicHash($property),
                'import_id' => $importId,
                'warning' => t('ota.import.fetch_failed'),
            ]);
        }
        $payload = OtaListingExtractor::extract((string) $html['body'], $platform);
        $partial = (bool) ($payload['sanitization_flags']['partial'] ?? true);
        $filled = self::mergeIntoProperty($property, $payload);

        $photos = self::enqueuePhotos($propertyId, $importId, $platform, (array) $payload['photos']);
        $payload['imported_fields'] = $filled;
        $payload['suggestions'] = [
            'title' => (string) $payload['title'],
            'description' => (string) $payload['description'],
        ];
        $payload['photos_enqueued'] = $photos['enqueued'];
        $payload['photos_failed'] = $photos['failed'];
        $payload['photos_skipped'] = $photos['skipped'];
        // Partial markup still yields a draft: the host fills the rest. `partial` stays in sanitization_flags.
        self::finishImport($importId, 'draft_ready', $payload, (array) ($payload['sanitization_flags'] ?? []));

        $warning = null;
        if ($partial) {
            $warning = t('ota.import.fetch_failed');
        } elseif ($photos['failed'] > 0) {
            $warning = t('ota.import.photo_fail');
        }
        return new Resultado(
            'ok',
            $warning,
            [
                'property_id' => $propertyId,
                'public_hash' => HouseRepository::ensurePublicHash(HouseRepository::find($propertyId) ?? $property),
                'import_id' => $importId,
                'platform' => $platform,
                'filled' => $filled,
                'photos' => $photos,
                'warning' => $warning,
            ],
            [[ActivityLog::OTA_IMPORT_START, [
                'imovel_id' => $propertyId,
                'plataforma' => $platform,
                'import_id' => $importId,
                'fotos' => $photos['enqueued'],
            ]]]
        );
    }

    /**
     * Non-destructive merge: only empty/placeholder fields are filled. Address, prices and rules are
     * never applied (they stay suggestions in extracted_payload for explicit host confirmation).
     *
     * @return list<string> fields actually filled
     */
    private static function mergeIntoProperty(array $property, array $payload): array
    {
        $id = (int) $property['id'];
        $fields = [];
        $title = (string) ($property['title'] ?? '');
        $description = (string) ($property['description'] ?? '');
        $set = [];

        if ((string) $payload['title'] !== '' && (trim($title) === '' || $title === HouseRepository::DRAFT_PLACEHOLDER_TITLE)) {
            $set['title'] = (string) $payload['title'];
            $fields[] = 'title';
        }
        if ((string) $payload['description'] !== '' && (trim($description) === '' || $description === HouseRepository::DRAFT_PLACEHOLDER_DESCRIPTION)) {
            $set['description'] = (string) $payload['description'];
            $set['summary'] = (string) $payload['summary'];
            $fields[] = 'description';
        }
        if ((int) ($property['max_guests'] ?? 0) <= 1 && (int) ($payload['suggested_guests'] ?? 0) > 1) {
            $set['max_guests'] = (int) $payload['suggested_guests'];
            $fields[] = 'max_guests';
        }
        if ((int) ($property['bedrooms'] ?? 0) <= 1 && (int) ($payload['suggested_bedrooms'] ?? 0) > 1) {
            $set['bedrooms'] = (int) $payload['suggested_bedrooms'];
            $fields[] = 'bedrooms';
        }
        if ((int) ($property['beds'] ?? 0) <= 1 && (int) ($payload['suggested_beds'] ?? 0) > 1) {
            $set['beds'] = (int) $payload['suggested_beds'];
            $fields[] = 'beds';
        }
        if ((string) ($property['checkin_time'] ?? '') === '15:00' && !empty($payload['suggested_checkin'])) {
            $set['checkin_time'] = (string) $payload['suggested_checkin'];
            $fields[] = 'checkin_time';
        }
        if ((string) ($property['checkout_time'] ?? '') === '11:00' && !empty($payload['suggested_checkout'])) {
            $set['checkout_time'] = (string) $payload['suggested_checkout'];
            $fields[] = 'checkout_time';
        }

        $currentAmenities = json_decode((string) ($property['amenities'] ?? '{}'), true);
        $currentAmenities = is_array($currentAmenities) ? $currentAmenities : [];
        $amenities = $currentAmenities;
        foreach ((array) $payload['amenities'] as $key) {
            if (is_string($key) && $key !== '') {
                $amenities[$key] = 1;
            }
        }
        if ($amenities !== $currentAmenities) {
            $set['amenities'] = json_encode($amenities, JSON_UNESCAPED_UNICODE);
            $fields[] = 'amenities';
        }
        foreach (['pool', 'bbq', 'garage'] as $flag) {
            if (in_array($flag, (array) $payload['amenities'], true) && (int) ($property[$flag] ?? 0) === 0) {
                $set[$flag] = 1;
                $fields[] = $flag;
            }
        }

        if ($set !== []) {
            $columns = array_keys($set);
            $sql = 'UPDATE properties SET ' . implode(', ', array_map(static fn (string $c): string => $c . ' = ?', $columns)) . ' WHERE id = ?';
            db()->prepare($sql)->execute([...array_values($set), $id]);
            ListingSearch::sync($id);
        }
        return $fields;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $flags
     */
    private static function finishImport(int $importId, string $status, array $payload, array $flags): void
    {
        $now = self::now();
        db()->prepare(
            'UPDATE property_ota_imports
                SET status = ?, extracted_payload = ?::jsonb, sanitization_flags = ?::jsonb, updated_at = to_timestamp(?)
              WHERE id = ?'
        )->execute([
            $status,
            json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}',
            json_encode($flags, JSON_UNESCAPED_UNICODE) ?: '{}',
            $now,
            $importId,
        ]);
    }

    /**
     * Validates CDN hosts, upgrades to full resolution, dedups URLs and enqueues the image worker.
     *
     * @param list<string> $urls
     * @return array{enqueued:int, failed:int, skipped:int}
     */
    public static function enqueuePhotos(int $propertyId, int $importId, string $platform, array $urls): array
    {
        $limit = class_exists('Settings') ? max(1, Settings::int('max_photos_per_property') ?: 40) : 40;
        $existing = PhotoRepository::count($propertyId);
        $remaining = max(0, min(self::MAX_IMPORT_PHOTOS, $limit - $existing));
        $enqueued = 0;
        $failed = 0;
        $skipped = 0;
        $queue = null;
        $seen = [];
        $primaryNeeded = $existing === 0;
        foreach ($urls as $raw) {
            if ($remaining <= 0) {
                $skipped++;
                continue;
            }
            $url = self::upgradeImageUrl((string) $raw, $platform);
            if ($url === '') {
                $skipped++;
                continue;
            }
            $hash = hash('sha256', $url);
            if (isset($seen[$hash]) || PhotoRepository::sourceUrlHashExists($propertyId, $hash)) {
                $skipped++;
                continue;
            }
            $seen[$hash] = true;
            $uuid = PhotoStorage::randomId();
            $row = PhotoRepository::insertProcessing($propertyId, $uuid, null, $primaryNeeded ? 1 : 0, $existing + $enqueued, [
                'source_url' => $url,
                'source_url_hash' => $hash,
                'ota_import_id' => $importId,
            ]);
            $photoId = (int) ($row['id'] ?? 0);
            if ($photoId < 1) {
                $failed++;
                continue;
            }
            $primaryNeeded = false;
            try {
                $queue ??= (PhotoStorage::$queueFactory ?? ImageQueues::make(...))();
                $queue->push($photoId);
                $enqueued++;
                $remaining--;
            } catch (Throwable $e) {
                error_log('ota_import: image queue push failed for photo #' . $photoId . ' (' . $e::class . ')');
                PhotoRepository::markFailed($photoId, PhotoFail::QUEUE);
                activity(ActivityLog::OTA_IMPORT_PHOTO_FAIL, [
                    'imovel_id' => $propertyId,
                    'import_id' => $importId,
                    'motivo' => PhotoFail::QUEUE,
                ]);
                $failed++;
            }
        }
        return ['enqueued' => $enqueued, 'failed' => $failed, 'skipped' => $skipped];
    }

    /** Exact CDN host only; known resolution query params are dropped so the full-size image is fetched. */
    private static function upgradeImageUrl(string $url, string $platform): string
    {
        $url = trim($url);
        if ($url === '' || CalendarSync::isHttpsUrl($url) === false) {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '';
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!OtaPlatformRegistry::isImageHost($platform, $host)) {
            return '';
        }
        $query = [];
        if (!empty($parts['query'])) {
            parse_str((string) $parts['query'], $query);
            foreach (['im_w', 'im_h', 'aki_policy', 'x', 'y', 'width', 'height', 'w', 'h'] as $drop) {
                unset($query[$drop]);
            }
        }
        $path = (string) ($parts['path'] ?? '/');
        $built = 'https://' . $host . ($path === '' ? '/' : $path);
        if ($query !== []) {
            $built .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        return CalendarSync::isHttpsUrl($built) ? $built : '';
    }

    /** Newest non-discarded/expired import for a property (optionally one platform). */
    public static function activeForProperty(int $propertyId, ?string $platform = null): ?array
    {
        $sql = "SELECT * FROM property_ota_imports
                 WHERE property_id = ? AND status IN ('fetching', 'draft_ready', 'failed', 'verified', 'rejected')";
        $params = [$propertyId];
        if ($platform !== null && $platform !== '') {
            $sql .= ' AND platform = ?';
            $params[] = $platform;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::decodeRow($row) : null;
    }

    /** @return list<array<string, mixed>> */
    public static function importsForProperty(int $propertyId): array
    {
        $stmt = db()->prepare('SELECT * FROM property_ota_imports WHERE property_id = ? ORDER BY id DESC');
        $stmt->execute([$propertyId]);
        return array_map([self::class, 'decodeRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public static function verifiedFeedLock(int $propertyId, string $platform): ?array
    {
        $stmt = db()->prepare(
            "SELECT id, property_url, canonical_listing_id FROM calendar_feeds
              WHERE property_id = ? AND source_platform = ? AND verification_mode = 'ota'
                AND status = 'active' AND verified_at IS NOT NULL AND review_status = 'approved'
              ORDER BY id LIMIT 1"
        );
        $stmt->execute([$propertyId, $platform]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** Canonical listing id of the approved feed that locks the import URL. */
    private static function canonicalFromFeed(array $feed, string $platform): string
    {
        $url = (string) ($feed['property_url'] ?? '');
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        try {
            return OtaFeedGate::canonicalizePublic($platform, '/' . trim($path, '/'));
        } catch (OtaFeedGateException) {
            return (string) ($feed['canonical_listing_id'] ?? '');
        }
    }

    public static function activeUnverified(int $propertyId, ?string $platform = null): ?array
    {
        $sql = "SELECT * FROM property_ota_imports
                 WHERE property_id = ? AND status IN ('fetching', 'draft_ready', 'rejected')";
        $params = [$propertyId];
        if ($platform !== null && $platform !== '') {
            $sql .= ' AND platform = ?';
            $params[] = $platform;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::decodeRow($row) : null;
    }

    /**
     * Newest import a host may discard (unverified statuses plus a failed fetch). Verified imports are
     * never discarded: they follow the feed lifecycle.
     */
    private static function discardable(int $propertyId, ?string $platform = null): ?array
    {
        $sql = "SELECT * FROM property_ota_imports
                 WHERE property_id = ? AND status IN ('fetching', 'draft_ready', 'failed', 'rejected')";
        $params = [$propertyId];
        if ($platform !== null && $platform !== '') {
            $sql .= ' AND platform = ?';
            $params[] = $platform;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::decodeRow($row) : null;
    }

    public static function unverifiedDrafts(int $userId): int
    {
        $marks = implode(', ', array_fill(0, count(self::UNVERIFIED), '?'));
        $stmt = db()->prepare('SELECT COUNT(*) FROM property_ota_imports WHERE user_id = ? AND status IN (' . $marks . ')');
        $stmt->execute([$userId, ...self::UNVERIFIED]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Discards an import: deletes its unverified photos (DB + WebP/original files), clears the draft
     * content and unlocks the URL.
     */
    public static function discard(int $propertyId, int $userId, ?string $platform = null): Resultado
    {
        $existing = self::discardable($propertyId, $platform);
        if ($existing === null) {
            return new Resultado('nao_encontrado', t('ota.import.discard_missing'));
        }
        $importId = (int) $existing['id'];
        $photos = Db::transactional(db(), static function () use ($propertyId, $importId): array {
            $lock = db()->prepare('SELECT * FROM property_ota_imports WHERE id = ? AND property_id = ? FOR UPDATE');
            $lock->execute([$importId, $propertyId]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return [];
            }
            $removed = PhotoRepository::deleteByImport($importId);
            db()->prepare(
                "UPDATE property_ota_imports
                    SET status = 'discarded', calendar_feed_id = NULL, updated_at = to_timestamp(?)
                  WHERE id = ?"
            )->execute([self::now(), $importId]);
            $statusStmt = db()->prepare('SELECT status FROM properties WHERE id = ?');
            $statusStmt->execute([$propertyId]);
            $status = (string) ($statusStmt->fetchColumn() ?: '');
            if ($status === 'draft') {
                db()->prepare(
                    "UPDATE properties
                        SET title = ?, description = ?, summary = 'Rascunho importado',
                            amenities = '{}', pool = 0, bbq = 0, garage = 0,
                            max_guests = 1, bedrooms = 1, beds = 1, nightly_price = 1, cleaning_fee = 0,
                            checkin_time = '15:00', checkout_time = '11:00'
                      WHERE id = ?"
                )->execute([HouseRepository::DRAFT_PLACEHOLDER_TITLE, HouseRepository::DRAFT_PLACEHOLDER_DESCRIPTION, $propertyId]);
                ListingSearch::sync($propertyId);
            }
            return $removed;
        });
        foreach ($photos as $photo) {
            PhotoStorage::deletePhotoFiles($photo);
        }
        return new Resultado(
            'ok',
            t('ota.import.discard_done'),
            [
                'import_id' => $importId,
                'photos' => count($photos),
            ],
            [[ActivityLog::OTA_IMPORT_DISCARD, [
                'imovel_id' => $propertyId,
                'plataforma' => (string) $existing['platform'],
                'import_id' => $importId,
                'fotos' => count($photos),
            ]]]
        );
    }

    /** Publishes a private draft (draft -> active), after the unverified import gate. */
    public static function publish(int $propertyId, int $userId): Resultado
    {
        $property = HouseRepository::findManageable($userId, $propertyId);
        if ($property === null) {
            return new Resultado('nao_encontrado', t('err.house_missing'));
        }
        if ((string) ($property['status'] ?? '') !== 'draft') {
            return new Resultado('invalido', t('ota.import.draft_only'));
        }
        if (self::activeUnverified($propertyId) !== null) {
            return new Resultado('bloqueado', t('ota.import.publish_blocked'));
        }
        Db::transactional(db(), static function () use ($propertyId): void {
            db()->prepare("UPDATE properties SET status = 'active', inactivated_at = NULL WHERE id = ? AND status = 'draft'")->execute([$propertyId]);
            ListingSearch::sync($propertyId);
        });
        return new Resultado(
            'ok',
            t('ota.import.publish_done'),
            ['imovel_id' => $propertyId],
            [[ActivityLog::PROPERTY_PUBLISH, [
                'imovel_id' => $propertyId,
                'titulo' => (string) ($property['title'] ?? ''),
                'estado' => 'active',
            ]]]
        );
    }

    /**
     * Retention sweep: imports past expires_at become 'expired' and their unverified photos are removed.
     *
     * @return array{expired:int, photos:int}
     */
    public static function expireStale(?int $now = null): array
    {
        $now ??= self::now();
        $stmt = db()->prepare(
            "SELECT id, property_id FROM property_ota_imports
              WHERE expires_at <= to_timestamp(?)
                AND status IN ('fetching', 'draft_ready', 'failed', 'rejected')
              ORDER BY id LIMIT 200"
        );
        $stmt->execute([$now]);
        $expired = 0;
        $photosTotal = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $importId = (int) $row['id'];
            $photos = Db::transactional(db(), static function () use ($importId): array {
                $lock = db()->prepare('SELECT id FROM property_ota_imports WHERE id = ? FOR UPDATE');
                $lock->execute([$importId]);
                if ($lock->fetchColumn() === false) {
                    return [];
                }
                $removed = PhotoRepository::deleteByImport($importId);
                db()->prepare("UPDATE property_ota_imports SET status = 'expired', updated_at = to_timestamp(?) WHERE id = ?")
                    ->execute([self::now(), $importId]);
                return $removed;
            });
            foreach ($photos as $photo) {
                PhotoStorage::deletePhotoFiles($photo);
            }
            $expired++;
            $photosTotal += count($photos);
        }
        return ['expired' => $expired, 'photos' => $photosTotal];
    }

    /** @return array<string, mixed> */
    private static function decodeRow(array $row): array
    {
        foreach (['extracted_payload', 'sanitization_flags'] as $key) {
            $decoded = json_decode((string) ($row[$key] ?? ''), true);
            $row[$key] = is_array($decoded) ? $decoded : [];
        }
        $row['id'] = (int) $row['id'];
        $row['property_id'] = (int) $row['property_id'];
        $row['user_id'] = (int) $row['user_id'];
        return $row;
    }
}
