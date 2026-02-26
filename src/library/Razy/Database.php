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

namespace Razy;

use Exception;
use PDO;
use Razy\Database\Driver;
use Razy\Database\Driver\MySQL;
use Razy\Database\Driver\PostgreSQL;
use Razy\Database\Driver\SQLite;
use Razy\Database\Query;
use Razy\Database\Statement;
use Razy\Database\StatementPool;
use Razy\Database\Transaction;
use Razy\Contract\DatabaseInterface;
use Razy\Exception\ConnectionException;
use Razy\Exception\QueryException;
use Razy\Exception\TransactionException;
use Throwable;

use Razy\Util\StringUtil;
/**
 * Class Database
 *
 * Provides a unified database abstraction layer supporting multiple drivers (MySQL,
 * PostgreSQL, SQLite). Manages named connection instances, SQL statement preparation
 * and execution, query history tracking, and table prefix management.
 *
 * @class Database
 * @package Razy
 */
class Database implements DatabaseInterface
{
    /** @var string Driver type constant for MySQL/MariaDB */
    public const DRIVER_MYSQL = 'mysql';
    /** @var string Driver type constant for PostgreSQL */
    public const DRIVER_PGSQL = 'pgsql';
    /** @var string Driver type constant for SQLite */
    public const DRIVER_SQLITE = 'sqlite';
    
    /** @var array<string, Database> Registry of named Database instances */
    private static array $instances = [];

    /** @var array<string, string> Registry of driver type aliases to driver class names */
    private static array $driverRegistry = [
        'mysql'      => MySQL::class,
        'mariadb'    => MySQL::class,
        'pgsql'      => PostgreSQL::class,
        'postgres'   => PostgreSQL::class,
        'postgresql' => PostgreSQL::class,
        'sqlite'     => SQLite::class,
        'sqlite3'    => SQLite::class,
    ];
    /** @var PDO|null The underlying PDO adapter for executing queries */
    private ?PDO $adapter = null;
    /** @var Driver|null The active database driver instance */
    private ?Driver $driver = null;
    /** @var bool Whether a database connection has been established */
    private bool $connected = false;
    /** @var string Table name prefix applied to all table references */
    private string $prefix = '';
    /** @var int Maximum number of SQL statements retained in query history (ring buffer) */
    private const MAX_QUERY_HISTORY = 200;
    /** @var array<string> History of executed SQL statements (ring buffer) */
    private array $queried = [];
    /** @var int Ring buffer write index — total queries recorded since last clear */
    private int $queryIndex = 0;
    /** @var int Number of rows affected by the last query */
    private int $affected_rows = 0;
    /** @var StatementPool|null Prepared statement cache with LRU eviction */
    private ?StatementPool $statementPool = null;
    /** @var Transaction|null Transaction manager for nested transaction support */
    private ?Transaction $transaction = null;

