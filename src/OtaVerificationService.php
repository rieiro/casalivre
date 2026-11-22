<?php

declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/I18n.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/CalendarSync.php';

/**
 * OTA listing ownership verification (iCal challenge-response).
 *
 * The host proves control of an external listing by blocking one future date in the OTA
 * calendar export. A unified pre-save fraud gate validates the URLs, ownership, daily rate,
 * global iCal uniqueness and the live calendar before anything is persisted; the challenge
 * date prefers the 14-16 month horizon and falls back to 3-6 and 1-4 months. The iCal URL is
 * encrypted at rest and a permanent credit ledger prevents farming by deleting rows.
 */
final class OtaVerificationService
{
    public const CHALLENGE_TTL_SECONDS = 172800; // 48 hours
    public const CHECK_COOLDOWN_SECONDS = 60;
    public const FETCH_TIMEOUT_SECONDS = 8;
    public const MAX_ICS_BYTES = 2097152; // 2 MB
    public const MAX_DATE_PICK_ATTEMPTS = 10;
    public const ONBOARDING_CREDIT_REWARD = 5;
    public const MAX_DAILY_CHALLENGES_PER_USER = 5;
    public const MAX_CHECK_ATTEMPTS = 15;
    public const PASSIVE_DOWNGRADE_FAIL_DAYS = 3;
    /** Retention of the daily-rate log; the 5/day cap only reads the last 24h. */
    public const CHALLENGE_ATTEMPTS_RETENTION_DAYS = 7;

    /** Test hook: fn(string $url, int $timeout): string. Null uses the SSRF-safe cURL transport. */
    public static ?Closure $httpTransport = null;
    /** Test hook: fn(): int unix timestamp. Null uses time(). */
    public static ?Closure $clock = null;
    /** Test hook: fn(int $min, int $max): int. Null uses random_int(). */
    public static ?Closure $randomInt = null;
    /** Test hook: fn(int $propertyId, int $userId, int $verificationId): void. */
    public static ?Closure $creditHook = null;

    /** @var array<string, string> */
    private const PLATFORM_LABELS = [
        'airbnb' => 'Airbnb',
        'booking' => 'Booking.com',
        'vrbo' => 'Vrbo',
    ];

    /** @var array<string, list<string>> */
    private const PUBLIC_HOSTS = [
        'airbnb' => ['www.airbnb.com', 'airbnb.com', 'www.airbnb.com.br', 'airbnb.com.br'],
        'booking' => ['www.booking.com', 'booking.com'],
        'vrbo' => ['www.vrbo.com', 'vrbo.com', 'www.aluguetemporada.com.br', 'aluguetemporada.com.br'],
    ];

    /** @var array<string, list<string>> */
    private const ICAL_HOSTS = [
        'airbnb' => ['www.airbnb.com', 'airbnb.com', 'www.airbnb.com.br', 'airbnb.com.br'],
        'booking' => ['ical.booking.com', 'admin.booking.com', 'www.booking.com'],
        'vrbo' => ['www.vrbo.com', 'vrbo.com', 'www.aluguetemporada.com.br', 'aluguetemporada.com.br'],
    ];

    /** Legacy URL-only validation (kept for callers/tests): hygiene + allowlist + canonical path. */
    public static function validateUrls(string $platform, string $publicUrl, string $icalUrl): void
    {
        if (!isset(self::PLATFORM_LABELS[$platform])) {
            throw new InvalidArgumentException('Plataforma de verificação não suportada.');
        }
        self::validateUrlHygiene($publicUrl);
        self::validateUrlHygiene($icalUrl);
        $publicHost = strtolower((string) (parse_url($publicUrl, PHP_URL_HOST) ?? ''));
        $icalHost = strtolower((string) (parse_url($icalUrl, PHP_URL_HOST) ?? ''));
        if (!in_array($publicHost, self::PUBLIC_HOSTS[$platform], true)
            || !in_array($icalHost, self::ICAL_HOSTS[$platform], true)
        ) {
            throw new InvalidArgumentException('O domínio informado não pertence à plataforma selecionada.');
        }
        self::canonicalizeListingUrl($platform, $publicUrl, $icalUrl);
    }

