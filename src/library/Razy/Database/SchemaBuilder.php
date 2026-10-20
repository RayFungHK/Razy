<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Schema builder facade for database migrations.
 *
 *
 * @license MIT
 */

namespace Razy\Database;

use Closure;
use Razy\Database;
use Razy\Database\Table\TableHelper;

/**
 * Facade for schema operations used in database migrations.
 *
 * Wraps the Database instance to provide a clean API for creating, altering,
 * dropping, and renaming tables. Leverages the existing Table and TableHelper
 * classes for DDL generation, while providing escape hatches via raw().
 *
 * Usage in a migration:
 *   $schema->create('users', function (Table $table) {
 *       $table->addColumn('id=type(int),auto');
 *       $table->addColumn('name=type(varchar),length(100)');
 *   });
 *
 *   $schema->table('users', function (TableHelper $helper) {
 *       $helper->addColumn(new Column('email', 'type(varchar),length(255)'));
 *   });
 *
 *   $schema->drop('temp_table');
 *   $schema->raw('CREATE INDEX idx_name ON users (name)');
 */
class SchemaBuilder
{
    /**
     * SchemaBuilder constructor.
     *
     * @param Database $database The connected database instance
     */
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Create a new table using a callback that configures the Table instance.
     *
     * The callback receives a Table instance for adding columns and defining
     * the table structure. After the callback executes, the CREATE TABLE SQL
     * is generated and executed.
     *
     * @param string $tableName The table name (prefix is auto-applied)
     * @param Closure $callback Receives Table instance for configuration
     */
    public function create(string $tableName, Closure $callback): void
    {
        $table = new Table($this->database->getPrefix() . $tableName);
        $callback($table);
        $this->run($table->getStatements($this->driverType()));
    }

    /**
     * Alter an existing table using a callback that configures a TableHelper.
     *
     * The callback receives a TableHelper instance for adding, modifying,
     * dropping columns, indexes, foreign keys, etc. After the callback,
     * the ALTER TABLE SQL is generated and executed.
     *
     * @param string $tableName The table name to alter (prefix is auto-applied)
     * @param Closure $callback Receives TableHelper instance for configuration
     */
    public function table(string $tableName, Closure $callback): void
    {
        $table = new Table($this->database->getPrefix() . $tableName);
        $helper = $table->createHelper();
        $callback($helper);
        $this->run($helper->getStatements($this->driverType()));
    }

    /**
     * Drop a table.
     *
     * @param string $tableName The table name to drop
     */
    public function drop(string $tableName): void
    {
        $this->database->execute(
            $this->database->prepare('DROP TABLE ' . $this->database->getDriver()->quoteIdentifier($this->database->getPrefix() . $tableName)),
        );
    }

    /**
     * Drop a table if it exists.
     *
     * @param string $tableName The table name to drop
     */
    public function dropIfExists(string $tableName): void
    {
        $this->database->execute(
            $this->database->prepare('DROP TABLE IF EXISTS ' . $this->database->getDriver()->quoteIdentifier($this->database->getPrefix() . $tableName)),
        );
    }

    /**
     * Rename a table.
     *
     * @param string $from Current table name
     * @param string $to New table name
     */
    public function rename(string $from, string $to): void
    {
        $prefix = $this->database->getPrefix();
        $driver = $this->database->getDriver();
        $this->database->execute(
            $this->database->prepare(
                'ALTER TABLE ' . $driver->quoteIdentifier($prefix . $from) . ' RENAME TO ' . $driver->quoteIdentifier($prefix . $to),
            ),
        );
    }

    /**
     * Check if a table exists.
     *
     * Note: This delegates to Database::isTableExists() which applies
     * the configured table prefix automatically.
     *
     * @param string $tableName The table name (without prefix)
     *
     * @return bool True if the table exists
     */
    public function hasTable(string $tableName): bool
    {
        return $this->database->isTableExists($tableName);
    }

    /**
     * Execute a raw SQL statement.
     *
     * Use this for driver-specific DDL, complex ALTER statements,
     * or any SQL that the Table/TableHelper classes cannot generate.
     *
     * @param string $sql The raw SQL to execute
     *
     * @return Query The query result
     */
    public function raw(string $sql): Query
    {
        return $this->database->execute($this->database->prepare($sql));
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
     * RZ-07: the connection's driver picks the DDL grammar (pgsql → PostgresGrammar).
     */
    private function driverType(): string
    {
        return $this->database->getDriverType() ?? Database::DRIVER_MYSQL;
    }

    /**
     * Run DDL statements in order. Postgres DDL is transactional, so a
     * multi-statement change there applies all-or-nothing (unless the caller
     * already holds a transaction, which then owns the outcome).
     *
     * @param list<string> $statements
     */
    private function run(array $statements): void
    {
        $atomic = \count($statements) > 1
            && $this->driverType() === Database::DRIVER_PGSQL
            && !$this->database->inTransaction();
        $apply = function () use ($statements): void {
            foreach ($statements as $sql) {
                $this->database->execute($this->database->prepare($sql));
            }
        };
        $atomic ? $this->database->transaction($apply) : $apply();
    }
}
