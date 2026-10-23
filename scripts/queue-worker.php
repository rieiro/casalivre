#!/usr/bin/env php
<?php

declare(strict_types=1);

// Long-running queue worker (P0-4: runit service queue-worker in ops/dev-env). Loops over the mail and
// image queues of QUEUE_DRIVER, one batch each, then sleeps. Same batch code as the cron workers
// (MailWorker/ImageWorker); for QUEUE_DRIVER=db the cron scripts also work.
//   php scripts/queue-worker.php [--once]
// Exits on SIGTERM between batches (runit `sv stop`). Each iteration is QueueWorkerLoop::tick (clears per-process caches).
// Exits 1 when the DB connection is lost (tick pings it first; a job interrupted by it is not counted as failed):
// runit restarts the worker with a fresh connection. Before exiting it logs an ALERT line and sleeps 5-30 s
// (QueueWorkerLoop::dbLost), so a PG outage does not restart the worker every second.

$root = dirname(__DIR__);
require_once $root . '/src/Db.php';
require_once $root . '/src/AppEnv.php';
require_once $root . '/src/helpers.php';
require_once $root . '/src/QueueWorkerLoop.php';
Db::loadEnv();

$once = in_array('--once', $argv, true);
$stop = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$stop): void {
        $stop = true;
    });
}
@mkdir($root . '/storage', 0700, true);
$dbLost = static function (Throwable $e): never {
    if (!Db::isConnectionError($e)) {
        throw $e;
    }
    QueueWorkerLoop::dbLost($e);
    exit(1);
};
try {
    $mail = MailQueues::make(); // the SQL queues connect here: PG down at start is a lost DB too
    $image = ImageQueues::make();
} catch (Throwable $e) {
    $dbLost($e);
}
$webp = function_exists('imagewebp') && (gd_info()['WebP Support'] ?? false);
echo 'queue-worker: driver ' . Queues::driver() . ($webp ? '' : ' (no WebP: image queue skipped)') . "\n";
do {
    try {
        $reports = QueueWorkerLoop::tick($mail, $webp ? $image : null, $root . '/storage');
    } catch (Throwable $e) {
        $dbLost($e);
    }
    foreach ($reports as $q => $report) {
        if ($report !== null && ($report['enviados'] + $report['ignorados'] + $report['falhas']) > 0) {
            echo "queue-worker: $q " . json_encode($report) . "\n";
        }
    }
    if (!$once && !$stop) {
        sleep(1);
    }
} while (!$once && !$stop);
