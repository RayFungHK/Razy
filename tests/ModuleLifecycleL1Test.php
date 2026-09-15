<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Razy\Database;
use Razy\Database\MigrationManager;
use Razy\Distributor;
use Razy\Distributor\ModuleRegistry;
use Razy\Exception\DatabaseException;
use Razy\Module;
use Razy\Module\ModuleStatus;
use Razy\ModuleInfo;
use ReflectionClass;
use ReflectionProperty;

/**
 * MODULE-LIFECYCLE.md L1: derived readiness. `ready := declared migrations
 * all applied` — computed from the ledger, never stored. Plus the provision
 * declaration, the enable-list door, and the Disabled enum's first assignment.
 */
#[CoversNothing]
final class ModuleLifecycleL1Test extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    private Database $db;

    protected function setUp(): void
    {
        parent::setUp();

        static $counter = 0;
        $this->db = new Database('l1_' . (++$counter));
        $this->db->connectWithDriver('sqlite', ['database' => ':memory:']);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            foreach (\glob($dir . '/*') ?: [] as $f) {
                if (\is_file($f)) {
                    @\unlink($f);
                }
            }

            @\rmdir($dir);
        }

        $this->tempDirs = [];
        parent::tearDown();
    }

    // ── provision declaration ─────────────────────────────────────────────

    public function testProvisionDefaultsToDeployWhenUndeclared(): void
    {
        $info = $this->moduleInfo([]);

        self::assertSame('deploy', $info->getProvision(), 'the default IS the M3 policy: web never migrates');
        self::assertSame('', $info->getProvisionDeclared(), 'undeclared is distinguishable from declared');
    }

    public function testProvisionWizardIsEchoedAndIsAKnownKey(): void
    {
        $info = $this->moduleInfo(['provision' => 'wizard']);

        self::assertSame('wizard', $info->getProvision());
        self::assertSame([], \array_diff($info->getPackageKeys(), ModuleInfo::PACKAGE_KEYS), "L0's placeholder note promised 'provision' joins at L1");
    }

    public function testSuspectProvisionDegradesToDeployButStaysVisible(): void
    {
        $info = $this->moduleInfo(['provision' => 'wizar']);

        self::assertSame('deploy', $info->getProvision(), 'unknown values take the web-never-migrates side');
        self::assertSame('wizar', $info->getProvisionDeclared(), 'validate can still surface the typo');
    }

    // ── the Disabled enum resurrection ────────────────────────────────────

    public function testDisableFlipsStatusToTheFormerlyDeadEnumCase(): void
    {
        $module = (new ReflectionClass(Module::class))->newInstanceWithoutConstructor();
        $status = new ReflectionProperty(Module::class, 'status');
        $status->setValue($module, ModuleStatus::Pending);

        $module->disable();

        self::assertSame(ModuleStatus::Disabled, $status->getValue($module));
    }

    // ── MigrationManager::isUpToDate (the ledger answer) ──────────────────

    public function testUpToDateFollowsTheLedgerNotAnyFlag(): void
    {
        $dir = $this->migrationDir('2026_09_18_120000_L1Alpha', 'l1_t');
        $manager = new MigrationManager($this->db, 'test/l1alpha');
        $manager->addPath($dir);

        self::assertFalse($manager->isUpToDate(), 'pending migration = not ready');

        $manager->migrate();
        self::assertTrue($manager->isUpToDate(), 'applied = ready, derived from the ledger');

        $this->addMigrationFile($dir, '2026_09_18_120100_L1Beta', 'l1_t2');
        self::assertFalse($manager->isUpToDate(), 'a new file re-opens readiness — the memo is per-process, the ledger is truth');
    }

    public function testUpToDateIsVacuouslyTrueForEmptyScope(): void
    {
        $manager = new MigrationManager($this->db, 'test/l1empty');
        self::assertTrue($manager->isUpToDate(), 'nothing declared, nothing to wait for');
    }

    // ── Distributor::moduleReady (the predicate) ──────────────────────────

    public function testModuleReadyIsFalseForAbsentAndDisabled(): void
    {
        $distributor = $this->distributorWith(['absent/mod' => null, 'off/mod' => $this->moduleWithStatus('off/mod', ModuleStatus::Disabled)]);

        self::assertFalse($distributor->moduleReady('absent/mod'));
        self::assertFalse($distributor->moduleReady('off/mod'), 'disabled is not ready, and says so without throwing');
    }

    public function testModuleReadyIsFalseForAModuleBlockedMidRequire(): void
    {
        // The shape the L2 dogfood caught: standby() already ran (Processing)
        // when a peer's require failed — a blacklist of "bad states" passed
        // this through and showed READY=yes beside a NOT-loaded warning.
        // Only InQueue/Loaded may answer; that is why the gate is a whitelist.
        $blocked = $this->moduleWithStatus('test/blocked', ModuleStatus::Processing);
        $pending = $this->moduleWithStatus('test/pending', ModuleStatus::Pending);
        $distributor = $this->distributorWith(['test/blocked' => $blocked, 'test/pending' => $pending]);

        self::assertFalse($distributor->moduleReady('test/blocked'));
        self::assertFalse($distributor->moduleReady('test/pending'));
    }

    public function testModuleReadyIsVacuouslyTrueForLoadedModuleWithoutMigrations(): void
    {
        $dir = \sys_get_temp_dir() . '/razy_l1nomig_' . \bin2hex(\random_bytes(5));
        \mkdir($dir, 0o777, true);
        $this->tempDirs[] = $dir;

        $distributor = $this->distributorWith(['test/ok' => $this->moduleWithStatus('test/ok', ModuleStatus::Loaded, $dir)]);

        self::assertTrue($distributor->moduleReady('test/ok'));
    }

    public function testModuleReadyFailsLoudWhenTheLedgerIsUnreachable(): void
    {
        $dir = \sys_get_temp_dir() . '/razy_l1db_' . \bin2hex(\random_bytes(5));
        \mkdir($dir . '/migration', 0o777, true);
        $this->tempDirs[] = $dir . '/migration';
        $this->tempDirs[] = $dir;

        // Loaded + a migration dir + no config-connect database => the ERP's
        // six-installed-flags disease is prevented exactly here: no quiet false.
        $module = $this->moduleWithStatus('test/nodb', ModuleStatus::Loaded, $dir);
        $distributor = $this->distributorWith(['test/nodb' => $module]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('no config-connect database declared');

        $distributor->moduleReady('test/nodb');
    }

    // ── source pins: the doors are wired, not hinted ──────────────────────

    public function testEnableListAndDisableAreWiredIntoBoot(): void
    {
        $source = (string) \file_get_contents(SYSTEM_ROOT . '/src/library/Razy/Distributor.php');
        self::assertStringContainsString('$this->applyEnableList();', $source, 'enable-list runs right after scan');
        self::assertStringContainsString("config', \$this->code, 'modules.php'", $source, 'the Q5 path, absent = all enabled');
        self::assertStringContainsString('names no module in dist', $source, 'zombie enable-list entries warn');
        self::assertStringContainsString('ModuleStatus::Disabled) {' . "\n" . '                continue; // operator-disabled', $source, 'disabled modules never enter require()');
        self::assertStringContainsString('$reqModule->getStatus() === ModuleStatus::Disabled', $source, 'a disabled dependency blocks its dependents like a Failed one');
    }

    public function testReadinessPredicatePlumbingIsPinned(): void
    {
        $source = (string) \file_get_contents(SYSTEM_ROOT . '/src/library/Razy/Distributor.php');
        self::assertStringContainsString('readinessMemo', $source, 'memo is in-process only');
        self::assertStringContainsString("ModuleDatabaseConnector::connect(\$module, \$code, 'module_ready')", $source, 'ONE connector door, distinct instance name');
        self::assertStringContainsString('$manager->isUpToDate()', $source);

        $connector = (string) \file_get_contents(SYSTEM_ROOT . '/src/library/Razy/Database/ModuleDatabaseConnector.php');
        self::assertStringContainsString('ONE config-connect database resolution policy', $connector);

        $validate = (string) \file_get_contents(SYSTEM_ROOT . '/src/system/terminal/validate.inc.php');
        self::assertStringContainsString('Suspect provision declaration', $validate);
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $packageKeys
     */
    private function moduleInfo(array $packageKeys): ModuleInfo
    {
        $base = \sys_get_temp_dir() . '/razy_l1info_' . \bin2hex(\random_bytes(5));
        \mkdir($base . '/default', 0o777, true);
        $this->tempDirs[] = $base . '/default';
        $this->tempDirs[] = $base;

        \file_put_contents(
            $base . '/default/package.php',
            '<?php return ' . \var_export(\array_merge(['name' => 'L1', 'version' => '0.1.0', 'author' => 'l1'], $packageKeys), true) . ';',
        );

        return new ModuleInfo($base, ['module_code' => 'test/l1', 'author' => 'l1', 'description' => 'x'], 'default');
    }

    private function moduleWithStatus(string $code, ModuleStatus $status, ?string $path = null): Module
    {
        $info = $this->createMock(ModuleInfo::class);
        $info->method('getCode')->willReturn($code);
        $info->method('getPath')->willReturn($path ?? \sys_get_temp_dir() . '/razy_l1_missing_' . $code);

        $module = $this->createMock(Module::class);
        $module->method('getStatus')->willReturn($status);
        $module->method('getModuleInfo')->willReturn($info);

        return $module;
    }

    /**
     * @param array<string, Module|null> $modules
     */
    private function distributorWith(array $modules): Distributor
    {
        $distributor = (new ReflectionClass(Distributor::class))->newInstanceWithoutConstructor();

        $registry = $this->createMock(ModuleRegistry::class);
        $registry->method('get')->willReturnCallback(
            static fn (string $code) => $modules[$code] ?? null,
        );

        $prop = new ReflectionProperty(Distributor::class, 'registry');
        $prop->setValue($distributor, $registry);

        return $distributor;
    }

    private function migrationDir(string $name, string $table = 'l1_t'): string
    {
        $dir = \sys_get_temp_dir() . '/razy_l1mig_' . \bin2hex(\random_bytes(5));
        \mkdir($dir, 0o777, true);
        $this->tempDirs[] = $dir;

        $this->addMigrationFile($dir, $name, $table);

        return $dir;
    }

    private function addMigrationFile(string $dir, string $name, string $table): void
    {
        $body = '<?php' . "\n"
            . 'return new class extends \Razy\Database\Migration {' . "\n"
            . '    public function up(\Razy\Database\SchemaBuilder $schema): void { $schema->raw(\'CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY)\'); }' . "\n"
            . '    public function down(\Razy\Database\SchemaBuilder $schema): void { $schema->dropIfExists(\'' . $table . '\'); }' . "\n"
            . '};' . "\n";
        \file_put_contents("{$dir}/{$name}.php", $body);
    }
}
