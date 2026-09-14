<?php

declare(strict_types=1);

namespace Razy\Tests;

use PDO;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Razy\Configuration;
use Razy\Database;
use Razy\Database\MigrationManager;
use Razy\Database\SchemaBuilder;
use Razy\Exception\QueryException;
use Razy\Module;
use Razy\ModuleInfo;
use Razy\ORM\Contract;
use Throwable;

/**
 * razymod/permissions S2 (dossier PERMISSION-MODULE.md milestone table):
 * migration up/down runs END TO END on sqlite (possible precisely because
 * up() is driver-branched raw DDL — the DatabaseStore precedent), the
 * Contract mirror matches the LIVE schema, the config-connect resolver
 * degrades to null on every unhappy path, and the two-tier __onAPICall gate
 * (Q4 governor) is pinned command-by-command before any command ships (S3
 * can only plug into this reviewed shape).
 */
#[CoversNothing]
class PermissionsModuleTest extends TestCase
{
    private const MIGRATION_DIR = __DIR__ . '/../modules/permissions/default/migration';

    private string $tempDir = '';

    protected function tearDown(): void
    {
        if ($this->tempDir !== '' && \is_dir($this->tempDir)) {
            foreach (\glob($this->tempDir . '/*') ?: [] as $f) {
                @\unlink((string) $f);
            }
            @\rmdir($this->tempDir);
        }
        $this->tempDir = '';

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: array<string, mixed>, 3: bool}>
     */
    public static function gateMatrix(): iterable
    {
        $gov = ['governor' => 'acme/governor'];
        $none = [];

        // reads: open to any module, always
        foreach (['can', 'can-any', 'abilities', 'define-ability'] as $cmd) {
            yield "read {$cmd} by governor" => [$cmd, 'acme/governor', $gov, true];
            yield "read {$cmd} by anyone" => [$cmd, 'other/mod', $gov, true];
            yield "read {$cmd} with no governor configured" => [$cmd, 'other/mod', $none, true];
        }

        // governance: governor-named caller only (audit-actor joined with S5)
        foreach (['roles-of', 'assign-role', 'revoke-role', 'audit-actor'] as $cmd) {
            yield "govern {$cmd} by governor" => [$cmd, 'acme/governor', $gov, true];
            yield "govern {$cmd} by stranger" => [$cmd, 'evil/mod', $gov, false];
            yield "govern {$cmd} with no governor configured" => [$cmd, 'acme/governor', $none, false];
            yield "govern {$cmd} with EMPTY governor config" => [$cmd, '', ['governor' => ''], false];
        }

        // unknown: deny
        yield 'unknown command denied even to governor' => ['wipe-all', 'acme/governor', $gov, false];
    }

    // ── migration: up ─────────────────────────────────────────────

    public function testUpCreatesAllRbacTablesPlusAuditLog(): void
    {
        $db = $this->migrateSqliteDb();
        $schema = new SchemaBuilder($db);

        foreach (['permissions', 'roles', 'permission_role', 'actor_role', 'permission_audit_log'] as $table) {
            $this->assertTrue($schema->hasTable($table), "table '{$table}' must exist after migrate()");
        }
    }

    public function testUpIsIdempotentAndTrackedOnce(): void
    {
        static $counter = 0;
        $db = new Database('perm_test_' . (++$counter));
        $db->connectWithDriver('sqlite', ['database' => ':memory:']);

        $manager = new MigrationManager($db);
        $manager->addPath(self::MIGRATION_DIR);

        $first = $manager->migrate();
        $second = $manager->migrate();

        $this->assertCount(2, $first, 'the two shipped migrations apply (RBAC tables S2 + audit log S5)');
        $this->assertSame([], $second, 'tracking table prevents re-run');
        $this->assertCount(2, $manager->getApplied());
    }

    // ── migration: constraints actually enforced ──────────────────

    public function testCompositeUniqueOnPermissionRoleBlocksDoubleGrant(): void
    {
        $db = $this->migrateSqliteDb();
        $exec = function (string $sql) use ($db): void {
            $db->execute($db->prepare($sql));
        };

        $exec("INSERT INTO permissions (code, module_code) VALUES ('demo.act', 'demo/mod')");
        $exec("INSERT INTO roles (code) VALUES ('tester')");
        $exec('INSERT INTO permission_role (role_id, permission_id) VALUES (1, 1)');

        $this->expectException(QueryException::class);
        $exec('INSERT INTO permission_role (role_id, permission_id) VALUES (1, 1)');
    }

