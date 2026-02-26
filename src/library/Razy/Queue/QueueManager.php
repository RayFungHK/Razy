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

use Razy\Exception\QueueException;
use Throwable;

/**
 * Central facade for dispatching and processing queued jobs.
 *
 * Responsibilities:
 *  - Push jobs onto named queues
 *  - Pop and process the next available job
 *  - Manage retry / bury logic based on attempt limits
 *  - Resolve handler instances from class names
 *  - Fire lifecycle callbacks (optional)
 *
 * Usage:
 *   $manager = new QueueManager($store);
 *   $manager->dispatch('emails', SendEmailHandler::class, ['to' => 'a@b.com']);
 *   $manager->process('emails');
 *
 * @package Razy\Queue
 */
class QueueManager
{
    /** @var array<string, array<callable>> Lifecycle listeners keyed by event name */
    private array $listeners = [];

    /** @var callable|null Custom handler resolver */
    private mixed $handlerResolver = null;

    /**
     * @param QueueStoreInterface $store The underlying queue store
     */
    public function __construct(
        private readonly QueueStoreInterface $store,
    ) {
    }

    // ═══════════════════════════════════════════════════════════════
    // Dispatching
    // ═══════════════════════════════════════════════════════════════

    /**
     * Push a job onto the queue.
     *
     * @param string $queue Queue name (e.g. 'emails', 'reports')
     * @param string $handler Fully-qualified class name implementing JobHandlerInterface
     * @param array $payload Arbitrary data passed to the handler
     * @param int $delay Seconds to delay availability (0 = immediate)
     * @param int $maxAttempts Max processing attempts (default 3)
     * @param int $retryDelay Seconds to wait between retries (default 0)
     * @param int $priority Lower = higher priority (default 100)
     *
     * @return int|string Job ID
     */
    public function dispatch(
        string $queue,
        string $handler,
        array $payload = [],
        int $delay = 0,
        int $maxAttempts = 3,
        int $retryDelay = 0,
        int $priority = 100,
    ): int|string {
        $id = $this->store->push($queue, $handler, $payload, $delay, $maxAttempts, $retryDelay, $priority);

        $this->fireEvent('dispatched', [
            'id' => $id,
            'queue' => $queue,
            'handler' => $handler,
        ]);

        return $id;
    }

    /**
     * Push a job for immediate processing (delay=0, priority=0).
     */
    public function dispatchNow(
        string $queue,
        string $handler,
        array $payload = [],
        int $maxAttempts = 1,
    ): int|string {
        return $this->dispatch($queue, $handler, $payload, 0, $maxAttempts, 0, 0);
    }

    // ═══════════════════════════════════════════════════════════════
    // Processing
    // ═══════════════════════════════════════════════════════════════

    /**
     * Process the next available job from the queue.
     *
     * Reserves a job, resolves the handler, calls handle().
     * On success the job is completed. On failure:
     *   - If attempts < maxAttempts → release for retry
     *   - Otherwise → bury permanently and call handler's failed()
     *
     * @param string $queue The queue to process
     *
     * @return bool True if a job was processed, false if the queue was empty
     */
    public function process(string $queue): bool
    {
        $job = $this->store->reserve($queue);

        if ($job === null) {
            return false;
        }

        $this->fireEvent('reserved', [
            'id' => $job->id,
            'queue' => $queue,
            'handler' => $job->handler,
            'attempts' => $job->attempts,
        ]);

        try {
            $handler = $this->resolveHandler($job->handler);
            $handler->handle($job->payload);

            $this->store->complete($job->id);

            $this->fireEvent('completed', [
                'id' => $job->id,
                'queue' => $queue,
                'handler' => $job->handler,
            ]);
        } catch (Throwable $e) {
            $this->handleFailure($job, $e, $queue);
        }

        return true;
    }

    /**
     * Process up to $limit jobs from the queue.
     *
     * @param string $queue Queue name
     * @param int $limit Maximum jobs to process (0 = drain all available)
     *
     * @return int Number of jobs processed
     */
    public function processBatch(string $queue, int $limit = 0): int
    {
        $processed = 0;

        while ($limit === 0 || $processed < $limit) {
            if (!$this->process($queue)) {
                break;
            }
            ++$processed;
        }

        return $processed;
    }

    // ═══════════════════════════════════════════════════════════════
    // Job Inspection
    // ═══════════════════════════════════════════════════════════════

