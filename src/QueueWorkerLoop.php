<?php

declare(strict_types=1);

require_once __DIR__ . '/MailWorker.php';
require_once __DIR__ . '/ImageWorker.php';
require_once __DIR__ . '/WorkerCaches.php';

/**
 * One iteration of the long-running scripts/queue-worker.php: one mail batch and, when $image is given, one image batch.
 * The worker never exits, so file-backed per-process caches are refreshed first when their file changed
 * (WorkerCaches::refreshIfChanged); GrupoResolvedor's entries expire by TTL.
 * The worker never reconnects to the DB in-process (Db keeps one shared connection, and the SQL queues hold it):
 * each tick pings it first (Db::ping) and a lost connection propagates, so scripts/queue-worker.php exits non-zero
 * and runit restarts it with a fresh connection (Arquitetura #142).
 */
final class QueueWorkerLoop
{
    /** @return array<string, ?array> report per queue ('mail', and 'image' when $image is not null) */
    public static function tick(MailQueue $mail, ?ImageQueue $image, string $storageDir): array
    {
        Db::ping();
        WorkerCaches::refreshIfChanged();
        $reports = ['mail' => MailWorker::run($mail, $storageDir . '/mail-worker.lock')];
        if ($image !== null) {
            $reports['image'] = ImageWorker::run($image, $storageDir . '/image-worker.lock');
        }
        return $reports;
    }
}
