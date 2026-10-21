#!/usr/bin/env php
<?php

declare(strict_types=1);

// Long-running queue worker (P0-4: runit service queue-worker in ops/dev-env). Loops over the mail and
// image queues of QUEUE_DRIVER, one batch each, then sleeps. Same batch code as the cron workers
// (MailWorker/ImageWorker); for sqlite keep using the cron scripts.
//   php scripts/queue-worker.php [--once]
// Exits on SIGTERM between batches (runit `sv stop`). Each iteration is QueueWorkerLoop::tick (clears per-process caches).
// Exits 1 when the DB connection is lost (tick pings it first; a job interrupted by it is not counted as failed):
// runit restarts the worker with a fresh connection.
// Valkey unreachable (QUEUE_DRIVER=valkey, RedisException): one line when the outage starts and one when it ends, no
// stack trace and no exit; the queues are reconnected on the next iteration (every second). A job whose ack was lost
// stays reserved and is sent again after STALE_SECONDS (at-least-once).

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
$valkeyDown = false;
$webp = function_exists('imagewebp') && (gd_info()['WebP Support'] ?? false);
echo 'queue-worker: driver ' . Queues::driver() . ($webp ? '' : ' (no WebP: image queue skipped)') . "\n";
do {
    $reports = [];
    try {
        $mail ??= MailQueues::make();
        $image ??= ImageQueues::make();
        $reports = QueueWorkerLoop::tick($mail, $webp ? $image : null, $root . '/storage');
        if ($valkeyDown) {
            $valkeyDown = false;
            fwrite(STDERR, "queue-worker: Valkey reachable again, resuming\n");
        }
    } catch (Throwable $e) {
        if ($e instanceof RedisException) {
            if (!$valkeyDown) {
                $valkeyDown = true;
                fwrite(STDERR, 'queue-worker: ALERT Valkey unavailable (' . $e::class . '), retrying every second' . "\n");
            }
            if ($once) {
                exit(1);
            }
            $mail = $image = null; // reconnect on the next iteration
            if (!$stop) {
                sleep(1);
            }
            continue;
        }
        if (!Db::isConnectionError($e)) {
            throw $e;
        }
        fwrite(STDERR, 'queue-worker: DB connection lost (' . $e::class . '), exiting so runit restarts the worker' . "\n");
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
