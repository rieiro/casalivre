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

$root = dirname(__DIR__);
require_once $root . '/src/Db.php';
require_once $root . '/src/AppEnv.php';
require_once $root . '/src/helpers.php';
require_once $root . '/src/QueueWorkerLoop.php';
Db::loadEnv();

$which = $argv[1] ?? '';
$once = in_array('--once', $argv, true);
if ($which !== 'mail' && $which !== 'image') {
    fwrite(STDERR, "usage: php scripts/queue-worker.php mail|image [--once]\n");
    exit(2);
}
$stop = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$stop): void {
        $stop = true;
    });
}
if ($which === 'mail' && !AppEnv::isDev()) {
    try {
        Mailer::linkFromAppUrl('http://localhost/');
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'mail-worker: ' . $e->getMessage() . " Defina APP_URL (http:// ou https:// com host) no .env; nada foi tirado da fila.\n");
        exit(2);
    }
}
if ($which === 'image' && !QueueWorkerLoop::hasWebp()) {
    QueueWorkerLoop::halt('GD WebP missing');
    exit(1);
}
@mkdir($root . '/storage', 0700, true);
echo 'queue-worker: ' . $which . ' ' . Queues::driver() . "\n";
$queue = null;
do {
    $report = null;
    try {
        if ($which === 'mail') {
            $queue ??= MailQueues::make();
            $report = QueueWorkerLoop::mail($queue, $root . '/storage');
        } else {
            $queue ??= ImageQueues::make();
            $report = QueueWorkerLoop::image($queue);
        }
        QueueWorkerLoop::valkeyUp();
    } catch (Throwable $e) {
        if ($e instanceof RedisException) {
            $queue = null;
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
    if ($report !== null && ($report['enviados'] + $report['ignorados'] + $report['falhas']) > 0) {
        echo 'queue-worker: ' . $which . ' ' . json_encode($report) . "\n";
    }
    if (!$once && !$stop) {
        sleep(1);
    }
} while (!$once && !$stop);
