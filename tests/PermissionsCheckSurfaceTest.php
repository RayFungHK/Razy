<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Razy\Auth\AuthManager;
use Razy\Auth\CallbackGuard;
use Razy\Auth\Gate;
use Razy\Auth\GateFactory;
use Razy\Database;
use Razy\Database\MigrationManager;
use Razy\Database\SchemaBuilder;
use Razy\Module\permissions\Service;

// The repo suite has no module autoloader (namespaces live per module runtime,
// ClosureLoader); requiring the support class by path is the house test
// pattern (GateHooksTest loads controller fixtures the same way).
if (!\class_exists(Service::class, false)) {
    require_once __DIR__ . '/../modules/permissions/default/controller/support/Service.php';
}

/**
 * razymod/permissions S3 check surface: decision grammar (guest-pre-deny >
 * super > DB membership), never-throw hot path, memo + write invalidation,
 * governance mutations, CLI posture, and the Gate composition (decisive DB
 * hook behind Gate's own guest pre-deny). Service takes a plain Database —
 * no module runtime needed.
 */
#[CoversNothing]
class PermissionsCheckSurfaceTest extends TestCase
{
    private const MIGRATION_DIR = __DIR__ . '/../modules/permissions/default/migration';

    private Database $db;

    protected function setUp(): void
    {
        parent::setUp();
        unset($_SESSION);
        GateFactory::flush();

        static $counter = 0;
        $this->db = new Database('perm_s3_' . (++$counter));
        $this->db->connectWithDriver('sqlite', ['database' => ':memory:']);

        $manager = new MigrationManager($this->db);
        $manager->addPath(self::MIGRATION_DIR);
        $manager->migrate();

        // seed: ability 'queue.purge' <- role 'admin' <- actor user:1
        $this->seed('queue.purge', 'admin', 'user', '1');
    }

    protected function tearDown(): void
    {
        unset($_SESSION, $_ENV['RAZY_SUPER_ADMINS']);
        \putenv('RAZY_SUPER_ADMINS');
        GateFactory::flush();
        parent::tearDown();
    }

    // ── decision grammar ──────────────────────────────────────────

    public function testMemberAllowedNonMemberDefaultDenied(): void
    {
        $s = $this->service();

        $this->assertTrue($s->can('queue.purge', 'user:1'));
        $this->assertFalse($s->can('queue.purge', 'user:2'), 'undefined grant = deny (default-deny, no deny rows needed)');
        $this->assertFalse($s->can('never.defined', 'user:1'), 'granted actor still denied unknown ability');
    }

    public function testGuestPreDeniesBeforeEverything(): void
    {
        $s = $this->service(['super_actors' => ['user:9']]);

        // no session, no explicit actor, not CLI => guest
        $this->assertFalse($s->can('queue.purge'), 'no actor = guest');
        $this->assertSame(['allow' => false, 'actor' => null, 'via' => 'guest'], $s->decide('queue.purge'));
    }

    public function testSessionActorIsHonouredOnWeb(): void
    {
        $_SESSION = ['__auth_actor' => 'user:1'];

        $this->assertTrue($this->service()->can('queue.purge'));
    }

    public function testIntSessionValueNormalisesToUserNamespace(): void
    {
        $_SESSION = ['__auth_actor' => 1];
        $s = $this->service();

        $this->assertSame('user:1', $s->actorKey());
        $this->assertTrue($s->can('queue.purge'));
    }

    public function testSuperActorConfigOnlyAndNeverForGuests(): void
    {
        $s = $this->service(['super_actors' => ['user:9']]);

        $this->assertTrue($s->can('anything.at.all', 'user:9'));
        $this->assertFalse($s->can('anything.at.all'), 'the same super has zero power while guest (§7.3 pinned)');
    }

