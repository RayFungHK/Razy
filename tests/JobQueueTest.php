<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Database;
use Razy\Exception\QueueException;
use Razy\Queue\DatabaseStore;
use Razy\Queue\Job;
use Razy\Queue\JobHandlerInterface;
use Razy\Queue\JobStatus;
use Razy\Queue\QueueManager;
use Razy\Queue\QueueStoreInterface;

/**
 * Tests for P6: Job Queue System.
 *
 * Covers JobStatus enum, Job value object, QueueStoreInterface contract,
 * DatabaseStore implementation, and QueueManager facade.
 */
#[CoversClass(JobStatus::class)]
#[CoversClass(Job::class)]
#[CoversClass(DatabaseStore::class)]
#[CoversClass(QueueManager::class)]
#[CoversClass(QueueException::class)]
class JobQueueTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Section 1: JobStatus Enum
    // ═══════════════════════════════════════════════════════════════

    public function testJobStatusHasFiveCases(): void
    {
        $cases = JobStatus::cases();
        $this->assertCount(5, $cases);
    }

    public function testJobStatusPendingValue(): void
    {
        $this->assertSame('pending', JobStatus::Pending->value);
    }

    public function testJobStatusReservedValue(): void
    {
        $this->assertSame('reserved', JobStatus::Reserved->value);
    }

    public function testJobStatusCompletedValue(): void
    {
        $this->assertSame('completed', JobStatus::Completed->value);
    }

    public function testJobStatusFailedValue(): void
    {
        $this->assertSame('failed', JobStatus::Failed->value);
    }

    public function testJobStatusBuriedValue(): void
    {
        $this->assertSame('buried', JobStatus::Buried->value);
    }

    public function testJobStatusFromString(): void
    {
        $this->assertSame(JobStatus::Pending, JobStatus::from('pending'));
        $this->assertSame(JobStatus::Buried, JobStatus::from('buried'));
    }

    public function testJobStatusTryFromInvalidReturnsNull(): void
    {
        $this->assertNull(JobStatus::tryFrom('invalid'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 2: Job Value Object
    // ═══════════════════════════════════════════════════════════════

    // ─── Job: Constructor & Properties ───────────────────────────

    public function testJobConstructorSetsProperties(): void
    {
        $job = new Job(
            id: 1,
            queue: 'emails',
            handler: 'App\\Handler\\SendEmail',
            payload: ['to' => 'a@b.com'],
            attempts: 0,
            maxAttempts: 3,
            retryDelay: 60,
            priority: 50,
            availableAt: '2025-01-01 00:00:00',
            createdAt: '2025-01-01 00:00:00',
        );

        $this->assertSame(1, $job->id);
        $this->assertSame('emails', $job->queue);
        $this->assertSame('App\\Handler\\SendEmail', $job->handler);
        $this->assertSame(['to' => 'a@b.com'], $job->payload);
        $this->assertSame(0, $job->attempts);
        $this->assertSame(3, $job->maxAttempts);
        $this->assertSame(60, $job->retryDelay);
        $this->assertSame(50, $job->priority);
        $this->assertSame(JobStatus::Pending, $job->status);
        $this->assertNull($job->error);
    }

    public function testJobConstructorDefaults(): void
    {
        $job = new Job(id: 99, queue: 'default', handler: 'Handler', payload: []);

        $this->assertSame(0, $job->attempts);
        $this->assertSame(3, $job->maxAttempts);
        $this->assertSame(0, $job->retryDelay);
        $this->assertSame(100, $job->priority);
        $this->assertSame(JobStatus::Pending, $job->status);
        $this->assertNull($job->availableAt);
        $this->assertNull($job->createdAt);
        $this->assertNull($job->reservedAt);
        $this->assertNull($job->error);
    }

    // ─── Job: Attempt Tracking ───────────────────────────────────

    public function testJobIncrementAttempts(): void
    {
        $job = new Job(id: 1, queue: 'q', handler: 'H', payload: []);
        $job->incrementAttempts();
        $this->assertSame(1, $job->attempts);
        $job->incrementAttempts();
        $this->assertSame(2, $job->attempts);
    }

    public function testJobHasExhaustedAttemptsReturnsFalseInitially(): void
    {
        $job = new Job(id: 1, queue: 'q', handler: 'H', payload: [], maxAttempts: 3);
        $this->assertFalse($job->hasExhaustedAttempts());
    }

    public function testJobHasExhaustedAttemptsReturnsTrueAtMax(): void
    {
        $job = new Job(id: 1, queue: 'q', handler: 'H', payload: [], attempts: 3, maxAttempts: 3);
        $this->assertTrue($job->hasExhaustedAttempts());
    }

    public function testJobHasExhaustedAttemptsReturnsTrueAboveMax(): void
    {
        $job = new Job(id: 1, queue: 'q', handler: 'H', payload: [], attempts: 5, maxAttempts: 3);
        $this->assertTrue($job->hasExhaustedAttempts());
    }

    // ─── Job: Status Transitions ─────────────────────────────────

    public function testJobMarkReserved(): void
    {
        $job = new Job(id: 1, queue: 'q', handler: 'H', payload: []);
        $job->markReserved();
        $this->assertSame(JobStatus::Reserved, $job->status);
        $this->assertNotNull($job->reservedAt);
    }

    public function testJobMarkCompleted(): void
    {
        $job = new Job(id: 1, queue: 'q', handler: 'H', payload: []);
        $job->markCompleted();
        $this->assertSame(JobStatus::Completed, $job->status);
    }

    public function testJobMarkFailed(): void
    {
        $job = new Job(id: 1, queue: 'q', handler: 'H', payload: []);
        $job->markFailed('Something broke');
        $this->assertSame(JobStatus::Failed, $job->status);
        $this->assertSame('Something broke', $job->error);
    }

    public function testJobMarkBuried(): void
    {
        $job = new Job(id: 1, queue: 'q', handler: 'H', payload: []);
        $job->markBuried('Permanent failure');
        $this->assertSame(JobStatus::Buried, $job->status);
        $this->assertSame('Permanent failure', $job->error);
    }

    // ─── Job: Serialization ──────────────────────────────────────

    public function testJobToArray(): void
    {
        $job = new Job(
            id: 42,
            queue: 'reports',
            handler: 'ReportHandler',
            payload: ['type' => 'monthly'],
            attempts: 1,
            maxAttempts: 5,
            retryDelay: 30,
            priority: 10,
            availableAt: '2025-06-01 12:00:00',
            createdAt: '2025-06-01 11:00:00',
        );

        $arr = $job->toArray();
        $this->assertSame(42, $arr['id']);
        $this->assertSame('reports', $arr['queue']);
        $this->assertSame('ReportHandler', $arr['handler']);
        $this->assertSame('{"type":"monthly"}', $arr['payload']);
        $this->assertSame(1, $arr['attempts']);
        $this->assertSame(5, $arr['max_attempts']);
        $this->assertSame(30, $arr['retry_delay']);
        $this->assertSame(10, $arr['priority']);
        $this->assertSame('pending', $arr['status']);
    }

    public function testJobFromArray(): void
    {
        $row = [
            'id' => 7,
            'queue' => 'notifications',
            'handler' => 'NotifyHandler',
            'payload' => '{"channel":"sms"}',
            'attempts' => 2,
            'max_attempts' => 4,
            'retry_delay' => 120,
            'priority' => 50,
            'available_at' => '2025-06-01 12:00:00',
            'created_at' => '2025-06-01 11:00:00',
            'reserved_at' => null,
            'status' => 'pending',
            'error' => null,
        ];

        $job = Job::fromArray($row);
        $this->assertSame(7, $job->id);
        $this->assertSame('notifications', $job->queue);
        $this->assertSame('NotifyHandler', $job->handler);
        $this->assertSame(['channel' => 'sms'], $job->payload);
        $this->assertSame(2, $job->attempts);
        $this->assertSame(4, $job->maxAttempts);
        $this->assertSame(120, $job->retryDelay);
        $this->assertSame(50, $job->priority);
        $this->assertSame(JobStatus::Pending, $job->status);
    }

    public function testJobRoundTrip(): void
    {
        $original = new Job(
            id: 1,
            queue: 'q',
            handler: 'H',
            payload: ['nested' => ['key' => 'val']],
            attempts: 1,
            maxAttempts: 3,
            retryDelay: 60,
            priority: 10,
            availableAt: '2025-01-01 00:00:00',
            createdAt: '2025-01-01 00:00:00',
        );

        $restored = Job::fromArray($original->toArray());
        $this->assertSame($original->id, $restored->id);
        $this->assertSame($original->handler, $restored->handler);
        $this->assertSame($original->payload, $restored->payload);
        $this->assertSame($original->maxAttempts, $restored->maxAttempts);
    }

    public function testJobFromArrayWithMinimalData(): void
    {
        $row = [
            'id' => 1,
            'handler' => 'H',
            'payload' => '{}',
            'status' => 'pending',
        ];

        $job = Job::fromArray($row);
        $this->assertSame(1, $job->id);
        $this->assertSame('default', $job->queue);
        $this->assertSame([], $job->payload);
        $this->assertSame(3, $job->maxAttempts);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 3: QueueException
    // ═══════════════════════════════════════════════════════════════

    public function testQueueExceptionDefaultMessage(): void
    {
        $e = new QueueException();
        $this->assertSame('Queue operation failed.', $e->getMessage());
    }

    public function testQueueExceptionCustomMessage(): void
    {
        $e = new QueueException('Handler not found');
        $this->assertSame('Handler not found', $e->getMessage());
    }

    public function testQueueExceptionWithPrevious(): void
    {
        $prev = new \RuntimeException('root cause');
        $e = new QueueException('Wrapped', 500, $prev);
        $this->assertSame($prev, $e->getPrevious());
        $this->assertSame(500, $e->getCode());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 4: DatabaseStore — Storage & CRUD
    // ═══════════════════════════════════════════════════════════════

    // ─── DatabaseStore: ensureStorage ────────────────────────────

    public function testDatabaseStoreEnsureStorageCreatesTable(): void
    {
        $store = $this->createDatabaseStore();
        $store->ensureStorage();

        // Table should exist — push should work without error
        $id = $store->push('default', 'SomeHandler', ['key' => 'value']);
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testDatabaseStoreEnsureStorageIsIdempotent(): void
    {
        $store = $this->createDatabaseStore();
        $store->ensureStorage();
        $store->ensureStorage(); // Second call should not throw
        $this->assertTrue(true);
    }

    // ─── DatabaseStore: push ─────────────────────────────────────

    public function testDatabaseStorePushReturnsIncrementingId(): void
    {
        $store = $this->createDatabaseStore();
        $store->ensureStorage();

        $id1 = $store->push('q', 'Handler', ['a' => 1]);
        $id2 = $store->push('q', 'Handler', ['b' => 2]);

        $this->assertSame(1, $id1);
        $this->assertSame(2, $id2);
    }

    public function testDatabaseStorePushStoresPayloadAsJson(): void
    {
        $store = $this->createDatabaseStore();
        $store->ensureStorage();

        $id = $store->push('q', 'H', ['complex' => ['nested' => true]]);
        $job = $store->find($id);

        $this->assertNotNull($job);
        $this->assertSame(['complex' => ['nested' => true]], $job->payload);
    }

    public function testDatabaseStorePushWithDelay(): void
    {
        $store = $this->createDatabaseStore();
        $store->ensureStorage();

        $id = $store->push('q', 'H', [], 3600); // 1h delay
        $job = $store->find($id);

        $this->assertNotNull($job);
        // available_at should be in the future
        $this->assertGreaterThan(date('Y-m-d H:i:s'), $job->availableAt);
    }

    public function testDatabaseStorePushDefaultValues(): void
    {
        $store = $this->createDatabaseStore();
        $store->ensureStorage();

        $id = $store->push('myqueue', 'MyHandler', ['x' => 1]);
        $job = $store->find($id);

        $this->assertSame('myqueue', $job->queue);
        $this->assertSame('MyHandler', $job->handler);
        $this->assertSame(0, $job->attempts);
        $this->assertSame(3, $job->maxAttempts);
        $this->assertSame(0, $job->retryDelay);
        $this->assertSame(100, $job->priority);
        $this->assertSame(JobStatus::Pending, $job->status);
    }

    public function testDatabaseStorePushCustomPriorityAndRetry(): void
    {
        $store = $this->createDatabaseStore();
        $store->ensureStorage();

        $id = $store->push('q', 'H', [], 0, 5, 120, 10);
        $job = $store->find($id);

        $this->assertSame(5, $job->maxAttempts);
        $this->assertSame(120, $job->retryDelay);
        $this->assertSame(10, $job->priority);
    }

    // ─── DatabaseStore: find ─────────────────────────────────────

    public function testDatabaseStoreFindReturnsNullForMissing(): void
    {
        $store = $this->createDatabaseStore();
        $store->ensureStorage();

        $this->assertNull($store->find(999));
    }

    public function testDatabaseStoreFindReturnsJobObject(): void
    {
        $store = $this->createDatabaseStore();
        $store->ensureStorage();

        $id = $store->push('q', 'H', ['k' => 'v']);
        $job = $store->find($id);

        $this->assertInstanceOf(Job::class, $job);
        $this->assertSame($id, $job->id);
    }

    // ─── DatabaseStore: reserve ──────────────────────────────────

    public function testDatabaseStoreReserveReturnsNullOnEmptyQueue(): void
    {
        $store = $this->createDatabaseStore();
        $store->ensureStorage();

        $this->assertNull($store->reserve('empty'));
    }

    public function testDatabaseStoreReserveReturnsJobAndUpdatesStatus(): void
    {
        $store = $this->createDatabaseStore();
        $store->ensureStorage();

        $store->push('work', 'H', ['data' => 1]);
        $job = $store->reserve('work');

        $this->assertInstanceOf(Job::class, $job);
        $this->assertSame(JobStatus::Reserved, $job->status);
        $this->assertSame(1, $job->attempts);
        $this->assertNotNull($job->reservedAt);
    }

    public function testDatabaseStoreReserveMarksStatusInDb(): void
    {
        $store = $this->createDatabaseStore();
        $store->ensureStorage();

        $id = $store->push('work', 'H', []);
        $store->reserve('work');

        // Verify persisted status
        $dbJob = $store->find($id);
        $this->assertSame(JobStatus::Reserved, $dbJob->status);
    }

    public function testDatabaseStoreReserveReturnsPendingOnly(): void
    {
        $store = $this->createDatabaseStore();
        $store->ensureStorage();

        $id = $store->push('q', 'H', []);
        $store->reserve('q'); // Reserves the only job

        // Second reserve should return null (no pending left)
        $this->assertNull($store->reserve('q'));
    }

    public function testDatabaseStoreReserveRespectsQueueName(): void
    {
        $store = $this->createDatabaseStore();
        $store->ensureStorage();

        $store->push('queue_a', 'H', ['a' => 1]);
        $store->push('queue_b', 'H', ['b' => 1]);

        $jobA = $store->reserve('queue_a');
        $this->assertSame('queue_a', $jobA->queue);
        $this->assertSame(['a' => 1], $jobA->payload);

        $jobB = $store->reserve('queue_b');
        $this->assertSame('queue_b', $jobB->queue);
    }

    public function testDatabaseStoreReserveRespectsPriority(): void
    {
        $store = $this->createDatabaseStore();
        $store->ensureStorage();

        // Push low-priority first, high-priority second
        $store->push('q', 'H', ['name' => 'low'], 0, 3, 0, 200);
        $store->push('q', 'H', ['name' => 'high'], 0, 3, 0, 10);

        $first = $store->reserve('q');
        $this->assertSame(['name' => 'high'], $first->payload);
    }

    public function testDatabaseStoreReserveSkipsDelayedJobs(): void
    {
        $store = $this->createDatabaseStore();
        $store->ensureStorage();

        // Push a delayed job (1h in the future)
        $store->push('q', 'H', ['delayed' => true], 3600);
        // Push an immediate job
        $store->push('q', 'H', ['immediate' => true]);

        $job = $store->reserve('q');
        $this->assertSame(['immediate' => true], $job->payload);
    }

    // ─── DatabaseStore: complete ─────────────────────────────────

    public function testDatabaseStoreCompleteUpdatesStatus(): void
    {
        $store = $this->createDatabaseStore();
        $store->ensureStorage();

        $id = $store->push('q', 'H', []);
        $store->reserve('q');
        $store->complete($id);

        $job = $store->find($id);
        $this->assertSame(JobStatus::Completed, $job->status);
    }

    // ─── DatabaseStore: release ──────────────────────────────────

    public function testDatabaseStoreReleaseResetsStatusToPending(): void
    {
        $store = $this->createDatabaseStore();
        $store->ensureStorage();

        $id = $store->push('q', 'H', [], 0, 3, 0);
        $store->reserve('q');
        $store->release($id, 0, 'transient error');

        $job = $store->find($id);
        $this->assertSame(JobStatus::Pending, $job->status);
        $this->assertSame('transient error', $job->error);
        $this->assertNull($job->reservedAt);
    }

    public function testDatabaseStoreReleaseWithRetryDelay(): void
    {
        $store = $this->createDatabaseStore();
        $store->ensureStorage();

        $id = $store->push('q', 'H', []);
        $store->reserve('q');
        $store->release($id, 3600, 'retry later');

        $job = $store->find($id);
        $this->assertSame(JobStatus::Pending, $job->status);
        // available_at should be in the future
        $this->assertGreaterThan(date('Y-m-d H:i:s'), $job->availableAt);
    }

    // ─── DatabaseStore: bury ─────────────────────────────────────

    public function testDatabaseStoreBuryUpdatesStatus(): void
    {
        $store = $this->createDatabaseStore();
        $store->ensureStorage();

        $id = $store->push('q', 'H', []);
        $store->reserve('q');
        $store->bury($id, 'fatal error');

        $job = $store->find($id);
        $this->assertSame(JobStatus::Buried, $job->status);
        $this->assertSame('fatal error', $job->error);
    }

    // ─── DatabaseStore: delete ───────────────────────────────────

    public function testDatabaseStoreDeleteRemovesJob(): void
    {
        $store = $this->createDatabaseStore();
        $store->ensureStorage();

        $id = $store->push('q', 'H', []);
        $store->delete($id);

        $this->assertNull($store->find($id));
    }

    // ─── DatabaseStore: count ────────────────────────────────────

    public function testDatabaseStoreCountByStatus(): void
    {
        $store = $this->createDatabaseStore();
        $store->ensureStorage();

        $store->push('q', 'H', []);
        $store->push('q', 'H', []);
        $id3 = $store->push('q', 'H', []);

        $this->assertSame(3, $store->count('q', JobStatus::Pending));
        $this->assertSame(0, $store->count('q', JobStatus::Reserved));

        $store->reserve('q');
        $this->assertSame(2, $store->count('q', JobStatus::Pending));
        $this->assertSame(1, $store->count('q', JobStatus::Reserved));

        $store->bury($id3, 'err');
        $this->assertSame(1, $store->count('q', JobStatus::Pending));
        $this->assertSame(1, $store->count('q', JobStatus::Buried));
    }

    public function testDatabaseStoreCountRespectsQueueName(): void
    {
        $store = $this->createDatabaseStore();
        $store->ensureStorage();

        $store->push('alpha', 'H', []);
        $store->push('alpha', 'H', []);
        $store->push('beta', 'H', []);

        $this->assertSame(2, $store->count('alpha', JobStatus::Pending));
        $this->assertSame(1, $store->count('beta', JobStatus::Pending));
        $this->assertSame(0, $store->count('gamma', JobStatus::Pending));
    }

    // ─── DatabaseStore: clear ────────────────────────────────────

    public function testDatabaseStoreClearRemovesCompletedAndBuried(): void
    {
        $store = $this->createDatabaseStore();
        $store->ensureStorage();

        $id1 = $store->push('q', 'H', []);
        $id2 = $store->push('q', 'H', []);
        $id3 = $store->push('q', 'H', []);

        $store->reserve('q');
        $store->complete($id1);

        $store->reserve('q');
        $store->bury($id2, 'err');

        // id3 is still pending — should NOT be cleared
        $cleared = $store->clear('q');
        $this->assertSame(2, $cleared);

        $this->assertNull($store->find($id1));
        $this->assertNull($store->find($id2));
        $this->assertNotNull($store->find($id3));
    }

    public function testDatabaseStoreClearReturnsZeroWhenNothingToClear(): void
    {
        $store = $this->createDatabaseStore();
        $store->ensureStorage();

        $store->push('q', 'H', []);

        $this->assertSame(0, $store->clear('q'));
    }

    // ─── DatabaseStore: getDatabase ──────────────────────────────

    public function testDatabaseStoreGetDatabaseReturnsInstance(): void
    {
        $db = $this->createSqliteDb();
        $store = new DatabaseStore($db);

        $this->assertSame($db, $store->getDatabase());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 5: QueueManager — Dispatch & Process
    // ═══════════════════════════════════════════════════════════════

    // ─── QueueManager: dispatch ──────────────────────────────────

    public function testQueueManagerDispatchReturnsJobId(): void
    {
        $manager = $this->createQueueManager();
        $manager->ensureStorage();

        $id = $manager->dispatch('q', SuccessHandler::class, ['x' => 1]);
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testQueueManagerDispatchCreatesJob(): void
    {
        $manager = $this->createQueueManager();
        $manager->ensureStorage();

        $id = $manager->dispatch('q', SuccessHandler::class, ['x' => 1]);
        $job = $manager->find($id);

        $this->assertNotNull($job);
        $this->assertSame('q', $job->queue);
        $this->assertSame(SuccessHandler::class, $job->handler);
        $this->assertSame(['x' => 1], $job->payload);
    }

    public function testQueueManagerDispatchWithOptions(): void
    {
        $manager = $this->createQueueManager();
        $manager->ensureStorage();

        $id = $manager->dispatch(
            'q',
            SuccessHandler::class,
            [],
            delay: 0,
            maxAttempts: 5,
            retryDelay: 120,
            priority: 10,
        );

        $job = $manager->find($id);
        $this->assertSame(5, $job->maxAttempts);
        $this->assertSame(120, $job->retryDelay);
        $this->assertSame(10, $job->priority);
    }

    public function testQueueManagerDispatchNow(): void
    {
        $manager = $this->createQueueManager();
        $manager->ensureStorage();

        $id = $manager->dispatchNow('q', SuccessHandler::class, ['quick' => true]);
        $job = $manager->find($id);

        $this->assertSame(0, $job->priority);
        $this->assertSame(1, $job->maxAttempts);
    }

    // ─── QueueManager: process ───────────────────────────────────

    public function testQueueManagerProcessReturnsFalseOnEmptyQueue(): void
    {
        $manager = $this->createQueueManager();
        $manager->ensureStorage();

        $this->assertFalse($manager->process('empty'));
    }

    public function testQueueManagerProcessSuccessfulJob(): void
    {
        $manager = $this->createQueueManager();
        $manager->ensureStorage();

        SuccessHandler::$handled = [];
        $id = $manager->dispatch('q', SuccessHandler::class, ['msg' => 'hello']);
        $result = $manager->process('q');

        $this->assertTrue($result);
        $this->assertCount(1, SuccessHandler::$handled);
        $this->assertSame(['msg' => 'hello'], SuccessHandler::$handled[0]);

        $job = $manager->find($id);
        $this->assertSame(JobStatus::Completed, $job->status);
    }

    public function testQueueManagerProcessFailingJobRetries(): void
    {
        $manager = $this->createQueueManager();
        $manager->ensureStorage();

        FailHandler::$failedCalls = [];
        $id = $manager->dispatch('q', FailHandler::class, ['x' => 1], 0, 3);
        $manager->process('q');

        // After first failure, should be released (pending again)
        $job = $manager->find($id);
        $this->assertSame(JobStatus::Pending, $job->status);
        $this->assertSame('Always fails', $job->error);
    }

    public function testQueueManagerProcessBuriesExhaustedJob(): void
    {
        $manager = $this->createQueueManager();
        $manager->ensureStorage();

        FailHandler::$failedCalls = [];
        // maxAttempts=1 → first failure exhausts
        $id = $manager->dispatch('q', FailHandler::class, ['x' => 1], 0, 1);
        $manager->process('q');

        $job = $manager->find($id);
        $this->assertSame(JobStatus::Buried, $job->status);
        $this->assertSame('Always fails', $job->error);

        // handler->failed() should have been called
        $this->assertCount(1, FailHandler::$failedCalls);
        $this->assertSame(['x' => 1], FailHandler::$failedCalls[0]['payload']);
    }

    public function testQueueManagerProcessThrowsOnMissingHandler(): void
    {
        $manager = $this->createQueueManager();
        $manager->ensureStorage();

        // Push a job with a non-existent handler class
        $id = $manager->dispatch('q', 'NonExistent\\Class\\Handler', [], 0, 1);
        // Process should bury the job (handler resolution fails)
        $manager->process('q');

        $job = $manager->find($id);
        $this->assertSame(JobStatus::Buried, $job->status);
    }

    // ─── QueueManager: processBatch ──────────────────────────────

    public function testQueueManagerProcessBatchProcessesMultipleJobs(): void
    {
        $manager = $this->createQueueManager();
        $manager->ensureStorage();

        SuccessHandler::$handled = [];
        $manager->dispatch('q', SuccessHandler::class, ['n' => 1]);
        $manager->dispatch('q', SuccessHandler::class, ['n' => 2]);
        $manager->dispatch('q', SuccessHandler::class, ['n' => 3]);

        $processed = $manager->processBatch('q');
        $this->assertSame(3, $processed);
        $this->assertCount(3, SuccessHandler::$handled);
    }

    public function testQueueManagerProcessBatchRespectsLimit(): void
    {
        $manager = $this->createQueueManager();
        $manager->ensureStorage();

        SuccessHandler::$handled = [];
        $manager->dispatch('q', SuccessHandler::class, ['n' => 1]);
        $manager->dispatch('q', SuccessHandler::class, ['n' => 2]);
        $manager->dispatch('q', SuccessHandler::class, ['n' => 3]);

        $processed = $manager->processBatch('q', 2);
        $this->assertSame(2, $processed);
        $this->assertCount(2, SuccessHandler::$handled);

        // One job remaining
        $this->assertSame(1, $manager->count('q', JobStatus::Pending));
    }

    public function testQueueManagerProcessBatchReturnsZeroOnEmpty(): void
    {
        $manager = $this->createQueueManager();
        $manager->ensureStorage();

        $this->assertSame(0, $manager->processBatch('empty'));
    }

    // ─── QueueManager: count & delete & clear ────────────────────

    public function testQueueManagerCount(): void
    {
        $manager = $this->createQueueManager();
        $manager->ensureStorage();

        $manager->dispatch('q', SuccessHandler::class, []);
        $manager->dispatch('q', SuccessHandler::class, []);

        $this->assertSame(2, $manager->count('q', JobStatus::Pending));
    }

    public function testQueueManagerDelete(): void
    {
        $manager = $this->createQueueManager();
        $manager->ensureStorage();

        $id = $manager->dispatch('q', SuccessHandler::class, []);
        $manager->delete($id);

        $this->assertNull($manager->find($id));
    }

    public function testQueueManagerClear(): void
    {
        $manager = $this->createQueueManager();
        $manager->ensureStorage();

        SuccessHandler::$handled = [];
        $manager->dispatch('q', SuccessHandler::class, []);
        $manager->dispatch('q', SuccessHandler::class, []);
        $manager->processBatch('q');

        $cleared = $manager->clear('q');
        $this->assertSame(2, $cleared);
    }

    // ─── QueueManager: getStore ──────────────────────────────────

    public function testQueueManagerGetStore(): void
    {
        $store = $this->createDatabaseStore();
        $manager = new QueueManager($store);

        $this->assertSame($store, $manager->getStore());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 6: QueueManager — Lifecycle Events
    // ═══════════════════════════════════════════════════════════════

    public function testQueueManagerDispatchedEvent(): void
    {
        $manager = $this->createQueueManager();
        $manager->ensureStorage();

        $events = [];
        $manager->on('dispatched', function (array $ctx) use (&$events) {
            $events[] = $ctx;
        });

        $manager->dispatch('q', SuccessHandler::class, []);
        $this->assertCount(1, $events);
        $this->assertSame('q', $events[0]['queue']);
        $this->assertSame(SuccessHandler::class, $events[0]['handler']);
    }

    public function testQueueManagerReservedEvent(): void
    {
        $manager = $this->createQueueManager();
        $manager->ensureStorage();

        $events = [];
        $manager->on('reserved', function (array $ctx) use (&$events) {
            $events[] = $ctx;
        });

        $manager->dispatch('q', SuccessHandler::class, []);
        $manager->process('q');

        $this->assertCount(1, $events);
        $this->assertSame(1, $events[0]['attempts']);
    }

    public function testQueueManagerCompletedEvent(): void
    {
        $manager = $this->createQueueManager();
        $manager->ensureStorage();

        $events = [];
        $manager->on('completed', function (array $ctx) use (&$events) {
            $events[] = $ctx;
        });

        SuccessHandler::$handled = [];
        $manager->dispatch('q', SuccessHandler::class, []);
        $manager->process('q');

        $this->assertCount(1, $events);
        $this->assertArrayHasKey('id', $events[0]);
    }

    public function testQueueManagerFailedEvent(): void
    {
        $manager = $this->createQueueManager();
        $manager->ensureStorage();

        $events = [];
        $manager->on('failed', function (array $ctx) use (&$events) {
            $events[] = $ctx;
        });

        FailHandler::$failedCalls = [];
        $manager->dispatch('q', FailHandler::class, [], 0, 3);
        $manager->process('q');

        $this->assertCount(1, $events);
        $this->assertSame('Always fails', $events[0]['error']);
    }

    public function testQueueManagerReleasedEvent(): void
    {
        $manager = $this->createQueueManager();
        $manager->ensureStorage();

        $events = [];
        $manager->on('released', function (array $ctx) use (&$events) {
            $events[] = $ctx;
        });

        FailHandler::$failedCalls = [];
        $manager->dispatch('q', FailHandler::class, [], 0, 3, 60);
        $manager->process('q');

        $this->assertCount(1, $events);
        $this->assertSame(60, $events[0]['retry_delay']);
    }

    public function testQueueManagerBuriedEvent(): void
    {
        $manager = $this->createQueueManager();
        $manager->ensureStorage();

        $events = [];
        $manager->on('buried', function (array $ctx) use (&$events) {
            $events[] = $ctx;
        });

        FailHandler::$failedCalls = [];
        $manager->dispatch('q', FailHandler::class, [], 0, 1);
        $manager->process('q');

        $this->assertCount(1, $events);
        $this->assertSame('Always fails', $events[0]['error']);
    }

    public function testQueueManagerMultipleListenersForSameEvent(): void
    {
        $manager = $this->createQueueManager();
        $manager->ensureStorage();

        $count = 0;
        $manager->on('dispatched', function () use (&$count) { $count++; });
        $manager->on('dispatched', function () use (&$count) { $count++; });

        $manager->dispatch('q', SuccessHandler::class, []);
        $this->assertSame(2, $count);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 7: QueueManager — Handler Resolution
    // ═══════════════════════════════════════════════════════════════

    public function testQueueManagerCustomHandlerResolver(): void
    {
        $manager = $this->createQueueManager();
        $manager->ensureStorage();

        $customHandler = new SuccessHandler();
        SuccessHandler::$handled = [];

        $manager->setHandlerResolver(function (string $class) use ($customHandler) {
            return $customHandler;
        });

        $manager->dispatch('q', 'AnyClassName', ['resolved' => true]);
        $manager->process('q');

        $this->assertCount(1, SuccessHandler::$handled);
        $this->assertSame(['resolved' => true], SuccessHandler::$handled[0]);
    }

    public function testQueueManagerResolverReturnsNonInterfaceBuryesJob(): void
    {
        $manager = $this->createQueueManager();
        $manager->ensureStorage();

        $manager->setHandlerResolver(function (string $class) {
            return new \stdClass(); // Does not implement JobHandlerInterface
        });

        $id = $manager->dispatch('q', 'Whatever', [], 0, 1);
        $manager->process('q');

        $job = $manager->find($id);
        $this->assertSame(JobStatus::Buried, $job->status);
    }

    public function testQueueManagerHandlerNotImplementingInterfaceBuryesJob(): void
    {
        $manager = $this->createQueueManager();
        $manager->ensureStorage();

        // NotAHandler exists but doesn't implement JobHandlerInterface
        $id = $manager->dispatch('q', NotAHandler::class, [], 0, 1);
        $manager->process('q');

        $job = $manager->find($id);
        $this->assertSame(JobStatus::Buried, $job->status);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 8: QueueManager — Retry Logic
    // ═══════════════════════════════════════════════════════════════

    public function testQueueManagerRetryUntilExhausted(): void
    {
        $manager = $this->createQueueManager();
        $manager->ensureStorage();

        FailHandler::$failedCalls = [];
        $id = $manager->dispatch('q', FailHandler::class, ['attempt' => true], 0, 3, 0);

        // Attempt 1 → release
        $manager->process('q');
        $job = $manager->find($id);
        $this->assertSame(JobStatus::Pending, $job->status);

        // Attempt 2 → release
        $manager->process('q');
        $job = $manager->find($id);
        $this->assertSame(JobStatus::Pending, $job->status);

        // Attempt 3 → bury
        $manager->process('q');
        $job = $manager->find($id);
        $this->assertSame(JobStatus::Buried, $job->status);

        // handler->failed() called exactly once
        $this->assertCount(1, FailHandler::$failedCalls);
    }

    public function testQueueManagerSingleAttemptBuriesImmediately(): void
    {
        $manager = $this->createQueueManager();
        $manager->ensureStorage();

        FailHandler::$failedCalls = [];
        $id = $manager->dispatch('q', FailHandler::class, [], 0, 1);
        $manager->process('q');

        $job = $manager->find($id);
        $this->assertSame(JobStatus::Buried, $job->status);
        $this->assertCount(1, FailHandler::$failedCalls);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 9: QueueManager — on() chaining
    // ═══════════════════════════════════════════════════════════════

    public function testQueueManagerOnReturnsSelf(): void
    {
        $manager = $this->createQueueManager();
        $result = $manager->on('dispatched', function () {});

        $this->assertSame($manager, $result);
    }

    public function testQueueManagerSetHandlerResolverReturnsSelf(): void
    {
        $manager = $this->createQueueManager();
        $result = $manager->setHandlerResolver(function (string $class) {
            return new SuccessHandler();
        });

        $this->assertSame($manager, $result);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 10: Integration — Full Workflow
    // ═══════════════════════════════════════════════════════════════

    public function testFullWorkflowDispatchProcessComplete(): void
    {
        $manager = $this->createQueueManager();
        $manager->ensureStorage();

        SuccessHandler::$handled = [];

        // Dispatch 3 jobs
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $manager->dispatch('emails', SuccessHandler::class, ['n' => $i]);
        }

        $this->assertSame(3, $manager->count('emails', JobStatus::Pending));

        // Process all
        $processed = $manager->processBatch('emails');
        $this->assertSame(3, $processed);
        $this->assertCount(3, SuccessHandler::$handled);

        // All completed
        $this->assertSame(0, $manager->count('emails', JobStatus::Pending));
        $this->assertSame(3, $manager->count('emails', JobStatus::Completed));

        // Clear
        $cleared = $manager->clear('emails');
        $this->assertSame(3, $cleared);
        $this->assertSame(0, $manager->count('emails', JobStatus::Completed));
    }

    public function testFullWorkflowMixedSuccessAndFailure(): void
    {
        $manager = $this->createQueueManager();
        $manager->ensureStorage();

        SuccessHandler::$handled = [];
        FailHandler::$failedCalls = [];

        $successId = $manager->dispatch('q', SuccessHandler::class, ['ok' => true]);
        $failId = $manager->dispatch('q', FailHandler::class, ['fail' => true], 0, 1);

        // Process both
        $manager->processBatch('q');

        $successJob = $manager->find($successId);
        $failJob = $manager->find($failId);

        $this->assertSame(JobStatus::Completed, $successJob->status);
        $this->assertSame(JobStatus::Buried, $failJob->status);
    }

    public function testFullWorkflowMultipleQueues(): void
    {
        $manager = $this->createQueueManager();
        $manager->ensureStorage();

        SuccessHandler::$handled = [];
        $manager->dispatch('high', SuccessHandler::class, ['q' => 'high']);
        $manager->dispatch('low', SuccessHandler::class, ['q' => 'low']);

        // Process only 'high' queue
        $manager->processBatch('high');
        $this->assertCount(1, SuccessHandler::$handled);
        $this->assertSame('high', SuccessHandler::$handled[0]['q']);

        // 'low' queue still has 1 pending
        $this->assertSame(1, $manager->count('low', JobStatus::Pending));
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Create an SQLite in-memory Database instance.
     */
    private function createSqliteDb(): Database
    {
        static $counter = 0;
        $db = new Database('test_queue_' . (++$counter));
        $db->connectWithDriver('sqlite', ['path' => ':memory:']);

        return $db;
    }

    /**
     * Create a DatabaseStore backed by SQLite in-memory.
     */
    private function createDatabaseStore(): DatabaseStore
    {
        return new DatabaseStore($this->createSqliteDb());
    }

    /**
     * Create a QueueManager backed by SQLite in-memory.
     */
    private function createQueueManager(): QueueManager
    {
        return new QueueManager($this->createDatabaseStore());
    }
}

// ═══════════════════════════════════════════════════════════════
// Test Doubles
// ═══════════════════════════════════════════════════════════════

/**
 * A handler that always succeeds and records what it handled.
 */
class SuccessHandler implements JobHandlerInterface
{
    /** @var array<int, array> */
    public static array $handled = [];

    public function handle(array $payload): void
    {
        self::$handled[] = $payload;
    }

    public function failed(array $payload, \Throwable $error): void
    {
        // Not expected to be called
    }
}

/**
 * A handler that always throws and records failed() calls.
 */
class FailHandler implements JobHandlerInterface
{
    /** @var array<int, array{payload: array, error: string}> */
    public static array $failedCalls = [];

    public function handle(array $payload): void
    {
        throw new \RuntimeException('Always fails');
    }

    public function failed(array $payload, \Throwable $error): void
    {
        self::$failedCalls[] = [
            'payload' => $payload,
            'error' => $error->getMessage(),
        ];
    }
}

/**
 * A class that exists but does NOT implement JobHandlerInterface.
 */
class NotAHandler
{
    public function doSomething(): void {}
}
