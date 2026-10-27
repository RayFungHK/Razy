<?php

declare(strict_types=1);

namespace Razy\Tests;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Distributor;
use Razy\Distributor\ModuleVersionGuard;
use Razy\Distributor\ModuleVersionResolver;
use Razy\Exception\ConfigurationException;
use Razy\Exception\ModuleConfigException;
use Razy\Exception\ModuleVersionException;
use Razy\Module\ModuleStatus;
use Razy\ModuleInfo;
use Razy\Util\PathUtil;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * RZ-35: module versions per distributor.
 *
 * One module tree (`rz35/engine` with default/ = 1.0.0, 1.4.0/, 2.0.0/, 2.1.0/)
 * is shared by several dists through dist.php 'module_path'; each dist pins a
 * version in dist.php 'modules'.
 */
#[CoversClass(ModuleVersionResolver::class)]
#[CoversClass(ModuleVersionGuard::class)]
final class ModuleVersionsTest extends TestCase
{
    private string $suffix;

    private string $moduleRoot;

    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        ModuleVersionGuard::reset();
        $GLOBALS['rz35_init'] = [];
        $this->suffix = \substr(\md5(\uniqid('', true)), 0, 8);
        $this->moduleRoot = PathUtil::append(SITES_FOLDER, 'rz35mods' . $this->suffix);
        $this->cleanup[] = $this->moduleRoot;