    public function testEnvExtendsSuperListNeverReplaces(): void
    {
        $_ENV['RAZY_SUPER_ADMINS'] = 'user:77, api-client:x';

        $s = $this->service(['super_actors' => ['user:9']]);
        $this->assertTrue($s->can('x.y', 'user:77'));
        $this->assertTrue($s->can('x.y', 'api-client:x'));
        $this->assertTrue($s->can('x.y', 'user:9'), 'env EXTENDS the config list (BridgeSignature precedent)');
    }

    public function testCliPostureSystemActorsOnlyUnderCli(): void
    {
        $this->seed('queue.purge2', 'admin2', 'user', 'svc');

        $cfg = ['system_actors' => ['user:svc']];

        $this->assertFalse($this->service($cfg, cli: false)->can('queue.purge2'), 'system_actors NEVER act on web (§7.5)');
        $this->assertTrue($this->service($cfg, cli: true)->can('queue.purge2'), 'CLI resolves the configured system actor');
        $this->assertFalse($this->service([], cli: true)->can('queue.purge2'), 'CLI without config stays guest');
    }

    // ── never-throw hot path ──────────────────────────────────────

    public function testUnreachableDatabaseCollapsesToDenyNotThrow(): void
    {
        (new SchemaBuilder($this->db))->drop('permissions'); // mid-flight schema loss
        $s = $this->service();

        $this->assertFalse($s->can('queue.purge', 'user:1'));
        $this->assertFalse($s->canAny(['queue.purge', 'other.thing'], 'user:1'));
        $this->assertSame('error', $s->decide('queue.purge', 'user:1')['via']);
    }

    public function testNullDatabaseDegradesExplicitly(): void
    {
        // new Service directly: the helper's $db ?? $this->db could not carry an explicit null
        $s = new Service(null, [], false);

        $this->assertFalse($s->can('queue.purge', 'user:1'));
        $this->assertSame([], $s->abilities());
        $this->assertSame(['ok' => false, 'error' => 'no database connection available'], $s->assignRole('user', '2', 'admin'));
        $this->assertSame(['ok' => false, 'error' => 'no database connection available'], $s->revokeRole('user', '1', 'admin'));
        $this->assertSame(['ok' => false, 'error' => 'no database connection available'], $s->defineAbility('demo.act'));
    }

    // ── memo discipline ───────────────────────────────────────────

    public function testPerActorMemoServesRepeatChecksAndAnyReusesIt(): void
    {
        $s = $this->service();
        $s->can('queue.purge', 'user:1');
        $after = $this->db->getTotalQueryCount();

        $this->assertTrue($s->can('queue.purge', 'user:1'));
        $this->assertTrue($s->canAny(['queue.purge', 'x.y'], 'user:1'));
        $this->assertSame($after, $this->db->getTotalQueryCount(), 'rechecks inside one service instance hit the memo, never the DB');
    }

    public function testWriteInvalidatesMemoWithinTheInstance(): void
    {
        $s = $this->service();
        $this->assertFalse($s->can('queue.purge', 'user:42'));

        $this->assertTrue($s->assignRole('user', '42', 'admin')['ok']);
        $this->assertTrue($s->can('queue.purge', 'user:42'), 'write-time invalidation (§7.6) beats the stale memo');
    }

    // ── governance mutations ──────────────────────────────────────

    public function testAssignRefusesUnknownRoleAndIsIdempotent(): void
    {
        $s = $this->service();

        $this->assertFalse($s->assignRole('user', '5', 'ghost')['ok'], 'unknown role never auto-creates');
        $this->assertTrue($s->assignRole('user', '5', 'admin')['created']);
        $this->assertFalse($s->assignRole('user', '5', 'admin')['created'], 'idempotent re-assign reports created:false, still ok');
        $this->assertSame(['admin'], $s->rolesOf('user', '5'));
    }

    public function testRevokeIsIdempotentNoOp(): void
    {
        $s = $this->service();

        $this->assertSame(1, $s->revokeRole('user', '1', 'admin')['revoked']);
        $this->assertSame(0, $s->revokeRole('user', '1', 'admin')['revoked'], 'revoking an absent grant is a successful no-op');
        $this->assertSame([], $s->rolesOf('user', '1'));
        $this->assertFalse($s->revokeRole('user', '1', 'ghost')['ok']);
    }

