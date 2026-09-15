<?php

declare(strict_types=1);

namespace Razy\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use Razy\Emitter;
use Razy\Module;
use Razy\Module\ClosureLoader;
use Razy\Module\CommandRegistry;
use Razy\ModuleInfo;

/**
 * MODULE-LIFECYCLE.md L0 companion pack:
 *   1. Emitter::has() — the sanctioned probe the ERP audit demanded
 *      (method_exists() against the __call façade always returned false and
 *      silently killed the leave/holiday integration).
 *   2. Unknown package.php keys fail loud at validate (task/appform shipped a
 *      dead 'requires' key — dependencies silently never applied).
 *   3. Modules left unqueued by an unsatisfied 'require' warn loudly
 *      (Distributor mirrors the await-unresolved warning).
 */
#[\PHPUnit\Framework\Attributes\CoversClass(Emitter::class)]
final class ModuleLifecycleL0Test extends TestCase
{
    // ── 1) the has() probe ────────────────────────────────────────────────

    public function testRegistryHasMirrorsRegistrationExactly(): void
    {
        $registry = new CommandRegistry();
        $loader = $this->createMock(ClosureLoader::class);

        self::assertFalse($registry->has('getDB'), 'unregistered command must report false');

        $registry->addAPICommand('getDB', 'api/getdb', $loader);
        self::assertTrue($registry->has('getDB'), 'registered command must report true');

        // Slash-style commands register verbatim — probe mirrors executeCommand's
        // array-key lookup for them too.
        $registry->addAPICommand('list/dates', 'api/list_dates', $loader);
        self::assertTrue($registry->has('list/dates'));
        self::assertFalse($registry->has('list'), 'prefix is not a command');
    }

    public function testEmitterHasDelegatesToTargetModuleProbe(): void
    {
        $requester = $this->createMock(Module::class);
        $target = $this->createMock(Module::class);
        $target->method('hasAPICommand')->willReturnCallback(
            static fn (string $command): bool => $command === 'listDatesInRange',
        );

        $emitter = new Emitter($requester, $target);

        self::assertTrue($emitter->has('listDatesInRange'));
        self::assertFalse($emitter->has('isInstalled'), 'the dead-code trap shape: probe, never method_exists');
    }

    public function testEmitterHasOnUnresolvedTargetIsFalse(): void
    {
        $requester = $this->createMock(Module::class);
        self::assertFalse((new Emitter($requester, null))->has('anything'), 'null target never dispatches');
    }

    // ── 2) package.php closed key set ─────────────────────────────────────

    public function testPackageKeysCoversEverythingModuleInfoParses(): void
    {
        $parsed = ['alias', 'assets', 'prerequisite', 'api_name', 'shadow_asset', 'migration', 'require', 'services', 'metadata'];
        foreach ($parsed as $key) {
            self::assertContains($key, ModuleInfo::PACKAGE_KEYS, "parsed key '{$key}' must live in the closed set");
        }
    }

    public function testUnknownPackageKeysSurfaceViaModuleInfo(): void
    {
        $base = \sys_get_temp_dir() . '/razy_l0keys_' . \uniqid();
        \mkdir($base . '/default', 0o777, true);
        \file_put_contents(
            $base . '/default/package.php',
            "<?php\nreturn ['api_name' => 'x', 'requires' => ['a/b' => '*'], 'label' => 'z'];\n",
        );

        try {
            $info = new ModuleInfo($base, ['module_code' => 'test/l0keys', 'author' => 'x', 'description' => 'x'], 'default');
            $unknown = \array_values(\array_diff($info->getPackageKeys(), ModuleInfo::PACKAGE_KEYS));
            \sort($unknown);
            self::assertSame(['label', 'requires'], $unknown, 'the exact typo shapes the ERP shipped must be reported');
        } finally {
            $this->rrmdir($base);
        }
    }

    public function testValidateGateReferencesTheClosedSet(): void
    {
        $source = (string) \file_get_contents(SYSTEM_ROOT . '/src/system/terminal/validate.inc.php');
        self::assertStringContainsString('ModuleInfo::PACKAGE_KEYS', $source, 'validate must diff against the closed set');
        self::assertStringContainsString('Unknown package.php key', $source);
        self::assertStringContainsString('$totalErrors++', $source, 'unknown keys are errors, not decoration');
    }

    public function testMarkdownDemosDeadKeysPurged(): void
    {
        $service = (string) \file_get_contents(SYSTEM_ROOT . '/demo_modules/system/markdown_service/default/package.php');
        $consumer = (string) \file_get_contents(SYSTEM_ROOT . '/demo_modules/demo/markdown_consumer/default/package.php');

        self::assertStringNotContainsString("'label'", $service);
        self::assertStringNotContainsString("'required'", $service);
        self::assertStringNotContainsString("'label'", $consumer);
        self::assertStringContainsString("'require' => [", $consumer, 'the dead key became the real one');
    }

    // ── 3) loud require failures ──────────────────────────────────────────

    public function testDistributorWarnsOnModulesLeftUnqueuedByRequire(): void
    {
        $source = (string) \file_get_contents(SYSTEM_ROOT . '/src/library/Razy/Distributor.php');
        self::assertStringContainsString('was NOT loaded', $source, 'the warning must name the skipped module');
        self::assertStringContainsString('E_USER_WARNING', $source, 'mirror the await-unresolved warning shape');
        self::assertStringContainsString('the manifest key is', $source);
        self::assertStringContainsString('(singular)', $source, 'the hint that catches the ERP-shaped typo');
    }

    // ── 4) new lint rules ship wired ──────────────────────────────────────

    public function testLintToolRegistersTheNewRules(): void
    {
        $source = (string) \file_get_contents(SYSTEM_ROOT . '/tools/lint-module-discipline.php');
        self::assertStringContainsString("'RZ-016'", $source, 'web-migrate door rule');
        self::assertStringContainsString('getMigrationManager', $source);
        self::assertStringContainsString("'RZ-017'", $source, 'cross-module namespace import rule');
        self::assertStringContainsString('discoverModuleManifests', $source, 'the structural pre-pass');
        self::assertStringContainsString('rz017Scan', $source);
    }

    private function rrmdir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach ((new FilesystemIterator($dir)) as $entry) {
            $entry->isDir() ? $this->rrmdir($entry->getPathname()) : \unlink($entry->getPathname());
        }
        \rmdir($dir);
    }
}
