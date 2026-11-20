<?php

declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/CalendarSync.php';

/**
 * OTA listing ownership verification (iCal challenge-response).
 *
 * The host proves control of an external listing by blocking one future date in the OTA
 * calendar export. We pick a date 14-16 months ahead that is currently open, ask the host to
 * block only that date, and then confirm it appears in the private .ics feed. The public and
 * iCal hosts are allowlisted per platform and the feed is fetched with the same SSRF
 * protections as CalendarSync (HTTPS only, DNS pinning, public IPs only, redirect re-check).
 */
final class OtaVerificationService
{
    public const CHALLENGE_TTL_SECONDS = 172800; // 48 hours
    public const CHECK_COOLDOWN_SECONDS = 60;
    public const FETCH_TIMEOUT_SECONDS = 8;
    public const MAX_ICS_BYTES = 2097152; // 2 MB
    public const MAX_DATE_PICK_ATTEMPTS = 10;
    public const ONBOARDING_CREDIT_REWARD = 5;

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

    /**
     * Validates the platform and both URLs. Throws InvalidArgumentException with a PT-BR message.
     */
    public static function validateUrls(string $platform, string $publicUrl, string $icalUrl): void
    {
        if (!isset(self::PLATFORM_LABELS[$platform])) {
            throw new InvalidArgumentException('Plataforma de verificação não suportada.');
        }
        if (!CalendarSync::isHttpsUrl($publicUrl) || !CalendarSync::isHttpsUrl($icalUrl)) {
            throw new InvalidArgumentException('Informe URLs https:// válidas para o anúncio e para o calendário iCal.');
        }
        $publicHost = strtolower((string) (parse_url($publicUrl, PHP_URL_HOST) ?? ''));
        $icalHost = strtolower((string) (parse_url($icalUrl, PHP_URL_HOST) ?? ''));
        if (!in_array($publicHost, self::PUBLIC_HOSTS[$platform], true)
            || !in_array($icalHost, self::ICAL_HOSTS[$platform], true)
        ) {
            throw new InvalidArgumentException('O domínio informado não pertence à plataforma selecionada.');
        }
        if ($platform === 'airbnb') {
            $publicId = self::extractAirbnbListingId($publicUrl);
            $icalId = self::extractAirbnbListingId($icalUrl, true);
            if ($publicId === null || $icalId === null || $publicId !== $icalId) {
                throw new InvalidArgumentException(
                    'O ID do anúncio no link público do Airbnb não corresponde ao ID do calendário iCal.'
                );
            }
        }
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

    /**
     * Downloads the private .ics feed. The test hook may return the body or throw RuntimeException.
     * The real transport mirrors CalendarSync: HTTPS only, public IPs only, DNS pinning, manual
     * redirects re-validated at every hop, 8 s timeout and a 2 MB streaming cap.
     */
    public static function fetchIcsFeed(string $icalUrl): string
    {
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

        if (function_exists('exported_calendar_body')) {
            $local = exported_calendar_body($icalUrl);
            if (is_string($local) && $local !== '') {
                return self::acceptFeed($local);
            }
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Não foi possível baixar o calendário iCal informado.');
        }

        $current = $icalUrl;
        for ($hop = 0; $hop <= CalendarSync::MAX_REDIRECTS; $hop++) {
            $target = CalendarSync::resolveTarget($current);
            if (isset($target['error'])) {
                throw new RuntimeException(self::fetchError((string) $target['error']));
            }
            $result = self::request($current, $target);
            if (isset($result['error'])) {
                throw new RuntimeException(self::fetchError((string) $result['error']));
            }
            [$code, $body, $location] = $result;
            if ($code >= 300 && $code < 400) {
                if ($location === '' || $hop === CalendarSync::MAX_REDIRECTS) {
                    throw new RuntimeException('O calendário iCal redirecionou para um endereço inválido.');
                }
                $current = $location;
                continue;
            }
            if ($code !== 200) {
                throw new RuntimeException('O calendário iCal respondeu com erro HTTP ' . $code . '.');
            }
            return self::acceptFeed($body);
        }
        throw new RuntimeException('O calendário iCal redirecionou vezes demais.');
    }

    /**
     * True when $targetDateYmd is covered by a non-cancelled, opaque VEVENT. DTEND is exclusive.
     */
    public static function isDateBlockedInIcs(string $icsContent, string $targetDateYmd): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDateYmd) !== 1) {
            return false;
        }
        $target = str_replace('-', '', $targetDateYmd);
        $unfolded = (string) preg_replace("/\r?\n[ \t]/", '', $icsContent);
        if (preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/is', $unfolded, $matches) === false) {
            return false;
        }
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
                $endCandidate = $endMatch[1];
                $endDt = DateTimeImmutable::createFromFormat('!Ymd', $endCandidate, new DateTimeZone('UTC'));
                if ($endDt !== false && $endDt->format('Ymd') === $endCandidate) {
                    $endYmd = $endCandidate;
                }
            }
            if ($endYmd === $startYmd) {
                // Same-day timestamp event (or no DTEND): the single day is blocked.
                $endYmd = $startDt->modify('+1 day')->format('Ymd');
            }
            if ($endYmd > $startYmd && $target >= $startYmd && $target < $endYmd) {
                return true;
            }
        }
        return false;
    }

    /**
     * Starts (or restarts) the challenge for one property/platform.
     *
     * @return array<string, mixed>
     */
    public static function startChallenge(int $propertyId, string $platform, string $publicUrl, string $icalUrl): array
    {
        $platform = strtolower(trim($platform));
        $publicUrl = self::normalizeUrl(trim($publicUrl));
        $icalUrl = self::normalizeUrl(trim($icalUrl));
        self::validateUrls($platform, $publicUrl, $icalUrl);

        $taken = db()->prepare(
            "SELECT 1 FROM property_external_verifications
              WHERE property_id <> ? AND status IN ('verified', 'pending_challenge')
                AND (public_url = ? OR (ical_url IS NOT NULL AND ical_url = ?))
              LIMIT 1"
        );
        $taken->execute([$propertyId, $publicUrl, $icalUrl]);
        if ($taken->fetchColumn() !== false) {
            throw new InvalidArgumentException('Este link já está verificado em outro imóvel.');
        }

        $ics = self::fetchIcsFeed($icalUrl);
        $now = self::now();
        $base = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('UTC'));
        $minDate = $base->modify('+14 months');
        $maxDate = $base->modify('+16 months');
        $totalDays = (int) $minDate->diff($maxDate)->days;

        $challengeDate = null;
        for ($attempt = 0; $attempt < self::MAX_DATE_PICK_ATTEMPTS; $attempt++) {
            $offset = self::randomOffset(0, max(0, $totalDays));
            $candidate = $minDate->modify('+' . $offset . ' days')->format('Y-m-d');
            if (!self::isDateBlockedInIcs($ics, $candidate)) {
                $challengeDate = $candidate;
                break;
            }
        }
        if ($challengeDate === null) {
            throw new RuntimeException(
                'Não foi possível encontrar uma data livre entre 14 e 16 meses no futuro no calendário informado.'
            );
        }

        $stmt = db()->prepare(
            'INSERT INTO property_external_verifications
                (property_id, platform, public_url, ical_url, status, challenge_date,
                 challenge_expires_at, last_checked_at, attempts, verified_at, credits_awarded,
                 created_at, updated_at)
             VALUES (?, ?, ?, ?, \'pending_challenge\', ?, to_timestamp(?), NULL, 0, NULL, 0,
                     to_timestamp(?), to_timestamp(?))
             ON CONFLICT (property_id, platform) DO UPDATE SET
                public_url = EXCLUDED.public_url,
                ical_url = EXCLUDED.ical_url,
                status = \'pending_challenge\',
                challenge_date = EXCLUDED.challenge_date,
                challenge_expires_at = EXCLUDED.challenge_expires_at,
                last_checked_at = NULL,
                attempts = 0,
                verified_at = NULL,
                updated_at = EXCLUDED.updated_at
             RETURNING id'
        );
        $stmt->execute([
            $propertyId,
            $platform,
            $publicUrl,
            $icalUrl,
            $challengeDate,
            $now + self::CHALLENGE_TTL_SECONDS,
            $now,
            $now,
        ]);
        $verificationId = (int) $stmt->fetchColumn();

        ActivityLog::record(ActivityLog::OTA_VERIFY_START, [
            'imovel_id' => $propertyId,
            'plataforma' => $platform,
            'url' => CalendarSync::logRef($icalUrl),
            'challenge_date' => $challengeDate,
        ]);

        $dateBr = (new DateTimeImmutable($challengeDate, new DateTimeZone('UTC')))->format('d/m/Y');
        $label = self::PLATFORM_LABELS[$platform];

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
                $label
            ),
        ];
    }

    /**
     * Checks the feed for the challenge date. Cooldown/expiry are returned as structured statuses
     * so the API can map them to 429/422 without a 500.
     *
     * @return array<string, mixed>
     */
    public static function checkChallenge(int $verificationId, bool $bypassCooldown = false): array
    {
        $row = self::findRow($verificationId);
        if ($row === null) {
            throw new InvalidArgumentException('Verificação não encontrada.');
        }
        $now = self::now();

        if ((string) $row['status'] === 'verified') {
            return self::verifiedResult($row, (int) $row['credits_awarded'] === 1 ? 1 : 0, true);
        }

        $expires = strtotime((string) $row['challenge_expires_at']);
        if ((string) $row['status'] !== 'pending_challenge' || $expires === false || $expires <= $now) {
            db()->prepare(
                "UPDATE property_external_verifications
                    SET status = 'failed', updated_at = to_timestamp(?)
                  WHERE id = ?"
            )->execute([$now, $verificationId]);
            return [
                'status' => 'expired',
                'verification_id' => $verificationId,
                'message' => 'O desafio expirou. Inicie uma nova verificação.',
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

        $ics = self::fetchIcsFeed((string) $row['ical_url']);
        $blocked = self::isDateBlockedInIcs($ics, (string) $row['challenge_date']);

        if (!$blocked) {
            db()->prepare(
                'UPDATE property_external_verifications
                    SET attempts = attempts + 1, last_checked_at = to_timestamp(?), updated_at = to_timestamp(?)
                  WHERE id = ?'
            )->execute([$now, $now, $verificationId]);
            $dateBr = self::dateBr((string) $row['challenge_date']);
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

            $duplicate = db()->prepare(
                "SELECT 1 FROM property_external_verifications
                  WHERE property_id <> ? AND id <> ? AND status = 'verified'
                    AND (public_url = ? OR (ical_url IS NOT NULL AND ical_url = ?))
                  LIMIT 1"
            );
            $duplicate->execute([(int) $row['property_id'], $verificationId, (string) $row['public_url'], (string) $row['ical_url']]);
            if ($duplicate->fetchColumn() !== false) {
                throw new InvalidArgumentException('Este link já está verificado em outro imóvel.');
            }

            $credited = db()->prepare(
                'SELECT 1 FROM property_external_verifications
                  WHERE property_id = ? AND credits_awarded = 1 AND id <> ? LIMIT 1'
            );
            $credited->execute([(int) $row['property_id'], $verificationId]);
            $awardNow = (int) $row['credits_awarded'] === 0 && $credited->fetchColumn() === false;

            db()->prepare(
                "UPDATE property_external_verifications
                    SET status = 'verified', verified_at = to_timestamp(?), last_checked_at = to_timestamp(?),
                        attempts = attempts + 1, credits_awarded = 1, updated_at = to_timestamp(?)
                  WHERE id = ?"
            )->execute([$now, $now, $now, $verificationId]);

            if ($awardNow) {
                $owner = db()->prepare('SELECT user_id FROM properties WHERE id = ?');
                $owner->execute([(int) $row['property_id']]);
                $userId = (int) $owner->fetchColumn();
                if ($userId > 0) {
                    db()->prepare('UPDATE users SET quote_credits = quote_credits + ? WHERE id = ?')
                        ->execute([self::ONBOARDING_CREDIT_REWARD, $userId]);
                    if (self::$creditHook !== null) {
                        (self::$creditHook)((int) $row['property_id'], $userId, $verificationId);
                    }
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
                'ical_url' => self::maskUrl($row['ical_url'] === null ? null : (string) $row['ical_url']),
                'status' => (string) $row['status'],
                'challenge_date' => $row['challenge_date'] === null ? null : (string) $row['challenge_date'],
                'challenge_date_br' => $row['challenge_date'] === null ? null : self::dateBr((string) $row['challenge_date']),
                'challenge_expires_at' => $row['challenge_expires_at'] === null ? null : (string) $row['challenge_expires_at'],
                'last_checked_at' => $row['last_checked_at'] === null ? null : (string) $row['last_checked_at'],
                'attempts' => (int) $row['attempts'],
                'verified_at' => $row['verified_at'] === null ? null : (string) $row['verified_at'],
                'credits_awarded' => (int) $row['credits_awarded'],
            ];
        }
        return $out;
    }

    /**
     * Public, token-free view of the verified listings of a property.
     *
     * @return list<array{platform: string, platform_label: string, public_url: string, verified_at: string}>
     */
    public static function listVerifiedForProperty(int $propertyId): array
    {
        $stmt = db()->prepare(
            "SELECT platform, public_url, verified_at
               FROM property_external_verifications
              WHERE property_id = ? AND status = 'verified'
              ORDER BY verified_at DESC, id DESC"
        );
        $stmt->execute([$propertyId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $platform = (string) $row['platform'];
            $out[] = [
                'platform' => $platform,
                'platform_label' => self::PLATFORM_LABELS[$platform] ?? $platform,
                'public_url' => (string) $row['public_url'],
                'verified_at' => (string) $row['verified_at'],
            ];
        }
        return $out;
    }

    public static function hasVerifiedPlatform(int $propertyId): bool
    {
        $stmt = db()->prepare(
            "SELECT 1 FROM property_external_verifications WHERE property_id = ? AND status = 'verified' LIMIT 1"
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

    /** Conservative normalization for duplicate detection: lowercase scheme/host, drop the :443 and fragments. */
    public static function normalizeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return $url;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) && (int) $parts['port'] !== 443 ? ':' . (int) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        return $scheme . '://' . $host . $port . $path . $query;
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

    private static function dateBr(string $ymd): string
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new DateTimeZone('UTC'));
        return $dt === false ? $ymd : $dt->format('d/m/Y');
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
        if (!str_contains($body, 'BEGIN:VCALENDAR')) {
            throw new RuntimeException('O calendário iCal informado não é válido.');
        }
        return $body;
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

    private static function now(): int
    {
        return self::$clock !== null ? (int) (self::$clock)() : time();
    }

    private static function randomOffset(int $min, int $max): int
    {
        return self::$randomInt !== null ? (int) (self::$randomInt)($min, $max) : random_int($min, $max);
    }

    /**
     * SSRF-safe request pinned to the already-validated IP.
     *
     * @param array{host: string, port: int, ip: string} $target
     * @return array{0: int, 1: string, 2: string}|array{error: string, http: int}
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
        $location = (string) (curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: '');
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
        return [$code, $buffer, $location];
    }
}
