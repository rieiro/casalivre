<?php

declare(strict_types=1);

require_once __DIR__ . '/OtaPlatformRegistry.php';
require_once __DIR__ . '/OtaListingExtractor.php';

final class CalendarSync
{
    /** Tamanho máximo aceito para um feed .ics (bytes). */
    public const MAX_BYTES = 2 * 1024 * 1024;
    /** One VEVENT above this fails the feed (too_big). It is not skipped. */
    public const MAX_EVENT_BYTES = 1024 * 1024;
    /** Events above this fail the feed (too_big). */
    public const MAX_EVENTS = 2000;
    /** Feed URL cap. An unbounded URL must be a validation error, never an HTTP 500. */
    public const MAX_URL_LENGTH = 2048;
    /** Número máximo de redirecionamentos, cada um revalidado. */
    public const MAX_REDIRECTS = 3;
    /** Total fetch budget (seconds). Connect stays at CONNECT_TIMEOUT. */
    public const FETCH_TIMEOUT = 8;
    public const CONNECT_TIMEOUT = 5;
    /** Listing HTML cap for the URL content import (bytes). */
    public const MAX_LISTING_BYTES = 2 * 1024 * 1024;
    /** Single CDN image cap for the URL content import (bytes). */
    public const MAX_IMAGE_BYTES = 8 * 1024 * 1024;

    /**
     * Test hook for the URL content import: fn(string $url, int $maxBytes, string $accept): array{body?:string,http?:int,error?:string}.
     * Null uses the real SSRF-safe curl transport.
     */
    public static ?Closure $httpTransport = null;

    /**
     * SSRF-safe HTTPS GET for a public listing page or CDN asset. Reuses resolveTarget()/isPublicIp(),
     * pins the resolved IP with CURLOPT_RESOLVE, never follows redirects, restricts to https and caps bytes.
     *
     * @return array{body: string, http: int}|array{error: string, http: int}
     */
    public static function fetchPublicResource(string $url, int $maxBytes = self::MAX_LISTING_BYTES, string $accept = 'text/html,application/xhtml+xml,*/*;q=0.8'): array
    {
        $result = self::fetchRedirectAware($url, $maxBytes, $accept, []);
        if (isset($result['redirect'])) {
            return ['error' => 'bad_url', 'http' => (int) ($result['http'] ?? 0)];
        }
        return $result;
    }

    /**
     * Public listing HTML: follows up to 2 same-platform 3xx Location hops (locale/canonical slug),
     * re-validating https, the OTA public host allowlist and SSRF/IP pinning on every hop.
     * Only an exact HTTP 200 with real listing markup is accepted; WAF/anti-bot responses become
     * blocked_by_ota so the caller can offer the host-side raw HTML paste fallback.
     */
    public static function fetchListingHtml(string $url, string $platform = ''): array
    {
        $current = $url;
        for ($hop = 0; $hop <= 2; $hop++) {
            $result = self::fetchRedirectAware(
                $current,
                self::MAX_LISTING_BYTES,
                'text/html,application/xhtml+xml,*/*;q=0.8',
                ['Accept-Language: pt-BR,pt;q=0.9,en;q=0.8']
            );
            if (!isset($result['redirect'])) {
                $http = (int) ($result['http'] ?? 0);
                $headers = (array) ($result['headers'] ?? []);
                if (self::isOtaBlockedResponse($http, $headers)) {
                    return ['error' => 'blocked_by_ota', 'http' => $http];
                }
                if (isset($result['error'])) {
                    return $result;
                }
                if ($http !== 200) {
                    return ['error' => $http >= 500 || $http === 0 ? 'http_5xx' : 'http_4xx', 'http' => $http];
                }
                $body = (string) ($result['body'] ?? '');
                if (OtaListingExtractor::isWafChallengeShell($body)) {
                    return ['error' => 'blocked_by_ota', 'http' => $http];
                }
                return ['body' => $body, 'http' => $http];
            }
            if ($hop === 2) {
                return ['error' => 'bad_url', 'http' => (int) ($result['http'] ?? 0)];
            }
            $next = self::resolveRedirectUrl($current, (string) $result['redirect']);
            if ($next === '' || !self::isHttpsUrl($next)) {
                return ['error' => 'bad_url', 'http' => (int) ($result['http'] ?? 0)];
            }
            if ($platform !== '') {
                $host = strtolower((string) (parse_url($next, PHP_URL_HOST) ?? ''));
                if (!OtaPlatformRegistry::isPublicHost($platform, $host)) {
                    return ['error' => 'bad_url', 'http' => (int) ($result['http'] ?? 0)];
                }
            }
            $current = $next;
        }
        return ['error' => 'bad_url', 'http' => 0];
    }

