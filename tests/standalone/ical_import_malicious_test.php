<?php
// Malicious .ics input for the iCal import path (import_ics, CalendarSync SSRF guards, POST /api/imoveis/{hash}/ical/importar).
// import_ics returns the nights it would block and does not write; a successful parse is applied with importFeed.
// Every case must end clean: no exception, no HTTP 500, nights only inside [today, max_calendar_day], origins only
// 'reserva' or 'sync', other properties untouched. A VEVENT over MAX_EVENT_BYTES fails the feed with too_big.
// Known app defects print "FINDING" and do not fail the file; CL_ICS_STRICT=1 turns them into failures.
// Defects the calendar-sync worker already fixed are ordinary assertions.
// PG only (t_temp_db). fetch() never reaches the network: every URL is refused before a connection.
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/helpers.php';
require_once CL_ROOT . '/src/HouseRepository.php';
require_once CL_ROOT . '/src/CalendarSync.php';

t_temp_db();
$strict = getenv('CL_ICS_STRICT') === '1';
$finding = static function (bool $bad, string $what) use ($strict): void {
    if ($strict) {
        t_ok(!$bad, 'strict: ' . $what);
    } elseif ($bad) {
        echo "  FINDING $what\n";
    }
};
$ymd = static fn (int $days): string => date('Ymd', strtotime(date('Y-m-d') . ' ' . ($days >= 0 ? '+' : '') . $days . ' days'));
$iso = static fn (int $days): string => date('Y-m-d', strtotime(date('Y-m-d') . ' ' . ($days >= 0 ? '+' : '') . $days . ' days'));
$cal = static fn (string $events): string => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//evil//EN\r\n" . $events . "END:VCALENDAR\r\n";
$ev = static fn (string $start, string $end = '', string $extra = ''): string => "BEGIN:VEVENT\r\nUID:" . bin2hex(random_bytes(6))
    . "@evil\r\nDTSTART;VALUE=DATE:$start\r\n" . ($end !== '' ? "DTEND;VALUE=DATE:$end\r\n" : '') . $extra . "SUMMARY:Reserved\r\nEND:VEVENT\r\n";
$blockedOf = static function (int $propertyId): array {
    $days = [];
    foreach (HouseRepository::calendarDays($propertyId) as $row) {
        if ((int) $row['blocked'] !== 1) {
            continue;
        }
        $days[substr((string) $row['day'], 0, 10)] = (string) $row['origin'];
    }
    ksort($days);
    return $days;
};

$bystander = t_seed_owner();
db()->prepare("INSERT INTO calendar_days (property_id, day, blocked, origin) VALUES (?, ?, 1, 'sync')")
    ->execute([(int) $bystander['house_id'], $iso(3)]);
$bystanderDays = $blockedOf((int) $bystander['house_id']);

/**
 * Parses $body. On success, applies it with importFeed and checks the rows match.
 * Returns [count, day => origin, seconds, error message or '', property id, error code or null].
 *
 * @return array{0:int,1:array<string,string>,2:float,3:string,4:int,5:?string}
 */
