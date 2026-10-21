<?php
// IpRateLimiter on Valkey (cleanup sprint #158): limits, fixed-window TTL and Retry-After, IPv6 /64, HMAC key under
// cl:rl:, peek/reserve, and the fail mode of each bucket with Valkey down (Segurança #158: fail-closed 429 +
// Retry-After 30 on auth/email buckets, fail-open elsewhere, throttled log without IP). The Valkey part needs
// phpredis and VALKEY_SOCKET (ops/dev-env/test-pg.sh) and is skipped otherwise; the Valkey-down part always runs.
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/ActivityLog.php';

$ns0 = IpRateLimiter::$namespace;
$withValkey = extension_loaded('redis') && (string) getenv('VALKEY_SOCKET') !== '';

echo "key\n";
$salt = (string) getenv('LOG_SALT');
$k = (string) IpRateLimiter::key('/cadastro', '1.2.3.4');
t_ok(str_starts_with($k, 'cl:rl:' . $ns0 . '/cadastro:'), 'key under cl:rl:<bucket>: (cl_app ACL ~cl:*)');
t_eq(hash_hmac('sha256', "ratelimit\0" . '1.2.3.4', $salt), substr($k, -64), 'client part = HMAC-SHA256(LOG_SALT, "ratelimit\0" ip)');
t_ok(!str_contains($k, '1.2.3.4') && substr($k, -64) !== hash('sha256', '1.2.3.4'), 'no raw IP and not a plain hash');
t_eq(IpRateLimiter::key('b', '2001:db8:1:2::1'), IpRateLimiter::key('b', '2001:db8:1:2:ffff:1:2:3'), 'IPv6: same /64, same key');
t_ok(IpRateLimiter::key('b', '2001:db8:1:2::1') !== IpRateLimiter::key('b', '2001:db8:1:3::1'), 'IPv6: other /64, other key');
t_eq('1.2.3.4', IpRateLimiter::clientKey('::ffff:1.2.3.4'), 'IPv4-mapped IPv6 counts as IPv4');
putenv('LOG_SALT=');
putenv('APP_ENV=production');
t_eq(null, IpRateLimiter::key('b', '1.2.3.4'), 'no LOG_SALT outside dev: no key (limiter unavailable)');
putenv('APP_ENV=dev');
t_ok(str_starts_with((string) IpRateLimiter::key('b', '1.2.3.4'), 'cl:rl:'), 'no LOG_SALT in dev/local: fixed dev key');
putenv('APP_ENV');
putenv('LOG_SALT=' . $salt);

