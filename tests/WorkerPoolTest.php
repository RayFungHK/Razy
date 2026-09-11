<?php

declare(strict_types=1);

namespace Razy\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\WorkerPool;
use RuntimeException;

/**
 * WorkerPool end-to-end: real child processes, real line-JSON protocol, no
 * mocking — the pool's whole point is process persistence, so the tests
 * observe real pids, real crashes, real recovery. Portable (proc_open +
 * PHP_BINARY + cooperative shutdown; no posix/pcntl).
 */
#[CoversClass(WorkerPool::class)]
class WorkerPoolTest extends TestCase
{
    private ?WorkerPool $pool = null;

    protected function tearDown(): void
    {
        $this->pool?->shutdown();
        $this->pool = null;
        parent::tearDown();
    }

    public function testExecutesJobsAndReusesTheSameProcess(): void
    {
        $this->pool = new WorkerPool(size: 1);

        $job = 'return function (array $p) { return [$p["n"] * 2, getmypid()]; };';

        $r1 = $this->pool->wait($this->pool->submitCode($job, ['n' => 21]));
        $r2 = $this->pool->wait($this->pool->submitCode($job, ['n' => 5]));

        $this->assertTrue($r1['ok'], (string) ($r1['error'] ?? ''));
        $this->assertSame(42, $r1['result'][0]);
        $this->assertSame(10, $r2['result'][0]);
        $this->assertSame($r1['result'][1], $r2['result'][1], 'same worker pid across jobs = persistence');
    }

    public function testJobThrowableBecomesErrorFrame(): void
    {
        $this->pool = new WorkerPool(size: 1);

        $out = $this->pool->wait($this->pool->submitCode('return function () { throw new \RuntimeException("nope"); };'));

        $this->assertFalse($out['ok']);
        $this->assertStringContainsString('nope', $out['error'] ?? '');
        $this->assertStringContainsString('RuntimeException', $out['error'] ?? '');
    }

    public function testJobFileMustReturnClosure(): void
    {
        $this->pool = new WorkerPool(size: 1);

        $out = $this->pool->wait($this->pool->submitCode('return "not a closure";'));

        $this->assertFalse($out['ok']);
        $this->assertStringContainsString('must return a Closure', $out['error'] ?? '');
    }

    public function testPayloadAndResultRoundTrip(): void
    {
        $this->pool = new WorkerPool(size: 1);

        $payload = ['text' => '繁體中文 🚀', 'nested' => ['f' => 1.5, 'list' => [1, 2, 3]], 'null' => null];
        $out = $this->pool->wait($this->pool->submitCode('return function (array $p) { $p["echo"] = $p; return $p; };', $payload));

        $this->assertTrue($out['ok'], (string) ($out['error'] ?? ''));
        $this->assertSame($payload, $out['result']['echo']);
    }

    public function testWorkerDeathFailsJobOnceAndPoolRecovers(): void
    {
        $this->pool = new WorkerPool(size: 1);

        $dead = $this->pool->wait($this->pool->submitCode('return function () { exit(7); };'));

        $this->assertFalse($dead['ok']);
        $this->assertSame('worker died before completion', $dead['error'] ?? '');

        // Replacement worker boots on the next dispatch and succeeds.
        $alive = $this->pool->wait($this->pool->submitCode('return fn() => "ok";'));
        $this->assertTrue($alive['ok']);
        $this->assertSame('ok', $alive['result']);
    }

    public function testJobDeadlineIsEnforcedInWorkerAndPoolRecovers(): void
    {
        // Windows cannot non-blocking-read anonymous pipes (verified: fread
        // blocks, stream_select false-reads), so the deadline lives IN the
        // worker (set_time_limit): a CPU-bound job over budget self-fatal →
        // pipe EOF → parent reaps. Granularity: 1 s.
        $this->pool = new WorkerPool(size: 1);

        $burn = 'return function () { $e = microtime(true) + 5; while (microtime(true) < $e) {} return "late"; };';
        $start = \microtime(true);
        $out = $this->pool->wait($this->pool->submitCode($burn, [], 1.0));
        $elapsed = \microtime(true) - $start;

        $this->assertFalse($out['ok']);
        $this->assertSame('worker died before completion', $out['error'] ?? '');
        $this->assertLessThan(3.0, $elapsed, '1s budget must reap the worker well before the 5s job finishes');

        $next = $this->pool->wait($this->pool->submitCode('return fn() => "fresh";'));
        $this->assertTrue($next['ok'], (string) ($next['error'] ?? ''));
        $this->assertSame('fresh', $next['result']);
    }

    public function testBacklogQueuesInOneWorkerPipe(): void
    {
        $this->pool = new WorkerPool(size: 1);

        $ids = [];
        for ($i = 1; $i <= 3; ++$i) {
            $ids[] = $this->pool->submitCode('return fn(array $p) => $p["i"] * 10;', ['i' => $i]);
        }

        foreach ($ids as $n => $id) {
            $out = $this->pool->wait($id);
            $this->assertTrue($out['ok'], (string) ($out['error'] ?? ''));
            $this->assertSame(($n + 1) * 10, $out['result']);
        }
    }

    public function testTwoWorkersRunInParallel(): void
    {
        $this->pool = new WorkerPool(size: 2);

        // Each job reports its own start timestamp. Overlap ⇒ gap ≈ boot time
        // (≤ ~0.4 s); sequential ⇒ gap ≥ sleep length (2 s). Threshold 1.0 s
        // keeps a >2× margin on both sides — no wall-clock flakiness.
        $job = 'return function () { $s = microtime(true); sleep(2); return [$s, getmypid()]; };';

        $a = $this->pool->submitCode($job, [], 10.0);
        $b = $this->pool->submitCode($job, [], 10.0);
        $ra = $this->pool->wait($a);
        $rb = $this->pool->wait($b);

        $this->assertTrue($ra['ok'] && $rb['ok']);
        $this->assertNotSame($ra['result'][1], $rb['result'][1], 'jobs ran on two distinct worker processes');

        $startGap = \abs($ra['result'][0] - $rb['result'][0]);
        $this->assertLessThan(1.0, $startGap, 'both 2s jobs must start together (overlap), not sequentially');
    }

    public function testWaitUnknownJobThrows(): void
    {
        $this->pool = new WorkerPool(size: 1);

        $this->expectException(InvalidArgumentException::class);
        $this->pool->wait('nope');
    }

    public function testSubmitMissingFileThrows(): void
    {
        $this->pool = new WorkerPool(size: 1);

        $this->expectException(InvalidArgumentException::class);
        $this->pool->submit(__DIR__ . '/no_such_job_file.php');
    }

    public function testSizeIsValidated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new WorkerPool(size: 0);
    }

    public function testShutdownIsIdempotentAndRejectsNewWork(): void
    {
        $this->pool = new WorkerPool(size: 1);
        $this->pool->wait($this->pool->submitCode('return fn() => 1;'));

        $this->pool->shutdown();
        $this->pool->shutdown(); // idempotent

        $this->assertSame(0, $this->pool->stats()['alive']);
        $this->expectException(RuntimeException::class);
        $this->pool->submitCode('return fn() => 2;');
    }

    public function testStatsTrackInflightJobs(): void
    {
        $this->pool = new WorkerPool(size: 1);

        $id = $this->pool->submitCode('return fn() => 1;');
        $this->assertSame(1, $this->pool->stats()['inflight']);

        $this->pool->wait($id);
        $this->assertSame(0, $this->pool->stats()['inflight']);
    }
}
