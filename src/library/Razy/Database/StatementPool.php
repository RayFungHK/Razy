<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Database;

use PDO;
use PDOStatement;
use WeakReference;

/**
 * Class StatementPool.
 *
 * Caches PDOStatement objects keyed by SQL string to avoid redundant prepare() calls.
 * Uses LRU (Least Recently Used) eviction when the pool exceeds its maximum size.
 *
 * Performance impact: Reduces prepare() overhead by ~50% in repeated query scenarios.
 *
 * @class StatementPool
 */
class StatementPool
{
    /** @var array<string, PDOStatement> Cached prepared statements keyed by SQL string */
    private array $pool = [];

    /** @var array<string, int> Access counters for LRU eviction, keyed by SQL string */
    private array $accessOrder = [];

    /** @var int Monotonically increasing counter for tracking access order */
    private int $accessCounter = 0;

    /**
     * @var array<string, WeakReference<object>> The result object (Query) still reading each
     *                                           pooled statement. While it is alive the statement
     *                                           is busy and must not be re-executed (RZ-02: one
     *                                           SQL template now serves many different values).
     */
    private array $owners = [];

    /**
     * StatementPool constructor.
     *
     * @param PDO $pdo The PDO connection instance
     * @param int $maxSize Maximum number of statements to cache (default: 100)
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $maxSize = 100,
    ) {
    }

    /**
     * Get a cached prepared statement or prepare a new one.
     *
     * If the SQL has been prepared before, returns the cached PDOStatement
     * after calling closeCursor() to ensure it's ready for re-execution.
     * If not cached, prepares a new statement and caches it, evicting the
     * LRU entry if the pool is at capacity.
     *
     * @param string $sql The SQL string to prepare
     *
     * @return PDOStatement|false The prepared statement, or false on failure
     */
    public function getOrPrepare(string $sql): PDOStatement|false
    {
        if (isset($this->pool[$sql])) {
            if ($this->isBusy($sql)) {
                // A live result set still reads the pooled statement (e.g. an
                // N+1 loop running the same template): give this execution a
                // dedicated, uncached statement instead of resetting that cursor.
                return $this->pdo->prepare($sql);
            }

            // Reuse cached statement — close any active cursor first
            $this->pool[$sql]->closeCursor();
            $this->accessOrder[$sql] = ++$this->accessCounter;
            return $this->pool[$sql];
        }

        // Prepare new statement via PDO
        $stmt = $this->pdo->prepare($sql);

        if ($stmt === false) {
            return false;
        }

        // Evict LRU entry if pool is full
        if (\count($this->pool) >= $this->maxSize) {
            $this->evictLRU();
        }

        // Cache the statement
        $this->pool[$sql] = $stmt;
        $this->accessOrder[$sql] = ++$this->accessCounter;

        return $stmt;
    }

    /**
     * Record the object that reads the result set of a pooled statement.
     * The statement stays busy until that object is garbage collected.
     * Statements that are not the pooled one for $sql are ignored.
     *
     * @param string $sql The SQL template the statement was prepared from
     * @param PDOStatement $statement The statement returned by getOrPrepare()
     * @param object $owner The result object (Query) reading the statement
     */
    public function claim(string $sql, PDOStatement $statement, object $owner): void
    {
        if (($this->pool[$sql] ?? null) === $statement) {
            $this->owners[$sql] = WeakReference::create($owner);
        }
    }

    /**
     * Whether the pooled statement for $sql is still read by a live result object.
     *
     * @param string $sql
     *
     * @return bool
     */
    public function isBusy(string $sql): bool
    {
        return null !== ($this->owners[$sql] ?? null)?->get();
    }

    /**
     * Get the current number of cached statements.
     *
     * @return int
     */
    public function getPoolSize(): int
    {
        return \count($this->pool);
    }

    /**
     * Clear all cached statements.
     * Should be called at end of worker mode request cycles.
     */
    public function clear(): void
    {
        foreach ($this->pool as $stmt) {
            $stmt->closeCursor();
        }
        $this->pool = [];
        $this->accessOrder = [];
        $this->accessCounter = 0;
        $this->owners = [];
    }

    /**
     * Evict the least recently used statement from the pool.
     */
    private function evictLRU(): void
    {
        $lruKey = \array_search(\min($this->accessOrder), $this->accessOrder, true);
        if ($lruKey !== false) {
            $this->pool[$lruKey]->closeCursor();
            unset($this->pool[$lruKey], $this->accessOrder[$lruKey], $this->owners[$lruKey]);
        }
    }
}
