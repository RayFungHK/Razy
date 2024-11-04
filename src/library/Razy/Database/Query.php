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

use PDO;
use PDOStatement;

class Query
{
    /**
     * Query constructor.
     *
     * @param Statement    $statement
     * @param PDOStatement $pdoStatement
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
     * Fetch the result.
     *
     * @param array $mapping a set of column would be fetched as result
     *
     * @return mixed
     */
    public function fetch(array $mapping = []): mixed
    {
        if (count($mapping) > 0) {
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
     * Fetch all results.
     *
     * @param string $type the type of the result will be returned
     *
     * @return array
     */
    public function fetchAll(string $type = ''): array
    {
        if ('group' === $type) {
            return $this->pdoStatement->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_GROUP);
        }

        if ('keypair' === $type) {
            return $this->pdoStatement->fetchAll(PDO::FETCH_KEY_PAIR);
        }

        return $this->pdoStatement->fetchAll(PDO::FETCH_ASSOC);
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
