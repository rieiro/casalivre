#!/usr/bin/env php
<?php

declare(strict_types=1);

// Argument values must not show up in a stack trace (same setting as the runit script).
ini_set('zend.exception_ignore_args', '1');

// Postgres-only calendar sync. No Valkey.
//   php scripts/calendar-worker.php [--once] [--fixture http://127.0.0.1:PORT]
// --once claims at most one feed and exits 0 (a feed failure is not a process failure).
// --fixture is the test hook for a local php -S feed. runit does not pass it.
// A lost database: QueueWorkerLoop::halt (ALERT, pause 5-30s) and exit 1.

// Recycling: after a feed/batch and before sleep(), exit(0) when the memory/jobs/runtime thresholds
// are reached. Same --max-* flags/env as the queue worker; --once never recycles.

$root = dirname(__DIR__);
require_once $root . '/src/Db.php';
require_once $root . '/src/helpers.php';
require_once $root . '/src/HouseRepository.php';
require_once $root . '/src/CalendarSync.php';
require_once $root . '/src/ActivityLog.php';
require_once $root . '/src/QueueWorkerLoop.php';
require_once $root . '/src/CalendarWorker.php';
require_once $root . '/src/WorkerRecycling.php';
Db::loadEnv();

$once = in_array('--once', $argv, true);
$fixture = null;
foreach ($argv as $i => $arg) {
    if ($arg === '--fixture' && isset($argv[$i + 1])) {
        $fixture = $argv[$i + 1];
    }
}
if ($fixture !== null && !preg_match('#^http://(127\.0\.0\.1|localhost):\d+$#', $fixture)) {
    fwrite(STDERR, "usage: php scripts/calendar-worker.php [--once] [--fixture http://127.0.0.1:PORT] [--max-memory=MB] [--max-jobs=N] [--max-time=S]\n");
    exit(2);
}
try {
    $recycling = WorkerRecycling::fromArgv($argv);
} catch (InvalidArgumentException) {
    fwrite(STDERR, "usage: php scripts/calendar-worker.php [--once] [--fixture http://127.0.0.1:PORT] [--max-memory=MB] [--max-jobs=N] [--max-time=S]\n");
    exit(2);
}

exit(CalendarWorker::run($once, $fixture, $once ? null : $recycling));
