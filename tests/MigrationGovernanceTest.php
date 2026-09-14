<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Razy\Controller;
use Razy\Database;
use Razy\Database\MigrationManager;
use Razy\Exception\DatabaseException;
use Razy\Module;
use Razy\ModuleInfo;

/**
 * M0+M1 (dossier MIGRATION-GOVERNANCE.md, all decisions 2026-09):
 * module-scoped tracking rows (E4: cross-module rollback corruption),
 * sha256 checksums with fail-loud drift detection (E3), self-healing
 * pre-M0 tables, and the Controller auto-scope wiring.
 */
#[CoversNothing]
class MigrationGovernanceTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    private Database $db;

    protected function setUp(): void
    {
        parent::setUp();

        static $counter = 0;
        $this->db = new Database('gov_' . (++$counter));
        $this->db->connectWithDriver('sqlite', ['database' => ':memory:']);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            foreach (\glob($dir . '/*') ?: [] as $f) {
                @\unlink($f);
            }

            @\rmdir($dir);
        }

        $this->tempDirs = [];
        parent::tearDown();
    }

    // ── M1: checksum ──────────────────────────────────────────────

    public function testApplyRecordsFileChecksum(): void
    {
        $dir = $this->migrationDir('2026_09_14_120000_GovAlpha', 'gov_a');
        $m = $this->manager();
        $m->addPath($dir);
        $m->migrate();

        $expected = \hash_file('sha256', "{$dir}/2026_09_14_120000_GovAlpha.php");
        $this->assertSame(['2026_09_14_120000_GovAlpha' => $expected], $m->getAppliedWithChecksum());
    }

    public function testEditedAppliedFileFailsLoud(): void
    {
        $dir = $this->migrationDir('2026_09_14_120001_GovBeta', 'gov_b');
        $m = $this->manager();
        $m->addPath($dir);
        $m->migrate();

        // the classic incident: someone edits history after the fact
        \file_put_contents("{$dir}/2026_09_14_120001_GovBeta.php", "<?php // tampered\n");

        $drift = $m->verifyChecksums();
        $this->assertArrayHasKey('2026_09_14_120001_GovBeta', $drift);
        $this->assertStringContainsString('checksum drift', $drift['2026_09_14_120001_GovBeta']);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('drifted');
        $m->migrate();
    }

    public function testForceIsTheOnlyWayThroughDrift(): void
    {
        $dir = $this->migrationDir('2026_09_14_120002_GovGamma', 'gov_g');
        $m = $this->manager();
        $m->addPath($dir);
        $m->migrate();
        \file_put_contents("{$dir}/2026_09_14_120002_GovGamma.php", "<?php // tampered\n");

        // force proceeds AND does not re-run applied work
        $this->assertSame([], $m->migrate(force: true));
    }

    public function testMissingAppliedFileIsDriftToo(): void
    {
        $dir = $this->migrationDir('2026_09_14_120003_GovDelta', 'gov_d');
        $m = $this->manager();
        $m->addPath($dir);
        $m->migrate();
        \unlink("{$dir}/2026_09_14_120003_GovDelta.php");

        $this->assertStringContainsString('missing', $m->verifyChecksums()['2026_09_14_120003_GovDelta'] ?? '');
    }

    public function testLegacyUnchecksummedRowsAreSkippedNotGuessed(): void
    {
        $dir = $this->migrationDir('2026_09_14_120004_GovEps', 'gov_e');
        $m = $this->manager();
        $m->addPath($dir);
        $m->migrate();

        $this->db->execute($this->db->prepare('UPDATE ' . MigrationManager::TRACKING_TABLE . " SET checksum = ''"));
        \file_put_contents("{$dir}/2026_09_14_120004_GovEps.php", "<?php // tampered\n");

        $this->assertSame([], $m->verifyChecksums(), 'empty checksum = unverifiable, never guessed');
        $this->assertSame([], $m->migrate(), 'legacy row still counts as applied');
    }

    // ── M0: scope ─────────────────────────────────────────────────

    public function testScopesTrackIndependentlyOnSharedDatabase(): void
    {
        $dirA = $this->migrationDir('2026_09_14_130000_Apple', 'gov_apple');
        $dirB = $this->migrationDir('2026_09_14_130001_Banana', 'gov_banana');

        $a = $this->manager('vendor/a');
        $a->addPath($dirA);
        $b = $this->manager('vendor/b');
        $b->addPath($dirB);

        $a->migrate();

        $this->assertSame(['2026_09_14_130000_Apple'], $a->getApplied());
        $this->assertSame([], $b->getApplied(), 'B cannot see A applied rows (and vice versa)');
        $this->assertCount(1, $b->getPending());

        $b->migrate();
        $this->assertCount(2, $this->trackingPdoRows());
    }

    public function testRollbackCannotDeleteAnotherModulesHistoryAcrossBatches(): void
    {
        // The exact E4 scenario: A migrates twice (batches 1,2), B once
        // (batch 3). B rollback(2) must touch ONLY B rows. Pre-M0 this
        // selected batch 3 AND 2 - B paths could not resolve A second
        // migration, the missing-file skip deleted the row anyway, and A
        // tables remained WITHOUT history.
        $dirA1 = $this->migrationDir('2026_09_14_140000_First', 'gov_f');
        $dirB = $this->migrationDir('2026_09_14_140001_Bside', 'gov_bs');

        $aFirst = $this->manager('mod/a');
        $aFirst->addPath($dirA1);
        $aFirst->migrate();

        // A second migration in its own dir (new batch)
        $aSecondDir = $this->migrationDir('2026_09_14_140002_Second', 'gov_s');
        $aSecond = $this->manager('mod/a');
        $aSecond->addPath($dirA1);
        $aSecond->addPath($aSecondDir);
        $aSecond->migrate();

        $b = $this->manager('mod/b');
        $b->addPath($dirB);
        $b->migrate();

        $rolled = $b->rollback(2);

        $this->assertSame(['2026_09_14_140001_Bside'], $rolled, 'B rolls back only its own scope');
        $this->assertSame(
            ['2026_09_14_140000_First', '2026_09_14_140002_Second'],
            $aSecond->getApplied(),
            'A history survives intact (E4 regression pin)',
        );

        $exists = $this->db->execute($this->db->prepare("SELECT name FROM sqlite_master WHERE name IN ('gov_f','gov_s')"))->fetchAll();
        $this->assertCount(2, $exists, 'A tables untouched by B rollback');
    }

    public function testSameFileNameDifferentScopesCoexist(): void
    {
        $name = '2026_09_14_150000_SameName';
        $dirA = $this->migrationDir($name, 'gov_n1');

        // same file NAME, different content/table, different module dir
        $dirB = \sys_get_temp_dir() . '/razy_gov_' . \bin2hex(\random_bytes(6));
        \mkdir($dirB, 0o777, true);
        $this->tempDirs[] = $dirB;
        \file_put_contents("{$dirB}/{$name}.php", '<?php' . "\n"
            . 'return new class extends \Razy\Database\Migration {' . "\n"
            . '    public function up(\Razy\Database\SchemaBuilder $schema): void { $schema->raw(\'CREATE TABLE gov_n2 (id INTEGER PRIMARY KEY)\'); }' . "\n"
            . '    public function down(\Razy\Database\SchemaBuilder $schema): void { $schema->dropIfExists(\'gov_n2\'); }' . "\n"
            . '};' . "\n");

        $a = $this->manager('mod/a');
        $a->addPath($dirA);
        $b = $this->manager('mod/b');
        $b->addPath($dirB);

        $a->migrate();
        $b->migrate();

        $this->assertSame([$name], $a->getApplied());
        $this->assertSame([$name], $b->getApplied());
        $this->assertCount(2, $this->trackingPdoRows(), 'one row per scope, no UNIQUE collision (no UNIQUE existed, none invented)');

        $a->reset();

        $this->assertSame([], $a->getApplied());
        $this->assertSame([$name], $b->getApplied(), 'removeMigrationRecord is scope-filtered too');
    }

    // ── self-heal ─────────────────────────────────────────────────

    public function testLegacyTrackingTableGainsColumnsWithoutLosingRows(): void
    {
        // pre-M0 shape, created by hand
        $this->db->execute($this->db->prepare(
            'CREATE TABLE ' . MigrationManager::TRACKING_TABLE . ' ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, migration VARCHAR(255) NOT NULL, '
            . 'batch INTEGER NOT NULL, executed_at DATETIME DEFAULT CURRENT_TIMESTAMP)',
        ));
        $this->db->execute($this->db->prepare('INSERT INTO ' . MigrationManager::TRACKING_TABLE . " (migration, batch) VALUES ('legacy_one', 1)"));

        $legacy = $this->manager('');
        $scoped = $this->manager('vendor/x');

        $this->assertSame(['legacy_one'], $legacy->getApplied(), 'legacy rows visible to scope-\'\' manager (backward compat)');
        $this->assertSame([], $scoped->getApplied(), 'scoped manager does NOT silently adopt legacy rows (strict M0)');

        $rows = $this->trackingPdoRows();
        $this->assertSame('', $rows[0]['scope'], 'legacy row untouched, scope defaults to \'\'');
        $this->assertSame('', $rows[0]['checksum']);
    }

    // ── Controller wiring ─────────────────────────────────────────

    public function testControllerManagerIsScopedByModuleCode(): void
    {
        // getMigrationManager appends 'migration/' to the MODULE path, so the
        // mock path is a base dir with a real migration/ subdir inside.
        $base = \sys_get_temp_dir() . '/razy_gov_base_' . \bin2hex(\random_bytes(6));
        \mkdir($base . '/migration', 0o777, true);
        $this->tempDirs[] = $base . '/migration';
        $this->tempDirs[] = $base;

        $name = '2026_09_14_160000_Wire';
        \file_put_contents("{$base}/migration/{$name}.php", '<?php' . "\n"
            . 'return new class extends \Razy\Database\Migration {' . "\n"
            . '    public function up(\Razy\Database\SchemaBuilder $schema): void { $schema->raw(\'CREATE TABLE gov_w (id INTEGER PRIMARY KEY)\'); }' . "\n"
            . '    public function down(\Razy\Database\SchemaBuilder $schema): void { $schema->dropIfExists(\'gov_w\'); }' . "\n"
            . '};' . "\n");

        $info = $this->createMock(ModuleInfo::class);
        $info->method('getPath')->willReturn($base);
        $info->method('getCode')->willReturn('vendor/gov');

        $module = $this->createMock(Module::class);
        $module->method('getModuleInfo')->willReturn($info);

        $controller = new class($module) extends Controller {};

        $manager = $controller->getMigrationManager($this->db);

        $this->assertSame('vendor/gov', $manager->getScope(), 'module code is the tracking scope (E4 floor)');
        $this->assertSame([$name], \array_keys($manager->getPending()), 'module migration/ dir pre-registered as before');
    }

    // ── M4: fast-path manifest ────────────────────────────────────

    public function testRepeatPassTakesTheManifestFastPath(): void
    {
        $dir = $this->migrationDir('2026_09_15_120000_FastOne', 'gov_f1');

        $first = $this->manager('mod/fast');
        $first->addPath($dir);
        $before = $this->db->getTotalQueryCount();
        $this->assertCount(1, $first->migrate());
        $fullPass = $this->db->getTotalQueryCount() - $before;

        // fresh instance (CLI reality): ensure-DDL + ONE meta SELECT, no
        // applied-rows scan, no verification hashing pass
        $second = $this->manager('mod/fast');
        $second->addPath($dir);
        $before = $this->db->getTotalQueryCount();
        $this->assertSame([], $second->migrate());
        $fastPass = $this->db->getTotalQueryCount() - $before;

        $this->assertLessThan($fullPass, $fastPass, 'no-op pass costs strictly fewer queries than the real pass');
    }

    public function testContentEditAlwaysInvalidatesTheFastPath(): void
    {
        $dir = $this->migrationDir('2026_09_15_120001_FastTwo', 'gov_f2');
        $m = $this->manager('mod/fast2');
        $m->addPath($dir);
        $m->migrate();

        \file_put_contents("{$dir}/2026_09_15_120001_FastTwo.php", "<?php // tampered\n");

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('drifted');
        $m->migrate(); // manifest mismatch -> full path -> verification catches it
    }

    public function testRollbackInvalidatesTheManifest(): void
    {
        $dir = $this->migrationDir('2026_09_15_120002_FastThree', 'gov_f3');
        $m = $this->manager('mod/fast3');
        $m->addPath($dir);
        $name = $m->migrate()[0];

        $m->rollback(1);

        $this->assertSame([$name], $m->migrate(), 'rolled-back work resurfaces — never hidden by a stale manifest');
    }

    public function testForceNeitherTrustsNorStoresTheManifest(): void
    {
        $dir = $this->migrationDir('2026_09_15_120003_FastFour', 'gov_f4');
        $m = $this->manager('mod/fast4');
        $m->addPath($dir);
        $m->migrate();
        \file_put_contents("{$dir}/2026_09_15_120003_FastFour.php", "<?php // tampered\n");

        $this->assertSame([], $m->migrate(force: true), 'force escapes drift');
        $this->assertSame([], $m->migrate(force: true), 'twice, without re-running');

        $this->expectException(DatabaseException::class);
        $m->migrate(); // and STILL fails afterwards: force did not normalize the drift into a manifest
    }

    public function testEmptyDiscoveryStoresNoManifestAndStaysSafe(): void
    {
        $dir = \sys_get_temp_dir() . '/razy_gov_empty_' . \bin2hex(\random_bytes(6));
        \mkdir($dir, 0o777, true);
        $this->tempDirs[] = $dir;

        $m = $this->manager('mod/empty');
        $m->addPath($dir);

        $this->assertSame([], $m->migrate());
        $this->assertSame([], $m->migrate());
    }

    public function testMigrationDeclarationParses(): void
    {
        $this->assertSame('deploy', $this->moduleInfoWith(['migration' => 'deploy'])->getMigrationMode());
        $this->assertSame('manual', $this->moduleInfoWith(['migration' => 'manual'])->getMigrationMode());
        $this->assertSame('manual', $this->moduleInfoWith([])->getMigrationMode(), 'default = historic behaviour');
        $this->assertSame('', $this->moduleInfoWith([])->getMigrationDeclared());
    }

    public function testSuspectDeclarationDegradesToManualButStaysVisible(): void
    {
        $info = $this->moduleInfoWith(['migration' => 'deply']); // the typo class

        $this->assertSame('manual', $info->getMigrationMode(), 'safe side, never auto-apply on a typo');
        $this->assertSame('deply', $info->getMigrationDeclared(), '...and the CLI can still surface the suspect value');
    }

    // ── M3: package declaration ───────────────────────────────────

    /**
     * @param array<string, mixed> $packageKeys extra package.php keys
     */
    private function moduleInfoWith(array $packageKeys): ModuleInfo
    {
        $base = \sys_get_temp_dir() . '/razy_gov_mod_' . \bin2hex(\random_bytes(6));
        \mkdir($base . '/default', 0o777, true);
        $this->tempDirs[] = $base . '/default';
        $this->tempDirs[] = $base;

        \file_put_contents("{$base}/default/package.php", '<?php return ' . \var_export(\array_merge(['name' => 'GovDecl', 'version' => '0.1.0', 'author' => 'gov'], $packageKeys), true) . ';');

        // site-level config supplies code/author; package.php (disk) supplies
        // the 'migration' declaration — ModuleInfo.php:177/187 verbatim
        return new ModuleInfo($base, ['module_code' => 'test/govdecl', 'author' => 'gov', 'description' => 'x'], 'default');
    }

    /**
     * Create a temp migration dir holding one minimal migration file.
     */
    private function migrationDir(string $name, string $table = 'gov_t'): string
    {
        $dir = \sys_get_temp_dir() . '/razy_gov_' . \bin2hex(\random_bytes(6));
        \mkdir($dir, 0o777, true);
        $this->tempDirs[] = $dir;

        $body = '<?php' . "\n"
            . 'return new class extends \Razy\Database\Migration {' . "\n"
            . '    public function up(\Razy\Database\SchemaBuilder $schema): void { $schema->raw(\'CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY)\'); }' . "\n"
            . '    public function down(\Razy\Database\SchemaBuilder $schema): void { $schema->dropIfExists(\'' . $table . '\'); }' . "\n"
            . '};' . "\n";
        \file_put_contents("{$dir}/{$name}.php", $body);

        return $dir;
    }

    private function manager(string $scope = ''): MigrationManager
    {
        return new MigrationManager($this->db, $scope);
    }

    private function trackingPdoRows(): array
    {
        return $this->db
            ->execute($this->db->prepare('SELECT migration, batch, scope, checksum FROM ' . MigrationManager::TRACKING_TABLE . ' ORDER BY id ASC'))
            ->fetchAll();
    }
}
