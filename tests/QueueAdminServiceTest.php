<?php

declare(strict_types=1);

namespace Razy\Tests;

use BadMethodCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Queue\Job;
use Razy\Queue\JobStatus;
use Razy\Queue\QueueStoreInterface;
use RuntimeException;

// Module code is not composer-autoloaded; load BEFORE the CoversClass attribute
// resolves (attribute validation happens before setUp).
require_once __DIR__ . '/../modules/queue-admin/default/controller/support/QueueAdminService.php';

/**
 * QueueAdminService (modules/queue-admin) pinned against an in-memory
 * QueueStoreInterface fake — the dashboard core must behave identically over
 * DatabaseStore and RedisQueueStore, so every assertion runs on the INTERFACE
 * contract only. RZ-014: every published command's logic is covered here.
 */
#[CoversClass(\Razy\Module\queueadmin\QueueAdminService::class)]
class QueueAdminServiceTest extends TestCase
{
    private FakeQueueStore $store;

    private \Razy\Module\queueadmin\QueueAdminService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new FakeQueueStore();
        $this->service = new \Razy\Module\queueadmin\QueueAdminService($this->store);
    }

    public function testConstructorEnsuresStorage(): void
    {
        $this->assertSame(1, $this->store->ensureCalls);
    }

    public function testStatusBuildsCountMatrixPerQueue(): void
    {
        $this->store->counts['emails']['pending'] = 3;
        $this->store->counts['emails']['buried'] = 1;

        $status = $this->service->status(['default', 'emails']);

        $this->assertSame(['default', 'emails'], \array_keys($status));
        $this->assertSame(['pending', 'reserved', 'completed', 'failed', 'buried'], \array_keys($status['emails']), 'every JobStatus::cases() entry, declaration order');
        $this->assertSame(3, $status['emails']['pending']);
        $this->assertSame(1, $status['emails']['buried']);
        $this->assertSame(0, $status['default']['pending']);
    }

    public function testJobNormalisesEveryDisplayField(): void
    {
        $this->store->jobs[7] = new Job(7, 'emails', 'App\SendMail', ['to' => 'a@b.c'], 2, 3, 10, 50, '2026-07-31 00:00:00', '2026-07-30 00:00:00', null, JobStatus::Failed, 'smtp down');

        $job = $this->service->job(7);

        $this->assertIsArray($job);
        $this->assertSame('emails', $job['queue']);
        $this->assertSame('App\SendMail', $job['handler']);
        $this->assertSame(['to' => 'a@b.c'], $job['payload']);
        $this->assertSame(2, $job['attempts']);
        $this->assertSame('failed', $job['status']);
        $this->assertSame('smtp down', $job['error']);
        $this->assertSame(10, $job['retry_delay']);
        $this->assertSame(50, $job['priority']);
    }

    public function testJobUnknownIdReturnsNull(): void
    {
        $this->assertNull($this->service->job(999));
    }

    public function testActionRejectsUnknownKindWithoutStoreTouch(): void
    {
        $this->store->jobs[1] = new Job(1, 'default', 'H', []);

        $out = $this->service->action(1, 'drop');

        $this->assertFalse($out['ok']);
        $this->assertStringContainsString('unknown action kind', $out['error']);
        $this->assertSame([], $this->store->mutated);
    }

    public function testActionRejectsMissingJob(): void
    {
        $out = $this->service->action(404, 'delete');

        $this->assertFalse($out['ok']);
        $this->assertStringContainsString('job not found', $out['error']);
    }

    public function testDeleteRemovesAndJobNullsOut(): void
    {
        $this->store->jobs[1] = new Job(1, 'default', 'H', []);

        $out = $this->service->action(1, 'delete');

        $this->assertTrue($out['ok']);
        $this->assertContains('delete:1', $this->store->mutated);
        $this->assertNull($out['job']);
    }

    public function testBuryCarriesForwardPriorError(): void
    {
        $this->store->jobs[2] = new Job(2, 'default', 'H', [], 3, 3, 0, 100, null, null, null, JobStatus::Failed, 'boom');

        $out = $this->service->action(2, 'bury');

        $this->assertTrue($out['ok']);
        $this->assertSame(JobStatus::Buried, $this->store->jobs[2]->status);
        $this->assertSame('boom', $this->store->jobs[2]->error);
    }

    public function testReleaseFloorsNegativeDelayAndResetsPending(): void
    {
        $this->store->jobs[3] = new Job(3, 'default', 'H', [], 1, 3, 0, 100, null, null, null, JobStatus::Failed, 'oops');

        $out = $this->service->action(3, 'release', -5);

        $this->assertTrue($out['ok']);
        $this->assertSame('release:3:0', $this->store->mutated[\array_key_last($this->store->mutated)]);
        $this->assertSame(JobStatus::Pending, $this->store->jobs[3]->status);
    }

    public function testPurgeReportsClearedCount(): void
    {
        $this->store->clearResult = 4;

        $this->assertSame(['ok' => true, 'cleared' => 4], $this->service->purge('emails'));
    }

    public function testStoreThrowingBecomesErrorFrameNotException(): void
    {
        $this->store->jobs[9] = new Job(9, 'default', 'H', []);
        $this->store->failOn = 'delete';

        $out = $this->service->action(9, 'delete');

        $this->assertFalse($out['ok']);
        $this->assertStringContainsString('RuntimeException', $out['error']);
    }
}

