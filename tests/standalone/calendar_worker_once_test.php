<?php
// Worker --once: in-process export (real fetch path) and a local php -S fixture.
require __DIR__ . '/lib.php';
t_temp_db();
$owner = t_seed_owner();
$src = t_seed_owner();
require_once CL_ROOT . '/src/ActivityLog.php';
$day = date('Y-m-d', strtotime('+8 days'));
db()->prepare('INSERT INTO calendar_days (property_id, day, blocked, origin) VALUES (?, ?, 1, \'sistema\')')->execute([$src['house_id'], $day]);
$feed = HouseRepository::addFeed((int) $owner['house_id'], (int) $owner['user_id'], 'https://casalivre.test/ical/' . $src['ical_hash'], 'Origem');
t_ok(is_array($feed), 'feed do export');

$run = static function (string $extra = '') use ($owner): array {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CL_ROOT . '/scripts/calendar-worker.php') . ' --once ' . $extra . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
};

echo "export in-process\n";
[$code, $text] = $run();
t_eq(0, $code, 'worker --once sai 0');
t_ok(!str_contains($text, $src['ical_hash']), 'a saída não ecoa o hash do feed');
$night = db()->prepare('SELECT origin, feed_id FROM calendar_days WHERE property_id = ? AND day = ?');
$night->execute([$owner['house_id'], $day]);
$row = $night->fetch();
// export_ics writes SUMMARY:Bloqueado for an owner night; the parser maps that to sync.
t_eq('sync', (string) ($row['origin'] ?? ''), 'a noite do outro imóvel entra como sync');
t_eq((int) $feed['feed']['id'], (int) ($row['feed_id'] ?? 0), 'feed_id é o feed importado');
$status = db()->query('SELECT last_status FROM calendar_feeds WHERE id = ' . (int) $feed['feed']['id'])->fetchColumn();
t_eq('OK', $status, 'last_status OK');

echo "php -S\n";
$sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$port = (int) substr(strrchr((string) stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$ymd = date('Ymd', strtotime('+12 days'));
$ymdEnd = date('Ymd', strtotime('+13 days'));
$router = tempnam(sys_get_temp_dir(), 'ics');
file_put_contents($router, '<?php
$path = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH);
if ($path === "/cal.ics") {
    header("Content-Type: text/calendar");
    echo "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART;VALUE=DATE:' . $ymd . '\r\nDTEND;VALUE=DATE:' . $ymdEnd . '\r\nSUMMARY:Blocked\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    return true;
}
http_response_code(404);
return true;
');
$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
register_shutdown_function(static function () use ($proc, $router): void {
    if (is_resource($proc)) {
        proc_terminate($proc);
    }
    @unlink($router);
});
$base = 'http://127.0.0.1:' . $port;
for ($i = 0; $i < 50 && @file_get_contents($base . '/cal.ics') === false; $i++) {
    usleep(20000);
}
db()->exec("UPDATE calendar_feeds SET next_sync_at = 'infinity'");
$remote = HouseRepository::addFeed((int) $owner['house_id'], (int) $owner['user_id'], 'https://feeds.example/cal.ics?t=SEGREDO', 'Remoto');
t_ok(is_array($remote), 'feed do php -S');
[$code, $text] = $run('--fixture ' . $base);
t_eq(0, $code, 'worker --fixture sai 0');
t_ok(!str_contains($text, 'SEGREDO'), 'a saída não ecoa o token');
$remoteDay = date('Y-m-d', strtotime('+12 days'));
$got = db()->prepare('SELECT feed_id FROM calendar_days WHERE property_id = ? AND day = ? AND blocked = 1');
$got->execute([$owner['house_id'], $remoteDay]);
t_eq((int) $remote['feed']['id'], (int) $got->fetchColumn(), 'o corpo do php -S bloqueou a noite');
$log = (string) @file_get_contents(CL_ROOT . '/storage/logs/calendar-sync.log');
t_ok(!str_contains($log, 'SEGREDO') && str_contains($log, 'feed=' . (int) $remote['feed']['id']), 'o log tem o id e não a URL');
t_eq('0600', substr(sprintf('%o', fileperms(CL_ROOT . '/storage/logs/calendar-sync.log')), -4), 'calendar-sync.log é 0600');

echo "loopback recusado\n";
db()->exec("UPDATE calendar_feeds SET next_sync_at = 'infinity'");
$loop = HouseRepository::addFeed((int) $owner['house_id'], (int) $owner['user_id'], 'https://127.0.0.1/secret.ics', 'Loop');
t_ok(is_array($loop), 'feed loopback é gravado (a checagem de IP é no fetch)');
[$code] = $run();
t_eq(0, $code, 'recusa SSRF não derruba o worker');
$err = db()->query('SELECT last_error FROM calendar_feeds WHERE id = ' . (int) $loop['feed']['id'])->fetchColumn();
t_eq('private_ip', $err, '127.0.0.1 vira private_ip');
$blocked = db()->prepare('SELECT COUNT(*) FROM calendar_days WHERE property_id = ? AND feed_id = ?');
$blocked->execute([$owner['house_id'], (int) $loop['feed']['id']]);
t_eq(0, (int) $blocked->fetchColumn(), 'o loopback não importa noites');

t_done();
