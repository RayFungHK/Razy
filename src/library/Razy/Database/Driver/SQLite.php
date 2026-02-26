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
 * SQLite Database Driver
 * 
 * Provides SQLite-specific database operations and SQL syntax generation,
 * including || concatenation, LIMIT/OFFSET syntax, INTEGER PRIMARY KEY
 * AUTOINCREMENT columns, and ON CONFLICT upsert support (SQLite 3.24+).
 *
 * @package Razy
 * @license MIT
 */
class SQLite extends Driver
{
    /** @var string Path to the SQLite database file, or ':memory:' for in-memory databases */
    private string $databasePath = '';
    
    /**
     * @inheritDoc
     */
    public function getType(): string
    {
        return 'sqlite';
    }
    
    /**
     * @inheritDoc
     */
    public function connect(array $config): bool
    {
        try {
            // SQLite uses a file path instead of host/database
            $this->databasePath = $config['database'] ?? $config['path'] ?? ':memory:';
            
            $dsn = "sqlite:{$this->databasePath}";
            
            $this->adapter = new PDO($dsn, null, null, $this->getConnectionOptions());
            $this->connected = true;
            
            // Enable foreign keys (disabled by default in SQLite)
            $this->adapter->exec('PRAGMA foreign_keys = ON');
            
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
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
    }
    
    /**
     * @inheritDoc
     */
    public function tableExists(string $tableName): bool
    {
        // sqlite_master is the system catalog table storing schema for all database objects
        $stmt = $this->adapter->prepare(
            "SELECT name FROM sqlite_master WHERE type='table' AND name = ?"
        );
        $stmt->execute([$tableName]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * @inheritDoc
     */
    public function getCharset(): array
    {
        // SQLite always uses UTF-8 internally
        if (!count($this->charset)) {
            $this->charset = [
                'UTF-8' => [
                    'default' => 'UTF-8',
                    'collation' => ['BINARY', 'NOCASE', 'RTRIM'],
                ],
            ];
        }
        return $this->charset;
    }
    
    /**
     * @inheritDoc
     */
    public function getCollation(string $charset): array
    {
        // SQLite has limited collation support: BINARY, NOCASE, RTRIM
        return ['BINARY', 'NOCASE', 'RTRIM'];
    }
    
    /**
     * @inheritDoc
     */
    public function setTimezone(string $timezone): void
    {
        // SQLite doesn't support timezone settings directly
        // Datetime functions use UTC by default
        // Store timezone offset for application-level handling
    }
    
    /**
     * @inheritDoc
     */
    public function getLimitSyntax(int $position, int $length): string
    {
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
        // SQLite uses INTEGER PRIMARY KEY for auto-increment
        // AUTOINCREMENT keyword is optional and prevents ID reuse
        return 'INTEGER PRIMARY KEY AUTOINCREMENT';
    }
    
    /**
     * @inheritDoc
     */
    public function getUpsertSyntax(string $tableName, array $columns, array $duplicateKeys, callable $valueGetter): string
    {
        $quotedColumns = array_map(fn($c) => '"' . $c . '"', $columns);
        
        if (count($duplicateKeys)) {
            // SQLite 3.24+ supports ON CONFLICT ... DO UPDATE (UPSERT)
            $sql = 'INSERT INTO ' . $tableName . ' (' . implode(', ', $quotedColumns) . ') VALUES (';
            $values = [];
            foreach ($columns as $column) {
                $values[] = $valueGetter($column);
            }
            $sql .= implode(', ', $values) . ')';
            
            $conflictColumns = array_map(fn($c) => '"' . $c . '"', $duplicateKeys);
            // Build SET clause for non-conflict columns; 'excluded' pseudo-table references the proposed row
            $updates = [];
            foreach ($columns as $column) {
                if (!in_array($column, $duplicateKeys)) {
                    $updates[] = '"' . $column . '" = excluded."' . $column . '"';
                }
            }
            
            if (count($updates)) {
                $sql .= ' ON CONFLICT(' . implode(', ', $conflictColumns) . ') DO UPDATE SET ' . implode(', ', $updates);
            } else {
                $sql .= ' ON CONFLICT(' . implode(', ', $conflictColumns) . ') DO NOTHING';
            }
            
            return $sql;
        }
        
        // Simple insert without upsert
        $sql = 'INSERT INTO ' . $tableName . ' (' . implode(', ', $quotedColumns) . ') VALUES (';
        $values = [];
        foreach ($columns as $column) {
            $values[] = $valueGetter($column);
        }
        $sql .= implode(', ', $values) . ')';
        
        return $sql;
    }
    
    /**
     * @inheritDoc
     */
    public function getConcatSyntax(array $parts): string
    {
        // SQLite uses || for concatenation
        return '(' . implode(' || ', $parts) . ')';
    }
    
    /**
     * @inheritDoc
     */
    public function quoteIdentifier(string $identifier): string
    {
        // SQLite uses standard SQL double-quotes for identifiers; escape by doubling
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
    
    /**
     * Get the database file path
     *
     * @return string
     */
    public function getDatabasePath(): string
    {
        return $this->databasePath;
    }
}
