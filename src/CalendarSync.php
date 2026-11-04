<?php

declare(strict_types=1);

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
    public const FETCH_TIMEOUT = 20;
    public const CONNECT_TIMEOUT = 5;

    /** Referência de log para uma URL de feed: só host + hash (a URL pode conter token). */
    public static function logRef(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }
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
        return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && (string) ($parts['host'] ?? '') !== '';
    }

    /**
     * Baixa um feed iCal externo com proteção contra SSRF:
     * - só https na porta 443 (isHttpsUrl), sem credenciais na URL;
     * - host resolvido uma única vez e fixado com CURLOPT_RESOLVE (evita DNS rebinding entre a checagem e o connect);
     * - recusa IPs privados, loopback, link-local, reservados e CGNAT;
     * - redirecionamentos seguidos manualmente e revalidados a cada salto;
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
        $current = $url;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = self::resolveTarget($current);
            if (isset($target['error'])) {
                return ['error' => $target['error'], 'http' => 0];
            }
            $result = self::request($current, $target, $timeout);
            if (isset($result['error'])) {
                return $result;
            }
            [$code, $body, $location] = $result;
            if ($code >= 300 && $code < 400) {
                if ($location === '' || $hop === self::MAX_REDIRECTS) {
                    return ['error' => 'bad_url', 'http' => $code];
                }
                $current = $location;
                continue;
            }
            return self::classify($code, $body);
        }
        return ['error' => 'bad_url', 'http' => 0];
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
        if (!str_contains($body, 'BEGIN:VCALENDAR')) {
            return ['error' => 'not_ics', 'http' => $http];
        }
        return ['body' => $body, 'http' => $http];
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
            CURLOPT_HTTPHEADER => ['Accept: text/calendar, text/plain, */*'],
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
}
