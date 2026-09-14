<?php

declare(strict_types=1);

namespace Razy\Tests;

use DateInterval;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Razy\Agent;
use Razy\Cache\CacheInterface;
use Razy\Configuration;
use Razy\Database;
use Razy\Database\MigrationManager;
use Razy\Module;
use Razy\Module\permissions\PermissionController;
use Razy\Module\permissions\Service;
use Razy\ModuleInfo;

// the suite has no module autoloader (PermissionsTemplateTest precedent)
require_once SYSTEM_ROOT . '/modules/permissions/default/controller/support/Service.php';
require_once SYSTEM_ROOT . '/modules/permissions/default/controller/support/controller.php';

/**
 * Minimal in-process cache adapter for exercising the S5 layer WITHOUT the
 * Razy\Cache facade's static state (CacheInterface is the seam the Service
 * takes; the facade merely supplies it in production, Cache::getAdapter).
 *
 * Razy\Cache\CacheInterface, 8 methods, PSR-16 style.
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $store = [];

    /** @var list<string> */
    public array $deletes = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store[$key] ?? $default;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->store[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        $this->deletes[] = $key;
        unset($this->store[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->store = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];

        foreach ($keys as $key) {
            $out[$key] = $this->get((string) $key, $default);
        }

        return $out;
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete((string) $key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->store);
    }
}

/**
 * S5 governance polish, part 1: the cross-request cache (§7.6) and the
 * opt-in audit trail (§8 read side). CLI_MODE is true under phpunit
 * (bootstrap:27-28), so identity rides system_actors — the DB decision
 * path is the one under test (super would short-circuit before any read).
 */
#[CoversNothing]
class PermissionsS5Test extends TestCase
{
    private const MODULE_DIR = SYSTEM_ROOT . '/modules/permissions/default';

    /** @var list<string> */
    private array $tempFiles = [];

    private string $prevSuper = '';

    protected function setUp(): void
    {
        // a developer-wide RAZY_SUPER_ADMINS must not silently grant (S4 file
        // does the same save/blank/restore dance)
        $this->prevSuper = (string) \getenv('RAZY_SUPER_ADMINS');
        \putenv('RAZY_SUPER_ADMINS=');
    }

    protected function tearDown(): void
    {
        \putenv('RAZY_SUPER_ADMINS=' . $this->prevSuper);

        // worker-mode antidote (Database::resetInstances, the documented
        // §7.6 pattern): release the sqlite handles so temp files unlink
        Database::resetInstances();

        foreach ($this->tempFiles as $file) {
            @\unlink($file);
        }

        $this->tempFiles = [];
    }

    // ── cache layer (§7.6) ────────────────────────────────────────

    public function testCacheLayerServesSecondServiceWithoutTouchingTheDatabase(): void
    {
        $db = $this->seededDb();
        $cache = new ArrayCache();
        $config = ['cache_ttl' => 60, 'system_actors' => ['user:7']];

        $first = new Service($db, $config, true, $cache);
        $this->assertTrue($first->can('demo.view'));

        // out-of-band write BYPASSING the module API (bulk governance edit,
        // app-side script): within the TTL the cached answer is by design
        // the stale-but-safe one — and provably zero queries this time.
        $db->execute($db->prepare('DELETE FROM permission_role'));

        $second = new Service($db, $config, true, $cache);
        $before = $db->getTotalQueryCount();
        $this->assertTrue($second->can('demo.view'), 'served from cache (the §7.6 cross-request layer)');
        $this->assertSame($before, $db->getTotalQueryCount(), 'a cache hit must issue no query at all');
    }

    public function testApiWritesInvalidateTheActorCacheUnconditionally(): void
    {
        // §7.6's MANDATE: no cross-request cache exists without
        // invalidation-on-write. Both mutation verbs must clear the key,
        // revoke even when zero rows matched (the delete is a signal).
        $db = $this->seededDb();
        $cache = new ArrayCache();
        $config = ['cache_ttl' => 60, 'system_actors' => ['user:7']];
        $key = 'razymod/permissions.abilities.' . \sha1('user:7');

        $svc = new Service($db, $config, true, $cache);
        $svc->can('demo.view');
        $this->assertArrayHasKey($key, $cache->store, 'the read populated the cache');

        // a REAL write: revoke drops the row -> the cached set must die with it
        $this->assertSame(1, $svc->revokeRole('user', '7', 'viewer')['revoked']);
        $this->assertArrayNotHasKey($key, $cache->store, 'revoke cleared the cached ability set');
        $this->assertFalse($svc->can('demo.view'), 'post-revoke the truth is visible immediately');
        $this->assertSame([], $cache->store[$key], 'even the EMPTY deny-set is cached (fail-closed direction)');

        // the other real write: assign re-creates the grant -> clears again
        $this->assertTrue($svc->assignRole('user', '7', 'viewer')['created']);
        $this->assertArrayNotHasKey($key, $cache->store, 'created=true invalidates');
        $this->assertTrue($svc->can('demo.view'), 're-read sees the restored grant');
        $this->assertSame(['demo.view' => true], $cache->store[$key]);

        // created=false writes NOTHING to the DB -> nothing went stale;
        // invalidating on no-op would only erase the cache's purpose
        $this->assertTrue($svc->assignRole('user', '7', 'viewer')['ok'], 'idempotent re-assign');
        $this->assertArrayHasKey($key, $cache->store, 'no-op assign keeps the still-true cache');

        // direct verb (governor tools may call it explicitly): safe no-op shape
        $svc->invalidateActor('user:7');
        $this->assertArrayNotHasKey($key, $cache->store);
    }

