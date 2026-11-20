<?php

declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/I18n.php';
require_once __DIR__ . '/Acoes/TextoBruto.php';

/**
 * Google Places API (New - v1) reputation client for one property's google_place_id.
 *
 * The API key is used strictly server-side (X-Goog-Api-Key). Reputation data is ephemeral:
 * it lives in google_places_cache for at most 24h (Google ToS) and is never copied to
 * properties. Any upstream failure degrades to null so the property page keeps working.
 */
final class GooglePlacesService
{
    public const ENDPOINT_BASE = 'https://places.googleapis.com/v1/places/';
    public const FIELD_MASK = 'rating,userRatingCount,googleMapsUri,reviews';
    public const PLACE_ID_REGEX = '/\A[A-Za-z0-9_-]{10,128}\z/';
    public const DEFAULT_TTL = 86400; // 24 hours
    public const DEFAULT_TIMEOUT = 3; // 3 seconds max so page load never stalls
    public const MAX_REVIEWS = 3;
    public const MAX_REVIEW_TEXT_LEN = 280;
    public const ATTRIBUTION = 'Powered by Google';

    /**
     * Test hook for deterministic HTTP transport in unit tests (zero external network calls).
     * Signature: fn(string $url, array $headers, int $timeout): array{status: int, body: string, error?: string}
     */
    public static ?Closure $httpTransport = null;

    /** Test hook for a deterministic clock (unix timestamp). Null uses time(). */
    public static ?Closure $clock = null;

    /**
     * Validates a raw google_place_id input.
     * - null or '' (after trim) -> valid empty; - non-string or bad format -> error.
     * Prevents SSRF, path traversal, query injection and control characters.
     *
     * @return array{ok: bool, value?: ?string, error?: string}
     */
    public static function validatePlaceId(mixed $raw): array
    {
        if ($raw === null) {
            return ['ok' => true, 'value' => null];
        }
        if (!is_string($raw)) {
            return ['ok' => false, 'error' => t('err.google_place_id')];
        }
        $value = trim($raw);
        if ($value === '') {
            return ['ok' => true, 'value' => null];
        }
        if (preg_match(self::PLACE_ID_REGEX, $value) !== 1) {
            return ['ok' => false, 'error' => t('err.google_place_id')];
        }
        return ['ok' => true, 'value' => $value];
    }

    /**
     * Reputation for a property's google_place_id: fresh cache -> upstream -> graceful null.
     * Never throws to callers on missing key, timeout, quota or upstream errors.
     *
     * @return ?array{
     *   place_id: string,
     *   rating: ?float,
     *   user_rating_count: int,
     *   google_maps_uri: string,
     *   reviews: list<array{author: string, rating: int, text: string, relative_time: string}>,
     *   attribution: string,
     *   cached: bool
     * }
     */
    public static function getReputation(?string $placeId, string $locale = 'pt-BR'): ?array
    {
        $check = self::validatePlaceId($placeId);
        if (!$check['ok'] || ($check['value'] ?? null) === null) {
            return null;
        }
        $id = (string) $check['value'];
        $now = self::now();

        $row = self::cacheFind($id);
        if ($row !== null && self::isFresh($row, $now)) {
            return self::payloadFromRow($row) + ['cached' => true];
        }

        $key = self::apiKey();
        if ($key === '') {
            // Silent degradation: the page renders without the Google block.
            if ($row !== null) {
                self::cacheDelete($id);
            }
            return null;
        }

        $result = self::fetch($id, $key, $locale, self::timeout());
        $status = (int) ($result['status'] ?? 0);
        if ($status === 200) {
            $decoded = json_decode((string) ($result['body'] ?? ''), true);
            if (!is_array($decoded)) {
                self::cacheDeleteExpired($id, $now);
                return null;
            }
            $payload = self::normalize($id, $decoded);
            self::cacheStore($id, $payload, $now, self::ttl());
            return $payload + ['cached' => false];
        }

        self::cacheDeleteExpired($id, $now);
        return null;
    }

