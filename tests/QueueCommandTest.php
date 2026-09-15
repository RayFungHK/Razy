<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * `queue` CLI fail-loud rework (dossier PERMISSION-MODULE.md Q3 tail, the
 * half of the P2 phantom the S1.5 fix deliberately left open). House style
 * for terminal .inc.php files (MigrateCommandTest precedent): the shipped
 * source IS the assertion surface — the closure is exit()-bound to the
 * Console and cannot be invoked in-process.
 */
#[CoversNothing]
class QueueCommandTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        parent::setUp();
        $path = SYSTEM_ROOT . '/src/system/terminal/queue.inc.php';
        $this->assertFileExists($path);
        $this->src = \file_get_contents($path);
    }

    public function testTheSwallowIsGone(): void
    {
        $this->assertStringNotContainsString('Fall through', $this->src, 'the Q3-swallowing comment is gone with the swallow');
        $this->assertStringNotContainsString('Could not initialize QueueManager', $this->src, 'the anonymous one-liner that hid every failure class');
        $this->assertStringNotContainsString('class_exists(QueueManager::class)', $this->src, 'guarding a framework-own class was dead weight; removal is the loud posture');
    }

    public function testSharedInstanceMissNamesTheFix(): void
    {
        $this->assertStringContainsString('Database::getSharedInstance()', $this->src, 'drives the P2-defined contract, no re-guessing');
        $this->assertStringContainsString('no registered-and-connected database', $this->src, 'failure class 1 named: the config gap');
        $this->assertStringContainsString('register AND connect', $this->src, '...with the actionable hint (the CLI opens no connection itself)');
    }

    public function testConstructionFailuresSurfaceVerbatim(): void
    {
        // failure class 2: a throwing construction prints class + message —
        // exactly what the old catch-Throwable destroyed
        $this->assertStringContainsString('queue store construction failed', $this->src);
        $this->assertStringContainsString('\get_class($e)', $this->src);
        $this->assertStringContainsString('$e->getMessage()', $this->src);
    }

    public function testEveryUnreachableQueuePathExitsNonZero(): void
    {
        // work, once, status, clear, retry all share the resolver-null shape;
        // retry's usage error adds its own exit
        $this->assertSame(
            5,
            \substr_count($this->src, 'if ($manager === null) {' . "\n" . '                exit(1);'),
            'all five subcommands fail loud, none breaks to a silent zero',
        );
        $this->assertGreaterThanOrEqual(6, \substr_count($this->src, 'exit(1)'));
    }

    public function testHelpAndUsageSurvive(): void
    {
        $this->assertStringContainsString('php Razy.phar queue [work|once|status|clear|retry]', $this->src, 'the zero-arg help path still exists and exits 0');
    }
}
