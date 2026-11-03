<?php
// Huge .ics feeds through import_ics: a realistic feed at CalendarSync::MAX_BYTES (2 MiB), a minimal-event count bomb,
// and a 20 MiB body (import_ics itself has no size cap; only fetch() caps at MAX_BYTES). Asserts bounded time and memory,
// the exact nights an independent model of the rules expects (window [today, max_calendar_day], first event wins the
// origin), idempotent re-import and an intact DB. PG only (t_temp_db). Timings and peak memory are printed.
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/helpers.php';
require_once CL_ROOT . '/src/HouseRepository.php';
require_once CL_ROOT . '/src/CalendarSync.php';

t_temp_db();
mt_srand(246);
$today = date('Y-m-d');
$day = static fn (int $offset): DateTimeImmutable => (new DateTimeImmutable($today))->modify(($offset >= 0 ? '+' : '') . $offset . ' days');

/** @return array{0:int,1:array<string,string>,2:float,3:float,4:string} count, days, seconds, peak MB over baseline, error */
$run = static function (int $propertyId, string $body): array {
    gc_collect_cycles();
    memory_reset_peak_usage();
    $base = memory_get_usage();
    $t = microtime(true);
    $err = '';
    $count = -1;
    try {
        $count = import_ics($propertyId, $body);
    } catch (Throwable $e) {
        $err = get_class($e) . ': ' . substr($e->getMessage(), 0, 200);
    }
    $secs = microtime(true) - $t;
    $peak = (memory_get_peak_usage() - $base) / 1048576;
    $days = [];
    foreach (HouseRepository::blockedDays($propertyId) as $row) {
        $days[(string) $row['day']] = (string) $row['origin'];
    }
    return [$count, $days, $secs, $peak, $err];
};
$report = static fn (string $label, array $r, int $bytes): string => sprintf('  %-34s %8.2f MiB %6d nights %7.2fs peak +%.1f MB', $label, $bytes / 1048576, $r[0], $r[2], $r[3]);

echo "realistic feed at MAX_BYTES\n";
$owner = t_seed_owner();
$pid = (int) $owner['house_id'];
$max = max_calendar_day(HouseRepository::find($pid));
$events = [];
$expected = [];
$size = 60;
$i = 0;
while (true) {
    $start = $day(mt_rand(-60, 400));
    $nights = mt_rand(1, 7);
    $blocked = mt_rand(0, 1) === 1;
    $ev = "BEGIN:VEVENT\r\nUID:" . sprintf('%08d', $i++) . "@airbnb.example\r\nDTSTAMP:20260101T000000Z\r\nDTSTART;VALUE=DATE:" . $start->format('Ymd')
        . "\r\nDTEND;VALUE=DATE:" . $start->modify("+$nights days")->format('Ymd') . "\r\nSUMMARY:" . ($blocked ? 'Airbnb (Not available)' : 'Reserved')
        . "\r\nDESCRIPTION:Reservation URL: https://www.airbnb.example/hosting/reservations/details/HM" . strtoupper(bin2hex(random_bytes(5)))
        . "\\nPhone Number (Last 4 Digits): " . mt_rand(1000, 9999) . "\r\nEND:VEVENT\r\n";
    if ($size + strlen($ev) > CalendarSync::MAX_BYTES) {
        break;
    }
    $size += strlen($ev);
    $events[] = $ev;
    for ($n = 0; $n < $nights; $n++) {
        $iso = $start->modify("+$n days")->format('Y-m-d');
        if ($iso >= $today && $iso <= $max && !isset($expected[$iso])) {
            $expected[$iso] = $blocked ? 'sync' : 'reserva';
        }
    }
}
$body = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Airbnb Inc//Hosting Calendar//EN\r\n" . implode('', $events) . "END:VCALENDAR\r\n";
t_ok(strlen($body) <= CalendarSync::MAX_BYTES && strlen($body) > CalendarSync::MAX_BYTES - 1024, 'body is just under MAX_BYTES (' . strlen($body) . ' bytes, ' . count($events) . ' events)');
$r = $run($pid, $body);
echo $report(count($events) . ' events', $r, strlen($body)) . "\n";
t_eq('', $r[4], 'no exception');
t_eq(count($expected), $r[0], 'nights match the model of the rules');
ksort($expected);
ksort($r[1]);
t_eq($expected, $r[1], 'every blocked day and origin match the model (first event wins)');
t_ok($r[2] < 20.0, sprintf('under 20 s (%.2f s)', $r[2]));
t_ok($r[3] < 128.0, sprintf('peak memory under +128 MB (%.1f MB)', $r[3]));

