<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Database;

/**
 * The phantom fix (PERMISSION-MODULE.md ledger P2 / Q3): Database::getSharedInstance()
 * existed only as 6 CALLERS (queue CLI + the published razymod/queue-admin module)
 * with zero definitions. Contract pinned here: shared = registered AND connected;
 * never lazily creates; null when unavailable (callers degrade explicitly on null).
 *
 * Real connections via the SQLite driver — no server needed.
 */
#[CoversClass(Database::class)]
class DatabaseSharedInstanceTest extends TestCase
{
    protected function tearDown(): void
    {
        Database::resetInstances();
    }

    public function testNullWhenRegistryEmpty(): void
    {
        $this->assertNull(Database::getSharedInstance());
    }

    public function testLazyRegisteredButUnconnectedIsNotShared(): void
    {
        Database::getInstance('main');

        $this->assertNull(Database::getSharedInstance(), 'a lazily created, never-connected instance must resolve to null (operationally = no database)');
    }

    public function testConnectedInstanceResolvesAsShared(): void
    {
        $db = Database::getInstance('main');
        $this->assertTrue($db->connectWithDriver('sqlite', ['database' => ':memory:']));

        $this->assertSame($db, Database::getSharedInstance());
    }

    public function testHonoursCustomName(): void
    {
        $reporting = Database::getInstance('reporting');
        $this->assertTrue($reporting->connectWithDriver('sqlite', ['database' => ':memory:']));

        $this->assertSame($reporting, Database::getSharedInstance('reporting'));
        $this->assertNull(Database::getSharedInstance(), "'main' remains absent when only 'reporting' is connected");
    }

    public function testFailedConnectionNeverBecomesShared(): void
    {
        $db = Database::getInstance('main');
        // Unresolvable path: driver connect fails (caught internally) => connected flag stays false.
        // The missing-PARENT-DIRECTORY shape fails on every platform: an old 'Q:\…' literal was a
        // legal *relative filename* on Linux, where sqlite happily created it (CI-only red, 2026-09).
        $this->assertFalse($db->connectWithDriver('sqlite', ['database' => \sys_get_temp_dir() . '/razy-no-such-dir-9f2b/app.db']));

        $this->assertNull(Database::getSharedInstance(), 'a failed connect must NOT flip the shared-instance gate');
    }

    public function testWorkerResetClearsSharedInstance(): void
    {
        $db = Database::getInstance('main');
        $this->assertTrue($db->connectWithDriver('sqlite', ['database' => ':memory:']));
        $this->assertNotNull(Database::getSharedInstance());

        Database::resetInstances();

        $this->assertNull(Database::getSharedInstance(), 'worker-mode reset must drop the shared registration (no cross-request bleed)');
    }
}
