<?php

/**
 * Tests for ModuleChangeDetector - file change detection and classification.
 * This file is part of Razy v0.5.
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Worker\ChangeType;
use Razy\Worker\ModuleChangeDetector;

#[CoversClass(ModuleChangeDetector::class)]
class ModuleChangeDetectorTest extends TestCase
{
    private string $tempDir;

    private ModuleChangeDetector $detector;

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . '/razy_detector_test_' . \uniqid();
        \mkdir($this->tempDir, 0o755, true);
        $this->detector = new ModuleChangeDetector();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    // ── isClassFile ─────────────────────────────────────

    public function testIsClassFileForControllerPhp(): void
    {
        $this->assertTrue($this->detector->isClassFile('controller/Main.php'));
    }

    public function testIsClassFileForLibraryPhp(): void
    {
        $this->assertTrue($this->detector->isClassFile('library/Service.php'));
    }

    public function testIsNotClassFileForPackagePhp(): void
    {
        $this->assertFalse($this->detector->isClassFile('package.php'));
    }

    public function testIsNotClassFileForTemplate(): void
    {
        $this->assertFalse($this->detector->isClassFile('views/index.tpl'));
    }

    public function testIsNotClassFileForAssets(): void
    {
        $this->assertFalse($this->detector->isClassFile('assets/style.css'));
        $this->assertFalse($this->detector->isClassFile('assets/app.js'));
        $this->assertFalse($this->detector->isClassFile('assets/logo.png'));
    }

    public function testIsNotClassFileForJson(): void
    {
        $this->assertFalse($this->detector->isClassFile('config/routes.json'));
    }

    // ── Snapshot & Detect ───────────────────────────────

    public function testDetectNoChangeAfterSnapshot(): void
    {
        $path = $this->createModuleDir('moduleA');
        $this->detector->snapshot('moduleA', $path);

        $this->assertSame(ChangeType::None, $this->detector->detect('moduleA'));
    }

    public function testDetectClassChangeWhenControllerModified(): void
    {
        $path = $this->createModuleDir('moduleB');
        $this->detector->snapshot('moduleB', $path);

        \file_put_contents($path . '/controller/Main.php', '<?php class Main { public function v2() {} }');

        $this->assertSame(ChangeType::ClassFile, $this->detector->detect('moduleB'));
    }

    public function testDetectClassChangeWhenLibraryModified(): void
    {
        $path = $this->createModuleDir('moduleC');
        $this->detector->snapshot('moduleC', $path);

        \file_put_contents($path . '/library/Helper.php', '<?php class Helper { public function v2() {} }');

        $this->assertSame(ChangeType::ClassFile, $this->detector->detect('moduleC'));
    }

    public function testDetectConfigChangeWhenOnlyPackagePhpModified(): void
    {
        $path = $this->createModuleDir('moduleD');
        $this->detector->snapshot('moduleD', $path);

        \file_put_contents($path . '/package.php', '<?php return ["version" => "2.0"];');

        $this->assertSame(ChangeType::Config, $this->detector->detect('moduleD'));
    }

    public function testDetectConfigChangeWhenTemplateModified(): void
    {
        $path = $this->createModuleDir('moduleE');
        \mkdir($path . '/views', 0o755, true);
        \file_put_contents($path . '/views/index.tpl', '<html>v1</html>');
        $this->detector->snapshot('moduleE', $path);

        \file_put_contents($path . '/views/index.tpl', '<html>v2</html>');

        $this->assertSame(ChangeType::Config, $this->detector->detect('moduleE'));
    }

    public function testDetectClassChangeWhenNewPhpFileAdded(): void
    {
        $path = $this->createModuleDir('moduleF');
        $this->detector->snapshot('moduleF', $path);

        \file_put_contents($path . '/library/NewService.php', '<?php class NewService {}');

        $this->assertSame(ChangeType::ClassFile, $this->detector->detect('moduleF'));
    }

    public function testDetectRebindableWhenPhpFileDeleted(): void
    {
        $path = $this->createModuleDir('moduleG');
        $this->detector->snapshot('moduleG', $path);

        \unlink($path . '/library/Helper.php');

        // Deleted PHP files are treated as Rebindable (the class was already loaded)
        $this->assertSame(ChangeType::Rebindable, $this->detector->detect('moduleG'));
    }

    public function testDetectClassFileForUnknownModule(): void
    {
        $this->assertSame(ChangeType::ClassFile, $this->detector->detect('unknown'));
    }

    // ── detectAll / detectOverall ───────────────────────

    public function testDetectAllReturnsOnlyChangedModules(): void
    {
        $pathA = $this->createModuleDir('modA');
        $pathB = $this->createModuleDir('modB');
        $this->detector->snapshot('modA', $pathA);
        $this->detector->snapshot('modB', $pathB);

        \file_put_contents($pathA . '/package.php', '<?php return ["v" => 2];');

        $changes = $this->detector->detectAll();
        $this->assertArrayHasKey('modA', $changes);
        $this->assertArrayNotHasKey('modB', $changes);
    }

    public function testDetectOverallReturnsClassIfAnyModuleHasClassChange(): void
    {
        $pathA = $this->createModuleDir('ovA');
        $pathB = $this->createModuleDir('ovB');
        $this->detector->snapshot('ovA', $pathA);
        $this->detector->snapshot('ovB', $pathB);

        \file_put_contents($pathA . '/package.php', '<?php return ["changed"];');
        \file_put_contents($pathB . '/controller/Main.php', '<?php class V2 {}');

        $this->assertSame(ChangeType::ClassFile, $this->detector->detectOverall());
    }

    public function testDetectOverallReturnsConfigIfAllChangesAreConfig(): void
    {
        $pathA = $this->createModuleDir('cfgA');
        $pathB = $this->createModuleDir('cfgB');
        $this->detector->snapshot('cfgA', $pathA);
        $this->detector->snapshot('cfgB', $pathB);

        \file_put_contents($pathA . '/package.php', '<?php return ["a"];');
        \file_put_contents($pathB . '/package.php', '<?php return ["b"];');

        $this->assertSame(ChangeType::Config, $this->detector->detectOverall());
    }

    public function testDetectOverallReturnsNoneIfNoChanges(): void
    {
        $path = $this->createModuleDir('noChg');
        $this->detector->snapshot('noChg', $path);

        $this->assertSame(ChangeType::None, $this->detector->detectOverall());
    }

    // ── getHotSwappableModules / getRestartRequiredModules

    public function testGetHotSwappableAndRestartRequiredModules(): void
    {
        $pathA = $this->createModuleDir('hsA');
        $pathB = $this->createModuleDir('hsB');
        $this->detector->snapshot('hsA', $pathA);
        $this->detector->snapshot('hsB', $pathB);

        \file_put_contents($pathA . '/package.php', '<?php return ["hot"];');
        \file_put_contents($pathB . '/controller/Main.php', '<?php class Restart {}');

        $this->detector->detectAll();

        $this->assertSame(['hsA'], $this->detector->getHotSwappableModules());
        $this->assertSame(['hsB'], $this->detector->getRestartRequiredModules());
    }

    // ── refreshSnapshot ─────────────────────────────────

    public function testRefreshSnapshotClearsChangeDetection(): void
    {
        $path = $this->createModuleDir('refresh');
        $this->detector->snapshot('refresh', $path);

        \file_put_contents($path . '/package.php', '<?php return ["changed"];');
        $this->assertSame(ChangeType::Config, $this->detector->detect('refresh'));

        $this->detector->refreshSnapshot('refresh');
        $this->assertSame(ChangeType::None, $this->detector->detect('refresh'));
    }

    // ── Module registration ──────────────────────────────

    public function testHasModuleAndGetRegistered(): void
    {
        $path = $this->createModuleDir('reg');
        $this->detector->snapshot('reg', $path);

        $this->assertTrue($this->detector->hasModule('reg'));
        $this->assertFalse($this->detector->hasModule('nonexistent'));
        $this->assertContains('reg', $this->detector->getRegisteredModules());
    }

    // ── Edge cases ──────────────────────────────────────

    public function testSubdirectoryPhpFilesDetectedAsClassChange(): void
    {
        $path = $this->createModuleDir('sub');
        \mkdir($path . '/model', 0o755, true);
        \file_put_contents($path . '/model/User.php', '<?php class User {}');
        $this->detector->snapshot('sub', $path);

        \file_put_contents($path . '/model/User.php', '<?php class User { public $v2; }');
        $this->assertSame(ChangeType::ClassFile, $this->detector->detect('sub'));
    }

    public function testMixedChangesClassTakesPrecedence(): void
    {
        $path = $this->createModuleDir('mixed');
        \mkdir($path . '/assets', 0o755, true);
        \file_put_contents($path . '/assets/style.css', 'body {}');
        $this->detector->snapshot('mixed', $path);

        \file_put_contents($path . '/assets/style.css', 'body { color: red; }');
        \file_put_contents($path . '/library/Helper.php', '<?php class Helper { /* v2 */ }');

        $this->assertSame(ChangeType::ClassFile, $this->detector->detect('mixed'));
    }

    public function testOnlyAssetChangeIsConfig(): void
    {
        $path = $this->createModuleDir('asset');
        \mkdir($path . '/assets', 0o755, true);
        \file_put_contents($path . '/assets/logo.png', 'PNG_V1');
        $this->detector->snapshot('asset', $path);

        \file_put_contents($path . '/assets/logo.png', 'PNG_V2');
        $this->assertSame(ChangeType::Config, $this->detector->detect('asset'));
    }

    public function testDeletedConfigFileDetectedAsConfigChange(): void
    {
        $path = $this->tempDir . '/vanished';
        \mkdir($path, 0o755, true);
        \file_put_contents($path . '/package.php', '<?php return [];');
        $this->detector->snapshot('vanished', $path);

        \unlink($path . '/package.php');
        $this->assertSame(ChangeType::Config, $this->detector->detect('vanished'));
    }

    public function testRefreshAllUpdatesAllSnapshots(): void
    {
        $pathA = $this->createModuleDir('raA');
        $pathB = $this->createModuleDir('raB');
        $this->detector->snapshot('raA', $pathA);
        $this->detector->snapshot('raB', $pathB);

        \file_put_contents($pathA . '/package.php', '<?php return ["x"];');
        \file_put_contents($pathB . '/package.php', '<?php return ["y"];');

        $this->detector->refreshAll();

        $this->assertSame(ChangeType::None, $this->detector->detect('raA'));
        $this->assertSame(ChangeType::None, $this->detector->detect('raB'));
    }

    // ── classifyPhpFile ─────────────────────────────────

    public function testClassifyPhpFileWithNamedClass(): void
    {
        $file = $this->tempDir . '/NamedClass.php';
        \file_put_contents($file, '<?php class Foo { public function bar() {} }');
        $this->assertSame(ChangeType::ClassFile, $this->detector->classifyPhpFile($file));
    }

    public function testClassifyPhpFileWithAnonymousClass(): void
    {
        $file = $this->tempDir . '/AnonClass.php';
        \file_put_contents($file, '<?php return new class { public function bar() {} };');
        $this->assertSame(ChangeType::Rebindable, $this->detector->classifyPhpFile($file));
    }

    public function testClassifyPhpFileWithInterface(): void
    {
        $file = $this->tempDir . '/NamedInterface.php';
        \file_put_contents($file, '<?php interface FooInterface { public function bar(): void; }');
        $this->assertSame(ChangeType::ClassFile, $this->detector->classifyPhpFile($file));
    }

    public function testClassifyPhpFileWithTrait(): void
    {
        $file = $this->tempDir . '/NamedTrait.php';
        \file_put_contents($file, '<?php trait FooTrait { public function bar() {} }');
        $this->assertSame(ChangeType::ClassFile, $this->detector->classifyPhpFile($file));
    }

    public function testClassifyPhpFileWithEnum(): void
    {
        $file = $this->tempDir . '/NamedEnum.php';
        \file_put_contents($file, '<?php enum Status: string { case Active = "active"; }');
        $this->assertSame(ChangeType::ClassFile, $this->detector->classifyPhpFile($file));
    }

    public function testClassifyPhpFileClosureOnly(): void
    {
        $file = $this->tempDir . '/Closures.php';
        \file_put_contents($file, '<?php return function($x) { return $x * 2; };');
        $this->assertSame(ChangeType::Rebindable, $this->detector->classifyPhpFile($file));
    }

    public function testClassifyPhpFileMixedNamedAndAnonymous(): void
    {
        $file = $this->tempDir . '/Mixed.php';
        \file_put_contents($file, '<?php class Named {} $x = new class {};');
        $this->assertSame(ChangeType::ClassFile, $this->detector->classifyPhpFile($file));
    }

    public function testClassifyPhpFileWithNamespace(): void
    {
        $file = $this->tempDir . '/Namespaced.php';
        \file_put_contents($file, '<?php namespace App\\Model; class User { public string $name; }');
        $this->assertSame(ChangeType::ClassFile, $this->detector->classifyPhpFile($file));
    }

    public function testClassifyPhpFileAnonymousWithExtends(): void
    {
        $file = $this->tempDir . '/AnonExtends.php';
        \file_put_contents($file, '<?php return new class extends SomeBase { public function run() {} };');
        $this->assertSame(ChangeType::Rebindable, $this->detector->classifyPhpFile($file));
    }

    public function testClassifyPhpFileNonexistentReturnsRebindable(): void
    {
        $this->assertSame(ChangeType::Rebindable, $this->detector->classifyPhpFile($this->tempDir . '/nope.php'));
    }

    // ── classifyFile ────────────────────────────────────

    public function testClassifyFilePackagePhpIsConfig(): void
    {
        $path = $this->createModuleDir('cfFile');
        $this->assertSame(ChangeType::Config, $this->detector->classifyFile('package.php', $path));
    }

    public function testClassifyFileNonPhpIsConfig(): void
    {
        $path = $this->createModuleDir('cfNonPhp');
        $this->assertSame(ChangeType::Config, $this->detector->classifyFile('views/index.tpl', $path));
    }

    public function testClassifyFileNamedClassPhpIsClassFile(): void
    {
        $path = $this->createModuleDir('cfNamed');
        // controller/Main.php created by createModuleDir has named class
        $this->assertSame(ChangeType::ClassFile, $this->detector->classifyFile('controller/Main.php', $path));
    }

    public function testClassifyFileAnonymousClassPhpIsRebindable(): void
    {
        $path = $this->createModuleDir('cfAnon');
        \file_put_contents($path . '/controller/Main.php', '<?php return new class { public function index() {} };');
        $this->assertSame(ChangeType::Rebindable, $this->detector->classifyFile('controller/Main.php', $path));
    }

    // ── getRebindableModules ────────────────────────────

    public function testGetRebindableModules(): void
    {
        $pathA = $this->createModuleDir('rbA');
        $pathB = $this->createModuleDir('rbB');
        // rbA: anonymous class controller
        \file_put_contents($pathA . '/controller/Main.php', '<?php return new class { public function index() {} };');
        // rbB: named class controller
        \file_put_contents($pathB . '/controller/Main.php', '<?php class MainController {}');

        $this->detector->snapshot('rbA', $pathA);
        $this->detector->snapshot('rbB', $pathB);

        // Modify both
        \file_put_contents($pathA . '/controller/Main.php', '<?php return new class { public function v2() {} };');
        \file_put_contents($pathB . '/controller/Main.php', '<?php class MainControllerV2 {}');

        $this->detector->detectAll();

        $rebindable = $this->detector->getRebindableModules();
        $this->assertContains('rbA', $rebindable);
        $this->assertNotContains('rbB', $rebindable);
    }

    // ── Rebindable detection via detect() ───────────────

    public function testDetectRebindableWhenAnonymousClassControllerModified(): void
    {
        $path = $this->createModuleDir('rbDetect');
        \file_put_contents($path . '/controller/Main.php', '<?php return new class { public function index() {} };');
        \file_put_contents($path . '/library/Helper.php', '<?php return new class { public function help() {} };');
        $this->detector->snapshot('rbDetect', $path);

        \file_put_contents($path . '/controller/Main.php', '<?php return new class { public function v2() {} };');

        $this->assertSame(ChangeType::Rebindable, $this->detector->detect('rbDetect'));
    }

    public function testDetectOverallRebindableWhenAllChangesAreAnonymous(): void
    {
        $pathA = $this->createModuleDir('rbOvA');
        $pathB = $this->createModuleDir('rbOvB');
        \file_put_contents($pathA . '/controller/Main.php', '<?php return new class {};');
        \file_put_contents($pathB . '/controller/Main.php', '<?php return new class {};');
        \file_put_contents($pathA . '/library/Helper.php', '<?php return function() {};');
        \file_put_contents($pathB . '/library/Helper.php', '<?php return function() {};');

        $this->detector->snapshot('rbOvA', $pathA);
        $this->detector->snapshot('rbOvB', $pathB);

        \file_put_contents($pathA . '/controller/Main.php', '<?php return new class { public function v2() {} };');
        \file_put_contents($pathB . '/controller/Main.php', '<?php return new class { public function v2() {} };');

        $this->assertSame(ChangeType::Rebindable, $this->detector->detectOverall());
    }

    public function testDetectOverallClassFileWhenMixedRebindableAndClass(): void
    {
        $pathA = $this->createModuleDir('mixOvA');
        $pathB = $this->createModuleDir('mixOvB');
        \file_put_contents($pathA . '/controller/Main.php', '<?php return new class {};');
        \file_put_contents($pathA . '/library/Helper.php', '<?php return function() {};');

        $this->detector->snapshot('mixOvA', $pathA);
        $this->detector->snapshot('mixOvB', $pathB);

        // rbA: anonymous (rebindable), rbB: named (classfile)
        \file_put_contents($pathA . '/controller/Main.php', '<?php return new class { public function v2() {} };');
        \file_put_contents($pathB . '/controller/Main.php', '<?php class ControllerV2 {}');

        $this->assertSame(ChangeType::ClassFile, $this->detector->detectOverall());
    }

    private function removeDir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach (\scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            \is_dir($path) ? $this->removeDir($path) : \unlink($path);
        }
        \rmdir($dir);
    }

    private function createModuleDir(string $name): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $name;
        \mkdir($path . '/controller', 0o755, true);
        \mkdir($path . '/library', 0o755, true);
        \file_put_contents($path . '/package.php', '<?php return [];');
        \file_put_contents($path . '/controller/Main.php', '<?php class Main {}');
        \file_put_contents($path . '/library/Helper.php', '<?php class Helper {}');
        return $path;
    }
}
