<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Database;
use Razy\ORM\Model;
use Razy\ORM\ModelCollection;
use Razy\ORM\Relation\BelongsToMany;
use Razy\ORM\Relation\Relation;

/**
 * Tests for P16: BelongsToMany (Many-to-Many Relations).
 *
 * Covers the BelongsToMany class, pivot table operations (attach, detach, sync),
 * and the Model::belongsToMany() convenience method.
 */
#[CoversClass(BelongsToMany::class)]
#[CoversClass(Model::class)]
#[CoversClass(Relation::class)]
class BelongsToManyTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Section 1: Pivot table name inference
    // ═══════════════════════════════════════════════════════════════

    public function testPivotTableNameInferredAlphabetically(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $relation = $user->roles();

        // "btm_role" and "btm_user" → alphabetically: btm_role_btm_user
        // But convention uses short model name stems: role, user → role_user
        $this->assertSame('btm_role_btm_user', $relation->getPivotTable());
    }

    public function testExplicitPivotTableName(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $relation = $user->tagsExplicit();

        $this->assertSame('taggables', $relation->getPivotTable());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 2: Key inference
    // ═══════════════════════════════════════════════════════════════

    public function testForeignPivotKeyInferred(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $relation = $user->roles();

        $this->assertSame('btm_user_id', $relation->getForeignPivotKey());
    }

    public function testRelatedPivotKeyInferred(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $relation = $user->roles();

        $this->assertSame('btm_role_id', $relation->getRelatedPivotKey());
    }

    public function testRelatedKeyDefault(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $relation = $user->roles();

        $this->assertSame('id', $relation->getRelatedKey());
    }

    public function testLocalKeyDefault(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $relation = $user->roles();

        // The parent-side key (localKey in base Relation) defaults to 'id'
        $this->assertSame('id', $relation->getLocalKey());
    }

    public function testExplicitKeys(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $relation = $user->tagsExplicit();

        $this->assertSame('user_id', $relation->getForeignPivotKey());
        $this->assertSame('tag_id', $relation->getRelatedPivotKey());
        $this->assertSame('id', $relation->getRelatedKey());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 3: resolve() — basic resolution
    // ═══════════════════════════════════════════════════════════════

    public function testResolveReturnsEmptyCollectionWhenNoRelated(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $result = $user->roles()->resolve();

        $this->assertInstanceOf(ModelCollection::class, $result);
        $this->assertCount(0, $result);
    }

    public function testResolveReturnsSingleRelatedModel(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $admin = BTM_Role::create($db, ['name' => 'admin']);

        $user->roles()->attach($admin->getKey());

        $roles = $user->roles()->resolve();

        $this->assertCount(1, $roles);
        $this->assertSame('admin', $roles->first()->name);
    }

    public function testResolveReturnsMultipleRelatedModels(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $admin = BTM_Role::create($db, ['name' => 'admin']);
        $editor = BTM_Role::create($db, ['name' => 'editor']);
        $viewer = BTM_Role::create($db, ['name' => 'viewer']);

        $user->roles()->attach([$admin->getKey(), $editor->getKey(), $viewer->getKey()]);

        $roles = $user->roles()->resolve();

        $this->assertCount(3, $roles);
        $names = $roles->pluck('name');
        sort($names);
        $this->assertSame(['admin', 'editor', 'viewer'], $names);
    }

    public function testResolveDoesNotIncludeUnrelatedModels(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $alice = BTM_User::create($db, ['name' => 'Alice']);
        $bob = BTM_User::create($db, ['name' => 'Bob']);
        $admin = BTM_Role::create($db, ['name' => 'admin']);
        $editor = BTM_Role::create($db, ['name' => 'editor']);

        $alice->roles()->attach($admin->getKey());
        $bob->roles()->attach($editor->getKey());

        $aliceRoles = $alice->roles()->resolve();
        $this->assertCount(1, $aliceRoles);
        $this->assertSame('admin', $aliceRoles->first()->name);

        $bobRoles = $bob->roles()->resolve();
        $this->assertCount(1, $bobRoles);
        $this->assertSame('editor', $bobRoles->first()->name);
    }

    public function testResolveReturnsEmptyWhenParentKeyNull(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        // Create a user without saving — no PK
        $user = new BTM_User();
        $user->setDatabase($db);
        $user->name = 'Ghost';

        $relation = $user->roles();
        $result = $relation->resolve();

        $this->assertInstanceOf(ModelCollection::class, $result);
        $this->assertCount(0, $result);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 4: Magic property access (__get delegation)
    // ═══════════════════════════════════════════════════════════════

    public function testMagicPropertyAccessResolvesRelation(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $admin = BTM_Role::create($db, ['name' => 'admin']);
        $user->roles()->attach($admin->getKey());

        // Access via __get → triggers resolveRelation → roles() → resolve()
        $roles = $user->roles;

        $this->assertInstanceOf(ModelCollection::class, $roles);
        $this->assertCount(1, $roles);
        $this->assertSame('admin', $roles->first()->name);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 5: attach()
    // ═══════════════════════════════════════════════════════════════

    public function testAttachSingleId(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $role = BTM_Role::create($db, ['name' => 'admin']);

        $user->roles()->attach($role->getKey());

        $this->assertCount(1, $user->roles()->resolve());
    }

    public function testAttachMultipleIds(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $r1 = BTM_Role::create($db, ['name' => 'admin']);
        $r2 = BTM_Role::create($db, ['name' => 'editor']);

        $user->roles()->attach([$r1->getKey(), $r2->getKey()]);

        $this->assertCount(2, $user->roles()->resolve());
    }

    public function testAttachWithExtraAttributes(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchemaWithPivotExtra($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $tag = BTM_Tag::create($db, ['label' => 'php']);

        $user->tagsExplicit()->attach($tag->getKey(), ['note' => 'primary']);

        // Verify pivot row has the extra column
        $rows = $db->prepare()
            ->select('user_id,tag_id,note')
            ->from('taggables')
            ->lazyGroup();

        $this->assertCount(1, $rows);
        $this->assertSame('primary', $rows[0]['note']);
    }

    public function testAttachIsIdempotentCreatesMultipleRows(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $role = BTM_Role::create($db, ['name' => 'admin']);

        $user->roles()->attach($role->getKey());
        $user->roles()->attach($role->getKey()); // duplicate attach

        // Two rows in pivot (no unique constraint by default)
        $rows = $db->prepare()
            ->select('btm_user_id,btm_role_id')
            ->from('btm_role_btm_user')
            ->lazyGroup();
        $this->assertCount(2, $rows);
    }

    public function testAttachThrowsWhenParentKeyNull(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = new BTM_User();
        $user->setDatabase($db);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot attach');

        $user->roles()->attach(1);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 6: detach()
    // ═══════════════════════════════════════════════════════════════

    public function testDetachSingleId(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $r1 = BTM_Role::create($db, ['name' => 'admin']);
        $r2 = BTM_Role::create($db, ['name' => 'editor']);

        $user->roles()->attach([$r1->getKey(), $r2->getKey()]);
        $this->assertCount(2, $user->roles()->resolve());

        $deleted = $user->roles()->detach($r1->getKey());
        $this->assertSame(1, $deleted);

        $remaining = $user->roles()->resolve();
        $this->assertCount(1, $remaining);
        $this->assertSame('editor', $remaining->first()->name);
    }

    public function testDetachMultipleIds(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $r1 = BTM_Role::create($db, ['name' => 'admin']);
        $r2 = BTM_Role::create($db, ['name' => 'editor']);
        $r3 = BTM_Role::create($db, ['name' => 'viewer']);

        $user->roles()->attach([$r1->getKey(), $r2->getKey(), $r3->getKey()]);

        $deleted = $user->roles()->detach([$r1->getKey(), $r3->getKey()]);
        $this->assertSame(2, $deleted);
        $this->assertCount(1, $user->roles()->resolve());
    }

    public function testDetachAllWhenNoArgument(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $r1 = BTM_Role::create($db, ['name' => 'admin']);
        $r2 = BTM_Role::create($db, ['name' => 'editor']);

        $user->roles()->attach([$r1->getKey(), $r2->getKey()]);

        $deleted = $user->roles()->detach();
        $this->assertSame(2, $deleted);
        $this->assertCount(0, $user->roles()->resolve());
    }

    public function testDetachReturnZeroWhenNothingToDetach(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);

        $deleted = $user->roles()->detach(99);
        $this->assertSame(0, $deleted);
    }

    public function testDetachReturnZeroWhenParentKeyNull(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = new BTM_User();
        $user->setDatabase($db);

        $deleted = $user->roles()->detach();
        $this->assertSame(0, $deleted);
    }

    public function testDetachDoesNotAffectOtherParents(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $alice = BTM_User::create($db, ['name' => 'Alice']);
        $bob = BTM_User::create($db, ['name' => 'Bob']);
        $admin = BTM_Role::create($db, ['name' => 'admin']);

        $alice->roles()->attach($admin->getKey());
        $bob->roles()->attach($admin->getKey());

        $alice->roles()->detach($admin->getKey());

        // Alice has no roles, Bob still does
        $this->assertCount(0, $alice->roles()->resolve());
        $this->assertCount(1, $bob->roles()->resolve());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 7: sync()
    // ═══════════════════════════════════════════════════════════════

    public function testSyncAttachesNewIds(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $r1 = BTM_Role::create($db, ['name' => 'admin']);
        $r2 = BTM_Role::create($db, ['name' => 'editor']);

        $changes = $user->roles()->sync([$r1->getKey(), $r2->getKey()]);

        $this->assertCount(2, $user->roles()->resolve());
        $this->assertContains($r1->getKey(), $changes['attached']);
        $this->assertContains($r2->getKey(), $changes['attached']);
        $this->assertEmpty($changes['detached']);
    }

    public function testSyncDetachesRemovedIds(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $r1 = BTM_Role::create($db, ['name' => 'admin']);
        $r2 = BTM_Role::create($db, ['name' => 'editor']);
        $r3 = BTM_Role::create($db, ['name' => 'viewer']);

        $user->roles()->attach([$r1->getKey(), $r2->getKey(), $r3->getKey()]);

        // Sync to only r2 — r1 and r3 should be detached
        $changes = $user->roles()->sync([$r2->getKey()]);

        $this->assertCount(1, $user->roles()->resolve());
        $this->assertEmpty($changes['attached']);
        $this->assertContains($r1->getKey(), $changes['detached']);
        $this->assertContains($r3->getKey(), $changes['detached']);
    }

    public function testSyncBothAttachesAndDetaches(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $r1 = BTM_Role::create($db, ['name' => 'admin']);
        $r2 = BTM_Role::create($db, ['name' => 'editor']);
        $r3 = BTM_Role::create($db, ['name' => 'viewer']);

        $user->roles()->attach([$r1->getKey(), $r2->getKey()]);

        // Sync to r2 and r3 — r1 detached, r3 attached
        $changes = $user->roles()->sync([$r2->getKey(), $r3->getKey()]);

        $remaining = $user->roles()->resolve();
        $this->assertCount(2, $remaining);

        $names = $remaining->pluck('name');
        sort($names);
        $this->assertSame(['editor', 'viewer'], $names);

        $this->assertContains($r3->getKey(), $changes['attached']);
        $this->assertContains($r1->getKey(), $changes['detached']);
    }

    public function testSyncEmptyArrayDetachesAll(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $r1 = BTM_Role::create($db, ['name' => 'admin']);
        $user->roles()->attach($r1->getKey());

        $changes = $user->roles()->sync([]);

        $this->assertCount(0, $user->roles()->resolve());
        $this->assertContains($r1->getKey(), $changes['detached']);
        $this->assertEmpty($changes['attached']);
    }

    public function testSyncNoChangesWhenAlreadyInSync(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $r1 = BTM_Role::create($db, ['name' => 'admin']);
        $r2 = BTM_Role::create($db, ['name' => 'editor']);

        $user->roles()->attach([$r1->getKey(), $r2->getKey()]);

        $changes = $user->roles()->sync([$r1->getKey(), $r2->getKey()]);

        $this->assertCount(2, $user->roles()->resolve());
        $this->assertEmpty($changes['attached']);
        $this->assertEmpty($changes['detached']);
    }

    public function testSyncThrowsWhenParentKeyNull(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = new BTM_User();
        $user->setDatabase($db);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot sync');

        $user->roles()->sync([1]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 8: Bidirectional relationship
    // ═══════════════════════════════════════════════════════════════

    public function testInverseSideResolvesCorrectly(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $admin = BTM_Role::create($db, ['name' => 'admin']);

        $user->roles()->attach($admin->getKey());

        // Resolve from role → users
        $users = $admin->users()->resolve();

        $this->assertInstanceOf(ModelCollection::class, $users);
        $this->assertCount(1, $users);
        $this->assertSame('Alice', $users->first()->name);
    }

    public function testBidirectionalManyToMany(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $alice = BTM_User::create($db, ['name' => 'Alice']);
        $bob = BTM_User::create($db, ['name' => 'Bob']);
        $admin = BTM_Role::create($db, ['name' => 'admin']);
        $editor = BTM_Role::create($db, ['name' => 'editor']);

        $alice->roles()->attach([$admin->getKey(), $editor->getKey()]);
        $bob->roles()->attach($editor->getKey());

        // Alice → 2 roles
        $this->assertCount(2, $alice->roles()->resolve());

        // Bob → 1 role
        $this->assertCount(1, $bob->roles()->resolve());

        // admin role → 1 user (Alice)
        $adminUsers = $admin->users()->resolve();
        $this->assertCount(1, $adminUsers);
        $this->assertSame('Alice', $adminUsers->first()->name);

        // editor role → 2 users
        $editorUsers = $editor->users()->resolve();
        $this->assertCount(2, $editorUsers);
        $userNames = $editorUsers->pluck('name');
        sort($userNames);
        $this->assertSame(['Alice', 'Bob'], $userNames);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 9: resolve() returns ModelCollection type
    // ═══════════════════════════════════════════════════════════════

    public function testResolveReturnTypeIsModelCollection(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $result = $user->roles()->resolve();

        $this->assertInstanceOf(ModelCollection::class, $result);
    }

    public function testResolvedModelsAreCorrectType(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $role = BTM_Role::create($db, ['name' => 'admin']);
        $user->roles()->attach($role->getKey());

        $roles = $user->roles()->resolve();
        $this->assertInstanceOf(BTM_Role::class, $roles->first());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 10: Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testDetachSpecificIdFromMultiple(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $r1 = BTM_Role::create($db, ['name' => 'admin']);
        $r2 = BTM_Role::create($db, ['name' => 'editor']);
        $r3 = BTM_Role::create($db, ['name' => 'viewer']);

        $user->roles()->attach([$r1->getKey(), $r2->getKey(), $r3->getKey()]);

        // Detach only the middle one
        $user->roles()->detach($r2->getKey());

        $remaining = $user->roles()->resolve();
        $this->assertCount(2, $remaining);
        $names = $remaining->pluck('name');
        sort($names);
        $this->assertSame(['admin', 'viewer'], $names);
    }

    public function testAttachAndResolveLargeSet(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);

        $ids = [];
        for ($i = 0; $i < 20; $i++) {
            $role = BTM_Role::create($db, ['name' => 'role_' . $i]);
            $ids[] = $role->getKey();
        }

        $user->roles()->attach($ids);

        $roles = $user->roles()->resolve();
        $this->assertCount(20, $roles);
    }

    public function testDetachNonexistentIdReturnsZero(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $role = BTM_Role::create($db, ['name' => 'admin']);
        $user->roles()->attach($role->getKey());

        // 999 is not attached
        $deleted = $user->roles()->detach(999);
        $this->assertSame(0, $deleted);

        // Original is still there
        $this->assertCount(1, $user->roles()->resolve());
    }

    public function testSyncReturnsArrayWithAttachedAndDetachedKeys(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $r1 = BTM_Role::create($db, ['name' => 'admin']);

        $changes = $user->roles()->sync([$r1->getKey()]);

        $this->assertArrayHasKey('attached', $changes);
        $this->assertArrayHasKey('detached', $changes);
        $this->assertIsArray($changes['attached']);
        $this->assertIsArray($changes['detached']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 11: Getters
    // ═══════════════════════════════════════════════════════════════

    public function testGetPivotTable(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $this->assertSame('btm_role_btm_user', $user->roles()->getPivotTable());
    }

    public function testGetForeignPivotKey(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $this->assertSame('btm_user_id', $user->roles()->getForeignPivotKey());
    }

    public function testGetRelatedPivotKey(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $this->assertSame('btm_role_id', $user->roles()->getRelatedPivotKey());
    }

    public function testGetRelatedKey(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $this->assertSame('id', $user->roles()->getRelatedKey());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 12: BelongsToMany is a Relation
    // ═══════════════════════════════════════════════════════════════

    public function testBelongsToManyExtendsRelation(): void
    {
        $db = $this->createSqliteDb();
        $this->createSchema($db);

        $user = BTM_User::create($db, ['name' => 'Alice']);
        $relation = $user->roles();

        $this->assertInstanceOf(Relation::class, $relation);
        $this->assertInstanceOf(BelongsToMany::class, $relation);
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    private function createSqliteDb(): Database
    {
        static $counter = 0;
        $db = new Database('btm_test_' . (++$counter));
        $db->connectWithDriver('sqlite', ['path' => ':memory:']);

        return $db;
    }

    /**
     * Create the standard test schema: users, roles, and the pivot table.
     */
    private function createSchema(Database $db): void
    {
        $db->clearStatementPool();
        $db->getDBAdapter()->exec('
            CREATE TABLE IF NOT EXISTS btm_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                created_at TEXT,
                updated_at TEXT
            )
        ');

        $db->clearStatementPool();
        $db->getDBAdapter()->exec('
            CREATE TABLE IF NOT EXISTS btm_roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL
            )
        ');

        $db->clearStatementPool();
        $db->getDBAdapter()->exec('
            CREATE TABLE IF NOT EXISTS btm_role_btm_user (
                btm_user_id INTEGER NOT NULL,
                btm_role_id INTEGER NOT NULL
            )
        ');

        $db->clearStatementPool();
        $db->getDBAdapter()->exec('
            CREATE TABLE IF NOT EXISTS btm_tags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                label TEXT NOT NULL
            )
        ');
    }

    /**
     * Create the schema including a taggables pivot table with extra columns.
     */
    private function createSchemaWithPivotExtra(Database $db): void
    {
        $this->createSchema($db);

        $db->clearStatementPool();
        $db->getDBAdapter()->exec('
            CREATE TABLE IF NOT EXISTS taggables (
                user_id INTEGER NOT NULL,
                tag_id INTEGER NOT NULL,
                note TEXT
            )
        ');
    }
}

// ═══════════════════════════════════════════════════════════════
// Test Model Doubles
// ═══════════════════════════════════════════════════════════════

/**
 * Test user model for many-to-many tests.
 */
class BTM_User extends Model
{
    protected static string $table = 'btm_users';
    protected static array $fillable = ['name'];

    /**
     * User has many roles (many-to-many).
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(BTM_Role::class);
    }

    /**
     * User has many tags via explicit pivot table + keys.
     */
    public function tagsExplicit(): BelongsToMany
    {
        return $this->belongsToMany(
            BTM_Tag::class,
            'taggables',
            'user_id',
            'tag_id',
        );
    }
}

/**
 * Test role model for many-to-many tests.
 */
class BTM_Role extends Model
{
    protected static string $table = 'btm_roles';
    protected static array $fillable = ['name'];
    protected static bool $timestamps = false;

    /**
     * Role has many users (inverse side).
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(BTM_User::class);
    }
}

/**
 * Test tag model for many-to-many tests with explicit pivot table.
 */
class BTM_Tag extends Model
{
    protected static string $table = 'btm_tags';
    protected static array $fillable = ['label'];
    protected static bool $timestamps = false;
}
