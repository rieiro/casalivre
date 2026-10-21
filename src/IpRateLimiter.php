<?php

declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/AppEnv.php';
require_once __DIR__ . '/LogPrivacy.php';
require_once __DIR__ . '/ValkeyJobQueue.php';

/**
 * Fixed-window limit per client (IP, IPv6 by /64, or an account key) on Valkey, shared by web and API
 * (cleanup sprint #158; replaces the files in storage/cache/ratelimit).
 *
 * Key: cl:rl:<bucket>:<HMAC-SHA256(subkey, 'ratelimit' \0 clientKey)>, cl:* only (cl_app ACL ~cl:*), with the derived
 * subkey HMAC-SHA256(LOG_SALT, SUBKEY_LABEL) (Segurança #160), so the limiter never uses LOG_SALT itself.
 * Counting: MULTI INCR + EXPIRE NX + TTL + EXEC, so the window TTL is set atomically by the first hit and never
 * moved by later ones; Retry-After is the key's TTL (same fixed window as the file limiter: first hit + window).
 * Commands used: MULTI, EXEC, INCR, DECR, SET (NX EX), EXPIRE (NX), TTL, GET, DEL; all in the cl_app allowlist (no Lua).
 * The client IP is REMOTE_ADDR only (nginx real_ip sets it from trusted proxies); X-Forwarded-For is never read.
 *
 * Valkey down (or LOG_SALT missing outside dev): the per-bucket decision in failClosed() applies (Segurança #158),
 * logged as VALKEY_UNAVAILABLE at most once per LOG_EVERY seconds per worker, with no IP.
 */
final class IpRateLimiter
{
    public const PREFIX = Valkey::PREFIX . 'rl:';
    /** Valkey connect and read timeout for the limiter, in seconds (Segurança #158: ~100 ms). */
    public const TIMEOUT = 0.1;
    /** Retry-After of a fail-closed refusal (429), in seconds. */
    public const FAIL_CLOSED_RETRY_AFTER = 30;
    /** Valkey-down log throttle per worker, in seconds. */
    public const LOG_EVERY = 60;
    /** Label of the derived HMAC subkey: HMAC-SHA256(LOG_SALT, SUBKEY_LABEL). Bump the version to rotate the keys. */
    public const SUBKEY_LABEL = 'cl-ratelimit-v1';

    /**
     * FAIL-MODE DECISION POINT (Segurança, #158). Buckets that refuse (429 + Retry-After FAIL_CLOSED_RETRY_AFTER)
     * when the limiter cannot count: login, password reset, signup, OTP/2FA verify, email-verification resend and
     * anything that sends email or SMS. Every other bucket fails open (request allowed, logged).
     * To flip a bucket, add it to or remove it from this list; nothing else decides the fail mode.
     */
    public const FAIL_CLOSED_BUCKETS = [
        '/senha/esqueci',   // password reset (web + API), sends email
        '/cadastro',        // signup (web + API), sends the activation email
        'ativacao-reenvio', // activation resend on login of a pending account (LoginAttempt), sends email
    ];

    /** Only for dev/local without LOG_SALT (APP_ENV=dev|local); production without LOG_SALT fails per bucket. */
    private const DEV_KEY = 'casalivre-dev-ratelimit-key-not-secret';

    /** Test hook: key namespace after cl:rl: (tests/standalone isolates runs with it); '' in production. */
    public static string $namespace = '';
    /** Test hook: returns the connection (or throws); null = Valkey::connect(TIMEOUT). */
    public static ?Closure $connector = null;
    /** Test hook: where the per-worker log throttle stamp lives; null = stampDir() (storage/cache/iprl, 0700). */
    public static ?string $stampDir = null;

    private static ?Redis $conn = null;
    private static ?int $loggedAt = null;

    public static function failClosed(string $bucket): bool
    {
        return in_array($bucket, self::FAIL_CLOSED_BUCKETS, true);
    }

