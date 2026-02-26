<?php

declare(strict_types=1);

namespace Razy\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Database;
use Razy\Exception\ModelNotFoundException;
use Razy\ORM\Model;
use Razy\ORM\ModelCollection;
use Razy\ORM\ModelQuery;
use Razy\ORM\Relation\BelongsTo;
use Razy\ORM\Relation\HasMany;
use Razy\ORM\Relation\HasOne;
use Razy\ORM\Relation\Relation;
use RuntimeException;

/**
 * Tests for P7: ORM / Active Record System.
 *
 * Covers Model, ModelQuery, ModelCollection, ModelNotFoundException,
 * and Relation classes (HasOne, HasMany, BelongsTo).
 */
#[CoversClass(Model::class)]
#[CoversClass(ModelQuery::class)]
#[CoversClass(ModelCollection::class)]
#[CoversClass(ModelNotFoundException::class)]
#[CoversClass(Relation::class)]
#[CoversClass(HasOne::class)]
#[CoversClass(HasMany::class)]
#[CoversClass(BelongsTo::class)]
class ORMTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Section 1: ModelNotFoundException
    // ═══════════════════════════════════════════════════════════════

    public function testModelNotFoundExceptionIsRuntimeException(): void
    {
        $e = new ModelNotFoundException();
        $this->assertInstanceOf(RuntimeException::class, $e);
    }

    public function testModelNotFoundExceptionSetModel(): void
    {
        $e = (new ModelNotFoundException())->setModel('App\User', [42]);
        $this->assertSame('App\User', $e->getModel());
        $this->assertSame([42], $e->getIds());
    }

    public function testModelNotFoundExceptionMessage(): void
    {
        $e = (new ModelNotFoundException())->setModel('App\User', [1, 2, 3]);
        $this->assertStringContainsString('User', $e->getMessage());
        $this->assertStringContainsString('1, 2, 3', $e->getMessage());
    }

    public function testModelNotFoundExceptionEmptyIds(): void
    {
        $e = (new ModelNotFoundException())->setModel('App\User');
        $this->assertSame([], $e->getIds());
        $this->assertStringContainsString('User', $e->getMessage());
    }

    public function testModelNotFoundExceptionReturnsSelf(): void
    {
        $e = new ModelNotFoundException();
        $this->assertSame($e, $e->setModel('X'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 2: ModelCollection — Basics
    // ═══════════════════════════════════════════════════════════════

    public function testCollectionCountZero(): void
    {
        $c = new ModelCollection([]);
        $this->assertCount(0, $c);
    }

    public function testCollectionCountNonZero(): void
    {
        $m1 = $this->createStubModel(['id' => 1]);
        $m2 = $this->createStubModel(['id' => 2]);
        $c = new ModelCollection([$m1, $m2]);
        $this->assertCount(2, $c);
    }

    public function testCollectionIsEmptyTrue(): void
    {
        $this->assertTrue((new ModelCollection([]))->isEmpty());
    }

    public function testCollectionIsEmptyFalse(): void
    {
        $c = new ModelCollection([$this->createStubModel(['id' => 1])]);
        $this->assertFalse($c->isEmpty());
    }

    public function testCollectionIsNotEmpty(): void
    {
        $c = new ModelCollection([$this->createStubModel(['id' => 1])]);
        $this->assertTrue($c->isNotEmpty());
    }

    public function testCollectionFirst(): void
    {
        $m1 = $this->createStubModel(['id' => 10]);
        $m2 = $this->createStubModel(['id' => 20]);
        $c = new ModelCollection([$m1, $m2]);
        $this->assertSame($m1, $c->first());
    }

    public function testCollectionFirstEmpty(): void
    {
        $c = new ModelCollection([]);
        $this->assertNull($c->first());
    }

    public function testCollectionLast(): void
    {
        $m1 = $this->createStubModel(['id' => 10]);
        $m2 = $this->createStubModel(['id' => 20]);
        $c = new ModelCollection([$m1, $m2]);
        $this->assertSame($m2, $c->last());
    }

    public function testCollectionAll(): void
    {
        $m = $this->createStubModel(['id' => 1]);
        $c = new ModelCollection([$m]);
        $this->assertSame([$m], $c->all());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 3: ModelCollection — Transformation
    // ═══════════════════════════════════════════════════════════════

    public function testCollectionToArray(): void
    {
        $m1 = $this->createStubModel(['id' => 1, 'name' => 'a']);
        $m2 = $this->createStubModel(['id' => 2, 'name' => 'b']);
        $c = new ModelCollection([$m1, $m2]);
        $arr = $c->toArray();
        $this->assertCount(2, $arr);
        $this->assertSame('a', $arr[0]['name']);
        $this->assertSame('b', $arr[1]['name']);
    }

    public function testCollectionPluck(): void
    {
        $m1 = $this->createStubModel(['id' => 1, 'name' => 'alpha']);
        $m2 = $this->createStubModel(['id' => 2, 'name' => 'beta']);
        $c = new ModelCollection([$m1, $m2]);
        $this->assertSame(['alpha', 'beta'], $c->pluck('name'));
    }

    public function testCollectionPluckWithKey(): void
    {
        $m1 = $this->createStubModel(['id' => 1, 'name' => 'alpha']);
        $m2 = $this->createStubModel(['id' => 2, 'name' => 'beta']);
        $c = new ModelCollection([$m1, $m2]);
        $result = $c->pluck('name', 'id');
        $this->assertSame([1 => 'alpha', 2 => 'beta'], $result);
    }

    public function testCollectionMap(): void
    {
        $m1 = $this->createStubModel(['id' => 1]);
        $m2 = $this->createStubModel(['id' => 2]);
        $c = new ModelCollection([$m1, $m2]);
        $mapped = $c->map(fn (Model $m) => $m->id * 10);
        $this->assertSame([10, 20], $mapped);
    }

    public function testCollectionFilter(): void
    {
        $m1 = $this->createStubModel(['id' => 1]);
        $m2 = $this->createStubModel(['id' => 2]);
        $m3 = $this->createStubModel(['id' => 3]);
        $c = new ModelCollection([$m1, $m2, $m3]);
        $filtered = $c->filter(fn (Model $m) => $m->id > 1);
        $this->assertCount(2, $filtered);
        $this->assertSame(2, $filtered->first()->id);
    }

    public function testCollectionEach(): void
    {
        $m1 = $this->createStubModel(['id' => 1]);
        $m2 = $this->createStubModel(['id' => 2]);
        $c = new ModelCollection([$m1, $m2]);
        $visited = [];
        $c->each(function (Model $m) use (&$visited) {
            $visited[] = $m->id;
        });
        $this->assertSame([1, 2], $visited);
    }

    public function testCollectionEachBreaksOnFalse(): void
    {
        $m1 = $this->createStubModel(['id' => 1]);
        $m2 = $this->createStubModel(['id' => 2]);
        $m3 = $this->createStubModel(['id' => 3]);
        $c = new ModelCollection([$m1, $m2, $m3]);
        $visited = [];
        $c->each(function (Model $m) use (&$visited) {
            if ($m->id === 2) {
                return false;
            }
            $visited[] = $m->id;
        });
        $this->assertSame([1], $visited);
    }

    public function testCollectionContainsTrue(): void
    {
        $m1 = $this->createStubModel(['id' => 1]);
        $c = new ModelCollection([$m1]);
        $this->assertTrue($c->contains(fn (Model $m) => $m->id === 1));
    }

    public function testCollectionContainsFalse(): void
    {
        $m1 = $this->createStubModel(['id' => 1]);
        $c = new ModelCollection([$m1]);
        $this->assertFalse($c->contains(fn (Model $m) => $m->id === 999));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 4: ModelCollection — ArrayAccess & Iteration
    // ═══════════════════════════════════════════════════════════════

    public function testCollectionOffsetExists(): void
    {
        $c = new ModelCollection([$this->createStubModel(['id' => 1])]);
        $this->assertTrue(isset($c[0]));
        $this->assertFalse(isset($c[1]));
    }

    public function testCollectionOffsetGet(): void
    {
        $m = $this->createStubModel(['id' => 42]);
        $c = new ModelCollection([$m]);
        $this->assertSame($m, $c[0]);
    }

    public function testCollectionOffsetSet(): void
    {
        $m1 = $this->createStubModel(['id' => 1]);
        $m2 = $this->createStubModel(['id' => 2]);
        $c = new ModelCollection([$m1]);
        $c[0] = $m2;
        $this->assertSame($m2, $c[0]);
    }

    public function testCollectionOffsetUnset(): void
    {
        $m1 = $this->createStubModel(['id' => 1]);
        $m2 = $this->createStubModel(['id' => 2]);
        $c = new ModelCollection([$m1, $m2]);
        unset($c[0]);
        $this->assertCount(1, $c);
        $this->assertSame($m2, $c[0]); // re-indexed
    }

    public function testCollectionIterator(): void
    {
        $m1 = $this->createStubModel(['id' => 1]);
        $m2 = $this->createStubModel(['id' => 2]);
        $c = new ModelCollection([$m1, $m2]);
        $ids = [];
        foreach ($c as $model) {
            $ids[] = $model->id;
        }
        $this->assertSame([1, 2], $ids);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 5: Model — Attribute Access & Magic
    // ═══════════════════════════════════════════════════════════════

    public function testModelGetSetAttribute(): void
    {
        $user = new ORM_TestUser();
        $user->name = 'Ray';
        $this->assertSame('Ray', $user->name);
    }

    public function testModelGetNonExistentReturnsNull(): void
    {
        $user = new ORM_TestUser();
        $this->assertNull($user->nonexistent);
    }

    public function testModelIsset(): void
    {
        $user = new ORM_TestUser();
        $user->name = 'Ray';
        $this->assertTrue(isset($user->name));
        $this->assertFalse(isset($user->nonexistent));
    }

    public function testModelUnset(): void
    {
        $user = new ORM_TestUser();
        $user->name = 'Ray';
        unset($user->name);
        $this->assertNull($user->name);
    }

    public function testModelGetRawAttribute(): void
    {
        $user = new ORM_TestUser();
        $user->name = 'Ray';
        $this->assertSame('Ray', $user->getRawAttribute('name'));
    }

    public function testModelSetRawAttribute(): void
    {
        $user = new ORM_TestUser();
        $user->setRawAttribute('name', 'direct');
        $this->assertSame('direct', $user->name);
    }

    public function testModelSetRawReturnsSelf(): void
    {
        $user = new ORM_TestUser();
        $this->assertSame($user, $user->setRawAttribute('x', 'y'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 6: Model — Mass Assignment
    // ═══════════════════════════════════════════════════════════════

    public function testModelFillRespectsFillable(): void
    {
        $user = new ORM_TestUser();
        $user->fill(['name' => 'Ray', 'email' => 'ray@test.com', 'secret' => 'hidden']);
        $this->assertSame('Ray', $user->name);
        $this->assertSame('ray@test.com', $user->email);
        $this->assertNull($user->secret); // not fillable
    }

    public function testModelFillReturnsSelf(): void
    {
        $user = new ORM_TestUser();
        $this->assertSame($user, $user->fill(['name' => 'a']));
    }

    public function testModelIsFillableTrue(): void
    {
        $user = new ORM_TestUser();
        $this->assertTrue($user->isFillable('name'));
        $this->assertTrue($user->isFillable('email'));
    }

    public function testModelIsFillableFalseNotInList(): void
    {
        $user = new ORM_TestUser();
        $this->assertFalse($user->isFillable('secret'));
    }

    public function testModelGuardedStar(): void
    {
        // ORM_TestGuardedModel has guarded=['*'] and empty fillable
        $model = new ORM_TestGuardedModel();
        $model->fill(['anything' => 'value']);
        $this->assertNull($model->anything);
    }

    public function testModelGuardedSpecificColumns(): void
    {
        // ORM_TestPartialGuardModel: fillable=[], guarded=['secret']
        $model = new ORM_TestPartialGuardModel();
        $model->fill(['name' => 'ok', 'secret' => 'nope']);
        $this->assertSame('ok', $model->name);
        $this->assertNull($model->secret);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 7: Model — Casting
    // ═══════════════════════════════════════════════════════════════

    public function testModelCastInt(): void
    {
        $m = new ORM_TestCastModel();
        $m->setRawAttribute('age', '25');
        $this->assertSame(25, $m->age);
    }

    public function testModelCastFloat(): void
    {
        $m = new ORM_TestCastModel();
        $m->setRawAttribute('price', '9.99');
        $this->assertSame(9.99, $m->price);
    }

    public function testModelCastBool(): void
    {
        $m = new ORM_TestCastModel();
        $m->setRawAttribute('active', '1');
        $this->assertTrue($m->active);

        $m->setRawAttribute('active', '0');
        $this->assertFalse($m->active);
    }

    public function testModelCastBoolSetConvertsToInt(): void
    {
        $m = new ORM_TestCastModel();
        $m->active = true;
        $this->assertSame(1, $m->getRawAttribute('active'));

        $m->active = false;
        $this->assertSame(0, $m->getRawAttribute('active'));
    }

    public function testModelCastString(): void
    {
        $m = new ORM_TestCastModel();
        $m->setRawAttribute('label', 123);
        $this->assertSame('123', $m->label);
    }

    public function testModelCastArrayGet(): void
    {
        $m = new ORM_TestCastModel();
        $m->setRawAttribute('tags', '["a","b"]');
        $this->assertSame(['a', 'b'], $m->tags);
    }

    public function testModelCastArraySet(): void
    {
        $m = new ORM_TestCastModel();
        $m->tags = ['x', 'y'];
        $this->assertSame('["x","y"]', $m->getRawAttribute('tags'));
    }

    public function testModelCastDatetimeGet(): void
    {
        $m = new ORM_TestCastModel();
        $m->setRawAttribute('created', '2025-01-15 10:30:00');
        $dt = $m->created;
        $this->assertInstanceOf(DateTimeImmutable::class, $dt);
        $this->assertSame('2025-01-15', $dt->format('Y-m-d'));
    }

    public function testModelCastDatetimeSet(): void
    {
        $m = new ORM_TestCastModel();
        $m->created = new DateTimeImmutable('2025-06-01 12:00:00');
        $this->assertSame('2025-06-01 12:00:00', $m->getRawAttribute('created'));
    }

    public function testModelCastNullPassthrough(): void
    {
        $m = new ORM_TestCastModel();
        $m->setRawAttribute('age', null);
        $this->assertNull($m->age);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 8: Model — Dirty Tracking
    // ═══════════════════════════════════════════════════════════════

    public function testModelIsDirtyAfterChange(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        $user = ORM_TestUser::create($db, ['name' => 'Original', 'email' => 'o@test.com']);
        $this->assertFalse($user->isDirty());

        $user->name = 'Modified';
        $this->assertTrue($user->isDirty());
        $this->assertTrue($user->isDirty('name'));
        $this->assertFalse($user->isDirty('email'));
    }

    public function testModelGetDirty(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        $user = ORM_TestUser::create($db, ['name' => 'A', 'email' => 'a@test.com']);
        $user->name = 'B';
        $dirty = $user->getDirty();
        $this->assertArrayHasKey('name', $dirty);
        $this->assertSame('B', $dirty['name']);
        $this->assertArrayNotHasKey('email', $dirty);
    }

    public function testModelGetOriginal(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        $user = ORM_TestUser::create($db, ['name' => 'Start', 'email' => 's@test.com']);
        $user->name = 'Changed';
        $this->assertSame('Start', $user->getOriginal('name'));
    }

    public function testModelGetOriginalAll(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        $user = ORM_TestUser::create($db, ['name' => 'X', 'email' => 'x@test.com']);
        $orig = $user->getOriginal();
        $this->assertIsArray($orig);
        $this->assertSame('X', $orig['name']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 9: Model — Table & Key Resolution
    // ═══════════════════════════════════════════════════════════════

    public function testModelResolveTableExplicit(): void
    {
        $this->assertSame('users', ORM_TestUser::resolveTable());
    }

    public function testModelResolveTableAuto(): void
    {
        // ORM_TestAutoTable does not set $table — derives from class name
        $this->assertSame('orm_testautotables', ORM_TestAutoTable::resolveTable());
    }

    public function testModelGetPrimaryKeyName(): void
    {
        $this->assertSame('id', ORM_TestUser::getPrimaryKeyName());
    }

    public function testModelGetPrimaryKeyNameCustom(): void
    {
        $this->assertSame('uid', ORM_TestCustomPK::getPrimaryKeyName());
    }

    public function testModelGetKey(): void
    {
        $user = new ORM_TestUser();
        $user->setRawAttribute('id', 42);
        $this->assertSame(42, $user->getKey());
    }

    public function testModelGetKeyNull(): void
    {
        $user = new ORM_TestUser();
        $this->assertNull($user->getKey());
    }

    public function testModelExistsFalseByDefault(): void
    {
        $user = new ORM_TestUser();
        $this->assertFalse($user->exists());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 10: Model — Database Connection
    // ═══════════════════════════════════════════════════════════════

    public function testModelGetDatabaseNull(): void
    {
        $user = new ORM_TestUser();
        $this->assertNull($user->getDatabase());
    }

    public function testModelSetDatabase(): void
    {
        $db = $this->createSqliteDb();
        $user = new ORM_TestUser();
        $result = $user->setDatabase($db);
        $this->assertSame($db, $user->getDatabase());
        $this->assertSame($user, $result);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 11: Model — CRUD with Database (SQLite)
    // ═══════════════════════════════════════════════════════════════

    // ─── Create ──────────────────────────────────────────────────

    public function testModelCreateInsertAndReturnModel(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        $user = ORM_TestUser::create($db, ['name' => 'Ray', 'email' => 'ray@test.com']);
        $this->assertTrue($user->exists());
        $this->assertSame(1, $user->getKey());
        $this->assertSame('Ray', $user->name);
        $this->assertSame('ray@test.com', $user->email);
    }

    public function testModelCreateSetsTimestamps(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        $user = ORM_TestUser::create($db, ['name' => 'T', 'email' => 't@test.com']);
        $this->assertNotNull($user->getRawAttribute('created_at'));
        $this->assertNotNull($user->getRawAttribute('updated_at'));
    }

    public function testModelCreateNoTimestamps(): void
    {
        $db = $this->createSqliteDb();
        $this->createSimpleTable($db);

        $model = ORM_TestNoTimestamps::create($db, ['name' => 'NT']);
        $this->assertTrue($model->exists());
        $this->assertNull($model->getRawAttribute('created_at'));
    }

    // ─── Find ────────────────────────────────────────────────────

    public function testModelFindExisting(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        ORM_TestUser::create($db, ['name' => 'A', 'email' => 'a@test.com']);
        $found = ORM_TestUser::find($db, 1);

        $this->assertNotNull($found);
        $this->assertSame('A', $found->name);
        $this->assertTrue($found->exists());
    }

    public function testModelFindNonExisting(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        $found = ORM_TestUser::find($db, 999);
        $this->assertNull($found);
    }

    public function testModelFindOrFailExisting(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        ORM_TestUser::create($db, ['name' => 'B', 'email' => 'b@test.com']);
        $found = ORM_TestUser::findOrFail($db, 1);

        $this->assertSame('B', $found->name);
    }

    public function testModelFindOrFailThrows(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        $this->expectException(ModelNotFoundException::class);
        ORM_TestUser::findOrFail($db, 999);
    }

    // ─── All ─────────────────────────────────────────────────────

    public function testModelAll(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        ORM_TestUser::create($db, ['name' => 'X', 'email' => 'x@test.com']);
        ORM_TestUser::create($db, ['name' => 'Y', 'email' => 'y@test.com']);

        $all = ORM_TestUser::all($db);
        $this->assertInstanceOf(ModelCollection::class, $all);
        $this->assertCount(2, $all);
    }

    public function testModelAllEmpty(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        $all = ORM_TestUser::all($db);
        $this->assertCount(0, $all);
        $this->assertTrue($all->isEmpty());
    }

    // ─── Save (update) ──────────────────────────────────────────

    public function testModelSaveUpdate(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        $user = ORM_TestUser::create($db, ['name' => 'Before', 'email' => 'b@test.com']);
        $user->name = 'After';
        $result = $user->save();

        $this->assertTrue($result);
        $this->assertFalse($user->isDirty());

        // Verify in DB
        $reloaded = ORM_TestUser::find($db, $user->getKey());
        $this->assertSame('After', $reloaded->name);
    }

    public function testModelSaveUpdateNoChangesReturnsFalse(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        $user = ORM_TestUser::create($db, ['name' => 'Same', 'email' => 's@test.com']);
        $result = $user->save();
        $this->assertFalse($result);
    }

    public function testModelSaveInsert(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        $user = new ORM_TestUser();
        $user->setDatabase($db);
        $user->setRawAttribute('name', 'Manual');
        $user->setRawAttribute('email', 'm@test.com');

        $result = $user->save();
        $this->assertTrue($result);
        $this->assertTrue($user->exists());
        $this->assertNotNull($user->getKey());

        // Verify in DB
        $reloaded = ORM_TestUser::find($db, $user->getKey());
        $this->assertSame('Manual', $reloaded->name);
    }

    public function testModelSaveWithoutDatabaseThrows(): void
    {
        $user = new ORM_TestUser();
        $user->setRawAttribute('name', 'NoDb');

        $this->expectException(RuntimeException::class);
        $user->save();
    }

    // ─── Delete ──────────────────────────────────────────────────

    public function testModelDeleteExisting(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        $user = ORM_TestUser::create($db, ['name' => 'Del', 'email' => 'd@test.com']);
        $id = $user->getKey();

        $result = $user->delete();
        $this->assertTrue($result);
        $this->assertFalse($user->exists());

        $this->assertNull(ORM_TestUser::find($db, $id));
    }

    public function testModelDeleteNonExisting(): void
    {
        $user = new ORM_TestUser();
        $this->assertFalse($user->delete());
    }

    // ─── Destroy (static) ───────────────────────────────────────

    public function testModelDestroy(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        ORM_TestUser::create($db, ['name' => 'D1', 'email' => 'd1@test.com']);
        ORM_TestUser::create($db, ['name' => 'D2', 'email' => 'd2@test.com']);

        $count = ORM_TestUser::destroy($db, 1, 2);
        $this->assertSame(2, $count);
        $this->assertCount(0, ORM_TestUser::all($db));
    }

    public function testModelDestroyEmpty(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);
        $this->assertSame(0, ORM_TestUser::destroy($db));
    }

    // ─── Refresh ─────────────────────────────────────────────────

    public function testModelRefresh(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        $user = ORM_TestUser::create($db, ['name' => 'Original', 'email' => 'o@test.com']);

        // Modify directly in DB
        $db->execute(
            $db->update('users', ['name'])
                ->where('id=:id')
                ->assign(['name' => 'DirectUpdate', 'id' => $user->getKey()]),
        );

        $user->name = 'InMemory';
        $user->refresh();

        $this->assertSame('DirectUpdate', $user->name);
        $this->assertFalse($user->isDirty());
    }

    public function testModelRefreshNonExistingNoOp(): void
    {
        $user = new ORM_TestUser();
        $result = $user->refresh();
        $this->assertSame($user, $result);
    }

    // ─── ToArray ─────────────────────────────────────────────────

    public function testModelToArray(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        $user = ORM_TestUser::create($db, ['name' => 'Arr', 'email' => 'arr@test.com']);
        $arr = $user->toArray();
        $this->assertIsArray($arr);
        $this->assertSame('Arr', $arr['name']);
        $this->assertArrayHasKey('id', $arr);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 12: ModelQuery — Fluent Builder
    // ═══════════════════════════════════════════════════════════════

    public function testQueryWhereAndGet(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        ORM_TestUser::create($db, ['name' => 'Alice', 'email' => 'alice@test.com']);
        ORM_TestUser::create($db, ['name' => 'Bob', 'email' => 'bob@test.com']);

        $result = ORM_TestUser::query($db)
            ->where('name=:name', ['name' => 'Alice'])
            ->get();

        $this->assertCount(1, $result);
        $this->assertSame('Alice', $result->first()->name);
    }

    public function testQueryFirst(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        ORM_TestUser::create($db, ['name' => 'A', 'email' => 'a@test.com']);
        ORM_TestUser::create($db, ['name' => 'B', 'email' => 'b@test.com']);

        $first = ORM_TestUser::query($db)->first();
        $this->assertNotNull($first);
        $this->assertSame('A', $first->name);
    }

    public function testQueryFirstReturnsNullWhenEmpty(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        $this->assertNull(ORM_TestUser::query($db)->first());
    }

    public function testQueryFind(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        ORM_TestUser::create($db, ['name' => 'Find', 'email' => 'f@test.com']);

        $found = ORM_TestUser::query($db)->find(1);
        $this->assertNotNull($found);
        $this->assertSame('Find', $found->name);
    }

    public function testQueryCount(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        ORM_TestUser::create($db, ['name' => 'C1', 'email' => 'c1@test.com']);
        ORM_TestUser::create($db, ['name' => 'C2', 'email' => 'c2@test.com']);
        ORM_TestUser::create($db, ['name' => 'C3', 'email' => 'c3@test.com']);

        $this->assertSame(3, ORM_TestUser::query($db)->count());
    }

    public function testQueryCountWithWhere(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        ORM_TestUser::create($db, ['name' => 'A', 'email' => 'a@test.com']);
        ORM_TestUser::create($db, ['name' => 'B', 'email' => 'b@test.com']);

        $count = ORM_TestUser::query($db)
            ->where('name=:name', ['name' => 'A'])
            ->count();

        $this->assertSame(1, $count);
    }

    public function testQueryOrderBy(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        ORM_TestUser::create($db, ['name' => 'Charlie', 'email' => 'c@test.com']);
        ORM_TestUser::create($db, ['name' => 'Alice', 'email' => 'a@test.com']);
        ORM_TestUser::create($db, ['name' => 'Bob', 'email' => 'b@test.com']);

        $users = ORM_TestUser::query($db)->orderBy('name')->get();
        $this->assertSame('Alice', $users[0]->name);
        $this->assertSame('Bob', $users[1]->name);
        $this->assertSame('Charlie', $users[2]->name);
    }

    public function testQueryOrderByDesc(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        ORM_TestUser::create($db, ['name' => 'A', 'email' => 'a@test.com']);
        ORM_TestUser::create($db, ['name' => 'B', 'email' => 'b@test.com']);
        ORM_TestUser::create($db, ['name' => 'C', 'email' => 'c@test.com']);

        $users = ORM_TestUser::query($db)->orderBy('name', 'DESC')->get();
        $this->assertSame('C', $users[0]->name);
        $this->assertSame('A', $users[2]->name);
    }

    public function testQueryLimit(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        ORM_TestUser::create($db, ['name' => 'A', 'email' => 'a@test.com']);
        ORM_TestUser::create($db, ['name' => 'B', 'email' => 'b@test.com']);
        ORM_TestUser::create($db, ['name' => 'C', 'email' => 'c@test.com']);

        $users = ORM_TestUser::query($db)->limit(2)->get();
        $this->assertCount(2, $users);
    }

    public function testQueryOffsetAndLimit(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        ORM_TestUser::create($db, ['name' => 'A', 'email' => 'a@test.com']);
        ORM_TestUser::create($db, ['name' => 'B', 'email' => 'b@test.com']);
        ORM_TestUser::create($db, ['name' => 'C', 'email' => 'c@test.com']);

        $users = ORM_TestUser::query($db)->orderBy('id')->offset(1)->limit(2)->get();
        $this->assertCount(2, $users);
        $this->assertSame('B', $users[0]->name);
    }

    public function testQueryPaginate(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        for ($i = 1; $i <= 10; $i++) {
            ORM_TestUser::create($db, ['name' => "User$i", 'email' => "u$i@test.com"]);
        }

        $page = ORM_TestUser::query($db)->paginate(2, 3);

        $this->assertCount(3, $page['data']);
        $this->assertSame(10, $page['total']);
        $this->assertSame(2, $page['page']);
        $this->assertSame(3, $page['per_page']);
        $this->assertSame(4, $page['last_page']);
    }

    public function testQueryPaginateFirstPage(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        for ($i = 1; $i <= 5; $i++) {
            ORM_TestUser::create($db, ['name' => "P$i", 'email' => "p$i@test.com"]);
        }

        $page = ORM_TestUser::query($db)->paginate(1, 2);
        $this->assertCount(2, $page['data']);
        $this->assertSame(1, $page['page']);
        $this->assertSame(3, $page['last_page']);
    }

    public function testQueryPaginateOutOfRange(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        ORM_TestUser::create($db, ['name' => 'X', 'email' => 'x@test.com']);

        $page = ORM_TestUser::query($db)->paginate(100, 5);
        $this->assertSame(1, $page['page']); // Clamped to last page
        $this->assertSame(1, $page['last_page']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 13: ModelQuery — Bulk Operations
    // ═══════════════════════════════════════════════════════════════

    public function testQueryBulkUpdate(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        ORM_TestUser::create($db, ['name' => 'Old1', 'email' => 'o1@test.com']);
        ORM_TestUser::create($db, ['name' => 'Old2', 'email' => 'o2@test.com']);

        $affected = ORM_TestUser::query($db)
            ->where('name*=:pattern', ['pattern' => 'Old%'])
            ->bulkUpdate(['name' => 'New']);

        $this->assertSame(2, $affected);

        $all = ORM_TestUser::all($db);
        foreach ($all as $u) {
            $this->assertSame('New', $u->name);
        }
    }

    public function testQueryBulkDelete(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        ORM_TestUser::create($db, ['name' => 'Keep', 'email' => 'k@test.com']);
        ORM_TestUser::create($db, ['name' => 'Del1', 'email' => 'd1@test.com']);
        ORM_TestUser::create($db, ['name' => 'Del2', 'email' => 'd2@test.com']);

        $affected = ORM_TestUser::query($db)
            ->where('name*=:pattern', ['pattern' => 'Del%'])
            ->bulkDelete();

        $this->assertSame(2, $affected);
        $this->assertCount(1, ORM_TestUser::all($db));
    }

    public function testQueryCreateViaBuilder(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        $user = ORM_TestUser::query($db)->create([
            'name' => 'Created',
            'email' => 'c@test.com',
        ]);

        $this->assertTrue($user->exists());
        $this->assertSame('Created', $user->name);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 14: Model — Relationships (HasOne, HasMany, BelongsTo)
    // ═══════════════════════════════════════════════════════════════

    // ─── HasOne ──────────────────────────────────────────────────

    public function testHasOneReturnsRelatedModel(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);
        $this->createProfilesTable($db);

        $user = ORM_TestUser::create($db, ['name' => 'U', 'email' => 'u@test.com']);

        // Insert profile manually
        $db->execute(
            $db->insert('profiles', ['user_id', 'bio'])->assign([
                'user_id' => $user->getKey(),
                'bio' => 'Hello world',
            ]),
        );

        $profile = $user->profile;
        $this->assertInstanceOf(ORM_TestProfile::class, $profile);
        $this->assertSame('Hello world', $profile->bio);
    }

    public function testHasOneReturnsNullWhenNoRelated(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);
        $this->createProfilesTable($db);

        $user = ORM_TestUser::create($db, ['name' => 'NP', 'email' => 'np@test.com']);
        $this->assertNull($user->profile);
    }

    public function testHasOneCachesResult(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);
        $this->createProfilesTable($db);

        $user = ORM_TestUser::create($db, ['name' => 'Cache', 'email' => 'c@test.com']);
        $db->execute(
            $db->insert('profiles', ['user_id', 'bio'])->assign([
                'user_id' => $user->getKey(),
                'bio' => 'Cached',
            ]),
        );

        $p1 = $user->profile;
        $p2 = $user->profile;
        $this->assertSame($p1, $p2); // Cached — same object
    }

    // ─── HasMany ─────────────────────────────────────────────────

    public function testHasManyReturnsCollection(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);
        $this->createPostsTable($db);

        $user = ORM_TestUser::create($db, ['name' => 'Author', 'email' => 'a@test.com']);

        $db->execute(
            $db->insert('posts', ['user_id', 'title'])->assign([
                'user_id' => $user->getKey(),
                'title' => 'Post 1',
            ]),
        );
        $db->execute(
            $db->insert('posts', ['user_id', 'title'])->assign([
                'user_id' => $user->getKey(),
                'title' => 'Post 2',
            ]),
        );

        $posts = $user->posts;
        $this->assertInstanceOf(ModelCollection::class, $posts);
        $this->assertCount(2, $posts);
    }

    public function testHasManyReturnsEmptyCollection(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);
        $this->createPostsTable($db);

        $user = ORM_TestUser::create($db, ['name' => 'NoPosts', 'email' => 'np@test.com']);
        $posts = $user->posts;
        $this->assertInstanceOf(ModelCollection::class, $posts);
        $this->assertTrue($posts->isEmpty());
    }

    // ─── BelongsTo ───────────────────────────────────────────────

    public function testBelongsToReturnsParent(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);
        $this->createPostsTable($db);

        $user = ORM_TestUser::create($db, ['name' => 'Owner', 'email' => 'o@test.com']);

        $db->execute(
            $db->insert('posts', ['user_id', 'title'])->assign([
                'user_id' => $user->getKey(),
                'title' => 'My Post',
            ]),
        );

        $post = ORM_TestPost::find($db, 1);
        $this->assertNotNull($post);

        $owner = $post->user;
        $this->assertInstanceOf(ORM_TestUser::class, $owner);
        $this->assertSame('Owner', $owner->name);
    }

    public function testBelongsToReturnsNullWhenNoParent(): void
    {
        $db = $this->createSqliteDb();
        $this->createPostsTable($db);

        $db->execute(
            $db->insert('posts', ['user_id', 'title'])->assign([
                'user_id' => null,
                'title' => 'Orphan',
            ]),
        );

        $post = ORM_TestPost::find($db, 1);
        $this->assertNotNull($post);
        $this->assertNull($post->user);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 15: Model — Timestamps
    // ═══════════════════════════════════════════════════════════════

    public function testTimestampsSetOnCreate(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        $before = \date('Y-m-d H:i:s');
        $user = ORM_TestUser::create($db, ['name' => 'TS', 'email' => 'ts@test.com']);
        $after = \date('Y-m-d H:i:s');

        $createdAt = $user->getRawAttribute('created_at');
        $this->assertGreaterThanOrEqual($before, $createdAt);
        $this->assertLessThanOrEqual($after, $createdAt);
    }

    public function testTimestampsUpdatedOnSave(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        $user = ORM_TestUser::create($db, ['name' => 'TSU', 'email' => 'tsu@test.com']);
        $createdAt = $user->getRawAttribute('created_at');

        // Force a tiny delay so updated_at differs
        \usleep(10000);

        $user->name = 'Updated';
        $user->save();

        $updatedAt = $user->getRawAttribute('updated_at');
        $this->assertGreaterThanOrEqual($createdAt, $updatedAt);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 16: Model — newFromRow Hydration
    // ═══════════════════════════════════════════════════════════════

    public function testNewFromRowSetsExists(): void
    {
        $db = $this->createSqliteDb();
        $model = ORM_TestUser::newFromRow(['id' => 1, 'name' => 'H'], $db);
        $this->assertTrue($model->exists());
        $this->assertSame($db, $model->getDatabase());
    }

    public function testNewFromRowSetsOriginal(): void
    {
        $db = $this->createSqliteDb();
        $model = ORM_TestUser::newFromRow(['id' => 1, 'name' => 'H'], $db);
        $this->assertFalse($model->isDirty());
        $this->assertSame('H', $model->getOriginal('name'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 17: Relation — Meta
    // ═══════════════════════════════════════════════════════════════

    public function testHasOneGetForeignKey(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);
        $user = ORM_TestUser::create($db, ['name' => 'FK', 'email' => 'fk@test.com']);

        $relation = new HasOne($user, ORM_TestProfile::class, 'user_id', 'id');
        $this->assertSame('user_id', $relation->getForeignKey());
        $this->assertSame('id', $relation->getLocalKey());
    }

    public function testHasManyGetForeignKey(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);
        $user = ORM_TestUser::create($db, ['name' => 'FK2', 'email' => 'fk2@test.com']);

        $relation = new HasMany($user, ORM_TestPost::class, 'user_id', 'id');
        $this->assertSame('user_id', $relation->getForeignKey());
    }

    public function testBelongsToGetKeys(): void
    {
        $db = $this->createSqliteDb();
        $this->createPostsTable($db);

        $db->execute(
            $db->insert('posts', ['user_id', 'title'])->assign([
                'user_id' => 1,
                'title' => 'Test',
            ]),
        );

        $post = ORM_TestPost::find($db, 1);
        $relation = new BelongsTo($post, ORM_TestUser::class, 'user_id', 'id');
        $this->assertSame('user_id', $relation->getForeignKey());
        $this->assertSame('id', $relation->getLocalKey());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 18: Integration — Complex Scenarios
    // ═══════════════════════════════════════════════════════════════

    public function testCreateFindUpdateDeleteCycle(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        // Create
        $user = ORM_TestUser::create($db, ['name' => 'Cycle', 'email' => 'cycle@test.com']);
        $this->assertTrue($user->exists());
        $id = $user->getKey();

        // Find
        $found = ORM_TestUser::find($db, $id);
        $this->assertSame('Cycle', $found->name);

        // Update
        $found->name = 'Updated';
        $found->save();
        $reloaded = ORM_TestUser::find($db, $id);
        $this->assertSame('Updated', $reloaded->name);

        // Delete
        $reloaded->delete();
        $this->assertNull(ORM_TestUser::find($db, $id));
    }

    public function testMultipleModelsOnSameConnection(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);
        $this->createPostsTable($db);

        $user = ORM_TestUser::create($db, ['name' => 'Multi', 'email' => 'm@test.com']);

        $db->execute(
            $db->insert('posts', ['user_id', 'title'])->assign([
                'user_id' => $user->getKey(),
                'title' => 'First Post',
            ]),
        );

        $this->assertCount(1, ORM_TestUser::all($db));
        $this->assertCount(1, ORM_TestPost::all($db));
    }

    public function testQueryChainingMultipleWheres(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        ORM_TestUser::create($db, ['name' => 'Alice', 'email' => 'alice@a.com']);
        ORM_TestUser::create($db, ['name' => 'Alice', 'email' => 'alice@b.com']);
        ORM_TestUser::create($db, ['name' => 'Bob', 'email' => 'bob@a.com']);

        $result = ORM_TestUser::query($db)
            ->where('name=:name', ['name' => 'Alice'])
            ->where('email=:email', ['email' => 'alice@a.com'])
            ->get();

        $this->assertCount(1, $result);
        $this->assertSame('alice@a.com', $result->first()->email);
    }

    public function testQuerySelectSpecificColumns(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        ORM_TestUser::create($db, ['name' => 'Cols', 'email' => 'cols@test.com']);

        $user = ORM_TestUser::query($db)
            ->select('id, name')
            ->first();

        $this->assertNotNull($user);
        $this->assertSame('Cols', $user->name);
        // email was not selected, so it should be null
        $this->assertNull($user->email);
    }

    public function testCollectionIntegrationWithQuery(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);

        for ($i = 1; $i <= 5; $i++) {
            ORM_TestUser::create($db, ['name' => "User$i", 'email' => "u$i@test.com"]);
        }

        $collection = ORM_TestUser::all($db);
        $names = $collection->pluck('name');
        $this->assertCount(5, $names);
        $this->assertContains('User1', $names);
        $this->assertContains('User5', $names);

        $filtered = $collection->filter(fn (Model $m) => (int) $m->id <= 3);
        $this->assertCount(3, $filtered);
    }

    public function testRelationshipChain(): void
    {
        $db = $this->createSqliteDb();
        $this->createUsersTable($db);
        $this->createPostsTable($db);

        $user = ORM_TestUser::create($db, ['name' => 'Author', 'email' => 'auth@test.com']);

        $db->execute(
            $db->insert('posts', ['user_id', 'title'])->assign([
                'user_id' => $user->getKey(),
                'title' => 'Chain Post',
            ]),
        );

        // User → posts → first post → user (back via belongsTo)
        $this->createUsersTable($db); // Already exists, just for clarity
        $posts = $user->posts;
        $this->assertCount(1, $posts);

        $post = $posts->first();
        $backToUser = $post->user;
        $this->assertInstanceOf(ORM_TestUser::class, $backToUser);
        $this->assertSame('Author', $backToUser->name);
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Create an in-memory SQLite database.
     */
    private function createSqliteDb(): Database
    {
        static $counter = 0;
        $db = new Database('test_orm_' . (++$counter));
        $db->connectWithDriver('sqlite', ['path' => ':memory:']);

        return $db;
    }

    /**
     * Create a simple model stub for collection-only tests.
     */
    private function createStubModel(array $attributes): Model
    {
        return new class($attributes) extends Model {
            protected static string $table = 'stubs';

            protected static array $fillable = [];

            protected static array $guarded = [];

            public function __construct(array $attrs = [])
            {
                parent::__construct();
                $this->attributes = $attrs;
                $this->original = $attrs;
            }
        };
    }

    /**
     * Create the users table for testing.
     */
    private function createUsersTable(Database $db): void
    {
        $db->clearStatementPool();
        $db->getDBAdapter()->exec('
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL,
                created_at TEXT,
                updated_at TEXT
            )
        ');
    }

    /**
     * Create the profiles table for HasOne testing.
     */
    private function createProfilesTable(Database $db): void
    {
        $db->clearStatementPool();
        $db->getDBAdapter()->exec('
            CREATE TABLE IF NOT EXISTS profiles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                bio TEXT
            )
        ');
    }

    /**
     * Create the posts table for HasMany / BelongsTo testing.
     */
    private function createPostsTable(Database $db): void
    {
        $db->clearStatementPool();
        $db->getDBAdapter()->exec('
            CREATE TABLE IF NOT EXISTS posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                title TEXT NOT NULL
            )
        ');
    }

    /**
     * Create a simple table without timestamps for ORM_TestNoTimestamps.
     */
    private function createSimpleTable(Database $db): void
    {
        $db->clearStatementPool();
        $db->getDBAdapter()->exec('
            CREATE TABLE IF NOT EXISTS simple_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL
            )
        ');
    }
}

// ═══════════════════════════════════════════════════════════════
// Test Model Doubles
// ═══════════════════════════════════════════════════════════════

/**
 * Standard test user model.
 */
class ORM_TestUser extends Model
{
    protected static string $table = 'users';

    protected static array $fillable = ['name', 'email'];

    /**
     * User has one profile.
     */
    public function profile(): HasOne
    {
        return $this->hasOne(ORM_TestProfile::class, 'user_id', 'id');
    }

    /**
     * User has many posts.
     */
    public function posts(): HasMany
    {
        return $this->hasMany(ORM_TestPost::class, 'user_id', 'id');
    }
}

/**
 * Profile model for HasOne testing.
 */
class ORM_TestProfile extends Model
{
    protected static string $table = 'profiles';

    protected static array $fillable = ['user_id', 'bio'];

    protected static bool $timestamps = false;
}

/**
 * Post model for HasMany / BelongsTo testing.
 */
class ORM_TestPost extends Model
{
    protected static string $table = 'posts';

    protected static array $fillable = ['user_id', 'title'];

    protected static bool $timestamps = false;

    /**
     * Post belongs to a user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(ORM_TestUser::class, 'user_id', 'id');
    }
}

/**
 * Model without explicit $table to test auto-derivation.
 */
class ORM_TestAutoTable extends Model
{
    protected static array $fillable = ['name'];
}

/**
 * Model with custom primary key.
 */
class ORM_TestCustomPK extends Model
{
    protected static string $table = 'custom';

    protected static string $primaryKey = 'uid';
}

/**
 * Model with guarded=['*'] and empty fillable.
 */
class ORM_TestGuardedModel extends Model
{
    protected static string $table = 'guarded';
    // inherits guarded=['*'] and fillable=[]
}

/**
 * Model with specific guarded columns (not '*').
 */
class ORM_TestPartialGuardModel extends Model
{
    protected static string $table = 'partial';

    protected static array $fillable = [];

    protected static array $guarded = ['secret'];
}

/**
 * Model for testing type casting.
 */
class ORM_TestCastModel extends Model
{
    protected static string $table = 'casts';

    protected static array $fillable = [];

    protected static array $guarded = [];

    protected static array $casts = [
        'age' => 'int',
        'price' => 'float',
        'active' => 'bool',
        'label' => 'string',
        'tags' => 'array',
        'created' => 'datetime',
    ];
}

/**
 * Model without timestamps.
 */
class ORM_TestNoTimestamps extends Model
{
    protected static string $table = 'simple_items';

    protected static array $fillable = ['name'];

    protected static bool $timestamps = false;
}
