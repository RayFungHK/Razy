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

use Throwable;

/**
 * Interface for job handler classes.
 *
 * Any class that processes queued jobs must implement this interface.
 * The dispatcher reconstructs the handler and calls `handle()` with
 * the deserialized payload.
 *
 * @package Razy\Queue
 */
interface JobHandlerInterface
{
    /**
     * Process the job.
     *
     * @param array<string, mixed> $payload The deserialized job payload
     *
     * @throws Throwable Any exception causes the job to be marked as failed
     */
    public function handle(array $payload): void;

    /**
     * Called when the job has permanently failed (exhausted all retries).
     *
     * Override this method to perform cleanup, send notifications, or
     * log the permanent failure.
     *
     * @param array<string, mixed> $payload The deserialized job payload
     * @param Throwable $error The last error that caused failure
     */
    public function failed(array $payload, Throwable $error): void;
}