    /**
     * Database constructor.
     *
     * @param string $name
     */
    public function __construct(private string $name = '')
    {
        // Generate a random name if none provided
        $this->name = trim($this->name);
        if (!$this->name) {
            $this->name = 'Database_' . sprintf('%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff));
        }
    }

    /**
     * Get the Database instance by given name.
     *
     * @deprecated Use the DI Container instead: $container->make(Database::class, ['name' => $name])
     *
     * @param string $name
     *
     * @return null|Database
     */
    public static function getInstance(string $name): ?Database
    {
        if (!isset(self::$instances[$name])) {
            self::$instances[$name] = new self($name);
        }

        return self::$instances[$name];
    }

    /**
     * Reset the static instance registry. Used in worker mode between requests.
     */
    public static function resetInstances(): void
    {
        self::$instances = [];
    }
    
    /**
     * Register a custom database driver.
     *
     * Allows third-party code to extend Razy's database support with custom driver implementations.
     * The driver class must extend `Razy\Database\Driver`.
     *
     * @param string $type The driver type identifier (e.g., 'oracle', 'sqlsrv')
     * @param string $className Fully-qualified class name of the Driver subclass
     *
     * @throws Error If the class does not extend Driver
     */
    public static function registerDriver(string $type, string $className): void
    {
        if (!is_subclass_of($className, Driver::class)) {
            throw new ConnectionException("Driver class '{$className}' must extend " . Driver::class);
        }
        self::$driverRegistry[strtolower($type)] = $className;
    }

    /**
     * Create a driver instance by type.
     *
     * Uses the driver registry to resolve type aliases to concrete driver classes.
     * Custom drivers can be registered via `registerDriver()`.
     *
     * @param string $type Driver type: mysql, pgsql, sqlite, or any registered custom type
     * @return Driver
     * @throws Error If the driver type is not registered
     */
    public static function createDriver(string $type): Driver
    {
        $normalizedType = strtolower($type);

        if (!isset(self::$driverRegistry[$normalizedType])) {
            throw new ConnectionException("Unsupported database driver: {$type}");
        }

        $className = self::$driverRegistry[$normalizedType];

        return new $className();
    }

    /**
     * Clear the executed SQL statement history.
     *
     * @return self Chainable
     */
    public function clearQueried(): Database
    {
        $this->queried = [];
        $this->queryIndex = 0;

        return $this;
    }

    /**
     * Clear the prepared statement cache.
     * Should be called at end of worker mode request cycles to free resources.
     *
     * @return self Chainable
     */
    public function clearStatementPool(): Database
    {
        $this->statementPool?->clear();

        return $this;
    }

    /**
     * Start connect to database (legacy MySQL method for backward compatibility).
     *
     * @param string $host
     * @param string $username
     * @param string $password
     * @param string $database
     *
     * @return bool
     * @deprecated Use connectWithDriver() for new code
     */
    public function connect(string $host, string $username, string $password, string $database): bool
    {
        return $this->connectWithDriver(self::DRIVER_MYSQL, [
            'host' => $host,
            'username' => $username,
            'password' => $password,
            'database' => $database,
        ]);
    }
    
    /**
     * Connect to database using a driver
     *
     * @param string $driverType Driver type: mysql, pgsql, sqlite
     * @param array $config Connection configuration
     * @return bool
     */
    public function connectWithDriver(string $driverType, array $config): bool
    {
        try {
            $this->driver = self::createDriver($driverType);
            
            if ($this->driver->connect($config)) {
                $this->adapter = $this->driver->getAdapter();
                $this->statementPool = new StatementPool($this->adapter);
                $this->transaction = new Transaction($this->adapter);
                $this->connected = true;
                return true;
            }
            
            return false;
        } catch (Exception) {
            return false;
        }
    }
    
    /**
     * Get the current database driver
     *
     * @return Driver|null
     */
    public function getDriver(): ?Driver
    {
        return $this->driver;
    }
    
    /**
     * Get the driver type
     *
     * @return string|null
     */
    public function getDriverType(): ?string
    {
        return $this->driver?->getType();
    }

    /**
     * Set the database timezone
     *
     * @param string $timezone
     * @return $this
     * @throws Error If no driver is connected
     */
    public function setTimezone(string $timezone): static
    {
        if (!$this->driver) {
            throw new ConnectionException('Cannot set timezone: no database driver connected.');
        }

        $this->driver->setTimezone($timezone);

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
            // Use statement pool for cached prepare (LRU eviction), fallback to direct prepare
            $pdoStatement = $this->statementPool
                ? $this->statementPool->getOrPrepare($sql)
                : $this->adapter->prepare($sql);
            if ($pdoStatement) {
                $pdoStatement->execute();
                $this->affected_rows = $pdoStatement->rowCount();
            }
        } catch (Exception $e) {
            throw new QueryException($e->getMessage() . "\n" . $sql, 500, $e);
        }

        // Record the executed SQL in the query history (ring buffer)
        $this->recordQuery($sql);

        return new Query($statement, $pdoStatement);
    }

