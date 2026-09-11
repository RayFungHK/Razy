<?php

declare(strict_types=1);

namespace Razy\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Scheduler\Job;
use Razy\Scheduler\Lock\FileLock;
use Razy\Scheduler\Scheduler;
use RuntimeException;

#[CoversClass(Scheduler::class)]
#[CoversClass(Job::class)]
#[CoversClass(FileLock::class)]
class SchedulerTest extends TestCase
{
    private const UTC = 'UTC';

    private const TUE_0930 = '2026-07-14 09:30:00 UTC';

    private string $lockDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lockDir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_sched_test_' . \uniqid();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->lockDir);
        parent::tearDown();
    }

    public function testDueOnlyMatchesEnabledCronMinutes(): void
    {
        $s = new Scheduler();
        $s->call(static fn () => null, 'min')->everyMinute();
        $s->call(static fn () => null, 'hr')->hourly();

        $names = \array_map(static fn (Job $j): string => $j->getName(), $s->due($this->now(), self::UTC));

        $this->assertSame(['min'], $names, 'a :30 minute must not fire an hourly job');
    }

    public function testRunExecutesDueJobAndLogsOk(): void
    {
        $fired = false;
        $events = [];

        $s = new Scheduler(new FileLock($this->lockDir));
        $s->call(function () use (&$fired): void {
            $fired = true;
        }, 'alpha')->everyMinute();

        $result = $s->run($this->now(), static function (array $e) use (&$events): void {
            $events[] = $e;
        }, self::UTC);

        $this->assertTrue($fired);
        $this->assertSame('ok', $result['alpha']);
        $this->assertSame('ok', $events[0]['status']);
        $this->assertArrayHasKey('ms', $events[0]);
    }

    public function testFailingJobRecordedNotThrown(): void
    {
        $s = new Scheduler();
        $s->call(static function (): void {
            throw new RuntimeException('kaput');
        }, 'boom')->everyMinute();

        $result = $s->run($this->now(), null, self::UTC);

        $this->assertSame('failed', $result['boom']);
    }

    public function testDisabledJobExcluded(): void
    {
        $s = new Scheduler();
        $s->call(static fn () => null, 'off')->everyMinute()->disabled();

        $this->assertSame([], $s->due($this->now(), self::UTC));
    }

    public function testDuplicateNameRejected(): void
    {
        $s = new Scheduler();
        $s->call(static fn () => null, 'dup')->everyMinute();

        $this->expectException(InvalidArgumentException::class);
        $s->call(static fn () => null, 'dup');
    }

    public function testWithoutOverlappingWithoutLockFailClosed(): void
    {
        $s = new Scheduler(); // no lock backend
        $s->call(static fn () => null, 'nl')->everyMinute()->withoutOverlapping();

        $this->assertSame('no-lock', $s->run($this->now(), null, self::UTC)['nl']);
    }

    public function testOverlapSkippedWhenLockHeldElsewhere(): void
    {
        // A lock dir shared by two scheduler instances (simulating two nodes).
        $shared = new FileLock($this->lockDir);
        $this->assertTrue($shared->acquire('job:blocked', 30), 'pre-acquire by other node');

        $fired = false;
        $s = new Scheduler(new FileLock($this->lockDir));
        $s->call(function () use (&$fired): void {
            $fired = true;
        }, 'blocked')->everyMinute()->withoutOverlapping();

        $this->assertSame('skipped-overlap', $s->run($this->now(), null, self::UTC)['blocked']);
        $this->assertFalse($fired);
    }

    public function testOverlapRunsAfterRelease(): void
    {
        $shared = new FileLock($this->lockDir);
        $shared->acquire('job:rel', 30);
        $shared->release('job:rel');

        $s = new Scheduler(new FileLock($this->lockDir));
        $s->call(static fn () => null, 'rel')->everyMinute()->withoutOverlapping();

        $this->assertSame('ok', $s->run($this->now(), null, self::UTC)['rel']);
    }

    public function testCommandJobNonZeroExitFails(): void
    {
        $s = new Scheduler();
        // PHP_BINARY -r "exit(3);" reliably yields exit code 3.
        $s->command(\escapeshellarg(PHP_BINARY) . ' -r "exit(3);"', 'failcmd')->everyMinute();

        $this->assertSame('failed', $s->run($this->now(), null, self::UTC)['failcmd']);
    }

    public function testCommandJobZeroExitOk(): void
    {
        $s = new Scheduler();
        $s->command(\escapeshellarg(PHP_BINARY) . ' -r "echo 1;"', 'okcmd')->everyMinute();

        $this->assertSame('ok', $s->run($this->now(), null, self::UTC)['okcmd']);
    }

    public function testFrequencySugarSetsExpression(): void
    {
        $s = new Scheduler();

        $this->assertSame('*/5 * * * *', $s->call(static fn () => null)->everyFiveMinutes()->getExpression());
        $this->assertSame('*/15 * * * *', $s->call(static fn () => null)->everyFifteenMinutes()->getExpression());
        $this->assertSame('*/30 * * * *', $s->call(static fn () => null)->everyThirtyMinutes()->getExpression());
        $this->assertSame('0 0 * * *', $s->call(static fn () => null)->daily()->getExpression());
        $this->assertSame('30 3 * * *', $s->call(static fn () => null)->dailyAt('03:30')->getExpression());
        $this->assertSame('0 0 * * 0', $s->call(static fn () => null)->weekly()->getExpression());
        $this->assertSame('0 0 1 * *', $s->call(static fn () => null)->monthly()->getExpression());
    }

    public function testJobIntrospectionAfterRun(): void
    {
        $s = new Scheduler();
        $job = $s->call(static fn () => null, 'meta')->everyMinute();

        $s->run($this->now(), null, self::UTC);

        $this->assertIsInt($job->getLastRunAt());
        $this->assertIsInt($job->getLastDurationMs());
        $this->assertNull($job->getLastError());
        $this->assertSame('job:meta', $job->lockName());
    }

    private function now(): int
    {
        return (int) \strtotime(self::TUE_0930);
    }

    private function removeDir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach (\scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . DIRECTORY_SEPARATOR . $item;
            \is_dir($p) ? $this->removeDir($p) : @\unlink($p);
        }
        @\rmdir($dir);
    }
}
