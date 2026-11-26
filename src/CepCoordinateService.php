<?php

declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/CityRepository.php';
require_once __DIR__ . '/CepRepository.php';

final class CepCoordinateService
{
    public const AWESOME_API_BASE = 'https://cep.awesomeapi.com.br/json/';
    public const BRASIL_API_BASE = 'https://brasilapi.com.br/api/cep/v2/';
    public const TIMEOUT_SECONDS = 2;

    /**
     * Test hook for deterministic HTTP transport in unit tests (zero external network calls).
     * Signature: fn(string $url, int $timeout): array{status: int, body: string, error?: string}
     */
    public static ?Closure $httpTransport = null;

    /**
     * Resolves coordinates for an 8-digit CEP string.
     * Returns array{lat: ?float, lng: ?float, source: 'cep'|'city'}
     *
     * @param string $cep 8 digits
     * @param string $city City name for fallback
     * @param string $uf UF for fallback
     * @param ?float $cachedLat Pre-existing lat from DB
     * @param ?float $cachedLng Pre-existing lng from DB
     * @return array{lat: ?float, lng: ?float, source: string}
     */
    public static function resolve(string $cep, string $city, string $uf, ?float $cachedLat = null, ?float $cachedLng = null): array
    {
        $digits = preg_replace('/\D+/', '', $cep) ?? '';

        // 1. If we already have valid coordinates in DB
        if ($cachedLat !== null && $cachedLng !== null && self::isValidBrazilCoords($cachedLat, $cachedLng)) {
            return [
                'lat' => $cachedLat,
                'lng' => $cachedLng,
                'source' => 'cep',
            ];
        }

        // 2. Try external geocoding (AwesomeAPI then BrasilAPI)
        if (strlen($digits) === 8) {
            $coords = self::fetchCoords($digits);
            if ($coords !== null) {
                try {
                    CepRepository::updateCoordinates($digits, $coords['lat'], $coords['lng']);
                } catch (Throwable) {
                    // Non-fatal if DB write fails
                }
                return [
                    'lat' => $coords['lat'],
                    'lng' => $coords['lng'],
                    'source' => 'cep',
                ];
            }
        }

        // 3. Fallback to city centroid
        $known = CityRepository::exactCity($city, $uf);
        return [
            'lat' => isset($known['lat']) ? (float) $known['lat'] : null,
            'lng' => isset($known['lng']) ? (float) $known['lng'] : null,
            'source' => 'city',
        ];
    }

    /**
     * Fetches coordinates from upstream providers.
     *
     * @return array{lat: float, lng: float}|null
     */
    private static function fetchCoords(string $digits): ?array
    {
        // Provider 1: AwesomeAPI (high precision, street-level)
        $res = self::httpGet(self::AWESOME_API_BASE . $digits, self::TIMEOUT_SECONDS);
        if ($res['status'] === 200 && $res['body'] !== '') {
            $data = json_decode($res['body'], true);
            if (is_array($data) && isset($data['lat'], $data['lng'])) {
                $lat = is_numeric($data['lat']) ? (float) $data['lat'] : null;
                $lng = is_numeric($data['lng']) ? (float) $data['lng'] : null;
                if ($lat !== null && $lng !== null && self::isValidBrazilCoords($lat, $lng)) {
                    return ['lat' => $lat, 'lng' => $lng];
                }
            }
        }

        // Provider 2: BrasilAPI (fallback)
        $res = self::httpGet(self::BRASIL_API_BASE . $digits, self::TIMEOUT_SECONDS);
        if ($res['status'] === 200 && $res['body'] !== '') {
            $data = json_decode($res['body'], true);
            if (is_array($data)) {
                $coords = $data['location']['coordinates'] ?? null;
                if (is_array($coords) && isset($coords['latitude'], $coords['longitude'])) {
                    $lat = is_numeric($coords['latitude']) ? (float) $coords['latitude'] : null;
                    $lng = is_numeric($coords['longitude']) ? (float) $coords['longitude'] : null;
                    if ($lat !== null && $lng !== null && self::isValidBrazilCoords($lat, $lng)) {
                        return ['lat' => $lat, 'lng' => $lng];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Validates that coordinates fall within Brazil geographic bounding box.
     * Lat: -35.0 to +6.0; Lng: -75.0 to -33.0
     */
    public static function isValidBrazilCoords(float $lat, float $lng): bool
    {
        return $lat >= -35.0 && $lat <= 6.0 && $lng >= -75.0 && $lng <= -33.0;
    }

    /**
     * HTTP GET wrapper respecting test hook.
     *
     * @return array{status: int, body: string, error?: string}
     */
    private static function httpGet(string $url, int $timeout): array
    {
        if (self::$httpTransport !== null) {
            return (self::$httpTransport)($url, $timeout);
        }

        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'curl_missing'];
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_USERAGENT, 'CasaLivre/1.0 (CEP Geocoder)');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'status' => $status,
            'body' => is_string($body) ? $body : '',
            'error' => $error ?: ($status >= 400 ? 'http_' . $status : ''),
        ];
    }
}
