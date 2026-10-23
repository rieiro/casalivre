<?php
// Cleanup sprint #158 (Arquitetura #142): the long-running queue worker never reconnected to the DB, so after a PG
// restart every mail job failed until it was given up. Now each QueueWorkerLoop::tick pings the DB first, a lost
// connection is not counted as a job failure, and scripts/queue-worker.php exits 1 so runit restarts it with a fresh
// connection. Here the worker's backend is dropped with pg_terminate_backend (same role) mid-batch and between ticks;
// after the restart every job is delivered exactly once (none lost, none duplicated, no attempt spent). PG only.
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/helpers.php';
require_once CL_ROOT . '/src/QueueWorkerLoop.php';

putenv('QUEUE_DRIVER');
putenv('SMTP_HOST');
t_temp_db();
$dsn = (string) getenv('DB_DSN');
$other = new PDO($dsn, getenv('DB_USER') ?: null, getenv('DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
/** Drops the worker's (this process's) DB session, as a PG restart does. */
$kill = static function () use ($other): void {
    t_pg_terminate_backend($other, (int) db()->query('SELECT pg_backend_pid()')->fetchColumn());
    usleep(100000);
};
/** What runit does after the worker exits: a new process, so a new DB connection and new queue objects. */
$restart = static function (): MailQueue {
    (static fn () => Db::$conn = null)->bindTo(null, Db::class)();
    return MailQueues::make();
};
$rows = static function () use ($other): array {
    return $other->query('SELECT id, status, attempts, last_error FROM mail_queue ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
};
$dir = sys_get_temp_dir() . '/qwdb' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
register_shutdown_function(static fn () => @exec('rm -rf ' . escapeshellarg($dir)));
$sent = [];
$killOn = 'b@casalivre.test';
MailWorker::$deliver = static function (string $type, string $to) use (&$sent, &$killOn, $kill): bool {
    if ($to === $killOn) {
        $killOn = null;
        $kill();
        db()->query('SELECT 1'); // the delivery's own DB work (user/token lookups) hits the dead connection
    }
    $sent[] = $to;
    return true;
};

echo "connection dropped in the middle of a batch\n";
$mail = MailQueues::make();
foreach (['a', 'b', 'c'] as $n) {
    $mail->push('activation', "$n@casalivre.test");
}
$err = null;
try {
    QueueWorkerLoop::tick($mail, null, $dir);
} catch (Throwable $e) {
    $err = $e;
}
t_ok($err !== null && Db::isConnectionError($err), 'tick propagates the lost connection (' . ($err === null ? 'nothing thrown' : $err::class) . ')');
t_eq(['a@casalivre.test'], $sent, 'job a delivered before the drop; nothing after it in that batch');
$mail = $restart();
$r = $rows();
t_eq(['sent', 'processing', 'pending'], array_column($r, 'status'), 'a sent; b still reserved; c pending');
t_eq([0, 0, 0], array_map('intval', array_column($r, 'attempts')), 'the drop spent no attempt (not a job failure)');
t_eq('', (string) $r[1]['last_error'], 'no error recorded on b');
$rep = QueueWorkerLoop::tick($mail, null, $dir)['mail'];
t_eq([1, 0], [$rep['enviados'], $rep['falhas']], 'after the restart the worker keeps processing (c)');
// b's reservation goes stale after STALE_SECONDS and returns to the queue: age it instead of waiting 10 min.
$other->exec("UPDATE mail_queue SET updated_at = '2000-01-01 00:00:00' WHERE status = 'processing'");
$rep = QueueWorkerLoop::tick($mail, null, $dir)['mail'];
t_eq([1, 0], [$rep['enviados'], $rep['falhas']], 'b retried once its reservation is stale');
sort($sent);
t_eq(['a@casalivre.test', 'b@casalivre.test', 'c@casalivre.test'], $sent, 'every job delivered exactly once (none lost, none duplicated)');
t_eq(['sent', 'sent', 'sent'], array_column($rows(), 'status'), 'all three sent');
t_eq([0, 1, 0], array_map('intval', array_column($rows(), 'attempts')), 'only the reclaimed reservation counted an attempt');

echo "connection dropped between ticks (PG restart while idle)\n";
$mail->push('activation', 'd@casalivre.test');
$kill();
$err = null;
try {
    QueueWorkerLoop::tick($mail, null, $dir);
} catch (Throwable $e) {
    $err = $e;
}
t_ok($err !== null && Db::isConnectionError($err), 'tick: the DB ping fails first');
$d = $rows()[3];
t_eq(['pending', 0], [$d['status'], (int) $d['attempts']], 'd untouched (not reserved, no attempt)');
$mail = $restart();
$rep = QueueWorkerLoop::tick($mail, null, $dir)['mail'];
t_eq(1, $rep['enviados'], 'd delivered after the restart');
t_eq(4, count($sent), 'four deliveries in total');

echo "Db::isConnectionError\n";
$mk = static function (string $state, string $msg): PDOException {
    $e = new PDOException($msg);
    $e->errorInfo = [$state, 7, $msg];
    return $e;
};
t_ok(Db::isConnectionError($mk('08006', 'connection to server failed')), '08006 is a connection error');
t_ok(Db::isConnectionError($mk('57P01', 'terminating connection due to administrator command')), '57P01 is a connection error');
t_ok(Db::isConnectionError(new RuntimeException('wrap', 0, $mk('HY000', 'SQLSTATE[HY000]: General error: 7 no connection to the server'))), 'HY000 "no connection to the server", wrapped');
t_ok(Db::isConnectionError($mk('HY000', 'SQLSTATE[HY000]: General error: 7 FATAL:  terminating connection due to administrator command')), 'HY000 administrator terminate is a connection error');
t_ok(!Db::isConnectionError($mk('23505', 'duplicate key value')), 'unique violation is not');
t_ok(!Db::isConnectionError($mk('HY000', 'General error: 7 some other failure')), 'other HY000 is not');
t_ok(!Db::isConnectionError(new RuntimeException('SMTP down')), 'non-PDO error is not (still a job failure)');

echo "scripts/queue-worker.php: DB session dropped -> ALERT line, 5-30 s sleep, exit 1\n";
$stderr = (string) tempnam(sys_get_temp_dir(), 'qwerr');
$proc = proc_open([PHP_BINARY, CL_ROOT . '/scripts/queue-worker.php'], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $stderr, 'w']], $pipes, CL_ROOT);
$mine = (int) db()->query('SELECT pg_backend_pid()')->fetchColumn();
$ownPid = (int) $other->query('SELECT pg_backend_pid()')->fetchColumn();
$find = $other->prepare('SELECT pid FROM pg_stat_activity WHERE datname = current_database() AND pid NOT IN (?, ?)');
$worker = [];
for ($i = 0; $i < 100 && $worker === []; $i++) {
    usleep(50000);
    $find->execute([$mine, $ownPid]);
    $worker = $find->fetchAll(PDO::FETCH_COLUMN);
}
t_eq(1, count($worker), 'worker connected');
foreach ($worker as $p) {
    t_pg_terminate_backend($other, (int) $p);
}
// Arquitetura #167: no exit right away (runit would restart it every second during a PG outage). It sleeps 5-30 s,
// so it is still running 2 s later with the ALERT line already written; SIGTERM (sv stop) ends the sleep, exit 1.
$alert = '';
for ($i = 0; $i < 100 && !str_contains($alert, 'ALERT'); $i++) {
    usleep(50000);
    $alert = (string) file_get_contents($stderr);
}
t_ok(preg_match('/^queue-worker: ALERT DB connection lost \(PDOException\), exiting in (\d+) s so runit restarts the worker$/m', $alert, $m) === 1, 'ALERT "DB connection lost" line, without DSN or data');
t_ok(isset($m[1]) && (int) $m[1] >= 5 && (int) $m[1] <= 30, 'delay announced in 5-30 s (' . ($m[1] ?? '?') . ')');
sleep(2);
t_eq(true, proc_get_status($proc)['running'], 'still sleeping 2 s after the loss (no restart storm)');
$t0 = microtime(true);
proc_terminate($proc, 15);
$status = proc_get_status($proc);
for ($i = 0; $i < 100 && $status['running']; $i++) {
    usleep(50000);
    $status = proc_get_status($proc);
}
$ms = (int) ((microtime(true) - $t0) * 1000);
if ($status['running']) {
    proc_terminate($proc, 9);
}
proc_close($proc);
t_eq([false, 1], [$status['running'], $status['exitcode']], 'SIGTERM during the sleep: worker exits with code 1 (runit restarts it)');
t_ok($ms < 2000, "SIGTERM ends the sleep at once ($ms ms)");
@unlink($stderr);

echo "scripts/queue-worker.php: PG unreachable at start -> same ALERT + sleep, not a crash loop\n";
$stderr = (string) tempnam(sys_get_temp_dir(), 'qwerr');
$env = getenv();
$env['DB_DSN'] = (string) preg_replace('/dbname=[^;]+/', 'dbname=casalivre_no_such_db_' . getmypid(), $dsn);
$proc = proc_open([PHP_BINARY, CL_ROOT . '/scripts/queue-worker.php'], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $stderr, 'w']], $pipes, CL_ROOT, $env);
$alert = '';
for ($i = 0; $i < 100 && !str_contains($alert, 'ALERT'); $i++) {
    usleep(50000);
    $alert = (string) file_get_contents($stderr);
}
t_ok(str_contains($alert, 'queue-worker: ALERT DB connection lost (PDOException)') && !str_contains($alert, 'Stack trace'), 'ALERT line, no stack trace');
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
t_eq([false, 1], [$status['running'], $status['exitcode']], 'SIGTERM: exit 1');
if ($status['exitcode'] !== 1) {
    echo '  worker stderr: ' . str_replace("\n", "\n    ", trim((string) file_get_contents($stderr))) . "\n";
}
@unlink($stderr);

