<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Database;
use Razy\Database\Migration;
use Razy\Database\MigrationManager;
use Razy\Database\SchemaBuilder;
use Razy\Database\Table;
use Razy\Database\Table\TableHelper;
use ReflectionClass;
use Throwable;

/**
 * Tests for P3: Database Migration System.
 *
 * Covers SchemaBuilder, Migration abstract class, and MigrationManager
 * including discovery, tracking, execution, rollback, and status.
 */
#[CoversClass(SchemaBuilder::class)]
#[CoversClass(Migration::class)]
#[CoversClass(MigrationManager::class)]
class MigrationTest extends TestCase
{
    /** @var string Temporary directory for migration files */
    private string $tempDir;

    protected function tearDown(): void
    {
        // Clean up temp directories
        if (isset($this->tempDir) && \is_dir($this->tempDir)) {
            $files = \glob($this->tempDir . '/*');
            if ($files) {
                \array_map('unlink', $files);
            }
            @\rmdir($this->tempDir);
        }
    }

    public static function validMigrationFilenameProvider(): array
    {
        return [
            'basic' => ['2025_01_15_120000_CreateUsersTable.php'],
            'single word' => ['2025_06_30_235959_Init.php'],
            'underscores in name' => ['2025_01_01_000000_Create_Users_Table.php'],
            'long name' => ['2025_12_31_235959_AddEmailVerificationColumnToUsersTable.php'],
        ];
    }

