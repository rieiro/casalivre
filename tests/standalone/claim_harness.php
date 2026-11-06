<?php
// Two-process (or N-process) claim-test harness: starts several worker processes against the same queue (PostgreSQL
// table or Valkey) at the same instant and checks that every job was claimed and processed exactly once.
// Not a test itself (no _test suffix): require it from a *_test.php after lib.php. Self-test: claim_harness_test.php.
//
// Each process gets, on top of the parent's environment (DB_DSN of t_temp_db, QUEUE_DRIVER, VALKEY_*):
//   CL_CLAIM_WORKER  its index (0, 1, ...)
//   CL_CLAIM_LOG     a file where it appends one line "<job key>" per job it processed (O_APPEND + flock)
//   CL_CLAIM_GO      a file that appears once every process is started (start barrier; optional for real workers)
// claim_worker.php is a generic worker over MailQueues/ImageQueues that follows this protocol. A real worker (e.g. the
// upcoming calendar worker) can be raced as is: pass its command line and, if it cannot write CL_CLAIM_LOG, build the
// per-worker lists from the DB (who processed what) and call claim_check()/claim_assert_once() on them.
//
//   $race = claim_race([claim_worker_cmd('mail'), claim_worker_cmd('mail')], ['env' => ['QUEUE_DRIVER' => 'db']]);
//   claim_assert_once($race['logs'], $expectedKeys, 'two sql workers');
//   t_eq([0, 0], $race['exit'], 'both workers exit 0');
declare(strict_types=1);

/** Command line for the generic worker: claim_worker.php <queue> [batch] [mode]. Modes: claim (real reserve/ack), broken (no claim guard). */
function claim_worker_cmd(string $queue, int $batch = 1, string $mode = 'claim'): array
{
    return [...t_php_cmd(), __DIR__ . '/claim_worker.php', CL_ROOT, $queue, (string) $batch, $mode];
}

/**
 * Starts every command, opens the start barrier once all are running, waits up to 'timeout' seconds (then SIGTERM,
 * then SIGKILL) and collects what each one logged.
 *
 * @param list<list<string>> $cmds argv per worker
 * @param array{env?: array<string, string>, timeout?: float, work_us?: int} $opts env added to every worker; work_us is
 *        exported as CL_CLAIM_WORK_US (time each job "takes", widens the race window)
 * @return array{logs: list<list<string>>, exit: list<int>, stderr: list<string>, timed_out: list<bool>, seconds: float}
 */
function claim_race(array $cmds, array $opts = []): array
{
    $dir = sys_get_temp_dir() . '/cl-claim-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700);
    $go = $dir . '/go';
    $base = getenv();
    foreach ($opts['env'] ?? [] as $k => $v) {
        $base[$k] = $v;
    }
    if (isset($opts['work_us'])) {
        $base['CL_CLAIM_WORK_US'] = (string) $opts['work_us'];
    }
    $procs = [];
    foreach (array_values($cmds) as $i => $cmd) {
        $env = $base;
        $env['CL_CLAIM_WORKER'] = (string) $i;
        $env['CL_CLAIM_LOG'] = "$dir/log$i";
        $env['CL_CLAIM_GO'] = $go;
        touch("$dir/log$i");
        $procs[$i] = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$dir/out$i", 'w'], 2 => ['file', "$dir/err$i", 'w']], $pipes, CL_ROOT, $env);
        if (!is_resource($procs[$i])) {
            throw new RuntimeException('claim_race: could not start worker ' . $i);
        }
    }
    $t = microtime(true);
    touch($go);
    $deadline = $t + (float) ($opts['timeout'] ?? 30.0);
    $exit = [];
    $timedOut = [];
    foreach ($procs as $i => $proc) {
        $status = proc_get_status($proc);
        while ($status['running'] && microtime(true) < $deadline) {
            usleep(10000);
            $status = proc_get_status($proc);
        }
        $timedOut[$i] = $status['running'];
        if ($status['running']) {
            proc_terminate($proc, 15);
            for ($n = 0; $n < 100 && $status['running']; $n++) {
                usleep(20000);
                $status = proc_get_status($proc);
            }
            if ($status['running']) {
                proc_terminate($proc, 9);
            }
        }
        proc_close($proc);
        $exit[$i] = (int) $status['exitcode'];
    }
    $seconds = microtime(true) - $t;
    $logs = [];
    $stderr = [];
    foreach (array_keys($procs) as $i) {
        $logs[$i] = array_values(array_filter(explode("\n", (string) file_get_contents("$dir/log$i")), static fn (string $l): bool => $l !== ''));
        $stderr[$i] = (string) file_get_contents("$dir/err$i");
    }
    exec('rm -rf ' . escapeshellarg($dir));
    return ['logs' => $logs, 'exit' => $exit, 'stderr' => $stderr, 'timed_out' => $timedOut, 'seconds' => $seconds];
}

/**
 * @param list<list<string>> $perWorker job keys each worker processed
 * @param list<string> $expected every job key that was queued
 * @return array{missing: list<string>, extra: list<string>, dupes: array<string, list<int>>} dupes: key => workers (a worker twice if it repeated it)
 */
function claim_check(array $perWorker, array $expected): array
{
    $seen = [];
    foreach ($perWorker as $w => $keys) {
        foreach ($keys as $k) {
            $seen[(string) $k][] = (int) $w;
        }
    }
    $dupes = array_filter($seen, static fn (array $ws): bool => count($ws) > 1);
    $expected = array_map('strval', $expected);
    return [
        'missing' => array_values(array_diff($expected, array_keys($seen))),
        'extra' => array_values(array_diff(array_map('strval', array_keys($seen)), $expected)),
        'dupes' => $dupes,
    ];
}

/** Asserts (t_eq) that every expected job was processed exactly once across the workers. */
function claim_assert_once(array $perWorker, array $expected, string $label): array
{
    $c = claim_check($perWorker, $expected);
    $cut = static fn (array $a): array => array_slice($a, 0, 10, true);
    t_eq([], $cut($c['dupes']), "$label: no job processed twice");
    t_eq([], $cut($c['missing']), "$label: no job left unprocessed");
    t_eq([], $cut($c['extra']), "$label: no unknown job processed");
    return $c;
}
