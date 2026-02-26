<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Database;

use PDO;

/**
 * Abstract Database Driver.
 *
 * Base class for all database drivers providing a common interface for
 * database-specific operations. Concrete implementations exist for
 * MySQL, PostgreSQL, and SQLite.
 *
 *
 * @license MIT
 */
abstract class Driver
{
    /** @var PDO|null The PDO connection adapter instance */
    protected ?PDO $adapter = null;

    /** @var bool Whether a database connection has been established */
    protected bool $connected = false;

    /** @var array Cached character set information keyed by charset name */
    protected array $charset = [];

    /**
     * Get the driver type identifier.
     *
     * @return string Driver type: 'mysql', 'pgsql', or 'sqlite'
     */
    abstract public function getType(): string;

    /**
     * Create a PDO connection.
     *
     * @param array $config Connection configuration
     *
     * @return bool True if connection successful
     */
    abstract public function connect(array $config): bool;

    /**
     * Get PDO connection options.
     *
     * @return array PDO options array
     */
    abstract public function getConnectionOptions(): array;

    /**
     * Check if a table exists.
     *
     * @param string $tableName Table name to check
     *
     * @return bool True if table exists
     */
    abstract public function tableExists(string $tableName): bool;

    /**
     * Get supported character sets.
     *
     * @return array Character set list
     */
    abstract public function getCharset(): array;

    /**
     * Get collations for a character set.
     *
     * @param string $charset Character set name
     *
     * @return array Collation list
     */
    abstract public function getCollation(string $charset): array;

    /**
     * Set database timezone.
     *
     * @param string $timezone Timezone string
     */
    abstract public function setTimezone(string $timezone): void;

    /**
     * Generate LIMIT clause syntax.
     *
     * @param int $position Starting position
     * @param int $length Number of rows
     *
     * @return string LIMIT clause
     */
    abstract public function getLimitSyntax(int $position, int $length): string;

    /**
     * Generate auto-increment column syntax.
     *
     * @param int $length Column length
     *
     * @return string Auto-increment syntax
     */
    abstract public function getAutoIncrementSyntax(int $length): string;

    /**
     * Generate upsert (insert or update) syntax.
     *
     * @param string $tableName Table name
     * @param array $columns Column names
     * @param array $duplicateKeys Columns to check for duplicates
     * @param callable $valueGetter Function to get column value as SQL
     *
     * @return string Upsert SQL
     */
    abstract public function getUpsertSyntax(string $tableName, array $columns, array $duplicateKeys, callable $valueGetter): string;

    /**
     * Generate string concatenation syntax.
     *
     * @param array $parts Parts to concatenate
     *
     * @return string Concatenation syntax
     */
    abstract public function getConcatSyntax(array $parts): string;

    /**
     * Get the PDO adapter.
     *
     * @return PDO|null
     */
    public function getAdapter(): ?PDO
    {
        return $this->adapter;
    }

    /**
     * Check if connected.
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * Quote an identifier (table/column name).
     *
     * @param string $identifier
     *
     * @return string
     */
    public function quoteIdentifier(string $identifier): string
    {
        return '`' . \str_replace('`', '``', $identifier) . '`';
    }

    /**
     * Get the last insert ID.
     *
     * @return int
     */
    public function lastInsertId(): int
    {
        return (int) $this->adapter?->lastInsertId();
    }
}
