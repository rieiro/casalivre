<?php
// Huge .ics feeds through import_ics and importFeed.
// A realistic feed fills CalendarSync::MAX_BYTES with MAX_EVENTS events and is checked against a model of the rules
// (window [today, max_calendar_day], first event wins the origin), then re-imported (diff writes 0).
// A minimal-event count bomb and a 20 MiB body are over the cap: import_ics returns too_big, writes nothing.
// Asserts bounded time and memory and an intact DB. PG only (t_temp_db). Timings and peak memory are printed.
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

/** @return array{parsed: array, seconds: float, peak: float, error: string} */
$parse = static function (int $propertyId, string $body): array {
    gc_collect_cycles();
    memory_reset_peak_usage();
    $base = memory_get_usage();
    $t = microtime(true);
    $err = '';
    $parsed = [];
    try {
        $parsed = import_ics($propertyId, $body);
    } catch (Throwable $e) {
        $err = get_class($e) . ': ' . substr($e->getMessage(), 0, 200);
    }
    return ['parsed' => $parsed, 'seconds' => microtime(true) - $t, 'peak' => (memory_get_peak_usage() - $base) / 1048576, 'error' => $err];
};
$report = static fn (string $label, array $r, int $bytes, int $nights): string => sprintf(
    '  %-34s %8.2f MiB %6d nights %7.2fs peak +%.1f MB',
    $label,
    $bytes / 1048576,
    $nights,
    $r['seconds'],
    $r['peak']
);

echo "realistic feed at MAX_BYTES (MAX_EVENTS events)\n";
$owner = t_seed_owner();
$pid = (int) $owner['house_id'];
$max = max_calendar_day(HouseRepository::find($pid));
$prefix = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Airbnb Inc//Hosting Calendar//EN\r\n";
$suffix = "END:VCALENDAR\r\n";
$room = CalendarSync::MAX_BYTES - strlen($prefix) - strlen($suffix);
$nEvents = CalendarSync::MAX_EVENTS;
$per = intdiv($room, $nEvents);
$rem = $room % $nEvents;
$events = [];
$expected = [];
$short = 0;
for ($i = 0; $i < $nEvents; $i++) {
    $start = $day(mt_rand(-60, 400));
    $nights = mt_rand(1, 7);
    $blocked = mt_rand(0, 1) === 1;
    $target = $per + ($i === $nEvents - 1 ? $rem : 0);
    $head = "BEGIN:VEVENT\r\nUID:" . sprintf('%08d', $i) . "@airbnb.example\r\nDTSTAMP:20260101T000000Z\r\nDTSTART;VALUE=DATE:" . $start->format('Ymd')
        . "\r\nDTEND;VALUE=DATE:" . $start->modify("+$nights days")->format('Ymd') . "\r\nSUMMARY:" . ($blocked ? 'Airbnb (Not available)' : 'Reserved')
        . "\r\nDESCRIPTION:";
    $tail = "\r\nEND:VEVENT\r\n";
    $pad = $target - strlen($head) - strlen($tail);
    if ($pad < 0 || $pad >= CalendarSync::MAX_EVENT_BYTES) {
        $short++;
        $pad = 0;
    }
    $events[] = $head . str_repeat('x', $pad) . $tail;
    for ($n = 0; $n < $nights; $n++) {
        $iso = $start->modify("+$n days")->format('Y-m-d');
        if ($iso >= $today && $iso <= $max && !isset($expected[$iso])) {
            $expected[$iso] = $blocked ? 'sync' : 'reserva';
        }
    }
}
t_eq(0, $short, 'every event fits under MAX_EVENT_BYTES');
$body = $prefix . implode('', $events) . $suffix;
t_ok(strlen($body) <= CalendarSync::MAX_BYTES && strlen($body) > CalendarSync::MAX_BYTES - 1024, 'body is just under MAX_BYTES (' . strlen($body) . ' bytes, ' . count($events) . ' events)');
t_eq(CalendarSync::MAX_EVENTS, count($events), 'event count is the cap, not over it');
$r = $parse($pid, $body);
$parsedNights = $r['parsed']['nights'] ?? [];
echo $report(count($events) . ' events', $r, strlen($body), count($parsedNights)) . "\n";
t_eq('', $r['error'], 'no exception');
t_eq(null, $r['parsed']['error'] ?? null, 'feed accepted');
$got = [];
foreach ($parsedNights as $i => $iso) {
    $got[$iso] = (string) $r['parsed']['origins'][$i];
}
ksort($expected);
ksort($got);
t_eq($expected, $got, 'every night and origin match the model (first event wins)');
$created = HouseRepository::addFeed($pid, (int) $owner['user_id'], 'https://feeds.example/huge.ics', 'Huge');
t_ok(is_array($created), 'realistic feed row');
$feedId = (int) $created['feed']['id'];
$wrote = HouseRepository::importFeed($pid, $feedId, $r['parsed']['nights'], $r['parsed']['origins'], 0);
t_eq(count($expected), $wrote['written'] ?? -1, 'importFeed writes each modeled night once');
t_eq($expected, $blockedOf($pid), 'stored rows match the model');
t_ok($r['seconds'] < 20.0, sprintf('under 20 s (%.2f s)', $r['seconds']));
t_ok($r['peak'] < 128.0, sprintf('peak memory under +128 MB (%.1f MB)', $r['peak']));

