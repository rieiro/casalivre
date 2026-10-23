#!/usr/bin/env php
<?php

declare(strict_types=1);

// Argument values must not show up in a stack trace (same setting as public/index.php).
// The runit `run` scripts also pass -d zend.exception_ignore_args=1.
ini_set('zend.exception_ignore_args', '1');

// Long-running queue worker (P0-4: runit service queue-worker in ops/dev-env). Loops over the mail and
// image queues of QUEUE_DRIVER, one batch each, then sleeps. Same batch code as the cron workers
// (MailWorker/ImageWorker); for QUEUE_DRIVER=db the cron scripts also work.
//   php scripts/queue-worker.php [--once]
// Exits on SIGTERM between batches (runit `sv stop`). Each iteration is QueueWorkerLoop::tick (clears per-process caches).
// Queues are opened inside the loop. Valkey down at start is a RedisException: one ALERT, reconnect every second,
// no exit (a startup try would exit 255 and runit would restart it every second).
// Exits 1 when the DB connection is lost (tick pings it first; a job interrupted by it is not counted as failed):
// QueueWorkerLoop::dbLost logs an ALERT and sleeps 5-30 s, so a PG outage does not restart the worker every second.
// Any other exception (including a Valkey AUTH or secret-file failure): class name only, same backoff, exit 1.
// A job whose ack was lost stays reserved and is sent again after STALE_SECONDS (at-least-once). That reclaim
// counts as an attempt, so retries stop at Queues::MAX_ATTEMPTS.

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
$mail = null;
$image = null;
$webp = function_exists('imagewebp') && (gd_info()['WebP Support'] ?? false);
echo 'queue-worker: driver ' . Queues::driver() . ($webp ? '' : ' (no WebP: image queue skipped)') . "\n";
do {
    $reports = [];
    try {
        $mail ??= MailQueues::make();
        $image ??= ImageQueues::make();
        $reports = QueueWorkerLoop::tick($mail, $webp ? $image : null, $root . '/storage');
        QueueWorkerLoop::valkeyUp();
    } catch (Throwable $e) {
        if ($e instanceof RedisException) {
            $mail = $image = null; // reconnect on the next iteration
            if (QueueWorkerLoop::valkeyDown($e, $once, $stop)) {
                exit(1);
            }
            continue;
        }
        if (Db::isConnectionError($e)) {
            QueueWorkerLoop::dbLost($e);
        } else {
            QueueWorkerLoop::unexpected($e);
        }
        exit(1);
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
