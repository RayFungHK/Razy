<?php

/*
 * This file is part of Razy v0.4.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy;

use Exception;
use PDO;
use PDOException;
use Razy\Database\Query;
use Razy\Database\Statement;
use Throwable;

class Database
{
	/**
	 * The storage of the Database instances
	 * @var array
	 */
	private static array $instances = [];

	/**
	 * @var PDO|null
	 */
	private ?PDO $adapter = null;
	/**
	 * The charset setting of the database connection
	 * @var array
	 */
	private array $charset = [];
	/**
	 * The connection status of the adapter
	 * @var bool
	 */
	private bool $connected = false;
	/**
	 * The table prefix
	 * @var string
	 */
	private string $prefix = '';
	/**
	 * The storage of the queried SQL statement
	 * @var array
	 */
	private array $queried = [];
	private int $affected_rows = 0;

	/**
	 * Database constructor.
	 *
	 * @param string $name
	 */
	public function __construct(private string $name = '')
	{
		$this->name = trim($this->name);
		if (!$this->name) {
			$this->name = 'Database_' . sprintf('%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff));
		}
		self::$instances[$name] = $this;
	}

	/**
	 * Get the Database instance by given name
	 *
	 * @param string $name
	 *
	 * @return null|Database
	 */
	public static function GetInstance(string $name): ?Database
	{
		if (!isset(self::$instances[$name])) {
			self::$instances[$name] = new self($name);
		}

		return self::$instances[$name];
	}

	/**
	 * Clear the executed SQL statement history.
	 *
	 * @return self Chainable
	 */
	public function clearQueried(): Database
	{
		$this->queried = [];

		return $this;
	}

	/**
	 * Start connect to database.
	 *
	 * @param string $host
	 * @param string $username
	 * @param string $password
	 * @param string $database
	 *
	 * @return bool
	 */
	public function connect(string $host, string $username, string $password, string $database): bool
	{
		try {
			$connectionString = 'mysql:host=' . $host . ';dbname=' . $database . ';charset=UTF8';
			$this->adapter = new PDO($connectionString, $username, $password, [
				PDO::ATTR_PERSISTENT => true,
				PDO::ATTR_TIMEOUT => 5,
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::MYSQL_ATTR_FOUND_ROWS => true,
			]);

			$this->connected = true;

			return true;
		} catch (PDOException) {
			return false;
		}
	}

	/**
	 * Set the database timezone
	 *
	 * @param string $timezone
	 * @return $this
	 */
	public function setTimezone(string $timezone): self
	{
		if (preg_match('/^[+-]\d{0,2}:\d{0,2}$/', $timezone)) {
			$this->getDBAdapter()->exec("SET time_zone='$timezone';");
		}

		return $this;
	}

	/**
	 * Execute the Statement entity.
	 *
	 * @param Statement $statement
	 *
	 * @return Query
	 * @throws Throwable
	 */
	public function execute(Statement $statement): Query
	{
		$sql = $statement->getSyntax();

		try {
			$pdoStatement = $this->adapter->prepare($sql);
			if ($pdoStatement) {
				$pdoStatement->execute();
				$this->affected_rows = $pdoStatement->rowCount();
			}
		} catch (Exception $e) {
			throw new Error($e->getMessage() . "\n" . $sql, 500, Error::DEFAULT_HEADING, $sql);
		}

		$this->queried[] = $sql;

		return new Query($statement, $pdoStatement);
	}

	/**
	 * Get the affected row by latest query.
	 *
	 * @return int
	 */
	public function affectedRows(): int
	{
		return $this->affected_rows;
	}


	/**
	 * Get the collation list.
	 *
	 * @param string $charset The support charset name
	 *
	 * @return array The collation list
	 * @throws Throwable
	 *
	 */
	public function getCollation(string $charset): array
	{
		$charset = strtolower(trim($charset));

		// Get all supported charset from MySQL
		$this->getCharset();

		if (isset($this->charset[$charset])) {
			/** @var array $collation */
			$collation = &$this->charset[$charset]['collation'];
			if (!count($collation)) {
				$query = $this->prepare('SHOW COLLATION WHERE Charset = \'' . $charset . '\'')->query();
				while ($result = $query->fetch()) {
					$collation[$result['Collation']] = $result['Charset'];
				}
			}

			return $collation;
		}

		return [];
	}

	/**
	 * Get the support charset list.
	 *
	 * @return array The support charset list
	 * @throws Throwable
	 *
	 */
	public function getCharset(): array
	{
		if (!count($this->charset)) {
			$query = $this->prepare('SHOW CHARACTER SET')->query();
			while ($result = $query->fetch()) {
				$this->charset[$result['Charset']] = [
					'default' => $result['Default collation'],
					'collation' => [],
				];
			}
		}

		return $this->charset;
	}

	/**
	 * Get the database adapter resource.
	 *
	 * @return PDO The database adapter resource
	 */
	public function getDBAdapter(): PDO
	{
		return $this->adapter;
	}

	/**
	 * Get the latest executed SQL statement.
	 *
	 * @return string The SQL statement
	 */
	public function getLastQueried(): string
	{
		return end($this->queried);
	}

	/**
	 * Get the name
	 *
	 * @return string
	 */
	public function getName(): string
	{
		return $this->name;
	}

	/**
	 * Get the table prefix
	 *
	 * @return string
	 */
	public function getPrefix(): string
	{
		return $this->prefix;
	}

	/**
	 * Set the table prefix
	 *
	 * @param string $prefix
	 * @return $this
	 */
	public function setPrefix(string $prefix): Database
	{
		$this->prefix = $prefix;
		return $this;
	}

	/**
	 * Get a list of the executed SQL statement.
	 *
	 * @return array An array contains the executed SQL statement
	 */
	public function getQueried(): array
	{
		return $this->queried;
	}

	/**
	 * Create an insert statement.
	 *
	 * @param string $tableName The table name
	 * @param array $dataset An array contains the column name and its value
	 * @param array $duplicateKeys A set of columns to check the duplicate key
	 *
	 * @return Statement The Statement object
	 * @throws Error
	 */
	public function insert(string $tableName, array $dataset, array $duplicateKeys = []): Statement
	{
		return $this->prepare()->insert($tableName, $dataset, $duplicateKeys);
	}

	/**
	 * Get the Statement.
	 *
	 * @param string $sql
	 *
	 * @return Statement
	 */
	public function prepare(string $sql = ''): Statement
	{
		if ($sql) {
			return new Statement($this, $sql);
		}

		return new Statement($this);
	}

	/**
	 * Get the connection status.
	 *
	 * @return bool
	 */
	public function isConnected(): bool
	{
		return $this->connected;
	}

	/**
	 * Return the last insert id.
	 *
	 * @return int The last insert id
	 */
	public function lastID(): int
	{
		return $this->adapter->lastInsertId();
	}

	/**
	 * Create a update statement.
	 *
	 * @param string $tableName The table name
	 * @param array $updateSyntax An array contains the column name or update syntax
	 *
	 * @return Statement The Statement object
	 * @throws Throwable
	 *
	 */
	public function update(string $tableName, array $updateSyntax): Statement
	{
		return $this->prepare()->update($tableName, $updateSyntax);
	}

	/**
	 * @param string $tableName
	 * @param array $parameters
	 * @param string $whereSyntax
	 * @return Statement
	 * @throws Error
	 */
	public function delete(string $tableName, array $parameters = [], string $whereSyntax = ''): Statement
	{
		return $this->prepare()->delete($tableName, $parameters, $whereSyntax);
	}

	// alias.x:max[binding,value]

	/**
	 * @param string $tableName
	 * @param string $binding
	 * @param string $valueColumn
	 * @param array $extraSelect
	 *
	 * @return Statement
	 * @throws Error
	 */
	public function getMaxStatement(string $tableName, string $binding, string $valueColumn, array $extraSelect = []): Statement
	{
		$tableName = trim($tableName);
		if (!preg_match('^[a-z]\w*$', $tableName)) {
			throw new Error('The table name format is invalid');
		}
		$binding = Statement::StandardizeColumn($binding);
		$valueColumn = Statement::StandardizeColumn($valueColumn);
		if (!$binding) {
			throw new Error('Incorrect format of the binding column.');
		}

		if (!$valueColumn) {
			throw new Error('Incorrect format of the value column.');
		}

		$selectColumn = '';
		if (count($extraSelect)) {
			foreach ($extraSelect as $column) {
				$column = Statement::StandardizeColumn($column);
				if ($column) {
					$selectColumn .= ($selectColumn) ? ', ' . $column : $column;
				}
			}
		}

		if (!$selectColumn) {
			$selectColumn = '*';
		}

		$alias = guid();
		$tableName = guid();
		$statement = $this->prepare()->select($selectColumn)->from('a.' . $tableName . '-' . $alias . '.' . $tableName . '[' . $binding . ']');
		$statement->alias('latest')->select('MAX(' . $valueColumn . ') as ' . $valueColumn . ', ' . $binding)->from($tableName);

		return $statement;
	}
}
