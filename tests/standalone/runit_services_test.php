<?php

declare(strict_types=1);

// Production runit scripts: queue worker, log retention exit code, ALERT paging.
// No runit daemon: syntax, a fake chpst/svlogd, and the alert helper.
require __DIR__ . '/lib.php';

$root = CL_ROOT . '/deploy/runit';
$scripts = [
    "$root/casalivre-alert",
    "$root/casalivre-resolve",
    "$root/casalivre-finish",
    "$root/casalivre-svlogd",
    "$root/casalivre-queue-worker/run",
    "$root/casalivre-queue-worker/finish",
    "$root/casalivre-queue-worker/log/run",
    "$root/casalivre-log-retention/run",
    "$root/casalivre-log-retention/finish",
    "$root/casalivre-log-retention/log/run",
];

echo "runit scripts\n";
foreach ($scripts as $path) {
    $rel = substr($path, strlen(CL_ROOT) + 1);
    t_ok(is_file($path) && is_executable($path), "$rel exists and is executable");
    exec('sh -n ' . escapeshellarg($path) . ' 2>&1', $syntaxOut, $syntaxCode);
    t_eq(0, $syntaxCode, "$rel: sh -n");
}
$workerRun = (string) file_get_contents("$root/casalivre-queue-worker/run");
t_ok(str_contains($workerRun, 'exec 2>&1') && str_contains($workerRun, 'exec chpst -u') && str_contains($workerRun, 'scripts/queue-worker.php'), 'queue worker: chpst, stderr merged, queue-worker.php');
t_ok(str_contains($workerRun, '-d zend.exception_ignore_args=1'), 'queue worker php does not keep exception arguments');
t_ok(!str_contains($workerRun, '|| echo'), 'queue worker run does not swallow the exit code');
$retentionRun = (string) file_get_contents("$root/casalivre-log-retention/run");
t_ok(!preg_match('/\|\|\s*echo/', $retentionRun) && str_contains($retentionRun, 'exit "$status"') && str_contains($retentionRun, 'sleep 86400'), 'log-retention: failure exits, success still sleeps 24h');
t_ok(str_contains((string) file_get_contents("$root/casalivre-queue-worker/finish"), 'casalivre-queue-worker 0'), 'queue worker finish does not delay the restart');
t_ok(str_contains((string) file_get_contents("$root/casalivre-log-retention/finish"), 'casalivre-log-retention 86400'), 'log-retention finish waits a day after a failure');
$wrappers = [
    "$root/casalivre-queue-worker/finish",
    "$root/casalivre-queue-worker/log/run",
    "$root/casalivre-log-retention/finish",
    "$root/casalivre-log-retention/log/run",
];
foreach ($wrappers as $path) {
    $body = (string) file_get_contents($path);
    $rel = substr($path, strlen(CL_ROOT) + 1);
    t_eq(1, preg_match_all('/^exec /m', $body), "$rel is a single exec");
    t_ok(str_contains($body, 'casalivre-resolve') && !str_contains($body, 'elif'), "$rel does not copy the helper lookup");
}
$lookup = (string) file_get_contents("$root/casalivre-resolve");
t_ok(str_contains($lookup, 'ALERT_BIN') && str_contains($lookup, 'casalivre_bin'), 'alert lookup lives in casalivre-resolve');
$callers = (string) file_get_contents("$root/casalivre-finish") . (string) file_get_contents("$root/casalivre-svlogd");
t_ok(!str_contains($callers, 'ALERT_BIN') && str_contains($callers, 'casalivre_bin '), 'finish and svlogd call the shared lookup');
$sv = (string) file_get_contents("$root/casalivre-svlogd");
t_ok(str_contains($sv, '*ALERT*') && str_contains($sv, 'n14') && str_contains($sv, 't86400') && str_contains($sv, '0700'), 'svlogd helper: ALERT match, 14 days, private dir');

$tmp = sys_get_temp_dir() . '/cl-runit-' . bin2hex(random_bytes(4));
mkdir($tmp . '/bin', 0700, true);
register_shutdown_function(static function () use ($tmp): void {
    exec('chmod -R u+w ' . escapeshellarg($tmp) . ' 2>/dev/null; rm -rf ' . escapeshellarg($tmp));
});

$put = static function (string $name, string $body) use ($tmp): string {
    $path = "$tmp/bin/$name";
    file_put_contents($path, $body);
    chmod($path, 0755);
    return $path;
};
$put('chpst', <<<'SH'
#!/bin/sh
while [ $# -gt 0 ]; do
  case $1 in
    -u) shift 2 ;;
    --) shift; break ;;
    -*) shift ;;
    *) break ;;
  esac
