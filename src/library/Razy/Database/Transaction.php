<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Database Transaction manager with nested savepoint support.
 *
 * Wraps PDO's native transaction API and transparently manages savepoints
 * for nested begin/commit/rollback calls. Level 0 maps to PDO::beginTransaction()
 * / PDO::commit() / PDO::rollback(). Higher levels map to SAVEPOINT / RELEASE
 * SAVEPOINT / ROLLBACK TO SAVEPOINT statements.
 *
 *
 * @license MIT
 */

namespace Razy\Database;

use PDO;
use Razy\Exception\TransactionException;
use Throwable;

/**
 * Transaction manager for the Razy Database layer.
 *
 * Supports nested transactions through automatic savepoint management.
 * Each call to `begin()` increments the nesting level; `commit()` and
 * `rollback()` decrement it. Only the outermost level interacts with
 * PDO's native transaction methods.
 *
 * @class Transaction
 */
class Transaction
{
    /** @var string Savepoint name prefix used for nested transactions */
    private const SAVEPOINT_PREFIX = 'razy_sp_';

    /** @var int Current transaction nesting depth (0 = no active transaction) */
    private int $level = 0;

    /**
     * Transaction constructor.
     *
     * @param PDO $pdo The PDO connection to manage transactions for
     */
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Begin a transaction or create a savepoint for nested transactions.
     *
     * Level 0 → PDO::beginTransaction()
     * Level N → SAVEPOINT razy_sp_N
     *
     * @throws TransactionException If the PDO begin fails
     */
    public function begin(): void
    {
        if ($this->level === 0) {
            try {
                $this->pdo->beginTransaction();
            } catch (Throwable $e) {
                throw new TransactionException('Failed to begin transaction: ' . $e->getMessage(), 0, $e);
            }
        } else {
            $this->pdo->exec('SAVEPOINT ' . $this->getSavepointName($this->level));
        }

        $this->level++;
    }

    /**
     * Commit the current transaction level.
     *
     * Level 1 → 0: PDO::commit() (outermost)
     * Level N → N-1: RELEASE SAVEPOINT razy_sp_(N-1)
     *
     * @throws TransactionException If no transaction is active
     */
    public function commit(): void
    {
        if ($this->level === 0) {
            throw new TransactionException('Cannot commit: no active transaction.');
        }

        $this->level--;

        if ($this->level === 0) {
            try {
                $this->pdo->commit();
            } catch (Throwable $e) {
                throw new TransactionException('Failed to commit transaction: ' . $e->getMessage(), 0, $e);
            }
        } else {
            $this->pdo->exec('RELEASE SAVEPOINT ' . $this->getSavepointName($this->level));
        }
    }

    /**
     * Rollback the current transaction level.
     *
     * Level 1 → 0: PDO::rollback() (outermost)
     * Level N → N-1: ROLLBACK TO SAVEPOINT razy_sp_(N-1)
     *
     * @throws TransactionException If no transaction is active
     */
    public function rollback(): void
    {
        if ($this->level === 0) {
            throw new TransactionException('Cannot rollback: no active transaction.');
        }

        $this->level--;

        if ($this->level === 0) {
            try {
                $this->pdo->rollBack();
            } catch (Throwable $e) {
                throw new TransactionException('Failed to rollback transaction: ' . $e->getMessage(), 0, $e);
            }
        } else {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $this->getSavepointName($this->level));
        }
    }

    /**
     * Execute a callback within a transaction.
     *
     * Automatically commits on success, rolls back on exception.
     * Supports nesting — if called inside an existing transaction,
     * a savepoint is used instead of a new PDO transaction.
     *
     * @template T
     *
     * @param callable(Transaction): T $callback The callback to execute
     *
     * @return T The callback's return value
     *
     * @throws Throwable Re-throws the exception after rollback
     */
    public function run(callable $callback): mixed
    {
        $this->begin();

        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (Throwable $e) {
            $this->rollback();

            throw $e;
        }
    }

    /**
     * Check if a transaction is currently active.
     *
     * @return bool True if nesting level > 0
     */
    public function active(): bool
    {
        return $this->level > 0;
    }

    /**
     * Get the current nesting depth.
     *
     * @return int 0 when no transaction is active
     */
    public function level(): int
    {
        return $this->level;
    }

    /**
     * Generate a savepoint name for the given level.
     *
     * @param int $level The savepoint level (1-based)
     *
     * @return string The savepoint name (e.g., "razy_sp_1")
     */
    private function getSavepointName(int $level): string
    {
        return self::SAVEPOINT_PREFIX . $level;
    }
}