echo "re-import is idempotent\n";
$again = $run($pid, $body);
echo $report('same feed again', $again, strlen($body)) . "\n";
t_eq(0, $again[0], 'no new nights');
t_eq($r[1], $again[1], 'rows unchanged');
$dupes = db()->prepare('SELECT COUNT(*) - COUNT(DISTINCT day) FROM calendar_days WHERE property_id = ?');
$dupes->execute([$pid]);
t_eq(0, (int) $dupes->fetchColumn(), 'no duplicate day rows');

echo "imported days are not re-exported (no feed loop), so the export stays small\n";
$t = microtime(true);
$export = export_ics(HouseRepository::find($pid));
t_ok(strlen($export) < 4096, 'export of a feed-only calendar is tiny (' . strlen($export) . ' bytes, ' . round(microtime(true) - $t, 2) . ' s)');
t_eq(0, substr_count($export, 'BEGIN:VEVENT'), 'no imported day is exported');

echo "event count bomb: minimal events filling 2 MiB, all on one day\n";
$pid2 = (int) t_seed_owner()['house_id'];
$one = "BEGIN:VEVENT\nDTSTART:" . $day(5)->format('Ymd') . "\nEND:VEVENT\n";
$bomb = "BEGIN:VCALENDAR\n" . str_repeat($one, intdiv(CalendarSync::MAX_BYTES - 40, strlen($one))) . "END:VCALENDAR\n";
$r = $run($pid2, $bomb);
echo $report(intdiv(strlen($bomb), strlen($one)) . ' minimal events', $r, strlen($bomb)) . "\n";
t_eq(['', 1], [$r[4], $r[0]], 'one night, no exception');
t_ok($r[2] < 10.0, sprintf('under 10 s (%.2f s)', $r[2]));
t_ok($r[3] < 256.0, sprintf('peak memory under +256 MB (%.1f MB)', $r[3]));

echo "20 MiB body straight into import_ics (fetch caps at MAX_BYTES; this is the uncapped helper)\n";
$pid3 = (int) t_seed_owner()['house_id'];
$big = "BEGIN:VCALENDAR\r\n" . str_repeat(implode('', array_slice($events, 0, 1000)), 10) . "END:VCALENDAR\r\n";
$big = substr(str_repeat($big, (int) ceil(20 * 1048576 / strlen($big))), 0, 20 * 1048576);
$r = $run($pid3, $big);
echo $report('20 MiB', $r, strlen($big)) . "\n";
t_eq('', $r[4], 'no exception');
t_ok($r[0] > 0, 'nights imported');
t_ok($r[2] < 60.0, sprintf('under 60 s (%.2f s)', $r[2]));
t_ok($r[3] < 512.0, sprintf('peak memory under +512 MB (%.1f MB)', $r[3]));

echo "DB intact\n";
$bad = db()->prepare("SELECT COUNT(*) FROM calendar_days WHERE blocked = 1 AND (day < ? OR day > ? OR origin NOT IN ('reserva', 'sync'))");
$bad->execute([$today, $max]);
t_eq(0, (int) $bad->fetchColumn(), 'no blocked day outside the window or with an unknown origin');
t_done();
