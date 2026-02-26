<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Queue\Job;
use Razy\Queue\JobStatus;

#[CoversClass(Job::class)]
#[CoversClass(JobStatus::class)]
class JobTest extends TestCase
{
    // ── JobStatus enum ──────────────────────────────────────────

    public function testJobStatusCases(): void
    {
        $this->assertSame('pending', JobStatus::Pending->value);
        $this->assertSame('reserved', JobStatus::Reserved->value);
        $this->assertSame('completed', JobStatus::Completed->value);
        $this->assertSame('failed', JobStatus::Failed->value);
        $this->assertSame('buried', JobStatus::Buried->value);
        $this->assertCount(5, JobStatus::cases());
    }

    public function testJobStatusFrom(): void
    {
        $this->assertSame(JobStatus::Pending, JobStatus::from('pending'));
        $this->assertSame(JobStatus::Buried, JobStatus::from('buried'));
    }

    public function testJobStatusTryFromInvalid(): void
    {
        $this->assertNull(JobStatus::tryFrom('nonexistent'));
    }

    // ── Job construction ────────────────────────────────────────

    public function testConstructorDefaults(): void
    {
        $job = new Job(
            id: 1,
            queue: 'default',
            handler: 'App\Job\SendEmail',
            payload: ['to' => 'user@example.com'],
        );

        $this->assertSame(1, $job->id);
        $this->assertSame('default', $job->queue);
        $this->assertSame('App\Job\SendEmail', $job->handler);
        $this->assertSame(['to' => 'user@example.com'], $job->payload);
        $this->assertSame(0, $job->attempts);
        $this->assertSame(3, $job->maxAttempts);
        $this->assertSame(0, $job->retryDelay);
        $this->assertSame(100, $job->priority);
        $this->assertNull($job->availableAt);
        $this->assertNull($job->createdAt);
        $this->assertNull($job->reservedAt);
        $this->assertSame(JobStatus::Pending, $job->status);
        $this->assertNull($job->error);
    }

    // ── fromArray ───────────────────────────────────────────────

    public function testFromArray(): void
    {
        $row = [
            'id' => 42,
            'queue' => 'emails',
            'handler' => 'App\Job\Notify',
            'payload' => '{"subject":"Hi"}',
            'attempts' => '2',
            'max_attempts' => '5',
            'retry_delay' => '30',
            'priority' => '10',
            'available_at' => '2024-01-01 00:00:00',
            'created_at' => '2024-01-01 00:00:00',
            'reserved_at' => null,
            'status' => 'failed',
            'error' => 'Timeout',
        ];

        $job = Job::fromArray($row);

        $this->assertSame(42, $job->id);
        $this->assertSame('emails', $job->queue);
        $this->assertSame(['subject' => 'Hi'], $job->payload);
        $this->assertSame(2, $job->attempts);
        $this->assertSame(5, $job->maxAttempts);
        $this->assertSame(30, $job->retryDelay);
        $this->assertSame(10, $job->priority);
        $this->assertSame(JobStatus::Failed, $job->status);
        $this->assertSame('Timeout', $job->error);
    }

    public function testFromArrayMinimalRow(): void
    {
        $row = [
            'id' => 1,
            'handler' => 'App\Job\Foo',
        ];

        $job = Job::fromArray($row);
        $this->assertSame('default', $job->queue);
        $this->assertSame(0, $job->attempts);
        $this->assertSame(3, $job->maxAttempts);
        $this->assertSame(JobStatus::Pending, $job->status);
    }

    // ── Lifecycle methods ───────────────────────────────────────

    public function testIncrementAttempts(): void
    {
        $job = $this->createTestJob();
        $this->assertSame(0, $job->attempts);
        $job->incrementAttempts();
        $this->assertSame(1, $job->attempts);
        $job->incrementAttempts();
        $this->assertSame(2, $job->attempts);
    }

    public function testHasExhaustedAttempts(): void
    {
        $job = new Job(1, 'q', 'H', [], attempts: 0, maxAttempts: 2);
        $this->assertFalse($job->hasExhaustedAttempts());
        $job->incrementAttempts();
        $this->assertFalse($job->hasExhaustedAttempts());
        $job->incrementAttempts();
        $this->assertTrue($job->hasExhaustedAttempts());
    }

    public function testMarkReserved(): void
    {
        $job = $this->createTestJob();
        $job->markReserved();
        $this->assertSame(JobStatus::Reserved, $job->status);
        $this->assertNotNull($job->reservedAt);
    }

    public function testMarkCompleted(): void
    {
        $job = $this->createTestJob();
        $job->markCompleted();
        $this->assertSame(JobStatus::Completed, $job->status);
    }

    public function testMarkFailed(): void
    {
        $job = $this->createTestJob();
        $job->markFailed('Connection lost');
        $this->assertSame(JobStatus::Failed, $job->status);
        $this->assertSame('Connection lost', $job->error);
    }

    public function testMarkBuried(): void
    {
        $job = $this->createTestJob();
        $job->markBuried('Max retries exceeded');
        $this->assertSame(JobStatus::Buried, $job->status);
        $this->assertSame('Max retries exceeded', $job->error);
    }

    // ── toArray / roundtrip ─────────────────────────────────────

    public function testToArrayRoundtrip(): void
    {
        $job = new Job(
            id: 10,
            queue: 'default',
            handler: 'App\Job\Test',
            payload: ['key' => 'value'],
            attempts: 1,
            maxAttempts: 3,
            retryDelay: 60,
            priority: 50,
        );

        $arr = $job->toArray();
        $this->assertSame(10, $arr['id']);
        $this->assertSame('default', $arr['queue']);
        $this->assertSame('{"key":"value"}', $arr['payload']);
        $this->assertSame(1, $arr['attempts']);
        $this->assertSame(3, $arr['max_attempts']);
        $this->assertSame(60, $arr['retry_delay']);
        $this->assertSame(50, $arr['priority']);
        $this->assertSame('pending', $arr['status']);

        // Re-create from array
        $job2 = Job::fromArray($arr);
        $this->assertSame($job->id, $job2->id);
        $this->assertSame($job->payload, $job2->payload);
    }

    // ── helpers ─────────────────────────────────────────────────

    private function createTestJob(): Job
    {
        return new Job(
            id: 1,
            queue: 'default',
            handler: 'App\Job\Test',
            payload: [],
        );
    }
}
