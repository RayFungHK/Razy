<?php

declare(strict_types=1);

namespace Razy\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Database;
use Razy\ORM\Model;
use Razy\ORM\ModelQuery;
use Razy\ORM\Relation\HasMany;

/**
 * Tests for Visibility Packs — named serialisation views.
 *
 * The user concept (ORM-CONTRACT-PACKS.md decision 2, second half): the
 * Contract is the skeleton, a pack "就是指定 name/id visable 的屬性" — each
 * declared view lists exactly which attributes (including JSON sub-addresses
 * like profile.city) serialise out. Honest scope, pinned here: packs gate
 * OUTPUT (toArray/toJson); attributes remain in memory (getRawAttribute is
 * deliberately unaffected) because the SQL Executor inlines literals and
 * memory is module-trusted territory.
 */
#[CoversClass(Model::class)]
#[CoversClass(ModelQuery::class)]
class VisibilityPackTest extends TestCase
{
    // ── Fail-loud validation ─────────────────────────────────────────

    public function testUndeclaredPackThrowsAtQueryTimeBeforeAnySql(): void
    {
        $db = $this->createDb();

        $this->expectException(InvalidArgumentException::class);
        VP_User::query($db)->pack('ghost');
    }

    public function testUndeclaredPackErrorListsDeclaredNames(): void
    {
        $db = $this->createDb();

        try {
            VP_User::query($db)->pack('ghost');
            $this->fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('public', $e->getMessage(), 'error must list what IS declared');
        }
    }

