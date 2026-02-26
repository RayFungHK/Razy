<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Database migration manager for schema versioning.
 *
 *
 * @license MIT
 */

namespace Razy\Database;

use Razy\Database;
use Razy\Exception\DatabaseException;
use Throwable;

/**
 * Manages database migration discovery, tracking, execution, and rollback.
 *
 * The MigrationManager discovers migration files from registered directories,
 * tracks applied migrations in a database table, runs pending migrations,
 * and supports rolling back by batch or resetting entirely.
 *
 * Migration files must:
 *   - Follow the naming convention: YYYY_MM_DD_HHMMSS_DescriptionName.php
 *   - Return a Migration instance (typically via anonymous class)
 *
 * Tracking table schema (razy_migrations):
 *   - id: Auto-incrementing primary key
 *   - migration: Migration filename (unique identifier)
 *   - batch: Batch number (incremented per migrate() call)
 *   - executed_at: Timestamp of execution
 *
 * Usage:
 *   $manager = new MigrationManager($database);
 *   $manager->addPath('/path/to/migrations');
 *   $manager->migrate();           // Run all pending
 *   $manager->rollback();          // Rollback last batch
 *   $manager->rollback(2);         // Rollback last 2 batches
 *   $manager->reset();             // Rollback everything
 *   $status = $manager->getStatus(); // Get migration status
 */
class MigrationManager
{
    /** @var string Name of the migration tracking table (without prefix) */
    public const TRACKING_TABLE = 'razy_migrations';

    /** @var string Regex pattern for valid migration filenames */
    public const MIGRATION_FILENAME_PATTERN = '/^\d{4}_\d{2}_\d{2}_\d{6}_\w+\.php$/';

    /** @var SchemaBuilder Schema builder instance for migration execution */
    private SchemaBuilder $schema;

    /** @var string[] Registered migration directory paths */
    private array $migrationPaths = [];

    /** @var bool Whether the tracking table has been verified/created */
    private bool $trackingTableReady = false;

    /**
     * MigrationManager constructor.
     *
     * @param Database $database The connected database instance
     */
    public function __construct(private readonly Database $database)
    {
        $this->schema = new SchemaBuilder($database);
    }

    /**
     * Register a directory path for migration file discovery.
     *
     * Multiple paths can be registered. Duplicate paths are ignored.
     *
     * @param string $path Absolute path to a directory containing migration files
     *
     * @return static
     */
    public function addPath(string $path): static
    {
        $path = \rtrim(\str_replace('\\', '/', $path), '/');
        if (!\in_array($path, $this->migrationPaths, true)) {
            $this->migrationPaths[] = $path;
        }

        return $this;
    }

    /**
     * Get all registered migration paths.
     *
     * @return string[]
     */
    public function getPaths(): array
    {
        return $this->migrationPaths;
    }

    /**
     * Ensure the migration tracking table exists.
     *
     * Creates the table on first call. Uses driver-appropriate DDL
     * for MySQL, PostgreSQL, and SQLite.
     */
    public function ensureTrackingTable(): void
    {
        if ($this->trackingTableReady) {
            return;
        }

        $tableName = $this->qualifiedTableName();

        // Use driver-specific DDL for the tracking table
        $driverType = $this->database->getDriverType() ?? 'sqlite';
        $sql = match ($driverType) {
            'mysql', 'mariadb' => "CREATE TABLE IF NOT EXISTS `{$tableName}` ("
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
                . '`migration` VARCHAR(255) NOT NULL, '
                . '`batch` INT NOT NULL, '
                . '`executed_at` DATETIME DEFAULT CURRENT_TIMESTAMP'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'pgsql' => "CREATE TABLE IF NOT EXISTS \"{$tableName}\" ("
                . '"id" SERIAL PRIMARY KEY, '
                . '"migration" VARCHAR(255) NOT NULL, '
                . '"batch" INTEGER NOT NULL, '
                . '"executed_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
                . ')',
            default => "CREATE TABLE IF NOT EXISTS \"{$tableName}\" ("
                . '"id" INTEGER PRIMARY KEY AUTOINCREMENT, '
                . '"migration" VARCHAR(255) NOT NULL, '
                . '"batch" INTEGER NOT NULL, '
                . '"executed_at" DATETIME DEFAULT CURRENT_TIMESTAMP'
                . ')',
        };

        $this->database->execute($this->database->prepare($sql));
        $this->trackingTableReady = true;
    }

    /**
     * Discover all migration files from registered paths.
     *
     * Scans all registered directories for PHP files matching the
     * migration filename pattern. Returns filenames sorted alphabetically
     * (timestamp prefix ensures chronological order).
     *
     * @return array<string, string> Map of migration name => absolute file path
     */
    public function discover(): array
    {
        $migrations = [];

        foreach ($this->migrationPaths as $path) {
            if (!\is_dir($path)) {
                continue;
            }

            $files = \scandir($path);
            if ($files === false) {
                continue;
            }

            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                if (\preg_match(self::MIGRATION_FILENAME_PATTERN, $file)) {
                    $name = \pathinfo($file, PATHINFO_FILENAME);
                    $fullPath = $path . '/' . $file;
                    $migrations[$name] = $fullPath;
                }
            }
        }

