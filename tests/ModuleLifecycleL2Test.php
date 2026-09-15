<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Razy\Configuration;

/**
 * MODULE-LIFECYCLE.md L2: the operator surface (`module status|enable|disable`).
 * Source-pin style follows MigrateCommandTest/QueueCommandTest — the silent-zero
 * class of failure this pins is exactly what those tests were written for.
 */
#[CoversNothing]
final class ModuleLifecycleL2Test extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        parent::setUp();
        $this->src = (string) \file_get_contents(SYSTEM_ROOT . '/src/system/terminal/module.inc.php');
    }

    public function testOnlyThreeVerbsExistAndUninstallIsAbsentByDesign(): void
    {
        $this->assertStringContainsString("\$sub === 'enable' || \$sub === 'disable'", $this->src);
        $this->assertStringContainsString("if (\$sub !== 'status')", $this->src);
        // the doctrine's Do-NOT-build list is structural, not just commented
        $this->assertStringNotContainsString("=== 'install'", $this->src);
        $this->assertStringNotContainsString("=== 'uninstall'", $this->src);
        $this->assertStringContainsString('There is deliberately NO `module install` or `module uninstall`', $this->src, 'the refusal states itself in the file header');
    }

    public function testStatusIsADeployGateNotACosmeticTable(): void
    {
        $this->assertStringContainsString('exit($gate > 0 ? 1 : 0)', $this->src, 'deploy-gate parity with migrate --status');
        $this->assertStringContainsString("'PENDING', 'READY'", $this->src, 'the dossier column contract');
        $this->assertStringContainsString('UNREACHABLE', $this->src, 'an unreadable ledger never renders as ready');
        $this->assertStringContainsString('the gate refuses what it cannot see', $this->src);
        $this->assertStringContainsString('php Razy.phar migrate ', $this->src, 'the fix command is printed next to the failure');
    }

    public function testDisableRefusesLiveDependentsAndNamesThem(): void
    {
        $this->assertStringContainsString('[REFUSED]', $this->src);
        $this->assertStringContainsString("' is required by: ", $this->src, 'dependents are named, not counted');
        $this->assertStringContainsString("!isset(\$options['force'])", $this->src, '--force is the only override');
        $this->assertStringContainsString('disable never drops schema or rows', $this->src, 'the Q4 data rail is printed at the moment of temptation');
    }

    public function testGhostNamesAreRefusedAndWritesGoThroughConfiguration(): void
    {
        $this->assertStringContainsString('enable-list ghosts are never written', $this->src, 'no writing state for a typo\'d module');
        $this->assertStringContainsString('new Configuration($enableListPath)', $this->src, 'ONE file, written through the framework config door');
        $this->assertStringContainsString("config', \$distCode, 'modules.php'", $this->src, 'the Q5 path, identical to the boot-time read');
        $this->assertStringContainsString('Takes effect at the next boot', $this->src, 'no pretending a write mutates the running process');
    }

    public function testBootIsInitPhaseOnly(): void
    {
        $this->assertStringContainsString('$distributor->initialize();', $this->src);
        $this->assertStringContainsString('RZ-009', $this->src, 'init-only boot rationale stated inline (migrate precedent)');
    }

    public function testHelpRegistryListsTheModule(): void
    {
        $help = (string) \file_get_contents(SYSTEM_ROOT . '/src/system/terminal/help.inc.php');
        $this->assertStringContainsString("'module'", $help, 'help.inc.php promises the list mirrors the command files');
    }

    public function testStatusReadyColumnRespectsLoadStateLikeThePredicate(): void
    {
        // The dogfood run caught the real bug: a dependent blocked by a
        // DISABLED peer (status Processing after standby) printed READY=yes
        // while the boot warning named it NOT loaded. The column may not
        // drift from the predicate's POSITIVE whitelist.
        $this->assertStringContainsString(
            '[ModuleStatus::InQueue, ModuleStatus::Loaded]',
            $this->src,
            'the status table and the predicate share one whitelist',
        );
        $predicate = (string) \file_get_contents(SYSTEM_ROOT . '/src/library/Razy/Distributor.php');
        $this->assertStringContainsString(
            '[ModuleStatus::InQueue, ModuleStatus::Loaded]',
            $predicate,
        );
    }

    // ── behaviour: the enable-list file round-trip the two doors share ────

    public function testEnableListRoundTripsExplicitBooleans(): void
    {
        // disable writes false; applyEnableList reads false. If Configuration
        // ever stopped round-tripping the explicit false, whole modules would
        // silently re-enable — this pins the contract BETWEEN the two doors.
        $file = \sys_get_temp_dir() . '/razy_l2_enable_' . \bin2hex(\random_bytes(5)) . '/modules.php';
        $dir = \dirname($file);

        try {
            $config = new Configuration($file);
            $config->offsetSet('test/off', false);
            $config->offsetSet('test/on', true);
            $config->save();

            $reread = (new Configuration($file))->array();

            self::assertArrayHasKey('test/off', $reread);
            self::assertFalse($reread['test/off'], 'false must survive as explicit false, not vanish');
            self::assertTrue($reread['test/on']);
        } finally {
            @\unlink($file);
            @\rmdir($dir);
        }
    }
}