    /**
     * Create a view table by given select statement and table name
     *
     * @param Statement $statement
     * @param string $viewTableName
     * @return bool
     * @throws Error
     * @throws Throwable
     */
    public function createViewTable(Statement $statement, string $viewTableName): bool
    {
        $viewTableName = trim($viewTableName);
        if (!$viewTableName) {
            throw new QueryException('View table name cannot be empty.');
        }

        if ($statement->getType() !== 'select') {
            throw new QueryException('The type of the statement must be a select syntax');
        }

        // Prepend the table prefix and build the CREATE VIEW SQL
        $viewTableName = $this->prefix . $viewTableName;
        $sql = 'CREATE VIEW ' . $viewTableName . ' AS ' . $statement->getSyntax();

        $this->recordQuery($sql);
        try {
            $pdoStatement = $this->adapter->prepare($sql);
            if ($pdoStatement) {
                $pdoStatement->execute();
            }
            return true;
        } catch (Exception $e) {
            throw new QueryException($e->getMessage() . "\n" . $sql, 500, $e);
        }
    }

    /**
     * Check if the table is existing
     *
     * @param string $tableName
     * @return bool
     */
    public function isTableExists(string $tableName): bool {
        $tableName = trim($tableName);
        if (!$tableName) {
            return false;
        }
        // Apply the configured table prefix
        $tableName = $this->prefix . $tableName;

        if (!$this->driver) {
            return false;
        }

        return $this->driver->tableExists($tableName);
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
     * @throws Error If no driver is connected
     */
    public function getCollation(string $charset): array
    {
        if (!$this->driver) {
            throw new ConnectionException('Cannot get collation: no database driver connected.');
        }

        return $this->driver->getCollation($charset);
    }

    /**
     * Get the support charset list.
     *
     * @return array The support charset list
     * @throws Error If no driver is connected
     */
    public function getCharset(): array
    {
        if (!$this->driver) {
            throw new ConnectionException('Cannot get charset: no database driver connected.');
        }

        return $this->driver->getCharset();
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
     * @return string The SQL statement, or empty string if no queries recorded
     */
    public function getLastQueried(): string
    {
        if ($this->queryIndex === 0) {
            return '';
        }
        return $this->queried[($this->queryIndex - 1) % self::MAX_QUERY_HISTORY];
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
     * Get a list of the executed SQL statements (most recent MAX_QUERY_HISTORY).
     *
     * When fewer than MAX_QUERY_HISTORY queries have been recorded, returns them
     * in insertion order. When the ring buffer has wrapped, returns entries
     * from oldest to newest.
     *
     * @return array An array of executed SQL statements in chronological order
     */
    public function getQueried(): array
    {
        if ($this->queryIndex <= self::MAX_QUERY_HISTORY) {
            return $this->queried;
        }
        // Ring buffer has wrapped — reorder oldest→newest
        $start = $this->queryIndex % self::MAX_QUERY_HISTORY;
        return array_merge(
            array_slice($this->queried, $start),
            array_slice($this->queried, 0, $start)
        );
    }

    /**
     * Get the total number of queries executed since last clear.
     *
     * Unlike count(getQueried()) which is capped at MAX_QUERY_HISTORY,
     * this returns the true total.
     *
     * @return int
     */
    public function getTotalQueryCount(): int
    {
        return $this->queryIndex;
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
     * Build a SELECT statement that retrieves the row with the maximum value
     * for a given column, grouped by a binding column. Uses a self-join with
     * a subquery alias to find the maximum value per group.
     *
     * @param string $tableName The table name
     * @param string $binding The column to group by
     * @param string $valueColumn The column to find the MAX of
     * @param array $extraSelect Additional columns to include in the SELECT
     *
     * @return Statement
     * @throws Error
     */
    public function getMaxStatement(string $tableName, string $binding, string $valueColumn, array $extraSelect = []): Statement
    {
        $tableName = trim($tableName);
        if (!preg_match('^[a-z]\w*$', $tableName)) {
            throw new QueryException('The table name format is invalid');
        }
        $binding = Statement::standardizeColumn($binding);
        $valueColumn = Statement::standardizeColumn($valueColumn);
        if (!$binding) {
            throw new QueryException('Incorrect format of the binding column.');
        }

        if (!$valueColumn) {
            throw new QueryException('Incorrect format of the value column.');
        }

        $selectColumn = '';
        if (count($extraSelect)) {
            foreach ($extraSelect as $column) {
                $column = Statement::standardizeColumn($column);
                if ($column) {
                    $selectColumn .= ($selectColumn) ? ', ' . $column : $column;
                }
            }
        }

        if (!$selectColumn) {
            $selectColumn = '*';
        }

        // Build the self-join: main table joined with a subquery that provides MAX values per binding group
        $alias = StringUtil::guid();
        $tableName = StringUtil::guid();
        $statement = $this->prepare()->select($selectColumn)->from('a.' . $tableName . '-' . $alias . '.' . $tableName . '[' . $binding . ']');
        $statement->alias('latest')->select('MAX(' . $valueColumn . ') as ' . $valueColumn . ', ' . $binding)->from($tableName);

        return $statement;
    }

    /**
     * Begin a database transaction.
     *
     * Supports nesting: the first call uses PDO::beginTransaction(),
     * subsequent calls create savepoints automatically.
     *
     * @throws TransactionException If no connection or PDO begin fails
     */
    public function beginTransaction(): void
    {
        $this->ensureTransaction();
        $this->transaction->begin();
    }

    /**
     * Commit the current transaction level.
     *
     * If nested, releases the current savepoint. If outermost, commits the PDO transaction.
     *
     * @throws TransactionException If no active transaction
     */
    public function commit(): void
    {
        $this->ensureTransaction();
        $this->transaction->commit();
    }

    /**
     * Rollback the current transaction level.
     *
     * If nested, rolls back to the current savepoint. If outermost, rolls back the PDO transaction.
     *
     * @throws TransactionException If no active transaction
     */
    public function rollback(): void
    {
        $this->ensureTransaction();
        $this->transaction->rollback();
    }

    /**
     * Check if a transaction is currently active.
     *
     * @return bool True if a transaction is in progress
     */
    public function inTransaction(): bool
    {
        return $this->transaction?->active() ?? false;
    }

    /**
     * Execute a callback within a transaction.
     *
     * Automatically commits on success, rolls back on exception.
     * Supports nesting — inner calls create savepoints.
     *
     * @template T
     * @param callable(Database): T $callback Receives this Database instance
     * @return T The callback's return value
     * @throws Throwable Re-throws after rollback
     */
    public function transaction(callable $callback): mixed
    {
        $this->ensureTransaction();

        $this->transaction->begin();

        try {
            $result = $callback($this);
            $this->transaction->commit();

            return $result;
        } catch (Throwable $e) {
            $this->transaction->rollback();

            throw $e;
        }
    }

    /**
     * Get the current transaction nesting level.
     *
     * @return int 0 when no transaction is active
     */
    public function getTransactionLevel(): int
    {
        return $this->transaction?->level() ?? 0;
    }

    /**
     * Get the Transaction manager instance.
     *
     * @return Transaction|null
     */
    public function getTransaction(): ?Transaction
    {
        return $this->transaction;
    }

    /**
     * Ensure the transaction manager is available.
     *
     * @throws TransactionException If no database connection established
     */
    private function ensureTransaction(): void
    {
        if (!$this->transaction) {
            throw new TransactionException('Cannot manage transactions: no database connection established.');
        }
    }

    /**
     * Record an executed SQL statement in the ring buffer.
     *
     * Overwrites the oldest entry when MAX_QUERY_HISTORY is exceeded,
     * preventing unbounded memory growth in long-running Worker Mode processes.
     *
     * @param string $sql The SQL statement to record
     */
    private function recordQuery(string $sql): void
    {
        $this->queried[$this->queryIndex % self::MAX_QUERY_HISTORY] = $sql;
        $this->queryIndex++;
    }
}
