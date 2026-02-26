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
 * @license MIT
 */

namespace Razy\Session\Driver;

use Razy\Contract\SessionDriverInterface;

/**
 * In-memory session driver for testing.
 *
 * Stores session data in a PHP array — no I/O, no side effects.
 * Ideal for unit tests where you need deterministic session behaviour.
 *
 * Supports GC simulation via last-write timestamps.
 *
 * @package Razy\Session\Driver
 */
class ArrayDriver implements SessionDriverInterface
{
    /**
     * @var array<string, array{data: array<string, mixed>, time: int}>
     */
    private array $sessions = [];

    /**
     * Whether open()/close() have been called (for assertion).
     */
    private bool $opened = false;

    /**
     * @var int Tracks how many sessions GC deleted (for testing)
     */
    private int $lastGcCount = 0;

    /**
     * {@inheritdoc}
     */
    public function open(): bool
    {
        $this->opened = true;

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function close(): bool
    {
        $this->opened = false;

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $id): array
    {
        return $this->sessions[$id]['data'] ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $id, array $data): bool
    {
        $this->sessions[$id] = [
            'data' => $data,
            'time' => time(),
        ];

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function destroy(string $id): bool
    {
        unset($this->sessions[$id]);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function gc(int $maxLifetime): int
    {
        $cutoff = time() - $maxLifetime;
        $count  = 0;

        foreach ($this->sessions as $id => $entry) {
            if ($entry['time'] < $cutoff) {
                unset($this->sessions[$id]);
                ++$count;
            }
        }

        $this->lastGcCount = $count;

        return $count;
    }

    // ── Test Helpers ──────────────────────────────────────────

    /**
     * Whether the driver is currently open.
     */
    public function isOpened(): bool
    {
        return $this->opened;
    }

    /**
     * Get the raw session store (for assertions).
     *
     * @return array<string, array{data: array, time: int}>
     */
    public function getSessions(): array
    {
        return $this->sessions;
    }

    /**
     * How many sessions exist.
     */
    public function count(): int
    {
        return count($this->sessions);
    }

    /**
     * Inject a session with a specific timestamp (for GC testing).
     */
    public function setSessionTime(string $id, int $timestamp): void
    {
        if (isset($this->sessions[$id])) {
            $this->sessions[$id]['time'] = $timestamp;
        }
    }

    /**
     * Get the last GC deletion count.
     */
    public function getLastGcCount(): int
    {
        return $this->lastGcCount;
    }
}
