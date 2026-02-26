<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy\Queue;

/**
 * Contract for queue store backends.
 *
 * A queue store is responsible for persisting, reserving, updating,
 * and deleting jobs. Implementations may use a database, Redis, file
 * system, or in-memory storage.
 *
 * @package Razy\Queue
 */
interface QueueStoreInterface
{
    /**
     * Push a new job onto the queue.
     *
     * @param string $queue The queue name
     * @param string $handler Fully-qualified handler class name
     * @param array<string,mixed> $payload Job payload data
     * @param int $delay Seconds to delay before the job becomes available
     * @param int $maxAttempts Maximum number of attempts
     * @param int $retryDelay Seconds to wait between retries
     * @param int $priority Lower number = higher priority
     *
     * @return int|string The job ID
     */
    public function push(
        string $queue,
        string $handler,
        array $payload = [],
        int $delay = 0,
        int $maxAttempts = 3,
        int $retryDelay = 0,
        int $priority = 100,
    ): int|string;

    /**
     * Reserve the next available job from the queue.
     *
     * Atomically marks a pending job as reserved so that no other
     * worker can pick it up.
     *
     * @param string $queue The queue name to pop from
     *
     * @return Job|null The reserved job, or null if the queue is empty
     */
    public function reserve(string $queue): ?Job;

    /**
     * Mark a job as completed and remove it from the queue.
     *
     * @param int|string $jobId The job ID
     */
    public function complete(int|string $jobId): void;

    /**
     * Release a failed job back to the queue for retry.
     *
     * Updates the status to pending and sets the available_at timestamp
     * based on the retry delay.
     *
     * @param int|string $jobId The job ID
     * @param int $retryDelay Seconds to delay before available again
     * @param string $error The error message from the failed attempt
     */
    public function release(int|string $jobId, int $retryDelay = 0, string $error = ''): void;

    /**
     * Permanently bury a job that has exhausted all retries.
     *
     * @param int|string $jobId The job ID
     * @param string $error The final error message
     */
    public function bury(int|string $jobId, string $error = ''): void;

    /**
     * Delete a job from the queue.
     *
     * @param int|string $jobId The job ID
     */
    public function delete(int|string $jobId): void;

    /**
     * Get a job by its ID.
     *
     * @param int|string $jobId The job ID
     *
     * @return Job|null The job, or null if not found
     */
    public function find(int|string $jobId): ?Job;

    /**
     * Get the count of jobs by status.
     *
     * @param string $queue The queue name
     * @param JobStatus $status The status to count
     *
     * @return int The count
     */
    public function count(string $queue, JobStatus $status): int;

    /**
     * Clear all completed/buried jobs from a queue.
     *
     * @param string $queue The queue name
     *
     * @return int Number of jobs cleared
     */
    public function clear(string $queue): int;

    /**
     * Ensure the underlying storage (table/collection) exists.
     */
    public function ensureStorage(): void;
}