if ($withValkey) {
    $r = Valkey::connect();
    echo "limits and window\n";
    t_eq(0, IpRateLimiter::hit('b', 3, 60, '1.2.3.4'), 'hit 1 allowed');
    $ttl1 = $r->ttl((string) IpRateLimiter::key('b', '1.2.3.4'));
    t_ok($ttl1 > 0 && $ttl1 <= 60, "first hit sets the window TTL ($ttl1 s)");
    t_eq(0, IpRateLimiter::hit('b', 3, 60, '1.2.3.4'), 'hit 2 allowed');
    t_eq(0, IpRateLimiter::hit('b', 3, 60, '1.2.3.4'), 'hit 3 allowed');
    $wait = IpRateLimiter::hit('b', 3, 60, '1.2.3.4');
    t_ok($wait >= 59 && $wait <= 60, "4th refused, Retry-After = remaining window ($wait)");
    t_eq(0, IpRateLimiter::hit('b', 3, 60, '5.6.7.8'), 'other IP not affected');
    t_eq(0, IpRateLimiter::hit('other', 3, 60, '1.2.3.4'), 'other bucket not affected');
    t_ok($r->ttl((string) IpRateLimiter::key('b', '1.2.3.4')) <= $ttl1, 'later hits never extend the window (EXPIRE NX)');
    t_eq(0, IpRateLimiter::hit('w', 1, 2, '1.2.3.4'), 'short window: hit 1 allowed');
    $w = IpRateLimiter::hit('w', 1, 2, '1.2.3.4');
    t_ok($w >= 1 && $w <= 2, "short window: hit 2 refused, Retry-After $w");
    usleep(2300000);
    t_eq(0, IpRateLimiter::hit('w', 1, 2, '1.2.3.4'), 'next window allowed again (key expired)');
    for ($i = 1; $i <= 3; $i++) {
        IpRateLimiter::hit('v6', 3, 60, "2001:db8:1:2::$i");
    }
    t_ok(IpRateLimiter::hit('v6', 3, 60, '2001:db8:1:2:ffff:1:2:3') > 0, 'IPv6: other address in the same /64 is refused');
    t_eq(0, IpRateLimiter::hit('v6', 3, 60, '2001:db8:1:3::1'), 'IPv6: other /64 not affected');

    echo "peek and reserve\n";
    t_eq(0, IpRateLimiter::peek('pk', 2, 60, '1.2.3.4'), 'peek without state: allowed');
    IpRateLimiter::hit('pk', 2, 60, '1.2.3.4');
    t_eq(0, IpRateLimiter::peek('pk', 2, 60, '1.2.3.4'), 'peek at 1 of 2: allowed');
    t_eq(0, IpRateLimiter::peek('pk', 2, 60, '1.2.3.4'), 'peek does not count');
    IpRateLimiter::hit('pk', 2, 60, '1.2.3.4');
    $p = IpRateLimiter::peek('pk', 2, 60, '1.2.3.4');
    t_ok($p >= 59 && $p <= 60, "peek at 2 of 2: refused (Retry-After $p)");
    $acao = static fn (bool $feito): Closure => static fn (bool $cabe): bool => $feito;
    t_eq(false, IpRateLimiter::reserve('rs', 2, 60, '1.2.3.4', $acao(false)), 'reserve returns the action result');
    t_eq(0, IpRateLimiter::peek('rs', 2, 60, '1.2.3.4'), 'reserve: action not done is not counted (slot given back)');
    $seen = [];
    foreach ([1, 2, 3] as $_) {
        IpRateLimiter::reserve('rs', 2, 60, '1.2.3.4', static function (bool $cabe) use (&$seen): bool {
            $seen[] = $cabe;
            return true;
        });
    }
    t_eq([true, true, false], $seen, 'reserve: 3rd sees cabe=false');
    t_eq('2', $r->get((string) IpRateLimiter::key('rs', '1.2.3.4')), 'reserve: done without fitting is not counted');

    echo "resetClient\n";
    IpRateLimiter::hit('/cadastro', 1, 60, '127.0.0.1');
    IpRateLimiter::hit('/cadastro', 1, 60, '127.0.0.1');
    IpRateLimiter::resetClient('127.0.0.1', ['/cadastro', '/api/busca']);
    t_eq(0, IpRateLimiter::hit('/cadastro', 1, 60, '127.0.0.1'), 'resetClient clears that client');

    echo "cl_app ACL\n";
    $r->clearLastError();
    try {
        $out = $r->multi()->incr('rl:x')->expire('rl:x', 60, 'NX')->exec();
        $refused = $out === false || in_array(false, (array) $out, true);
    } catch (RedisException $e) {
        $refused = str_contains($e->getMessage(), 'NOPERM');
    }
    t_ok($refused, 'cl_app: the same MULTI INCR/EXPIRE NX outside cl:* is refused');
    IpRateLimiter::disconnect();
} else {
    echo "  SKIPPED Valkey part (needs phpredis and VALKEY_SOCKET: run via ops/dev-env/test-pg.sh)\n";
}

