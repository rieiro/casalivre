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
    /** Random delay (s) before the worker exits on a lost DB, so runit does not restart it every second (Arquitetura #167). */
    public const DB_LOST_SLEEP_MIN = 5;
    public const DB_LOST_SLEEP_MAX = 30;
    /** Test hooks: replace sleep() and STDERR. */
    public static ?Closure $sleep = null;
    /** @var resource|null */
    public static $stderr = null;

    /**
     * scripts/queue-worker.php on a lost DB connection, before exit(1): one alertable line (ALERT, as log-retention)
     * on STDERR (runit log) and in the PHP error log when one is configured, then a random DB_LOST_SLEEP_MIN-MAX s
     * sleep. SIGTERM (sv stop/restart) interrupts the sleep. Returns the delay chosen.
     */
    public static function dbLost(Throwable $e): int
    {
        $delay = random_int(self::DB_LOST_SLEEP_MIN, self::DB_LOST_SLEEP_MAX);
        $what = 'DB connection lost (' . $e::class . '), exiting in ' . $delay . ' s so runit restarts the worker';
        fwrite(self::$stderr ?? STDERR, 'queue-worker: ALERT ' . $what . "\n");
        if ((string) ini_get('error_log') !== '') {
            error_log('CasaLivre ALERT: queue worker: ' . $what . '.');
        }
        (self::$sleep ?? 'sleep')($delay);
        return $delay;
    }

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
