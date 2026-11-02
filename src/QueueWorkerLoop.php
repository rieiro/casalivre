<?php

declare(strict_types=1);

require_once __DIR__ . '/MailWorker.php';
require_once __DIR__ . '/ImageWorker.php';
require_once __DIR__ . '/WorkerCaches.php';
require_once __DIR__ . '/PhotoRepository.php';
require_once __DIR__ . '/PhotoStorage.php';
require_once __DIR__ . '/PhotoFail.php';
require_once __DIR__ . '/ActivityLog.php';

/**
 * One batch of one long-running worker (scripts/queue-worker.php mail|image).
 * The worker never exits, so file-backed per-process caches are refreshed first when their file changed
 * (WorkerCaches::refreshIfChanged); GrupoResolvedor's entries expire by TTL.
 * The worker never reconnects to the DB in-process (Db keeps one shared connection, and the SQL queues hold it):
 * each batch pings it first (Db::ping) and a lost connection propagates, so scripts/queue-worker.php exits non-zero
 * and runit restarts it with a fresh connection (Arquitetura #142).
 * Valkey outages (RedisException) stay in this process: one ALERT, then the same pause helper, reconnecting every second.
 * A lost DB and any other exception share one ALERT writer and one backoff-then-exit rule.
 */
final class QueueWorkerLoop
{
    /** Random delay (s) before the worker exits on a lost DB or any other exception, so runit does not restart it every second. */
    public const DB_LOST_SLEEP_MIN = 5;
    public const DB_LOST_SLEEP_MAX = 30;
    /** Pause (s) between reconnects while Valkey is down. The worker stays up. */
    public const VALKEY_RETRY_SECONDS = 1;
    /**
     * How often an image worker discards photos left in processing. The sweep runs after the image batch.
     * $stuckSweptAt starts at 0, so the first batch of a worker start is followed by one sweep, then one
     * sweep per this many seconds. A restart sweeps once, after that first batch. The age limit is
     * PhotoRepository::STUCK_AFTER_SECONDS (30 min).
     */
    public const STUCK_SWEEP_SECONDS = 86400;
    /** Test hooks: replace sleep() and STDERR. */
    public static ?Closure $sleep = null;
    /** @var resource|null */
    public static $stderr = null;
    /** True after the first ALERT of the current Valkey outage, so the line is logged once. */
    private static bool $valkeyDown = false;
    private static int $stuckSweptAt = 0;

    public static function hasWebp(): bool
    {
        return function_exists('imagewebp') && (gd_info()['WebP Support'] ?? false);
    }

    /**
     * scripts/queue-worker.php on a lost DB connection, before exit(1): one alertable line (ALERT, as log-retention)
     * on STDERR (runit log) and in the PHP error log when one is configured, then a random DB_LOST_SLEEP_MIN-MAX s
     * sleep. SIGTERM (sv stop/restart) interrupts the sleep. Returns the delay chosen.
     */
    public static function dbLost(Throwable $e): int
    {
        return self::halt('DB connection lost (' . $e::class . ')');
    }

    /**
     * Any other exception (Valkey AUTH, unreadable secret file, a bug): the class name only, then the same
     * backoff and exit as dbLost. The message and the stack stay out of the log (they can carry paths and secrets).
     */
    public static function unexpected(Throwable $e): int
    {
        return self::halt($e::class);
    }

    /**
     * RedisException while opening the queues or running a batch. One ALERT the first time (STDERR and error_log), then
     * a VALKEY_RETRY_SECONDS pause unless $stop. Returns true when --once should exit 1 (no pause).
     */
    public static function valkeyDown(Throwable $e, bool $once, bool $stop): bool
    {
        if (!self::$valkeyDown) {
            self::$valkeyDown = true;
            self::alert('Valkey unavailable (' . $e::class . '), retrying every second');
        }
        if ($once) {
            return true;
        }
        if (!$stop) {
            self::pause(self::VALKEY_RETRY_SECONDS);
        }
        return false;
    }

    /** Valkey answered again: one line, and the next outage may ALERT. */
    public static function valkeyUp(): void
    {
        if (!self::$valkeyDown) {
            return;
        }
        self::$valkeyDown = false;
        fwrite(self::$stderr ?? STDERR, "queue-worker: Valkey reachable again, resuming\n");
    }

    /** One mail batch. The lock keeps a second mail process from running a batch at the same time. */
    public static function mail(MailQueue $queue, string $storageDir): ?array
    {
        self::begin();
        return MailWorker::run($queue, $storageDir . '/mail-worker.lock');
    }

    /**
     * One image batch, then the stuck sweep when it is due. The batch is first so a photo that still has
     * a queue job is handled before rows left in processing are discarded. No lock: the claim is atomic,
     * so several image workers can run. Every image worker sweeps; discardStuck is one statement.
     */
    public static function image(ImageQueue $queue): ?array
    {
        self::begin();
        $report = ImageWorker::run($queue);
        $now = time();
        if ($now - self::$stuckSweptAt >= self::STUCK_SWEEP_SECONDS) {
            self::$stuckSweptAt = $now;
            self::sweepStuck();
        }
        return $report;
    }

    /**
     * One ALERT line, then the same 5-30 s pause as a lost DB, so runit does not restart every second.
     * Returns the delay. The caller exits non-zero.
     */
    public static function halt(string $what): int
    {
        $delay = random_int(self::DB_LOST_SLEEP_MIN, self::DB_LOST_SLEEP_MAX);
        self::alert($what . ', exiting in ' . $delay . ' s so runit restarts the worker');
        self::pause($delay);
        return $delay;
    }

    private static function begin(): void
    {
        Db::ping();
        WorkerCaches::refreshIfChanged();
    }

    /** Mark processing photos older than the limit as failed, delete the original, and log each one. */
    private static function sweepStuck(): void
    {
        foreach (PhotoRepository::discardStuck() as $row) {
            PhotoStorage::deleteOriginal($row['original']);
            self::alert('stuck photo #' . $row['id'] . ' discarded (' . PhotoFail::STUCK . ')');
            ActivityLog::record(ActivityLog::PHOTO_STUCK, [
                'imovel_id' => $row['property_id'],
                'foto_id' => $row['id'],
            ]);
        }
    }

    /** One ALERT line on STDERR (runit) and, when error_log is set, in the PHP error log. Class names only; no message. */
    private static function alert(string $what): void
    {
        fwrite(self::$stderr ?? STDERR, 'queue-worker: ALERT ' . $what . "\n");
        if ((string) ini_get('error_log') !== '') {
            error_log('CasaLivre ALERT: queue worker: ' . $what . '.');
        }
    }

    private static function pause(int $seconds): void
    {
        (self::$sleep ?? 'sleep')($seconds);
    }
}