    public function testActorRoleTripleUniqueAndDefaultActorType(): void
    {
        $db = $this->migrateSqliteDb();
        $exec = function (string $sql) use ($db): int {
            $db->execute($db->prepare($sql));

            return (int) $this->pdo($db)->query('SELECT COUNT(*) FROM actor_role')->fetchColumn();
        };

        $exec("INSERT INTO roles (code, is_system) VALUES ('editor', 1)");
        $this->assertSame(1, $exec("INSERT INTO actor_role (actor_id, role_id) VALUES ('42', 1)"));
        $this->assertSame(2, $exec("INSERT INTO actor_role (actor_type, actor_id, role_id) VALUES ('api-client', '42', 1)"), 'same id, different actor_type is a DIFFERENT actor (§7.5 service actors)');

        try {
            $exec("INSERT INTO actor_role (actor_id, role_id) VALUES ('42', 1)");
            $this->fail('duplicate (user,42,role1) must violate the composite unique');
        } catch (Throwable) {
            $this->assertSame(2, (int) $this->pdo($db)->query('SELECT COUNT(*) FROM actor_role')->fetchColumn());
        }

        $default = $this->pdo($db)->query("SELECT actor_type FROM actor_role WHERE actor_id = '42' AND actor_type <> 'api-client'")->fetchColumn();
        $this->assertSame('user', $default, "actor_type defaults to 'user' (§7.1)");
    }

    // ── migration: down ───────────────────────────────────────────

    public function testDownDropsEverythingThroughRollback(): void
    {
        static $counter = 0;
        $db = new Database('perm_test_' . (++$counter));
        $db->connectWithDriver('sqlite', ['database' => ':memory:']);

        $manager = new MigrationManager($db);
        $manager->addPath(self::MIGRATION_DIR);
        $manager->migrate();

        $rolled = $manager->rollback();
        $this->assertCount(2, $rolled, 'one step rolls the whole last batch (both shipped migrations ran in batch 1)');

        $schema = new SchemaBuilder($db);
        foreach (['permissions', 'roles', 'permission_role', 'actor_role', 'permission_audit_log'] as $table) {
            $this->assertFalse($schema->hasTable($table), "table '{$table}' must be gone after rollback");
        }
        $this->assertSame([], $manager->getApplied());
    }

    // ── §4.3: prefix-scoped shared-DB deployments ─────────────────

    public function testMigrationsHonorDatabasePrefix(): void
    {
        $db = $this->migrateSqliteDb('rzt_');

        $physical = $this->pdo($db)
            ->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'rzt_%' AND name NOT LIKE '%migration%' ORDER BY name")
            ->fetchAll(PDO::FETCH_COLUMN);

        $this->assertSame(
            ['rzt_actor_role', 'rzt_permission_audit_log', 'rzt_permission_role', 'rzt_permissions', 'rzt_roles'],
            $physical,
            'raw DDL applies the prefix itself — shared-DB multi-dist isolation lever (§4.3)',
        );
        $this->assertTrue((new SchemaBuilder($db))->hasTable('permissions'), 'hasTable resolves through the prefix too');
    }

    // ── Contract mirror vs LIVE schema ────────────────────────────

    public function testContractMirrorMatchesLiveSqliteSchema(): void
    {
        $db = $this->migrateSqliteDb();
        /** @var array<string, Contract> $contracts */
        $contracts = require __DIR__ . '/../modules/permissions/default/controller/support/contracts.php';

        $this->assertSame(['permissions', 'roles', 'permission_role', 'actor_role', 'permission_audit_log'], \array_keys($contracts));

        foreach ($contracts as $name => $contract) {
            $this->assertInstanceOf(Contract::class, $contract);
            $pragma = $this->pdo($db)->query('PRAGMA table_info(' . $name . ')')->fetchAll(PDO::FETCH_ASSOC);
            $this->assertNotSame([], $pragma, "PRAGMA must see '{$name}'");

            $live = \array_map(static fn (array $r): string => (string) $r['name'], $pragma);
            $this->assertSame(
                \array_keys($contract->getFields()),
                $live,
                "Contract mirror drifted from the live schema on '{$name}' (names AND order)",
            );
        }
    }

