<?php
// Cleanup sprint #158 (Arquitetura #167): with QUEUE_DRIVER=valkey and Valkey down, scripts/queue-worker.php crashed
// with exit 255 and a stack trace in the runit log (and runit restarted it every second). Now it logs one ALERT line
// when the outage starts and one when Valkey is back, never a stack trace, stays up and reconnects. The outage is a
// VALKEY_SOCKET symlink that dangles (Valkey gone) and then points at the dev env's socket (Valkey back). PG only.
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';

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
$env = getenv();
$env['QUEUE_DRIVER'] = 'valkey';
$env['VALKEY_SOCKET'] = $link;
$start = static fn (array $argv) => proc_open([PHP_BINARY, CL_ROOT . '/scripts/queue-worker.php', ...$argv], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $stderr, 'w']], $pipes, CL_ROOT, $env);
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

echo "Valkey down: one line, no stack trace, no exit\n";
$proc = $start([]);
sleep(3); // about three iterations
$log = (string) file_get_contents($stderr);
t_eq(true, proc_get_status($proc)['running'], 'worker still running with Valkey down (no crash loop)');
t_eq(1, substr_count($log, 'queue-worker: ALERT Valkey unavailable (RedisException), retrying every second'), 'one ALERT line for the whole outage');
t_ok(!str_contains($log, 'Stack trace') && !str_contains($log, 'PHP Fatal') && !str_contains($log, 'Uncaught'), 'no stack trace');
t_ok(!str_contains($log, $dir), 'the line names no socket path');

echo "Valkey back: one line, the worker resumes\n";
unlink($link);
symlink($real, $link);
$back = '';
for ($i = 0; $i < 60 && !str_contains($back, 'reachable again'); $i++) {
    usleep(100000);
    $back = (string) file_get_contents($stderr);
}
t_eq(1, substr_count($back, 'queue-worker: Valkey reachable again, resuming'), 'one "reachable again" line after reconnecting');
t_eq(true, proc_get_status($proc)['running'], 'still running');
proc_terminate($proc, 15);
$status = $wait($proc);
t_eq([false, 0], [$status['running'], $status['exitcode']], 'SIGTERM: clean exit 0');

echo "--once with Valkey down: one line, exit 1 (no stack trace)\n";
unlink($link);
symlink($dir . '/gone.sock', $link);
file_put_contents($stderr, '');
$status = $wait($start(['--once']));
$log = (string) file_get_contents($stderr);
t_eq([false, 1], [$status['running'], $status['exitcode']], 'exit 1');
t_eq(1, substr_count($log, 'ALERT Valkey unavailable'), 'one ALERT line');
t_ok(!str_contains($log, 'Stack trace'), 'no stack trace');
t_done();
