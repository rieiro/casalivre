<?php
// Malicious .ics input for the iCal import path (import_ics, CalendarSync SSRF guards, POST /api/imoveis/{hash}/ical/importar).
// Every case must end with a clean result: no exception, no HTTP 500, blocked days only inside [today, max_calendar_day],
// origins only 'reserva' or 'sync', other properties untouched. Timings are printed. Known app defects are printed as
// "FINDING" lines and do not fail the file; CL_ICS_STRICT=1 turns them into failures (use it to verify a fix).
// PG only (t_temp_db). The HTTP part never reaches the network: every URL is refused before a connection.
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

$bystander = t_seed_owner();
HouseRepository::blockDay((int) $bystander['house_id'], $iso(3), 'sync');
$bystanderDays = HouseRepository::blockedDays((int) $bystander['house_id']);

/** Imports $body into a fresh property; returns [count, day => origin, seconds, error message or '']. */
$run = static function (string $body, ?int $propertyId = null): array {
    $propertyId ??= (int) t_seed_owner()['house_id'];
    $t = microtime(true);
    $err = '';
    $count = -1;
    try {
        $count = import_ics($propertyId, $body);
    } catch (Throwable $e) {
        $err = get_class($e) . ': ' . substr($e->getMessage(), 0, 200);
    }
    $days = [];
    foreach (HouseRepository::blockedDays($propertyId) as $row) {
        $days[(string) $row['day']] = (string) $row['origin'];
    }
    return [$count, $days, microtime(true) - $t, $err, $propertyId];
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
$check = static function (string $label, array $r, ?int $expect = null, float $budget = 5.0) use ($inWindow): void {
    [$count, $days, $secs, $err] = $r;
    echo sprintf("  %-46s %6d nights %7.3fs\n", $label, $count, $secs);
    t_eq('', $err, "$label: no exception");
    t_ok($inWindow($days), "$label: blocked days inside [today, max] with a known origin");
    t_eq(count($days), max(0, $count), "$label: return value matches the rows written");
    if ($expect !== null) {
        t_eq($expect, $count, "$label: $expect nights");
    }
    t_ok($secs < $budget, sprintf('%s: under %.0f s (%.3f s)', $label, $budget, $secs));
};
$windowNights = (int) ((strtotime($max) - strtotime($today)) / 86400) + 1;

echo "absurd dates\n";
$r = $run($cal($ev('00010101', '99991231')));
$check('0001-01-01..9999-12-31 (one event)', $r, $windowNights, 30.0);
$finding($r[2] > 1.0, sprintf('ical.php import_ics walks every day of DTSTART..DTEND before the window check: one event took %.2f s, a 2 MiB feed of them would take hours (clamp start/end to [today, max] before the loop)', $r[2]));
$check('DTEND before DTSTART', $run($cal($ev($ymd(10), $ymd(5)))), 1);
$check('DTEND equal to DTSTART', $run($cal($ev($ymd(10), $ymd(10)))), 1);
$check('dates far in the past only', $run($cal($ev('19000101', '19991231'))), 0);
$check('dates after the horizon only', $run($cal($ev('30000101', '30010101'))), 0);
$check('DTSTART not 8 digits', $run($cal($ev('2026-11-10') . $ev('2611') . $ev('abcdefgh'))), 0);
$r = $run($cal($ev(substr($ymd(10), 0, 4) . '1399')));
$check('month 13 day 99 (overflow)', $r);
$finding($r[0] !== 0, 'DTSTART with month 13 / day 99 is accepted (DateTimeImmutable::createFromFormat overflows instead of rejecting): ' . implode(',', array_keys($r[1])));
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
$check('CR-only line endings', $run(str_replace("\r\n", "\r", $cal($ev($ymd(11)))) ), 1);
$r = $run($cal("BEGIN:VEVENT\r\nDTSTART:{$ymd(11)}\r\n" . str_repeat("SUMMARY:x\r\n \r\n", 100000) . "END:VEVENT\r\n"));
$check('100k empty fold lines (1.1 MB event)', $r);
$finding($r[0] !== 1, 'a VEVENT larger than ~1 MB is dropped silently: preg_match_all(/BEGIN:VEVENT(.*?)END:VEVENT/s) hits pcre.backtrack_limit and import_ics returns 0 with no log, so the whole feed imports nothing');
$check('NUL bytes everywhere', $run(str_replace("\r\n", "\0\r\n\0", $cal($ev($ymd(12))))), null);
$check('UTF-16LE body', $run(mb_convert_encoding($cal($ev($ymd(12))), 'UTF-16LE', 'UTF-8')), 0);
$check('UTF-8 BOM + emoji/RTL summary', $run("\xEF\xBB\xBF" . $cal($ev($ymd(12), '', "SUMMARY:\u{202E}🏠 Bloqueado \u{0000}\r\n"))), 1);
$check('invalid UTF-8 bytes', $run($cal($ev($ymd(12), '', "SUMMARY:\xC3\x28\xA0\xA1\xFF\xFE\r\nDESCRIPTION:\xF0\x28\x8C\xBC\r\n"))), 1);
$r = $run($cal($ev($ymd(13), '', 'SUMMARY:' . str_repeat('A', 1900000) . "\r\n")));
$check('1.9 MB single line', $r);
$finding($r[0] !== 1, 'a 1.9 MB SUMMARY line drops the event (same pcre.backtrack_limit cause)');
$r = $run($cal($ev($ymd(14), '', "SUMMARY:'); DROP TABLE properties; --\r\nLOCATION:\\'; SELECT pg_sleep(10); --\r\n")));
$check('SQL in fields', $r, 1);
t_ok((int) db()->query('SELECT COUNT(*) FROM properties')->fetchColumn() > 0, 'properties table still there');

echo "summary classification\n";
$r = $run($cal("BEGIN:VEVENT\r\nDTSTART:{$ymd(15)}\r\nSUMMARY:Not available\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nDTSTART:{$ymd(16)}\r\nSUMMARY:João\r\nEND:VEVENT\r\n"));
$check('blocked vs reservation', $r, 2);
t_eq(['sync', 'reserva'], [$r[1][$iso(15)] ?? '', $r[1][$iso(16)] ?? ''], 'Not available -> sync, a guest name -> reserva');
$p = (int) t_seed_owner()['house_id'];
HouseRepository::blockDay($p, $iso(17), 'reserva');
$r = $run($cal("BEGIN:VEVENT\r\nDTSTART:{$ymd(17)}\r\nSUMMARY:Blocked\r\nEND:VEVENT\r\n"), $p);
t_eq(['reserva', 0], [$r[1][$iso(17)] ?? '', $r[0]], 'an existing reservation is not overwritten by a feed');
$r = $run($cal("BEGIN:VEVENT\r\nUID:casalivre-$p-1\r\nDTSTART:{$ymd(18)}\r\nEND:VEVENT\r\n"), $p);
t_eq(0, $r[0], 'own exported UID is skipped');
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
t_eq($bystanderDays, HouseRepository::blockedDays((int) $bystander['house_id']), 'the other property keeps exactly its own blocked day');

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
    $body = CalendarSync::fetch($url, 2);
    $secs = microtime(true) - $t;
    t_eq(null, $body, 'refused: ' . json_encode($url));
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
$before = HouseRepository::blockedDays((int) $owner['house_id']);
$cases = [
    ['file:// URL', ['external_ical_url' => 'file:///etc/passwd', 'feed_title' => 'x'], [422]],
    ['array URL', ['external_ical_url' => ['https://a'], 'feed_title' => 'x'], [422]],
    ['number title', ['external_ical_url' => 'https://127.0.0.1/a.ics', 'feed_title' => 5], [422]],
    ['URL with CRLF', ['external_ical_url' => "https://127.0.0.1/\r\nX-Evil: 1", 'feed_title' => 'x'], [422]],
    ['loopback URL + client ics_body', ['external_ical_url' => 'https://127.0.0.1/a.ics', 'feed_title' => 'loop', 'ics_body' => $cal($ev($ymd(30)))], [502]],
    ['metadata URL', ['external_ical_url' => 'https://169.254.169.254/latest/meta-data/', 'feed_title' => 'meta'], [502]],
    ['100 KB URL', ['external_ical_url' => 'https://127.0.0.1/' . bin2hex(random_bytes(50000)), 'feed_title' => 'long'], [422, 502]],
];
$serverErrors = [];
foreach ($cases as [$label, $payload, $okStatus]) {
    $t = microtime(true);
    $r = $post($payload);
    echo sprintf("  %-46s HTTP %d %6.3fs\n", $label, $r['status'], microtime(true) - $t);
    if ($r['status'] >= 500 && $r['status'] !== 502) {
        $serverErrors[] = $label;
    }
    $finding(!in_array($r['status'], $okStatus, true), "$label: HTTP {$r['status']} (expected " . implode('/', $okStatus) . ')');
}
t_eq([], array_diff($serverErrors, ['100 KB URL']), 'no 500 for bad import input');
$finding(in_array('100 KB URL', $serverErrors, true), 'a long https URL (100 KB here; PG refuses index rows over 8191 bytes) makes POST /api/imoveis/{hash}/ical/importar answer 500: calendar_feeds UNIQUE (property_id, url) btree cannot index it (\"index row requires 100040 bytes\"); HouseRepository::addFeed has no URL length limit');
t_eq($before, HouseRepository::blockedDays((int) $owner['house_id']), 'nothing blocked by refused feeds or by the client ics_body');
$feeds = HouseRepository::feeds((int) $owner['house_id']);
t_ok(count($feeds) <= 5, 'feed limit holds (' . count($feeds) . ')');
@unlink($jar);

t_done();
