<?php

declare(strict_types=1);

function ics_text(string $value): string
{
    return str_replace(["\\", "\r\n", "\n", "\r", ';', ','], ['\\\\', '\\n', '\\n', '', '\\;', '\\,'], $value);
}

function export_ics(array $property): string
{
    $title = ics_text((string) ($property['title'] ?? 'CasaLivre'));
    $stamp = gmdate('Ymd\THis\Z');
    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//CasaLivre//Calendario//PT',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'X-WR-CALNAME:' . $title,
    ];
    $groups = [];
    foreach (HouseRepository::exportDays((int) ($property['id'] ?? 0)) as $row) {
        $origin = $row['origin'];
        if ($origin === '' && $row['blocked']) {
            $origin = 'sistema';
        }
        $summary = match ($origin) {
            'reserva' => 'Reservado',
            'sync' => 'Indisponivel',
            default => 'Bloqueado',
        };
        $last = $groups[count($groups) - 1] ?? null;
        $next = null;
        try {
            $next = $last ? (new DateTimeImmutable($last['end']))->modify('+1 day')->format('Y-m-d') : null;
        } catch (Exception) {
            $next = null;
        }
        if ($last && $last['summary'] === $summary && $next === $row['day']) {
            $groups[count($groups) - 1]['end'] = $row['day'];
            continue;
        }
        $groups[] = ['start' => $row['day'], 'end' => $row['day'], 'summary' => $summary];
    }
    foreach ($groups as $group) {
        try {
            $start = new DateTimeImmutable($group['start']);
            $end = (new DateTimeImmutable($group['end']))->modify('+1 day');
        } catch (Exception) {
            continue;
        }
        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:casalivre-' . (int) ($property['id'] ?? 0) . '-' . $start->format('Ymd') . '-' . $end->format('Ymd') . '@casalivre.local';
        $lines[] = 'DTSTAMP:' . $stamp;
        $lines[] = 'DTSTART;VALUE=DATE:' . $start->format('Ymd');
        $lines[] = 'DTEND;VALUE=DATE:' . $end->format('Ymd');
        $lines[] = 'SUMMARY:' . ics_text($group['summary']);
        $lines[] = 'STATUS:CONFIRMED';
        $lines[] = 'TRANSP:OPAQUE';
        $lines[] = 'END:VEVENT';
    }
    $lines[] = 'END:VCALENDAR';
    return implode("\r\n", $lines) . "\r\n";
}

function exported_calendar_body(string $url): ?string
{
    $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
    if (!preg_match('#/ical/([a-f0-9]{32})$#', $path, $match)) {
        return null;
    }
    $source = HouseRepository::findByIcalHash($match[1]);
    if (!$source || (string) ($source['status'] ?? 'active') !== 'active') {
        return null;
    }
    return export_ics($source);
}

function import_ics(int $propertyId, string $body, ?array &$alreadyBlocked = null): int
{
    $body = preg_replace("/\r?\n[ \t]/", '', $body) ?? $body;
    $house = HouseRepository::find($propertyId);
    $max = max_calendar_day($house ?: null);
    HouseRepository::clearBlockedAfter($propertyId, $max);
    $today = date('Y-m-d');
    if ($alreadyBlocked === null) {
        $alreadyBlocked = [];
        foreach (HouseRepository::blockedDays($propertyId) as $row) {
            $alreadyBlocked[$row['day']] = (string) ($row['origin'] ?? '');
        }
    }
    if (!preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $body, $events)) {
        return 0;
    }
    $count = 0;
    foreach ($events[1] as $event) {
        if (preg_match('/UID:casalivre-' . $propertyId . '-/', $event)) {
            continue;
        }
        if (!preg_match('/DTSTART(?:;[^:\r\n]*)?:(\d{8})/', $event, $startMatch)) {
            continue;
        }
        $start = DateTimeImmutable::createFromFormat('!Ymd', $startMatch[1]);
        if (!$start) {
            continue;
        }
        $end = $start->modify('+1 day');
        if (preg_match('/DTEND(?:;[^:\r\n]*)?:(\d{8})/', $event, $endMatch)) {
            $parsedEnd = DateTimeImmutable::createFromFormat('!Ymd', $endMatch[1]);
            if ($parsedEnd && $parsedEnd > $start) {
                $end = $parsedEnd;
            }
        }
        $summary = '';
        if (preg_match('/SUMMARY:(.*)/', $event, $summaryMatch)) {
            $summary = strtolower(trim($summaryMatch[1]));
        }
        $origin = 'reserva';
        if (preg_match('/bloquead|indispon|ocupado|closed|not available|unavailable|blocked/', $summary)) {
            $origin = 'sync';
        }
        for ($day = $start; $day < $end; $day = $day->modify('+1 day')) {
            $iso = $day->format('Y-m-d');
            if ($iso > $max || $iso < $today) {
                continue;
            }
            $current = $alreadyBlocked[$iso] ?? null;
            if ($current === 'sync' || $current === 'reserva') {
                continue;
            }
            HouseRepository::blockDay($propertyId, $iso, $origin);
            $alreadyBlocked[$iso] = $origin;
            $count++;
        }
    }
    return $count;
}