    public function testDefineAbilityValidatesDottedGrammarAndIsIdempotent(): void
    {
        $s = $this->service();

        foreach (['Queue.Purge', 'nodots', 'trailing.', '.leading', ''] as $bad) {
            $this->assertFalse($s->defineAbility($bad)['ok'], "code '{$bad}' must fail §7.4 grammar");
        }

        $this->assertTrue($s->defineAbility('demo.act', 'Demo')['created']);
        $this->assertFalse($s->defineAbility('demo.act')['created'], 'idempotent');
        $this->assertContains('demo.act', $s->abilities());
        $this->assertContains('queue.purge', $s->abilities());
    }

    public function testGateAnswersFromDbThroughS1Hook(): void
    {
        $gate = $this->gateFor('user:1', ['super_actors' => ['user:9']]);

        $this->assertTrue($gate->forUser(new \Razy\Auth\GenericUser(['id' => 'user:1']))->allows('queue.purge'));
        $this->assertFalse($gate->forUser(new \Razy\Auth\GenericUser(['id' => 'user:2']))->allows('queue.purge'));
        $this->assertTrue($gate->forUser(new \Razy\Auth\GenericUser(['id' => 'user:9']))->allows('anything'), 'super semantics ride through the same decisive hook');
    }

    public function testGateGuestDeniesBeforeTheHookIsConsulted(): void
    {
        $hit = false;
        $gate = GateFactory::make('guest-dist', new AuthManager(['actor' => new CallbackGuard(fn () => null)], 'actor'), function (Gate $g) use (&$hit): void {
            $g->addBefore(function () use (&$hit): ?bool {
                $hit = true;

                return null;
            });
        });

        $this->assertFalse($gate->allows('queue.purge'));
        $this->assertFalse($hit, 'Gate.php:173 guest pre-deny still precedes DB policy (super/guest semantics unchanged by composition)');
    }

    private function seed(string $code, string $role, string $actorType, string $actorId): void
    {
        $pdo = $this->db->getDriver()->getAdapter();
        $this->db->execute($this->db->insert('permissions', ['code', 'module_code'])->assign(['code' => $code, 'module_code' => 'razymod/queue-admin']));
        $permissionId = (int) $pdo->lastInsertId();
        $this->db->execute($this->db->insert('roles', ['code'])->assign(['code' => $role]));
        $roleId = (int) $pdo->lastInsertId();
        $this->db->execute($this->db->insert('permission_role', ['role_id', 'permission_id'])->assign(['role_id' => $roleId, 'permission_id' => $permissionId]));
        $this->db->execute($this->db->insert('actor_role', ['actor_type', 'actor_id', 'role_id'])->assign(['actor_type' => $actorType, 'actor_id' => $actorId, 'role_id' => $roleId]));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function service(array $config = [], bool $cli = false, ?Database $db = null): Service
    {
        return new Service($db ?? $this->db, $config, $cli);
    }

    // ── Gate composition (S1 seam, decisive DB hook) ──────────────

    /**
     * @param array<string, mixed> $config
     */
    private function gateFor(?string $actorKey, array $config = [], string $name = 'surface-dist'): Gate
    {
        // GateFactory memoizes by NAME (first registration wins) — distinct
        // builders/auths in one process need distinct names, pinned here and
        // by testGateGuestDeniesBeforeTheHookIsConsulted below.
        return GateFactory::make($name, new AuthManager(
            ['actor' => new CallbackGuard(fn () => $actorKey === null ? null : new \Razy\Auth\GenericUser(['id' => $actorKey]))],
            'actor',
        ), function (Gate $gate) use ($config): void {
            $gate->addBefore(fn ($user, string $ability): ?bool => $this->service($config)->decide($ability, (string) $user->getAuthIdentifier())['allow']);
        });
    }
}
