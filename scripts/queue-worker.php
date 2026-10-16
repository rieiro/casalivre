#!/usr/bin/env php
<?php

declare(strict_types=1);

// Long-running queue worker (P0-4: runit service queue-worker in ops/dev-env). Loops over the mail and
// image queues of QUEUE_DRIVER, one batch each, then sleeps. Same batch code as the cron workers
// (MailWorker/ImageWorker); for sqlite keep using the cron scripts.
//   php scripts/queue-worker.php [--once]
// Exits on SIGTERM between batches (runit `sv stop`).

$root = dirname(__DIR__);
require_once $root . '/src/Db.php';
require_once $root . '/src/AppEnv.php';
require_once $root . '/src/helpers.php';
require_once $root . '/src/MailWorker.php';
require_once $root . '/src/ImageWorker.php';
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
$mail = MailQueues::make();
$image = ImageQueues::make();
$webp = function_exists('imagewebp') && (gd_info()['WebP Support'] ?? false);
echo 'queue-worker: driver ' . Queues::driver() . ($webp ? '' : ' (no WebP: image queue skipped)') . "\n";
do {
    $reports = ['mail' => MailWorker::run($mail, $root . '/storage/mail-worker.lock')];
    if ($webp) {
        $reports['image'] = ImageWorker::run($image, $root . '/storage/image-worker.lock');
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
