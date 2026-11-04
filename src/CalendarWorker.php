<?php

declare(strict_types=1);

/**
 * One calendar-sync process. Claims one due feed, fetches outside any transaction, then imports
 * in one transaction. A lost database pauses and exits 1 (QueueWorkerLoop::halt) so runit restarts it.
 * No Valkey. --once (CalendarWorker::run(true)) handles a single claim and returns.
 */
final class CalendarWorker
{
    public const IDLE_SECONDS = 1;
    public const LAG_ALERT_SECONDS = 1800;
    public const TEMP_FAIL_RATE = 0.20;
    public const LEASE_EXPIRY_RATE = 0.01;
    public const ALERT_AGAIN_SECONDS = 900;
    /** A 10-minute lease sits this far past a normal 30min..6h schedule (jitter is at most 300s). */
    private const LEASE_GAP_MIN = 480;
    private const LEASE_GAP_MAX = 900;
    private const SCHEDULE_BASES = [1800, 3600, 7200, 14400, 21600];

    /** @var resource|null */
    public static $stderr = null;
    /** @var array<string, int> */
    private static array $alertedAt = [];
    /** @var list<int> */
    private static array $claims = [];
    /** @var list<int> */
    private static array $expiries = [];

    public static function run(bool $once, ?string $fixtureBase = null): int
    {
        $stop = false;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static function () use (&$stop): void {
                $stop = true;
            });
        }
        do {
            $feed = null;
            try {
                Db::ping();
                self::checkAlerts();
                $feed = HouseRepository::claimDueFeed();
                if ($feed !== null && $feed['active']) {
                    self::observeClaim($feed);
                    self::sync($feed, $fixtureBase);
                }
            } catch (Throwable $e) {
                $what = Db::isConnectionError($e) ? 'DB connection lost (' . $e::class . ')' : $e::class;
                QueueWorkerLoop::halt($what, 'calendar-worker');
                return 1;
            }
            if (!$once && !$stop && $feed === null) {
                sleep(self::IDLE_SECONDS);
            }
        } while (!$once && !$stop);
        return 0;
    }

    /** @param array{prev_due?: ?string, last_synced_at?: ?string} $feed */
    public static function observeClaim(array $feed): void
    {
        $now = time();
        self::$claims[] = $now;
        if (self::isExpiredLease($feed['prev_due'], $feed['last_synced_at'])) {
            self::$expiries[] = $now;
        }
        self::pruneWindow($now);
    }

    public static function isExpiredLease(?string $prevDue, ?string $lastSynced): bool
    {
        if ($prevDue === null || $prevDue === '' || $lastSynced === null || $lastSynced === '') {
            return false;
        }
        $due = strtotime($prevDue);
        $synced = strtotime($lastSynced);
        if ($due === false || $synced === false) {
            return false;
        }
        $gap = $due - $synced;
        foreach (self::SCHEDULE_BASES as $base) {
            if ($gap >= $base + self::LEASE_GAP_MIN && $gap <= $base + self::LEASE_GAP_MAX) {
                return true;
            }
        }
        return false;
    }

    public static function checkAlerts(): void
    {
        $lag = db()->query("SELECT EXTRACT(EPOCH FROM (now() - min(next_sync_at)))
            FROM calendar_feeds WHERE next_sync_at <= now()")->fetchColumn();
        if (is_numeric($lag) && (float) $lag > self::LAG_ALERT_SECONDS) {
            self::alert('lag', 'lag ' . (int) $lag . ' s (due feeds older than 30 min)');
        }
        $row = db()->query("SELECT
            count(*) FILTER (WHERE last_error IN ('timeout','dns','http_5xx') AND last_synced_at > now() - interval '1 hour') AS temp,
            count(*) FILTER (WHERE last_synced_at > now() - interval '1 hour') AS total
            FROM calendar_feeds")->fetch() ?: ['temp' => 0, 'total' => 0];
        $total = (int) $row['total'];
        $temp = (int) $row['temp'];
        if ($total > 0 && ($temp / $total) > self::TEMP_FAIL_RATE) {
            self::alert('temp', 'temporary failure rate ' . $temp . '/' . $total . ' over 1 h');
        }
        $claims = count(self::$claims);
        $expiries = count(self::$expiries);
        if ($claims > 0 && ($expiries / $claims) > self::LEASE_EXPIRY_RATE) {
            self::alert('lease', 'lease expiries ' . $expiries . '/' . $claims . ' claims in 1 h');
        }
    }

    /**
     * Loopback HTTP only, for the --once test against php -S. runit does not pass a fixture.
     *
     * @return array{body: string, http: int}|array{error: string, http: int}
     */
    public static function fixtureFetch(string $base, string $feedUrl): array
    {
        $parts = parse_url($feedUrl);
        $path = (string) ($parts['path'] ?? '/');
        if ($path === '' || !str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $ctx = stream_context_create([
            'http' => ['timeout' => CalendarSync::FETCH_TIMEOUT, 'ignore_errors' => true],
        ]);
        $body = @file_get_contents($base . $path . $query, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', (string) $header, $match)) {
                $status = (int) $match[1];
            }
        }
        if (!is_string($body)) {
            return ['error' => 'timeout', 'http' => $status];
        }
        return CalendarSync::classify($status ?: 200, $body);
    }

    /** @param array{id: int, property_id: int, url: string} $feed */
    private static function sync(array $feed, ?string $fixtureBase): void
    {
        $t0 = microtime(true);
        $result = $fixtureBase !== null
            ? self::fixtureFetch($fixtureBase, $feed['url'])
            : CalendarSync::fetch($feed['url'], CalendarSync::FETCH_TIMEOUT);
        self::finish($feed, $result, (int) round((microtime(true) - $t0) * 1000));
    }

    /**
     * $result is already classified (fetch or classify): a body or an error code.
     *
     * @param array{id: int, property_id: int, url: string} $feed
     * @param array{body?: string, error?: string, http?: int} $result
     */
    public static function finish(array $feed, array $result, int $ms): void
    {
        $http = (int) $result['http'];
        $now = date('Y-m-d H:i:s');
        if (isset($result['error'])) {
            self::fail($feed, (string) $result['error'], $now, $http, $ms);
            return;
        }
        $parsed = import_ics((int) $feed['property_id'], (string) $result['body']);
        if (isset($parsed['error'])) {
            self::fail($feed, (string) $parsed['error'], $now, $http, $ms);
            return;
        }
        $imported = HouseRepository::importFeed(
            (int) $feed['property_id'],
            (int) $feed['id'],
            $parsed['nights'],
            $parsed['origins'],
            $now,
            random_int(0, HouseRepository::JITTER_MAX_SECONDS)
        );
        if ($imported === null) {
            self::logLine((int) $feed['id'], (int) $feed['property_id'], 'skip', 'removed', $http, $ms);
            return;
        }
        self::logLine((int) $feed['id'], (int) $feed['property_id'], 'ok', 'ok', $http, $ms);
        self::auditSync($feed, 'ok', $imported['written']);
    }

    /** @param array{id: int, property_id: int, url: string} $feed */
    private static function fail(array $feed, string $code, string $now, int $http, int $ms): void
    {
        $row = HouseRepository::recordFeedFailure((int) $feed['id'], $code, $now, random_int(0, HouseRepository::JITTER_MAX_SECONDS));
        self::logLine((int) $feed['id'], (int) $feed['property_id'], 'nok', $code, $http, $ms);
        self::auditSync($feed, 'nok', 0);
        if ($row['stopped']) {
            activity(ActivityLog::CALENDAR_FEED_FAIL, [
                'imovel_id' => (int) $feed['property_id'],
                'feed_id' => (int) $feed['id'],
                'motivo' => $code,
            ]);
        }
    }

    /** @param array{id: int, property_id: int, url: string} $feed */
    private static function auditSync(array $feed, string $result, int $written): void
    {
        activity(ActivityLog::CALENDAR_SYNC, [
            'imovel_id' => (int) $feed['property_id'],
            'url' => CalendarSync::logRef($feed['url']),
            'novas' => $written,
            'resultado' => $result,
        ]);
    }

    private static function logLine(int $feedId, int $propertyId, string $result, string $reason, int $http, int $ms): void
    {
        $dir = dirname(__DIR__) . '/storage/logs';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $path = $dir . '/calendar-sync.log';
        $class = $http >= 500 ? '5xx' : ($http >= 400 ? '4xx' : ($http >= 200 ? '2xx' : '0'));
        $line = date('c') . " feed=$feedId property=$propertyId result=$result reason=$reason http=$class ms=$ms\n";
        file_put_contents($path, $line, FILE_APPEND);
        @chmod($path, 0600);
    }

    private static function alert(string $kind, string $what): void
    {
        $now = time();
        if ($now - (self::$alertedAt[$kind] ?? 0) < self::ALERT_AGAIN_SECONDS) {
            return;
        }
        self::$alertedAt[$kind] = $now;
        self::write('calendar-worker: ALERT ' . $what . "\n");
        if ((string) ini_get('error_log') !== '') {
            error_log('CasaLivre ALERT: calendar-worker: ' . $what . '.');
        }
    }

    private static function write(string $line): void
    {
        fwrite(self::$stderr ?? STDERR, $line);
    }

    private static function pruneWindow(int $now): void
    {
        $cut = $now - 3600;
        self::$claims = array_values(array_filter(self::$claims, static fn (int $t): bool => $t >= $cut));
        self::$expiries = array_values(array_filter(self::$expiries, static fn (int $t): bool => $t >= $cut));
    }
}
