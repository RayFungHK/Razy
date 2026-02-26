<?php

declare(strict_types=1);

namespace Razy\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Controller;
use Razy\Database;
use Razy\Database\MigrationManager;
use Razy\Module;
use Razy\ModuleInfo;
use ReflectionClass;

/**
 * Tests for Controller::getMigrationManager().
 *
 * Validates that the Controller correctly wires the module's migration/
 * directory into a MigrationManager and that migrations can be executed,
 * rolled back, and queried through it.
 */
#[CoversClass(Controller::class)]
class ControllerMigrationTest extends TestCase
{
    private string $tempDir;

    /** @var string[] Temp dirs to clean up */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_ctrl_migration_test_' . \uniqid();
        \mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'migration', 0o777, true);
        $this->tempDirs[] = $this->tempDir;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }
        parent::tearDown();
    }

    // ───────────────────────────────────────────────────────────────
    // Factory: getMigrationManager()
    // ───────────────────────────────────────────────────────────────

    public function testGetMigrationManagerReturnsManager(): void
    {
        $controller = $this->createController();
        $db = $this->createDb();

        $manager = $controller->getMigrationManager($db);

        $this->assertInstanceOf(MigrationManager::class, $manager);
    }

    public function testMigrationManagerHasModulePath(): void
    {
        $controller = $this->createController();
        $db = $this->createDb();

        $manager = $controller->getMigrationManager($db);

        $paths = $manager->getPaths();
        $this->assertCount(1, $paths);
        $this->assertStringContainsString('migration', $paths[0]);
    }

    public function testThrowsWhenMigrationDirectoryMissing(): void
    {
        // Create a temp dir WITHOUT a migration/ subfolder
        $emptyDir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_ctrl_no_migration_' . \uniqid();
        \mkdir($emptyDir, 0o777, true);
        $this->tempDirs[] = $emptyDir;

        $controller = $this->createController($emptyDir);
        $db = $this->createDb();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Migration directory not found');

        $controller->getMigrationManager($db);
    }

    // ───────────────────────────────────────────────────────────────
    // Discovery
    // ───────────────────────────────────────────────────────────────

    public function testDiscoversMigrationFiles(): void
    {
        $this->writeMigrationFile('2026_02_24_100000_CreateUsers', 'users');
        $this->writeMigrationFile('2026_02_24_100001_CreatePosts', 'posts');

        $controller = $this->createController();
        $db = $this->createDb();
        $manager = $controller->getMigrationManager($db);

        $discovered = $manager->discover();

        $this->assertCount(2, $discovered);
        $this->assertArrayHasKey('2026_02_24_100000_CreateUsers', $discovered);
        $this->assertArrayHasKey('2026_02_24_100001_CreatePosts', $discovered);
    }

    public function testDiscoverReturnsEmptyForNoFiles(): void
    {
        $controller = $this->createController();
        $db = $this->createDb();
        $manager = $controller->getMigrationManager($db);

        $discovered = $manager->discover();

        $this->assertCount(0, $discovered);
    }

    // ───────────────────────────────────────────────────────────────
    // Migrate (up)
    // ───────────────────────────────────────────────────────────────

    public function testMigrateRunsPendingMigrations(): void
    {
        $this->writeMigrationFile('2026_02_24_100000_CreateItems', 'items');

        $controller = $this->createController();
        $db = $this->createDb();
        $manager = $controller->getMigrationManager($db);

        $applied = $manager->migrate();

        $this->assertCount(1, $applied);
        $this->assertContains('2026_02_24_100000_CreateItems', $applied);
        $this->assertTrue($db->isTableExists('items'), 'Table "items" should exist after migration');
    }

    public function testMigrateMultipleInOrder(): void
    {
        $this->writeMigrationFile('2026_02_24_100000_CreateAlpha', 'alpha');
        $this->writeMigrationFile('2026_02_24_100001_CreateBeta', 'beta');

        $controller = $this->createController();
        $db = $this->createDb();
        $manager = $controller->getMigrationManager($db);

        $applied = $manager->migrate();

        $this->assertCount(2, $applied);
        $this->assertTrue($db->isTableExists('alpha'));
        $this->assertTrue($db->isTableExists('beta'));
    }

    public function testMigrateSkipsAlreadyApplied(): void
    {
        $this->writeMigrationFile('2026_02_24_100000_CreateWidgets', 'widgets');

        $controller = $this->createController();
        $db = $this->createDb();
        $manager = $controller->getMigrationManager($db);

        $first = $manager->migrate();
        $this->assertCount(1, $first);

        // Re-create manager (simulating a fresh request)
        $manager2 = $controller->getMigrationManager($db);
        $second = $manager2->migrate();

        $this->assertCount(0, $second, 'No migrations should be applied on second run');
    }

    // ───────────────────────────────────────────────────────────────
    // Rollback (down)
    // ───────────────────────────────────────────────────────────────

    public function testRollbackLastBatch(): void
    {
        $this->writeMigrationFile('2026_02_24_100000_CreateLogs', 'logs');

        $controller = $this->createController();
        $db = $this->createDb();
        $manager = $controller->getMigrationManager($db);

        $manager->migrate();
        $this->assertTrue($db->isTableExists('logs'));

        // Re-create manager for rollback (same DB state via tracking table)
        $manager2 = $controller->getMigrationManager($db);
        $rolledBack = $manager2->rollback();

        $this->assertCount(1, $rolledBack);
        $this->assertFalse($db->isTableExists('logs'), 'Table should be dropped after rollback');
    }

    // ───────────────────────────────────────────────────────────────
    // Status
    // ───────────────────────────────────────────────────────────────

    public function testGetStatusShowsPendingAndApplied(): void
    {
        $this->writeMigrationFile('2026_02_24_100000_CreateFoo', 'foo');
        $this->writeMigrationFile('2026_02_24_100001_CreateBar', 'bar');

        $controller = $this->createController();
        $db = $this->createDb();
        $manager = $controller->getMigrationManager($db);

        // Before migration: both pending (applied === false)
        $status = $manager->getStatus();
        $this->assertCount(2, $status);
        foreach ($status as $entry) {
            $this->assertFalse($entry['applied'], 'Should be pending before migrate');
            $this->assertNull($entry['batch']);
        }

        // Run all migrations
        $manager->migrate();

        // Re-create manager to get fresh state
        $manager2 = $controller->getMigrationManager($db);
        $status2 = $manager2->getStatus();

        $this->assertCount(2, $status2);
        foreach ($status2 as $entry) {
            $this->assertTrue($entry['applied'], 'Both migrations should be applied');
            $this->assertSame(1, $entry['batch']);
        }
    }

    // ───────────────────────────────────────────────────────────────
    // Reset
    // ───────────────────────────────────────────────────────────────

    public function testResetRollsBackEverything(): void
    {
        $this->writeMigrationFile('2026_02_24_100000_CreateAA', 'aa_table');
        $this->writeMigrationFile('2026_02_24_100001_CreateBB', 'bb_table');

        $controller = $this->createController();
        $db = $this->createDb();
        $manager = $controller->getMigrationManager($db);

        $manager->migrate();
        $this->assertTrue($db->isTableExists('aa_table'));
        $this->assertTrue($db->isTableExists('bb_table'));

        $manager2 = $controller->getMigrationManager($db);
        $reset = $manager2->reset();

        $this->assertCount(2, $reset);
        $this->assertFalse($db->isTableExists('aa_table'));
        $this->assertFalse($db->isTableExists('bb_table'));
    }

    // ───────────────────────────────────────────────────────────────
    // Integration: model + migration together
    // ───────────────────────────────────────────────────────────────

    public function testMigrationAndModelWorkflowIntegration(): void
    {
        // Set up both model/ and migration/ dirs
        \mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'model', 0o777, true);

        // Write a migration
        $this->writeMigrationFile('2026_02_24_100000_CreateProducts', 'products');

        // Write a model
        \file_put_contents(
            $this->tempDir . DIRECTORY_SEPARATOR . 'model' . DIRECTORY_SEPARATOR . 'Product.php',
            '<?php
use Razy\ORM\Model;
return new class extends Model {
    protected static string $table = "products";
    protected static array $fillable = ["name"];
};',
        );

        $controller = $this->createController();
        $db = $this->createDb();

        // Run migration
        $manager = $controller->getMigrationManager($db);
        $applied = $manager->migrate();
        $this->assertCount(1, $applied);
        $this->assertTrue($db->isTableExists('products'));

        // Load model
        $Product = $controller->loadModel('Product');
        $this->assertSame('products', $Product::resolveTable());
    }

    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach (\scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            \is_dir($path) ? $this->removeDirectory($path) : @\unlink($path);
        }
        @\rmdir($dir);
    }

    /**
     * Create a Controller with a mocked Module whose ModuleInfo::getPath()
     * returns the given temp directory.
     */
    private function createController(?string $path = null): Controller
    {
        $moduleInfo = $this->createMock(ModuleInfo::class);
        $moduleInfo->method('getPath')->willReturn($path ?? $this->tempDir);

        $module = $this->createMock(Module::class);
        $module->method('getModuleInfo')->willReturn($moduleInfo);

        return (new ReflectionClass(Controller::class))->newInstance($module);
    }

    /**
     * Create an SQLite in-memory Database instance.
     */
    private function createDb(): Database
    {
        static $counter = 0;
        $db = new Database('ctrl_migration_' . (++$counter));
        $db->connectWithDriver('sqlite', ['path' => ':memory:']);

        return $db;
    }

    /**
     * Write a migration file into the temp migration/ directory.
     */
    private function writeMigrationFile(string $filename, string $tableName = 'test_table'): void
    {
        $content = <<<PHP
            <?php
            use Razy\\Database\\Migration;
            use Razy\\Database\\SchemaBuilder;

            return new class extends Migration {
                public function up(SchemaBuilder \$schema): void {
                    \$schema->raw('CREATE TABLE {$tableName} (id INTEGER PRIMARY KEY, name TEXT)');
                }
                public function down(SchemaBuilder \$schema): void {
                    \$schema->dropIfExists('{$tableName}');
                }
                public function getDescription(): string {
                    return 'Create {$tableName} table';
                }
            };
            PHP;

        \file_put_contents(
            $this->tempDir . DIRECTORY_SEPARATOR . 'migration' . DIRECTORY_SEPARATOR . $filename . '.php',
            $content,
        );
    }
}
