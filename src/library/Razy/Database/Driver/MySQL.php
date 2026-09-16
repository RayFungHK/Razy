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
 * MySQL Database Driver.
 *
 * Provides MySQL-specific database operations and SQL syntax generation,
 * including CONCAT(), LIMIT offset syntax, AUTO_INCREMENT, and
 * ON DUPLICATE KEY UPDATE for upsert operations.
 *
 *
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
        $this->lastConnectConfig = $config;

        try {
            $this->adapter = new PDO($this->buildDsn($config), $config['username'] ?? '', $config['password'] ?? '', $this->getConnectionOptions());
            $this->connected = true;

            return true;
        } catch (PDOException) {
            $this->connected = false;

            return false;
        }
    }

    /**
     * A persistent connection idle past wait_timeout dies server-side while the
     * pool hands the dead handle back — every later query dies with MySQL 2006
     * ("gone away") and nothing in the stack could recover (a worker process
     * outlives the idle link; live-caught by the benchmark worker 2026-09).
     * Ping honestly; on death, force a FRESH non-persistent link for this
     * request. PDO's pool may hand back another dead persistent link if we
     * asked for persistent again — one-shot fresh is the recovery that works.
     *
     * Callers hold only this adapter object, so the swap is transparent.
     */
    public function reconnect(): bool
    {
        try {
            $this->adapter = new PDO($this->buildDsn($this->lastConnectConfig), $this->lastConnectConfig['username'] ?? '', $this->lastConnectConfig['password'] ?? '', $this->getReconnectOptions());
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
            // Report matched rows instead of changed rows for UPDATE operations.
            // 8.5 deprecates PDO::MYSQL_ATTR_*; the replacement class \Pdo\Mysql
            // is a PHP **8.4** addition (verified: absent from a 8.3.1 that has
            // pdo_mysql loaded) AND extension-registered — so with the ^8.2
            // floor, or with the driver missing, this array must evaluate on
            // the legacy constant (PDO-core-registered, same value). The
            // class_exists picks the clean spelling exactly where it exists,
            // and constant deprecations only fire on executed branches, so
            // 8.4+/driver-present installs never touch the deprecated one.
            (\class_exists(\Pdo\Mysql::class, false) ? \Pdo\Mysql::ATTR_FOUND_ROWS : PDO::MYSQL_ATTR_FOUND_ROWS) => true,
        ];
    }

    /**
     * @inheritDoc
     */
    public function tableExists(string $tableName): bool
    {
        $stmt = $this->adapter->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$tableName]);
        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * @inheritDoc
     */
    public function getCharset(): array
    {
        // Lazy-load charset list from the server on first call
        if (!\count($this->charset)) {
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
        $charset = \strtolower(\trim($charset));
        $this->getCharset();

        if (isset($this->charset[$charset])) {
            $collation = &$this->charset[$charset]['collation'];
            if (!\count($collation)) {
                $stmt = $this->adapter->prepare('SHOW COLLATION WHERE Charset = ?');
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
        if (\preg_match('/^[+-]\d{1,2}:\d{2}$/', $timezone)) {
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
        $sql = 'INSERT INTO ' . $tableName . ' (`' . \implode('`, `', $columns) . '`) VALUES (';
        $values = [];
        foreach ($columns as $column) {
            $values[] = $valueGetter($column);
        }
        $sql .= \implode(', ', $values) . ')';

        // Append ON DUPLICATE KEY UPDATE for MySQL-specific upsert behavior
        if (\count($duplicateKeys)) {
            $updates = [];
            foreach ($duplicateKeys as $column) {
                if (\is_string($column)) {
                    $updates[] = '`' . $column . '` = ' . $valueGetter($column);
                }
            }
            if (\count($updates)) {
                $sql .= ' ON DUPLICATE KEY UPDATE ' . \implode(', ', $updates);
            }
        }

        return $sql;
    }

    /**
     * @inheritDoc
     */
    public function getConcatSyntax(array $parts): string
    {
        return 'CONCAT(' . \implode(', ', $parts) . ')';
    }

    /**
     * @inheritDoc
     */
    public function quoteIdentifier(string $identifier): string
    {
        // MySQL uses backticks for identifiers; escape by doubling existing backticks
        return '`' . \str_replace('`', '``', $identifier) . '`';
    }

    /**
     * Build the DSN from a connect config array.
     *
     * @param array $config
     */
    private function buildDsn(array $config): string
    {
        $host = $config['host'] ?? 'localhost';
        $database = $config['database'] ?? '';
        $port = $config['port'] ?? 3306;
        $charset = $config['charset'] ?? 'UTF8';

        return "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
    }

    /**
     * Reconnect options: the getConnectionOptions set with persistence OFF.
     */
    private function getReconnectOptions(): array
    {
        $options = $this->getConnectionOptions();
        $options[PDO::ATTR_PERSISTENT] = false;

        return $options;
    }
}
