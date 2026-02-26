<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Queue;

/**
 * Represents a single job payload retrieved from a queue store.
 *
 * A Job is a value object holding all information needed to execute a
 * queued task: the handler class, serialized payload, attempt count,
 * scheduling metadata, and queue name.
 */
class Job
{
    /**
     * @param int|string $id Unique job identifier (DB primary key)
     * @param string $queue Queue name this job belongs to
     * @param string $handler Fully-qualified class name of the job handler
     * @param array<string,mixed> $payload Deserialized payload data
     * @param int $attempts Number of times this job has been attempted
     * @param int $maxAttempts Maximum allowed attempts before burying
     * @param int $retryDelay Seconds to wait before retrying on failure
     * @param int $priority Lower = higher priority (default 100)
     * @param string|null $availableAt ISO datetime when the job becomes available
     * @param string|null $createdAt ISO datetime when the job was created
     * @param string|null $reservedAt ISO datetime when the job was reserved
     * @param JobStatus $status Current job status
     * @param string|null $error Last error message (if failed)
     */
    public function __construct(
        public readonly int|string $id,
        public readonly string $queue,
        public readonly string $handler,
        public readonly array $payload,
        public int $attempts = 0,
        public readonly int $maxAttempts = 3,
        public readonly int $retryDelay = 0,
        public readonly int $priority = 100,
        public readonly ?string $availableAt = null,
        public readonly ?string $createdAt = null,
        public ?string $reservedAt = null,
        public JobStatus $status = JobStatus::Pending,
        public ?string $error = null,
    ) {
    }

    /**
     * Create a Job from a database row array.
     *
     * @param array<string, mixed> $row Database row
     *
     * @return static
     */
    public static function fromArray(array $row): static
    {
        return new static(
            id: $row['id'],
            queue: $row['queue'] ?? 'default',
            handler: $row['handler'],
            payload: \json_decode($row['payload'] ?? '{}', true, 512, JSON_THROW_ON_ERROR),
            attempts: (int) ($row['attempts'] ?? 0),
            maxAttempts: (int) ($row['max_attempts'] ?? 3),
            retryDelay: (int) ($row['retry_delay'] ?? 0),
            priority: (int) ($row['priority'] ?? 100),
            availableAt: $row['available_at'] ?? null,
            createdAt: $row['created_at'] ?? null,
            reservedAt: $row['reserved_at'] ?? null,
            status: JobStatus::from($row['status'] ?? 'pending'),
            error: $row['error'] ?? null,
        );
    }

    /**
     * Increment the attempt counter.
     */
    public function incrementAttempts(): void
    {
        $this->attempts++;
    }

    /**
     * Whether this job has exhausted all retry attempts.
     */
    public function hasExhaustedAttempts(): bool
    {
        return $this->attempts >= $this->maxAttempts;
    }

    /**
     * Mark the job as reserved.
     */
    public function markReserved(): void
    {
        $this->status = JobStatus::Reserved;
        $this->reservedAt = \date('Y-m-d H:i:s');
    }

    /**
     * Mark the job as completed.
     */
    public function markCompleted(): void
    {
        $this->status = JobStatus::Completed;
    }

    /**
     * Mark the job as failed with an error message.
     */
    public function markFailed(string $error): void
    {
        $this->status = JobStatus::Failed;
        $this->error = $error;
    }

    /**
     * Mark the job as permanently buried.
     */
    public function markBuried(string $error): void
    {
        $this->status = JobStatus::Buried;
        $this->error = $error;
    }

    /**
     * Convert to array for storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'queue' => $this->queue,
            'handler' => $this->handler,
            'payload' => \json_encode($this->payload, JSON_THROW_ON_ERROR),
            'attempts' => $this->attempts,
            'max_attempts' => $this->maxAttempts,
            'retry_delay' => $this->retryDelay,
            'priority' => $this->priority,
            'available_at' => $this->availableAt,
            'created_at' => $this->createdAt,
            'reserved_at' => $this->reservedAt,
            'status' => $this->status->value,
            'error' => $this->error,
        ];
    }
}
