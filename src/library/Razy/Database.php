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
     * The PDO entity
     * @var PDO
     */
    private PDO $adapter;
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
     * The name of the database entity
     * @var string
     */
    private string $name;
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

    /**
     * Database constructor.
     *
     * @param string $name
     */
    public function __construct(string $name = '')
    {
        $name = trim($name);
        if (!$name) {
            $name = 'Database_' . sprintf('%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff));
        }
        $this->name             = $name;
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
            $this->adapter    = new PDO($connectionString, $username, $password, [
                PDO::ATTR_PERSISTENT => true,
                PDO::ATTR_TIMEOUT    => 5,
                PDO::ATTR_ERRMODE    => PDO::ERRMODE_EXCEPTION,
            ]);

            $this->connected = true;

            return true;
        } catch (PDOException $e) {
            return false;
        }
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
            }
        } catch (Exception $e) {
            throw new Error($e->getMessage(), 500, Error::DEFAULT_HEADING, $sql);
        }

        $this->queried[] = $sql;

        return new Query($statement, $pdoStatement);
    }

    /**
     * Get the collation list.
     *
     * @param string $charset The support charset name
     *
     * @throws Throwable
     *
     * @return array The collation list
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
     * @throws Throwable
     *
     * @return array The support charset list
     */
    public function getCharset(): array
    {
        if (!count($this->charset)) {
            $query = $this->prepare('SHOW CHARACTER SET')->query();
            while ($result = $query->fetch()) {
                $this->charset[$result['Charset']] = [
                    'default'   => $result['Default collation'],
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
     * @param array  $dataset   An array contains the column name and its value
     *
     * @return Statement The Statement object
     * @throws Error
     */
    public function insert(string $tableName, array $dataset): Statement
    {
        return $this->prepare()->insert($tableName, $dataset);
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
     * @param string $tableName    The table name
     * @param array  $updateSyntax An array contains the column name or update syntax
     *
     * @throws Throwable
     *
     * @return Statement The Statement object
     */
    public function update(string $tableName, array $updateSyntax): Statement
    {
        return $this->prepare()->update($tableName, $updateSyntax);
    }

    // alias.x:max[binding,value]

    /**
     * @param string $tableName
     * @param string $binding
     * @param string $valueColumn
     * @param array  $extraSelect
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

        $alias = guid(4);
        $tableName = guid(4);
        $statement = $this->prepare()->select($selectColumn)->from('a.' . $tableName . '-' . $alias . '.' . $tableName . '[' . $binding . ']');
        $statement->alias('latest')->select('MAX(' . $valueColumn . ') as ' . $valueColumn . ', ' . $binding)->from($tableName);

        return $statement;
    }
}
