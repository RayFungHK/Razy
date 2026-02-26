<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Database;
use Razy\ORM\Model;
use Razy\ORM\ModelQuery;
use Razy\ORM\SoftDeletes;

/**
 * Tests for P19: Soft Deletes.
 *
 * Covers the SoftDeletes trait: soft-delete, restore, forceDelete,
 * trashed(), withTrashed, onlyTrashed, global scope integration,
 * and interaction with other query features.
 */
#[CoversClass(ModelQuery::class)]
#[CoversClass(Model::class)]
class SoftDeletesTest extends TestCase
{
    protected function tearDown(): void
    {
        Model::clearBootedModels();
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 1: Basic soft delete
    // ═══════════════════════════════════════════════════════════════

    public function testDeleteSetDeletedAtTimestamp(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $post = SD_Post::create($db, ['title' => 'Hello', 'body' => 'World']);
        $this->assertNull($post->deleted_at);

        $result = $post->delete();

        $this->assertTrue($result);
        $this->assertNotNull($post->deleted_at);
    }

    public function testDeleteDoesNotRemoveRow(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $post = SD_Post::create($db, ['title' => 'Hello', 'body' => 'World']);
        $post->delete();

        // Row still exists in DB
        $row = $db->prepare()->select('*')->from('sd_posts')
            ->where('id=:id')->assign(['id' => $post->getKey()])->lazy();

        $this->assertNotEmpty($row);
        $this->assertNotNull($row['deleted_at']);
    }

    public function testSoftDeletedModelHiddenFromQueries(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $post = SD_Post::create($db, ['title' => 'Post 1', 'body' => 'Body']);
        SD_Post::create($db, ['title' => 'Post 2', 'body' => 'Body']);

        $post->delete();

        $all = SD_Post::all($db);
        $this->assertCount(1, $all);
        $this->assertSame('Post 2', $all->first()->title);
    }

    public function testSoftDeletedHiddenFromFind(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $post = SD_Post::create($db, ['title' => 'Hello', 'body' => 'World']);
        $id = $post->getKey();
        $post->delete();

        $found = SD_Post::find($db, $id);
        $this->assertNull($found);
    }

    public function testSoftDeletedHiddenFromFirst(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $post = SD_Post::create($db, ['title' => 'Only', 'body' => 'Post']);
        $post->delete();

        $result = SD_Post::query($db)->first();
        $this->assertNull($result);
    }

    public function testSoftDeletedHiddenFromCount(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        SD_Post::create($db, ['title' => 'A', 'body' => 'x']);
        $post = SD_Post::create($db, ['title' => 'B', 'body' => 'y']);
        $post->delete();

        $this->assertSame(1, SD_Post::query($db)->count());
    }

    public function testTrashedReturnsTrue(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $post = SD_Post::create($db, ['title' => 'Hello', 'body' => 'World']);
        $this->assertFalse($post->trashed());

        $post->delete();
        $this->assertTrue($post->trashed());
    }

    public function testDeleteOnUnsavedModelReturnsFalse(): void
    {
        $post = new SD_Post(['title' => 'New', 'body' => 'Post']);
        $this->assertFalse($post->delete());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 2: Restore
    // ═══════════════════════════════════════════════════════════════

    public function testRestoreClearsDeletedAt(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $post = SD_Post::create($db, ['title' => 'Hello', 'body' => 'World']);
        $post->delete();
        $this->assertTrue($post->trashed());

        $result = $post->restore();

        $this->assertTrue($result);
        $this->assertFalse($post->trashed());
        $this->assertNull($post->deleted_at);
    }

    public function testRestoredModelAppearsInQueries(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $post = SD_Post::create($db, ['title' => 'Restored', 'body' => 'Post']);
        $id = $post->getKey();

        $post->delete();
        $this->assertNull(SD_Post::find($db, $id));

        $post->restore();

        $found = SD_Post::find($db, $id);
        $this->assertNotNull($found);
        $this->assertSame('Restored', $found->title);
    }

    public function testRestoreOnUnsavedModelReturnsFalse(): void
    {
        $post = new SD_Post(['title' => 'New', 'body' => 'Post']);
        $this->assertFalse($post->restore());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 3: Force delete
    // ═══════════════════════════════════════════════════════════════

    public function testForceDeleteRemovesRow(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $post = SD_Post::create($db, ['title' => 'Doomed', 'body' => 'Post']);
        $id = $post->getKey();

        $result = $post->forceDelete();
        $this->assertTrue($result);
        $this->assertFalse($post->exists());

        // Row must be gone from the database
        $row = $db->prepare()->select('*')->from('sd_posts')
            ->where('id=:id')->assign(['id' => $id])->lazy();

        $this->assertEmpty($row);
    }

    public function testForceDeleteOnSoftDeletedModel(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $post = SD_Post::create($db, ['title' => 'Will Die', 'body' => 'Twice']);
        $id = $post->getKey();

        $post->delete(); // soft-delete first
        $this->assertTrue($post->exists()); // still "exists" after soft-delete

        $post->forceDelete();
        $this->assertFalse($post->exists());

        $row = $db->prepare()->select('*')->from('sd_posts')
            ->where('id=:id')->assign(['id' => $id])->lazy();
        $this->assertEmpty($row);
    }

    public function testForceDeleteOnUnsavedModelReturnsFalse(): void
    {
        $post = new SD_Post(['title' => 'New', 'body' => 'Post']);
        $this->assertFalse($post->forceDelete());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 4: withTrashed scope
    // ═══════════════════════════════════════════════════════════════

    public function testWithTrashedIncludesDeletedRecords(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        SD_Post::create($db, ['title' => 'Active', 'body' => 'A']);
        $deleted = SD_Post::create($db, ['title' => 'Deleted', 'body' => 'B']);
        $deleted->delete();

        $all = SD_Post::query($db)->withTrashed()->get();

        $this->assertCount(2, $all);
    }

    public function testWithTrashedFirst(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $post = SD_Post::create($db, ['title' => 'Deleted', 'body' => 'Only']);
        $post->delete();

        $found = SD_Post::query($db)->withTrashed()->first();
        $this->assertNotNull($found);
        $this->assertSame('Deleted', $found->title);
    }

    public function testWithTrashedCount(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        SD_Post::create($db, ['title' => 'A', 'body' => 'x']);
        $deleted = SD_Post::create($db, ['title' => 'B', 'body' => 'y']);
        $deleted->delete();

        $this->assertSame(2, SD_Post::query($db)->withTrashed()->count());
    }

    public function testWithTrashedFind(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $post = SD_Post::create($db, ['title' => 'Trashed', 'body' => 'Find me']);
        $id = $post->getKey();
        $post->delete();

        $found = SD_Post::query($db)->withTrashed()->find($id);
        $this->assertNotNull($found);
        $this->assertSame('Trashed', $found->title);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 5: onlyTrashed scope
    // ═══════════════════════════════════════════════════════════════

    public function testOnlyTrashedReturnsOnlyDeletedRecords(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        SD_Post::create($db, ['title' => 'Active', 'body' => 'Alive']);
        $deleted = SD_Post::create($db, ['title' => 'Deleted', 'body' => 'Gone']);
        $deleted->delete();

        $trashed = SD_Post::query($db)->onlyTrashed()->get();

        $this->assertCount(1, $trashed);
        $this->assertSame('Deleted', $trashed->first()->title);
    }

    public function testOnlyTrashedCount(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        SD_Post::create($db, ['title' => 'Active', 'body' => 'x']);
        $d1 = SD_Post::create($db, ['title' => 'Del 1', 'body' => 'y']);
        $d2 = SD_Post::create($db, ['title' => 'Del 2', 'body' => 'z']);
        $d1->delete();
        $d2->delete();

        $this->assertSame(2, SD_Post::query($db)->onlyTrashed()->count());
    }

    public function testOnlyTrashedFirst(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        SD_Post::create($db, ['title' => 'Active', 'body' => 'x']);
        $deleted = SD_Post::create($db, ['title' => 'Trashed', 'body' => 'y']);
        $deleted->delete();

        $found = SD_Post::query($db)->onlyTrashed()->first();
        $this->assertNotNull($found);
        $this->assertSame('Trashed', $found->title);
    }

    public function testOnlyTrashedReturnsEmptyWhenNoneDeleted(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        SD_Post::create($db, ['title' => 'Active', 'body' => 'x']);

        $trashed = SD_Post::query($db)->onlyTrashed()->get();
        $this->assertCount(0, $trashed);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 6: Bulk operations respect soft deletes
    // ═══════════════════════════════════════════════════════════════

    public function testBulkUpdateRespectsGlobalScope(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $active = SD_Post::create($db, ['title' => 'Active', 'body' => 'x']);
        $deleted = SD_Post::create($db, ['title' => 'Deleted', 'body' => 'y']);
        $deleted->delete();

        $affected = SD_Post::query($db)->bulkUpdate(['body' => 'updated']);
        $this->assertSame(1, $affected);

        // Verify: only active post was updated
        $rows = $db->prepare()->select('*')->from('sd_posts')->lazyGroup();
        $activeRow = \array_values(\array_filter($rows, fn ($r) => $r['title'] === 'Active'))[0];
        $deletedRow = \array_values(\array_filter($rows, fn ($r) => $r['title'] === 'Deleted'))[0];

        $this->assertSame('updated', $activeRow['body']);
        $this->assertSame('y', $deletedRow['body']);
    }

    public function testBulkDeleteRespectsGlobalScope(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        SD_Post::create($db, ['title' => 'Active', 'body' => 'x']);
        $deleted = SD_Post::create($db, ['title' => 'Deleted', 'body' => 'y']);
        $deleted->delete();

        // bulkDelete should only hard-delete the active (non-soft-deleted) post
        $affected = SD_Post::query($db)->bulkDelete();
        $this->assertSame(1, $affected);

        // The soft-deleted post should survive (it was already excluded by global scope)
        $rows = $db->prepare()->select('*')->from('sd_posts')->lazyGroup();
        $this->assertCount(1, $rows);
        $this->assertSame('Deleted', $rows[0]['title']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 7: Scopes chain with normal query features
    // ═══════════════════════════════════════════════════════════════

    public function testWithTrashedChainedWithWhere(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $d1 = SD_Post::create($db, ['title' => 'Del Tech', 'body' => 'tech']);
        $d2 = SD_Post::create($db, ['title' => 'Del News', 'body' => 'news']);
        $d1->delete();
        $d2->delete();
        SD_Post::create($db, ['title' => 'Active Tech', 'body' => 'tech']);

        $results = SD_Post::query($db)
            ->withTrashed()
            ->where('body=:b', ['b' => 'tech'])
            ->get();

        $this->assertCount(2, $results);
    }

    public function testWithTrashedChainedWithOrderAndLimit(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        SD_Post::create($db, ['title' => 'C', 'body' => 'x']);
        $b = SD_Post::create($db, ['title' => 'B', 'body' => 'x']);
        SD_Post::create($db, ['title' => 'A', 'body' => 'x']);
        $b->delete();

        $results = SD_Post::query($db)
            ->withTrashed()
            ->orderBy('title')
            ->limit(2)
            ->get();

        $this->assertCount(2, $results);
        $this->assertSame('A', $results[0]->title);
        $this->assertSame('B', $results[1]->title);
    }

    public function testWithTrashedPaginate(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        SD_Post::create($db, ['title' => 'Active', 'body' => 'x']);
        $del = SD_Post::create($db, ['title' => 'Deleted', 'body' => 'y']);
        $del->delete();

        $page = SD_Post::query($db)->withTrashed()->paginate(1, 10);

        $this->assertSame(2, $page['total']);
        $this->assertCount(2, $page['data']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 8: Interaction with other global scopes
    // ═══════════════════════════════════════════════════════════════

    public function testSoftDeletesWithCustomGlobalScope(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        // SD_PublishedPost has BOTH softDeletes + a custom 'published' global scope
        SD_PublishedPost::create($db, ['title' => 'Pub Active', 'status' => 'published', 'body' => 'a']);
        SD_PublishedPost::create($db, ['title' => 'Draft Active', 'status' => 'draft', 'body' => 'b']);
        $deleted = SD_PublishedPost::create($db, ['title' => 'Pub Deleted', 'status' => 'published', 'body' => 'c']);
        $deleted->delete();

        // Normal query: published AND not-deleted
        $results = SD_PublishedPost::query($db)->get();
        $this->assertCount(1, $results);
        $this->assertSame('Pub Active', $results->first()->title);
    }

    public function testWithTrashedKeepsOtherGlobalScopes(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        SD_PublishedPost::create($db, ['title' => 'Pub Active', 'status' => 'published', 'body' => 'a']);
        SD_PublishedPost::create($db, ['title' => 'Draft Active', 'status' => 'draft', 'body' => 'b']);
        $deleted = SD_PublishedPost::create($db, ['title' => 'Pub Deleted', 'status' => 'published', 'body' => 'c']);
        $deleted->delete();

        // withTrashed removes soft-delete scope but keeps 'published' scope
        $results = SD_PublishedPost::query($db)->withTrashed()->get();
        $this->assertCount(2, $results); // Pub Active + Pub Deleted (Draft excluded)
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 9: Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testDeleteAlreadyDeletedModel(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $post = SD_Post::create($db, ['title' => 'Double', 'body' => 'Delete']);
        $post->delete();

        $firstTimestamp = $post->deleted_at;

        // Deleting again should still succeed (update deleted_at again)
        \sleep(1);
        $result = $post->delete();
        $this->assertTrue($result);
    }

    public function testRestoreNonDeletedModel(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $post = SD_Post::create($db, ['title' => 'Active', 'body' => 'Post']);

        // Restore on non-deleted → should still work (set deleted_at=NULL even though already null)
        $result = $post->restore();
        // Might or might not affect rows depending on DB behavior, but should not error
        $this->assertFalse($post->trashed());
    }

    public function testEmptyTableWithSoftDeletes(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $results = SD_Post::query($db)->get();
        $this->assertCount(0, $results);

        $results = SD_Post::query($db)->withTrashed()->get();
        $this->assertCount(0, $results);

        $results = SD_Post::query($db)->onlyTrashed()->get();
        $this->assertCount(0, $results);
    }

    public function testWhereNullMethodDirectly(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        SD_Post::create($db, ['title' => 'Active', 'body' => 'x']);
        $del = SD_Post::create($db, ['title' => 'Deleted', 'body' => 'y']);
        $del->delete();

        // Use whereNull directly on the query builder (bypassing global scope)
        $results = SD_Post::query($db)->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->get();

        $this->assertCount(1, $results);
        $this->assertSame('Active', $results->first()->title);
    }

    public function testWhereNotNullMethodDirectly(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        SD_Post::create($db, ['title' => 'Active', 'body' => 'x']);
        $del = SD_Post::create($db, ['title' => 'Deleted', 'body' => 'y']);
        $del->delete();

        // Use whereNotNull directly
        $results = SD_Post::query($db)->withoutGlobalScopes()
            ->whereNotNull('deleted_at')
            ->get();

        $this->assertCount(1, $results);
        $this->assertSame('Deleted', $results->first()->title);
    }

    public function testGetDeletedAtColumn(): void
    {
        $this->assertSame('deleted_at', SD_Post::getDeletedAtColumn());
    }

    public function testCustomDeletedAtColumn(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $item = SD_CustomColumn::create($db, ['name' => 'Test']);
        $item->delete();

        $this->assertTrue($item->trashed());
        $this->assertNotNull($item->removed_at);

        // Verify it's using the custom column
        $row = $db->prepare()->select('*')->from('sd_custom_columns')
            ->where('id=:id')->assign(['id' => $item->getKey()])->lazy();
        $this->assertNotNull($row['removed_at']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 10: Boot mechanism for traits
    // ═══════════════════════════════════════════════════════════════

    public function testSoftDeletesTraitAutoBoots(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        // Before any query, no scopes registered
        $scopesBefore = SD_Post::getGlobalScopes();
        $this->assertEmpty($scopesBefore);

        // First query triggers boot
        SD_Post::query($db);

        $scopesAfter = SD_Post::getGlobalScopes();
        $this->assertArrayHasKey('softDeletes', $scopesAfter);
    }

    public function testBootTraitsCalledAlongsideManualBoot(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        // SD_PublishedPost has boot() + SoftDeletes trait
        SD_PublishedPost::query($db);

        $scopes = SD_PublishedPost::getGlobalScopes();
        $this->assertArrayHasKey('softDeletes', $scopes);
        $this->assertArrayHasKey('published', $scopes);
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    private function createDb(): Database
    {
        static $counter = 0;
        $db = new Database('sd_test_' . (++$counter));
        $db->connectWithDriver('sqlite', ['path' => ':memory:']);

        return $db;
    }

    private function createSchema(Database $db): void
    {
        $adapter = $db->getDBAdapter();

        $db->clearStatementPool();
        $adapter->exec('
            CREATE TABLE IF NOT EXISTS sd_posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                body TEXT,
                deleted_at TEXT DEFAULT NULL,
                created_at TEXT,
                updated_at TEXT
            )
        ');

        $db->clearStatementPool();
        $adapter->exec('
            CREATE TABLE IF NOT EXISTS sd_published_posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT \'draft\',
                body TEXT,
                deleted_at TEXT DEFAULT NULL,
                created_at TEXT,
                updated_at TEXT
            )
        ');

        $db->clearStatementPool();
        $adapter->exec('
            CREATE TABLE IF NOT EXISTS sd_custom_columns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                removed_at TEXT DEFAULT NULL,
                created_at TEXT,
                updated_at TEXT
            )
        ');
    }
}

// ═══════════════════════════════════════════════════════════════
// Test Model Doubles
// ═══════════════════════════════════════════════════════════════

/**
 * Basic model with SoftDeletes.
 */
class SD_Post extends Model
{
    use SoftDeletes;

    protected static string $table = 'sd_posts';

    protected static array $fillable = ['title', 'body'];
}

/**
 * Model with SoftDeletes AND a custom global scope (published).
 */
class SD_PublishedPost extends Model
{
    use SoftDeletes;

    protected static string $table = 'sd_published_posts';

    protected static array $fillable = ['title', 'status', 'body'];

    protected static function boot(): void
    {
        static::addGlobalScope('published', function (ModelQuery $query): void {
            $query->where('status=:_gs_status', ['_gs_status' => 'published']);
        });
    }
}

/**
 * Model with a custom deleted_at column name.
 */
class SD_CustomColumn extends Model
{
    use SoftDeletes;

    protected static string $table = 'sd_custom_columns';

    protected static array $fillable = ['name'];

    public static function getDeletedAtColumn(): string
    {
        return 'removed_at';
    }
}
