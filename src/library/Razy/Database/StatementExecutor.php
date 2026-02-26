<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Database;

use Razy\Database;
use Throwable;

/**
 * Handles the execution of SQL statements built by Statement.
 *
 * Separates the "execute SQL" concern from the "build SQL" concern,
 * providing a clean interface for query execution, view creation, and parameter merging.
 *
 * @package Razy\Database
 * @license MIT
 */
class StatementExecutor
{
    /**
     * @param Database  $database  The database connection instance
     * @param Statement $statement The statement to execute
     */
    public function __construct(
        private readonly Database $database,
        private readonly Statement $statement,
    ) {}

    /**
     * Execute the statement and return the Query instance for row-by-row fetching.
     * Merges any provided parameters with existing ones before execution.
     *
     * @param array $parameters Optional parameters to merge before execution
     *
     * @return Query The query result wrapper for fetching rows
     * @throws Throwable If execution fails
     */
    public function query(array $parameters = []): Query
    {
        if (count($parameters)) {
            $this->statement->mergeParameters($parameters);
        }

        return $this->database->execute($this->statement);
    }

    /**
     * Create a database view from the parent SELECT statement.
     *
     * @param string $viewTableName The name for the new view
     * @param array  $parameters    Optional parameters to merge into the statement
     *
     * @return bool True if the view was created successfully
     * @throws Error
     * @throws Throwable
     */
    public function createViewTable(string $viewTableName, array $parameters = []): bool
    {
        if (count($parameters)) {
            $this->statement->mergeParameters($parameters);
        }

        return $this->database->createViewTable($this->statement, $viewTableName);
    }

    /**
     * Get the underlying Database instance.
     *
     * @return Database
     */
    public function getDatabase(): Database
    {
        return $this->database;
    }

    /**
     * Get the underlying Statement instance.
     *
     * @return Statement
     */
    public function getStatement(): Statement
    {
        return $this->statement;
    }
}