        \ksort($migrations);

        return $migrations;
    }

    /**
     * Get the list of already-applied migration names.
     *
     * @return string[] Applied migration names, ordered by execution
     */
    public function getApplied(): array
    {
        $this->ensureTrackingTable();

        $tableName = $this->qualifiedTableName();
        $query = $this->database->execute(
            $this->database->prepare(
                "SELECT migration FROM \"{$tableName}\" ORDER BY id ASC",
            ),
        );

        $rows = $query->fetchAll();

        return \array_column($rows, 'migration');
    }

    /**
     * Get pending (not yet applied) migrations.
     *
     * @return array<string, string> Map of migration name => file path for unapplied migrations
     */
    public function getPending(): array
    {
        $all = $this->discover();
        $applied = $this->getApplied();

        return \array_diff_key($all, \array_flip($applied));
    }

    /**
     * Run all pending migrations.
     *
     * Each call increments the batch number. All migrations in a single
     * migrate() call share the same batch, enabling batch-based rollback.
     *
     * @return string[] Names of migrations that were executed
     *
     * @throws DatabaseException If a migration file does not return a Migration instance
     * @throws Throwable If a migration's up() method throws
     */
    public function migrate(): array
    {
        $this->ensureTrackingTable();

        $pending = $this->getPending();
        if (empty($pending)) {
            return [];
        }

        $batch = $this->getNextBatchNumber();

        // Clear the statement pool to release any SQLite cursors
        // that may lock the database during DDL execution.
        $this->database->clearStatementPool();

        $executed = [];

        foreach ($pending as $name => $path) {
            $migration = $this->resolveMigration($path);
            $migration->up($this->schema);
            $this->recordMigration($name, $batch);
            $executed[] = $name;
        }

        return $executed;
    }

    /**
     * Rollback the last batch of migrations, or multiple batches.
     *
     * Migrations are rolled back in reverse order within each batch.
     *
     * @param int $steps Number of batches to rollback (default: 1)
     *
     * @return string[] Names of migrations that were rolled back
     *
     * @throws DatabaseException If a migration file cannot be resolved
     * @throws Throwable If a migration's down() method throws
     */
    public function rollback(int $steps = 1): array
    {
        $this->ensureTrackingTable();

        if ($steps < 1) {
            return [];
        }

        $tableName = $this->qualifiedTableName();

        // Get the distinct batch numbers to rollback (most recent first)
        $query = $this->database->execute(
            $this->database->prepare(
                "SELECT DISTINCT batch FROM \"{$tableName}\" ORDER BY batch DESC",
            ),
        );
        $batches = \array_column($query->fetchAll(), 'batch');
        $batchesToRollback = \array_slice($batches, 0, $steps);

        if (empty($batchesToRollback)) {
            return [];
        }

        // Collect all migration names first before executing any DDL
        $migrationsToRollback = [];
        foreach ($batchesToRollback as $batch) {
            $query = $this->database->execute(
                $this->database->prepare(
                    "SELECT migration FROM \"{$tableName}\" WHERE batch = {$batch} ORDER BY id DESC",
                ),
            );
            foreach ($query->fetchAll() as $row) {
                $migrationsToRollback[] = $row['migration'];
            }
        }

        // Clear statement pool to release SQLite cursor locks before DDL
        $this->database->clearStatementPool();

        $rolledBack = [];
        foreach ($migrationsToRollback as $name) {
            $path = $this->findMigrationFile($name);

            if ($path !== null) {
                $migration = $this->resolveMigration($path);
                $migration->down($this->schema);
            }

            $this->removeMigrationRecord($name);
            $rolledBack[] = $name;
        }

        return $rolledBack;
    }

    /**
     * Reset all migrations (rollback everything).
     *
     * Rolls back all applied migrations in reverse order.
     *
     * @return string[] Names of migrations that were rolled back
     *
     * @throws DatabaseException If a migration file cannot be resolved
     * @throws Throwable If a migration's down() method throws
     */
    public function reset(): array
    {
        $this->ensureTrackingTable();

        $tableName = $this->qualifiedTableName();

        // Get all applied migrations in reverse order
        $query = $this->database->execute(
            $this->database->prepare(
                "SELECT migration FROM \"{$tableName}\" ORDER BY id DESC",
            ),
        );
        $rows = $query->fetchAll();

        if (empty($rows)) {
            return [];
        }

        // Collect migration names before executing DDL
        $migrationsToReset = \array_column($rows, 'migration');

        // Clear statement pool to release SQLite cursor locks before DDL
        $this->database->clearStatementPool();

        $rolledBack = [];

        foreach ($migrationsToReset as $name) {
            $path = $this->findMigrationFile($name);

            if ($path !== null) {
                $migration = $this->resolveMigration($path);
                $migration->down($this->schema);
            }

            $this->removeMigrationRecord($name);
            $rolledBack[] = $name;
        }

        return $rolledBack;
    }

    /**
     * Get the full migration status.
     *
     * Returns an array of status records, each containing:
     *   - name: Migration name
     *   - applied: Whether this migration has been applied
     *   - batch: Batch number (null if not applied)
     *   - executed_at: Execution timestamp (null if not applied)
     *
     * @return array<int, array{name: string, applied: bool, batch: int|null, executed_at: string|null}>
     */
    public function getStatus(): array
    {
        $this->ensureTrackingTable();

        $all = $this->discover();
        $tableName = $this->qualifiedTableName();

        // Get applied migration details
        $query = $this->database->execute(
            $this->database->prepare(
                "SELECT migration, batch, executed_at FROM \"{$tableName}\" ORDER BY id ASC",
            ),
        );
        $appliedRows = $query->fetchAll();
        $appliedMap = [];
        foreach ($appliedRows as $row) {
            $appliedMap[$row['migration']] = $row;
        }

        $status = [];

        // Include all discovered migrations
        foreach ($all as $name => $path) {
            $record = $appliedMap[$name] ?? null;
            $status[] = [
                'name' => $name,
                'applied' => $record !== null,
                'batch' => $record ? (int) $record['batch'] : null,
                'executed_at' => $record['executed_at'] ?? null,
            ];
            unset($appliedMap[$name]);
        }

        // Include applied migrations whose files are no longer present (orphaned)
        foreach ($appliedMap as $name => $record) {
            $status[] = [
                'name' => $name,
                'applied' => true,
                'batch' => (int) $record['batch'],
                'executed_at' => $record['executed_at'] ?? null,
            ];
        }

        return $status;
    }

    /**
     * Get the SchemaBuilder instance.
     *
     * @return SchemaBuilder
     */
    public function getSchemaBuilder(): SchemaBuilder
    {
        return $this->schema;
    }

    /**
     * Get the next batch number.
     *
     * @return int
     */
    private function getNextBatchNumber(): int
    {
        $tableName = $this->qualifiedTableName();

        $query = $this->database->execute(
            $this->database->prepare(
                "SELECT MAX(batch) as max_batch FROM \"{$tableName}\"",
            ),
        );
        $row = $query->fetch();

        return ($row && $row['max_batch'] !== null) ? ((int) $row['max_batch'] + 1) : 1;
    }

    /**
     * Record a migration as applied in the tracking table.
     *
     * @param string $name Migration name
     * @param int $batch Batch number
     */
    private function recordMigration(string $name, int $batch): void
    {
        $tableName = $this->qualifiedTableName();
        $escapedName = \addslashes($name);

        $this->database->execute(
            $this->database->prepare(
                "INSERT INTO \"{$tableName}\" (migration, batch) VALUES ('{$escapedName}', {$batch})",
            ),
        );
    }

    /**
     * Remove a migration record from the tracking table.
     *
     * @param string $name Migration name
     */
    private function removeMigrationRecord(string $name): void
    {
        $tableName = $this->qualifiedTableName();
        $escapedName = \addslashes($name);

        $this->database->execute(
            $this->database->prepare(
                "DELETE FROM \"{$tableName}\" WHERE migration = '{$escapedName}'",
            ),
        );
    }

    /**
     * Get the fully qualified tracking table name (with prefix).
     *
     * @return string
     */
    private function qualifiedTableName(): string
    {
        return $this->database->getPrefix() . self::TRACKING_TABLE;
    }

    /**
     * Resolve a migration file to a Migration instance.
     *
     * The file must return a Migration instance (typically via anonymous class).
     *
     * @param string $path Absolute path to the migration file
     *
     * @return Migration
     *
     * @throws DatabaseException If the file does not return a Migration instance
     */
    private function resolveMigration(string $path): Migration
    {
        if (!\file_exists($path)) {
            throw new DatabaseException("Migration file not found: {$path}");
        }

        $result = require $path;

        if (!$result instanceof Migration) {
            throw new DatabaseException(
                "Migration file must return a Migration instance: {$path}",
            );
        }

        return $result;
    }

    /**
     * Find the file path for a migration by name.
     *
     * Searches all registered paths for a matching migration file.
     *
     * @param string $name Migration name (without .php extension)
     *
     * @return string|null Absolute file path, or null if not found
     */
    private function findMigrationFile(string $name): ?string
    {
        $all = $this->discover();

        return $all[$name] ?? null;
    }
}
