<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The bootstrap helpers (env/xcopy) were moved INSIDE the Razy namespace in
 * the Phase 2.5 refactor, but the `\env()` / `\xcopy()` global-form call
 * sites were never updated — so the package-extract path of `compose` has
 * been dead since (undefined function), and the RAZY_ALLOW_INSECURE_TRANSPORT
 * operator switch silently never fired there. Live playground `compose`
 * dogfood (manual/12 tutorial prep) surfaced both as red CLI lines.
 *
 * Pins (positive-form only — the repaired files' own comments legitimately
 * MENTION the legacy form): the call sites must keep the fully-qualified
 * `\Razy\…` shape. Bare words would be re-globalized by the cs fixer's
 * native_function_invocation rule — this fix has already been silently
 * undone by the tool once, so the pin is load-bearing.
 */
final class PackageManagerNamespaceTest extends TestCase
{
    public function testExtractUsesTheFullyQualifiedNamespacedXcopy(): void
    {
        $source = (string) \file_get_contents(SYSTEM_ROOT . '/src/library/Razy/PackageManager.php');

        self::assertStringContainsString(
            '\Razy\xcopy(PathUtil::append($temporaryExtractPath',
            $source,
            'fully-qualified call reaches Razy\xcopy (bootstrap.inc.php defines it there)',
        );
    }

    public function testInsecureTransportSwitchChecksTheNamespacedEnv(): void
    {
        $source = (string) \file_get_contents(SYSTEM_ROOT . '/src/library/Razy/PackageManager.php');

        self::assertStringContainsString(
            "function_exists('Razy\\env')",
            $source,
            'the existence check must name the namespace bootstrap defines env() in',
        );
        self::assertStringContainsString(
            '\Razy\env(\'RAZY_ALLOW_INSECURE_TRANSPORT\', false)',
            $source,
            'the call itself must be fully qualified, not the never-resolving \env() global form',
        );
        self::assertStringContainsString(
            '\getenv(\'RAZY_ALLOW_INSECURE_TRANSPORT\')',
            $source,
            'test/no-bootstrap contexts still see the switch through getenv',
        );
    }

    public function testPackAssetsCopyUsesTheFullyQualifiedNamespacedXcopy(): void
    {
        $source = (string) \file_get_contents(SYSTEM_ROOT . '/src/system/terminal/pack.inc.php');

        self::assertStringContainsString(
            '\Razy\xcopy($assetsPath, $assetsOutputPath)',
            $source,
            'same global-form trap: pack assets copy must reach the namespaced helper',
        );
    }
}
