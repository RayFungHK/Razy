<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Database;
use Razy\ORM\Model;
use Razy\ORM\ModelCollection;
use Razy\ORM\ModelQuery;
use Razy\ORM\Relation\Relation;

/**
 * Tests for P18: Query Scopes (Local & Global).
 *
 * Covers the Model boot mechanism, global scopes (`addGlobalScope`,
 * `withoutGlobalScope`, `withoutGlobalScopes`), and local scopes
 * (`scope*` methods via `ModelQuery::__call`).
 */
#[CoversClass(ModelQuery::class)]
#[CoversClass(Model::class)]
class QueryScopesTest extends TestCase
{
    protected function tearDown(): void
    {
        // Clear boot state & global scopes between tests to prevent leaks
        Model::clearBootedModels();
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 1: Boot mechanism
    // ═══════════════════════════════════════════════════════════════

    public function testBootIsCalledOnFirstQuery(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        // QS_BootTracking::$bootCount is incremented in boot()
        QS_BootTracking::$bootCount = 0;

        QS_BootTracking::query($db)->get();

        $this->assertSame(1, QS_BootTracking::$bootCount);
    }

    public function testBootIsCalledOnlyOnce(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        QS_BootTracking::$bootCount = 0;

        QS_BootTracking::query($db)->get();
        QS_BootTracking::query($db)->get();
        QS_BootTracking::query($db)->get();

        $this->assertSame(1, QS_BootTracking::$bootCount);
    }

    public function testClearBootedModelsResetsBootState(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        QS_BootTracking::$bootCount = 0;

        QS_BootTracking::query($db)->get();
        $this->assertSame(1, QS_BootTracking::$bootCount);

        Model::clearBootedModels();

        QS_BootTracking::query($db)->get();
        $this->assertSame(2, QS_BootTracking::$bootCount);
    }

    public function testBootIsPerClass(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        QS_BootTracking::$bootCount = 0;

        // Query two different model classes
        QS_BootTracking::query($db)->get();
        QS_Article::query($db)->get();

        // Each class boots independently
        $this->assertSame(1, QS_BootTracking::$bootCount);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 2: Local Scopes — basic usage
    // ═══════════════════════════════════════════════════════════════

    public function testLocalScopeFiltersResults(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        QS_Article::create($db, ['title' => 'Draft 1', 'status' => 'draft', 'category' => 'tech']);
        QS_Article::create($db, ['title' => 'Pub 1', 'status' => 'published', 'category' => 'tech']);
        QS_Article::create($db, ['title' => 'Pub 2', 'status' => 'published', 'category' => 'news']);

        $published = QS_Article::query($db)->published()->get();

        $this->assertCount(2, $published);

        $titles = $published->pluck('title');
        sort($titles);
        $this->assertSame(['Pub 1', 'Pub 2'], $titles);
    }

    public function testLocalScopeWithParameters(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        QS_Article::create($db, ['title' => 'Tech 1', 'status' => 'published', 'category' => 'tech']);
        QS_Article::create($db, ['title' => 'News 1', 'status' => 'published', 'category' => 'news']);
        QS_Article::create($db, ['title' => 'Tech 2', 'status' => 'draft', 'category' => 'tech']);

        $tech = QS_Article::query($db)->inCategory('tech')->get();

        $this->assertCount(2, $tech);

        $titles = $tech->pluck('title');
        sort($titles);
        $this->assertSame(['Tech 1', 'Tech 2'], $titles);
    }

    public function testLocalScopeChainingMultipleScopes(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        QS_Article::create($db, ['title' => 'Draft Tech', 'status' => 'draft', 'category' => 'tech']);
        QS_Article::create($db, ['title' => 'Pub Tech', 'status' => 'published', 'category' => 'tech']);
        QS_Article::create($db, ['title' => 'Pub News', 'status' => 'published', 'category' => 'news']);

        $results = QS_Article::query($db)->published()->inCategory('tech')->get();

        $this->assertCount(1, $results);
        $this->assertSame('Pub Tech', $results->first()->title);
    }

    public function testLocalScopeChainedWithWhere(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        QS_Article::create($db, ['title' => 'A', 'status' => 'published', 'category' => 'tech']);
        QS_Article::create($db, ['title' => 'B', 'status' => 'published', 'category' => 'tech']);

        $results = QS_Article::query($db)
            ->published()
            ->where('title=:t', ['t' => 'A'])
            ->get();

        $this->assertCount(1, $results);
        $this->assertSame('A', $results->first()->title);
    }

    public function testLocalScopeChainedWithOrderAndLimit(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        QS_Article::create($db, ['title' => 'C', 'status' => 'published', 'category' => 'tech']);
        QS_Article::create($db, ['title' => 'A', 'status' => 'published', 'category' => 'tech']);
        QS_Article::create($db, ['title' => 'B', 'status' => 'published', 'category' => 'tech']);

        $results = QS_Article::query($db)
            ->published()
            ->orderBy('title')
            ->limit(2)
            ->get();

        $this->assertCount(2, $results);
        $this->assertSame('A', $results[0]->title);
        $this->assertSame('B', $results[1]->title);
    }

    public function testLocalScopeReturnsModelQueryForFluency(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $query = QS_Article::query($db)->published();
        $this->assertInstanceOf(ModelQuery::class, $query);
    }

    public function testLocalScopeWorksWithFirst(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        QS_Article::create($db, ['title' => 'Draft', 'status' => 'draft', 'category' => 'tech']);
        QS_Article::create($db, ['title' => 'Published', 'status' => 'published', 'category' => 'tech']);

        $article = QS_Article::query($db)->published()->first();

        $this->assertNotNull($article);
        $this->assertSame('Published', $article->title);
    }

    public function testLocalScopeWorksWithCount(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        QS_Article::create($db, ['title' => 'D1', 'status' => 'draft', 'category' => 'tech']);
        QS_Article::create($db, ['title' => 'P1', 'status' => 'published', 'category' => 'tech']);
        QS_Article::create($db, ['title' => 'P2', 'status' => 'published', 'category' => 'news']);

        $count = QS_Article::query($db)->published()->count();

        $this->assertSame(2, $count);
    }

    public function testUndefinedScopeThrowsBadMethodCall(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $this->expectException(\BadMethodCallException::class);

        QS_Article::query($db)->nonExistentScope();
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 3: Global Scopes — basic usage
    // ═══════════════════════════════════════════════════════════════

    public function testGlobalScopeAppliedAutomatically(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        // QS_ActiveUser has a global scope that filters active=1
        QS_ActiveUser::create($db, ['name' => 'Active Alice', 'active' => 1]);
        QS_ActiveUser::create($db, ['name' => 'Inactive Bob', 'active' => 0]);

        $users = QS_ActiveUser::query($db)->get();

        $this->assertCount(1, $users);
        $this->assertSame('Active Alice', $users->first()->name);
    }

    public function testGlobalScopeAppliedToFirst(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        QS_ActiveUser::create($db, ['name' => 'Inactive', 'active' => 0]);
        QS_ActiveUser::create($db, ['name' => 'Active', 'active' => 1]);

        $user = QS_ActiveUser::query($db)->first();

        $this->assertNotNull($user);
        $this->assertSame('Active', $user->name);
    }

    public function testGlobalScopeAppliedToCount(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        QS_ActiveUser::create($db, ['name' => 'Active', 'active' => 1]);
        QS_ActiveUser::create($db, ['name' => 'Inactive', 'active' => 0]);

        $count = QS_ActiveUser::query($db)->count();

        $this->assertSame(1, $count);
    }

    public function testGlobalScopeAppliedToFind(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        QS_ActiveUser::create($db, ['name' => 'Inactive', 'active' => 0]);
        $active = QS_ActiveUser::create($db, ['name' => 'Active', 'active' => 1]);

        // find() uses where + first(), so global scope should apply
        $found = QS_ActiveUser::query($db)->find($active->getKey());
        $this->assertNotNull($found);

        // Looking for the inactive user should return null
        $notFound = QS_ActiveUser::query($db)->find(1);
        $this->assertNull($notFound);
    }

    public function testGlobalScopeAppliedToPaginate(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        QS_ActiveUser::create($db, ['name' => 'Active 1', 'active' => 1]);
        QS_ActiveUser::create($db, ['name' => 'Active 2', 'active' => 1]);
        QS_ActiveUser::create($db, ['name' => 'Inactive', 'active' => 0]);

        $result = QS_ActiveUser::query($db)->paginate(1, 10);

        $this->assertSame(2, $result['total']);
        $this->assertCount(2, $result['data']);
    }

    public function testGlobalScopeAppliedToBulkUpdate(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        QS_ActiveUser::create($db, ['name' => 'Active', 'active' => 1]);
        QS_ActiveUser::create($db, ['name' => 'Inactive', 'active' => 0]);

        // bulkUpdate with global scope should only affect active users
        $affected = QS_ActiveUser::query($db)->bulkUpdate(['name' => 'Updated']);

        $this->assertSame(1, $affected);

        // Verify: active user was updated, inactive was not
        // Use raw query (no global scope) to check
        $rows = $db->prepare()->select('name,active')->from('qs_active_users')->lazyGroup();

        $active = array_filter($rows, fn($r) => (int) $r['active'] === 1);
        $inactive = array_filter($rows, fn($r) => (int) $r['active'] === 0);

        $this->assertSame('Updated', array_values($active)[0]['name']);
        $this->assertSame('Inactive', array_values($inactive)[0]['name']);
    }

    public function testGlobalScopeAppliedToBulkDelete(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        QS_ActiveUser::create($db, ['name' => 'Active', 'active' => 1]);
        QS_ActiveUser::create($db, ['name' => 'Inactive', 'active' => 0]);

        // bulkDelete with global scope should only delete active users
        $deleted = QS_ActiveUser::query($db)->bulkDelete();

        $this->assertSame(1, $deleted);

        // Inactive user should survive
        $rows = $db->prepare()->select('*')->from('qs_active_users')->lazyGroup();
        $this->assertCount(1, $rows);
        $this->assertSame('Inactive', $rows[0]['name']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 4: Removing global scopes
    // ═══════════════════════════════════════════════════════════════

    public function testWithoutGlobalScopeRemovesSpecificScope(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        QS_ActiveUser::create($db, ['name' => 'Active', 'active' => 1]);
        QS_ActiveUser::create($db, ['name' => 'Inactive', 'active' => 0]);

        $users = QS_ActiveUser::query($db)->withoutGlobalScope('active')->get();

        $this->assertCount(2, $users);
    }

    public function testWithoutGlobalScopesRemovesAll(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        QS_ActiveUser::create($db, ['name' => 'Active', 'active' => 1]);
        QS_ActiveUser::create($db, ['name' => 'Inactive', 'active' => 0]);

        $users = QS_ActiveUser::query($db)->withoutGlobalScopes()->get();

        $this->assertCount(2, $users);
    }

    public function testWithoutGlobalScopeOnlyRemovesNamed(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        // QS_MultiScopeUser has two global scopes: 'active' and 'verified'
        QS_MultiScopeUser::create($db, ['name' => 'Both', 'active' => 1, 'verified' => 1]);
        QS_MultiScopeUser::create($db, ['name' => 'ActiveOnly', 'active' => 1, 'verified' => 0]);
        QS_MultiScopeUser::create($db, ['name' => 'VerifiedOnly', 'active' => 0, 'verified' => 1]);
        QS_MultiScopeUser::create($db, ['name' => 'Neither', 'active' => 0, 'verified' => 0]);

        // Remove only 'active' scope → should include active=0 but still filter verified=1
        $users = QS_MultiScopeUser::query($db)
            ->withoutGlobalScope('active')
            ->get();

        $this->assertCount(2, $users);
        $names = $users->pluck('name');
        sort($names);
        $this->assertSame(['Both', 'VerifiedOnly'], $names);
    }

    public function testWithoutGlobalScopeMultipleNames(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        QS_MultiScopeUser::create($db, ['name' => 'Both', 'active' => 1, 'verified' => 1]);
        QS_MultiScopeUser::create($db, ['name' => 'Neither', 'active' => 0, 'verified' => 0]);

        // Remove both scopes by name
        $users = QS_MultiScopeUser::query($db)
            ->withoutGlobalScope('active', 'verified')
            ->get();

        $this->assertCount(2, $users);
    }

    public function testWithoutGlobalScopesChainedWithWhere(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        QS_ActiveUser::create($db, ['name' => 'Active', 'active' => 1]);
        QS_ActiveUser::create($db, ['name' => 'Inactive', 'active' => 0]);

        $users = QS_ActiveUser::query($db)
            ->withoutGlobalScopes()
            ->where('name=:n', ['n' => 'Inactive'])
            ->get();

        $this->assertCount(1, $users);
        $this->assertSame('Inactive', $users->first()->name);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 5: Global Scope registration API
    // ═══════════════════════════════════════════════════════════════

    public function testAddGlobalScopeRegistersScope(): void
    {
        $scopes = QS_ActiveUser::getGlobalScopes();

        // Before boot, no scopes
        $this->assertEmpty($scopes);

        // Trigger boot
        $db = $this->createDb();
        $this->createSchema($db);
        QS_ActiveUser::query($db);

        $scopes = QS_ActiveUser::getGlobalScopes();
        $this->assertArrayHasKey('active', $scopes);
    }

    public function testRemoveGlobalScope(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        // Boot to register scopes
        QS_ActiveUser::query($db);

        $this->assertArrayHasKey('active', QS_ActiveUser::getGlobalScopes());

        QS_ActiveUser::removeGlobalScope('active');

        $this->assertArrayNotHasKey('active', QS_ActiveUser::getGlobalScopes());
    }

    public function testGetGlobalScopesReturnsEmptyForModelsWithNoScopes(): void
    {
        $scopes = QS_Article::getGlobalScopes();
        $this->assertEmpty($scopes);
    }

    public function testGlobalScopesArePerClass(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        // Boot both classes
        QS_ActiveUser::query($db);
        QS_Article::query($db);

        $this->assertNotEmpty(QS_ActiveUser::getGlobalScopes());
        $this->assertEmpty(QS_Article::getGlobalScopes());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 6: Combining local and global scopes
    // ═══════════════════════════════════════════════════════════════

    public function testLocalScopeWithGlobalScope(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        // QS_ScopedArticle has a global scope (published) + local scope (inCategory)
        QS_ScopedArticle::create($db, ['title' => 'Published Tech', 'status' => 'published', 'category' => 'tech']);
        QS_ScopedArticle::create($db, ['title' => 'Draft Tech', 'status' => 'draft', 'category' => 'tech']);
        QS_ScopedArticle::create($db, ['title' => 'Published News', 'status' => 'published', 'category' => 'news']);

        // Global scope filters to published; local scope further filters to tech
        $results = QS_ScopedArticle::query($db)->inCategory('tech')->get();

        $this->assertCount(1, $results);
        $this->assertSame('Published Tech', $results->first()->title);
    }

    public function testLocalScopeBypassingGlobalScope(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        QS_ScopedArticle::create($db, ['title' => 'Published', 'status' => 'published', 'category' => 'tech']);
        QS_ScopedArticle::create($db, ['title' => 'Draft', 'status' => 'draft', 'category' => 'tech']);

        // Remove global scope, keep local scope
        $results = QS_ScopedArticle::query($db)
            ->withoutGlobalScope('published')
            ->inCategory('tech')
            ->get();

        $this->assertCount(2, $results);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 7: Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testLocalScopeReturningNullIsHandled(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        QS_Article::create($db, ['title' => 'Test', 'status' => 'published', 'category' => 'tech']);

        // scopeVoid doesn't explicitly return, so returns null → __call should return $this
        $query = QS_Article::query($db)->voidScope();
        $this->assertInstanceOf(ModelQuery::class, $query);
    }

    public function testGlobalScopeWithEagerLoading(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $active = QS_ActiveUser::create($db, ['name' => 'Active', 'active' => 1]);
        QS_ActiveUser::create($db, ['name' => 'Inactive', 'active' => 0]);

        // Create posts for both
        $db->clearStatementPool();
        $db->getDBAdapter()->exec("INSERT INTO qs_posts (title, qs_activeuser_id) VALUES ('Post A', {$active->getKey()})");
        $db->getDBAdapter()->exec("INSERT INTO qs_posts (title, qs_activeuser_id) VALUES ('Post B', 1)");

        // Eager load with global scope active
        $users = QS_ActiveUser::query($db)->with('posts')->get();

        $this->assertCount(1, $users);
        $this->assertTrue($users->first()->relationLoaded('posts'));
    }

    public function testWithoutGlobalScopeReturnsFluent(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $query = QS_ActiveUser::query($db)->withoutGlobalScope('active');
        $this->assertInstanceOf(ModelQuery::class, $query);
    }

    public function testWithoutGlobalScopesReturnsFluent(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $query = QS_ActiveUser::query($db)->withoutGlobalScopes();
        $this->assertInstanceOf(ModelQuery::class, $query);
    }

    public function testGlobalScopeOnEmptyTableReturnsEmptyCollection(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $users = QS_ActiveUser::query($db)->get();
        $this->assertCount(0, $users);
    }

    public function testLocalScopeOnEmptyTableReturnsEmptyCollection(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $results = QS_Article::query($db)->published()->get();
        $this->assertCount(0, $results);
    }

    public function testModelConvenienceMethodsUseGlobalScopes(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        QS_ActiveUser::create($db, ['name' => 'Inactive', 'active' => 0]);
        QS_ActiveUser::create($db, ['name' => 'Active', 'active' => 1]);

        // Model::all() goes through query()->get()
        $all = QS_ActiveUser::all($db);
        $this->assertCount(1, $all);

        // Model::find() goes through query()->find()
        // ID 1 is 'Inactive' → should be null because global scope filters it
        $found = QS_ActiveUser::find($db, 1);
        $this->assertNull($found);

        // ID 2 is 'Active' → should be found
        $found2 = QS_ActiveUser::find($db, 2);
        $this->assertNotNull($found2);
        $this->assertSame('Active', $found2->name);
    }

    public function testMultipleGlobalScopes(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        QS_MultiScopeUser::create($db, ['name' => 'Both', 'active' => 1, 'verified' => 1]);
        QS_MultiScopeUser::create($db, ['name' => 'ActiveOnly', 'active' => 1, 'verified' => 0]);
        QS_MultiScopeUser::create($db, ['name' => 'VerifiedOnly', 'active' => 0, 'verified' => 1]);
        QS_MultiScopeUser::create($db, ['name' => 'Neither', 'active' => 0, 'verified' => 0]);

        // Both scopes active → only 'Both' matches
        $users = QS_MultiScopeUser::query($db)->get();

        $this->assertCount(1, $users);
        $this->assertSame('Both', $users->first()->name);
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    private function createDb(): Database
    {
        static $counter = 0;
        $db = new Database('qs_test_' . (++$counter));
        $db->connectWithDriver('sqlite', ['path' => ':memory:']);

        return $db;
    }

    private function createSchema(Database $db): void
    {
        $adapter = $db->getDBAdapter();

        $db->clearStatementPool();
        $adapter->exec('
            CREATE TABLE IF NOT EXISTS qs_articles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT \'draft\',
                category TEXT,
                created_at TEXT,
                updated_at TEXT
            )
        ');

        $db->clearStatementPool();
        $adapter->exec('
            CREATE TABLE IF NOT EXISTS qs_active_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT,
                updated_at TEXT
            )
        ');

        $db->clearStatementPool();
        $adapter->exec('
            CREATE TABLE IF NOT EXISTS qs_multi_scope_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                active INTEGER NOT NULL DEFAULT 1,
                verified INTEGER NOT NULL DEFAULT 0,
                created_at TEXT,
                updated_at TEXT
            )
        ');

        $db->clearStatementPool();
        $adapter->exec('
            CREATE TABLE IF NOT EXISTS qs_scoped_articles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT \'draft\',
                category TEXT,
                created_at TEXT,
                updated_at TEXT
            )
        ');

        $db->clearStatementPool();
        $adapter->exec('
            CREATE TABLE IF NOT EXISTS qs_boot_trackings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                created_at TEXT,
                updated_at TEXT
            )
        ');

        $db->clearStatementPool();
        $adapter->exec('
            CREATE TABLE IF NOT EXISTS qs_posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                qs_activeuser_id INTEGER,
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
 * Article model with local scopes but no global scopes.
 */
class QS_Article extends Model
{
    protected static string $table = 'qs_articles';
    protected static array $fillable = ['title', 'status', 'category'];

    /**
     * Local scope: filter to published articles.
     */
    public function scopePublished(ModelQuery $query): ModelQuery
    {
        return $query->where('status=:_s_status', ['_s_status' => 'published']);
    }

    /**
     * Local scope: filter by category (parameterized).
     */
    public function scopeInCategory(ModelQuery $query, string $category): ModelQuery
    {
        return $query->where('category=:_s_cat', ['_s_cat' => $category]);
    }

    /**
     * Local scope that returns void (implicitly null).
     */
    public function scopeVoidScope(ModelQuery $query): void
    {
        $query->where('1=1');
    }
}

/**
 * User model with a global 'active' scope.
 */
class QS_ActiveUser extends Model
{
    protected static string $table = 'qs_active_users';
    protected static array $fillable = ['name', 'active'];

    protected static function boot(): void
    {
        static::addGlobalScope('active', function (ModelQuery $query): void {
            $query->where('active=:_gs_active', ['_gs_active' => 1]);
        });
    }

    public function posts(): \Razy\ORM\Relation\HasMany
    {
        return $this->hasMany(QS_Post::class, 'qs_activeuser_id');
    }
}

/**
 * Post model for testing eager loading with global scopes.
 */
class QS_Post extends Model
{
    protected static string $table = 'qs_posts';
    protected static array $fillable = ['title', 'qs_activeuser_id'];
}

/**
 * User model with TWO global scopes: active + verified.
 */
class QS_MultiScopeUser extends Model
{
    protected static string $table = 'qs_multi_scope_users';
    protected static array $fillable = ['name', 'active', 'verified'];

    protected static function boot(): void
    {
        static::addGlobalScope('active', function (ModelQuery $query): void {
            $query->where('active=:_gs_active', ['_gs_active' => 1]);
        });

        static::addGlobalScope('verified', function (ModelQuery $query): void {
            $query->where('verified=:_gs_verified', ['_gs_verified' => 1]);
        });
    }
}

/**
 * Article model with BOTH a global scope and local scopes.
 */
class QS_ScopedArticle extends Model
{
    protected static string $table = 'qs_scoped_articles';
    protected static array $fillable = ['title', 'status', 'category'];

    protected static function boot(): void
    {
        static::addGlobalScope('published', function (ModelQuery $query): void {
            $query->where('status=:_gs_pub', ['_gs_pub' => 'published']);
        });
    }

    public function scopeInCategory(ModelQuery $query, string $category): ModelQuery
    {
        return $query->where('category=:_s_cat', ['_s_cat' => $category]);
    }
}

/**
 * Model that tracks boot calls for testing the boot mechanism.
 */
class QS_BootTracking extends Model
{
    protected static string $table = 'qs_boot_trackings';
    protected static array $fillable = ['name'];

    public static int $bootCount = 0;

    protected static function boot(): void
    {
        static::$bootCount++;
    }
}