    /**
     * HTTP statuses / challenge headers that mean the OTA refused to serve the real listing.
     *
     * @param array<string, mixed> $headers lowercased response headers
     */
    private static function isOtaBlockedResponse(int $http, array $headers): bool
    {
        if (in_array($http, [202, 403, 405, 429], true)) {
            return true;
        }
        foreach (['x-amzn-waf-action', 'x-hcom-origin-id'] as $name) {
            if (isset($headers[$name]) && trim((string) $headers[$name]) !== '') {
                return true;
            }
        }
        return str_contains(strtolower((string) ($headers['x-page-id'] ?? '')), 'wildcard-challenge-handler');
    }

    /** Resolves a Location header (absolute, scheme-relative, root-relative or path-relative) against $base. */
    public static function resolveRedirectUrl(string $base, string $location): string
    {
        $location = trim($location);
        if ($location === '') {
            return '';
        }
        $baseParts = parse_url($base);
        if (!is_array($baseParts) || empty($baseParts['host'])) {
            return '';
        }
        $scheme = strtolower((string) ($baseParts['scheme'] ?? 'https'));
        $host = strtolower((string) $baseParts['host']);
        $port = isset($baseParts['port']) ? ':' . (int) $baseParts['port'] : '';
        if (preg_match('#\Ahttps?://#i', $location) === 1) {
            return self::isHttpsUrl($location) ? $location : '';
        }
        if (str_starts_with($location, '//')) {
            $candidate = $scheme . ':' . $location;
            return self::isHttpsUrl($candidate) ? $candidate : '';
        }
        if (str_starts_with($location, '/')) {
            return $scheme . '://' . $host . $port . $location;
        }
        $basePath = (string) ($baseParts['path'] ?? '/');
        $dir = rtrim(str_replace('\\', '/', dirname($basePath)), '/');
        $joined = ($dir === '' || $dir === '.' ? '' : $dir) . '/' . $location;
        $segments = [];
        foreach (explode('/', $joined) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        return $scheme . '://' . $host . $port . '/' . implode('/', $segments);
    }

    /**
     * @param list<string> $extraHeaders
     * @return array{body: string, http: int}|array{redirect: string, http: int}|array{error: string, http: int}
     */
    private static function fetchRedirectAware(string $url, int $maxBytes, string $accept, array $extraHeaders): array
    {
        $maxBytes = max(1, min($maxBytes, self::MAX_IMAGE_BYTES));
        if (!self::isHttpsUrl($url)) {
            return ['error' => 'bad_url', 'http' => 0];
        }
        if (self::$httpTransport !== null) {
            $result = (array) (self::$httpTransport)($url, $maxBytes, $accept);
            if (isset($result['error'])) {
                return ['error' => (string) $result['error'], 'http' => (int) ($result['http'] ?? 0), 'headers' => self::normalizeHeaders($result['headers'] ?? [])];
            }
            $http = (int) ($result['http'] ?? 200);
            $headers = self::normalizeHeaders($result['headers'] ?? $result['response_headers'] ?? []);
            $location = (string) ($result['redirect'] ?? $result['location'] ?? '');
            if ($http >= 300 && $http < 400) {
                return $location !== ''
                    ? ['redirect' => $location, 'http' => $http, 'headers' => $headers]
                    : ['error' => 'bad_url', 'http' => $http, 'headers' => $headers];
            }
            $body = (string) ($result['body'] ?? '');
            if (strlen($body) > $maxBytes) {
                return ['error' => 'too_big', 'http' => $http, 'headers' => $headers];
            }
            return ['body' => $body, 'http' => $http, 'headers' => $headers];
        }
        if (!function_exists('curl_init')) {
            return ['error' => 'http_5xx', 'http' => 0];
        }
        $target = self::resolveTarget($url);
        if (isset($target['error'])) {
            return ['error' => $target['error'], 'http' => 0];
        }
        $result = self::requestBytes($url, $target, $maxBytes, $accept, self::FETCH_TIMEOUT, $extraHeaders);
        if (isset($result['error'])) {
            return $result;
        }
        [$code, $body, $location, $headers] = $result;
        if ($code >= 300 && $code < 400) {
            return $location !== ''
                ? ['redirect' => $location, 'http' => $code, 'headers' => $headers]
                : ['error' => 'bad_url', 'http' => $code, 'headers' => $headers];
        }
        if ($code === 429 || $code >= 500) {
            return ['error' => 'http_5xx', 'http' => $code, 'headers' => $headers];
        }
        if ($code < 200 || $code >= 300) {
            return ['error' => 'http_4xx', 'http' => $code, 'headers' => $headers];
        }
        return ['body' => $body, 'http' => $code, 'headers' => $headers];
    }

    /** @return array<string, string> lowercased response headers */
    private static function normalizeHeaders(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $key => $value) {
            if (is_int($key)) {
                $line = is_string($value) ? trim($value) : '';
                $pos = strpos($line, ':');
                if ($pos === false) {
                    continue;
                }
                $key = substr($line, 0, $pos);
                $value = substr($line, $pos + 1);
            }
            $name = strtolower(trim((string) $key));
            if ($name === '') {
                continue;
            }
            $out[$name] = is_array($value) ? implode(', ', array_map('strval', $value)) : trim((string) $value);
        }
        return $out;
    }