    public function testResolverDegradesToNullOnEveryUnhappyConfig(): void
    {
        $resolve = $this->resolver();

        $this->assertNull($resolve(null));
        $this->assertNull($resolve('mysql'));
        $this->assertNull($resolve([]), 'absent config = no database, never a guess');
        $this->assertNull($resolve(['type' => '', 'connection' => []]));
        $this->assertNull($resolve(['type' => 'nosuchdriver', 'connection' => []]));
        $this->assertNull(
            $resolve(['type' => 'sqlite', 'connection' => ['database' => 'Q:\definitely-not-a-drive\x.db']]),
            'failed connect must surface as null, not an unconnected handle',
        );
    }

    public function testResolverConnectsFromValidFileSqliteConfig(): void
    {
        // bare ['type'=>'sqlite'] is a LEGAL config, not a failure: the driver
        // itself defaults to ':memory:' (Database/Driver/SQLite.php:48). The
        // resolver does not second-guess driver defaults.
        $memory = ($this->resolver())(['type' => 'sqlite']);
        $this->assertInstanceOf(Database::class, $memory);
        $this->assertTrue($memory->isConnected());

        $file = $this->makeTempDir() . '/app.db';

        $first = ($this->resolver())(['type' => 'sqlite', 'connection' => ['database' => $file]]);

        $this->assertInstanceOf(Database::class, $first);
        $this->assertTrue($first->isConnected());
        $first->execute($first->prepare('CREATE TABLE probe (x INTEGER NOT NULL)'));
        $first->execute($first->prepare('INSERT INTO probe (x) VALUES (7)'));

        // second call = fresh instance against the SAME file (no ambient sharing)
        $again = ($this->resolver())(['type' => 'sqlite', 'connection' => ['database' => $file]]);
        $this->assertInstanceOf(Database::class, $again);
        $this->assertNotSame($first, $again);
        $this->assertSame(
            [[7]],
            \array_map(static fn (array $r): array => [(int) $r['x']], $this->pdo($again)->query('SELECT x FROM probe')->fetchAll(PDO::FETCH_ASSOC)),
            'persistence lives in the declared file, not in a module-held singleton',
        );
    }

    /**
     * @dataProvider gateMatrix
     */
    public function testApiGateMatrix(string $method, string $callerCode, array $config, bool $allowed): void
    {
        $controller = $this->gateController($config);

        $this->assertSame(
            $allowed,
            $controller->__onAPICall($this->caller($callerCode), $method),
            "gate decision for '{$method}' called by '{$callerCode}'",
        );
    }

    private function makeTempDir(): string
    {
        if ($this->tempDir === '') {
            $this->tempDir = \sys_get_temp_dir() . '/razy_perm_test_' . \uniqid();
            \mkdir($this->tempDir, 0o777, true);
        }

        return $this->tempDir;
    }

    private function migrateSqliteDb(?string $prefix = null): Database
    {
        static $counter = 0;
        $db = new Database('perm_test_' . (++$counter));
        $this->assertTrue($db->connectWithDriver('sqlite', ['database' => ':memory:']));

        if ($prefix !== null) {
            $db->setPrefix($prefix);
        }

        $manager = new MigrationManager($db);
        $manager->addPath(self::MIGRATION_DIR);
        $manager->migrate();

        return $db;
    }

    private function pdo(Database $db): PDO
    {
        $adapter = $db->getDriver()?->getAdapter();
        $this->assertInstanceOf(PDO::class, $adapter);

        return $adapter;
    }

    // ── config-connect resolver ───────────────────────────────────

    private function resolver(): callable
    {
        return require __DIR__ . '/../modules/permissions/default/controller/support/database.php';
    }

    // ── two-tier __onAPICall gate (Q4) ────────────────────────────

    /**
     * @param array<string, mixed> $config
     */
    private function gateController(array $config): object
    {
        $prototype = require __DIR__ . '/../modules/permissions/default/controller/permissions.php';
        $class = \get_class($prototype);

        // Configuration loads a real file (Configuration.php:44 takes a PATH) —
        // exercising the actual module-config read path, not a fake.
        $dir = $this->makeTempDir();
        $file = $dir . '/permissions.php';
        \file_put_contents($file, '<?php return ' . \var_export($config, true) . ';');

        $module = $this->createMock(Module::class);
        $module->method('loadConfig')->willReturn(new Configuration($file));

        /** @var object $controller */
        $controller = new $class($module);

        return $controller;
    }

    private function caller(string $code): ModuleInfo
    {
        $info = $this->createMock(ModuleInfo::class);
        $info->method('getCode')->willReturn($code);

        return $info;
    }
}
