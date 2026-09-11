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

use RuntimeException;

/**
 * flock()-based single-host overlap lock.
 *
 * The kernel releases flock handles when a process dies, so crashed workers
 * cannot strand a lock — no timestamp bookkeeping needed and $maxSeconds is
 * advisory (kept for interface parity with distributed backends).
 *
 * Suitable for one machine (or one K8s pod). Multi-node deployments must use
 * RedisLock instead: flock is local to the host filesystem.
 */
class FileLock implements LockInterface
{
    /** @var array<string, resource> Held lock handles keyed by lock name */
    private array $handles = [];

    public function __construct(
        private readonly string $directory,
    ) {
        if (!\is_dir($this->directory) && !@\mkdir($this->directory, 0o700, true) && !\is_dir($this->directory)) {
            throw new RuntimeException("Cannot create scheduler lock directory: {$this->directory}");
        }
    }

    public function acquire(string $name, int $maxSeconds): bool
    {
        if (isset($this->handles[$name])) {
            return true; // Re-entrant within the same process
        }

        $handle = @\fopen($this->path($name), 'c');

        if ($handle === false) {
            return false;
        }

        if (!@\flock($handle, LOCK_EX | LOCK_NB)) {
            \fclose($handle);

            return false;
        }

        $this->handles[$name] = $handle;

        return true;
    }

    public function release(string $name): void
    {
        if (!isset($this->handles[$name])) {
            return;
        }

        @\flock($this->handles[$name], LOCK_UN);
        \fclose($this->handles[$name]);
        unset($this->handles[$name]);
    }

    /**
     * Release every lock held by this process (shutdown safety net).
     */
    public function releaseAll(): void
    {
        foreach (\array_keys($this->handles) as $name) {
            $this->release($name);
        }
    }

    private function path(string $name): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . \sha1($name) . '.lock';
    }
}