echo "re-import is idempotent\n";
$again = HouseRepository::importFeed($pid, $feedId, $r['parsed']['nights'], $r['parsed']['origins'], 0);
t_eq(['freed' => 0, 'written' => 0], $again, 'no-op sync writes 0 rows');
t_eq($expected, $blockedOf($pid), 'rows unchanged');
$dupes = db()->prepare('SELECT COUNT(*) - COUNT(DISTINCT day) FROM calendar_days WHERE property_id = ?');
$dupes->execute([$pid]);
t_eq(0, (int) $dupes->fetchColumn(), 'no duplicate day rows');

echo "imported days are not re-exported (no feed loop), so the export stays small\n";
$t = microtime(true);
$export = export_ics(HouseRepository::find($pid));
t_ok(strlen($export) < 4096, 'export of a feed-only calendar is tiny (' . strlen($export) . ' bytes, ' . round(microtime(true) - $t, 2) . ' s)');
t_eq(0, substr_count($export, 'BEGIN:VEVENT'), 'no imported day is exported');

echo "event count bomb: minimal events filling 2 MiB\n";
$pid2 = (int) t_seed_owner()['house_id'];
$one = "BEGIN:VEVENT\nDTSTART:" . $day(5)->format('Ymd') . "\nEND:VEVENT\n";
$copies = intdiv(CalendarSync::MAX_BYTES - 40, strlen($one));
t_ok($copies > CalendarSync::MAX_EVENTS, 'the bomb has more events than MAX_EVENTS (' . $copies . ')');
$bomb = "BEGIN:VCALENDAR\n" . str_repeat($one, $copies) . "END:VCALENDAR\n";
$r = $parse($pid2, $bomb);
echo $report($copies . ' minimal events', $r, strlen($bomb), count($r['parsed']['nights'] ?? [])) . "\n";
t_eq(['', 'too_big'], [$r['error'], $r['parsed']['error'] ?? null], 'the bomb fails the feed with too_big');
t_eq([], $blockedOf($pid2), 'too_big writes nothing');
t_ok($r['seconds'] < 10.0, sprintf('under 10 s (%.2f s)', $r['seconds']));
t_ok($r['peak'] < 256.0, sprintf('peak memory under +256 MB (%.1f MB)', $r['peak']));

echo "20 MiB body straight into import_ics (acceptBody caps fetch at MAX_BYTES; the helper rejects the event count)\n";
$pid3 = (int) t_seed_owner()['house_id'];
$chunk = implode('', array_slice($events, 0, 1000));
$big = "BEGIN:VCALENDAR\r\n" . str_repeat($chunk, 10) . "END:VCALENDAR\r\n";
$big = substr(str_repeat($big, (int) ceil(20 * 1048576 / strlen($big))), 0, 20 * 1048576);
$r = $parse($pid3, $big);
echo $report('20 MiB', $r, strlen($big), count($r['parsed']['nights'] ?? [])) . "\n";
t_eq(['', 'too_big'], [$r['error'], $r['parsed']['error'] ?? null], '20 MiB fails the feed with too_big');
t_eq([], $blockedOf($pid3), 'too_big writes nothing');
t_ok($r['seconds'] < 60.0, sprintf('under 60 s (%.2f s)', $r['seconds']));
t_ok($r['peak'] < 512.0, sprintf('peak memory under +512 MB (%.1f MB)', $r['peak']));

echo "DB intact\n";
$ids = implode(',', [$pid, $pid2, $pid3]);
$bad = db()->prepare("SELECT COUNT(*) FROM calendar_days WHERE property_id IN ($ids) AND blocked = 1 AND (day < ? OR day > ? OR origin NOT IN ('reserva', 'sync'))");
$bad->execute([$today, $max]);
t_eq(0, (int) $bad->fetchColumn(), 'no blocked day outside the window or with an unknown origin');
t_done();