/**
 * Minimal in-memory QueueStoreInterface: records mutations as "kind:id"
 * strings, asserts-through-shape only. Buried deliberately: test-only code.
 */
final class FakeQueueStore implements QueueStoreInterface
{
    /** @var array<int|string, Job> */
    public array $jobs = [];

    /** @var array<string, array<string, int>> */
    public array $counts = [];

    /** @var list<string> */
    public array $mutated = [];

    public int $ensureCalls = 0;

    public int $clearResult = 0;

    public string $failOn = '';

    public function push(string $queue, string $handler, array $payload = [], int $delay = 0, int $maxAttempts = 3, int $retryDelay = 0, int $priority = 100): int|string
    {
        throw new BadMethodCallException('push not needed by queue-admin');
    }

    public function reserve(string $queue): ?Job
    {
        throw new BadMethodCallException('reserve not needed by queue-admin');
    }

    public function complete(int|string $jobId): void
    {
        $this->mutated[] = "complete:{$jobId}";
        unset($this->jobs[$jobId]);
    }

    public function release(int|string $jobId, int $retryDelay = 0, string $error = ''): void
    {
        if ($this->failOn === 'release') {
            throw new RuntimeException('release exploded');
        }
        $this->mutated[] = "release:{$jobId}:{$retryDelay}";

        if (isset($this->jobs[$jobId])) {
            $this->jobs[$jobId]->status = JobStatus::Pending;
            $this->jobs[$jobId]->error = $error;
        }
    }

    public function bury(int|string $jobId, string $error = ''): void
    {
        $this->mutated[] = "bury:{$jobId}";

        if (isset($this->jobs[$jobId])) {
            $this->jobs[$jobId]->status = JobStatus::Buried;
            $this->jobs[$jobId]->error = $error !== '' ? $error : ($this->jobs[$jobId]->error ?? '');
        }
    }

    public function delete(int|string $jobId): void
    {
        if ($this->failOn === 'delete') {
            throw new RuntimeException('delete exploded');
        }
        $this->mutated[] = "delete:{$jobId}";
        unset($this->jobs[$jobId]);
    }

    public function find(int|string $jobId): ?Job
    {
        return $this->jobs[$jobId] ?? null;
    }

    public function count(string $queue, JobStatus $status): int
    {
        return $this->counts[$queue][$status->value] ?? 0;
    }

    public function clear(string $queue): int
    {
        return $this->clearResult;
    }

    public function ensureStorage(): void
    {
        ++$this->ensureCalls;
    }
}
