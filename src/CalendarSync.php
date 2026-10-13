<?php

declare(strict_types=1);

final class CalendarSync
{
    public static function run(): array
    {
        $feeds = HouseRepository::feedsForActiveAccounts();
        $blockedByProperty = [];
        $ok = 0;
        $nok = 0;
        $lines = [];
        foreach ($feeds as $feed) {
            $propertyId = (int) $feed['property_id'];
            if (!isset($blockedByProperty[$propertyId])) {
                $blockedByProperty[$propertyId] = [];
                foreach (HouseRepository::blockedDays($propertyId) as $row) {
                    $blockedByProperty[$propertyId][$row['day']] = (string) ($row['origin'] ?? '');
                }
            }
            $body = self::fetch((string) $feed['url']);
            $now = date('Y-m-d H:i:s');
            if ($body === null) {
                HouseRepository::markFeed((int) $feed['id'], 'NOK', $now);
                $nok++;
                $lines[] = "NOK  imóvel $propertyId  " . self::logRef((string) $feed['url']);
                if (function_exists('activity')) {
                    activity(ActivityLog::CALENDAR_SYNC, [
                        'imovel_id' => $propertyId,
                        'url' => self::logRef((string) $feed['url']),
                        'resultado' => 'nok',
                    ]);
                }
                continue;
            }
            $written = import_ics($propertyId, $body, $blockedByProperty[$propertyId]);
            HouseRepository::markFeed((int) $feed['id'], 'OK', $now);
            $ok++;
            $lines[] = "OK   imóvel $propertyId  novas=$written  " . self::logRef((string) $feed['url']);
            if (function_exists('activity')) {
                activity(ActivityLog::CALENDAR_SYNC, [
                    'imovel_id' => $propertyId,
                    'url' => self::logRef((string) $feed['url']),
                    'novas' => $written,
                    'resultado' => 'ok',
                ]);
            }
        }
        $summary = 'Sincronização de calendários: ' . count($feeds) . " links, $ok OK, $nok NOK";
        $log = dirname(__DIR__) . '/storage/logs/calendar-sync.log';
        $dir = dirname($log);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($log, date('c') . ' ' . $summary . "\n" . implode("\n", $lines) . "\n", FILE_APPEND);
        return ['feeds' => count($feeds), 'ok' => $ok, 'nok' => $nok, 'lines' => $lines, 'summary' => $summary];
    }

    /** Tamanho máximo aceito para um feed .ics (bytes). */
    public const MAX_BYTES = 2 * 1024 * 1024;
    /** Número máximo de redirecionamentos, cada um revalidado. */
    public const MAX_REDIRECTS = 3;

    /** Orçamento total (s) da ressincronização síncrona feita no DELETE de um feed. */
    public const RESYNC_BUDGET_SECONDS = 15.0;
    /** Timeout máximo (s) por feed durante a ressincronização síncrona. */
    public const RESYNC_FEED_TIMEOUT = 6;

    /**
     * Ressincroniza os feeds de um imóvel (usado após excluir um feed,
     * depois de releaseImported). Respeita um orçamento total de tempo: feeds
     * que não couberem são marcados como PENDENTE e ficam para o cron
     * (scripts/sync-calendars.php sincroniza todos os feeds).
     *
     * @return array{ok: int, nok: int, pendentes: int}
     */
    public static function resyncProperty(int $propertyId, float $budget = self::RESYNC_BUDGET_SECONDS): array
    {
        $deadline = microtime(true) + $budget;
        $ok = 0;
        $nok = 0;
        $pending = 0;
        foreach (HouseRepository::feeds($propertyId) as $feed) {
            $remaining = (int) floor($deadline - microtime(true));
            if ($remaining < 2) {
                HouseRepository::markFeedPending((int) $feed['id']);
                $pending++;
                continue;
            }
            $body = self::fetch((string) $feed['url'], min(self::RESYNC_FEED_TIMEOUT, $remaining));
            $now = date('Y-m-d H:i:s');
            if ($body === null) {
                HouseRepository::markFeed((int) $feed['id'], 'NOK', $now);
                $nok++;
                continue;
            }
            import_ics($propertyId, $body);
            HouseRepository::markFeed((int) $feed['id'], 'OK', $now);
            $ok++;
        }
        return ['ok' => $ok, 'nok' => $nok, 'pendentes' => $pending];
    }

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
     * Baixa um feed iCal externo com proteção contra SSRF:
     * - só http/https, sem credenciais na URL;
     * - host resolvido uma única vez e fixado com CURLOPT_RESOLVE (evita DNS rebinding);
     * - recusa IPs privados, loopback, link-local, reservados e CGNAT;
     * - redirecionamentos seguidos manualmente e revalidados a cada salto;
     * - corpo limitado a MAX_BYTES.
     */
    public static function fetch(string $url, int $timeout = 20): ?string
    {
        if (function_exists('exported_calendar_body')) {
            $local = exported_calendar_body($url);
            if (is_string($local) && $local !== '') {
                return $local;
            }
        }
        if (!function_exists('curl_init')) {
            return null;
        }
        $current = $url;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = self::resolveTarget($current);
            if ($target === null) {
                return null;
            }
            $result = self::request($current, $target, $timeout);
            if ($result === null) {
                return null;
            }
            [$code, $body, $location] = $result;
            if ($code >= 300 && $code < 400) {
                if ($location === '') {
                    return null;
                }
                $current = $location;
                continue;
            }
            if ($code >= 200 && $code < 300 && str_contains($body, 'BEGIN:VCALENDAR')) {
                return $body;
            }
            return null;
        }
        return null;
    }

    /**
     * @return array{host: string, port: int, ip: string}|null
     */
    public static function resolveTarget(string $url): ?array
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return null;
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
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
            return null;
        }
        // Conservador: se qualquer IP do host for interno, recusa.
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return null;
            }
        }
        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    public static function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        // IPv4 mapeado em IPv6 (::ffff:a.b.c.d) -> valida o IPv4.
        if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
            return self::isPublicIp((string) inet_ntop(substr($bin, 12)));
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
     * @param array{host: string, port: int, ip: string} $target
     * @return array{0: int, 1: string, 2: string}|null
     */
    private static function request(string $url, array $target, int $timeout = 20): ?array
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
            CURLOPT_CONNECTTIMEOUT => min(5, max(1, $timeout)),
            CURLOPT_USERAGENT => 'CasaLivre/1.0',
            CURLOPT_HTTPHEADER => ['Accept: text/calendar, text/plain, */*'],
            CURLOPT_NOPROXY => '*',
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffer, &$tooBig): int {
                if (strlen($buffer) + strlen($chunk) > self::MAX_BYTES) {
                    $tooBig = true;
                    return 0; // aborta a transferência
                }
                $buffer .= $chunk;
                return strlen($chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
        } elseif (defined('CURLPROTO_HTTP')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if (defined('CURLOPT_MAXFILESIZE')) {
            $options[CURLOPT_MAXFILESIZE] = self::MAX_BYTES;
        }
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $primaryIp = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $location = (string) (curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: '');
        if ($ok === false || $tooBig) {
            return null;
        }
        // Defesa extra: o IP efetivamente conectado precisa ser público.
        if ($primaryIp !== '' && !self::isPublicIp($primaryIp)) {
            return null;
        }
        return [$code, $buffer, $location];
    }
}
