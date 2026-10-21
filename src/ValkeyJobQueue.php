<?php

declare(strict_types=1);

require_once __DIR__ . '/JobQueue.php';

/**
 * Valkey connection for the queues (phpredis). Unix socket only, ACL user from VALKEY_USER
 * (cl_app in the dev env) with the password read from VALKEY_PASSWORD_FILE, never from env.
 */
final class Valkey
{
    /** Every key the app writes starts with this prefix (cl_app ACL: ~cl:* only). */
    public const PREFIX = 'cl:';

    /**
     * $timeout > 0 (seconds) bounds both connect and read (IpRateLimiter: 0.1 s, Segurança #158); 0 keeps
     * phpredis defaults (queues and workers).
     */
    public static function connect(float $timeout = 0.0): Redis
    {
        Db::loadEnv();
        if (!extension_loaded('redis')) {
            throw new RuntimeException('QUEUE_DRIVER=valkey needs the phpredis extension (php8.4-redis).');
        }
        $socket = (string) getenv('VALKEY_SOCKET');
        $user = (string) getenv('VALKEY_USER');
        $file = (string) getenv('VALKEY_PASSWORD_FILE');
        if ($socket === '' || $user === '' || $file === '') {
            throw new RuntimeException('QUEUE_DRIVER=valkey needs VALKEY_SOCKET, VALKEY_USER and VALKEY_PASSWORD_FILE.');
        }
        $pass = @file_get_contents($file);
        if ($pass === false) {
            throw new RuntimeException('VALKEY_PASSWORD_FILE is not readable.');
        }
        $r = new Redis();
        $r->connect($socket, 0, $timeout);
        if ($timeout > 0) {
            $r->setOption(Redis::OPT_READ_TIMEOUT, $timeout);
        }
        if (!$r->auth([$user, trim($pass)])) {
            throw new RuntimeException('Valkey AUTH failed for ' . $user . '.');
        }
        return $r;
    }
}

/**
 * Queue on Valkey lists, using only commands in the cl_app allowlist (no Lua, no streams, no KEYS/SCAN).
 * Keys, all under cl:q:<name>:
 *   seq           INCR job id
 *   job:<id>      HASH with the data columns, attempts, last_error, status
 *   ready         LIST of ids ready to run (LPUSH in, LMOVE out from the right: FIFO)
 *   processing    LIST of reserved ids
 *   reserved      ZSET id -> reservation time (stale reservations go back to ready)
 *   delayed       ZSET id -> next attempt time (backoff after fail)
 * Done items are deleted on ack; given-up items keep only status/attempts/last_error and expire after
 * DONE_TTL_DAYS, so purge() has nothing to delete (Valkey expires them).
 */
abstract class ValkeyJobQueue implements JobQueue
{
    public const STALE_SECONDS = SqlJobQueue::STALE_SECONDS;
    public const DONE_TTL_DAYS = 7;

    public function __construct(protected Redis $r)
    {
    }

    /** Queue name, e.g. 'mail'. */
    abstract protected function name(): string;

    /** @return list<string> data fields returned by reserve() */
    abstract protected function dataFields(): array;

    /** Fields cleared when the item gives up (e.g. the mail recipient). @return list<string> */
    protected function clearOnDone(): array
    {
        return [];
    }

    public function key(string $suffix): string
    {
        return Valkey::PREFIX . 'q:' . $this->name() . ':' . $suffix;
    }

    protected function insertJob(array $data): int
    {
        $id = $this->r->incr($this->key('seq'));
        if (!is_int($id) || $id < 1) {
            throw new RuntimeException('Valkey: INCR seq failed.');
        }
        $fields = [...array_map('strval', $data), 'attempts' => '0', 'last_error' => '', 'status' => 'pending'];
        $this->r->multi();
        $this->r->hSet($this->key('job:' . $id), $fields);
        $this->r->lPush($this->key('ready'), (string) $id);
        $res = $this->r->exec();
        if (!is_array($res) || in_array(false, $res, true)) {
            throw new RuntimeException('Valkey: enqueue failed.');
        }
        return $id;
    }

