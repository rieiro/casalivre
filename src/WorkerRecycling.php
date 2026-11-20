<?php

declare(strict_types=1);

/**
 * Graceful self-recycling policy for long-running CLI workers.
 *
 * The worker evaluates this only after a batch and before sleeping, so a recycle never
 * interrupts a job, lock or transaction. terminateIfDue() logs a notice and exits 0, which
 * runit treats as a clean exit and respawns with a fresh heap and updated code.
 */
final class WorkerRecycling
{
    public const DEFAULT_MAX_MEMORY_MB = 128;
    public const DEFAULT_MAX_JOBS = 500;
    public const DEFAULT_MAX_TIME_MIN = 3600;
    public const DEFAULT_MAX_TIME_MAX = 14400;

    private int $maxMemoryBytes;
    private int $maxJobs;
    private int $maxTimeSeconds;
    private int $startedAt;
    private int $jobs = 0;

    public function __construct(
        int $maxMemoryBytes,
        int $maxJobs,
        int $maxTimeSeconds,
        ?int $startedAt = null
    ) {
        if ($maxMemoryBytes < 1 || $maxJobs < 1 || $maxTimeSeconds < 1) {
            throw new InvalidArgumentException('worker recycling thresholds must be positive');
        }
        $this->maxMemoryBytes = $maxMemoryBytes;
        $this->maxJobs = $maxJobs;
        $this->maxTimeSeconds = $maxTimeSeconds;
        $this->startedAt = $startedAt ?? time();
    }

    /**
     * Parse --max-memory=MB, --max-jobs=N and --max-time=S, then WORKER_MAX_*_... and defaults.
     * Other CLI arguments (mail|image|all, --once, --fixture) are ignored here.
     *
     * @param list<string> $argv
     * @param array<string, string|int>|null $env
     */
    public static function fromArgv(array $argv, ?array $env = null, ?int $now = null): self
    {
        $cli = self::cliOptions($argv);
        $get = static function (string $key) use ($env): ?string {
            if ($env !== null) {
                return array_key_exists($key, $env) ? (string) $env[$key] : null;
            }
            $value = getenv($key);
            return $value === false ? null : (string) $value;
        };

        $memoryMb = $cli['memory'] ?? self::positiveInt($get('WORKER_MAX_MEMORY_MB'), 'WORKER_MAX_MEMORY_MB');
        $maxJobs = $cli['jobs'] ?? self::positiveInt($get('WORKER_MAX_JOBS'), 'WORKER_MAX_JOBS');
        $maxTime = $cli['time'] ?? self::positiveInt($get('WORKER_MAX_TIME_SECONDS'), 'WORKER_MAX_TIME_SECONDS');

        $maxMemoryBytes = $memoryMb !== null
            ? $memoryMb * 1024 * 1024
            : self::defaultMaxMemoryBytesForLimit((string) ini_get('memory_limit'));

        return new self(
            $maxMemoryBytes,
            $maxJobs ?? self::DEFAULT_MAX_JOBS,
            $maxTime ?? random_int(self::DEFAULT_MAX_TIME_MIN, self::DEFAULT_MAX_TIME_MAX),
            $now
        );
    }

    /**
     * Default memory budget: 128MB, reduced to 75% when memory_limit is lower than 171MB.
     * An unlimited/empty memory_limit keeps 128MB.
     */
    public static function defaultMaxMemoryBytesForLimit(string $limit): int
    {
        $bytes = self::memoryLimitBytes($limit);
        if ($bytes <= 0) {
            return self::DEFAULT_MAX_MEMORY_MB * 1024 * 1024;
        }
        $seventyFivePercent = (int) floor($bytes * 0.75);
        return min(self::DEFAULT_MAX_MEMORY_MB * 1024 * 1024, max(1024 * 1024, $seventyFivePercent));
    }

    public function recordJobs(int $jobs = 1): void
    {
        if ($jobs < 0) {
            throw new InvalidArgumentException('job count cannot be negative');
        }
        $this->jobs += $jobs;
    }

    public function shouldRecycle(?int $memoryBytes = null, ?int $now = null): bool
    {
        $memory = $memoryBytes ?? memory_get_usage(true);
        $current = $now ?? time();

        return $memory >= $this->maxMemoryBytes
            || $this->jobs >= $this->maxJobs
            || ($current - $this->startedAt) >= $this->maxTimeSeconds;
    }

    /**
     * Evaluate the thresholds at the loop boundary. When due, emit one notice and exit 0
     * (runit's clean-exit path). Never call this inside a job, lock or transaction.
     */
    public function terminateIfDue(string $prefix, ?int $memoryBytes = null, ?int $now = null): void
    {
        $memory = $memoryBytes ?? memory_get_usage(true);
        $current = $now ?? time();
        if (!$this->shouldRecycle($memory, $current)) {
            return;
        }

        $runtime = max(0, $current - $this->startedAt);
        fwrite(
            STDOUT,
            sprintf(
                "%s: recycling process (memory: %s MB, jobs: %d, runtime: %d s)\n",
                $prefix,
                round($memory / 1024 / 1024, 1),
                $this->jobs,
                $runtime
            )
        );
        exit(0);
    }

    public function maxMemoryBytes(): int
    {
        return $this->maxMemoryBytes;
    }

    public function maxJobs(): int
    {
        return $this->maxJobs;
    }

    public function maxTimeSeconds(): int
    {
        return $this->maxTimeSeconds;
    }

    public function jobs(): int
    {
        return $this->jobs;
    }

    /** @param list<string> $argv
     *  @return array{memory?: int, jobs?: int, time?: int}
     */
    private static function cliOptions(array $argv): array
    {
        $out = [];
        foreach (array_slice($argv, 1) as $arg) {
            if (!str_starts_with($arg, '--max-')) {
                continue;
            }
            if (preg_match('/^--max-(memory|jobs|time)=(\d+)$/', $arg, $m) !== 1) {
                throw new InvalidArgumentException('invalid worker recycling option: ' . $arg);
            }
            $value = (int) $m[2];
            if ($value < 1) {
                throw new InvalidArgumentException('worker recycling option must be positive: ' . $arg);
            }
            $out[$m[1] === 'memory' ? 'memory' : ($m[1] === 'jobs' ? 'jobs' : 'time')] = $value;
        }
        return $out;
    }

    private static function positiveInt(?string $value, string $name): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('/^\d+$/', $value) !== 1 || (int) $value < 1) {
            throw new InvalidArgumentException($name . ' must be a positive integer');
        }
        return (int) $value;
    }

    private static function memoryLimitBytes(string $limit): int
    {
        $limit = strtolower(trim($limit));
        if ($limit === '' || $limit === '-1' || $limit === '0') {
            return 0;
        }
        if (preg_match('/^(\d+)([kmg]?)$/', $limit, $m) !== 1) {
            return 0;
        }
        $bytes = (int) $m[1];
        return match ($m[2]) {
            'k' => $bytes * 1024,
            'm' => $bytes * 1024 * 1024,
            'g' => $bytes * 1024 * 1024 * 1024,
            default => $bytes,
        };
    }
}