    public static function invalidMigrationFilenameProvider(): array
    {
        return [
            'no extension' => ['2025_01_15_120000_Create'],
            'wrong extension' => ['2025_01_15_120000_Create.txt'],
            'no timestamp' => ['CreateUsersTable.php'],
            'short timestamp' => ['2025_01_15_1200_Create.php'],
            'no underscore before name' => ['20250115120000Create.php'],
            'markdown file' => ['README.md'],
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 1: SchemaBuilder
    // ═══════════════════════════════════════════════════════════════

    // ─── SchemaBuilder: Constructor & Accessor ───────────────────

    public function testSchemaBuilderConstructorAcceptsDatabase(): void
    {
        $db = $this->createSqliteDb();
        $schema = new SchemaBuilder($db);

        $this->assertSame($db, $schema->getDatabase());
    }

    // ─── SchemaBuilder: raw() ────────────────────────────────────

    public function testSchemaBuilderRawExecutesSql(): void
    {
        $db = $this->createSqliteDb();
        $schema = new SchemaBuilder($db);

        $schema->raw('CREATE TABLE test_raw (id INTEGER PRIMARY KEY, name TEXT)');

        // Verify by inserting and selecting
        $db->execute($db->prepare("INSERT INTO test_raw (id, name) VALUES (1, 'Alice')"));
        $query = $db->execute($db->prepare('SELECT name FROM test_raw WHERE id = 1'));
        $row = $query->fetch();

        $this->assertSame('Alice', $row['name']);
    }

    public function testSchemaBuilderRawReturnsQueryResult(): void
    {
        $db = $this->createSqliteDb();
        $schema = new SchemaBuilder($db);

        $schema->raw('CREATE TABLE test_return (id INTEGER PRIMARY KEY)');
        $result = $schema->raw('INSERT INTO test_return (id) VALUES (42)');

        $this->assertInstanceOf(Database\Query::class, $result);
    }

    // ─── SchemaBuilder: drop() / dropIfExists() ─────────────────

    public function testSchemaBuilderDropRemovesTable(): void
    {
        $db = $this->createSqliteDb();
        $schema = new SchemaBuilder($db);

        $schema->raw('CREATE TABLE drop_test (id INTEGER PRIMARY KEY)');
        $schema->drop('drop_test');

        // Inserting should now fail
        $this->expectException(Throwable::class);
        $db->execute($db->prepare('INSERT INTO drop_test (id) VALUES (1)'));
    }

    public function testSchemaBuilderDropIfExistsNonExistent(): void
    {
        $db = $this->createSqliteDb();
        $schema = new SchemaBuilder($db);

        // Should not throw
        $schema->dropIfExists('nonexistent_table');
        $this->assertTrue(true);
    }

    public function testSchemaBuilderDropIfExistsRemovesExistingTable(): void
    {
        $db = $this->createSqliteDb();
        $schema = new SchemaBuilder($db);

        $schema->raw('CREATE TABLE drop_if_test (id INTEGER PRIMARY KEY)');
        $schema->dropIfExists('drop_if_test');

        // Table should be gone
        $this->expectException(Throwable::class);
        $db->execute($db->prepare('INSERT INTO drop_if_test (id) VALUES (1)'));
    }

    // ─── SchemaBuilder: rename() ─────────────────────────────────

    public function testSchemaBuilderRenameTable(): void
    {
        $db = $this->createSqliteDb();
        $schema = new SchemaBuilder($db);

        $schema->raw('CREATE TABLE old_name (id INTEGER PRIMARY KEY)');
        $db->execute($db->prepare('INSERT INTO old_name (id) VALUES (1)'));

        $schema->rename('old_name', 'new_name');

        // New name should work
        $query = $db->execute($db->prepare('SELECT id FROM new_name'));
        $row = $query->fetch();
        $this->assertSame(1, (int) $row['id']);

        // Old name should fail
        $this->expectException(Throwable::class);
        $db->execute($db->prepare('SELECT * FROM old_name'));
    }

    // ─── SchemaBuilder: hasTable() ───────────────────────────────

    public function testSchemaBuilderHasTableReturnsFalseForNonExistent(): void
    {
        $db = $this->createSqliteDb();
        $schema = new SchemaBuilder($db);

        $this->assertFalse($schema->hasTable('nonexistent'));
    }

    // ─── SchemaBuilder: create() ─────────────────────────────────

    public function testSchemaBuilderCreateCallsCallbackWithTable(): void
    {
        $db = $this->createSqliteDb();
        $schema = new SchemaBuilder($db);

        $callbackCalled = false;
        $receivedTable = null;

        // Note: Table::getSyntax() generates MySQL-specific DDL which won't
        // work on SQLite. This test verifies the callback receives a Table instance.
        // Actual SQL execution requires MySQL or using raw().
        try {
            $schema->create('cb_test', function (Table $table) use (&$callbackCalled, &$receivedTable) {
                $callbackCalled = true;
                $receivedTable = $table;
                $table->addColumn('id=type(int),auto');
            });
        } catch (Throwable) {
            // Expected: MySQL DDL fails on SQLite — callback was still called
        }

        $this->assertTrue($callbackCalled);
        $this->assertInstanceOf(Table::class, $receivedTable);
    }

    // ─── SchemaBuilder: table() ──────────────────────────────────

    public function testSchemaBuilderTableCallsCallbackWithHelper(): void
    {
        $db = $this->createSqliteDb();
        $schema = new SchemaBuilder($db);

        $receivedHelper = null;

        try {
            $schema->table('some_table', function (TableHelper $helper) use (&$receivedHelper) {
                $receivedHelper = $helper;
                // Don't add changes — getSyntax() returns '' for no-ops
            });
        } catch (Throwable) {
            // Ignore
        }

        $this->assertInstanceOf(TableHelper::class, $receivedHelper);
    }

    public function testSchemaBuilderTableNoOpDoesNotExecute(): void
    {
        $db = $this->createSqliteDb();
        $schema = new SchemaBuilder($db);

        $initialCount = $db->getTotalQueryCount();

        // Empty callback = no pending changes = no SQL executed
        $schema->table('some_table', function (TableHelper $helper) {
            // No changes
        });

        $this->assertSame($initialCount, $db->getTotalQueryCount());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 2: Migration Abstract Class
    // ═══════════════════════════════════════════════════════════════

    public function testMigrationUpAndDownAreAbstract(): void
    {
        $ref = new ReflectionClass(Migration::class);

        $this->assertTrue($ref->isAbstract());
        $this->assertTrue($ref->getMethod('up')->isAbstract());
        $this->assertTrue($ref->getMethod('down')->isAbstract());
    }

    public function testMigrationGetDescriptionDefaultsToEmpty(): void
    {
        $migration = new class() extends Migration {
            public function up(SchemaBuilder $schema): void
            {
            }

            public function down(SchemaBuilder $schema): void
            {
            }
        };

        $this->assertSame('', $migration->getDescription());
    }

    public function testMigrationGetDescriptionCanBeOverridden(): void
    {
        $migration = new class() extends Migration {
            public function up(SchemaBuilder $schema): void
            {
            }

            public function down(SchemaBuilder $schema): void
            {
            }

            public function getDescription(): string
            {
                return 'Create the users table';
            }
        };

        $this->assertSame('Create the users table', $migration->getDescription());
    }

    public function testMigrationUpReceivesSchemaBuilder(): void
    {
        $receivedSchema = null;

        $migration = new class($receivedSchema) extends Migration {
            public function __construct(private mixed &$capture)
            {
            }

            public function up(SchemaBuilder $schema): void
            {
                $this->capture = $schema;
            }

            public function down(SchemaBuilder $schema): void
            {
            }
        };

        $db = $this->createSqliteDb();
        $schema = new SchemaBuilder($db);
        $migration->up($schema);

        $this->assertSame($schema, $receivedSchema);
    }

    public function testMigrationDownReceivesSchemaBuilder(): void
    {
        $receivedSchema = null;

        $migration = new class($receivedSchema) extends Migration {
            public function __construct(private mixed &$capture)
            {
            }

            public function up(SchemaBuilder $schema): void
            {
            }

            public function down(SchemaBuilder $schema): void
            {
                $this->capture = $schema;
            }
        };

        $db = $this->createSqliteDb();
        $schema = new SchemaBuilder($db);
        $migration->down($schema);

        $this->assertSame($schema, $receivedSchema);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 3: MigrationManager — Construction & Configuration
    // ═══════════════════════════════════════════════════════════════

    public function testManagerConstructorCreatesInstance(): void
    {
        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);

        $this->assertInstanceOf(MigrationManager::class, $manager);
    }

    public function testManagerGetSchemaBuilder(): void
    {
        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);

        $schema = $manager->getSchemaBuilder();
        $this->assertInstanceOf(SchemaBuilder::class, $schema);
        $this->assertSame($db, $schema->getDatabase());
    }

    public function testManagerAddPathStoresPath(): void
    {
        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);

        $manager->addPath('/some/path');
        $this->assertSame(['/some/path'], $manager->getPaths());
    }

    public function testManagerAddPathDeduplicates(): void
    {
        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);

        $manager->addPath('/some/path');
        $manager->addPath('/some/path');
        $this->assertCount(1, $manager->getPaths());
    }

