<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/WorkerRecycling.php';

final class WorkerRecyclingTest extends TestCase
{
    public function testMemoryThresholdTriggersAtTheBoundary(): void
    {
        $policy = new WorkerRecycling(100, 500, 3600, 1000);

        $this->assertFalse($policy->shouldRecycle(99, 1000));
        $this->assertTrue($policy->shouldRecycle(100, 1000));
    }

    public function testJobThresholdIsCountedBetweenBatches(): void
    {
        $policy = new WorkerRecycling(10_000, 2, 3600, 1000);

        $policy->recordJobs(1);
        $this->assertFalse($policy->shouldRecycle(1, 1001));

        $policy->recordJobs(1);
        $this->assertTrue($policy->shouldRecycle(1, 1002));
    }

    public function testTimeThresholdIsInclusive(): void
    {
        $policy = new WorkerRecycling(10_000, 500, 10, 1000);

        $this->assertFalse($policy->shouldRecycle(1, 1009));
        $this->assertTrue($policy->shouldRecycle(1, 1010));
    }

    public function testCliOverridesEnvironmentAndDefaults(): void
    {
        $env = [
            'WORKER_MAX_MEMORY_MB' => '96',
            'WORKER_MAX_JOBS' => '9',
            'WORKER_MAX_TIME_SECONDS' => '60',
        ];

        $fromEnv = WorkerRecycling::fromArgv([], $env, 1000);
        $this->assertSame(96 * 1024 * 1024, $fromEnv->maxMemoryBytes());
        $this->assertSame(9, $fromEnv->maxJobs());
        $this->assertSame(60, $fromEnv->maxTimeSeconds());

        $fromCli = WorkerRecycling::fromArgv(
            ['mail', '--once', '--max-memory=64', '--max-jobs=7', '--max-time=120'],
            $env,
            1000
        );
        $this->assertSame(64 * 1024 * 1024, $fromCli->maxMemoryBytes());
        $this->assertSame(7, $fromCli->maxJobs());
        $this->assertSame(120, $fromCli->maxTimeSeconds());
    }

    public function testDefaultMemoryIs75PercentOr128Mb(): void
    {
        $this->assertSame(48 * 1024 * 1024, WorkerRecycling::defaultMaxMemoryBytesForLimit('64M'));
        $this->assertSame(128 * 1024 * 1024, WorkerRecycling::defaultMaxMemoryBytesForLimit('256M'));
        $this->assertSame(128 * 1024 * 1024, WorkerRecycling::defaultMaxMemoryBytesForLimit('-1'));
    }

    public function testTerminateIfDueExitsZeroAndLogsTheNotice(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is disabled');
        }

        $code = 'require ' . var_export(dirname(__DIR__, 2) . '/src/WorkerRecycling.php', true) . ';'
            . '$p = new WorkerRecycling(1, 1, 1, time());'
            . '$p->terminateIfDue("test-worker");'
            . 'echo "after";';

        $proc = proc_open(
            [PHP_BINARY, '-d', 'disable_functions=', '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($proc);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $this->assertSame(0, $exit, $stderr);
        $this->assertStringContainsString('test-worker: recycling process', $stdout);
        $this->assertStringNotContainsString('after', $stdout);
    }
}