    /**
     * Find a job by ID.
     */
    public function find(int|string $jobId): ?Job
    {
        return $this->store->find($jobId);
    }

    /**
     * Count jobs in a queue by status.
     */
    public function count(string $queue, JobStatus $status): int
    {
        return $this->store->count($queue, $status);
    }

    /**
     * Delete a specific job.
     */
    public function delete(int|string $jobId): void
    {
        $this->store->delete($jobId);
    }

    /**
     * Clear completed and buried jobs from a queue.
     *
     * @return int Number of jobs cleared
     */
    public function clear(string $queue): int
    {
        return $this->store->clear($queue);
    }

    /**
     * Ensure the underlying storage is ready (creates tables, etc.).
     */
    public function ensureStorage(): void
    {
        $this->store->ensureStorage();
    }

    // ═══════════════════════════════════════════════════════════════
    // Configuration
    // ═══════════════════════════════════════════════════════════════

    /**
     * Register a lifecycle event listener.
     *
     * Supported events: 'dispatched', 'reserved', 'completed', 'failed', 'buried', 'released'
     *
     * @param string $event Event name
     * @param callable $listener Receives array context
     *
     * @return static
     */
    public function on(string $event, callable $listener): static
    {
        $this->listeners[$event][] = $listener;

        return $this;
    }

    /**
     * Set a custom handler resolver.
     *
     * The resolver receives the handler class name and must return a
     * JobHandlerInterface instance. This allows integration with DI containers.
     *
     * @param callable $resolver fn(string $class): JobHandlerInterface
     *
     * @return static
     */
    public function setHandlerResolver(callable $resolver): static
    {
        $this->handlerResolver = $resolver;

        return $this;
    }

    /**
     * Get the underlying store.
     */
    public function getStore(): QueueStoreInterface
    {
        return $this->store;
    }

    // ═══════════════════════════════════════════════════════════════
    // Internal
    // ═══════════════════════════════════════════════════════════════

    /**
     * Resolve a handler class name to an instance.
     *
     * @throws QueueException If the handler cannot be resolved
     */
    private function resolveHandler(string $handlerClass): JobHandlerInterface
    {
        // Use custom resolver if provided
        if ($this->handlerResolver !== null) {
            $handler = ($this->handlerResolver)($handlerClass);

            if (!$handler instanceof JobHandlerInterface) {
                throw new QueueException(
                    'Handler resolver must return a JobHandlerInterface instance, got ' . \get_debug_type($handler),
                );
            }

            return $handler;
        }

        // Default: instantiate directly
        if (!\class_exists($handlerClass)) {
            throw new QueueException("Handler class '{$handlerClass}' not found.");
        }

        $handler = new $handlerClass();

        if (!$handler instanceof JobHandlerInterface) {
            throw new QueueException(
                "Handler '{$handlerClass}' must implement " . JobHandlerInterface::class,
            );
        }

        return $handler;
    }

    /**
     * Handle a job failure — retry or bury.
     */
    private function handleFailure(Job $job, Throwable $e, string $queue): void
    {
        $errorMessage = $e->getMessage();

        if ($job->hasExhaustedAttempts()) {
            // Permanently failed
            $this->store->bury($job->id, $errorMessage);

            // Notify the handler of permanent failure
            try {
                $handler = $this->resolveHandler($job->handler);
                $handler->failed($job->payload, $e);
            } catch (Throwable) {
                // Ignore failures in the failure handler
            }

            $this->fireEvent('buried', [
                'id' => $job->id,
                'queue' => $queue,
                'handler' => $job->handler,
                'error' => $errorMessage,
                'attempts' => $job->attempts,
            ]);
        } else {
            // Release for retry
            $this->store->release($job->id, $job->retryDelay, $errorMessage);

            $this->fireEvent('released', [
                'id' => $job->id,
                'queue' => $queue,
                'handler' => $job->handler,
                'error' => $errorMessage,
                'attempts' => $job->attempts,
                'retry_delay' => $job->retryDelay,
            ]);
        }

        $this->fireEvent('failed', [
            'id' => $job->id,
            'queue' => $queue,
            'handler' => $job->handler,
            'error' => $errorMessage,
            'attempts' => $job->attempts,
        ]);
    }

    /**
     * Fire a lifecycle event.
     */
    private function fireEvent(string $event, array $context): void
    {
        if (!isset($this->listeners[$event])) {
            return;
        }

        foreach ($this->listeners[$event] as $listener) {
            $listener($context);
        }
    }
}