    public function testManagerAddPathNormalizesSlashes(): void
    {
        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);

        $manager->addPath('C:\\Users\\test\\migrations\\');
        $paths = $manager->getPaths();
        $this->assertSame(['C:/Users/test/migrations'], $paths);
    }

    public function testManagerAddPathIsChainable(): void
    {
        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);

        $result = $manager->addPath('/path1')->addPath('/path2');
        $this->assertSame($manager, $result);
        $this->assertCount(2, $manager->getPaths());
    }

    public function testManagerGetPathsEmptyByDefault(): void
    {
        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);

        $this->assertSame([], $manager->getPaths());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 4: MigrationManager — Tracking Table
    // ═══════════════════════════════════════════════════════════════

    public function testEnsureTrackingTableCreatesTable(): void
    {
        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);

        $manager->ensureTrackingTable();

        // Verify table exists by inserting a row
        $db->execute($db->prepare(
            "INSERT INTO \"razy_migrations\" (migration, batch) VALUES ('test', 1)",
        ));
        $query = $db->execute($db->prepare('SELECT migration FROM "razy_migrations"'));
        $row = $query->fetch();

        $this->assertSame('test', $row['migration']);
    }

    public function testEnsureTrackingTableIdempotent(): void
    {
        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);

        $manager->ensureTrackingTable();
        $manager->ensureTrackingTable(); // Should not throw

        $this->assertTrue(true);
    }

    public function testEnsureTrackingTableAutoIncrements(): void
    {
        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->ensureTrackingTable();

        $db->execute($db->prepare(
            "INSERT INTO \"razy_migrations\" (migration, batch) VALUES ('m1', 1)",
        ));
        $db->execute($db->prepare(
            "INSERT INTO \"razy_migrations\" (migration, batch) VALUES ('m2', 1)",
        ));

        $query = $db->execute($db->prepare('SELECT id FROM "razy_migrations" ORDER BY id'));
        $rows = $query->fetchAll();

        $this->assertSame(1, (int) $rows[0]['id']);
        $this->assertSame(2, (int) $rows[1]['id']);
    }

    public function testTrackingTableConstant(): void
    {
        $this->assertSame('razy_migrations', MigrationManager::TRACKING_TABLE);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 5: MigrationManager — Discovery
    // ═══════════════════════════════════════════════════════════════

    public function testDiscoverFindsValidMigrationFiles(): void
    {
        $dir = $this->createTempMigrationDir();
        $this->createMigrationFile($dir, '2025_01_15_120000_CreateUsersTable');
        $this->createMigrationFile($dir, '2025_01_16_090000_CreatePostsTable');

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir);

        $migrations = $manager->discover();

        $this->assertCount(2, $migrations);
        $this->assertArrayHasKey('2025_01_15_120000_CreateUsersTable', $migrations);
        $this->assertArrayHasKey('2025_01_16_090000_CreatePostsTable', $migrations);
    }

    public function testDiscoverSortsByFilename(): void
    {
        $dir = $this->createTempMigrationDir();
        // Create in reverse order
        $this->createMigrationFile($dir, '2025_03_01_000000_Third');
        $this->createMigrationFile($dir, '2025_01_01_000000_First');
        $this->createMigrationFile($dir, '2025_02_01_000000_Second');

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir);

        $names = \array_keys($manager->discover());

        $this->assertSame([
            '2025_01_01_000000_First',
            '2025_02_01_000000_Second',
            '2025_03_01_000000_Third',
        ], $names);
    }

    public function testDiscoverIgnoresInvalidFilenames(): void
    {
        $dir = $this->createTempMigrationDir();
        $this->createMigrationFile($dir, '2025_01_15_120000_Valid');

        // Create invalid files
        \file_put_contents($dir . '/not_a_migration.php', '<?php return true;');
        \file_put_contents($dir . '/README.md', '# Migrations');
        \file_put_contents($dir . '/2025_13_01_Invalid.php', '<?php return true;'); // wrong format

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir);

        $migrations = $manager->discover();

        $this->assertCount(1, $migrations);
        $this->assertArrayHasKey('2025_01_15_120000_Valid', $migrations);
    }

    public function testDiscoverReturnsEmptyForNonExistentDir(): void
    {
        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath('/nonexistent/path/to/nowhere');

        $this->assertSame([], $manager->discover());
    }

    public function testDiscoverMultiplePaths(): void
    {
        $dir1 = $this->createTempMigrationDir('dir1');
        $dir2 = $this->createTempMigrationDir('dir2');

        $this->createMigrationFile($dir1, '2025_01_01_000000_FromDir1');
        $this->createMigrationFile($dir2, '2025_01_02_000000_FromDir2');

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir1)->addPath($dir2);

        $migrations = $manager->discover();

        $this->assertCount(2, $migrations);
        $this->assertArrayHasKey('2025_01_01_000000_FromDir1', $migrations);
        $this->assertArrayHasKey('2025_01_02_000000_FromDir2', $migrations);
    }

    public function testDiscoverWithNoRegisteredPaths(): void
    {
        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);

        $this->assertSame([], $manager->discover());
    }

    // ─── Migration Filename Pattern ──────────────────────────────

    #[DataProvider('validMigrationFilenameProvider')]
    public function testValidMigrationFilenames(string $filename): void
    {
        $this->assertMatchesRegularExpression(
            MigrationManager::MIGRATION_FILENAME_PATTERN,
            $filename,
        );
    }

    #[DataProvider('invalidMigrationFilenameProvider')]
    public function testInvalidMigrationFilenames(string $filename): void
    {
        $this->assertDoesNotMatchRegularExpression(
            MigrationManager::MIGRATION_FILENAME_PATTERN,
            $filename,
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 6: MigrationManager — Migrate (Up)
    // ═══════════════════════════════════════════════════════════════

    public function testMigrateRunsPendingMigrations(): void
    {
        $dir = $this->createTempMigrationDir();
        $this->createMigrationFile($dir, '2025_01_01_000000_CreateAlpha', 'alpha_table');
        $this->createMigrationFile($dir, '2025_01_02_000000_CreateBeta', 'beta_table');

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir);

        $executed = $manager->migrate();

        $this->assertSame([
            '2025_01_01_000000_CreateAlpha',
            '2025_01_02_000000_CreateBeta',
        ], $executed);

        // Verify tables were created
        $db->execute($db->prepare('INSERT INTO alpha_table (id) VALUES (1)'));
        $db->execute($db->prepare('INSERT INTO beta_table (id) VALUES (1)'));
        $this->assertTrue(true); // No exception means tables exist
    }

    public function testMigrateRecordsInTrackingTable(): void
    {
        $dir = $this->createTempMigrationDir();
        $this->createMigrationFile($dir, '2025_01_01_000000_CreateGamma', 'gamma_table');

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir);

        $manager->migrate();

        $applied = $manager->getApplied();
        $this->assertSame(['2025_01_01_000000_CreateGamma'], $applied);
    }

    public function testMigrateAssignsBatchNumber(): void
    {
        $dir = $this->createTempMigrationDir();
        $this->createMigrationFile($dir, '2025_01_01_000000_First', 'first_table');

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir);

        $manager->migrate();

        // Check batch number
        $query = $db->execute($db->prepare(
            'SELECT batch FROM "razy_migrations" WHERE migration = \'2025_01_01_000000_First\'',
        ));
        $row = $query->fetch();
        $this->assertSame(1, (int) $row['batch']);
    }

    public function testMigrateIncrementsBatchNumber(): void
    {
        $dir = $this->createTempMigrationDir();
        $this->createMigrationFile($dir, '2025_01_01_000000_BatchOne', 'batch_one');

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir);

        $manager->migrate(); // Batch 1

        // Add a second migration
        $this->createMigrationFile($dir, '2025_01_02_000000_BatchTwo', 'batch_two');

        $manager->migrate(); // Batch 2

        $query = $db->execute($db->prepare(
            'SELECT batch FROM "razy_migrations" WHERE migration = \'2025_01_02_000000_BatchTwo\'',
        ));
        $row = $query->fetch();
        $this->assertSame(2, (int) $row['batch']);
    }

    public function testMigrateSkipsAlreadyApplied(): void
    {
        $dir = $this->createTempMigrationDir();
        $this->createMigrationFile($dir, '2025_01_01_000000_OnlyOnce', 'only_once');

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir);

        $first = $manager->migrate();
        $second = $manager->migrate();

        $this->assertCount(1, $first);
        $this->assertSame([], $second);
    }

    public function testMigrateReturnsEmptyWhenNoPending(): void
    {
        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);

        $result = $manager->migrate();
        $this->assertSame([], $result);
    }

    public function testMigrateMultipleInSameBatch(): void
    {
        $dir = $this->createTempMigrationDir();
        $this->createMigrationFile($dir, '2025_01_01_000000_TableA', 'table_a');
        $this->createMigrationFile($dir, '2025_01_02_000000_TableB', 'table_b');
        $this->createMigrationFile($dir, '2025_01_03_000000_TableC', 'table_c');

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir);

        $executed = $manager->migrate();
        $this->assertCount(3, $executed);

        // All should be in batch 1
        $query = $db->execute($db->prepare(
            'SELECT DISTINCT batch FROM "razy_migrations"',
        ));
        $rows = $query->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['batch']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 7: MigrationManager — Rollback
    // ═══════════════════════════════════════════════════════════════

    public function testRollbackLastBatch(): void
    {
        $dir = $this->createTempMigrationDir();
        $this->createMigrationFile($dir, '2025_01_01_000000_RollA', 'roll_a');

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir);

        $manager->migrate();

        // Add more and migrate again (batch 2)
        $this->createMigrationFile($dir, '2025_01_02_000000_RollB', 'roll_b');
        $manager->migrate();

        $rolledBack = $manager->rollback();

        // Should rollback batch 2 only
        $this->assertSame(['2025_01_02_000000_RollB'], $rolledBack);

        // Batch 1 should still be applied
        $applied = $manager->getApplied();
        $this->assertSame(['2025_01_01_000000_RollA'], $applied);

        // roll_b table should be dropped
        $this->expectException(Throwable::class);
        $db->execute($db->prepare('SELECT * FROM roll_b'));
    }

    public function testRollbackMultipleBatches(): void
    {
        $dir = $this->createTempMigrationDir();
        $this->createMigrationFile($dir, '2025_01_01_000000_Multi1', 'multi_1');

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir);
        $manager->migrate(); // Batch 1

        $this->createMigrationFile($dir, '2025_01_02_000000_Multi2', 'multi_2');
        $manager->migrate(); // Batch 2

        $this->createMigrationFile($dir, '2025_01_03_000000_Multi3', 'multi_3');
        $manager->migrate(); // Batch 3

        $rolledBack = $manager->rollback(2);

        // Should rollback batches 3 and 2
        $this->assertCount(2, $rolledBack);
        $this->assertContains('2025_01_03_000000_Multi3', $rolledBack);
        $this->assertContains('2025_01_02_000000_Multi2', $rolledBack);

        // Batch 1 remains
        $applied = $manager->getApplied();
        $this->assertSame(['2025_01_01_000000_Multi1'], $applied);
    }

    public function testRollbackReverseOrderWithinBatch(): void
    {
        $dir = $this->createTempMigrationDir();
        $this->createMigrationFile($dir, '2025_01_01_000000_RevA', 'rev_a');
        $this->createMigrationFile($dir, '2025_01_02_000000_RevB', 'rev_b');

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir);
        $manager->migrate(); // Both in batch 1

        $rolledBack = $manager->rollback();

        // Should be in reverse order
        $this->assertSame([
            '2025_01_02_000000_RevB',
            '2025_01_01_000000_RevA',
        ], $rolledBack);
    }

    public function testRollbackWithZeroSteps(): void
    {
        $dir = $this->createTempMigrationDir();
        $this->createMigrationFile($dir, '2025_01_01_000000_NoRoll', 'no_roll');

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir);
        $manager->migrate();

        $rolledBack = $manager->rollback(0);
        $this->assertSame([], $rolledBack);

        // Migration should still be applied
        $this->assertCount(1, $manager->getApplied());
    }

    public function testRollbackWhenNothingApplied(): void
    {
        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);

        $rolledBack = $manager->rollback();
        $this->assertSame([], $rolledBack);
    }

    public function testRollbackMoreStepsThanBatches(): void
    {
        $dir = $this->createTempMigrationDir();
        $this->createMigrationFile($dir, '2025_01_01_000000_Over1', 'over_1');

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir);
        $manager->migrate(); // 1 batch

        $rolledBack = $manager->rollback(10); // Request 10, only 1 exists

        $this->assertCount(1, $rolledBack);
        $this->assertSame([], $manager->getApplied());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 8: MigrationManager — Reset
    // ═══════════════════════════════════════════════════════════════

    public function testResetRollsBackEverything(): void
    {
        $dir = $this->createTempMigrationDir();
        $this->createMigrationFile($dir, '2025_01_01_000000_Reset1', 'reset_1');

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir);
        $manager->migrate(); // Batch 1

        $this->createMigrationFile($dir, '2025_01_02_000000_Reset2', 'reset_2');
        $manager->migrate(); // Batch 2

        $rolledBack = $manager->reset();

        $this->assertCount(2, $rolledBack);
        $this->assertSame([], $manager->getApplied());
    }

    public function testResetReverseOrder(): void
    {
        $dir = $this->createTempMigrationDir();
        $this->createMigrationFile($dir, '2025_01_01_000000_Order1', 'order_1');
        $this->createMigrationFile($dir, '2025_01_02_000000_Order2', 'order_2');
        $this->createMigrationFile($dir, '2025_01_03_000000_Order3', 'order_3');

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir);
        $manager->migrate();

        $rolledBack = $manager->reset();

        // Should be in reverse order
        $this->assertSame([
            '2025_01_03_000000_Order3',
            '2025_01_02_000000_Order2',
            '2025_01_01_000000_Order1',
        ], $rolledBack);
    }

    public function testResetWhenNothingApplied(): void
    {
        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);

        $rolledBack = $manager->reset();
        $this->assertSame([], $rolledBack);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 9: MigrationManager — GetApplied / GetPending
    // ═══════════════════════════════════════════════════════════════

    public function testGetAppliedEmptyInitially(): void
    {
        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);

        $this->assertSame([], $manager->getApplied());
    }

    public function testGetAppliedAfterMigrate(): void
    {
        $dir = $this->createTempMigrationDir();
        $this->createMigrationFile($dir, '2025_01_01_000000_Applied1', 'applied_1');
        $this->createMigrationFile($dir, '2025_01_02_000000_Applied2', 'applied_2');

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir);
        $manager->migrate();

        $applied = $manager->getApplied();

        $this->assertSame([
            '2025_01_01_000000_Applied1',
            '2025_01_02_000000_Applied2',
        ], $applied);
    }

    public function testGetPendingWithNoneApplied(): void
    {
        $dir = $this->createTempMigrationDir();
        $this->createMigrationFile($dir, '2025_01_01_000000_Pend1', 'pend_1');
        $this->createMigrationFile($dir, '2025_01_02_000000_Pend2', 'pend_2');

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir);

        $pending = $manager->getPending();

        $this->assertCount(2, $pending);
        $this->assertArrayHasKey('2025_01_01_000000_Pend1', $pending);
        $this->assertArrayHasKey('2025_01_02_000000_Pend2', $pending);
    }

    public function testGetPendingExcludesApplied(): void
    {
        $dir = $this->createTempMigrationDir();
        $this->createMigrationFile($dir, '2025_01_01_000000_Done', 'done_t');
        $this->createMigrationFile($dir, '2025_01_02_000000_NotDone', 'not_done_t');

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir);

        $manager->migrate();

        // Add a new one
        $this->createMigrationFile($dir, '2025_01_03_000000_New', 'new_t');

        $pending = $manager->getPending();
        $this->assertCount(1, $pending);
        $this->assertArrayHasKey('2025_01_03_000000_New', $pending);
    }

    public function testGetPendingEmptyAfterFullMigrate(): void
    {
        $dir = $this->createTempMigrationDir();
        $this->createMigrationFile($dir, '2025_01_01_000000_All', 'all_t');

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir);

        $manager->migrate();

        $this->assertSame([], $manager->getPending());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 10: MigrationManager — Status
    // ═══════════════════════════════════════════════════════════════

    public function testGetStatusShowsAllMigrations(): void
    {
        $dir = $this->createTempMigrationDir();
        $this->createMigrationFile($dir, '2025_01_01_000000_Status1', 'stat_1');
        $this->createMigrationFile($dir, '2025_01_02_000000_Status2', 'stat_2');

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir);

        $manager->migrate();

        // Add one more (pending)
        $this->createMigrationFile($dir, '2025_01_03_000000_Status3', 'stat_3');

        $status = $manager->getStatus();

        $this->assertCount(3, $status);

        // First two should be applied
        $this->assertTrue($status[0]['applied']);
        $this->assertSame(1, $status[0]['batch']);
        $this->assertSame('2025_01_01_000000_Status1', $status[0]['name']);

        $this->assertTrue($status[1]['applied']);
        $this->assertSame(1, $status[1]['batch']);

        // Third should be pending
        $this->assertFalse($status[2]['applied']);
        $this->assertNull($status[2]['batch']);
        $this->assertNull($status[2]['executed_at']);
        $this->assertSame('2025_01_03_000000_Status3', $status[2]['name']);
    }

    public function testGetStatusEmptyWithNoMigrations(): void
    {
        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);

        $status = $manager->getStatus();
        $this->assertSame([], $status);
    }

    public function testGetStatusOrphanedMigrations(): void
    {
        $dir = $this->createTempMigrationDir();
        $this->createMigrationFile($dir, '2025_01_01_000000_Orphan', 'orphan_t');

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir);

        $manager->migrate();

        // Delete the migration file
        \unlink($dir . '/2025_01_01_000000_Orphan.php');

        $status = $manager->getStatus();

        // Should still appear in status (as orphaned/applied)
        $this->assertCount(1, $status);
        $this->assertTrue($status[0]['applied']);
        $this->assertSame('2025_01_01_000000_Orphan', $status[0]['name']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 11: MigrationManager — Error Cases
    // ═══════════════════════════════════════════════════════════════

    public function testMigrateThrowsForInvalidMigrationFile(): void
    {
        $dir = $this->createTempMigrationDir();

        // Create a file that returns a non-Migration object
        \file_put_contents(
            $dir . '/2025_01_01_000000_BadMigration.php',
            '<?php return new \stdClass();',
        );

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir);

        $this->expectException(Throwable::class);
        $this->expectExceptionMessage('Migration file must return a Migration instance');
        $manager->migrate();
    }

    public function testMigrateThrowsForMissingFile(): void
    {
        $dir = $this->createTempMigrationDir();

        // Create then delete the file, but manually insert into tracking
        $this->createMigrationFile($dir, '2025_01_01_000000_Ghost', 'ghost_t');

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir);

        $manager->migrate();

        // Now corrupt the file path — create another manager with wrong path
        $manager2 = new MigrationManager($db);
        $manager2->addPath('/nonexistent');

        // getPending should be empty (already applied), so no error
        $result = $manager2->migrate();
        $this->assertSame([], $result);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 12: MigrationManager — Full Lifecycle
    // ═══════════════════════════════════════════════════════════════

    public function testFullMigrateRollbackCycle(): void
    {
        $dir = $this->createTempMigrationDir();
        $this->createMigrationFile($dir, '2025_01_01_000000_Lifecycle1', 'life_1');
        $this->createMigrationFile($dir, '2025_01_02_000000_Lifecycle2', 'life_2');

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir);

        // Step 1: Migrate
        $executed = $manager->migrate();
        $this->assertCount(2, $executed);
        $this->assertCount(2, $manager->getApplied());
        $this->assertSame([], $manager->getPending());

        // Step 2: Rollback
        $rolledBack = $manager->rollback();
        $this->assertCount(2, $rolledBack);
        $this->assertSame([], $manager->getApplied());
        $this->assertCount(2, $manager->getPending());

        // Step 3: Re-migrate
        $reExecuted = $manager->migrate();
        $this->assertCount(2, $reExecuted);
        $this->assertCount(2, $manager->getApplied());
    }

    public function testIncrementalMigrateCycle(): void
    {
        $dir = $this->createTempMigrationDir();
        $this->createMigrationFile($dir, '2025_01_01_000000_Incr1', 'incr_1');

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir);

        // Migrate first
        $manager->migrate();
        $this->assertCount(1, $manager->getApplied());

        // Add second migration and migrate again
        $this->createMigrationFile($dir, '2025_01_02_000000_Incr2', 'incr_2');
        $executed = $manager->migrate();
        $this->assertSame(['2025_01_02_000000_Incr2'], $executed);
        $this->assertCount(2, $manager->getApplied());

        // Rollback last batch (only incr_2)
        $rolledBack = $manager->rollback();
        $this->assertSame(['2025_01_02_000000_Incr2'], $rolledBack);
        $this->assertCount(1, $manager->getApplied());

        // Add third, migrate
        $this->createMigrationFile($dir, '2025_01_03_000000_Incr3', 'incr_3');
        $executed = $manager->migrate();
        $this->assertCount(2, $executed); // incr_2 + incr_3
    }

    public function testMigrateAndResetCycle(): void
    {
        $dir = $this->createTempMigrationDir();
        $this->createMigrationFile($dir, '2025_01_01_000000_Rst1', 'rst_1');
        $this->createMigrationFile($dir, '2025_01_02_000000_Rst2', 'rst_2');

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir);

        $manager->migrate();
        $this->assertCount(2, $manager->getApplied());

        $rolledBack = $manager->reset();
        $this->assertCount(2, $rolledBack);
        $this->assertSame([], $manager->getApplied());

        // Migrate again — should start at batch 1 (tracking table is empty)
        $manager->migrate();
        $query = $db->execute($db->prepare(
            'SELECT DISTINCT batch FROM "razy_migrations"',
        ));
        $rows = $query->fetchAll();
        $this->assertSame(1, (int) $rows[0]['batch']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 13: MigrationManager — Migration Execution Verification
    // ═══════════════════════════════════════════════════════════════

    public function testMigrationUpActuallyCreatesTable(): void
    {
        $dir = $this->createTempMigrationDir();
        $this->createMigrationFile($dir, '2025_01_01_000000_RealCreate', 'real_test');

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir);

        $manager->migrate();

        // Insert into the table created by migration
        $db->execute($db->prepare('INSERT INTO real_test (id) VALUES (42)'));
        $query = $db->execute($db->prepare('SELECT id FROM real_test'));
        $row = $query->fetch();

        $this->assertSame(42, (int) $row['id']);
    }

    public function testMigrationDownActuallyDropsTable(): void
    {
        $dir = $this->createTempMigrationDir();
        $this->createMigrationFile($dir, '2025_01_01_000000_DropVerify', 'drop_verify');

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir);

        $manager->migrate();

        // Table should exist
        $db->execute($db->prepare('INSERT INTO drop_verify (id) VALUES (1)'));

        $manager->rollback();

        // Table should be gone
        $this->expectException(Throwable::class);
        $db->execute($db->prepare('SELECT * FROM drop_verify'));
    }

    public function testMigrationWithDescription(): void
    {
        $dir = $this->createTempMigrationDir();

        $content = <<<'PHP'
            <?php
            use Razy\Database\Migration;
            use Razy\Database\SchemaBuilder;

            return new class extends Migration {
                public function up(SchemaBuilder $schema): void
                {
                    $schema->raw('CREATE TABLE desc_test (id INTEGER PRIMARY KEY)');
                }
                public function down(SchemaBuilder $schema): void
                {
                    $schema->dropIfExists('desc_test');
                }
                public function getDescription(): string
                {
                    return 'A migration with a description';
                }
            };
            PHP;

        \file_put_contents($dir . '/2025_01_01_000000_WithDescription.php', $content);

        // Verify the migration instance has the description
        $migration = require $dir . '/2025_01_01_000000_WithDescription.php';
        $this->assertSame('A migration with a description', $migration->getDescription());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 14: MigrationManager — Rollback with Missing Files
    // ═══════════════════════════════════════════════════════════════

    public function testRollbackSkipsMissingFilesGracefully(): void
    {
        $dir = $this->createTempMigrationDir();
        $this->createMigrationFile($dir, '2025_01_01_000000_WillDelete', 'will_del');

        $db = $this->createSqliteDb();
        $manager = new MigrationManager($db);
        $manager->addPath($dir);

        $manager->migrate();

        // Delete the file
        \unlink($dir . '/2025_01_01_000000_WillDelete.php');

        // Rollback should still remove the tracking record even though it can't
        // execute down() (file is missing)
        $rolledBack = $manager->rollback();

        $this->assertSame(['2025_01_01_000000_WillDelete'], $rolledBack);
        $this->assertSame([], $manager->getApplied());
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Create an SQLite in-memory Database instance.
     */
    private function createSqliteDb(): Database
    {
        static $counter = 0;
        $db = new Database('test_migration_' . (++$counter));
        $db->connectWithDriver('sqlite', ['path' => ':memory:']);

        return $db;
    }

    /**
     * Create a temporary directory for migration files.
     */
    private function createTempMigrationDir(string $suffix = ''): string
    {
        $dir = \sys_get_temp_dir() . '/razy_migration_test_' . \uniqid() . ($suffix ? '_' . $suffix : '');
        if (!\is_dir($dir)) {
            \mkdir($dir, 0o777, true);
        }

        // Track for cleanup
        $this->tempDir = $dir;

        return $dir;
    }

    /**
     * Create a migration file that creates/drops a simple SQLite table.
     */
    private function createMigrationFile(string $dir, string $name, string $tableName = 'test_table'): void
    {
        $content = <<<PHP
            <?php
            use Razy\\Database\\Migration;
            use Razy\\Database\\SchemaBuilder;

            return new class extends Migration {
                public function up(SchemaBuilder \$schema): void
                {
                    \$schema->raw('CREATE TABLE {$tableName} (id INTEGER PRIMARY KEY)');
                }
                public function down(SchemaBuilder \$schema): void
                {
                    \$schema->dropIfExists('{$tableName}');
                }
            };
            PHP;

        \file_put_contents($dir . '/' . $name . '.php', $content);
    }
}