    public static function fetchImageBytes(string $url): array
    {
        return self::fetchPublicResource($url, self::MAX_IMAGE_BYTES, 'image/webp,image/jpeg,image/png');
    }

    /** Referência de log para uma URL de feed: só host + hash (a URL pode conter token). */
    public static function logRef(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }
        $url = self::decryptUrl($url);
        $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
        return ($host !== '' ? strtolower($host) : '?') . '#' . substr(hash('sha256', $url), 0, 12);
    }

    /** Cópia da linha de feed para auditoria, com a URL substituída por logRef(). */
    public static function logFeed(mixed $feed): mixed
    {
        if (is_array($feed) && isset($feed['url'])) {
            $feed['url'] = self::logRef((string) $feed['url']);
        }
        return $feed;
    }

    /**
     * Regra única de URL de feed (cadastro e download): só https:// na porta 443, com host,
     * sem userinfo, sem espaços nem controle, no máximo MAX_URL_LENGTH. O esquema não diferencia maiúsculas.
     */
    public static function isHttpsUrl(string $url): bool
    {
        if (strlen($url) > self::MAX_URL_LENGTH || preg_match('/[\s\x00-\x1f\x7f]/', $url)) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }
        $bare = trim($host, '[]');
        if (filter_var($bare, FILTER_VALIDATE_IP) === false
            && (preg_match('/\A[a-z0-9.-]+\z/i', $host) !== 1 || str_contains($host, '..'))
        ) {
            return false;
        }
        return strtolower((string) ($parts['scheme'] ?? '')) === 'https';
    }

    /**
     * Baixa um feed iCal externo com proteção contra SSRF:
     * - só https na porta 443 (isHttpsUrl), sem credenciais na URL;
     * - host resolvido uma única vez e fixado com CURLOPT_RESOLVE (evita DNS rebinding entre a checagem e o connect);
     * - recusa IPs privados, loopback, link-local, reservados e CGNAT;
     * - sem redirecionamentos (3xx é bad_url; o host do .ics deve ser o destino final);
     * - corpo limitado a MAX_BYTES.
     *
     * @return array{body: string, http: int}|array{error: string, http: int}
     */
    public static function fetch(string $url, int $timeout = self::FETCH_TIMEOUT): array
    {
        if (!self::isHttpsUrl($url)) {
            return ['error' => 'bad_url', 'http' => 0];
        }
        if (function_exists('exported_calendar_body')) {
            $local = exported_calendar_body($url);
            if (is_string($local) && $local !== '') {
                return self::acceptBody($local, 200);
            }
        }
        if (!function_exists('curl_init')) {
            return ['error' => 'http_5xx', 'http' => 0];
        }
        $target = self::resolveTarget($url);
        if (isset($target['error'])) {
            return ['error' => $target['error'], 'http' => 0];
        }
        $result = self::request($url, $target, $timeout);
        if (isset($result['error'])) {
            return $result;
        }
        [$code, $body] = $result;
        if ($code >= 300 && $code < 400) {
            return ['error' => 'bad_url', 'http' => $code];
        }
        return self::classify($code, $body);
    }

    /**
     * HTTP status to a feed result. 2xx goes through acceptBody (size and BEGIN:VCALENDAR).
     *
     * @return array{body: string, http: int}|array{error: string, http: int}
     */
    public static function classify(int $code, string $body): array
    {
        if ($code === 429 || $code >= 500) {
            return ['error' => 'http_5xx', 'http' => $code];
        }
        if ($code >= 400) {
            return ['error' => 'http_4xx', 'http' => $code];
        }
        if ($code >= 200 && $code < 300) {
            return self::acceptBody($body, $code);
        }
        return ['error' => 'http_5xx', 'http' => $code];
    }

    /**
     * @return array{body: string, http: int}|array{error: string, http: int}
     */
    public static function acceptBody(string $body, int $http): array
    {
        if (strlen($body) > self::MAX_BYTES) {
            return ['error' => 'too_big', 'http' => $http];
        }
        $clean = ltrim($body, "\xEF\xBB\xBF \t\r\n");
        if (!str_starts_with($clean, 'BEGIN:VCALENDAR')) {
            return ['error' => 'not_ics', 'http' => $http];
        }
        return ['body' => $clean, 'http' => $http];
    }

    /**
     * @return array{host: string, port: int, ip: string}|array{error: string}
     */
    public static function resolveTarget(string $url): array
    {
        if (strlen($url) > self::MAX_URL_LENGTH) {
            return ['error' => 'bad_url'];
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return ['error' => 'bad_url'];
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if ($scheme !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return ['error' => 'bad_url'];
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return ['error' => 'bad_url'];
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return ['error' => 'private_ip'];
        }
        $port = 443;
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $ips = [];
            $records = @dns_get_record($host, DNS_A + DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (!empty($record['ip'])) {
                        $ips[] = (string) $record['ip'];
                    }
                    if (!empty($record['ipv6'])) {
                        $ips[] = (string) $record['ipv6'];
                    }
                }
            }
            if ($ips === []) {
                $v4 = @gethostbynamel($host);
                $ips = is_array($v4) ? $v4 : [];
            }
        }
        if ($ips === []) {
            return ['error' => 'dns'];
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return ['error' => 'private_ip'];
            }
        }
        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    public static function isPublicIp(string $ip): bool
    {
        // Checagem por bytes ANTES do filter_var: o resultado do filter_var para endereços
        // IPv4 mapeados muda entre PHP 8.2 e 8.4.
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        // ::ffff:0:0/96 (IPv4 mapeado) e ::ffff:0:0:0/96 (IPv4 traduzido, SIIT): recusa sempre.
        if (strlen($bin) === 16 && (
            str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")
            || str_starts_with($bin, str_repeat("\0", 8) . "\xff\xff\0\0")
        )) {
            return false;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (strlen($bin) === 4) {
            $n = unpack('N', $bin)[1];
            $blocked = [
                ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8],
                ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.168.0.0', 16],
                ['198.18.0.0', 15], ['224.0.0.0', 4], ['240.0.0.0', 4],
            ];
            foreach ($blocked as [$net, $bits]) {
                $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
                if (($n & $mask) === (ip2long($net) & $mask)) {
                    return false;
                }
            }
            return true;
        }
        // Prefixos IPv6 que embutem/encaminham IPv4 ou não são roteáveis: recusa (conservador).
        $hex = bin2hex($bin);
        $blockedV6 = [
            ['00000000000000000000000000000000', 96], // ::/96 (IPv4-compatível, inclui ::)
            ['0064ff9b000000000000000000000000', 96], // 64:ff9b::/96 (NAT64)
            ['0064ff9b000100000000000000000000', 48], // 64:ff9b:1::/48 (NAT64 local)
            ['20020000000000000000000000000000', 16], // 2002::/16 (6to4)
            ['20010000000000000000000000000000', 32], // 2001::/32 (Teredo)
            ['20010db8000000000000000000000000', 32], // 2001:db8::/32 (documentação)
            ['01000000000000000000000000000000', 64], // 100::/64 (discard)
        ];
        foreach ($blockedV6 as [$net, $bits]) {
            $nibbles = intdiv($bits, 4);
            if (strncmp($hex, $net, $nibbles) === 0) {
                return false;
            }
        }
        $first = ord($bin[0]);
        // fc00::/7 (ULA), fe80::/10 (link-local), ff00::/8 (multicast)
        if (($first & 0xFE) === 0xFC || $first === 0xFF || ($first === 0xFE && (ord($bin[1]) & 0xC0) === 0x80)) {
            return false;
        }
        return true;
    }

    /**
     * Connects only to $target['ip'] (CURLOPT_RESOLVE), the address already checked by resolveTarget.
     *
     * @param array{host: string, port: int, ip: string} $target
     * @return array{0: int, 1: string, 2: string}|array{error: string, http: int}
     */
    private static function request(string $url, array $target, int $timeout = self::FETCH_TIMEOUT): array
    {
        $ip = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];
        $buffer = '';
        $tooBig = false;
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . $ip],
            CURLOPT_TIMEOUT => max(1, $timeout),
            CURLOPT_CONNECTTIMEOUT => min(self::CONNECT_TIMEOUT, max(1, $timeout)),
            CURLOPT_USERAGENT => 'CasaLivre/1.0',
            CURLOPT_HTTPHEADER => [
                'Accept: text/calendar, text/plain, */*',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
            ],
            CURLOPT_NOPROXY => '*',
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffer, &$tooBig): int {
                if (strlen($buffer) + strlen($chunk) > self::MAX_BYTES) {
                    $tooBig = true;
                    return 0;
                }
                $buffer .= $chunk;
                return strlen($chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'https';
        } elseif (defined('CURLPROTO_HTTP')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        if (defined('CURLOPT_MAXFILESIZE')) {
            $options[CURLOPT_MAXFILESIZE] = self::MAX_BYTES;
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
        if ($primaryIp !== '' && ($pinned === false || $connected !== $pinned || !self::isPublicIp($primaryIp))) {
            return ['error' => 'private_ip', 'http' => $code];
        }
        return [$code, $buffer, $location];
    }

    /**
     * SSRF-safe GET pinned to the resolved IP, no redirects, https only, capped body.
     *
     * @param array{host: string, port: int, ip: string} $target
     * @param list<string> $extraHeaders
     * @return array{0: int, 1: string, 2: string, 3: array<string, string>}|array{error: string, http: int}
     */
    private static function requestBytes(string $url, array $target, int $maxBytes, string $accept, int $timeout, array $extraHeaders = []): array
    {
        $ip = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];
        $buffer = '';
        $tooBig = false;
        $headers = [];
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . $ip],
            CURLOPT_TIMEOUT => max(1, $timeout),
            CURLOPT_CONNECTTIMEOUT => min(self::CONNECT_TIMEOUT, max(1, $timeout)),
            CURLOPT_USERAGENT => 'CasaLivre/1.0 (+https://casalivre.com.br)',
            CURLOPT_HTTPHEADER => array_merge([
                'Accept: ' . $accept,
                'Cache-Control: no-cache',
                'Pragma: no-cache',
            ], $extraHeaders),
            CURLOPT_NOPROXY => '*',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$headers): int {
                $line = trim($header);
                if ($line === '') {
                    return strlen($header);
                }
                if (preg_match('#\AHTTP/#i', $line) === 1) {
                    $headers = [];
                    return strlen($header);
                }
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffer, &$tooBig, $maxBytes): int {
                if (strlen($buffer) + strlen($chunk) > $maxBytes) {
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
        if (defined('CURLOPT_MAXFILESIZE')) {
            $options[CURLOPT_MAXFILESIZE] = $maxBytes;
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
        if ($primaryIp !== '' && ($pinned === false || $connected !== $pinned || !self::isPublicIp($primaryIp))) {
            return ['error' => 'private_ip', 'http' => $code];
        }
        $location = (string) (curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: '');
        return [$code, $buffer, $location, $headers];
    }

    /** Canonical URL for hashing/dedup: lowercase scheme/host, sorted query, no fragment/default port. */
    public static function normalizeUrl(string $url, bool $stripQuery = false): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['host'])) {
            return trim($url);
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) && (int) $parts['port'] !== 443 ? ':' . (int) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');
        if ($stripQuery) {
            return $scheme . '://' . $host . $port . rtrim($path, '/');
        }
        $query = '';
        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $params);
            if ($params !== []) {
                ksort($params);
                $query = '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            } else {
                $query = '?' . $parts['query'];
            }
        }
        return $scheme . '://' . $host . $port . $path . $query;
    }

    /** AES-256-GCM at rest (enc:v1:); legacy plaintext URLs pass through decrypt unchanged. */
    public static function encryptUrl(string $url): string
    {
        if ($url === '' || str_starts_with($url, 'enc:v1:')) {
            return $url;
        }
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('A extensão OpenSSL é necessária para proteger a URL do calendário.');
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($url, 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if (!is_string($cipher)) {
            throw new RuntimeException('Não foi possível proteger a URL do calendário.');
        }
        return 'enc:v1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decryptUrl(?string $value): string
    {
        $value = (string) $value;
        if (!str_starts_with($value, 'enc:v1:')) {
            return $value;
        }
        if (!function_exists('openssl_decrypt')) {
            return '';
        }
        $raw = base64_decode(substr($value, 7), true);
        if (!is_string($raw) || strlen($raw) < 28) {
            return '';
        }
        $plain = openssl_decrypt(
            substr($raw, 28),
            'aes-256-gcm',
            self::encryptionKey(),
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16)
        );
        return is_string($plain) ? $plain : '';
    }

    private static function encryptionKey(): string
    {
        if (class_exists('Db', false)) {
            Db::loadEnv();
        }
        $salt = (string) getenv('LOG_SALT') . '|' . (string) getenv('GEO_SALT') . '|casalivre-ota-ical-v1';
        return hash('sha256', $salt, true);
    }
}
