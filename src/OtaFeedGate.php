<?php

declare(strict_types=1);

require_once __DIR__ . '/OtaPlatformRegistry.php';
require_once __DIR__ . '/OtaVerificationService.php';
require_once __DIR__ . '/CalendarSync.php';
require_once __DIR__ . '/LogPrivacy.php';
require_once __DIR__ . '/I18n.php';

/** A gate error with the form field it belongs to. */
final class OtaFeedGateException extends InvalidArgumentException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}

/**
 * Pre-save gate for calendar feeds: platform-bound OTA verification or general sync-only.
 * Nothing is persisted here; the caller inserts only after prepare() returns.
 */
final class OtaFeedGate
{
    /** Test hook: fn(string $url): array{status:int, location:?string}. */
    public static ?Closure $shareTransport = null;

    /**
     * @return array{
     *   mode: string, platform: ?string, property_url: ?string, canonical_listing_id: ?string,
     *   ics: string, ics_metadata: ?string, challenge_date: ?string, consent_at: ?int,
     *   verification_ip: ?string, review_status: string
     * }
     */
    public static function prepare(
        int $propertyId,
        int $userId,
        string $mode,
        string $platform,
        string $publicUrl,
        string $icalUrl,
        bool $consent
    ): array {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['ota', 'sync_only'], true)) {
            throw new OtaFeedGateException('mode', t('ota.err_mode'));
        }
        $icalUrl = trim($icalUrl);
        self::hygiene($icalUrl, 'external_ical_url');

        if ($mode === 'sync_only') {
            return [
                'mode' => 'sync_only',
                'platform' => null,
                'property_url' => null,
                'canonical_listing_id' => null,
                'ics' => OtaVerificationService::fetchIcsFeed($icalUrl),
                'ics_metadata' => null,
                'challenge_date' => null,
                'consent_at' => null,
                'verification_ip' => null,
                'review_status' => 'approved',
            ];
        }

        if (!$consent) {
            throw new OtaFeedGateException('consent', t('ota.err_consent'));
        }
        $platform = strtolower(trim($platform));
        if (!OtaPlatformRegistry::exists($platform)) {
            throw new OtaFeedGateException('platform', t('ota.err_platform'));
        }
        $publicUrl = trim($publicUrl);
        self::hygiene($publicUrl, 'property_url');
        self::hygiene($icalUrl, 'external_ical_url');

        $publicUrl = self::resolveMobileShareUrl($platform, $publicUrl);
        $publicParts = parse_url($publicUrl);
        $icalParts = parse_url($icalUrl);
        $publicHost = strtolower((string) ($publicParts['host'] ?? ''));
        $icalHost = strtolower((string) ($icalParts['host'] ?? ''));
        if (!OtaPlatformRegistry::isPublicHost($platform, $publicHost)) {
            throw new OtaFeedGateException('property_url', t('ota.err_domain'));
        }
        if (!OtaPlatformRegistry::isIcalHost($platform, $icalHost)) {
            throw new OtaFeedGateException('external_ical_url', t('ota.err_ical_domain'));
        }

        $publicPath = self::cleanPath((string) ($publicParts['path'] ?? ''));
        if (preg_match(OtaPlatformRegistry::publicPathRegex($platform), $publicPath) !== 1) {
            throw new OtaFeedGateException('property_url', t('ota.err_path'));
        }

        $canonical = self::canonicalize($platform, $publicPath, $icalUrl);
        self::assertCanonicalAvailable($propertyId, $userId, $canonical, $platform);

        $ics = OtaVerificationService::fetchIcsFeed($icalUrl);
        $icsMetadata = OtaVerificationService::extractIcsMetadata($ics);
        $excluded = db()->prepare(
            "SELECT challenge_date FROM calendar_feeds
              WHERE property_id = ? AND verification_mode = 'ota' AND status = 'pending_verification'
                AND challenge_date IS NOT NULL"
        );
        $excluded->execute([$propertyId]);
        $excludedDates = array_map('strval', $excluded->fetchAll(PDO::FETCH_COLUMN));
        $challengeDate = OtaVerificationService::pickOpenDateForFeed($ics, $excludedDates);

        return [
            'mode' => 'ota',
            'platform' => $platform,
            'property_url' => self::cleanUrl($publicUrl),
            'canonical_listing_id' => $canonical,
            'ics' => $ics,
            'ics_metadata' => $icsMetadata,
            'challenge_date' => $challengeDate,
            'consent_at' => OtaVerificationService::now(),
            'verification_ip' => self::pseudonymizedIp(),
            'review_status' => OtaPlatformRegistry::requiresAdminReview($platform) ? 'pending_review' : 'approved',
        ];
    }

    /** @return list<string> */
    public static function platforms(): array
    {
        return array_keys(OtaPlatformRegistry::all());
    }

    public static function canonicalize(string $platform, string $publicPath, string $icalUrl): string
    {
        if ($platform === 'airbnb') {
            if (preg_match('#\A/(?:rooms|h)/(\d+)\z#', $publicPath, $m) !== 1) {
                throw new OtaFeedGateException('property_url', t('ota.err_airbnb_path'));
            }
            $icalId = OtaVerificationService::extractAirbnbListingId($icalUrl, true);
            if ($icalId === null) {
                throw new OtaFeedGateException('external_ical_url', t('ota.err_ical_path'));
            }
            if ($m[1] !== $icalId) {
                throw new OtaFeedGateException('property_url', t('ota.err_airbnb_id'));
            }
            return 'airbnb:' . $m[1];
        }
        if ($platform === 'booking') {
            if (preg_match(OtaPlatformRegistry::publicPathRegex('booking'), $publicPath, $m) !== 1) {
                throw new OtaFeedGateException('property_url', t('ota.err_path'));
            }
            $icalPath = (string) (parse_url($icalUrl, PHP_URL_PATH) ?? '');
            $icalQuery = (string) (parse_url($icalUrl, PHP_URL_QUERY) ?? '');
            $icalOk = (preg_match('#\A/v1/export\z#i', $icalPath) === 1 && $icalQuery !== '')
                || preg_match('#\.ics\z#i', $icalPath) === 1;
            if (!$icalOk) {
                throw new OtaFeedGateException('external_ical_url', t('ota.err_ical_path'));
            }
            return 'booking:' . strtolower($m[1] . '/' . $m[2]);
        }
        if ($platform === 'vrbo') {
            if (preg_match(OtaPlatformRegistry::publicPathRegex('vrbo'), $publicPath, $m) !== 1) {
                throw new OtaFeedGateException('property_url', t('ota.err_path'));
            }
            $icalPath = (string) (parse_url($icalUrl, PHP_URL_PATH) ?? '');
            if (preg_match('#\.ics\z#i', $icalPath) !== 1) {
                throw new OtaFeedGateException('external_ical_url', t('ota.err_ical_path'));
            }
            $id = ltrim((string) $m[1], 'pP');
            return 'vrbo:' . $id;
        }
        throw new OtaFeedGateException('platform', t('ota.err_platform'));
    }

    public static function assertCanonicalAvailable(int $propertyId, int $userId, string $canonical, string $platform): void
    {
        $stmt = db()->prepare(
            "SELECT f.property_id, p.user_id
               FROM calendar_feeds f
               JOIN properties p ON p.id = f.property_id
              WHERE f.canonical_listing_id = ? AND f.property_id <> ? AND f.verification_mode = 'ota'"
        );
        $stmt->execute([$canonical, $propertyId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int) $row['user_id'] !== $userId) {
                throw new OtaFeedGateException('property_url', t('ota.err_canonical_other_host'));
            }
            if ($platform !== 'booking') {
                throw new OtaFeedGateException('property_url', t('ota.err_canonical_taken'));
            }
        }
        // The permanent ledger outlives deleted properties; it still blocks a different host.
        $ledger = db()->prepare('SELECT user_id FROM ota_credit_ledger WHERE canonical_listing_id = ? LIMIT 1');
        $ledger->execute([$canonical]);
        $owner = $ledger->fetchColumn();
        if ($owner !== false && (int) $owner !== $userId) {
            throw new OtaFeedGateException('property_url', t('ota.err_canonical_other_host'));
        }
    }

    /** Follows at most two 3xx Location hops, re-checking the selected platform on every hop. */
    public static function resolveMobileShareUrl(string $platform, string $url): string
    {
        $current = $url;
        for ($hop = 0; $hop <= 2; $hop++) {
            $parts = parse_url($current);
            $host = strtolower((string) ($parts['host'] ?? ''));
            $path = self::cleanPath((string) ($parts['path'] ?? ''));
            if (OtaPlatformRegistry::isPublicHost($platform, $host)
                && preg_match(OtaPlatformRegistry::publicPathRegex($platform), $path) === 1
            ) {
                return $current;
            }
            if (!OtaPlatformRegistry::isShareUrl($platform, $host, $path)) {
                throw new OtaFeedGateException('property_url', t('ota.err_domain'));
            }
            if ($hop === 2) {
                break;
            }
            $result = self::$shareTransport !== null
                ? (array) (self::$shareTransport)($current)
                : self::shareRequest($current);
            $status = (int) ($result['status'] ?? 0);
            $location = $result['location'] ?? null;
            if ($status < 300 || $status >= 400 || !is_string($location) || $location === '') {
                throw new OtaFeedGateException('property_url', t('ota.err_share'));
            }
            self::hygiene($location, 'property_url');
            $next = parse_url($location);
            $nextHost = strtolower((string) ($next['host'] ?? ''));
            $nextPath = self::cleanPath((string) ($next['path'] ?? ''));
            if (!OtaPlatformRegistry::isPublicHost($platform, $nextHost)
                && !OtaPlatformRegistry::isShareUrl($platform, $nextHost, $nextPath)
            ) {
                throw new OtaFeedGateException('property_url', t('ota.err_share_host'));
            }
            $current = $location;
        }
        throw new OtaFeedGateException('property_url', t('ota.err_share'));
    }

    private static function shareRequest(string $url): array
    {
        $target = CalendarSync::resolveTarget($url);
        if (isset($target['error']) || !function_exists('curl_init')) {
            throw new OtaFeedGateException('property_url', t('ota.err_share'));
        }
        $ip = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];
        $location = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . $ip],
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_NOPROXY => '*',
            CURLOPT_USERAGENT => 'CasaLivre/1.0',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$location): int {
                if (preg_match('#^Location:\s*(.+)$#i', trim($header), $m) === 1) {
                    $location = trim($m[1]);
                }
                return strlen($header);
            },
        ]);
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'https');
        }
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $redirect = (string) (curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: $location);
        return ['status' => $status, 'location' => $redirect !== '' ? $redirect : null];
    }

    private static function hygiene(string $url, string $field): void
    {
        try {
            OtaVerificationService::assertUrlHygiene($url);
        } catch (InvalidArgumentException $e) {
            throw new OtaFeedGateException($field, $e->getMessage());
        }
    }

    private static function cleanPath(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        return '/' . trim($path, '/');
    }

    private static function cleanUrl(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = self::cleanPath((string) ($parts['path'] ?? ''));
        return $scheme . '://' . $host . $path;
    }

    private static function pseudonymizedIp(): ?string
    {
        $ip = LogPrivacy::ip((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return $ip !== '' ? $ip : null;
    }
}
