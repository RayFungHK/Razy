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
 *
 * @license MIT
 */

namespace Razy\Queue;

use Razy\Database;

/**
 * Database-backed queue store.
 *
 * Persists jobs to a database table using the Razy Database abstraction.
 * Supports MySQL/MariaDB, PostgreSQL, and SQLite with driver-aware DDL.
 *
 * Uses Razy's query builder API (insert/update/delete/select) for all DML
 * and raw DDL only for ensureStorage().
 *
 * @package Razy\Queue
 */
class DatabaseStore implements QueueStoreInterface
{
    /**
     * Default job table name (without prefix).
     */
    public const TABLE_NAME = 'razy_jobs';

    /**
     * @param Database $database The database connection
     * @param string $tableName Custom table name (without prefix)
     */
    public function __construct(
        private readonly Database $database,
        private readonly string $tableName = self::TABLE_NAME,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function push(
        string $queue,
        string $handler,
        array $payload = [],
        int $delay = 0,
        int $maxAttempts = 3,
        int $retryDelay = 0,
        int $priority = 100,
    ): int|string {
        $now = \date('Y-m-d H:i:s');
        $availableAt = $delay > 0
            ? \date('Y-m-d H:i:s', \time() + $delay)
            : $now;

        $this->database->execute(
            $this->database->insert($this->tableName, [
                'queue', 'handler', 'payload', 'status',
                'attempts', 'max_attempts', 'retry_delay',
                'priority', 'available_at', 'created_at',
            ])->assign([
                'queue' => $queue,
                'handler' => $handler,
                'payload' => \json_encode($payload, JSON_THROW_ON_ERROR),
                'status' => JobStatus::Pending->value,
                'attempts' => 0,
                'max_attempts' => $maxAttempts,
                'retry_delay' => $retryDelay,
                'priority' => $priority,
                'available_at' => $availableAt,
                'created_at' => $now,
            ]),
        );

        return (int) $this->database->lastID();
    }

    /**
     * {@inheritdoc}
     */
    public function reserve(string $queue): ?Job
    {
        $now = \date('Y-m-d H:i:s');

        // SELECT next available pending job
        $row = $this->database->prepare()
            ->select('*')
            ->from($this->tableName)
            ->where('queue=:queue,status=:status,available_at<=:available_at')
            ->assign([
                'queue' => $queue,
                'status' => JobStatus::Pending->value,
                'available_at' => $now,
            ])
            ->order('<priority,<created_at')
            ->limit(1)
            ->lazy();

        if (!$row) {
            return null;
        }

        // Atomically mark as reserved
        $this->database->execute(
            $this->database->update($this->tableName, ['status', 'reserved_at', 'attempts++'])
                ->where('id=:id,status=:old_status')
                ->assign([
                    'status' => JobStatus::Reserved->value,
                    'reserved_at' => $now,
                    'id' => $row['id'],
                    'old_status' => JobStatus::Pending->value,
                ]),
        );

        $job = Job::fromArray($row);
        $job->markReserved();
        $job->incrementAttempts();

        return $job;
    }

    /**
     * {@inheritdoc}
     */
    public function complete(int|string $jobId): void
    {
        $this->updateStatus($jobId, JobStatus::Completed);
    }

    /**
     * {@inheritdoc}
     */
    public function release(int|string $jobId, int $retryDelay = 0, string $error = ''): void
    {
        $availableAt = $retryDelay > 0
            ? \date('Y-m-d H:i:s', \time() + $retryDelay)
            : \date('Y-m-d H:i:s');

        $this->database->execute(
            $this->database->update($this->tableName, ['status', 'available_at', 'reserved_at', 'error'])
                ->where('id=:id')
                ->assign([
                    'status' => JobStatus::Pending->value,
                    'available_at' => $availableAt,
                    'reserved_at' => null,
                    'error' => $error,
                    'id' => $jobId,
                ]),
        );
    }

    /**
     * {@inheritdoc}
     */
    public function bury(int|string $jobId, string $error = ''): void
    {
        $this->database->execute(
            $this->database->update($this->tableName, ['status', 'error'])
                ->where('id=:id')
                ->assign([
                    'status' => JobStatus::Buried->value,
                    'error' => $error,
                    'id' => $jobId,
                ]),
        );
    }

    /**
     * {@inheritdoc}
     */
    public function delete(int|string $jobId): void
    {
        $this->database->execute(
            $this->database->delete($this->tableName, ['id' => $jobId]),
        );
    }

    /**
     * {@inheritdoc}
     */
    public function find(int|string $jobId): ?Job
    {
        $row = $this->database->prepare()
            ->select('*')
            ->from($this->tableName)
            ->where('id=:id')
            ->assign(['id' => $jobId])
            ->lazy();

        return $row ? Job::fromArray($row) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function count(string $queue, JobStatus $status): int
    {
        $row = $this->database->prepare()
            ->select('COUNT(*) as cnt')
            ->from($this->tableName)
            ->where('queue=:queue,status=:status')
            ->assign([
                'queue' => $queue,
                'status' => $status->value,
            ])
            ->lazy();

        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * {@inheritdoc}
     */
    public function clear(string $queue): int
    {
        // Count completed + buried first
        $row = $this->database->prepare()
            ->select('COUNT(*) as cnt')
            ->from($this->tableName)
            ->where('queue=:queue,status|=:status')
            ->assign([
                'queue' => $queue,
                'status' => [JobStatus::Completed->value, JobStatus::Buried->value],
            ])
            ->lazy();

        $count = (int) ($row['cnt'] ?? 0);

        if ($count > 0) {
            $this->database->execute(
                $this->database->delete($this->tableName, [
                    'queue' => $queue,
                    'status' => [JobStatus::Completed->value, JobStatus::Buried->value],
                ]),
            );
        }

        return $count;
    }

    /**
     * {@inheritdoc}
     */
    public function ensureStorage(): void
    {
        $table = $this->database->getPrefix() . $this->tableName;
        $driverType = $this->database->getDriverType() ?? 'sqlite';

        $this->database->clearStatementPool();

        $sql = match ($driverType) {
            'mysql', 'mariadb' => "CREATE TABLE IF NOT EXISTS `{$table}` ("
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
                . '`queue` VARCHAR(255) NOT NULL DEFAULT \'default\', '
                . '`handler` VARCHAR(500) NOT NULL, '
                . '`payload` LONGTEXT NOT NULL, '
                . '`status` VARCHAR(20) NOT NULL DEFAULT \'pending\', '
                . '`attempts` INT UNSIGNED NOT NULL DEFAULT 0, '
                . '`max_attempts` INT UNSIGNED NOT NULL DEFAULT 3, '
                . '`retry_delay` INT UNSIGNED NOT NULL DEFAULT 0, '
                . '`priority` INT UNSIGNED NOT NULL DEFAULT 100, '
                . '`available_at` DATETIME NOT NULL, '
                . '`reserved_at` DATETIME NULL, '
                . '`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
                . '`error` TEXT NULL, '
                . 'INDEX `idx_queue_status_available` (`queue`, `status`, `available_at`), '
                . 'INDEX `idx_status` (`status`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'pgsql' => "CREATE TABLE IF NOT EXISTS \"{$table}\" ("
                . '"id" BIGSERIAL PRIMARY KEY, '
                . '"queue" VARCHAR(255) NOT NULL DEFAULT \'default\', '
                . '"handler" VARCHAR(500) NOT NULL, '
                . '"payload" TEXT NOT NULL, '
                . '"status" VARCHAR(20) NOT NULL DEFAULT \'pending\', '
                . '"attempts" INTEGER NOT NULL DEFAULT 0, '
                . '"max_attempts" INTEGER NOT NULL DEFAULT 3, '
                . '"retry_delay" INTEGER NOT NULL DEFAULT 0, '
                . '"priority" INTEGER NOT NULL DEFAULT 100, '
                . '"available_at" TIMESTAMP NOT NULL, '
                . '"reserved_at" TIMESTAMP NULL, '
                . '"created_at" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, '
                . '"error" TEXT NULL'
                . ')',
            default => "CREATE TABLE IF NOT EXISTS \"{$table}\" ("
                . '"id" INTEGER PRIMARY KEY AUTOINCREMENT, '
                . '"queue" VARCHAR(255) NOT NULL DEFAULT \'default\', '
                . '"handler" VARCHAR(500) NOT NULL, '
                . '"payload" TEXT NOT NULL, '
                . '"status" VARCHAR(20) NOT NULL DEFAULT \'pending\', '
                . '"attempts" INTEGER NOT NULL DEFAULT 0, '
                . '"max_attempts" INTEGER NOT NULL DEFAULT 3, '
                . '"retry_delay" INTEGER NOT NULL DEFAULT 0, '
                . '"priority" INTEGER NOT NULL DEFAULT 100, '
                . '"available_at" DATETIME NOT NULL, '
                . '"reserved_at" DATETIME NULL, '
                . '"created_at" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
                . '"error" TEXT NULL'
                . ')',
        };

        $this->database->execute($this->database->prepare($sql));
    }

    /**
     * Get the database instance (for testing/debugging).
     */
    public function getDatabase(): Database
    {
        return $this->database;
    }

    // ── Internal ──────────────────────────────────────────────────

    /**
     * Update a job's status only.
     */
    private function updateStatus(int|string $jobId, JobStatus $status): void
    {
        $this->database->execute(
            $this->database->update($this->tableName, ['status'])
                ->where('id=:id')
                ->assign([
                    'status' => $status->value,
                    'id' => $jobId,
                ]),
        );
    }
}
