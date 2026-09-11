<?php

/**
 * This file is part of Razy v1.0.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Scheduler\Lock;

/**
 * Contract for scheduler overlap locks (Job::withoutOverlapping backend).
 */
interface LockInterface
{
    /**
     * Try to acquire the named lock without waiting.
     *
     * @param string $name Lock identifier (job-scoped)
     * @param int $maxSeconds Safety timeout: a lock older than this is stale
     *                        and must be reclaimable (crashed worker recovery)
     *
     * @return bool True when the lock is now held by this caller
     */
    public function acquire(string $name, int $maxSeconds): bool;

    /**
     * Release a previously acquired lock. No-op when not held.
     */
    public function release(string $name): void;
}
