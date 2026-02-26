<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Core interface contract for database access (Phase 2.4).
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy\Contract;

use Razy\Database\Query;
use Razy\Database\Statement;

/**
 * Contract for database access and management.
 *
 * Provides the core operations for preparing statements,
 * executing queries, and checking table existence.
 */
interface DatabaseInterface
{
    /**
     * Create a prepared statement.
     *
     * @param string $sql Optional raw SQL string
     *
     * @return Statement The prepared statement instance
     */
    public function prepare(string $sql = ''): Statement;

    /**
     * Execute a statement and return the query result.
     *
     * @param Statement $statement The statement to execute
     *
     * @return Query The query result
     */
    public function execute(Statement $statement): Query;

    /**
     * Check if a table exists in the database.
     *
     * @param string $tableName The table name to check
     *
     * @return bool True if the table exists
     */
    public function isTableExists(string $tableName): bool;

    /**
     * Get the configured table name prefix.
     *
     * @return string The table prefix
     */
    public function getPrefix(): string;

    /**
     * Begin a database transaction (supports nesting via savepoints).
     */
    public function beginTransaction(): void;

    /**
     * Commit the current transaction level.
     */
    public function commit(): void;

    /**
     * Rollback the current transaction level.
     */
    public function rollback(): void;

    /**
     * Check if a transaction is currently active.
     *
     * @return bool
     */
    public function inTransaction(): bool;

    /**
     * Execute a callback within a transaction.
     *
     * Automatically commits on success, rolls back on exception.
     *
     * @template T
     *
     * @param callable(\Razy\Database): T $callback
     *
     * @return T
     */
    public function transaction(callable $callback): mixed;
}