    public function testMalformedPackDefinitionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VP_BadPack::getPackDefinition('oops');
    }

    public function testApplyPackFailsLoudOnInstance(): void
    {
        $model = new VP_User();

        $this->expectException(InvalidArgumentException::class);
        $model->applyPack('nope');
    }

    // ── View semantics ───────────────────────────────────────────────

    public function testPackWhitelistsScalarsAndPrunesJsonAddress(): void
    {
        $db = $this->createDb();
        $id = $this->seedUser($db);

        $model = VP_User::query($db)->pack('public')->find($id);
        $this->assertNotNull($model);

        $array = $model->toArray();
        $this->assertSame(['id', 'name', 'profile'], \array_keys($array));
        $this->assertSame(['city' => 'HK'], $array['profile'], 'only the declared sub-path leaks');
        $this->assertArrayNotHasKey('email', $array);
        $this->assertArrayNotHasKey('password_hash', $array);
    }

    public function testMergedAddressesOnSameRootCombine(): void
    {
        $db = $this->createDb();
        $id = $this->seedUser($db);

        $array = VP_User::query($db)->pack('contact')->find($id)->toArray();

        $this->assertSame(['city' => 'HK', 'phone' => '555'], $array['profile']);
        $this->assertArrayHasKey('email', $array, 'contact pack includes email');
    }

    public function testPackTakesPrecedenceOverHidden(): void
    {
        $db = $this->createDb();
        $id = $this->seedUser($db);

        $array = VP_User::query($db)->pack('adminview')->find($id)->toArray();

        $this->assertArrayHasKey('password_hash', $array, 'an explicit pack overrides $hidden by design');
        $this->assertSame(['id', 'password_hash'], \array_keys($array));
    }

    public function testMemoryGateBoundaryIsHonest(): void
    {
        $db = $this->createDb();
        $id = $this->seedUser($db);

        $model = VP_User::query($db)->pack('public')->find($id);

        // The documented boundary: OUTPUT is gated, in-memory access is not.
        $this->assertSame('ada@example.com', $model->getRawAttribute('email'));
        $this->assertSame('public', $model->getViewedPack());
    }

    public function testMissingOrNullJsonRootContributesNothing(): void
    {
        $db = $this->createDb();
        $id = $this->seedUser($db, 'Null', null);

        $array = VP_User::query($db)->pack('public')->find($id)->toArray();

        $this->assertSame(['id', 'name'], \array_keys($array), 'absent JSON root is omitted, never fabricated');
    }

    // ── Propagation ──────────────────────────────────────────────────

    public function testPackPropagatesThroughGetCollectionAndPaginate(): void
    {
        $db = $this->createDb();
        $this->seedUser($db, 'A');
        $this->seedUser($db, 'B');

        foreach (VP_User::query($db)->pack('public')->get() as $model) {
            $this->assertSame('public', $model->getViewedPack());
            $this->assertArrayNotHasKey('email', $model->toArray());
        }

        $page = VP_User::query($db)->pack('public')->paginate(1, 10);
        foreach ($page->items() as $model) {
            $this->assertSame('public', $model->getViewedPack());
        }
    }

    public function testEagerLoadedRelatedModelsKeepTheirOwnShape(): void
    {
        $db = $this->createDb();
        $id = $this->seedUser($db);
        VP_Post::create($db, ['title' => 'Post A', 'user_id' => $id]);

        $user = VP_User::query($db)->pack('public')->with('posts')->find($id);
        $posts = $user->posts; // magic property: loaded relation (EagerLoadingTest convention)

        $this->assertCount(1, $posts);
        $postArray = $posts->first()->toArray();
        $this->assertSame(['id', 'title', 'user_id'], \array_keys($postArray), 'pack shapes the queried model, not the graph beneath it');
    }

    public function testNoPackKeepsLegacyHiddenBehaviour(): void
    {
        $db = $this->createDb();
        $id = $this->seedUser($db);

        $array = VP_User::query($db)->find($id)->toArray();

        $this->assertArrayHasKey('email', $array);
        $this->assertArrayNotHasKey('password_hash', $array, 'hidden-only behaviour is byte-for-byte unchanged');
        $this->assertNull(VP_User::query($db)->find($id)->getViewedPack());
    }

    public function testToJsonCarriesThePackView(): void
    {
        $db = $this->createDb();
        $id = $this->seedUser($db);

        $json = VP_User::query($db)->pack('public')->find($id)->toJson();

        $decoded = \json_decode($json, true);
        $this->assertArrayNotHasKey('email', $decoded);
        $this->assertSame(['city' => 'HK'], $decoded['profile']);
    }

    private function createDb(): Database
    {
        static $counter = 0;
        $db = new Database('vp_test_' . (++$counter));
        $db->connectWithDriver('sqlite', ['path' => ':memory:']);

        $adapter = $db->getDBAdapter();
        $adapter->exec('
            CREATE TABLE vp_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT,
                password_hash TEXT,
                profile TEXT
            )
        ');
        $adapter->exec('
            CREATE TABLE vp_posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                user_id INTEGER
            )
        ');

        return $db;
    }

    /** @param array<string,mixed>|null $profile */
    private function seedUser(Database $db, string $name = 'Ada', ?array $profile = ['city' => 'HK', 'phone' => '555', 'secret' => 'top']): int
    {
        // casts write-path encodes the array — no hand-rolled JSON in SQL.
        $user = VP_User::create($db, [
            'name' => $name,
            'email' => 'ada@example.com',
            'password_hash' => 'HASHED',
            'profile' => $profile,
        ]);

        return (int) $user->getKey();
    }
}

// ═══════════════════════════════════════════════════════════════
// Model doubles
// ═══════════════════════════════════════════════════════════════

class VP_User extends Model
{
    protected static string $table = 'vp_users';

    protected static bool $timestamps = false;

    protected static array $fillable = ['name', 'email', 'password_hash', 'profile'];

    protected static array $casts = ['profile' => 'array'];

    /** serialisation default: never leak the hash */
    protected static array $hidden = ['password_hash'];

    protected static array $packs = [
        'public' => ['id', 'name', 'profile.city'],
        'contact' => ['id', 'name', 'email', 'profile.city', 'profile.phone'],
        'adminview' => ['id', 'password_hash'],
    ];

    public function posts(): HasMany
    {
        return $this->hasMany(VP_Post::class, 'user_id');
    }
}

class VP_Post extends Model
{
    protected static string $table = 'vp_posts';

    protected static bool $timestamps = false;

    protected static array $fillable = ['title', 'user_id'];
}

class VP_BadPack extends Model
{
    protected static string $table = 'vp_users';

    protected static bool $timestamps = false;

    protected static array $packs = [
        'oops' => ['id', 'Bad Name'],
    ];
}
