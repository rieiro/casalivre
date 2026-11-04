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

/**
 * Nights this feed wants, from today through max_calendar_day. Does not write.
 * An invalid DTSTART or DTEND skips that event (the rest of the feed still imports).
 * A VEVENT over MAX_EVENT_BYTES, or more than MAX_EVENTS events, fails the feed with too_big.
 * Body size and BEGIN:VCALENDAR are CalendarSync::acceptBody, before this runs.
 * RRULE/RDATE are ignored. STATUS:CANCELLED contributes no nights (the diff then frees them).
 * The first holder of a night is decided by the import SQL, not here.
 *
 * @return array{nights: list<string>, origins: list<string>}|array{error: string}
 */
function import_ics(int $propertyId, string $body): array
{
    $body = preg_replace("/\r?\n[ \t]/", '', $body) ?? $body;
    $house = HouseRepository::find($propertyId);
    $max = max_calendar_day($house ?: null);
    $today = date('Y-m-d');
    $matched = preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $body, $events);
    if ($matched === false) {
        return ['error' => 'too_big'];
    }
    $found = $events[1];
    if (count($found) > CalendarSync::MAX_EVENTS) {
        return ['error' => 'too_big'];
    }
    if ($found === []) {
        return ['nights' => [], 'origins' => []];
    }
    $ymd = static function (string $raw): ?DateTimeImmutable {
        $dt = DateTimeImmutable::createFromFormat('!Ymd', $raw);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$dt instanceof DateTimeImmutable) {
            return null;
        }
        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }
        return $dt->format('Ymd') === $raw ? $dt : null;
    };
    $windowStart = new DateTimeImmutable($today);
    $windowEnd = (new DateTimeImmutable($max))->modify('+1 day');
    $nights = [];
    $origins = [];
    $seen = [];
    foreach ($found as $event) {
        if (strlen($event) > CalendarSync::MAX_EVENT_BYTES) {
            return ['error' => 'too_big'];
        }
        if (preg_match('/UID:casalivre-' . $propertyId . '-/', $event)) {
            continue;
        }
        if (preg_match('/(?:^|[\r\n])STATUS:CANCELLED/i', $event)) {
            continue;
        }
        if (!preg_match('/DTSTART(?:;[^:\r\n]*)?:(\d{8})/', $event, $startMatch)) {
            continue;
        }
        $start = $ymd($startMatch[1]);
        if ($start === null) {
            continue;
        }
        $end = $start->modify('+1 day');
        if (preg_match('/DTEND(?:;[^:\r\n]*)?:(\d{8})/', $event, $endMatch)) {
            $parsedEnd = $ymd($endMatch[1]);
            if ($parsedEnd === null) {
                continue;
            }
            if ($parsedEnd > $start) {
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
        $from = $start > $windowStart ? $start : $windowStart;
        $to = $end < $windowEnd ? $end : $windowEnd;
        for ($day = $from; $day < $to; $day = $day->modify('+1 day')) {
            $iso = $day->format('Y-m-d');
            if (isset($seen[$iso])) {
                continue;
            }
            $seen[$iso] = true;
            $nights[] = $iso;
            $origins[] = $origin;
        }
    }
    return ['nights' => $nights, 'origins' => $origins];
}
