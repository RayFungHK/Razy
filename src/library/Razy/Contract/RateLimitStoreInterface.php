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

namespace Razy\Contract;

/**
 * Storage backend contract for rate limit hit counters.
 *
 * Implementations must persist hit counts with associated reset timestamps.
 * Each key represents an independent rate limit bucket. The store is responsible
 * for expiring stale entries when the reset time has passed.
 *
 * Built-in implementations:
 * - `ArrayStore`  — In-memory (for testing)
 * - `CacheStore`  — Backed by PSR-16 CacheInterface (for production)
 *
 * @package Razy\Contract
 */
interface RateLimitStoreInterface
{
    /**
     * Retrieve the current hit record for a key.
     *
     * Returns an associative array with the current hit count and the
     * Unix timestamp at which the window resets, or null if no record
     * exists (or the window has expired).
     *
     * @param string $key The rate limit bucket key.
     *
     * @return array{hits: int, resetAt: int}|null The record, or null if not found/expired.
     */
    public function get(string $key): ?array;

    /**
     * Store a hit record for a key.
     *
     * Creates or overwrites the record with the given hit count and reset
     * timestamp. The store should retain this record at least until the
     * reset timestamp has passed.
     *
     * @param string $key     The rate limit bucket key.
     * @param int    $hits    The current number of hits.
     * @param int    $resetAt Unix timestamp when the window resets.
     */
    public function set(string $key, int $hits, int $resetAt): void;

    /**
     * Delete the hit record for a key.
     *
     * Removes the record entirely, effectively resetting the counter.
     *
     * @param string $key The rate limit bucket key.
     */
    public function delete(string $key): void;
}