done
exec "$@"
SH);
$put('svlogd', <<<'SH'
#!/bin/sh
dir=
while [ $# -gt 0 ]; do
  case $1 in
    -*) shift ;;
    *) dir=$1; shift ;;
  esac
done
mkdir -p "$dir"
while IFS= read -r line || [ -n "$line" ]; do
  printf '%s\n' "$line" >> "$dir/current"
done
exit 0
SH);
$put('php-fail', <<<'SH'
#!/bin/sh
echo "log-retention: ALERT saude x: critical" >&2
exit 2
SH);
$put('php-exit3', <<<'SH'
#!/bin/sh
exit 3
SH);
$put('logger', <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "$LOGGER_OUT"
SH);
$put('page', <<<'SH'
#!/bin/sh
printf '%s\n' "$1 $2" >> "$ALERT_CMD_OUT"
SH);

$baseEnv = [
    'PATH' => "$tmp/bin:/usr/bin:/bin",
    'HOME' => $tmp,
    'LANG' => 'C',
    'ALERT_LOG' => "$tmp/alerts.log",
    'ALERT_STAMP_DIR' => "$tmp/stamps",
    'ALERT_THROTTLE' => '900',
    'LOGGER_BIN' => "$tmp/bin/logger",
    'LOGGER_OUT' => "$tmp/logger.out",
    'ALERT_CMD' => "$tmp/bin/page",
    'ALERT_CMD_OUT' => "$tmp/page.out",
    'FIFO_DIR' => "$tmp/fifo",
    'RESTART_DELAY' => '0',
    'CASALIVRE_RESOLVE' => "$root/casalivre-resolve",
];

$run = static function (string $cmd, string $cwd, array $env, ?string $stdin = null) use ($baseEnv): array {
    $desc = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $in = null;
    if ($stdin !== null) {
        $in = tempnam(sys_get_temp_dir(), 'cl-in');
        file_put_contents($in, $stdin);
        $desc[0] = ['file', $in, 'r'];
    }
    $proc = proc_open(['/bin/sh', '-c', $cmd], $desc, $pipes, $cwd, array_merge($baseEnv, $env));
    if (!is_resource($proc)) {
        if ($in !== null) {
            @unlink($in);
        }
        return [127, '', 'proc_open failed'];
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($in !== null) {
        @unlink($in);
    }
    return [$code, $out, $err];
};
$argv = static function (array $args, array $env) use ($baseEnv): array {
    $proc = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge($baseEnv, $env));
    if (!is_resource($proc)) {
        return [127, '', 'proc_open failed'];
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), $out, $err];
};
$resetPages = static function () use ($tmp): void {
    @unlink("$tmp/alerts.log");
    @unlink("$tmp/logger.out");
    @unlink("$tmp/page.out");
    exec('rm -rf ' . escapeshellarg("$tmp/stamps"));
};

echo "alert helper\n";
$resetPages();
$t0 = microtime(true);
[$code] = $argv(["$root/casalivre-alert", 'casalivre-queue-worker', "queue-worker: ALERT DB connection lost\nsecond"], []);
t_eq(0, $code, 'casalivre-alert exits 0');
t_ok(microtime(true) - $t0 < 3, 'casalivre-alert returns immediately');
$log = (string) file_get_contents("$tmp/alerts.log");
t_ok(substr_count($log, "\n") === 1, 'newline in the message stays one log line');
t_ok(str_contains($log, ' page ') && str_contains($log, 'ALERT DB connection lost second'), 'first event is paged and flattened');
t_eq('600', sprintf('%o', fileperms("$tmp/alerts.log") & 0777), 'alerts.log is 0600');
t_ok(is_file("$tmp/page.out") && is_file("$tmp/logger.out"), 'pager and logger ran');
[$code] = $argv(["$root/casalivre-alert", 'casalivre-queue-worker', 'queue-worker: ALERT again'], []);
t_eq(0, $code, 'second alert exits 0');
$log = (string) file_get_contents("$tmp/alerts.log");
t_ok(str_contains($log, ' skip ') && str_contains($log, 'ALERT again'), 'repeat inside the window is recorded and not paged');
t_eq(1, substr_count((string) file_get_contents("$tmp/page.out"), "\n"), 'pager runs once per window');
[$code] = $argv(["$root/casalivre-alert", 'casalivre-queue-worker', 'plain line'], ['ALERT_THROTTLE' => '0']);
t_eq(0, $code, 'throttle 0 exits 0');
t_ok(str_contains((string) file_get_contents("$tmp/page.out"), 'plain line'), 'throttle 0 pages again');

