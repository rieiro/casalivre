#!/usr/bin/env php
<?php

declare(strict_types=1);

// Argument values must not show up in a stack trace (same setting as public/index.php).
// The runit `run` scripts also pass -d zend.exception_ignore_args=1.
ini_set('zend.exception_ignore_args', '1');

// One long-running queue: mail (casalivre-mail-worker) or image (casalivre-image-worker-N).
//   php scripts/queue-worker.php mail|image [--once]
// --once drains a single batch and exits (helper scripts). The service loops and sleeps.
// Exits on SIGTERM between batches (runit `sv stop`). Each batch refreshes per-process caches.
// Queues are opened inside the loop. Valkey down at start is a RedisException: one ALERT, reconnect every second,
// no exit (a startup try would exit 255 and runit would restart it every second).
// Exits 1 when the DB connection is lost (the batch pings it first; a job interrupted by it is not counted as failed):
// QueueWorkerLoop::dbLost logs an ALERT and sleeps 5-30 s, so a PG outage does not restart the worker every second.
// Missing GD WebP on the image worker is the same ALERT and the same pause, then exit 1. The image queue is not skipped.
// Outside dev, the mail worker exits 2 when APP_URL is missing or invalid and takes nothing off the queue.
// Any other exception (including a Valkey AUTH or secret-file failure): class name only, same backoff, exit 1.
// A job whose ack was lost stays reserved and is sent again after STALE_SECONDS (at-least-once). That reclaim
// counts as an attempt, so retries stop at Queues::MAX_ATTEMPTS.

// Recycling: after each batch and before sleep(), exit(0) when memory/jobs/runtime thresholds are
// reached; runit restarts with a fresh heap and updated code. Override with --max-memory=MB,
// --max-jobs=N, --max-time=S or WORKER_MAX_MEMORY_MB/WORKER_MAX_JOBS/WORKER_MAX_TIME_SECONDS.
// --once never recycles.

$root = dirname(__DIR__);
require_once $root . '/src/Db.php';
require_once $root . '/src/AppEnv.php';
require_once $root . '/src/helpers.php';
require_once $root . '/src/QueueWorkerLoop.php';
require_once $root . '/src/WorkerRecycling.php';
Db::loadEnv();

$which = 'all';
$once = false;
try {
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--once') {
            $once = true;
        } elseif (in_array($arg, ['mail', 'image', 'all'], true)) {
            $which = $arg;
        } elseif (!str_starts_with($arg, '--max-')) {
            throw new InvalidArgumentException('unknown argument');
        }
    }
    $recycling = WorkerRecycling::fromArgv($argv);
} catch (InvalidArgumentException) {
    fwrite(STDERR, "usage: php scripts/queue-worker.php [mail|image|all] [--once] [--max-memory=MB] [--max-jobs=N] [--max-time=S]\n");
    exit(2);
}
$stop = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$stop): void {
        $stop = true;
    });
}
if (($which === 'mail' || $which === 'all') && !AppEnv::isDev()) {
    try {
        Mailer::linkFromAppUrl('http://localhost/');
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'mail-worker: ' . $e->getMessage() . " Defina APP_URL (http:// ou https:// com host) no .env; nada foi tirado da fila.\n");
        exit(2);
    }
}
if (($which === 'image' || $which === 'all') && !QueueWorkerLoop::hasWebp()) {
    QueueWorkerLoop::halt('GD WebP missing');
    exit(1);
}
@mkdir($root . '/storage', 0700, true);
echo 'queue-worker: ' . $which . ' ' . Queues::driver() . "\n";
$mailQueue = null;
$imageQueue = null;
do {
    $jobs = 0;
    try {
        if ($which === 'mail' || $which === 'all') {
            $mailQueue ??= MailQueues::make();
            $rMail = QueueWorkerLoop::mail($mailQueue, $root . '/storage');
            if ($rMail !== null) {
                $jobs += (int) $rMail['enviados'] + (int) $rMail['ignorados'] + (int) $rMail['falhas'];
                if ($jobs > 0 && ($rMail['enviados'] + $rMail['ignorados'] + $rMail['falhas']) > 0) {
                    echo 'queue-worker: mail ' . json_encode($rMail) . "\n";
                }
            }
        }
        if ($which === 'image' || $which === 'all') {
            $imageQueue ??= ImageQueues::make();
            $rImage = QueueWorkerLoop::image($imageQueue);
            if ($rImage !== null) {
                $jobs += (int) $rImage['enviados'] + (int) $rImage['ignorados'] + (int) $rImage['falhas'];
                if ($rImage['enviados'] + $rImage['ignorados'] + $rImage['falhas'] > 0) {
                    echo 'queue-worker: image ' . json_encode($rImage) . "\n";
                }
            }
        }
        QueueWorkerLoop::valkeyUp();
    } catch (Throwable $e) {
        if ($e instanceof RedisException) {
            $mailQueue = null;
            $imageQueue = null;
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
    if (!$once && !$stop) {
        if ($jobs > 0) {
            $recycling->recordJobs($jobs);
        }
        $recycling->terminateIfDue('queue-worker');
        sleep(1);
    }
} while (!$once && !$stop);