$run = static function (string $body, ?int $propertyId = null, bool $apply = true) use ($blockedOf): array {
    $propertyId ??= (int) t_seed_owner()['house_id'];
    $t = microtime(true);
    $err = '';
    $count = -1;
    $days = [];
    $code = null;
    try {
        $parsed = import_ics($propertyId, $body);
        if (isset($parsed['error'])) {
            $code = (string) $parsed['error'];
            $count = 0;
        } else {
            foreach ($parsed['nights'] as $i => $isoNight) {
                $days[$isoNight] = (string) $parsed['origins'][$i];
            }
            ksort($days);
            $count = count($days);
            if ($apply) {
                $house = HouseRepository::find($propertyId);
                $created = HouseRepository::addFeed(
                    $propertyId,
                    (int) ($house['user_id'] ?? 0),
                    'https://feeds.example/' . bin2hex(random_bytes(8)) . '.ics',
                    'Evil'
                );
                if (!is_array($created)) {
                    $err = 'addFeed ' . (is_string($created) ? $created : 'failed');
                } else {
                    HouseRepository::importFeed($propertyId, (int) $created['feed']['id'], $parsed['nights'], $parsed['origins'], 0);
                    $stored = $blockedOf($propertyId);
                    if ($stored !== $days) {
                        $err = 'stored ' . count($stored) . ' nights, import_ics returned ' . count($days);
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $err = get_class($e) . ': ' . substr($e->getMessage(), 0, 200);
    }
    return [$count, $days, microtime(true) - $t, $err, $propertyId, $code];
};
$max = max_calendar_day(HouseRepository::find((int) $bystander['house_id']));
$today = date('Y-m-d');
$inWindow = static function (array $days) use ($max, $today): bool {
    foreach ($days as $day => $origin) {
        if ($day < $today || $day > $max || !in_array($origin, ['reserva', 'sync'], true)) {
            return false;
        }
    }
    return true;
};
$check = static function (string $label, array $r, ?int $expect = null, float $budget = 5.0, ?string $error = null) use ($inWindow): void {
    [$count, $days, $secs, $err, , $code] = $r;
    echo sprintf("  %-46s %6d nights %7.3fs%s\n", $label, $count, $secs, $code !== null ? " $code" : '');
    t_eq('', $err, "$label: no exception");
    t_ok($inWindow($days), "$label: nights inside [today, max] with a known origin");
    if ($error !== null) {
        t_eq($error, $code, "$label: $error");
        t_eq(0, $count, "$label: nothing imported");
    } elseif ($expect !== null) {
        t_eq(null, $code, "$label: feed accepted");
        t_eq($expect, $count, "$label: $expect nights");
    }
    t_ok($secs < $budget, sprintf('%s: under %.0f s (%.3f s)', $label, $budget, $secs));
};
$windowNights = (new DateTimeImmutable($today))->diff(new DateTimeImmutable($max))->days + 1;

echo "absurd dates\n";
$r = $run($cal($ev('00010101', '99991231')));
$check('0001-01-01..9999-12-31 (one event)', $r, $windowNights, 1.0);
$check('DTEND before DTSTART', $run($cal($ev($ymd(10), $ymd(5)))), 1);
$check('DTEND equal to DTSTART', $run($cal($ev($ymd(10), $ymd(10)))), 1);
$check('dates far in the past only', $run($cal($ev('19000101', '19991231'))), 0);
$check('dates after the horizon only', $run($cal($ev('30000101', '30010101'))), 0);
$check('DTSTART not 8 digits', $run($cal($ev('2026-11-10') . $ev('2611') . $ev('abcdefgh'))), 0);
$check('month 13 day 99 (overflow)', $run($cal($ev(substr($ymd(10), 0, 4) . '1399'))), 0);
$check('DTSTART 00000000', $run($cal($ev('00000000'))), 0);

echo "RRULE bombs are ignored (one occurrence)\n";
$check('RRULE SECONDLY COUNT=2^31', $run($cal($ev($ymd(7), '', "RRULE:FREQ=SECONDLY;COUNT=2147483647\r\n"))), 1);
$check('RRULE DAILY no end + 5000 EXDATE', $run($cal($ev($ymd(7), '', "RRULE:FREQ=DAILY\r\n" . str_repeat("EXDATE:{$ymd(8)}\r\n", 5000)))), 1);
$check('RRULE BYSETPOS bomb 100 KB', $run($cal($ev($ymd(7), '', 'RRULE:FREQ=YEARLY;BYDAY=' . str_repeat('MO,', 30000) . "MO\r\n"))), 1);

echo "structure abuse\n";
$check('nested VEVENT (first END closes)', $run($cal("BEGIN:VEVENT\r\n" . $ev($ymd(9)) . "END:VEVENT\r\n")), 1);
$check('END:VEVENT without BEGIN', $run($cal("END:VEVENT\r\nEND:VEVENT\r\n" . $ev($ymd(9)))), 1);
$check('unclosed VEVENT', $run($cal("BEGIN:VEVENT\r\nDTSTART:{$ymd(9)}\r\n")), 0);
$check('20k BEGIN:VEVENT, no END (quadratic scan?)', $run($cal(str_repeat("BEGIN:VEVENT\r\nDTSTART:{$ymd(9)}\r\n", 20000))), 0);
$check('5k-deep nested VCALENDAR/VEVENT', $run(str_repeat("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\n", 5000) . "DTSTART:{$ymd(9)}\r\n" . str_repeat("END:VEVENT\r\nEND:VCALENDAR\r\n", 5000)), 1);
$check('no VCALENDAR wrapper', $run($ev($ymd(9))), 1);
$check('VTODO/VJOURNAL only', $run($cal("BEGIN:VTODO\r\nDTSTART:{$ymd(9)}\r\nEND:VTODO\r\n")), 0);

echo "folding, CRLF, encoding\n";
$check('folded property name', $run($cal("BEGIN:VEVENT\r\nDTST\r\n ART:{$ymd(11)}\r\nEND:VEVENT\r\n")), 1);
$check('bare LF and tab folding', $run($cal("BEGIN:VEVENT\nDTSTART:" . substr($ymd(11), 0, 4) . "\n\t" . substr($ymd(11), 4) . "\nEND:VEVENT\n")), 1);
$check('CR-only line endings', $run(str_replace("\r\n", "\r", $cal($ev($ymd(11))))), 1);
$check('100k empty fold lines (1.1 MB event)', $run($cal("BEGIN:VEVENT\r\nDTSTART:{$ymd(11)}\r\n" . str_repeat("SUMMARY:x\r\n \r\n", 100000) . "END:VEVENT\r\n")), 0, 5.0, 'too_big');
$check('NUL bytes everywhere', $run(str_replace("\r\n", "\0\r\n\0", $cal($ev($ymd(12))))), null);
$check('UTF-16LE body', $run(mb_convert_encoding($cal($ev($ymd(12))), 'UTF-16LE', 'UTF-8')), 0);
$check('UTF-8 BOM + emoji/RTL summary', $run("\xEF\xBB\xBF" . $cal($ev($ymd(12), '', "SUMMARY:\u{202E}🏠 Bloqueado \u{0000}\r\n"))), 1);
$check('invalid UTF-8 bytes', $run($cal($ev($ymd(12), '', "SUMMARY:\xC3\x28\xA0\xA1\xFF\xFE\r\nDESCRIPTION:\xF0\x28\x8C\xBC\r\n"))), 1);
$check('1.9 MB single line', $run($cal($ev($ymd(13), '', 'SUMMARY:' . str_repeat('A', 1900000) . "\r\n"))), 0, 5.0, 'too_big');
$r = $run($cal($ev($ymd(14), '', "SUMMARY:'); DROP TABLE properties; --\r\nLOCATION:\\'; SELECT pg_sleep(10); --\r\n")));
$check('SQL in fields', $r, 1);
t_ok((int) db()->query('SELECT COUNT(*) FROM properties')->fetchColumn() > 0, 'properties table still there');

echo "summary classification\n";
$r = $run($cal("BEGIN:VEVENT\r\nDTSTART:{$ymd(15)}\r\nSUMMARY:Not available\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nDTSTART:{$ymd(16)}\r\nSUMMARY:João\r\nEND:VEVENT\r\n"));
$check('blocked vs reservation', $r, 2);
t_eq(['sync', 'reserva'], [$r[1][$iso(15)] ?? '', $r[1][$iso(16)] ?? ''], 'Not available -> sync, a guest name -> reserva');
$p = (int) t_seed_owner()['house_id'];
db()->prepare("INSERT INTO calendar_days (property_id, day, blocked, origin) VALUES (?, ?, 1, 'reserva')")->execute([$p, $iso(17)]);
$parsed = import_ics($p, $cal("BEGIN:VEVENT\r\nDTSTART:{$ymd(17)}\r\nSUMMARY:Blocked\r\nEND:VEVENT\r\n"));
t_eq([[$iso(17)], ['sync']], [$parsed['nights'] ?? [], $parsed['origins'] ?? []], 'the parser still names the night; the holder is decided by importFeed');
$house = HouseRepository::find($p);
$created = HouseRepository::addFeed($p, (int) $house['user_id'], 'https://feeds.example/hold.ics', 'Hold');
t_ok(is_array($created), 'reservation case has a feed');
$wrote = is_array($created)
    ? HouseRepository::importFeed($p, (int) $created['feed']['id'], $parsed['nights'], $parsed['origins'], 0)
    : null;
t_eq(0, $wrote['written'] ?? -1, 'an existing reservation is not overwritten by a feed');
t_eq('reserva', $blockedOf($p)[$iso(17)] ?? '', 'the reservation origin stays');
$r = $run($cal("BEGIN:VEVENT\r\nUID:casalivre-$p-1\r\nDTSTART:{$ymd(18)}\r\nEND:VEVENT\r\n"), $p, false);
t_eq(['', 0], [$r[3], $r[0]], 'own exported UID is skipped');
$r = $run($cal("BEGIN:VEVENT\r\nDESCRIPTION:moved from DTSTART:{$ymd(25)}\r\nDTSTART:{$ymd(19)}\r\nEND:VEVENT\r\n"));
$check('DTSTART text inside DESCRIPTION', $r, 1);
$finding(isset($r[1][$iso(25)]), 'import_ics takes the first "DTSTART:" substring anywhere in the event (also inside DESCRIPTION), not the DTSTART property; low impact (the feed owner controls the feed)');

echo "binary and other formats\n";
$check('1 MiB random bytes', $run(random_bytes(1 << 20)), 0);
$check('zip header + VEVENT text inside', $run("PK\x03\x04\x14\x00\x00\x00\x08\x00" . random_bytes(64) . $cal($ev($ymd(20)))), 1);
$check('gzip of a calendar', $run((string) gzencode($cal($ev($ymd(20))))), 0);
$xml = '<?xml version="1.0"?><!DOCTYPE l [<!ENTITY a "' . str_repeat('A', 100) . '"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">]><l>&b;</l>';
$check('XML entity bomb', $run($xml), 0);
$check('JSON/HTML error page', $run('<html><body>{"error":"BEGIN:VCALENDAR"}</body></html>'), 0);
$check('empty body', $run(''), 0);

echo "bystander property untouched\n";
t_eq($bystanderDays, $blockedOf((int) $bystander['house_id']), 'the other property keeps exactly its own blocked day');

echo "SSRF: fetch refuses before connecting\n";
$urls = [
    'http://example.com/a.ics', 'file:///etc/passwd', 'gopher://127.0.0.1:6379/_INFO', 'ftp://127.0.0.1/a.ics', 'javascript:alert(1)',
    'dict://127.0.0.1:11211/', 'https:///nohost', 'https://', "https://exa\r\nmple.com/", "https://example.com/\0.ics", ' https://example.com',
    'https://localhost/a.ics', 'https://LOCALHOST:8443/', 'https://foo.localhost/', 'https://127.0.0.1/', 'https://127.1/', 'https://0/',
    'https://0.0.0.0/', 'https://2130706433/', 'https://0x7f000001/', 'https://0177.0.0.1/', 'https://10.0.0.1/', 'https://172.16.0.1/',
    'https://192.168.0.1/', 'https://100.64.0.1/', 'https://169.254.169.254/latest/meta-data/', 'https://[::1]/', 'https://[::]/',
    'https://[::ffff:127.0.0.1]/', 'https://[::ffff:7f00:1]/', 'https://[fe80::1]/', 'https://[fc00::1]/', 'https://[fd00:ec2::254]/',
    'https://[64:ff9b::a9fe:a9fe]/', 'https://[2002:7f00:1::]/', 'https://[2001:0:4136:e378:8000:63bf:80ff:fffe]/', 'https://[ff02::1]/',
    'https://user:pass@93.184.216.34/', 'https://user@93.184.216.34/', 'https://224.0.0.1/', 'https://255.255.255.255/',
];
$slow = [];
foreach ($urls as $url) {
    $t = microtime(true);
    $result = CalendarSync::fetch($url, 2);
    $secs = microtime(true) - $t;
    t_ok(!isset($result['body']) && isset($result['error']), 'refused: ' . json_encode($url));
    if ($secs > 1.0) {
        $slow[] = sprintf('%s %.1fs', $url, $secs);
    }
}
t_eq([], $slow, 'every refusal is fast (no connection attempt)');
foreach (['127.0.0.1', '10.1.2.3', '169.254.169.254', '::1', '::ffff:10.0.0.1', 'fe80::1', 'fc00::1', '100.64.0.1', '0.0.0.0'] as $ip) {
    t_ok(!CalendarSync::isPublicIp($ip), "$ip is not public");
}
t_ok(CalendarSync::isPublicIp('93.184.216.34'), 'a public IPv4 is public (guard not inverted)');

echo "HTTP route: bad input, never a 500\n";
$base = t_server();
$owner = t_seed_owner();
$jar = (string) tempnam(sys_get_temp_dir(), 'icsjar');
$csrf = t_login($base, $jar, $owner['email'], $owner['password']);
$post = static function (array $payload) use ($base, $owner, $jar, $csrf): array {
    return t_http('POST', $base . '/api/imoveis/' . $owner['hash'] . '/ical/importar', (string) json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE),
        ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
};
$cases = [
    ['file:// URL', ['external_ical_url' => 'file:///etc/passwd', 'feed_title' => 'x'], 422],
    ['array URL', ['external_ical_url' => ['https://a'], 'feed_title' => 'x'], 422],
    ['number title', ['external_ical_url' => 'https://127.0.0.1/a.ics', 'feed_title' => 5], 422],
    ['URL with CRLF', ['external_ical_url' => "https://127.0.0.1/\r\nX-Evil: 1", 'feed_title' => 'x'], 422],
    ['loopback URL + client ics_body', ['external_ical_url' => 'https://127.0.0.1/a.ics', 'feed_title' => 'loop', 'ics_body' => $cal($ev($ymd(30)))], 200],
    ['metadata URL', ['external_ical_url' => 'https://169.254.169.254/latest/meta-data/', 'feed_title' => 'meta'], 200],
    ['100 KB URL', ['external_ical_url' => 'https://127.0.0.1/' . bin2hex(random_bytes(50000)), 'feed_title' => 'long'], 422],
];
$serverErrors = [];
foreach ($cases as [$label, $payload, $okStatus]) {
    $t = microtime(true);
    $resp = $post($payload);
    echo sprintf("  %-46s HTTP %d %6.3fs\n", $label, $resp['status'], microtime(true) - $t);
    t_eq($okStatus, $resp['status'], $label);
    if ($resp['status'] >= 500) {
        $serverErrors[] = $label;
    }
    if ($label === 'loopback URL + client ics_body') {
        $json = json_decode($resp['body'], true) ?: [];
        t_eq(0, $json['importadas'] ?? null, 'client ics_body imports nothing');
    }
}
t_eq([], $serverErrors, 'no 500 for bad import input');
t_eq([], $blockedOf((int) $owner['house_id']), 'nothing blocked by saved feeds or by the client ics_body');
$feeds = HouseRepository::feeds((int) $owner['house_id']);
t_eq(2, count($feeds), 'only the loopback and metadata URLs were saved; the 100 KB URL was rejected');
t_ok(count($feeds) <= HouseRepository::FEED_SLOTS, 'feed limit holds (' . count($feeds) . ')');
@unlink($jar);

t_done();