    /**
     * Live validation/fetch for the host dashboard "Test Place ID" action.
     *
     * @return array{
     *   ok: bool,
     *   data?: array<string, mixed>,
     *   error_code?: 'invalid_place_id'|'missing_api_key'|'not_found'|'quota_exceeded'|'upstream_error',
     *   error_message?: string
     * }
     */
    public static function verifyPlaceId(mixed $rawPlaceId, string $locale = 'pt-BR'): array
    {
        $check = self::validatePlaceId($rawPlaceId);
        if (!$check['ok']) {
            return [
                'ok' => false,
                'error_code' => 'invalid_place_id',
                'error_message' => (string) ($check['error'] ?? t('err.google_place_id')),
            ];
        }
        if (($check['value'] ?? null) === null) {
            return [
                'ok' => false,
                'error_code' => 'invalid_place_id',
                'error_message' => t('err.google_place_id'),
            ];
        }
        $id = (string) $check['value'];

        $key = self::apiKey();
        if ($key === '') {
            return [
                'ok' => false,
                'error_code' => 'missing_api_key',
                'error_message' => t('err.google_place_unavailable'),
            ];
        }

        $result = self::fetch($id, $key, $locale, self::timeout());
        $status = (int) ($result['status'] ?? 0);
        if ($status === 200) {
            $decoded = json_decode((string) ($result['body'] ?? ''), true);
            if (!is_array($decoded)) {
                return self::verifyFailure('upstream_error');
            }
            $now = self::now();
            $payload = self::normalize($id, $decoded);
            self::cacheStore($id, $payload, $now, self::ttl());
            return ['ok' => true, 'data' => $payload + ['cached' => false]];
        }
        if ($status === 400 || $status === 404) {
            return self::verifyFailure('not_found');
        }
        if ($status === 429) {
            return self::verifyFailure('quota_exceeded');
        }
        return self::verifyFailure('upstream_error');
    }

    /** Evicts the cached reputation of a place_id (when the host changes/removes it). */
    public static function invalidateCache(string $placeId): void
    {
        if (preg_match(self::PLACE_ID_REGEX, $placeId) !== 1) {
            return;
        }
        self::cacheDelete($placeId);
    }

    /** Deletes expired rows (expires_at <= now) and returns how many were removed. */
    public static function purgeExpiredCache(): int
    {
        $stmt = db()->prepare('DELETE FROM google_places_cache WHERE expires_at <= statement_timestamp()');
        $stmt->execute();
        return $stmt->rowCount();
    }

    /** @return array{ok: false, error_code: string, error_message: string} */
    private static function verifyFailure(string $code): array
    {
        return [
            'ok' => false,
            'error_code' => $code,
            'error_message' => t($code === 'not_found' ? 'err.google_place_not_found' : 'err.google_place_unavailable'),
        ];
    }

    /** @return array<string, mixed> */
    private static function payloadFromRow(array $row): array
    {
        $reviews = json_decode((string) ($row['reviews_json'] ?? '[]'), true);
        return [
            'place_id' => (string) $row['place_id'],
            'rating' => $row['rating'] === null ? null : round((float) $row['rating'], 1),
            'user_rating_count' => max(0, (int) ($row['user_rating_count'] ?? 0)),
            'google_maps_uri' => self::mapsUri((string) $row['place_id'], $row['google_maps_uri'] ?? null),
            'reviews' => is_array($reviews) ? array_values($reviews) : [],
            'attribution' => self::ATTRIBUTION,
        ];
    }

    /**
     * Keeps only what the page needs. Reviewer photo/uri are never stored (CSP + tracking/LGPD).
     *
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    private static function normalize(string $placeId, array $json): array
    {
        $rating = isset($json['rating']) && is_numeric($json['rating'])
            ? round((float) $json['rating'], 1)
            : null;
        if ($rating !== null && ($rating < 1.0 || $rating > 5.0)) {
            $rating = null; // the cache CHECK also refuses out-of-range values
        }

        $reviews = [];
        $rawReviews = is_array($json['reviews'] ?? null) ? $json['reviews'] : [];
        foreach (array_slice($rawReviews, 0, self::MAX_REVIEWS) as $review) {
            if (!is_array($review)) {
                continue;
            }
            $author = trim((string) ($review['authorAttribution']['displayName'] ?? ''));
            $text = trim((string) ($review['text']['text'] ?? ($review['originalText']['text'] ?? '')));
            if (function_exists('mb_substr')) {
                $text = mb_substr($text, 0, self::MAX_REVIEW_TEXT_LEN);
            } else {
                $text = substr($text, 0, self::MAX_REVIEW_TEXT_LEN);
            }
            $reviews[] = [
                'author' => $author !== '' ? $author : 'Google User',
                'rating' => max(1, min(5, (int) ($review['rating'] ?? 5))),
                'text' => $text,
                'relative_time' => trim((string) ($review['relativePublishTimeDescription'] ?? '')),
            ];
        }

        return [
            'place_id' => $placeId,
            'rating' => $rating,
            'user_rating_count' => max(0, (int) ($json['userRatingCount'] ?? 0)),
            'google_maps_uri' => self::mapsUri($placeId, $json['googleMapsUri'] ?? null),
            'reviews' => $reviews,
            'attribution' => self::ATTRIBUTION,
        ];
    }

    /** Only official Google Maps hosts are rendered; anything else becomes a safe search link. */
    private static function mapsUri(string $placeId, mixed $candidate): string
    {
        $uri = is_string($candidate) ? trim($candidate) : '';
        if ($uri !== ''
            && preg_match('#\Ahttps://(?:maps\.google\.com/|www\.google\.com/maps/)\S+\z#', $uri) === 1
            && preg_match('/[\x00-\x1f\x7f]/', $uri) !== 1
        ) {
            return $uri;
        }
        return 'https://www.google.com/maps/search/?api=1&query=Google&query_place_id=' . rawurlencode($placeId);
    }