echo "Valkey down\n";
$log = (string) tempnam(sys_get_temp_dir(), 'iprl');
$prevLog = (string) ini_get('error_log');
ini_set('error_log', $log);
$stamps = sys_get_temp_dir() . '/cl-iprl-stamp-' . bin2hex(random_bytes(4));
mkdir($stamps, 0700);
IpRateLimiter::$stampDir = $stamps;
$actDb = (string) tempnam(sys_get_temp_dir(), 'iprlact');
putenv('ACTIVITY_DB_PATH=' . $actDb);
putenv('LOG_DRIVER=sqlite');
$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
IpRateLimiter::$connector = static fn (): Redis => throw new RedisException('Connection refused');
foreach (IpRateLimiter::FAIL_CLOSED_BUCKETS as $b) {
    t_eq(IpRateLimiter::FAIL_CLOSED_RETRY_AFTER, IpRateLimiter::hit($b, 5, 900), "fail-closed bucket $b: refused, Retry-After 30");
}
t_eq(30, IpRateLimiter::FAIL_CLOSED_RETRY_AFTER, 'fail-closed Retry-After is 30 s');
foreach (['/api/cidades', '/api/ceps', '/api/busca', '/api/imoveis/*/avaliar', '/api/casas/*/fotos'] as $b) {
    t_eq(0, IpRateLimiter::hit($b, 5, 900), "fail-open bucket $b: allowed");
}
$buckets = array_unique(array_column(require CL_ROOT . '/seguranca/api_limites.php', 4));
foreach ($buckets as $b) {
    t_eq(in_array($b, ['/cadastro', '/senha/esqueci'], true), IpRateLimiter::failClosed($b), "api_limites bucket $b: " . (IpRateLimiter::failClosed($b) ? 'fail-closed' : 'fail-open'));
}
$got = null;
IpRateLimiter::reserve('ativacao-reenvio', 3, 3600, 'conta:x', static function (bool $cabe) use (&$got): bool {
    $got = $cabe;
    return $cabe;
});
t_eq(false, $got, 'reserve on fail-closed bucket (activation resend): action gets cabe=false (no email)');
IpRateLimiter::reserve('outro', 3, 3600, 'conta:x', static function (bool $cabe) use (&$got): bool {
    $got = $cabe;
    return $cabe;
});
t_eq(true, $got, 'reserve on fail-open bucket: action runs (uncounted)');
t_eq(0, IpRateLimiter::peek('/api/busca', 5, 60), 'peek on fail-open bucket: allowed');
$lines = array_values(array_filter(explode("\n", (string) file_get_contents($log)), static fn ($l) => str_contains($l, 'ip_rate_limiter')));
t_eq(1, count($lines), 'many failures in a row: one log line per LOG_EVERY s per worker');
t_ok(str_contains($lines[0] ?? '', 'fail-closed') && str_contains($lines[0] ?? '', 'RedisException'), 'log line names the mode and exception class');
t_ok(!str_contains((string) file_get_contents($log), '203.0.113'), 'log line has no IP');
$rows = ActivityLog::store()->recent(10, ActivityLog::INTERNAL_ERROR);
t_eq(1, count($rows), 'one INTERNAL_ERROR activity row (security class)');
t_eq('ip_rate_limiter_unavailable', $rows[0]['params']['tipo'] ?? null, 'activity row tipo');
t_ok(!str_contains(json_encode($rows[0]['params']) ?: '', '203.0.113'), 'activity params have no IP');
// A new worker (or a minute later): logs again.
IpRateLimiter::$stampDir = $stamps . '/other';
mkdir(IpRateLimiter::$stampDir, 0700);
(static fn () => IpRateLimiter::$loggedAt = null)->bindTo(null, IpRateLimiter::class)();
IpRateLimiter::hit('/api/busca', 5, 60);
$n = substr_count((string) file_get_contents($log), 'ip_rate_limiter');
t_eq(2, $n, 'after the throttle expires: logs again (fail-open line)');

echo "Valkey down (real socket, ~100 ms timeout)\n";
IpRateLimiter::$connector = null;
IpRateLimiter::disconnect();
$sock = getenv('VALKEY_SOCKET');
putenv('VALKEY_SOCKET=' . sys_get_temp_dir() . '/no-valkey-' . bin2hex(random_bytes(3)) . '.sock');
putenv('VALKEY_USER=cl_app');
if (getenv('VALKEY_PASSWORD_FILE') === false) {
    putenv('VALKEY_PASSWORD_FILE=' . $log);
}
$t0 = microtime(true);
$closed = IpRateLimiter::hit('/cadastro', 10, 3600);
$open = IpRateLimiter::hit('/api/busca', 60, 60);
$ms = (int) ((microtime(true) - $t0) * 1000);
t_eq([30, 0], [$closed, $open], 'socket missing: /cadastro 30 (closed), /api/busca 0 (open)');
t_ok($ms < 1000, "socket missing: answers fast ($ms ms for two calls)");
if (extension_loaded('redis')) {
    // Hung Valkey: the socket accepts (listen backlog) but never answers, so AUTH hits the 100 ms read timeout.
    $hung = sys_get_temp_dir() . '/hung-valkey-' . bin2hex(random_bytes(3)) . '.sock';
    $srv = stream_socket_server('unix://' . $hung, $errno, $errstr);
    putenv('VALKEY_SOCKET=' . $hung);
    IpRateLimiter::disconnect();
    $t0 = microtime(true);
    $closed = IpRateLimiter::hit('/cadastro', 10, 3600);
    $ms = (int) ((microtime(true) - $t0) * 1000);
    t_eq(30, $closed, 'hung socket: /cadastro refused (fail-closed)');
    t_ok($ms >= 80 && $ms < 600, "hung socket: gives up after the ~100 ms read timeout ($ms ms)");
    fclose($srv);
    @unlink($hung);
}
$sock === false ? putenv('VALKEY_SOCKET') : putenv('VALKEY_SOCKET=' . $sock);
IpRateLimiter::disconnect();

ini_set('error_log', $prevLog);
@unlink($log);
@unlink($actDb);
exec('rm -rf ' . escapeshellarg($stamps));
IpRateLimiter::$stampDir = null;
t_done();