    /**
     * Moves due delayed ids and stale reservations back to ready. Each move (ZREM + LREM + LPUSH) runs in one
     * MULTI under WATCH, so a crash never loses an id and a concurrent promote never duplicates one (EXEC
     * aborts if the zset changed; the next reserve() retries). An id in processing without a reserved score
     * (crash between LMOVE and ZADD in reserve()) gets one now (ZADD NX), so it goes stale and returns to ready.
     */
    private function promote(): void
    {
        $now = time();
        $this->move('delayed', $now, false);
        $reserved = $this->r->zRange($this->key('reserved'), 0, -1, ['withscores' => true]) ?: [];
        foreach ($this->r->lRange($this->key('processing'), 0, -1) ?: [] as $id) {
            if (!isset($reserved[$id])) {
                $this->r->zAdd($this->key('reserved'), ['NX'], $now, $id);
            }
        }
        $this->move('reserved', $now - static::STALE_SECONDS, true);
    }

    /** Atomically moves the ids of zset $set with score <= $max to ready (also out of processing if $fromProcessing). */
    private function move(string $set, int $max, bool $fromProcessing): void
    {
        $key = $this->key($set);
        $this->r->watch($key);
        $ids = $this->r->zRangeByScore($key, '-inf', (string) $max) ?: [];
        if ($ids === []) {
            $this->r->unwatch();
            return;
        }
        $this->r->multi();
        foreach ($ids as $id) {
            $this->r->zRem($key, $id);
            if ($fromProcessing) {
                $this->r->lRem($this->key('processing'), $id, 1);
            }
            $this->r->lPush($this->key('ready'), $id);
        }
        $this->r->exec();
    }

    public function reserve(int $limit): array
    {
        $this->promote();
        $out = [];
        for ($i = 0; $i < max(1, $limit); $i++) {
            $id = $this->r->lMove($this->key('ready'), $this->key('processing'), 'RIGHT', 'LEFT');
            if (!is_string($id)) {
                break;
            }
            $this->r->zAdd($this->key('reserved'), time(), $id);
            $h = $this->r->hGetAll($this->key('job:' . $id));
            if (!is_array($h) || $h === []) {
                $this->release($id);
                continue;
            }
            $job = ['id' => (int) $id, 'attempts' => (int) ($h['attempts'] ?? 0)];
            foreach ($this->dataFields() as $f) {
                $job[$f] = (string) ($h[$f] ?? '');
            }
            $out[] = $this->shape($job);
        }
        return $out;
    }

    protected function shape(array $job): array
    {
        return $job;
    }

    /** Queues the removal of $id from processing/reserved inside the caller's MULTI. */
    private function queueRelease(string $id): void
    {
        $this->r->lRem($this->key('processing'), $id, 1);
        $this->r->zRem($this->key('reserved'), $id);
    }

    private function release(string $id): void
    {
        $this->r->multi();
        $this->queueRelease($id);
        $this->r->exec();
    }

    /** Release and DEL in one MULTI. */
    public function ack(int $id): void
    {
        $this->r->multi();
        $this->queueRelease((string) $id);
        $this->r->del($this->key('job:' . $id));
        $this->r->exec();
    }

    /** E-mail addresses in error text are masked before storing (Security followup: last_error lives 7 days). */
    public static function maskError(string $error): string
    {
        return substr((string) preg_replace('/[^\s<>"\'@]+@[^\s<>"\']+/', '[email]', $error), 0, 200);
    }

    public function fail(int $id, string $error = ''): bool
    {
        $job = $this->key('job:' . $id);
        $attempts = (int) $this->r->hIncrBy($job, 'attempts', 1);
        $giveUp = $attempts >= Queues::MAX_ATTEMPTS;
        // One MULTI: out of processing/reserved and into delayed (or failed) together, so a crash
        // cannot lose the job. A crash before it leaves the id in processing; it goes stale and is retried.
        $this->r->multi();
        $this->queueRelease((string) $id);
        $this->r->hSet($job, 'last_error', self::maskError($error));
        if ($giveUp) {
            $this->r->hSet($job, 'status', 'failed');
            foreach ($this->clearOnDone() as $f) {
                $this->r->hDel($job, $f);
            }
            $this->r->expire($job, self::DONE_TTL_DAYS * 86400);
        } else {
            $this->r->zAdd($this->key('delayed'), time() + Queues::backoffSeconds($attempts), (string) $id);
        }
        $this->r->exec();
        return $giveUp;
    }

    /** Done items are deleted on ack and given-up ones expire (DONE_TTL_DAYS): nothing to purge. */
    public function purge(int $days): int
    {
        return 0;
    }
}
