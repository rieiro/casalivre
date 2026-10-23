<?php
// Cleanup sprint #158 (Segurança #160 e): Valkey frozen with SIGSTOP (it accepts connections but never answers).
// The fail-closed buckets must answer 429 (Retry-After FAIL_CLOSED_RETRY_AFTER) within about the 100 ms
// IpRateLimiter::TIMEOUT, on an established connection (read timeout) and on a new one (AUTH read timeout); the
// fail-open buckets pass just as fast. Runs its own valkey-server on a private unix socket (never the dev env's),
// so it needs the valkey-server binary (ops/dev-env/test-pg.sh) and phpredis; skipped otherwise (CI: Valkey is a
// container there).
declare(strict_types=1);
require __DIR__ . '/lib.php';

$bin = trim((string) shell_exec('command -v valkey-server 2>/dev/null'));
if (!extension_loaded('redis') || $bin === '') {
    echo "  SKIPPED (needs phpredis and valkey-server: run via ops/dev-env/test-pg.sh)\n";
    t_done();
}
$dir = sys_get_temp_dir() . '/iprl-stop-' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
$sock = $dir . '/valkey.sock';
$pw = bin2hex(random_bytes(16));
file_put_contents($dir . '/app.secret', $pw);
file_put_contents($dir . '/valkey.acl', "user default off resetkeys resetchannels -@all\nuser cl_app on >$pw resetkeys ~cl:* resetchannels -@all +multi +exec +incr +decr +set +expire +ttl +get +del +auth +hello +ping\n");
$proc = proc_open([$bin, '--port', '0', '--unixsocket', $sock, '--unixsocketperm', '700', '--save', '', '--appendonly', 'no', '--aclfile', $dir . '/valkey.acl'], [0 => ['pipe', 'r'], 1 => ['file', $dir . '/out', 'w'], 2 => ['file', $dir . '/out', 'a']], $pipes);
$cleanup = static function () use (&$proc, $dir): void {
    if (is_resource($proc)) {
        proc_terminate($proc, 18); // SIGCONT, then SIGTERM
        proc_terminate($proc, 15);
        proc_close($proc);
    }
    exec('rm -rf ' . escapeshellarg($dir));
};
register_shutdown_function($cleanup);
for ($i = 0; $i < 100 && !file_exists($sock); $i++) {
    usleep(50000);
}
t_ok(file_exists($sock), 'private valkey-server up');
if (!file_exists($sock)) {
    echo (string) @file_get_contents($dir . '/out');
    t_done();
}
putenv('VALKEY_SOCKET=' . $sock);
putenv('VALKEY_USER=cl_app');
putenv('VALKEY_PASSWORD_FILE=' . $dir . '/app.secret');
$log = $dir . '/php.log';
ini_set('error_log', $log);
mkdir($dir . '/stamps', 0700);
IpRateLimiter::$stampDir = $dir . '/stamps';
IpRateLimiter::$namespace = 't' . getmypid() . ':';
IpRateLimiter::disconnect();
$time = static function (callable $f): array {
    $t0 = hrtime(true);
    $v = $f();
    return [$v, (int) ((hrtime(true) - $t0) / 1e6)];
};

echo "Valkey up\n";
foreach (IpRateLimiter::FAIL_CLOSED_BUCKETS as $b) {
    t_eq(0, IpRateLimiter::hit($b, 5, 900, '198.51.100.7'), "$b: allowed while Valkey answers");
}

echo "SIGSTOP, established connection (read timeout)\n";
proc_terminate($proc, 19); // SIGSTOP
usleep(100000);
foreach (IpRateLimiter::FAIL_CLOSED_BUCKETS as $b) {
    [$v, $ms] = $time(static fn () => IpRateLimiter::hit($b, 5, 900, '198.51.100.7'));
    t_eq(IpRateLimiter::FAIL_CLOSED_RETRY_AFTER, $v, "$b: 429 with Retry-After 30 (fail-closed)");
    t_ok($ms >= 60 && $ms < 300, "$b: within about 100 ms ($ms ms)");
}
[$v, $ms] = $time(static fn () => IpRateLimiter::hit('/api/busca', 60, 60, '198.51.100.7'));
t_ok($v === 0 && $ms < 300, "fail-open bucket: allowed, $ms ms");

echo "SIGSTOP, new connection (AUTH read timeout)\n";
IpRateLimiter::disconnect();
[$v, $ms] = $time(static fn () => IpRateLimiter::hit('/cadastro', 10, 3600, '198.51.100.8'));
t_eq(30, $v, '/cadastro on a new connection: 429 (fail-closed)');
t_ok($ms >= 60 && $ms < 300, "new connection: within about 100 ms ($ms ms)");
t_eq(1, substr_count((string) @file_get_contents($log), 'ip_rate_limiter: unavailable (RedisException), fail-closed'), 'one throttled log line for the whole outage');

echo "SIGCONT: counting resumes\n";
proc_terminate($proc, 18);
IpRateLimiter::disconnect();
t_eq(0, IpRateLimiter::hit('/cadastro', 10, 3600, '198.51.100.9'), 'Valkey back: allowed and counted again');
t_done();
