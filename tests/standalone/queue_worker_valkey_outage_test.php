<?php
// Cleanup sprint #158 (Arquitetura #167): with QUEUE_DRIVER=valkey and Valkey down, scripts/queue-worker.php crashed
// with exit 255 and a stack trace in the runit log (and runit restarted it every second). Now it logs one ALERT line
// when the outage starts (stderr and error_log) and one when Valkey is back, never a stack trace, stays up and
// reconnects. The outage is a VALKEY_SOCKET symlink that dangles (Valkey gone) and then points at the dev env's
// socket (Valkey back). The worker uses VALKEY_QUEUE_NS so it does not reserve the real cl:q:mail. PG only for the
// rest of the suite; this file needs phpredis and VALKEY_SOCKET.
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/QueueWorkerLoop.php';

echo "worker PHP does not keep exception arguments\n";
$src = (string) file_get_contents(CL_ROOT . '/scripts/queue-worker.php');
t_eq(1, substr_count($src, "ini_set('zend.exception_ignore_args', '1')"), 'queue-worker.php sets zend.exception_ignore_args');
t_ok(str_contains((string) file_get_contents(CL_ROOT . '/ops/dev-env/sv/queue-worker/run'), '-d zend.exception_ignore_args=1'), 'dev-env run passes -d zend.exception_ignore_args=1');
t_ok(str_contains($src, 'QueueWorkerLoop::valkeyDown'), 'Valkey outage handler lives on QueueWorkerLoop');
t_ok(str_contains($src, 'QueueWorkerLoop::dbLost'), 'DB loss still goes through dbLost');

echo "other exceptions share the ALERT writer and the 5-30 s exit backoff\n";
$slept = [];
QueueWorkerLoop::$sleep = static function (int $s) use (&$slept): void {
    $slept[] = $s;
};
$mem = fopen('php://memory', 'w+');
QueueWorkerLoop::$stderr = $mem;
$errFile = (string) tempnam(sys_get_temp_dir(), 'qwlog');
$prevLog = (string) ini_get('error_log');
ini_set('error_log', $errFile);
$secret = 'SEGREDO-' . bin2hex(random_bytes(4));
$delay = QueueWorkerLoop::unexpected(new RuntimeException('VALKEY_PASSWORD_FILE ' . $errFile . ' secret=' . $secret));
ini_set('error_log', $prevLog);
QueueWorkerLoop::$sleep = null;
QueueWorkerLoop::$stderr = null;
rewind($mem);
$line = (string) stream_get_contents($mem);
fclose($mem);
t_eq([$delay], $slept, 'sleeps the delay it returns');
t_ok($delay >= QueueWorkerLoop::DB_LOST_SLEEP_MIN && $delay <= QueueWorkerLoop::DB_LOST_SLEEP_MAX, 'backoff is 5-30 s');
t_eq('queue-worker: ALERT RuntimeException, exiting in ' . $delay . " s so runit restarts the worker\n", $line, 'STDERR is the class only');
t_ok(!str_contains($line, $secret) && !str_contains($line, 'VALKEY_PASSWORD_FILE'), 'STDERR has no exception message');
$elog = (string) file_get_contents($errFile);
t_ok(str_contains($elog, 'CasaLivre ALERT: queue worker: RuntimeException, exiting in ' . $delay . ' s so runit restarts the worker.'), 'error_log gets the same line');
t_ok(!str_contains($elog, $secret), 'error_log has no exception message');
@unlink($errFile);

$real = (string) getenv('VALKEY_SOCKET');
if (!extension_loaded('redis') || $real === '') {
    echo "  SKIPPED (needs phpredis and VALKEY_SOCKET: run via ops/dev-env/test-pg.sh)\n";
    t_done();
}
t_temp_db();
putenv('SMTP_HOST');
$dir = sys_get_temp_dir() . '/qwvk' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
register_shutdown_function(static fn () => @exec('rm -rf ' . escapeshellarg($dir)));
$link = $dir . '/valkey.sock';
symlink($dir . '/gone.sock', $link); // dangling: connect fails as with Valkey stopped
$stderr = $dir . '/stderr';
$errorLog = $dir . '/php-error.log';
$ns = 't' . substr(bin2hex(random_bytes(8)), 0, 16);
$env = getenv();
$env['QUEUE_DRIVER'] = 'valkey';
$env['VALKEY_SOCKET'] = $link;
$env['VALKEY_QUEUE_NS'] = $ns;
$start = static fn (array $argv) => proc_open(
    [PHP_BINARY, '-d', 'error_log=' . $errorLog, '-d', 'zend.exception_ignore_args=1', CL_ROOT . '/scripts/queue-worker.php', ...$argv],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $stderr, 'w']],
    $pipes,
    CL_ROOT,
    $env
);
$wait = static function ($proc): array {
    $status = proc_get_status($proc);
    for ($i = 0; $i < 100 && $status['running']; $i++) {
        usleep(50000);
        $status = proc_get_status($proc);
    }
    if ($status['running']) {
        proc_terminate($proc, 9);
    }
    proc_close($proc);
    return $status;
};