echo "QueueWorkerLoop::dbLost: random 5-30 s\n";
$slept = [];
QueueWorkerLoop::$sleep = static function (int $s) use (&$slept): void {
    $slept[] = $s;
};
$err = (string) tempnam(sys_get_temp_dir(), 'qwlog');
$out = fopen('php://memory', 'w+');
QueueWorkerLoop::$stderr = $out;
$prevLog = (string) ini_get('error_log');
ini_set('error_log', $err);
$got = [];
for ($i = 0; $i < 200; $i++) {
    $got[] = QueueWorkerLoop::dbLost($mk('08006', 'connection to server at "secret-host" failed'));
}
ini_set('error_log', $prevLog);
QueueWorkerLoop::$sleep = null;
QueueWorkerLoop::$stderr = null;
rewind($out);
t_eq(200, substr_count((string) stream_get_contents($out), "queue-worker: ALERT DB connection lost (PDOException), exiting in "), 'STDERR (runit log) gets the ALERT line');
t_eq($got, $slept, 'sleeps the delay it returns');
t_ok(min($got) >= 5 && max($got) <= 30, 'every delay in 5-30 s (' . min($got) . '-' . max($got) . ')');
t_ok(count(array_unique($got)) > 5, 'delays are random (' . count(array_unique($got)) . ' distinct in 200)');
$lines = array_values(array_filter(explode("\n", (string) file_get_contents($err)), static fn (string $l): bool => str_contains($l, 'CasaLivre ALERT: queue worker: DB connection lost (PDOException)')));
t_eq(200, count($lines), 'error log gets the "CasaLivre ALERT: queue worker: DB connection lost" line');
t_ok(!str_contains((string) file_get_contents($err), 'secret-host'), 'no exception message (DSN/host) in the log');
@unlink($err);
t_done();