    /**
     * Unified pre-save fraud gate. Nothing is persisted until every stage passes.
     *
     * @return array<string, mixed>
     */
    public static function validateAndCreateChallenge(
        int $propertyId,
        int $userId,
        string $platform,
        string $publicUrl,
        string $icalUrl,
        bool $consent = true
    ): array {
        // ---------------------------------------------------------- Stage 1: input hygiene
        if (!$consent) {
            throw new InvalidArgumentException(t('ota.err_consent'));
        }
        $platform = strtolower(trim($platform));
        if (!isset(self::PLATFORM_LABELS[$platform])) {
            throw new InvalidArgumentException('Plataforma de verificação não suportada.');
        }
        $publicUrlRaw = trim($publicUrl);
        $icalUrlRaw = trim($icalUrl);
        self::validateUrlHygiene($publicUrlRaw);
        self::validateUrlHygiene($icalUrlRaw);
        $publicHost = strtolower((string) (parse_url($publicUrlRaw, PHP_URL_HOST) ?? ''));
        $icalHost = strtolower((string) (parse_url($icalUrlRaw, PHP_URL_HOST) ?? ''));
        if (!in_array($publicHost, self::PUBLIC_HOSTS[$platform], true)
            || !in_array($icalHost, self::ICAL_HOSTS[$platform], true)
        ) {
            throw new InvalidArgumentException('O domínio informado não pertence à plataforma selecionada.');
        }
        $canonicalListingId = self::canonicalizeListingUrl($platform, $publicUrlRaw, $icalUrlRaw);
        $publicUrl = self::normalizeUrl($publicUrlRaw, true);
        $icalUrl = self::normalizeUrl($icalUrlRaw);
        $icalUrlHash = hash('sha256', $icalUrl);
        $now = self::now();

        // ---------------------------------------------------------- Stage 2: DB integrity
        self::assertPropertyOwner($propertyId, $userId);

        $locked = db()->prepare(
            "SELECT 1 FROM property_external_verifications
              WHERE property_id = ? AND platform = ? AND status = 'verified' LIMIT 1"
        );
        $locked->execute([$propertyId, $platform]);
        if ($locked->fetchColumn() !== false) {
            throw new InvalidArgumentException('Este anúncio já está verificado e bloqueado para alterações.');
        }

        $attempts = db()->prepare(
            'SELECT count(*) FROM ota_challenge_attempts
              WHERE user_id = ? AND created_at > to_timestamp(?)'
        );
        $attempts->execute([$userId, $now - 86400]);
        if ((int) $attempts->fetchColumn() >= self::MAX_DAILY_CHALLENGES_PER_USER) {
            throw new InvalidArgumentException(t('ota.err_daily_limit'));
        }

        $sameHash = db()->prepare(
            'SELECT 1 FROM property_external_verifications
              WHERE ical_url_hash = ? AND NOT (property_id = ? AND platform = ?) LIMIT 1'
        );
        $sameHash->execute([$icalUrlHash, $propertyId, $platform]);
        if ($sameHash->fetchColumn() !== false) {
            throw new InvalidArgumentException('Este calendário iCal já está cadastrado ou verificado em outro imóvel.');
        }

        self::assertCanonicalAvailable($propertyId, $userId, $canonicalListingId, $platform);

        // ---------------------------------------------------------- Stage 3: live feed
        $ics = self::fetchIcsFeed($icalUrl);
        $excluded = db()->prepare(
            "SELECT challenge_date FROM property_external_verifications
              WHERE property_id = ? AND platform <> ? AND status = 'pending_challenge'
                AND challenge_date IS NOT NULL"
        );
        $excluded->execute([$propertyId, $platform]);
        $excludedDates = array_map('strval', $excluded->fetchAll(PDO::FETCH_COLUMN));
        $challengeDate = self::pickOpenDate($ics, $excludedDates);
        $icsMetadata = self::extractIcsMetadata($ics);

        // ---------------------------------------------------------- Stage 4: commit
        $encryptedIcalUrl = self::encryptIcalUrl($icalUrl);
        $verificationId = Db::transactional(db(), static function () use (
            $userId,
            $propertyId,
            $platform,
            $publicUrl,
            $encryptedIcalUrl,
            $icalUrlHash,
            $canonicalListingId,
            $icsMetadata,
            $challengeDate,
            $now
        ): int {
            // TOCTOU guard: serialize challenge creation per user and re-check the daily cap.
            db()->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$userId]);
            $attempts = db()->prepare(
                'SELECT count(*) FROM ota_challenge_attempts
                  WHERE user_id = ? AND created_at > to_timestamp(?)'
            );
            $attempts->execute([$userId, $now - 86400]);
            if ((int) $attempts->fetchColumn() >= self::MAX_DAILY_CHALLENGES_PER_USER) {
                throw new InvalidArgumentException(t('ota.err_daily_limit'));
            }
            self::assertCanonicalAvailable($propertyId, $userId, $canonicalListingId, $platform);

            db()->prepare(
                'INSERT INTO ota_challenge_attempts (user_id, property_id, platform, created_at)
                 VALUES (?, ?, ?, to_timestamp(?))'
            )->execute([$userId, $propertyId, $platform, $now]);

            $stmt = db()->prepare(
                'INSERT INTO property_external_verifications
                    (property_id, platform, public_url, ical_url, status, challenge_date,
                     challenge_expires_at, last_checked_at, attempts, verified_at, credits_awarded,
                     canonical_listing_id, ical_url_hash, ics_metadata, fail_count,
                     last_passive_check_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, \'pending_challenge\', ?, to_timestamp(?), NULL, 0, NULL, 0,
                         ?, ?, ?, 0, NULL, to_timestamp(?), to_timestamp(?))
                 ON CONFLICT (property_id, platform) DO UPDATE SET
                    public_url = EXCLUDED.public_url,
                    ical_url = EXCLUDED.ical_url,
                    status = \'pending_challenge\',
                    challenge_date = EXCLUDED.challenge_date,
                    challenge_expires_at = EXCLUDED.challenge_expires_at,
                    last_checked_at = NULL,
                    attempts = 0,
                    verified_at = NULL,
                    canonical_listing_id = EXCLUDED.canonical_listing_id,
                    ical_url_hash = EXCLUDED.ical_url_hash,
                    ics_metadata = EXCLUDED.ics_metadata,
                    fail_count = 0,
                    last_passive_check_at = NULL,
                    updated_at = EXCLUDED.updated_at
                 WHERE property_external_verifications.status <> \'verified\'
                 RETURNING id'
            );
            $stmt->execute([
                $propertyId,
                $platform,
                $publicUrl,
                $encryptedIcalUrl,
                $challengeDate,
                $now + self::CHALLENGE_TTL_SECONDS,
                $canonicalListingId,
                $icalUrlHash,
                $icsMetadata,
                $now,
                $now,
            ]);
            $verificationId = $stmt->fetchColumn();
            if ($verificationId === false) {
                throw new InvalidArgumentException('Este anúncio já está verificado e bloqueado para alterações.');
            }
            return (int) $verificationId;
        });

        ActivityLog::record(ActivityLog::OTA_VERIFY_START, [
            'imovel_id' => $propertyId,
            'plataforma' => $platform,
            'url' => CalendarSync::logRef($icalUrl),
            'challenge_date' => $challengeDate,
        ]);

        return self::challengeResult($verificationId, $platform, $challengeDate, $now);
    }

    /** Starts/restarts a challenge for a property; the owner is derived from the property. */
    public static function startChallenge(int $propertyId, string $platform, string $publicUrl, string $icalUrl): array
    {
        $owner = db()->prepare('SELECT user_id FROM properties WHERE id = ?');
        $owner->execute([$propertyId]);
        $userId = (int) $owner->fetchColumn();
        if ($userId <= 0) {
            throw new InvalidArgumentException('Imóvel não encontrado.');
        }
        return self::validateAndCreateChallenge($propertyId, $userId, $platform, $publicUrl, $icalUrl, true);
    }

    /** Legacy facade: adding now means creating the pending challenge (fraud gate included). */
    public static function addChallenge(int $propertyId, string $platform, string $publicUrl, string $icalUrl): array
    {
        $result = self::startChallenge($propertyId, $platform, $publicUrl, $icalUrl);
        $result['id'] = (int) $result['verification_id'];
        return $result;
    }

    /** Regenerates the challenge for an already saved row. */
    public static function generateChallenge(int $verificationId): array
    {
        $row = self::findRow($verificationId);
        if ($row === null) {
            throw new InvalidArgumentException('Verificação não encontrada.');
        }
        if ((string) $row['status'] === 'verified') {
            throw new InvalidArgumentException('Este anúncio já está verificado e bloqueado para alterações.');
        }
        $icalUrl = self::decryptIcalUrl((string) $row['ical_url']);
        if ($icalUrl === '') {
            throw new InvalidArgumentException('Não foi possível ler a URL do calendário informado.');
        }
        return self::startChallenge(
            (int) $row['property_id'],
            (string) $row['platform'],
            (string) $row['public_url'],
            $icalUrl
        );
    }

    /** Removes an unverified/pending row; verified rows are read-only and the ledger is permanent. */
    public static function removeChallenge(int $verificationId, int $propertyId): bool
    {
        $row = self::findForProperty($verificationId, $propertyId);
        if ($row === null) {
            return false;
        }
        if ((string) $row['status'] === 'verified') {
            throw new InvalidArgumentException('Este anúncio já está verificado e bloqueado para alterações.');
        }
        $stmt = db()->prepare('DELETE FROM property_external_verifications WHERE id = ? AND property_id = ?');
        $stmt->execute([$verificationId, $propertyId]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        ActivityLog::record(ActivityLog::OTA_VERIFY_REMOVE, [
            'imovel_id' => $propertyId,
            'plataforma' => (string) $row['platform'],
        ]);
        return true;
    }

    /**
     * Checks the challenge. Only an isolated manual block (D-1 and D+1 open, no guest
     * reservation) verifies the listing. The credit ledger makes the reward permanent.
     *
     * @return array<string, mixed>
     */
    public static function checkChallenge(int $verificationId, bool $bypassCooldown = false, ?int $propertyId = null): array
    {
        $row = self::findRow($verificationId);
        if ($row === null || ($propertyId !== null && (int) $row['property_id'] !== $propertyId)) {
            throw new InvalidArgumentException('Verificação não encontrada.');
        }
        $now = self::now();

        if ((string) $row['status'] === 'verified') {
            return self::verifiedResult($row, (int) $row['credits_awarded'], true);
        }

        $expires = strtotime((string) $row['challenge_expires_at']);
        if ((string) $row['status'] !== 'pending_challenge'
            || $expires === false
            || $expires <= $now
            || (int) $row['attempts'] >= self::MAX_CHECK_ATTEMPTS
        ) {
            db()->prepare(
                "UPDATE property_external_verifications
                    SET status = 'failed', updated_at = to_timestamp(?)
                  WHERE id = ?"
            )->execute([$now, $verificationId]);
            return [
                'status' => 'expired',
                'verification_id' => $verificationId,
                'message' => 'O desafio expirou ou atingiu o limite de 15 tentativas. Inicie uma nova verificação.',
            ];
        }

        if (!$bypassCooldown && $row['last_checked_at'] !== null) {
            $last = strtotime((string) $row['last_checked_at']);
            if ($last !== false) {
                $elapsed = $now - $last;
                if ($elapsed < self::CHECK_COOLDOWN_SECONDS) {
                    $retry = self::CHECK_COOLDOWN_SECONDS - $elapsed;
                    return [
                        'status' => 'cooldown',
                        'retry_after' => $retry,
                        'message' => sprintf('Aguarde %d segundos antes de verificar novamente.', $retry),
                    ];
                }
            }
        }

        db()->prepare(
            'UPDATE property_external_verifications
                SET last_checked_at = to_timestamp(?), updated_at = to_timestamp(?)
              WHERE id = ?'
        )->execute([$now, $now, $verificationId]);

        $icalUrl = self::decryptIcalUrl((string) $row['ical_url']);
        $ics = self::fetchIcsFeed($icalUrl);
        $challengeDate = (string) $row['challenge_date'];
        $verdict = self::isIsolatedManualBlockInIcs($ics, $challengeDate);

        if (!$verdict['blocked']) {
            db()->prepare(
                'UPDATE property_external_verifications
                    SET attempts = attempts + 1, last_checked_at = to_timestamp(?), updated_at = to_timestamp(?)
                  WHERE id = ?'
            )->execute([$now, $now, $verificationId]);
            $dateBr = self::dateBr($challengeDate);
            return [
                'status' => 'not_yet_reflected',
                'verification_id' => $verificationId,
                'attempts' => (int) $row['attempts'] + 1,
                'retry_after' => self::CHECK_COOLDOWN_SECONDS,
                'message' => sprintf(
                    'O bloqueio do dia %s ainda não apareceu no feed iCal. As plataformas (Airbnb, Booking.com, Vrbo) costumam levar de 1 a 5 minutos para atualizar o cache do calendário. Aguarde 60 segundos e tente novamente.',
                    $dateBr
                ),
            ];
        }

        if (!$verdict['isolated'] || $verdict['is_guest_booking']) {
            db()->prepare(
                'UPDATE property_external_verifications
                    SET attempts = attempts + 1, last_checked_at = to_timestamp(?), updated_at = to_timestamp(?)
                  WHERE id = ?'
            )->execute([$now, $now, $verificationId]);
            return [
                'status' => 'not_isolated_block',
                'verification_id' => $verificationId,
                'attempts' => (int) $row['attempts'] + 1,
                'retry_after' => self::CHECK_COOLDOWN_SECONDS,
                'message' => sprintf(t('ota.err_not_isolated'), self::dateBr($challengeDate)),
            ];
        }

        return Db::transactional(db(), static function () use ($verificationId, $now): array {
            $lock = db()->prepare('SELECT * FROM property_external_verifications WHERE id = ? FOR UPDATE');
            $lock->execute([$verificationId]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new InvalidArgumentException('Verificação não encontrada.');
            }
            if ((string) $row['status'] === 'verified') {
                return self::verifiedResult($row, (int) $row['credits_awarded'], true);
            }

            $owner = db()->prepare('SELECT user_id FROM properties WHERE id = ?');
            $owner->execute([(int) $row['property_id']]);
            $userId = (int) $owner->fetchColumn();

            $canonicalId = (string) ($row['canonical_listing_id'] ?? '');
            if ($canonicalId !== '') {
                $conflict = db()->prepare(
                    "SELECT 1 FROM property_external_verifications pev
                       JOIN properties p ON p.id = pev.property_id
                      WHERE pev.canonical_listing_id = ? AND pev.property_id <> ?
                        AND p.user_id <> ? AND pev.status = 'verified'
                      LIMIT 1"
                );
                $conflict->execute([$canonicalId, (int) $row['property_id'], $userId]);
                if ($conflict->fetchColumn() !== false) {
                    throw new InvalidArgumentException('Este anúncio já está verificado ou vinculado por outro anfitrião.');
                }
            }

            $ledger = db()->prepare(
                'INSERT INTO ota_credit_ledger
                    (property_id, user_id, verification_id, canonical_listing_id, credits, awarded_at)
                 VALUES (?, ?, ?, ?, ?, to_timestamp(?))
                 ON CONFLICT DO NOTHING'
            );
            $ledger->execute([
                (int) $row['property_id'],
                $userId,
                $verificationId,
                $canonicalId !== '' ? $canonicalId : null,
                self::ONBOARDING_CREDIT_REWARD,
                $now,
            ]);
            $awardNow = $ledger->rowCount() === 1 && (int) $row['credits_awarded'] === 0;

            db()->prepare(
                "UPDATE property_external_verifications
                    SET status = 'verified', verified_at = to_timestamp(?),
                        credits_awarded = 1, fail_count = 0, updated_at = to_timestamp(?)
                  WHERE id = ?"
            )->execute([$now, $now, $verificationId]);

            if ($awardNow && $userId > 0) {
                db()->prepare('UPDATE users SET quote_credits = quote_credits + ? WHERE id = ?')
                    ->execute([self::ONBOARDING_CREDIT_REWARD, $userId]);
                if (self::$creditHook !== null) {
                    (self::$creditHook)((int) $row['property_id'], $userId, $verificationId);
                }
            }

            ActivityLog::record(ActivityLog::OTA_VERIFY_VERIFIED, [
                'imovel_id' => (int) $row['property_id'],
                'plataforma' => (string) $row['platform'],
                'data' => (string) $row['challenge_date'],
                'creditos' => $awardNow ? self::ONBOARDING_CREDIT_REWARD : 0,
            ]);

            $fresh = self::findRow($verificationId) ?? $row;
            return self::verifiedResult($fresh, $awardNow ? 1 : 0, false);
        });
    }

    /**
     * Passive daily check of a verified row: 3 consecutive 401/403/404/410 responses downgrade it.
     *
     * @return array<string, mixed>
     */
    public static function passiveVerifyRow(int $verificationId): array
    {
        $row = self::findRow($verificationId);
        if ($row === null) {
            return ['status' => 'not_found'];
        }
        if ((string) $row['status'] !== 'verified') {
            return ['status' => 'skipped'];
        }
        $now = self::now();
        $last = $row['last_passive_check_at'] === null ? null : strtotime((string) $row['last_passive_check_at']);
        if ($last !== null && $last !== false && $last > $now - 86400) {
            return ['status' => 'not_due'];
        }

        $icalUrl = self::decryptIcalUrl((string) $row['ical_url']);
        try {
            self::fetchIcsFeed($icalUrl);
        } catch (RuntimeException $e) {
            $code = (int) $e->getCode();
            if (!in_array($code, [401, 403, 404, 410], true)) {
                db()->prepare(
                    'UPDATE property_external_verifications
                        SET last_passive_check_at = to_timestamp(?), updated_at = to_timestamp(?)
                      WHERE id = ?'
                )->execute([$now, $now, $verificationId]);
                return ['status' => 'error', 'message' => $e->getMessage()];
            }
            $failCount = (int) $row['fail_count'] + 1;
            if ($failCount >= self::PASSIVE_DOWNGRADE_FAIL_DAYS) {
                db()->prepare(
                    "UPDATE property_external_verifications
                        SET status = 'unverified', verified_at = NULL, fail_count = ?,
                            last_passive_check_at = to_timestamp(?), updated_at = to_timestamp(?)
                      WHERE id = ?"
                )->execute([$failCount, $now, $now, $verificationId]);
                ActivityLog::record(ActivityLog::OTA_VERIFY_DOWNGRADED, [
                    'imovel_id' => (int) $row['property_id'],
                    'plataforma' => (string) $row['platform'],
                    'falhas' => $failCount,
                ]);
                return ['status' => 'downgraded', 'fail_count' => $failCount];
            }
            db()->prepare(
                'UPDATE property_external_verifications
                    SET fail_count = ?, last_passive_check_at = to_timestamp(?), updated_at = to_timestamp(?)
                  WHERE id = ?'
            )->execute([$failCount, $now, $now, $verificationId]);
            return ['status' => 'failed', 'fail_count' => $failCount, 'downgraded' => false];
        }

        db()->prepare(
            'UPDATE property_external_verifications
                SET fail_count = 0, last_passive_check_at = to_timestamp(?), updated_at = to_timestamp(?)
              WHERE id = ?'
        )->execute([$now, $now, $verificationId]);
        return ['status' => 'ok', 'fail_count' => 0];
    }

    /** Deletes challenge-rate rows older than the retention window (keeps the 24h cap intact). */
    public static function purgeOldChallengeAttempts(): int
    {
        $stmt = db()->prepare('DELETE FROM ota_challenge_attempts WHERE created_at < to_timestamp(?)');
        $stmt->execute([self::now() - self::CHALLENGE_ATTEMPTS_RETENTION_DAYS * 86400]);
        return $stmt->rowCount();
    }

    /** @return list<array<string, mixed>> */
    public static function listForProperty(int $propertyId): array
    {
        $stmt = db()->prepare(
            'SELECT * FROM property_external_verifications WHERE property_id = ? ORDER BY updated_at DESC, id DESC'
        );
        $stmt->execute([$propertyId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'platform' => (string) $row['platform'],
                'platform_label' => self::PLATFORM_LABELS[(string) $row['platform']] ?? (string) $row['platform'],
                'public_url' => (string) $row['public_url'],
                'ical_url' => self::maskUrl(self::decryptIcalUrl((string) ($row['ical_url'] ?? ''))),
                'status' => (string) $row['status'],
                'challenge_date' => $row['challenge_date'] === null ? null : (string) $row['challenge_date'],
                'challenge_date_br' => $row['challenge_date'] === null ? null : self::dateBr((string) $row['challenge_date']),
                'challenge_expires_at' => $row['challenge_expires_at'] === null ? null : (string) $row['challenge_expires_at'],
                'last_checked_at' => $row['last_checked_at'] === null ? null : (string) $row['last_checked_at'],
                'last_checked_at_br' => $row['last_checked_at'] === null ? null : self::dateTimeBr((string) $row['last_checked_at']),
                'attempts' => (int) $row['attempts'],
                'verified_at' => $row['verified_at'] === null ? null : (string) $row['verified_at'],
                'credits_awarded' => (int) $row['credits_awarded'],
                'ics_metadata' => $row['ics_metadata'] === null ? null : (string) $row['ics_metadata'],
                'fail_count' => (int) $row['fail_count'],
            ];
        }
        return $out;
    }

    /**
     * Public, token-free view of the verified listings of a property.
     *
     * @return list<array{platform: string, platform_label: string, title: string, verified_at: string}>
     */
    public static function listVerifiedForProperty(int $propertyId): array
    {
        $stmt = db()->prepare(
            "SELECT source_platform, title, verified_at
               FROM calendar_feeds
              WHERE property_id = ? AND verification_mode = 'ota'
                AND verified_at IS NOT NULL AND review_status = 'approved'
              ORDER BY verified_at DESC, id DESC"
        );
        $stmt->execute([$propertyId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $platform = (string) ($row['source_platform'] ?? 'other');
            $out[] = [
                'platform' => $platform,
                'platform_label' => self::PLATFORM_LABELS[$platform] ?? $platform,
                'title' => (string) ($row['title'] ?? ''),
                'verified_at' => (string) $row['verified_at'],
            ];
        }
        return $out;
    }

    public static function hasVerifiedPlatform(int $propertyId): bool
    {
        $stmt = db()->prepare(
            "SELECT 1 FROM calendar_feeds
              WHERE property_id = ? AND verification_mode = 'ota'
                AND verified_at IS NOT NULL AND review_status = 'approved'
              LIMIT 1"
        );
        $stmt->execute([$propertyId]);
        return $stmt->fetchColumn() !== false;
    }

    /** Verification row restricted to a property (used by the API ownership check). */
    public static function findForProperty(int $verificationId, int $propertyId): ?array
    {
        $stmt = db()->prepare('SELECT * FROM property_external_verifications WHERE id = ? AND property_id = ?');
        $stmt->execute([$verificationId, $propertyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** Extracts the Airbnb listing id from /rooms/{id}, /h/{id} or /calendar/ical/{id}.ics. */
    public static function extractAirbnbListingId(string $url, bool $ical = false): ?string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        if ($ical) {
            return preg_match('#^/calendar/ical/(\d+)\.ics$#', $path, $m) === 1 ? $m[1] : null;
        }
        if (preg_match('#^/rooms/(\d+)(?:/|$)#', $path, $m) === 1) {
            return $m[1];
        }
        return preg_match('#/(?:rooms|h)/(\d+)#', $path, $m) === 1 ? $m[1] : null;
    }

    /** Canonical listing id per platform; rejects profiles, searches and affiliate links. */
    public static function canonicalizeListingUrl(string $platform, string $publicUrl, string $icalUrl): string
    {
        $publicPath = (string) (parse_url($publicUrl, PHP_URL_PATH) ?? '');
        $icalPath = (string) (parse_url($icalUrl, PHP_URL_PATH) ?? '');
        $icalQuery = (string) (parse_url($icalUrl, PHP_URL_QUERY) ?? '');

        if ($platform === 'airbnb') {
            if (preg_match('#\A/(?:rooms|h)/(\d+)(?:/|\z)#', $publicPath, $pub) !== 1
                || preg_match('#\A/calendar/ical/(\d+)\.ics\z#', $icalPath, $ical) !== 1
            ) {
                throw new InvalidArgumentException('Informe o link direto de um anúncio do Airbnb e o link de exportação .ics.');
            }
            if ($pub[1] !== $ical[1]) {
                throw new InvalidArgumentException('O ID do anúncio no link público do Airbnb não corresponde ao ID do calendário iCal.');
            }
            return 'airbnb:' . $pub[1];
        }

        if ($platform === 'booking') {
            $icalOk = (preg_match('#\A/v1/export\z#i', $icalPath) === 1 && $icalQuery !== '')
                || preg_match('#\.ics\z#i', $icalPath) === 1;
            if (preg_match('#\A/hotel/([a-z]{2})/([a-z0-9][a-z0-9_-]*)(?:\.[a-z]{2}(?:-[a-z]{2})?)?\.html\z#i', $publicPath, $pub) !== 1
                || !$icalOk
            ) {
                throw new InvalidArgumentException('Informe o link direto de um anúncio do Booking.com e o link de exportação do calendário.');
            }
            return 'booking:' . strtolower($pub[1] . '/' . $pub[2]);
        }

        if ($platform === 'vrbo') {
            if (preg_match('~\A/(?:pt-br/)?(?:imovel/[^/?#]+/|p)?(\d+)[a-z0-9_-]*(?:/|\z)~i', $publicPath, $pub) !== 1
                || preg_match('#\.ics\z#i', $icalPath) !== 1
            ) {
                throw new InvalidArgumentException('Informe o link direto de um anúncio do Vrbo e o link de exportação .ics.');
            }
            return 'vrbo:' . $pub[1];
        }

        throw new InvalidArgumentException('Plataforma de verificação não suportada.');
    }

    /** @return array{blocked: bool, isolated: bool, is_guest_booking: bool} */
    public static function isIsolatedManualBlockInIcs(string $icsContent, string $targetDateYmd): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDateYmd) !== 1) {
            return ['blocked' => false, 'isolated' => false, 'is_guest_booking' => false];
        }
        $target = str_replace('-', '', $targetDateYmd);
        $events = self::events($icsContent);
        $cover = null;
        foreach ($events as $event) {
            if ($target >= $event['start'] && $target < $event['end']) {
                $cover = $event;
                break;
            }
        }
        if ($cover === null) {
            return ['blocked' => false, 'isolated' => false, 'is_guest_booking' => false];
        }
        $dt = DateTimeImmutable::createFromFormat('!Ymd', $target, new DateTimeZone('UTC'));
        $prev = $dt === false ? '' : $dt->modify('-1 day')->format('Ymd');
        $next = $dt === false ? '' : $dt->modify('+1 day')->format('Ymd');
        $prevBlocked = false;
        $nextBlocked = false;
        foreach ($events as $event) {
            if ($prev !== '' && $prev >= $event['start'] && $prev < $event['end']) {
                $prevBlocked = true;
            }
            if ($next !== '' && $next >= $event['start'] && $next < $event['end']) {
                $nextBlocked = true;
            }
        }
        $summary = (string) ($cover['summary'] ?? '');
        return [
            'blocked' => true,
            'isolated' => !$prevBlocked && !$nextBlocked,
            'is_guest_booking' => preg_match('/\A\s*(?:Reserved|Reservado)(?:\s*\([^)]*\))?\s*\z/i', $summary) === 1,
        ];
    }

    /** Extracts PRODID / X-WR-CALNAME for admin sanity (never the raw .ics; max 200 chars). */
    public static function extractIcsMetadata(string $ics): ?string
    {
        $unfolded = (string) preg_replace("/\r?\n[ \t]/", '', $ics);
        $parts = [];
        foreach (['PRODID', 'X-WR-CALNAME'] as $key) {
            if (preg_match('/^' . preg_quote($key, '/') . ':(.*)$/mi', $unfolded, $m) === 1) {
                $value = trim((string) preg_replace('/[\x00-\x1f\x7f]/', '', $m[1]));
                if ($value !== '') {
                    $parts[] = $key . ':' . $value;
                }
            }
        }
        $out = implode(' | ', $parts);
        if ($out === '') {
            return null;
        }
        return function_exists('mb_substr') ? mb_substr($out, 0, 200) : substr($out, 0, 200);
    }

    /** AES-256-GCM at rest; delegates to the shared CalendarSync implementation. */
    public static function encryptIcalUrl(string $url): string
    {
        return CalendarSync::encryptUrl($url);
    }

    public static function decryptIcalUrl(?string $value): string
    {
        return CalendarSync::decryptUrl($value);
    }

    /** Downloads the private .ics feed with no redirects and a public-IP-only transport. */
    public static function fetchIcsFeed(string $icalUrl): string
    {
        if (function_exists('exported_calendar_body')) {
            $local = exported_calendar_body($icalUrl);
            if (is_string($local) && $local !== '') {
                return self::acceptFeed($local);
            }
        }

        // Standalone harness: deterministic bodies for .example hosts; real SSRF guards still apply elsewhere.
        $fixture = getenv('T_ICS_BODY_FILE');
        $testing = strtolower(trim((string) getenv('APP_ENV'))) === 'testing';
        if ($testing && is_string($fixture) && $fixture !== '' && is_file($fixture)) {
            $host = strtolower((string) (parse_url($icalUrl, PHP_URL_HOST) ?? ''));
            $bare = trim($host, '[]');
            $isIp = filter_var($bare, FILTER_VALIDATE_IP) !== false;
            $isLocal = $host === 'localhost' || str_ends_with($host, '.localhost');
            // Testing only: deterministic bodies for public hosts; literal IPs still hit the SSRF guard.
            if (!$isIp && !$isLocal) {
                $body = file_get_contents($fixture);
                if (is_string($body) && $body !== '') {
                    return self::acceptFeed($body);
                }
            }
        }
        if (self::$httpTransport !== null) {
            try {
                $body = (string) (self::$httpTransport)($icalUrl, self::FETCH_TIMEOUT_SECONDS);
            } catch (RuntimeException $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new RuntimeException('Não foi possível baixar o calendário iCal informado.', 0, $e);
            }
            return self::acceptFeed($body);
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('Não foi possível baixar o calendário iCal informado.');
        }

        $target = CalendarSync::resolveTarget($icalUrl);
        if (isset($target['error'])) {
            throw new RuntimeException(self::fetchError((string) $target['error']));
        }
        $result = self::request($icalUrl, $target);
        if (isset($result['error'])) {
            throw new RuntimeException(self::fetchError((string) $result['error']));
        }
        [$code, $body] = $result;
        if ($code >= 300 && $code < 400) {
            throw new RuntimeException(
                'O calendário iCal não pode redirecionar. Use o link direto de exportação da plataforma.',
                $code
            );
        }
        if ($code !== 200) {
            throw new RuntimeException('O calendário iCal respondeu com erro HTTP ' . $code . '.', $code);
        }
        return self::acceptFeed($body);
    }

    /** True when $targetDateYmd is covered by a non-cancelled, opaque VEVENT. DTEND is exclusive. */
    public static function isDateBlockedInIcs(string $icsContent, string $targetDateYmd): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDateYmd) !== 1) {
            return false;
        }
        $target = str_replace('-', '', $targetDateYmd);
        foreach (self::events($icsContent) as $event) {
            if ($target >= $event['start'] && $target < $event['end']) {
                return true;
            }
        }
        return false;
    }

    /** @return list<array{start: string, end: string, summary: ?string}> */
    private static function events(string $icsContent): array
    {
        $unfolded = (string) preg_replace("/\r?\n[ \t]/", '', $icsContent);
        if (preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/is', $unfolded, $matches) === false) {
            return [];
        }
        $out = [];
        foreach ($matches[1] as $block) {
            if (preg_match('/^STATUS\s*:\s*CANCELLED\s*$/im', $block) === 1
                || preg_match('/^TRANSP\s*:\s*TRANSPARENT\s*$/im', $block) === 1
            ) {
                continue;
            }
            if (preg_match('/DTSTART(?:;[^:\r\n]*)?:(\d{8})(?:T\d{6}Z?)?/i', $block, $startMatch) !== 1) {
                continue;
            }
            $startYmd = $startMatch[1];
            $startDt = DateTimeImmutable::createFromFormat('!Ymd', $startYmd, new DateTimeZone('UTC'));
            if ($startDt === false || $startDt->format('Ymd') !== $startYmd) {
                continue;
            }
            $endYmd = $startYmd;
            if (preg_match('/DTEND(?:;[^:\r\n]*)?:(\d{8})(?:T\d{6}Z?)?/i', $block, $endMatch) === 1) {
                $candidate = $endMatch[1];
                $endDt = DateTimeImmutable::createFromFormat('!Ymd', $candidate, new DateTimeZone('UTC'));
                if ($endDt !== false && $endDt->format('Ymd') === $candidate) {
                    $endYmd = $candidate;
                }
            }
            if ($endYmd === $startYmd) {
                $endYmd = $startDt->modify('+1 day')->format('Ymd');
            }
            if ($endYmd <= $startYmd) {
                continue;
            }
            $summary = preg_match('/^SUMMARY:(.*)$/mi', $block, $sm) === 1 ? trim($sm[1]) : null;
            $out[] = ['start' => $startYmd, 'end' => $endYmd, 'summary' => $summary];
        }
        return $out;
    }

    /** Shared canonical URL helper (CalendarSync). */
    public static function normalizeUrl(string $url, bool $stripQuery = false): string
    {
        return CalendarSync::normalizeUrl($url, $stripQuery);
    }

    /** Masks the query string (iCal exports carry private tokens). */
    public static function maskUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return '?';
        }
        $out = (string) ($parts['scheme'] ?? 'https') . '://' . (string) $parts['host'];
        if (isset($parts['port'])) {
            $out .= ':' . (int) $parts['port'];
        }
        $out .= (string) ($parts['path'] ?? '');
        if (isset($parts['query']) && $parts['query'] !== '') {
            $out .= '?***';
        }
        return $out;
    }

    // ------------------------------------------------------------------ helpers

    /** Public wrapper used by OtaFeedGate and UI validators. */
    public static function assertUrlHygiene(string $url): void
    {
        self::validateUrlHygiene($url);
    }

    /** Public wrapper for the unified feed gate (the legacy method stays private). */
    public static function pickOpenDateForFeed(string $ics, array $excludedDates = []): string
    {
        return self::pickOpenDate($ics, $excludedDates);
    }

    private static function validateUrlHygiene(string $url): void
    {
        if ($url === ''
            || strlen($url) > CalendarSync::MAX_URL_LENGTH
            || preg_match('/[\s\x00-\x1f\x7f]/', $url) === 1
        ) {
            throw new InvalidArgumentException('Informe URLs https:// válidas para o anúncio e para o calendário iCal.');
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
        ) {
            throw new InvalidArgumentException('Informe URLs https:// válidas para o anúncio e para o calendário iCal.');
        }
        $host = strtolower((string) $parts['host']);
        if (preg_match('/\A[a-z0-9.-]+\z/i', $host) !== 1 || str_contains($host, '..')) {
            throw new InvalidArgumentException('Informe URLs https:// válidas para o anúncio e para o calendário iCal.');
        }
    }

    private static function assertPropertyOwner(int $propertyId, int $userId): void
    {
        $stmt = db()->prepare('SELECT user_id, status FROM properties WHERE id = ?');
        $stmt->execute([$propertyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)
            || ($row['status'] ?? 'active') !== 'active'
            || (int) $row['user_id'] !== $userId
        ) {
            throw new InvalidArgumentException('Imóvel não encontrado.');
        }
    }

    /** @param list<string> $excludedDates */
    private static function pickOpenDate(string $ics, array $excludedDates): string
    {
        $base = (new DateTimeImmutable('@' . self::now()))->setTimezone(new DateTimeZone('UTC'));
        foreach ([[14, 16], [3, 6], [1, 4]] as [$minMonths, $maxMonths]) {
            $minDate = $base->modify('+' . $minMonths . ' months');
            $maxDate = $base->modify('+' . $maxMonths . ' months');
            $totalDays = (int) $minDate->diff($maxDate)->days;
            for ($attempt = 0; $attempt < self::MAX_DATE_PICK_ATTEMPTS; $attempt++) {
                $offset = self::randomOffset(0, max(0, $totalDays));
                $candidate = $minDate->modify('+' . $offset . ' days')->format('Y-m-d');
                if (self::isCandidateDateEligible($ics, $candidate, $excludedDates)) {
                    return $candidate;
                }
            }
            // Mostly-booked calendars: scan the whole window deterministically before falling back.
            for ($offset = 0; $offset <= max(0, $totalDays); $offset++) {
                $candidate = $minDate->modify('+' . $offset . ' days')->format('Y-m-d');
                if (self::isCandidateDateEligible($ics, $candidate, $excludedDates)) {
                    return $candidate;
                }
            }
        }
        throw new RuntimeException(t('ota.err_no_open_dates'));
    }

    /** @param list<string> $excludedDates */
    private static function isCandidateDateEligible(string $ics, string $candidateYmd, array $excludedDates): bool
    {
        if (in_array($candidateYmd, $excludedDates, true)) {
            return false;
        }
        $dt = new DateTimeImmutable($candidateYmd . ' 00:00:00', new DateTimeZone('UTC'));
        return !self::isDateBlockedInIcs($ics, $dt->modify('-1 day')->format('Y-m-d'))
            && !self::isDateBlockedInIcs($ics, $candidateYmd)
            && !self::isDateBlockedInIcs($ics, $dt->modify('+7 days')->format('Y-m-d'));
    }

    private static function assertCanonicalAvailable(int $propertyId, int $userId, string $canonicalListingId, string $platform): void
    {
        $canonical = db()->prepare(
            'SELECT pev.property_id, p.user_id
               FROM property_external_verifications pev
               JOIN properties p ON p.id = pev.property_id
              WHERE pev.canonical_listing_id = ? AND pev.property_id <> ?'
        );
        $canonical->execute([$canonicalListingId, $propertyId]);
        foreach ($canonical->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int) $row['user_id'] !== $userId) {
                throw new InvalidArgumentException('Este anúncio já está verificado ou vinculado por outro anfitrião.');
            }
            if ($platform !== 'booking') {
                throw new InvalidArgumentException('Este anúncio já está verificado em outro imóvel.');
            }
        }
    }

    /** @param array<string, mixed> $row */
    private static function verifiedResult(array $row, int $creditsAwarded, bool $already): array
    {
        $dateBr = self::dateBr((string) $row['challenge_date']);
        return [
            'status' => 'verified',
            'verification_id' => (int) $row['id'],
            'platform' => (string) $row['platform'],
            'challenge_date' => (string) $row['challenge_date'],
            'challenge_date_br' => $dateBr,
            'verified_at' => (string) $row['verified_at'],
            'credits_awarded' => $already ? $creditsAwarded : ($creditsAwarded === 1),
            'badge' => 'Anúncio Verificado em Plataformas Parceiras',
            'message' => $already
                ? 'Este anúncio já está verificado.'
                : sprintf(
                    'Verificação concluída! Selo "Anúncio Verificado em Plataformas Parceiras" desbloqueado. Você já pode desbloquear o dia %s no calendário da plataforma.',
                    $dateBr
                ),
        ];
    }

    /** @return array<string, mixed> */
    private static function challengeResult(int $verificationId, string $platform, string $challengeDate, int $now): array
    {
        $dateBr = self::dateBr($challengeDate);
        return [
            'verification_id' => $verificationId,
            'platform' => $platform,
            'status' => 'pending_challenge',
            'challenge_date' => $challengeDate,
            'challenge_date_br' => $dateBr,
            'challenge_expires_at' => gmdate('Y-m-d H:i:s', $now + self::CHALLENGE_TTL_SECONDS),
            'message' => sprintf(
                'Bloqueie apenas o dia %s no seu calendário do %s e clique em Verificar agora.',
                $dateBr,
                self::PLATFORM_LABELS[$platform]
            ),
        ];
    }

    private static function dateBr(string $ymd): string
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new DateTimeZone('UTC'));
        return $dt === false ? $ymd : $dt->format('d/m/Y');
    }

    private static function dateTimeBr(string $value): string
    {
        $ts = strtotime($value);
        return $ts === false ? $value : gmdate('d/m/Y H:i', $ts);
    }

    /** @return array<string, mixed>|null */
    private static function findRow(int $verificationId): ?array
    {
        $stmt = db()->prepare('SELECT * FROM property_external_verifications WHERE id = ?');
        $stmt->execute([$verificationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private static function acceptFeed(string $body): string
    {
        if (strlen($body) > self::MAX_ICS_BYTES) {
            throw new RuntimeException('O calendário iCal excedeu o tamanho máximo permitido.');
        }
        $clean = ltrim($body, "\xEF\xBB\xBF \t\r\n");
        if (!str_starts_with($clean, 'BEGIN:VCALENDAR')) {
            throw new RuntimeException('O calendário iCal informado não é válido.');
        }
        return $clean;
    }

    private static function fetchError(string $error): string
    {
        return match ($error) {
            'private_ip' => 'O endereço informado não é público. Informe a URL de exportação da própria plataforma.',
            'too_big' => 'O calendário iCal excedeu o tamanho máximo permitido.',
            'not_ics' => 'O calendário iCal informado não é válido.',
            'dns' => 'Não foi possível resolver o endereço do calendário iCal.',
            'timeout' => 'O calendário iCal demorou demais para responder. Tente novamente.',
            default => 'Não foi possível baixar o calendário iCal informado. Verifique a URL e tente novamente.',
        };
    }

    private static function icalKey(): string
    {
        Db::loadEnv();
        $salt = (string) getenv('LOG_SALT') . '|' . (string) getenv('GEO_SALT') . '|casalivre-ota-ical-v1';
        return hash('sha256', $salt, true);
    }

    public static function now(): int
    {
        return self::$clock !== null ? (int) (self::$clock)() : time();
    }

    /**
     * Fetches a feed and picks an open future challenge date (add-feed flow).
     *
     * @return array{ics: string, challenge_date: string}
     */
    public static function prepareFeedChallenge(string $icalUrl): array
    {
        $ics = self::fetchIcsFeed($icalUrl);
        return ['ics' => $ics, 'challenge_date' => self::pickOpenDate($ics, [])];
    }

    /** Awards the permanent onboarding credits for a verified feed (ledger idempotent). */
    public static function awardFeedCredits(int $propertyId, int $feedId, ?string $canonicalListingId = null): bool
    {
        $owner = db()->prepare('SELECT user_id FROM properties WHERE id = ?');
        $owner->execute([$propertyId]);
        $userId = (int) $owner->fetchColumn();
        $ledger = db()->prepare(
            'INSERT INTO ota_credit_ledger
                (property_id, user_id, verification_id, canonical_listing_id, credits, awarded_at)
             VALUES (?, ?, ?, ?, ?, to_timestamp(?))
             ON CONFLICT DO NOTHING'
        );
        $ledger->execute([
            $propertyId,
            $userId,
            $feedId,
            $canonicalListingId,
            self::ONBOARDING_CREDIT_REWARD,
            self::now(),
        ]);
        if ($ledger->rowCount() !== 1) {
            return false;
        }
        if ($userId > 0) {
            db()->prepare('UPDATE users SET quote_credits = quote_credits + ? WHERE id = ?')
                ->execute([self::ONBOARDING_CREDIT_REWARD, $userId]);
            if (self::$creditHook !== null) {
                (self::$creditHook)($propertyId, $userId, $feedId);
            }
        }
        return true;
    }

    /** @return list<array<string, mixed>> */
    public static function listPendingReviews(): array
    {
        $stmt = db()->query(
            "SELECT f.id, f.property_id, f.title, f.url, f.source_platform, f.property_url,
                    f.canonical_listing_id, f.ics_metadata, f.verified_at, f.challenge_date,
                    p.title AS property_title, p.public_hash, u.name AS user_name, u.email
               FROM calendar_feeds f
               JOIN properties p ON p.id = f.property_id
               JOIN users u ON u.id = p.user_id
              WHERE f.verification_mode = 'ota' AND f.verified_at IS NOT NULL
                AND f.review_status = 'pending_review'
              ORDER BY f.verified_at ASC, f.id ASC"
        );
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $decrypted = CalendarSync::decryptUrl((string) $row['url']);
            $host = parse_url($decrypted, PHP_URL_HOST);
            $row['ical_url_masked'] = is_string($host) && $host !== ''
                ? 'https://' . strtolower($host) . '/••••'
                : '••••';
            unset($row['url']);
            $rows[] = $row;
        }
        return $rows;
    }

    /** @return array<string, mixed> */
    public static function approveFeed(int $feedId, int $adminId): array
    {
        return Db::transactional(db(), static function () use ($feedId, $adminId): array {
            $lock = db()->prepare('SELECT * FROM calendar_feeds WHERE id = ? FOR UPDATE');
            $lock->execute([$feedId]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || (string) ($row['verification_mode'] ?? 'sync_only') !== 'ota') {
                return ['status' => 'not_found', 'message' => t('cal.feed_missing')];
            }
            if ((string) ($row['review_status'] ?? '') === 'approved') {
                return ['status' => 'already_approved', 'message' => t('cal.review_approved')];
            }
            $now = self::now();
            $awarded = self::awardFeedCredits(
                (int) $row['property_id'],
                $feedId,
                $row['canonical_listing_id'] !== null ? (string) $row['canonical_listing_id'] : null
            );
            db()->prepare(
                "UPDATE calendar_feeds
                    SET review_status = 'approved', reviewed_by = ?, reviewed_at = to_timestamp(?),
                        review_note = NULL, status = 'active', verified_at = COALESCE(verified_at, to_timestamp(?)),
                        credits_awarded = CASE WHEN ? THEN TRUE ELSE credits_awarded END
                  WHERE id = ?"
            )->execute([$adminId, $now, $now, $awarded ? 1 : 0, $feedId]);
            ActivityLog::record(ActivityLog::OTA_REVIEW_APPROVE, [
                'imovel_id' => (int) $row['property_id'],
                'plataforma' => (string) ($row['source_platform'] ?? 'other'),
                'feed_id' => $feedId,
            ]);
            return ['status' => 'approved', 'credits_awarded' => $awarded, 'message' => t('cal.review_approved')];
        });
    }

    /** @return array<string, mixed> */
    public static function rejectFeed(int $feedId, int $adminId, string $note): array
    {
        return Db::transactional(db(), static function () use ($feedId, $adminId, $note): array {
            $lock = db()->prepare('SELECT * FROM calendar_feeds WHERE id = ? FOR UPDATE');
            $lock->execute([$feedId]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || (string) ($row['verification_mode'] ?? 'sync_only') !== 'ota') {
                return ['status' => 'not_found', 'message' => t('cal.feed_missing')];
            }
            $note = function_exists('mb_substr') ? mb_substr(trim($note), 0, 500) : substr(trim($note), 0, 500);
            db()->prepare(
                "UPDATE calendar_feeds
                    SET review_status = 'rejected', reviewed_by = ?, reviewed_at = to_timestamp(?),
                        review_note = ?, status = 'error', verified_at = NULL, credits_awarded = FALSE
                  WHERE id = ?"
            )->execute([$adminId, self::now(), $note, $feedId]);
            ActivityLog::record(ActivityLog::OTA_REVIEW_REJECT, [
                'imovel_id' => (int) $row['property_id'],
                'plataforma' => (string) ($row['source_platform'] ?? 'other'),
                'feed_id' => $feedId,
            ]);
            return ['status' => 'rejected', 'message' => t('cal.review_rejected')];
        });
    }

    private static function randomOffset(int $min, int $max): int
    {
        return self::$randomInt !== null ? (int) (self::$randomInt)($min, $max) : random_int($min, $max);
    }

    /**
     * SSRF-safe request pinned to the already-validated IP, no redirect following.
     *
     * @param array{host: string, port: int, ip: string} $target
     * @return array{0: int, 1: string}|array{error: string, http: int}
     */
    private static function request(string $url, array $target): array
    {
        $ip = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];
        $buffer = '';
        $tooBig = false;
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . $ip],
            CURLOPT_TIMEOUT => self::FETCH_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'CasaLivre/1.0',
            CURLOPT_HTTPHEADER => [
                'Accept: text/calendar, text/plain, */*',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
            ],
            CURLOPT_NOPROXY => '*',
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffer, &$tooBig): int {
                if (strlen($buffer) + strlen($chunk) > self::MAX_ICS_BYTES) {
                    $tooBig = true;
                    return 0;
                }
                $buffer .= $chunk;
                return strlen($chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'https';
        } elseif (defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $primaryIp = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        if ($tooBig) {
            return ['error' => 'too_big', 'http' => $code];
        }
        if ($ok === false) {
            if ($errno === CURLE_COULDNT_RESOLVE_HOST) {
                return ['error' => 'dns', 'http' => 0];
            }
            if ($errno === CURLE_OPERATION_TIMEDOUT || $errno === CURLE_COULDNT_CONNECT) {
                return ['error' => 'timeout', 'http' => 0];
            }
            return ['error' => 'http_5xx', 'http' => $code];
        }
        $pinned = @inet_pton($target['ip']);
        $connected = $primaryIp === '' ? $pinned : @inet_pton($primaryIp);
        if ($primaryIp !== '' && ($pinned === false || $connected !== $pinned || !CalendarSync::isPublicIp($primaryIp))) {
            return ['error' => 'private_ip', 'http' => $code];
        }
        return [$code, $buffer];
    }
}