echo "Valkey outage handler: one ALERT, also in error_log\n";
$slept = [];
QueueWorkerLoop::$sleep = static function (int $s) use (&$slept): void {
    $slept[] = $s;
};
$mem = fopen('php://memory', 'w+');
QueueWorkerLoop::$stderr = $mem;
$errFile = (string) tempnam(sys_get_temp_dir(), 'qwlog');
$prevLog = (string) ini_get('error_log');
ini_set('error_log', $errFile);
$down = new RedisException('socket ' . $link . ' down');
t_eq(false, QueueWorkerLoop::valkeyDown($down, false, false), 'stays up');
t_eq(false, QueueWorkerLoop::valkeyDown($down, false, false), 'still up on the next retry');
QueueWorkerLoop::valkeyUp();
t_eq(true, QueueWorkerLoop::valkeyDown($down, true, false), '--once asks the caller to exit');
ini_set('error_log', $prevLog);
QueueWorkerLoop::$sleep = null;
QueueWorkerLoop::$stderr = null;
rewind($mem);
$vlog = (string) stream_get_contents($mem);
fclose($mem);
t_eq([QueueWorkerLoop::VALKEY_RETRY_SECONDS, QueueWorkerLoop::VALKEY_RETRY_SECONDS], $slept, 'one-second pause, and --once does not sleep');
t_eq(2, substr_count($vlog, 'queue-worker: ALERT Valkey unavailable (RedisException), retrying every second'), 'one ALERT per outage');
t_eq(1, substr_count($vlog, 'queue-worker: Valkey reachable again, resuming'), 'one recovery line');
t_ok(!str_contains($vlog, $link), 'the line names no socket path');
$velog = (string) file_get_contents($errFile);
t_eq(2, substr_count($velog, 'CasaLivre ALERT: queue worker: Valkey unavailable (RedisException), retrying every second.'), 'Valkey ALERT also in error_log');
t_ok(!str_contains($velog, 'reachable again') && !str_contains($velog, $link), 'recovery is not an error_log line, and no path');
@unlink($errFile);

$sentinel = 'sentinel-' . $ns;
$realRedis = Valkey::connect();
$realRedis->lPush('cl:q:mail:ready', $sentinel);
register_shutdown_function(static function () use ($realRedis, $sentinel): void {
    try {
        $realRedis->lRem('cl:q:mail:ready', $sentinel, 0);
    } catch (Throwable) {
    }
});

echo "Valkey down: one line, no stack trace, no exit\n";
$proc = $start([]);
sleep(3); // about three iterations (a dangling socket fails at once; the 5 s worker timeout is for a freeze)
$log = (string) file_get_contents($stderr);
t_eq(true, proc_get_status($proc)['running'], 'worker still running with Valkey down (no crash loop)');
t_eq(1, substr_count($log, 'queue-worker: ALERT Valkey unavailable (RedisException), retrying every second'), 'one ALERT line for the whole outage');
t_ok(!str_contains($log, 'Stack trace') && !str_contains($log, 'PHP Fatal') && !str_contains($log, 'Uncaught'), 'no stack trace');
t_ok(!str_contains($log, $dir), 'the line names no socket path');
$elog = (string) file_get_contents($errorLog);
t_eq(1, substr_count($elog, 'CasaLivre ALERT: queue worker: Valkey unavailable (RedisException), retrying every second.'), 'the same ALERT in error_log');
t_ok(!str_contains($elog, $dir), 'error_log names no socket path');