        $this->makeModule('rz35/engine', 'default', ['version' => '1.0.0', 'alias' => 'engine']);
        foreach (['1.4.0', '2.0.0', '2.1.0'] as $version) {
            $this->makeModule('rz35/engine', $version, ['alias' => 'engine']);
        }
    }

    protected function tearDown(): void
    {
        ModuleVersionGuard::reset();
        unset($GLOBALS['rz35_init']);
        foreach ($this->cleanup as $dir) {
            $this->removeDir($dir);
        }
    }

    // ── two dists, two versions ────────────────────────────────────────────

    public function testTwoDistsRunTwoVersionsOfOneModule(): void
    {
        $stable = $this->makeDist('stable', ['*' => ['rz35/engine' => '1.4.0']]);
        $next = $this->makeDist('next', ['*' => ['rz35/engine' => '^2.0']]);

        $a = (new Distributor($stable))->initialize();
        $engineA = $a->getRegistry()->get('rz35/engine');
        self::assertNotNull($engineA);
        self::assertSame('1.4.0', $engineA->getModuleInfo()->getVersion());
        self::assertSame('1.4.0', $engineA->getModuleInfo()->getVersionFolder());
        self::assertSame('webassets/engine/1.4.0/', $engineA->getModuleInfo()->getAssetPath());
        self::assertSame(ModuleStatus::Loaded, $engineA->getStatus());

        // The second dist runs in its own process (worker pool) in production.
        ModuleVersionGuard::reset();

        $b = (new Distributor($next))->initialize();
        $engineB = $b->getRegistry()->get('rz35/engine');
        self::assertNotNull($engineB);
        self::assertSame('2.1.0', $engineB->getModuleInfo()->getVersion(), '^2.0 resolves to the highest 2.x folder');
        self::assertSame('webassets/engine/2.1.0/', $engineB->getModuleInfo()->getAssetPath());

        self::assertSame(['1.4.0', '2.1.0'], $GLOBALS['rz35_init'], 'each dist ran the __onInit of its own version');
    }

    public function testTagSetOverridesTheStarSet(): void
    {
        // The canary shape: one dist, a host mapped to tag 'canary' runs the next engine.
        $dist = $this->makeDist('canary', [
            '*' => ['rz35/engine' => '1.4.0'],
            'canary' => ['rz35/engine' => '2.0.0'],
        ]);

        // scanModules() loads no module code, so both tags scan in one process (as `rewrite` does).
        $main = (new Distributor($dist, '*'))->scanModules();
        $canary = (new Distributor($dist, 'canary'))->scanModules();

        self::assertSame('1.4.0', $main->getRegistry()->get('rz35/engine')?->getModuleInfo()->getVersion());
        self::assertSame('2.0.0', $canary->getRegistry()->get('rz35/engine')?->getModuleInfo()->getVersion());
        self::assertSame([], $GLOBALS['rz35_init'], 'a scan runs no module code');
    }

    public function testModuleCodeAtTheTopLevelOfModulesCountsAsStar(): void
    {
        $dist = $this->makeDist('flat', ['rz35/engine' => '2.0.0']);

        $scanned = (new Distributor($dist))->scanModules();

        self::assertSame('2.0.0', $scanned->getRegistry()->get('rz35/engine')?->getModuleInfo()->getVersion());
    }

    // ── range resolution ───────────────────────────────────────────────────

    public function testRangeResolution(): void
    {
        $folder = PathUtil::append($this->moduleRoot, 'rz35', 'engine');
        $resolve = static fn (?string $pin): ?string => ModuleVersionResolver::resolve('rz35/engine', $folder, $pin, 'site@*');

        self::assertSame('default', $resolve(null), 'unpinned loads default/');
        self::assertSame('default', $resolve('default'));
        self::assertSame('1.4.0', $resolve('1.4.0'), 'a folder name loads that folder');
        self::assertSame('default', $resolve('1.0.0'), 'default/ matches by the version its package.php declares');
        self::assertSame('2.1.0', $resolve('*'), '* is the highest version');
        self::assertSame('1.4.0', $resolve('^1.0'));
        self::assertSame('default', $resolve('~1.0.0'));
        self::assertSame('2.0.0', $resolve('>=2.0 <2.1'));
        self::assertSame('2.1.0', $resolve('2.*'));
        self::assertSame('1.4.0', $resolve('^1.0 || ^3.0'));
        self::assertSame('2.0.0', $resolve('2.0'), 'an exact version may omit trailing zeros');
    }

    public function testNumberedFolderWinsOverDefaultAtTheSameVersion(): void
    {
        $this->makeModule('rz35/engine', '1.0.0', ['alias' => 'engine']);
        $folder = PathUtil::append($this->moduleRoot, 'rz35', 'engine');

        self::assertSame('1.0.0', ModuleVersionResolver::resolve('rz35/engine', $folder, '~1.0.0', 'site@*'));
    }

    public function testStarFallsBackToAnUnversionedDefault(): void
    {
        $this->makeModule('rz35/plain', 'default', ['alias' => 'plain']);
        $folder = PathUtil::append($this->moduleRoot, 'rz35', 'plain');

        self::assertSame('default', ModuleVersionResolver::resolve('rz35/plain', $folder, '*', 'site@*'));
        self::assertNull(ModuleVersionResolver::resolve('rz35/none', PathUtil::append($this->moduleRoot, 'rz35', 'none'), null, 'site@*'));
    }

    public function testPinnedVersionThatIsMissingIsALoadError(): void
    {
        $dist = $this->makeDist('missing', ['*' => ['rz35/engine' => '^3.0']]);

        try {
            (new Distributor($dist))->initialize();
            self::fail('a pin with no matching folder must not load default/ silently');
        } catch (ModuleVersionException $e) {
            self::assertStringContainsString("pins module 'rz35/engine' to '^3.0'", $e->getMessage());
            self::assertStringContainsString('Available: 2.1.0, 2.0.0, 1.4.0, default (1.0.0)', $e->getMessage());
        }
        self::assertSame([], $GLOBALS['rz35_init']);
    }

    public function testPinIsAVersionString(): void
    {
        $dist = $this->makeDist('badpin', ['*' => ['rz35/engine' => ['1.4.0']]]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("pins 'rz35/engine' to a array");
        new Distributor($dist);
    }

    public function testPinNamingNoModuleWarns(): void
    {
        $dist = $this->makeDist('ghost', ['*' => ['rz35/ghost' => '1.0.0']]);

        $warnings = [];
        \set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_USER_WARNING);
        try {
            (new Distributor($dist))->initialize();
        } finally {
            \restore_error_handler();
        }

        self::assertCount(1, $warnings);
        self::assertStringContainsString("pins 'rz35/ghost'", $warnings[0]);
    }

    // ── require constraints ────────────────────────────────────────────────

    public function testRequireConflictIsAClearError(): void
    {
        $this->makeModule('rz35/consumer', 'default', ['version' => '3.2.0', 'alias' => 'consumer', 'require' => ['rz35/engine' => '^2.0']]);
        $dist = $this->makeDist('conflict', ['*' => ['rz35/engine' => '1.4.0']]);

        try {
            (new Distributor($dist))->initialize();
            self::fail('an unmet require constraint must stop the boot');
        } catch (ModuleVersionException $e) {
            self::assertStringContainsString('module version conflicts', $e->getMessage());
            self::assertStringContainsString("'rz35/consumer' 3.2.0 requires 'rz35/engine' ^2.0, but this dist loads 'rz35/engine' 1.4.0", $e->getMessage());
        }
        self::assertSame([], $GLOBALS['rz35_init'], 'no module code runs on a conflict');
    }

    public function testRequireConstraintThatHoldsBoots(): void
    {
        $this->makeModule('rz35/consumer', 'default', ['alias' => 'consumer', 'require' => ['rz35/engine' => '^2.0']]);
        $dist = $this->makeDist('agree', ['*' => ['rz35/engine' => '^2.0']]);

        $booted = (new Distributor($dist))->initialize();

        self::assertSame(ModuleStatus::Loaded, $booted->getRegistry()->get('rz35/consumer')?->getStatus());
        self::assertSame(['2.1.0', 'consumer'], $GLOBALS['rz35_init'], 'the dependency initializes first');
    }

    public function testRequireOnAnUnversionedDependencyIsNotChecked(): void
    {
        $this->makeModule('rz35/plain', 'default', ['alias' => 'plain']);
        $this->makeModule('rz35/consumer', 'default', ['alias' => 'consumer', 'require' => ['rz35/plain' => '^5.0']]);
        $dist = $this->makeDist('loose', []);

        $booted = (new Distributor($dist))->initialize();

        self::assertSame(ModuleStatus::Loaded, $booted->getRegistry()->get('rz35/consumer')?->getStatus());
    }

    // ── one version per process ────────────────────────────────────────────

    public function testOneProcessRefusesASecondVersionOfAModule(): void
    {
        $stable = $this->makeDist('procA', ['*' => ['rz35/engine' => '1.4.0']]);
        $next = $this->makeDist('procB', ['*' => ['rz35/engine' => '2.1.0']]);
        $same = $this->makeDist('procC', ['*' => ['rz35/engine' => '~1.4']]);

        (new Distributor($stable))->initialize();
        (new Distributor($same))->initialize();
        self::assertSame(['1.4.0', '1.4.0'], $GLOBALS['rz35_init'], 'the same version in a second dist is fine');

        try {
            (new Distributor($next))->initialize();
            self::fail('a second version of a module in one process must be refused');
        } catch (ModuleVersionException $e) {
            self::assertStringContainsString("cannot load module 'rz35/engine' version '2.1.0'", $e->getMessage());
            self::assertStringContainsString("already loaded version '1.4.0' for distributor 'rz35procA" . $this->suffix . "@*'", $e->getMessage());
            self::assertStringContainsString('separate processes', $e->getMessage());
        }
        self::assertSame(['1.4.0', '1.4.0'], $GLOBALS['rz35_init'], 'the refused version ran no code');
        self::assertSame('1.4.0', ModuleVersionGuard::claimed('rz35/engine'));
    }

    // ── backward compatibility ─────────────────────────────────────────────

    public function testDistWithoutPinsLoadsDefaultAsBefore(): void
    {
        // Only numbered folders, no default/: not loaded unless pinned (as before RZ-35).
        $this->makeModule('rz35/numbered', '1.0.0', ['alias' => 'numbered']);
        $dist = $this->makeDist('legacy', []);

        $booted = (new Distributor($dist))->initialize();
        $info = $booted->getRegistry()->get('rz35/engine')?->getModuleInfo();

        self::assertNotNull($info);
        self::assertSame('default', $info->getVersionFolder());
        self::assertSame('1.0.0', $info->getVersion(), 'getVersion() is the version package.php declares');
        self::assertSame('webassets/engine/default/', $info->getAssetPath(), 'asset URLs keep the folder name');
        self::assertNull($booted->getRegistry()->get('rz35/numbered'));
    }

    public function testExplicitDefaultPinLoadsDefault(): void
    {
        $dist = $this->makeDist('pindefault', ['*' => ['rz35/engine' => 'default']]);

        $booted = (new Distributor($dist))->initialize();

        self::assertSame('default', $booted->getRegistry()->get('rz35/engine')?->getModuleInfo()->getVersionFolder());
    }

    // ── ModuleInfo::getVersion() ───────────────────────────────────────────

    public function testUndeclaredDefaultVersionIsTheFolderName(): void
    {
        $this->makeModule('rz35/plain', 'default', ['alias' => 'plain']);
        $info = new ModuleInfo(PathUtil::append($this->moduleRoot, 'rz35', 'plain'), ['module_code' => 'rz35/plain', 'author' => 't'], 'default');

        self::assertSame('default', $info->getVersion());
        self::assertSame('default', $info->getVersionFolder());
    }

    public function testNumberedFolderMustNotDeclareAnotherVersion(): void
    {
        $this->makeModule('rz35/liar', '2.0.0', ['version' => '1.9.0', 'alias' => 'liar']);

        $this->expectException(ModuleConfigException::class);
        $this->expectExceptionMessage("version folder '2.0.0'");
        new ModuleInfo(PathUtil::append($this->moduleRoot, 'rz35', 'liar'), ['module_code' => 'rz35/liar', 'author' => 't'], '2.0.0');
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $package
     */
    private function makeModule(string $code, string $folder, array $package): void
    {
        [$vendor, $name] = \explode('/', $code);
        $base = PathUtil::append($this->moduleRoot, $vendor, $name);
        $controller = PathUtil::append($base, $folder, 'controller');
        if (!\is_dir($controller)) {
            \mkdir($controller, 0o777, true);
        }
        \file_put_contents($base . '/module.php', "<?php\nreturn ['module_code' => '{$code}', 'author' => 't', 'description' => 't'];\n");
        \file_put_contents($base . '/' . $folder . '/package.php', "<?php\nreturn " . \var_export($package, true) . ";\n");

        // __onInit records which version ran, so a test can see whose code loaded.
        $marker = \var_export(ModuleVersionResolver::isVersion($folder) ? $folder : ($package['alias'] ?? $folder), true);
        if ('rz35/engine' === $code && 'default' === $folder) {
            $marker = "'1.0.0'";
        }
        \file_put_contents($controller . '/' . $name . '.php', <<<PHP
            <?php
            use Razy\\Controller;
            return new class(null) extends Controller {
                public function __onInit(\$agent): bool
                {
                    \$GLOBALS['rz35_init'][] = {$marker};
                    return true;
                }
            };
            PHP);
    }

    /**
     * @param array<string, mixed> $modules dist.php 'modules'
     */
    private function makeDist(string $name, array $modules): string
    {
        $code = 'rz35' . $name . $this->suffix;
        $path = PathUtil::append(SITES_FOLDER, $code);
        \mkdir($path, 0o777, true);
        $this->cleanup[] = $path;
        \file_put_contents($path . '/dist.php', "<?php\nreturn " . \var_export([
            'dist' => $code,
            'module_path' => $this->moduleRoot,
            'modules' => $modules,
        ], true) . ";\n");

        return $code;
    }

    private function removeDir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? \rmdir($item->getPathname()) : \unlink($item->getPathname());
        }
        \rmdir($dir);
    }
}