    /** @return array{status: int, body: string, error?: string} */
    private static function fetch(string $placeId, string $key, string $locale, int $timeout): array
    {
        $url = self::ENDPOINT_BASE . rawurlencode($placeId) . '?languageCode=' . rawurlencode($locale);
        $headers = [
            'X-Goog-Api-Key' => $key,
            'X-Goog-FieldMask' => self::FIELD_MASK,
            'Accept' => 'application/json',
        ];
        if (self::$httpTransport !== null) {
            /** @var array{status: int, body: string, error?: string} $result */
            $result = (self::$httpTransport)($url, $headers, $timeout);
            return $result;
        }
        return self::curlFetch($url, $headers, $timeout);
    }

    /** @param array<string, string> $headers
     *  @return array{status: int, body: string, error?: string}
     */
    private static function curlFetch(string $url, array $headers, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'curl_missing'];
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['status' => 0, 'body' => '', 'error' => $error !== '' ? $error : 'curl_error'];
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $body];
    }

    /** @return ?array<string, mixed> */
    private static function cacheFind(string $placeId): ?array
    {
        $stmt = db()->prepare(
            'SELECT place_id, rating, user_rating_count, google_maps_uri, reviews_json, expires_at
               FROM google_places_cache WHERE place_id = ?'
        );
        $stmt->execute([$placeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private static function isFresh(array $row, int $now): bool
    {
        $expires = strtotime((string) ($row['expires_at'] ?? ''));
        return $expires !== false && $expires > $now;
    }

    /** @param array<string, mixed> $payload */
    private static function cacheStore(string $placeId, array $payload, int $now, int $ttl): void
    {
        $stmt = db()->prepare(
            'INSERT INTO google_places_cache
                (place_id, rating, user_rating_count, google_maps_uri, reviews_json, fetched_at, expires_at)
             VALUES (?, ?, ?, ?, CAST(? AS jsonb), to_timestamp(?), to_timestamp(?))
             ON CONFLICT (place_id) DO UPDATE SET
                rating = EXCLUDED.rating,
                user_rating_count = EXCLUDED.user_rating_count,
                google_maps_uri = EXCLUDED.google_maps_uri,
                reviews_json = EXCLUDED.reviews_json,
                fetched_at = EXCLUDED.fetched_at,
                expires_at = EXCLUDED.expires_at'
        );
        $stmt->execute([
            $placeId,
            $payload['rating'],
            (int) $payload['user_rating_count'],
            (string) $payload['google_maps_uri'],
            (string) json_encode($payload['reviews'], JSON_UNESCAPED_UNICODE),
            $now,
            $now + $ttl,
        ]);
    }

    private static function cacheDelete(string $placeId): void
    {
        $stmt = db()->prepare('DELETE FROM google_places_cache WHERE place_id = ?');
        $stmt->execute([$placeId]);
    }

    private static function cacheDeleteExpired(string $placeId, int $now): void
    {
        $stmt = db()->prepare('DELETE FROM google_places_cache WHERE place_id = ? AND expires_at <= to_timestamp(?)');
        $stmt->execute([$placeId, $now]);
    }

    private static function now(): int
    {
        return self::$clock !== null ? (int) (self::$clock)() : time();
    }

    private static function apiKey(): string
    {
        Db::loadEnv();
        $key = getenv('GOOGLE_PLACES_API_KEY');
        return is_string($key) ? trim($key) : '';
    }

    private static function ttl(): int
    {
        $raw = getenv('GOOGLE_PLACES_CACHE_TTL');
        $ttl = is_string($raw) && preg_match('/\A\d+\z/', $raw) === 1 ? (int) $raw : self::DEFAULT_TTL;
        return max(1, min(self::DEFAULT_TTL, $ttl));
    }

    private static function timeout(): int
    {
        $raw = getenv('GOOGLE_PLACES_TIMEOUT');
        $timeout = is_string($raw) && preg_match('/\A\d+\z/', $raw) === 1 ? (int) $raw : self::DEFAULT_TIMEOUT;
        return max(1, min(10, $timeout));
    }
}
