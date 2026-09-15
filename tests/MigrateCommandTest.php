<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * M2 deploy-time migration CLI (dossier MIGRATION-GOVERNANCE.md). Terminal
 * .inc.php files are tested in the established house style (PackageVerifier/
 * ScaffoldCommand precedent): the shipped source itself is the assertion
 * surface — exit()-bound closures cannot be invoked in-process, and booting
 * a real Distributor belongs to manual acceptance, not the unit suite.
 */
#[CoversNothing]
class MigrateCommandTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        parent::setUp();
        $path = SYSTEM_ROOT . '/src/system/terminal/migrate.inc.php';
        $this->assertFileExists($path);
        $this->src = \file_get_contents($path);
    }

    public function testCommandIsDiscoverableAndListedInHelp(): void
    {
        $help = \file_get_contents(SYSTEM_ROOT . '/src/system/terminal/help.inc.php');
        $this->assertStringContainsString("'migrate'", $help, 'help mirror kept in sync (its own comment demands it)');
    }

    public function testPolicyStatementShipped(): void
    {
        $this->assertStringContainsString('WEB REQUESTS NEVER MIGRATE', $this->src, 'Q-M2 decision is printed in the command header');
        $this->assertStringContainsString('web requests never migrate', $this->src, '...and in the usage text');
    }

    public function testManagersAreScopedPerModule(): void
    {
        // the whole point of M2: it drives the M0 scoped surface, one scope
        // per module code — never the legacy shared-scope constructor
        $this->assertStringContainsString('new MigrationManager($db, $code)', $this->src);
        $this->assertStringNotContainsString('new MigrationManager($db)', $this->src);
    }

    public function testStatusUsesM1VerificationSurface(): void
    {
        $this->assertStringContainsString('verifyChecksums()', $this->src);
        $this->assertStringContainsString('DRIFT', $this->src);
        $this->assertStringContainsString('pre-M1, unverifiable', $this->src, 'legacy rows labelled, not guessed');
    }

    public function testRollbackRequiresExplicitModule(): void
    {
        $this->assertStringContainsString('--rollback requires an explicit module code', $this->src);
        $this->assertStringContainsString('mass rollback is not a deploy verb', $this->src);
    }

    public function testForceIsOperatorOnlyEscape(): void
    {
        $this->assertStringContainsString('$manager->migrate($force)', $this->src);
        $this->assertStringContainsString('operator decision only', $this->src);
    }

    public function testDatabaseResolutionIsConfigConnectNotAmbient(): void
    {
        // Since L1 the connect lives behind the ONE door: the CLI pins the
        // door call, the door itself pins config-connect.
        $this->assertStringContainsString('ModuleDatabaseConnector::connect', $this->src);
        $door = (string) \file_get_contents(SYSTEM_ROOT . '/src/library/Razy/Database/ModuleDatabaseConnector.php');
        $this->assertStringContainsString("['database']", $door);
        $this->assertStringContainsString('connectWithDriver', $door);
        // the ambient patterns the dossier rejected must not sneak in (either side)
        $this->assertStringNotContainsString('getSharedInstance', $this->src);
        $this->assertStringNotContainsString('Database::getInstance', $this->src);
        $this->assertStringNotContainsString('getSharedInstance', $door);
    }

    public function testDistributorBootIsInitPhaseOnly(): void
    {
        $this->assertStringContainsString('$distributor->initialize();', $this->src);
        $this->assertStringContainsString('RZ-009', $this->src, 'init-only boot rationale stated inline');
    }

    public function testFailureRunsExitNonZero(): void
    {
        // deploy gate: problems (including --status drift) exit non-zero
        $this->assertMatchesRegularExpression('/failures > 0.*exit\(1\)/s', $this->src);
    }

    // ── M3: declaration-gated bulk pass ───────────────────────────

    public function testBulkApplyIsGatedByTheDeployDeclaration(): void
    {
        $this->assertStringContainsString("\$mode !== 'deploy'", $this->src, 'unnamed bulk pass skips manual-declared modules');
        $this->assertStringContainsString('skipped (declared manual', $this->src, '...but never silently');
        $this->assertStringContainsString('suspect migration declaration', $this->src, 'typos degrade to manual WITH a loud warning');
    }

    public function testStatusIgnoresTheGateAndShowsEverything(): void
    {
        // visibility is the point of --status: the gate applies to apply only
        $this->assertStringContainsString('!$statusOnly && $moduleFilter === null && $mode !== ', $this->src);
    }

    public function testFirstPartyModuleDeclaresDeploy(): void
    {
        $pkg = \file_get_contents(SYSTEM_ROOT . '/modules/permissions/default/package.php');
        $this->assertStringContainsString("'migration' => 'deploy'", $pkg, 'the tree demonstrates its own M3 declaration');
    }
}