    /** Client key: whole IPv4; IPv6 by its /64 prefix; IPv4-mapped IPv6 as IPv4; anything else (account key) as is. */
    public static function clientKey(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin !== false && strlen($bin) === 16) {
            if (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
                return (string) inet_ntop(substr($bin, 12));
            }
            return bin2hex(substr($bin, 0, 8)) . '::/64';
        }
        return $ip;
    }

    /** Valkey key of (bucket, client); null when there is no HMAC key (LOG_SALT missing outside dev). */
    public static function key(string $bucket, string $ip): ?string
    {
        $secret = LogPrivacy::salt() ?? (AppEnv::isDevWithEnv() ? self::DEV_KEY : null);
        if ($secret === null) {
            return null;
        }
        $subkey = hash_hmac('sha256', self::SUBKEY_LABEL, $secret, true);
        return self::PREFIX . self::$namespace . $bucket . ':' . hash_hmac('sha256', "ratelimit\0" . self::clientKey($ip), $subkey);
    }

    /** Counts one hit. @return int seconds until allowed again (0 = allowed); Retry-After when > 0. */
    public static function hit(string $bucket, int $max, int $windowSeconds, ?string $ip = null): int
    {
        try {
            [$count, $ttl] = self::incr(self::keyOrFail($bucket, $ip), $windowSeconds);
        } catch (Throwable $e) {
            return self::unavailable($bucket, $e);
        }
        return $count > $max ? self::retryAfter($ttl, $windowSeconds) : 0;
    }

    /** Checks without counting: seconds until allowed (0 = one more hit still fits). */
    public static function peek(string $bucket, int $max, int $windowSeconds, ?string $ip = null): int
    {
        try {
            $key = self::keyOrFail($bucket, $ip);
            $r = self::conn();
            $res = $r->multi()->get($key)->ttl($key)->exec();
            if (!is_array($res) || count($res) !== 2) {
                throw new RuntimeException('MULTI GET/TTL failed');
            }
        } catch (Throwable $e) {
            return self::unavailable($bucket, $e);
        }
        return (int) $res[0] >= $max ? self::retryAfter((int) $res[1], $windowSeconds) : 0;
    }

    /**
     * Check, act and count without a check-then-act race: INCR claims a slot first, so parallel calls for the same
     * key get distinct counts and at most $max of them see $cabe = true. $action(bool $cabe): bool says whether
     * the action really happened; the slot is given back (DECR) unless it fit AND happened, so only real actions
     * count. Returns $action's result. Limiter unavailable: $action(false) on a fail-closed bucket, $action(true)
     * (uncounted) otherwise.
     */
    public static function reserve(string $bucket, int $max, int $windowSeconds, ?string $ip, callable $action): bool
    {
        try {
            $key = self::keyOrFail($bucket, $ip);
            [$count] = self::incr($key, $windowSeconds);
        } catch (Throwable $e) {
            return (bool) $action(self::unavailable($bucket, $e) === 0);
        }
        $cabe = $count <= $max;
        $feito = (bool) $action($cabe);
        if (!$cabe || !$feito) {
            self::release($key, $windowSeconds, $bucket);
        }
        return $feito;
    }

    /** Clears the counters of one client in the given buckets (CASOS scripts; ACL has no SCAN). */
    public static function resetClient(string $ip, array $buckets): void
    {
        $keys = array_values(array_filter(array_map(static fn (string $b): ?string => self::key($b, $ip), $buckets)));
        if ($keys !== []) {
            self::conn()->del($keys);
        }
    }

    /** Drops the cached connection (tests). */
    public static function disconnect(): void
    {
        self::$conn = null;
    }

    /** @return array{0: int, 1: int} count after INCR and key TTL */
    private static function incr(string $key, int $windowSeconds): array
    {
        $res = self::conn()->multi()->incr($key)->expire($key, max(1, $windowSeconds), 'NX')->ttl($key)->exec();
        if (!is_array($res) || count($res) !== 3 || !is_int($res[0])) {
            throw new RuntimeException('MULTI INCR/EXPIRE NX/TTL failed');
        }
        return [$res[0], (int) $res[2]];
    }

    /**
     * Gives a claimed slot back, in one MULTI (Segurança #160: no corrective INCR outside it). If the window expired
     * in between, SET NX EX recreates the key at 1 with a new window and DECR takes it to 0, so a refund never goes
     * below 0 or leaves an extra slot in the next window; on a live key SET NX is a no-op and DECR gives the slot
     * back. EXPIRE NX still heals a key without TTL.
     */
    private static function release(string $key, int $windowSeconds, string $bucket): void
    {
        try {
            $w = max(1, $windowSeconds);
            $res = self::conn()->multi()->set($key, '1', ['nx', 'ex' => $w])->decr($key)->expire($key, $w, 'NX')->exec();
            if (!is_array($res) || count($res) !== 3 || !is_int($res[1])) {
                throw new RuntimeException('MULTI SET NX/DECR/EXPIRE NX failed');
            }
        } catch (Throwable $e) {
            self::unavailable($bucket, $e);
        }
    }

    private static function retryAfter(int $ttl, int $windowSeconds): int
    {
        return $ttl > 0 ? $ttl : max(1, $windowSeconds);
    }

    private static function keyOrFail(string $bucket, ?string $ip): string
    {
        $key = self::key($bucket, $ip ?? self::clientIp());
        if ($key === null) {
            throw new RuntimeException('LOG_SALT missing: no HMAC key for the limiter');
        }
        return $key;
    }

    /** REMOTE_ADDR only (never X-Forwarded-For): behind a proxy, nginx real_ip must set it from trusted proxies. */
    private static function clientIp(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return $ip !== '' ? $ip : '0.0.0.0';
    }

    private static function conn(): Redis
    {
        if (self::$conn === null) {
            self::$conn = self::$connector !== null ? (self::$connector)() : Valkey::connect(self::TIMEOUT);
        }
        return self::$conn;
    }

    /** Limiter could not count: applies failClosed() and logs (throttled). @return int Retry-After (0 = fail open) */
    private static function unavailable(string $bucket, Throwable $e): int
    {
        self::$conn = null;
        $closed = self::failClosed($bucket);
        self::logUnavailable($bucket, $closed, $e);
        return $closed ? self::FAIL_CLOSED_RETRY_AFTER : 0;
    }

    /**
     * Private dir of the log throttle stamps (Segurança #160: not the shared /tmp, where a local user could pre-create
     * the predictable stamp with a future mtime and mute the log): storage/cache/iprl, 0700, owned by this process's
     * user, not a symlink. null when it cannot be made private: then no stamp is read or written (the per-process
     * throttle still applies), so the log is never muted.
     */
    public static function stampDir(): ?string
    {
        $dir = self::$stampDir ?? dirname(__DIR__) . '/storage/cache/iprl';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return null;
        }
        clearstatcache(true, $dir);
        if (is_link($dir) || (function_exists('posix_geteuid') && @fileowner($dir) !== posix_geteuid())) {
            return null;
        }
        if ((@fileperms($dir) & 0077) !== 0) {
            @chmod($dir, 0700);
            clearstatcache(true, $dir);
            if ((@fileperms($dir) & 0077) !== 0) {
                return null;
            }
        }
        return $dir;
    }

    /**
     * At most once per LOG_EVERY s per worker (php-fpm worker = pid; the stamp file outlives the request): one
     * error_log line and one VALKEY_UNAVAILABLE activity row (its own code, Segurança #160, so alerts and BI tell it
     * from other internal errors). No IP, key or message text (it could carry the socket path or a key): only the
     * bucket, the mode and the exception class.
     */
    private static function logUnavailable(string $bucket, bool $closed, Throwable $e): void
    {
        $now = time();
        $dir = self::stampDir();
        $stamp = $dir === null ? null : $dir . '/down-' . getmypid();
        $last = self::$loggedAt ?? ($stamp === null ? null : (@filemtime($stamp) ?: null));
        if ($last !== null && $now - $last < self::LOG_EVERY && $last <= $now) {
            return;
        }
        self::$loggedAt = $now;
        if ($stamp !== null) {
            @touch($stamp, $now);
        }
        $mode = $closed ? 'fail-closed' : 'fail-open';
        error_log('ip_rate_limiter: unavailable (' . $e::class . '), ' . $mode . ' for bucket ' . $bucket);
        if (class_exists('ActivityLog', false)) {
            ActivityLog::record(ActivityLog::VALKEY_UNAVAILABLE, [
                'rota' => $bucket,
                'modo' => $mode,
                'excecao' => $e::class,
            ]);
        }
    }
}
