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


namespace Razy\Database;

use PDO;
use PDOStatement;
use Razy\Database\FetchMode;

/**
 * Class Query
 *
 * Wraps a PDOStatement result from an executed SQL query. Provides methods
 * for fetching individual rows, fetching all results, retrieving affected
 * row counts, and accessing the originating Statement.
 *
 * @package Razy
 * @license MIT
 */
class Query
{
    /**
     * Query constructor.
     *
     * @param Statement    $statement The originating Statement that produced this query
     * @param PDOStatement $pdoStatement The executed PDO statement with results
     */
    public function __construct(private Statement $statement, private PDOStatement $pdoStatement)
    {

    }

    /**
     * Get the affected row.
     *
     * @return int
     */
    public function affected(): int
    {
        return $this->pdoStatement->rowCount();
    }

    /**
     * Fetch a single row from the result set.
     * When a mapping array is provided, only the specified columns are bound
     * and returned using PDO::FETCH_BOUND.
     *
     * @param array $mapping An associative array of alias => column name to bind
     *
     * @return mixed The fetched row as associative array, or the bound mapping
     */
    public function fetch(array $mapping = []): mixed
    {
        if (count($mapping) > 0) {
            // Bind specific columns by name and fetch using FETCH_BOUND
            foreach ($mapping as $name => $column) {
                $mapping[$name] = null;
                $this->pdoStatement->bindColumn($column, $mapping[$name]);
            }
            $this->pdoStatement->fetch(PDO::FETCH_BOUND);
            $result = $mapping;
        } else {
            $result = $this->pdoStatement->fetch(PDO::FETCH_ASSOC);
        }

        return $result;
    }

    /**
     * Fetch all rows from the result set.
     *
     * @param FetchMode|string $type Fetch mode: FetchMode enum, or legacy string 'group'/'keypair'/''
     *
     * @return array The complete result set
     */
    public function fetchAll(FetchMode|string $type = FetchMode::Standard): array
    {
        // Support legacy string arguments for backward compatibility
        if (is_string($type)) {
            $type = FetchMode::tryFrom($type) ?? FetchMode::Standard;
        }

        return match ($type) {
            FetchMode::Group => $this->pdoStatement->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_GROUP),
            FetchMode::KeyPair => $this->pdoStatement->fetchAll(PDO::FETCH_KEY_PAIR),
            default => $this->pdoStatement->fetchAll(PDO::FETCH_ASSOC),
        };
    }

    /**
     * Get the Statement object.
     *
     * @return Statement
     */
    public function getStatement(): Statement
    {
        return $this->statement;
    }
}