    public function testDefaultIsUncachedAndOutlierTtlZeroNeverTouchesTheAdapter(): void
    {
        $db = $this->seededDb();
        $cache = new ArrayCache();

        // no cache_ttl key at all (the shipped default)
        $svc = new Service($db, ['system_actors' => ['user:7']], true, $cache);
        $this->assertTrue($svc->can('demo.view'));
        $this->assertSame([], $cache->store, 'default config = no cross-request state by construction');

        $db->execute($db->prepare('DELETE FROM permission_role'));
        // a NEW Service — the same instance legitimately keeps the §7.6
        // per-request memo; "uncached" means across calls, not within one
        $this->assertFalse((new Service($db, ['system_actors' => ['user:7']], true, $cache))->can('demo.view'), 'uncached reads see the truth immediately');

        // adapter present, ttl zero: same contract
        $svc2 = new Service($db, ['cache_ttl' => 0, 'system_actors' => ['user:7']], true, $cache);
        $this->assertFalse($svc2->can('demo.view'));
        $this->assertSame([], $cache->store);
    }

    public function testForeignCacheShapeRecomputesInsteadOfGranting(): void
    {
        // defense against a shared key-space holding foreign values: only the
        // EXACT own shape (string keys => true) is trusted; anything else
        // recomputes from the DB rather than trusting, never grants.
        $db = $this->seededDb();
        $cache = new ArrayCache();
        $key = 'razymod/permissions.abilities.' . \sha1('user:7');
        $cache->store[$key] = ['made.up' => 'not-a-true-flag'];

        $svc = new Service($db, ['cache_ttl' => 60, 'system_actors' => ['user:7']], true, $cache);
        $this->assertTrue($svc->can('demo.view'), 'recomputed from the real grant');
        $this->assertFalse($svc->can('made.up'), 'the foreign value granted nothing');
        $this->assertSame(['demo.view' => true], $cache->store[$key], 'the bad entry was overwritten by the truth');
    }

    public function testServiceWithoutAdapterRunsNormallyWithTtlConfigured(): void
    {
        $db = $this->seededDb();
        $config = ['cache_ttl' => 3600, 'system_actors' => ['user:7']];

        $first = new Service($db, $config, true); // no adapter = production pre-initialize() posture
        $this->assertTrue($first->can('demo.view'));

        $db->execute($db->prepare('DELETE FROM permission_role'));
        $second = new Service($db, $config, true);
        $this->assertFalse($second->can('demo.view'), 'ttl without an adapter caches nothing (fail-safe, not fail-open)');
    }

    // ── audit trail (§8 read side) ────────────────────────────────

    public function testAuditListenerIsRegisteredOnlyWhenConfigSaysSo(): void
    {
        $listeners = [];
        $this->controllerWithListenerCapture(['system_actors' => ['user:7']], $listeners);
        $this->assertSame([], $listeners, 'audit OFF by default: the event-only doctrine (§8) intact, table stays empty');

        $listeners = [];
        $this->controllerWithListenerCapture(['audit' => true, 'system_actors' => ['user:7']], $listeners);
        $this->assertArrayHasKey('razymod/permissions:permission.denied', $listeners, 'the module listens to its OWN qualified event');
    }

