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
 *   $manager = new MigrationManager($database);                  // legacy '' scope
 *   $manager = new MigrationManager($database, 'vendor/module'); // module scope (M0)
 *   $manager->addPath('/path/to/migrations');
 *   $manager->migrate();           // Run all pending (fails loud on checksum drift, M1)
 *   $manager->migrate(force: true) // explicit escape hatch (operator decision only)
 *   $manager->rollback();          // Rollback last batch (of THIS scope)
 *   $manager->rollback(2);         // Rollback last 2 batches
 *   $manager->reset();             // Rollback everything (this scope)
 *   $status = $manager->getStatus(); // Get migration status
 *
 * Scope (M0, dossier MIGRATION-GOVERNANCE.md E4): one Database can host many
 * modules; every manager used to share one tracking table with no owner, so
 * module A's rollback() batch selection could (and was designed to) delete
 * module B's tracking rows while B's tables remained. Rows now carry a
 * `scope` (module code, auto-filled by Controller::getMigrationManager) and
 * every read/write filters by it. Pre-M0 rows keep scope '' and are visible
 * only to scope-'' managers — strict, no silent adoption.
 *
 * Checksum (M1): each applied migration records sha256 of its file bytes;
 * migrate() re-hashes every checksummed applied file before running pending
 * work — an edited applied file (the classic "developer owns migrations"
 * incident) throws instead of silently rewriting history. Rows predating M1
 * have an empty checksum and are NOT verifiable (stated, not guessed).
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
     * @param string $scope Owner identity (module code) recorded on every
     *                      applied row and filtered on every read. Default ''
     *                      = legacy behaviour (pre-M0 single-owner tables).
     */
    public function __construct(private readonly Database $database, private readonly string $scope = '')
    {
        $this->schema = new SchemaBuilder($database);
    }

    /**
     * Get the ownership scope this manager reads and writes.
     */
    public function getScope(): string
    {
        return $this->scope;
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
                . '`executed_at` DATETIME DEFAULT CURRENT_TIMESTAMP, '
                . '`scope` VARCHAR(190) NOT NULL DEFAULT \'\', '
                . '`checksum` VARCHAR(64) NOT NULL DEFAULT \'\''
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'pgsql' => "CREATE TABLE IF NOT EXISTS \"{$tableName}\" ("
                . '"id" SERIAL PRIMARY KEY, '
                . '"migration" VARCHAR(255) NOT NULL, '
                . '"batch" INTEGER NOT NULL, '
                . '"executed_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP, '
                . '"scope" VARCHAR(190) NOT NULL DEFAULT \'\', '
                . '"checksum" VARCHAR(64) NOT NULL DEFAULT \'\''
                . ')',
            default => "CREATE TABLE IF NOT EXISTS \"{$tableName}\" ("
                . '"id" INTEGER PRIMARY KEY AUTOINCREMENT, '
                . '"migration" VARCHAR(255) NOT NULL, '
                . '"batch" INTEGER NOT NULL, '
                . '"executed_at" DATETIME DEFAULT CURRENT_TIMESTAMP, '
                . '"scope" VARCHAR(190) NOT NULL DEFAULT \'\', '
                . '"checksum" VARCHAR(64) NOT NULL DEFAULT \'\''
                . ')',
        };

        $this->database->execute($this->database->prepare($sql));

        // Self-heal pre-M0 tables: CREATE IF NOT EXISTS is a no-op on existing
        // tables, so ADD any missing governance column (idempotent per driver).
        $existing = $this->trackingColumns($driverType, $tableName);
        $columnDdl = match ($driverType) {
            'mysql', 'mariadb' => [
                'scope' => "ALTER TABLE `{$tableName}` ADD COLUMN `scope` VARCHAR(190) NOT NULL DEFAULT ''",
                'checksum' => "ALTER TABLE `{$tableName}` ADD COLUMN `checksum` VARCHAR(64) NOT NULL DEFAULT ''",
            ],
            'pgsql' => [
                'scope' => "ALTER TABLE \"{$tableName}\" ADD COLUMN \"scope\" VARCHAR(190) NOT NULL DEFAULT ''",
                'checksum' => "ALTER TABLE \"{$tableName}\" ADD COLUMN \"checksum\" VARCHAR(64) NOT NULL DEFAULT ''",
            ],
            default => [
                'scope' => "ALTER TABLE \"{$tableName}\" ADD COLUMN \"scope\" VARCHAR(190) NOT NULL DEFAULT ''",
                'checksum' => "ALTER TABLE \"{$tableName}\" ADD COLUMN \"checksum\" VARCHAR(64) NOT NULL DEFAULT ''",
            ],
        };

        foreach ($columnDdl as $column => $alter) {
            if (!\in_array($column, $existing, true)) {
                $this->database->execute($this->database->prepare($alter));
            }
        }

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
     * Get the list of already-applied migration names (this scope).
     *
     * @return string[] Applied migration names, ordered by execution
     */
    public function getApplied(): array
    {
        return \array_keys($this->getAppliedWithChecksum());
    }

    /**
     * Applied migrations of this scope as name => recorded sha256
     * ('' = recorded before M1, unverifiable). Duplicated names (should not
     * exist within one scope) collapse to the LAST recorded hash.
     *
     * @return array<string, string>
     */
    public function getAppliedWithChecksum(): array
    {
        $this->ensureTrackingTable();

        $quoted = $this->quotedTableName();
        $quotedScope = $this->database->getDBAdapter()->quote($this->scope);

        $query = $this->database->execute(
            $this->database->prepare(
                "SELECT migration, checksum FROM {$quoted} WHERE scope = {$quotedScope} ORDER BY id ASC",
            ),
        );

        $map = [];
        foreach ($query->fetchAll() as $row) {
            $map[$row['migration']] = (string) ($row['checksum'] ?? '');
        }

        return $map;
    }

    /**
     * Verify recorded checksums against the files on disk (M1).
     *
     * Programmatic surface for status tooling (M2 CLI --status consumes it);
     * migrate() enforces the same rule fail-loud before doing any work.
     * Pre-M1 rows (empty checksum) are skipped: unverifiable, never guessed.
     *
     * @return array<string, string> migration name => error ('' = clean);
     *                               empty array when everything verifies
     */
    public function verifyChecksums(): array
    {
        $errors = [];

        foreach ($this->getAppliedWithChecksum() as $name => $recorded) {
            if ($recorded === '') {
                continue; // legacy row: nothing trustworthy to compare against
            }

            $path = $this->findMigrationFile($name);

            if ($path === null) {
                $errors[$name] = "applied migration file is missing: {$name}";

                continue;
            }

            $actual = \hash_file('sha256', $path);

            if ($actual !== $recorded) {
                $errors[$name] = "checksum drift for {$name} (applied as {$recorded}, file now {$actual})";
            }
        }

        return $errors;
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
     * Run all pending migrations (this scope).
     *
     * Each call increments the batch number. All migrations in a single
     * migrate() call share the same batch, enabling batch-based rollback.
     *
     * Before any work, every checksummed applied file is re-verified (M1):
     * drift throws fail-loud — silently rewriting applied history is exactly
     * the incident this guards. $force is the operator-only escape hatch.
     *
     * @param bool $force Proceed despite checksum drift (operator decision;
     *                    never wire user input into this)
     *
     * @return string[] Names of migrations that were executed
     *
     * @throws DatabaseException If a migration file does not return a Migration instance,
     *                           or checksum verification fails while not forced
     * @throws Throwable If a migration's up() method throws
     */
    public function migrate(bool $force = false): array
    {
        $this->ensureTrackingTable();

        if (!$force) {
            $drift = $this->verifyChecksums();

            if ($drift !== []) {
                $detail = \implode('; ', $drift);

                throw new DatabaseException(
                    "Applied migrations drifted from their files (scope '{$this->scope}'): {$detail}. "
                    . 'Editing an applied migration rewrites shared history - restore the file, or '
                    . 'migrate(force: true) as an explicit operator decision.',
                );
            }
        }

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
            $this->recordMigration($name, $batch, (string) \hash_file('sha256', $path));
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

        $quoted = $this->quotedTableName();
        $quotedScope = $this->database->getDBAdapter()->quote($this->scope);

        // Get the distinct batch numbers to rollback (most recent first,
        // THIS SCOPE ONLY — pre-M0 this selected across every module sharing
        // the table and its missing-file skip then deleted other modules'
        // rows; MIGRATION-GOVERNANCE.md E4).
        $query = $this->database->execute(
            $this->database->prepare(
                "SELECT DISTINCT batch FROM {$quoted} WHERE scope = {$quotedScope} ORDER BY batch DESC",
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
                    "SELECT migration FROM {$quoted} WHERE batch = {$batch} AND scope = {$quotedScope} ORDER BY id DESC",
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

        $quoted = $this->quotedTableName();
        $quotedScope = $this->database->getDBAdapter()->quote($this->scope);

        // Get all applied migrations in reverse order (this scope)
        $query = $this->database->execute(
            $this->database->prepare(
                "SELECT migration FROM {$quoted} WHERE scope = {$quotedScope} ORDER BY id DESC",
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
     *   - checksum: Recorded sha256 ('' = pending, or applied pre-M1)
     *
     * Reads are scoped to this manager's scope (M0).
     *
     * @return array<int, array{name: string, applied: bool, batch: int|null, executed_at: string|null, checksum: string}>
     */
    public function getStatus(): array
    {
        $this->ensureTrackingTable();

        $all = $this->discover();
        $quoted = $this->quotedTableName();
        $quotedScope = $this->database->getDBAdapter()->quote($this->scope);

        // Get applied migration details (this scope)
        $query = $this->database->execute(
            $this->database->prepare(
                "SELECT migration, batch, executed_at, checksum FROM {$quoted} WHERE scope = {$quotedScope} ORDER BY id ASC",
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
                'checksum' => (string) ($record['checksum'] ?? ''),
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
                'checksum' => (string) ($record['checksum'] ?? ''),
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
     * Physical column names of the tracking table, per driver catalog.
     *
     * @return list<string>
     */
    private function trackingColumns(string $driverType, string $tableName): array
    {
        $quoted = $this->quotedTableName();

        $sql = match ($driverType) {
            'mysql', 'mariadb' => "SHOW COLUMNS FROM {$quoted}",
            'pgsql' => 'SELECT column_name AS name FROM information_schema.columns WHERE table_name = '
                . $this->database->getDBAdapter()->quote($tableName),
            default => "PRAGMA table_info({$quoted})",
        };

        $rows = $this->database->execute($this->database->prepare($sql))->fetchAll();

        return \array_values(\array_map(
            static fn (array $row): string => (string) ($driverType === 'mysql' || $driverType === 'mariadb' ? $row['Field'] : $row['name']),
            $rows,
        ));
    }

    /**
     * Get the next batch number.
     *
     * @return int
     */
    private function getNextBatchNumber(): int
    {
        $quoted = $this->quotedTableName();

        $query = $this->database->execute(
            $this->database->prepare(
                "SELECT MAX(batch) as max_batch FROM {$quoted}",
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
     * @param string $checksum sha256 of the migration file bytes ('' = unknown)
     */
    private function recordMigration(string $name, int $batch, string $checksum = ''): void
    {
        $quoted = $this->quotedTableName();
        $quotedName = $this->database->getDBAdapter()->quote($name);
        $quotedScope = $this->database->getDBAdapter()->quote($this->scope);
        $quotedChecksum = $this->database->getDBAdapter()->quote($checksum);

        $this->database->execute(
            $this->database->prepare(
                "INSERT INTO {$quoted} (migration, batch, scope, checksum) VALUES ({$quotedName}, {$batch}, {$quotedScope}, {$quotedChecksum})",
            ),
        );
    }

    /**
     * Remove a migration record from the tracking table (this scope only —
     * pre-M0 a name deleted every module's row with it, E4 again).
     *
     * @param string $name Migration name
     */
    private function removeMigrationRecord(string $name): void
    {
        $quoted = $this->quotedTableName();
        $quotedName = $this->database->getDBAdapter()->quote($name);
        $quotedScope = $this->database->getDBAdapter()->quote($this->scope);

        $this->database->execute(
            $this->database->prepare(
                "DELETE FROM {$quoted} WHERE migration = {$quotedName} AND scope = {$quotedScope}",
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
     * Get the fully qualified and driver-quoted tracking table name.
     *
     * MySQL/MariaDB use backticks, PostgreSQL/SQLite use double quotes.
     *
     * @return string
     */
    private function quotedTableName(): string
    {
        $name = $this->qualifiedTableName();
        $driver = $this->database->getDriverType() ?? 'sqlite';

        return match ($driver) {
            'mysql', 'mariadb' => "`{$name}`",
            default => '"' . $name . '"',
        };
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