echo "finish\n";
$finish = static function (string $which, string $args) use ($run, $tmp, $resetPages): array {
    $resetPages();
    $t0 = microtime(true);
    $result = $run('timeout 5 ' . $which . ' ' . $args, $tmp, []);
    $result[] = microtime(true) - $t0;
    return $result;
};
[$code, $out, $err, $elapsed] = $finish("$root/casalivre-log-retention/finish", '1 0');
t_eq(0, $code, 'log-retention finish exits 0 on failure so runit restarts (' . trim($out . $err) . ')');
t_ok($elapsed < 3, 'failed retention does not sleep 86400 when RESTART_DELAY=0');
t_ok(str_contains((string) file_get_contents("$tmp/alerts.log"), 'ALERT process exited 1'), 'exit 1 is paged');
t_ok(str_contains((string) file_get_contents("$tmp/page.out"), 'casalivre-log-retention'), 'page names the retention service');

[$code] = $finish("$root/casalivre-log-retention/finish", '0 0');
t_eq(0, $code, 'clean exit: finish exits 0');
t_ok(!is_file("$tmp/alerts.log"), 'clean exit is not paged');

[$code] = $finish("$root/casalivre-queue-worker/finish", '-1 15');
t_eq(0, $code, 'SIGTERM finish exits 0');
t_ok(!is_file("$tmp/alerts.log"), 'SIGTERM (sv down/restart) is not paged');

[$code] = $finish("$root/casalivre-queue-worker/finish", '-1 143');
t_eq(0, $code, 'SIGTERM with core bit exits 0');
t_ok(!is_file("$tmp/alerts.log"), 'signal 143 is still SIGTERM and is not paged');

[$code] = $finish("$root/casalivre-queue-worker/finish", '-1 9');
t_eq(0, $code, 'SIGKILL finish exits 0');
t_ok(str_contains((string) file_get_contents("$tmp/alerts.log"), 'ALERT process died from signal 9'), 'SIGKILL is paged');

echo "run exit codes\n";
$app = "$tmp/app";
mkdir($app, 0700);
$t0 = microtime(true);
[$code, $out, $err] = $run('timeout 5 ./run', "$root/casalivre-log-retention", [
    'APP_DIR' => $app,
    'APP_USER' => trim((string) shell_exec('id -un')),
    'PHP_BIN' => "$tmp/bin/php-fail",
]);
t_eq(2, $code, 'log-retention.php exit 2 is the service exit (' . trim($out . $err) . ')');
t_ok(microtime(true) - $t0 < 3, 'failed log-retention returns without the 24h sleep');
t_ok(str_contains($out . $err, 'ALERT saude x: critical'), 'stderr is merged onto the log stream');
t_ok(!str_contains($out . $err, 'falhou'), 'the old swallow message is gone');

[$code] = $run('timeout 5 ./run', "$root/casalivre-queue-worker", [
    'APP_DIR' => $app,
    'APP_USER' => trim((string) shell_exec('id -un')),
    'PHP_BIN' => "$tmp/bin/php-exit3",
]);
t_eq(3, $code, 'queue worker exit code is the php exit code');

echo "svlogd log service\n";
if (!is_executable('/usr/bin/timeout')) {
    echo "  SKIPPED smoke (no timeout)\n";
    t_done();
}
$user = trim((string) shell_exec('id -un'));
$group = trim((string) shell_exec('id -gn'));
$logDir = "$tmp/var/queue-worker";
$resetPages();
$t0 = microtime(true);
[$code, $out, $err] = $run(
    'timeout 5 ./run',
    "$root/casalivre-queue-worker/log",
    [
        'LOG_DIR' => $logDir,
        'LOG_USER' => $user,
        'LOG_GROUP' => $group,
        'APP_USER' => $user,
    ],
    "hello\nqueue-worker: ALERT DB connection lost\n"
);
$elapsed = microtime(true) - $t0;
t_eq(0, $code, 'log service exits after stdin EOF (' . trim($out . $err) . ')');
t_ok($elapsed < 3, 'log service does not hang after stdin EOF');
t_eq('700', sprintf('%o', fileperms($logDir) & 0777), 'queue-worker log dir is 0700');
t_eq('711', sprintf('%o', fileperms("$tmp/var") & 0777), 'parent log dir is 0711');
$cfg = (string) file_get_contents("$logDir/config");
t_ok(str_contains($cfg, "n14\n") && str_contains($cfg, "t86400\n") && str_contains($cfg, 's100000000'), 'seeded svlogd rotation is 14 days');
$current = (string) file_get_contents("$logDir/current");
t_ok(str_contains($current, 'hello') && str_contains($current, 'ALERT DB connection lost'), 'both lines are stored');
$paged = (string) file_get_contents("$tmp/alerts.log");
t_ok(str_contains($paged, 'ALERT DB connection lost') && !str_contains($paged, 'hello'), 'only the ALERT line is paged');
t_eq(1, substr_count((string) file_get_contents("$tmp/page.out"), "\n"), 'one page for one ALERT line');

t_done();
