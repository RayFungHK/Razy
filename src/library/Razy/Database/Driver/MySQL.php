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
 * MySQL Database Driver
 * 
 * Provides MySQL-specific database operations and SQL syntax generation,
 * including CONCAT(), LIMIT offset syntax, AUTO_INCREMENT, and
 * ON DUPLICATE KEY UPDATE for upsert operations.
 *
 * @package Razy
 * @license MIT
 */
class MySQL extends Driver
{
    /**
     * @inheritDoc
     */
    public function getType(): string
    {
        return 'mysql';
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
            $port = $config['port'] ?? 3306;
            $charset = $config['charset'] ?? 'UTF8';
            
            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
            
            $this->adapter = new PDO($dsn, $username, $password, $this->getConnectionOptions());
            $this->connected = true;
            
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
            // Report matched rows instead of changed rows for UPDATE operations
            PDO::MYSQL_ATTR_FOUND_ROWS => true,
        ];
    }
    
    /**
     * @inheritDoc
     */
    public function tableExists(string $tableName): bool
    {
        $stmt = $this->adapter->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$tableName]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * @inheritDoc
     */
    public function getCharset(): array
    {
        // Lazy-load charset list from the server on first call
        if (!count($this->charset)) {
            $stmt = $this->adapter->query('SHOW CHARACTER SET');
            while ($result = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->charset[$result['Charset']] = [
                    'default' => $result['Default collation'],
                    'collation' => [],
                ];
            }
        }
        return $this->charset;
    }
    
    /**
     * @inheritDoc
     */
    public function getCollation(string $charset): array
    {
        $charset = strtolower(trim($charset));
        $this->getCharset();
        
        if (isset($this->charset[$charset])) {
            $collation = &$this->charset[$charset]['collation'];
            if (!count($collation)) {
                $stmt = $this->adapter->prepare("SHOW COLLATION WHERE Charset = ?");
                $stmt->execute([$charset]);
                while ($result = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $collation[$result['Collation']] = $result['Charset'];
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
        if (preg_match('/^[+-]\d{1,2}:\d{2}$/', $timezone)) {
            $this->adapter->exec("SET time_zone='{$timezone}'");
        }
    }
    
    /**
     * @inheritDoc
     */
    public function getLimitSyntax(int $position, int $length): string
    {
        // MySQL LIMIT syntax: LIMIT count (single) or LIMIT offset, count (paginated)
        if ($length === 0 && $position > 0) {
            return ' LIMIT ' . $position;
        }
        if ($length > 0) {
            return ' LIMIT ' . $position . ', ' . $length;
        }
        return '';
    }
    
    /**
     * @inheritDoc
     */
    public function getAutoIncrementSyntax(int $length): string
    {
        return "INT({$length}) NOT NULL AUTO_INCREMENT";
    }
    
    /**
     * @inheritDoc
     */
    public function getUpsertSyntax(string $tableName, array $columns, array $duplicateKeys, callable $valueGetter): string
    {
        $sql = 'INSERT INTO ' . $tableName . ' (`' . implode('`, `', $columns) . '`) VALUES (';
        $values = [];
        foreach ($columns as $column) {
            $values[] = $valueGetter($column);
        }
        $sql .= implode(', ', $values) . ')';
        
        // Append ON DUPLICATE KEY UPDATE for MySQL-specific upsert behavior
        if (count($duplicateKeys)) {
            $updates = [];
            foreach ($duplicateKeys as $column) {
                if (is_string($column)) {
                    $updates[] = '`' . $column . '` = ' . $valueGetter($column);
                }
            }
            if (count($updates)) {
                $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
            }
        }
        
        return $sql;
    }
    
    /**
     * @inheritDoc
     */
    public function getConcatSyntax(array $parts): string
    {
        return 'CONCAT(' . implode(', ', $parts) . ')';
    }
    
    /**
     * @inheritDoc
     */
    public function quoteIdentifier(string $identifier): string
    {
        // MySQL uses backticks for identifiers; escape by doubling existing backticks
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