    public function testDenialRowsAreWrittenAndReadNewestFirstWithLimit(): void
    {
        $dbName = 'perm_s5_' . \bin2hex(\random_bytes(6));
        $dbFile = $this->temp($dbName . '.sqlite');
        $listeners = [];

        // the file DB needs the shipped schema before the listener can log
        // into it (S4 DB test pattern: the named instance IS the one the
        // config-connect resolver later hands the controller)
        $raw = new Database($dbName);
        $this->assertTrue($raw->connectWithDriver('sqlite', ['database' => $dbFile]));
        $manager = new MigrationManager($raw, 'razymod/permissions');
        $manager->addPath(self::MODULE_DIR . '/migration');
        $manager->migrate();

        $controller = $this->controllerWithListenerCapture([
            'audit' => true,
            'system_actors' => ['user:7'],
            'database' => ['type' => 'sqlite', 'connection' => ['database' => $dbFile], 'name' => $dbName],
        ], $listeners);

        $listener = $listeners['razymod/permissions:permission.denied'];
        $this->assertIsCallable($listener);

        // payload arrives exactly as Gate::deny triggers it (§8)
        $this->assertSame(
            ['audited' => true],
            $listener(['actor_key' => 'user:7', 'ability' => 'demo.view', 'source' => 'gate']),
        );
        $listener(['actor_key' => 'user:7', 'ability' => 'demo.delete', 'source' => 'gate']);

        // malformed payload: keys absent -> auditLog skips, NOTHING throws
        $this->assertSame(['audited' => true], $listener([]));

        $rows = $controller->service()->auditActor('user:7');
        $this->assertCount(2, $rows, 'the empty-key payload added no row');
        $this->assertSame('demo.delete', $rows[0]['ability'], 'newest first (id DESC)');
        $this->assertSame('demo.view', $rows[1]['ability']);
        $this->assertSame('user:7', $rows[0]['actor_key']);
        $this->assertSame('gate', $rows[0]['source']);

        $this->assertCount(1, $controller->service()->auditActor('user:7', 1), 'limit honored');
        $this->assertSame([], $controller->service()->auditActor(''), 'empty actor key asks for nothing');
        $this->assertSame([], $controller->service()->auditActor('user:404'), 'unknown actor: empty, not an error');
    }

    public function testAuditSurfaceIsSafeWithoutAnyDatabase(): void
    {
        $svc = new Service(null, ['audit' => true], true);

        $svc->auditLog('user:7', 'demo.view'); // best-effort: must NOT throw
        $this->assertSame([], $svc->auditActor('user:7'));
        $this->assertSame([], $svc->auditActor(''));
        // and the decision path still answers fail-closed with no db at all
        $this->assertFalse($svc->can('demo.view', 'user:7'));
    }

    // ── harness ───────────────────────────────────────────────────

    /**
     * Fresh sqlite db carrying the shipped schema (both migrations) and one
     * grant: role 'viewer' -> permission 'demo.view' -> actor user:7.
     */
    private function seededDb(): Database
    {
        $db = new Database('perm_s5_' . \bin2hex(\random_bytes(6)));
        $this->assertTrue($db->connectWithDriver('sqlite', ['database' => ':memory:']));

        $manager = new MigrationManager($db, 'razymod/permissions');
        $manager->addPath(self::MODULE_DIR . '/migration');
        $manager->migrate();

        $seed = function (string $sql) use ($db): void {
            $db->execute($db->prepare($sql));
        };
        $seed("INSERT INTO permissions (code, module_code) VALUES ('demo.view', 'demo/mod')");
        $seed("INSERT INTO roles (code) VALUES ('viewer')");
        $seed('INSERT INTO permission_role (role_id, permission_id) VALUES (1, 1)');
        $seed("INSERT INTO actor_role (actor_type, actor_id, role_id) VALUES ('user', '7', 1)");

        return $db;
    }

    /**
     * Same module-config harness as the S4 template tests, but the Agent
     * mock CAPTURES $agent->listen(...) callables so the test can fire the
     * denial event exactly as the event dispatcher would.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $listeners captured, by reference
     */
    private function controllerWithListenerCapture(array $config, array &$listeners): PermissionController
    {
        $configPath = $this->temp(\uniqid('perm_s5_cfg_', true) . '.php');
        \file_put_contents($configPath, '<?php return ' . \var_export($config, true) . ';');

        $info = $this->createMock(ModuleInfo::class);
        $info->method('getPath')->willReturn(self::MODULE_DIR);
        $info->method('getCode')->willReturn('razymod/permissions');

        $module = $this->createMock(Module::class);
        $module->method('getModuleInfo')->willReturn($info);
        $module->method('loadConfig')->willReturn(new Configuration($configPath));

        $agent = $this->createMock(Agent::class);
        $agent->method('listen')->willReturnCallback(
            static function (mixed $event, null|string|callable $path = null) use (&$listeners): bool {
                if (\is_callable($path)) {
                    $listeners[(string) $event] = $path;
                }

                return true;
            },
        );

        $controller = new PermissionController($module);
        $controller->__onInit($agent);

        return $controller;
    }

    private function temp(string $name): string
    {
        $path = \sys_get_temp_dir() . '/razy_perm_s5_' . $name;
        $this->tempFiles[] = $path;

        return $path;
    }
}