echo "Valkey back: one line, the worker resumes, real cl:q:mail untouched\n";
unlink($link);
symlink($real, $link);
$back = '';
for ($i = 0; $i < 60 && !str_contains($back, 'reachable again'); $i++) {
    usleep(100000);
    $back = (string) file_get_contents($stderr);
}
t_eq(1, substr_count($back, 'queue-worker: Valkey reachable again, resuming'), 'one "reachable again" line after reconnecting');
t_eq(1, substr_count((string) file_get_contents($errorLog), 'CasaLivre ALERT: queue worker: Valkey unavailable'), 'recovery does not add another error_log ALERT');
t_eq(true, proc_get_status($proc)['running'], 'still running');
$left = $realRedis->lRange('cl:q:mail:ready', 0, -1) ?: [];
t_ok(in_array($sentinel, $left, true), 'worker did not take a job from cl:q:mail');
proc_terminate($proc, 15);
$status = $wait($proc);
t_eq([false, 0], [$status['running'], $status['exitcode']], 'SIGTERM: clean exit 0');
$left = $realRedis->lRange('cl:q:mail:ready', 0, -1) ?: [];
t_ok(in_array($sentinel, $left, true), 'still on cl:q:mail after the worker stopped');

echo "--once with Valkey down: one line, exit 1 (no stack trace)\n";
unlink($link);
symlink($dir . '/gone.sock', $link);
file_put_contents($stderr, '');
file_put_contents($errorLog, '');
$status = $wait($start(['--once']));
$log = (string) file_get_contents($stderr);
t_eq([false, 1], [$status['running'], $status['exitcode']], 'exit 1');
t_eq(1, substr_count($log, 'ALERT Valkey unavailable'), 'one ALERT line');
t_ok(!str_contains($log, 'Stack trace'), 'no stack trace');
t_eq(1, substr_count((string) file_get_contents($errorLog), 'CasaLivre ALERT: queue worker: Valkey unavailable'), 'error_log ALERT on --once too');

echo "other exception in the worker: class only, backoff, exit 1\n";
$envBad = $env;
$envBad['VALKEY_PASSWORD_FILE'] = $dir . '/missing-secret';
$stderrBad = $dir . '/stderr-bad';
$errorBad = $dir . '/error-bad';
file_put_contents($stderrBad, '');
$proc = proc_open(
    [PHP_BINARY, '-d', 'error_log=' . $errorBad, '-d', 'zend.exception_ignore_args=1', CL_ROOT . '/scripts/queue-worker.php'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $stderrBad, 'w']],
    $pipes,
    CL_ROOT,
    $envBad
);
$alert = '';
for ($i = 0; $i < 100 && !str_contains($alert, 'ALERT'); $i++) {
    usleep(50000);
    $alert = (string) file_get_contents($stderrBad);
}
t_ok(preg_match('/^queue-worker: ALERT RuntimeException, exiting in (\d+) s so runit restarts the worker$/m', $alert, $m) === 1, 'class only, same exit line as a lost DB');
t_ok(isset($m[1]) && (int) $m[1] >= 5 && (int) $m[1] <= 30, 'delay announced in 5-30 s (' . ($m[1] ?? '?') . ')');
t_ok(!str_contains($alert, 'Stack trace') && !str_contains($alert, 'Uncaught') && !str_contains($alert, 'not readable') && !str_contains($alert, 'missing-secret'), 'no stack and no message');
$elog = (string) file_get_contents($errorBad);
t_ok(str_contains($elog, 'CasaLivre ALERT: queue worker: RuntimeException, exiting in '), 'error_log gets the class only');
t_ok(!str_contains($elog, 'missing-secret') && !str_contains($elog, 'not readable'), 'error_log has no path or message');
t_eq(true, proc_get_status($proc)['running'], 'sleeping instead of exiting at once');
proc_terminate($proc, 15);
$status = proc_get_status($proc);
for ($i = 0; $i < 100 && $status['running']; $i++) {
    usleep(50000);
    $status = proc_get_status($proc);
}
if ($status['running']) {
    proc_terminate($proc, 9);
}
proc_close($proc);
t_eq([false, 1], [$status['running'], $status['exitcode']], 'SIGTERM during the sleep: exit 1');
$realRedis->lRem('cl:q:mail:ready', $sentinel, 0);
t_done();
