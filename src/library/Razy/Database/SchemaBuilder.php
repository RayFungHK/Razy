<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Schema builder facade for database migrations.
 *
 * @package Razy
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
     * @param string $tableName The table name (prefix is NOT auto-applied)
     * @param Closure $callback Receives Table instance for configuration
     */
    public function create(string $tableName, Closure $callback): void
    {
        $table = new Table($tableName);
        $callback($table);
        $sql = $table->getSyntax();
        $this->database->execute($this->database->prepare($sql));
    }

    /**
     * Alter an existing table using a callback that configures a TableHelper.
     *
     * The callback receives a TableHelper instance for adding, modifying,
     * dropping columns, indexes, foreign keys, etc. After the callback,
     * the ALTER TABLE SQL is generated and executed.
     *
     * @param string $tableName The table name to alter
     * @param Closure $callback Receives TableHelper instance for configuration
     */
    public function table(string $tableName, Closure $callback): void
    {
        $table = new Table($tableName);
        $helper = $table->createHelper();
        $callback($helper);
        $sql = $helper->getSyntax();
        if ($sql) {
            $this->database->execute($this->database->prepare($sql));
        }
    }

    /**
     * Drop a table.
     *
     * @param string $tableName The table name to drop
     */
    public function drop(string $tableName): void
    {
        $this->database->execute(
            $this->database->prepare('DROP TABLE `' . \addslashes($tableName) . '`'),
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
            $this->database->prepare('DROP TABLE IF EXISTS `' . \addslashes($tableName) . '`'),
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
        $this->database->execute(
            $this->database->prepare(
                'ALTER TABLE `' . \addslashes($from) . '` RENAME TO `' . \addslashes($to) . '`',
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
}
