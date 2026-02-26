<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Database\Driver;

use PDO;
use PDOException;
use Razy\Database\Driver;

/**
 * PostgreSQL Database Driver.
 *
 * Provides PostgreSQL-specific database operations and SQL syntax generation,
 * including || concatenation, LIMIT/OFFSET syntax, SERIAL/BIGSERIAL columns,
 * and ON CONFLICT ... DO UPDATE for upsert operations.
 *
 * @package Razy
 *
 * @license MIT
 */
class PostgreSQL extends Driver
{
    /**
     * @inheritDoc
     */
    public function getType(): string
    {
        return 'pgsql';
    }

    /**
     * @inheritDoc
     */
    public function connect(array $config): bool
    {
        try {
            $host = $config['host'] ?? 'localhost';
            $database = $config['database'] ?? '';
            $username = $config['username'] ?? '';
            $password = $config['password'] ?? '';
            $port = $config['port'] ?? 5432;

            $dsn = "pgsql:host={$host};port={$port};dbname={$database}";

            $this->adapter = new PDO($dsn, $username, $password, $this->getConnectionOptions());
            $this->connected = true;

            // Set client encoding
            $charset = $config['charset'] ?? 'UTF8';
            $this->adapter->exec("SET client_encoding TO '{$charset}'");

            return true;
        } catch (PDOException) {
            $this->connected = false;
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function getConnectionOptions(): array
    {
        return [
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ];
    }

    /**
     * @inheritDoc
     */
    public function tableExists(string $tableName): bool
    {
        $stmt = $this->adapter->prepare(
            "SELECT EXISTS (
                SELECT FROM information_schema.tables
                WHERE table_schema = 'public'
                AND table_name = ?
            )",
        );
        $stmt->execute([$tableName]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * @inheritDoc
     */
    public function getCharset(): array
    {
        if (!\count($this->charset)) {
            $stmt = $this->adapter->query('SELECT pg_encoding_to_char(encoding) as charset FROM pg_database WHERE datname = current_database()');
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result) {
                $this->charset[$result['charset']] = [
                    'default' => $result['charset'],
                    'collation' => [],
                ];
            }

            // Also include common encodings
            $commonEncodings = ['UTF8', 'LATIN1', 'SQL_ASCII'];
            foreach ($commonEncodings as $encoding) {
                if (!isset($this->charset[$encoding])) {
                    $this->charset[$encoding] = [
                        'default' => $encoding,
                        'collation' => [],
                    ];
                }
            }
        }
        return $this->charset;
    }

    /**
     * @inheritDoc
     */
    public function getCollation(string $charset): array
    {
        $charset = \strtoupper(\trim($charset));
        $this->getCharset();

        if (isset($this->charset[$charset])) {
            $collation = &$this->charset[$charset]['collation'];
            if (!\count($collation)) {
                // collencoding = -1 matches collations valid for any encoding
                $stmt = $this->adapter->query(
                    "SELECT collname FROM pg_collation WHERE collencoding = -1 OR collencoding = pg_char_to_encoding('{$charset}')",
                );
                while ($result = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $collation[$result['collname']] = $charset;
                }
            }
            return $collation;
        }
        return [];
    }

    /**
     * @inheritDoc
     */
    public function setTimezone(string $timezone): void
    {
        // PostgreSQL accepts both offset format and named timezones
        $this->adapter->exec("SET TIME ZONE '{$timezone}'");
    }

    /**
     * @inheritDoc
     */
    public function getLimitSyntax(int $position, int $length): string
    {
        // PostgreSQL uses LIMIT count OFFSET position (not MySQL's comma-separated syntax)
        if ($length === 0 && $position > 0) {
            return ' LIMIT ' . $position;
        }
        if ($length > 0) {
            return ' LIMIT ' . $length . ' OFFSET ' . $position;
        }
        return '';
    }

    /**
     * @inheritDoc
     */
    public function getAutoIncrementSyntax(int $length): string
    {
        // PostgreSQL 10+ supports GENERATED AS IDENTITY
        // For broader compatibility, use SERIAL
        if ($length <= 4) {
            return 'SERIAL PRIMARY KEY';
        }
        return 'BIGSERIAL PRIMARY KEY';
    }

    /**
     * @inheritDoc
     */
    public function getUpsertSyntax(string $tableName, array $columns, array $duplicateKeys, callable $valueGetter): string
    {
        $quotedColumns = \array_map(fn ($c) => '"' . $c . '"', $columns);
        $sql = 'INSERT INTO ' . $tableName . ' (' . \implode(', ', $quotedColumns) . ') VALUES (';
        $values = [];
        foreach ($columns as $column) {
            $values[] = $valueGetter($column);
        }
        $sql .= \implode(', ', $values) . ')';

        if (\count($duplicateKeys)) {
            // PostgreSQL uses ON CONFLICT ... DO UPDATE with the EXCLUDED pseudo-table
            // referencing the row that would have been inserted
            $conflictColumns = \array_map(fn ($c) => '"' . $c . '"', $duplicateKeys);
            $updates = [];
            foreach ($duplicateKeys as $column) {
                if (\is_string($column)) {
                    $updates[] = '"' . $column . '" = EXCLUDED."' . $column . '"';
                }
            }
            if (\count($updates)) {
                // Assume first column is the conflict target (usually primary key)
                $sql .= ' ON CONFLICT (' . $conflictColumns[0] . ') DO UPDATE SET ' . \implode(', ', $updates);
            }
        }

        return $sql;
    }

    /**
     * @inheritDoc
     */
    public function getConcatSyntax(array $parts): string
    {
        // PostgreSQL uses || for concatenation
        return '(' . \implode(' || ', $parts) . ')';
    }

    /**
     * @inheritDoc
     */
    public function quoteIdentifier(string $identifier): string
    {
        // PostgreSQL uses standard SQL double-quotes; escape by doubling existing quotes
        return '"' . \str_replace('"', '""', $identifier) . '"';
    }
}
