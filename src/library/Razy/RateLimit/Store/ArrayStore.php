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

namespace Razy\RateLimit\Store;

use Razy\Contract\RateLimitStoreInterface;

/**
 * In-memory rate limit store — for testing and single-request contexts.
 *
 * Stores hit counters in a PHP array. Data is lost when the process ends.
 * Automatically prunes expired entries when accessed.
 *
 * Provides test helpers to inspect internal state:
 * - `getRecords()` — all stored records
 * - `count()` — number of active records
 * - `setCurrentTime()` / `getCurrentTime()` — override `time()` for testing
 *
 * @package Razy\RateLimit\Store
 */
class ArrayStore implements RateLimitStoreInterface
{
    /**
     * In-memory storage.
     * Format: ['key' => ['hits' => int, 'resetAt' => int], ...]
     *
     * @var array<string, array{hits: int, resetAt: int}>
     */
    private array $records = [];

    /**
     * Override for time() — when non-null, this value is used instead
     * of the real clock. Enables deterministic tests.
     */
    private ?int $currentTime = null;

    /**
     * {@inheritdoc}
     */
    public function get(string $key): ?array
    {
        if (!isset($this->records[$key])) {
            return null;
        }

        $record = $this->records[$key];

        // Auto-prune expired entries
        if ($record['resetAt'] <= $this->now()) {
            unset($this->records[$key]);

            return null;
        }

        return $record;
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, int $hits, int $resetAt): void
    {
        $this->records[$key] = [
            'hits' => $hits,
            'resetAt' => $resetAt,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): void
    {
        unset($this->records[$key]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Get all stored records (including potentially expired ones).
     *
     * @return array<string, array{hits: int, resetAt: int}>
     */
    public function getRecords(): array
    {
        return $this->records;
    }

    /**
     * Get the number of stored records (including potentially expired ones).
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->records);
    }

    /**
     * Override the clock for deterministic testing.
     *
     * @param int|null $timestamp Unix timestamp, or null to use real time.
     */
    public function setCurrentTime(?int $timestamp): void
    {
        $this->currentTime = $timestamp;
    }

    /**
     * Get the current clock value (real or overridden).
     *
     * @return int
     */
    public function getCurrentTime(): int
    {
        return $this->now();
    }

    /**
     * Clear all stored records.
     */
    public function flush(): void
    {
        $this->records = [];
    }

    /**
     * Get the current time — uses override if set, otherwise real time.
     *
     * @return int
     */
    private function now(): int
    {
        return $this->currentTime ?? time();
    }
}
