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
                $lines[] = "NOK  imóvel $propertyId  {$feed['url']}";
                if (function_exists('activity')) {
                    activity(ActivityLog::CALENDAR_SYNC, [
                        'imovel_id' => $propertyId,
                        'url' => (string) $feed['url'],
                        'resultado' => 'nok',
                    ]);
                }
                continue;
            }
            $written = import_ics($propertyId, $body, $blockedByProperty[$propertyId]);
            HouseRepository::markFeed((int) $feed['id'], 'OK', $now);
            $ok++;
            $lines[] = "OK   imóvel $propertyId  novas=$written  {$feed['url']}";
            if (function_exists('activity')) {
                activity(ActivityLog::CALENDAR_SYNC, [
                    'imovel_id' => $propertyId,
                    'url' => (string) $feed['url'],
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

    public static function fetch(string $url): ?string
    {
        if (function_exists('exported_calendar_body')) {
            $local = exported_calendar_body($url);
            if (is_string($local) && $local !== '') {
                return $local;
            }
        }
        if (!self::allowedCalendarUrl($url)) {
            return null;
        }
        $headers = "User-Agent: CasaLivre/1.0\r\nAccept: text/calendar, text/plain, */*\r\n";
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_USERAGENT => 'CasaLivre/1.0',
                CURLOPT_HTTPHEADER => ['Accept: text/calendar, text/plain, */*'],
            ];
            if (defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
                $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
                $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            }
            curl_setopt_array($ch, $options);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if (is_string($body) && str_contains($body, 'BEGIN:VCALENDAR') && ($code === 0 || ($code >= 200 && $code < 300))) {
                return $body;
            }
        }
        $context = stream_context_create([
            'http' => [
                'timeout' => 20,
                'follow_location' => 1,
                'header' => $headers,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        return is_string($body) && str_contains($body, 'BEGIN:VCALENDAR') ? $body : null;
    }

    private static function allowedCalendarUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            return false;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : [];
        if ($ips === [] && function_exists('dns_get_record')) {
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
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }
        return true;
    }
}
