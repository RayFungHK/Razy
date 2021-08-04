<?php

/*
 * This file is part of Razy v0.4.
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
	 * @var Statement
	 */
	private Statement $statement;

	/**
	 * @var PDOStatement
	 */
	private PDOStatement $pdoStatement;

	/**
	 * Query constructor.
	 *
	 * @param Statement    $statement
	 * @param PDOStatement $pdoStatement
	 */
	public function __construct(Statement $statement, PDOStatement $pdoStatement)
	{
		$this->statement    = $statement;
		$this->pdoStatement = $pdoStatement;
	}

	/**
	 * Fetch all result.
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
	 * Fetch the result.
	 *
	 * @param array $mapping a set of column would be fetched as result
	 *
	 * @return mixed
	 */
	public function fetch(array $mapping = [])
	{
		if (count($mapping) > 0) {
			foreach ($mapping as $name => $column) {
				$mapping[$name] = null;
				$this->pdoStatement->bindColumn($column, $mapping[$name]);
			}
			$this->pdoStatement->fetch(PDO::FETCH_BOUND);

			return $mapping;
		}

		return $this->pdoStatement->fetch(PDO::FETCH_ASSOC);
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
	 * Get the Statement object.
	 *
	 * @return Statement
	 */
	public function getStatement(): Statement
	{
		return $this->statement;
	}
}
