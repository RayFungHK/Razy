<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Database;
use Razy\ORM\Model;
use Razy\ORM\ModelCollection;
use Razy\ORM\ModelQuery;
use Razy\ORM\Relation\BelongsTo;
use Razy\ORM\Relation\BelongsToMany;
use Razy\ORM\Relation\HasMany;
use Razy\ORM\Relation\HasOne;
use Razy\ORM\Relation\Relation;

/**
 * Tests for P17: Eager Loading (N+1 Prevention).
 *
 * Covers the `with()` method on ModelQuery and the `loadEagerRelations()`
 * engine, along with `setRelation()`, `relationLoaded()`, and
 * `getRelationInstance()` on Model.
 */
#[CoversClass(ModelQuery::class)]
#[CoversClass(Model::class)]
#[CoversClass(HasOne::class)]
#[CoversClass(HasMany::class)]
#[CoversClass(BelongsTo::class)]
#[CoversClass(BelongsToMany::class)]
#[CoversClass(Relation::class)]
#[CoversClass(ModelCollection::class)]
class EagerLoadingTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Section 1: with() API
    // ═══════════════════════════════════════════════════════════════

    public function testWithReturnsModelQueryInstance(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $query = EL_Author::query($db)->with('posts');
        $this->assertInstanceOf(ModelQuery::class, $query);
    }

    public function testWithAcceptsMultipleRelationNames(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        // Should not throw — just builds query
        $query = EL_Author::query($db)->with('posts', 'profile');
        $this->assertInstanceOf(ModelQuery::class, $query);
    }

    public function testWithIsChainingFriendly(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $query = EL_Author::query($db)
            ->with('posts')
            ->where('name=:n', ['n' => 'Alice']);

        $this->assertInstanceOf(ModelQuery::class, $query);
    }

    public function testWithDeduplicatesRelationNames(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        EL_Author::create($db, ['name' => 'Alice']);

        // Calling with() twice with overlapping names should not cause errors
        $authors = EL_Author::query($db)
            ->with('posts')
            ->with('posts', 'profile')
            ->get();

        $this->assertCount(1, $authors);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 2: Model::setRelation / relationLoaded / getRelationInstance
    // ═══════════════════════════════════════════════════════════════

    public function testSetRelationStoresValueInCache(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $author = EL_Author::create($db, ['name' => 'Alice']);
        $collection = new ModelCollection([]);

        $result = $author->setRelation('posts', $collection);

        $this->assertSame($author, $result); // Fluent return
        $this->assertTrue($author->relationLoaded('posts'));
    }

    public function testSetRelationAcceptsNull(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $author = EL_Author::create($db, ['name' => 'Alice']);
        $author->setRelation('profile', null);

        $this->assertTrue($author->relationLoaded('profile'));
        $this->assertNull($author->profile);
    }

    public function testRelationLoadedReturnsFalseWhenNotSet(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $author = EL_Author::create($db, ['name' => 'Alice']);

        $this->assertFalse($author->relationLoaded('posts'));
    }

    public function testGetRelationInstanceReturnsRelation(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $author = EL_Author::create($db, ['name' => 'Alice']);

        $relation = $author->getRelationInstance('posts');
        $this->assertInstanceOf(HasMany::class, $relation);
    }

    public function testGetRelationInstanceReturnsNullForNonExistentMethod(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $author = EL_Author::create($db, ['name' => 'Alice']);
        $this->assertNull($author->getRelationInstance('nonExistent'));
    }

    public function testGetRelationInstanceReturnsNullForNonRelationMethod(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $author = EL_Author::create($db, ['name' => 'Alice']);
        // getKey() exists but doesn't return a Relation
        $this->assertNull($author->getRelationInstance('getKey'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 3: HasMany eager loading
    // ═══════════════════════════════════════════════════════════════

    public function testEagerLoadHasManyBasic(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $alice = EL_Author::create($db, ['name' => 'Alice']);
        EL_Post::create($db, ['title' => 'Post 1', 'author_id' => $alice->getKey()]);
        EL_Post::create($db, ['title' => 'Post 2', 'author_id' => $alice->getKey()]);

        $authors = EL_Author::query($db)->with('posts')->get();

        $this->assertCount(1, $authors);
        $author = $authors->first();

        $this->assertTrue($author->relationLoaded('posts'));
        $this->assertInstanceOf(ModelCollection::class, $author->posts);
        $this->assertCount(2, $author->posts);
    }

    public function testEagerLoadHasManyMultipleParents(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $alice = EL_Author::create($db, ['name' => 'Alice']);
        $bob = EL_Author::create($db, ['name' => 'Bob']);

        EL_Post::create($db, ['title' => 'A1', 'author_id' => $alice->getKey()]);
        EL_Post::create($db, ['title' => 'A2', 'author_id' => $alice->getKey()]);
        EL_Post::create($db, ['title' => 'B1', 'author_id' => $bob->getKey()]);

        $authors = EL_Author::query($db)->with('posts')->orderBy('name')->get();

        $this->assertCount(2, $authors);

        // Alice has 2 posts
        $aliceResult = $authors[0];
        $this->assertSame('Alice', $aliceResult->name);
        $this->assertTrue($aliceResult->relationLoaded('posts'));
        $this->assertCount(2, $aliceResult->posts);

        // Bob has 1 post
        $bobResult = $authors[1];
        $this->assertSame('Bob', $bobResult->name);
        $this->assertTrue($bobResult->relationLoaded('posts'));
        $this->assertCount(1, $bobResult->posts);
    }

    public function testEagerLoadHasManyWithNoRelatedRecords(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $alice = EL_Author::create($db, ['name' => 'Alice']);

        $authors = EL_Author::query($db)->with('posts')->get();

        $this->assertCount(1, $authors);
        $author = $authors->first();
        $this->assertTrue($author->relationLoaded('posts'));
        $this->assertCount(0, $author->posts);
    }

    public function testEagerLoadHasManyCorrectlyGroupsByFK(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $a = EL_Author::create($db, ['name' => 'Alice']);
        $b = EL_Author::create($db, ['name' => 'Bob']);
        $c = EL_Author::create($db, ['name' => 'Charlie']);

        EL_Post::create($db, ['title' => 'PA1', 'author_id' => $a->getKey()]);
        EL_Post::create($db, ['title' => 'PB1', 'author_id' => $b->getKey()]);
        EL_Post::create($db, ['title' => 'PB2', 'author_id' => $b->getKey()]);

        $authors = EL_Author::query($db)->with('posts')->orderBy('name')->get();

        $this->assertCount(3, $authors);

        $this->assertCount(1, $authors[0]->posts); // Alice
        $this->assertCount(2, $authors[1]->posts); // Bob
        $this->assertCount(0, $authors[2]->posts); // Charlie
    }

    public function testEagerLoadHasManyPostsAreCorrectType(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $a = EL_Author::create($db, ['name' => 'Alice']);
        EL_Post::create($db, ['title' => 'Post 1', 'author_id' => $a->getKey()]);

        $authors = EL_Author::query($db)->with('posts')->get();
        $post = $authors->first()->posts->first();

        $this->assertInstanceOf(EL_Post::class, $post);
        $this->assertSame('Post 1', $post->title);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 4: HasOne eager loading
    // ═══════════════════════════════════════════════════════════════

    public function testEagerLoadHasOneBasic(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $alice = EL_Author::create($db, ['name' => 'Alice']);
        EL_Profile::create($db, ['bio' => 'Engineer', 'author_id' => $alice->getKey()]);

        $authors = EL_Author::query($db)->with('profile')->get();

        $this->assertCount(1, $authors);
        $author = $authors->first();

        $this->assertTrue($author->relationLoaded('profile'));
        $this->assertInstanceOf(EL_Profile::class, $author->profile);
        $this->assertSame('Engineer', $author->profile->bio);
    }

    public function testEagerLoadHasOneMultipleParents(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $alice = EL_Author::create($db, ['name' => 'Alice']);
        $bob = EL_Author::create($db, ['name' => 'Bob']);

        EL_Profile::create($db, ['bio' => 'Engineer', 'author_id' => $alice->getKey()]);
        EL_Profile::create($db, ['bio' => 'Designer', 'author_id' => $bob->getKey()]);

        $authors = EL_Author::query($db)->with('profile')->orderBy('name')->get();

        $this->assertSame('Engineer', $authors[0]->profile->bio);
        $this->assertSame('Designer', $authors[1]->profile->bio);
    }

    public function testEagerLoadHasOneReturnsNullWhenNoMatch(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $alice = EL_Author::create($db, ['name' => 'Alice']);

        $authors = EL_Author::query($db)->with('profile')->get();

        $author = $authors->first();
        $this->assertTrue($author->relationLoaded('profile'));
        $this->assertNull($author->profile);
    }

    public function testEagerLoadHasOneMixedExistence(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $alice = EL_Author::create($db, ['name' => 'Alice']);
        $bob = EL_Author::create($db, ['name' => 'Bob']);

        // Only Alice has a profile
        EL_Profile::create($db, ['bio' => 'Engineer', 'author_id' => $alice->getKey()]);

        $authors = EL_Author::query($db)->with('profile')->orderBy('name')->get();

        $this->assertInstanceOf(EL_Profile::class, $authors[0]->profile); // Alice
        $this->assertNull($authors[1]->profile); // Bob
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 5: BelongsTo eager loading
    // ═══════════════════════════════════════════════════════════════

    public function testEagerLoadBelongsToBasic(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $alice = EL_Author::create($db, ['name' => 'Alice']);
        EL_Post::create($db, ['title' => 'Post 1', 'author_id' => $alice->getKey()]);

        $posts = EL_Post::query($db)->with('author')->get();

        $this->assertCount(1, $posts);
        $post = $posts->first();

        $this->assertTrue($post->relationLoaded('author'));
        $this->assertInstanceOf(EL_Author::class, $post->author);
        $this->assertSame('Alice', $post->author->name);
    }

    public function testEagerLoadBelongsToMultipleChildrenSameParent(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $alice = EL_Author::create($db, ['name' => 'Alice']);
        EL_Post::create($db, ['title' => 'P1', 'author_id' => $alice->getKey()]);
        EL_Post::create($db, ['title' => 'P2', 'author_id' => $alice->getKey()]);

        $posts = EL_Post::query($db)->with('author')->get();

        $this->assertCount(2, $posts);
        // Both posts point to Alice
        $this->assertSame('Alice', $posts[0]->author->name);
        $this->assertSame('Alice', $posts[1]->author->name);
    }

    public function testEagerLoadBelongsToMultipleChildrenDifferentParents(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $alice = EL_Author::create($db, ['name' => 'Alice']);
        $bob = EL_Author::create($db, ['name' => 'Bob']);

        EL_Post::create($db, ['title' => 'P1', 'author_id' => $alice->getKey()]);
        EL_Post::create($db, ['title' => 'P2', 'author_id' => $bob->getKey()]);

        $posts = EL_Post::query($db)->with('author')->orderBy('title')->get();

        $this->assertSame('Alice', $posts[0]->author->name);
        $this->assertSame('Bob', $posts[1]->author->name);
    }

    public function testEagerLoadBelongsToNullFK(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        // Post with no author_id
        EL_Post::create($db, ['title' => 'Orphan', 'author_id' => null]);

        $posts = EL_Post::query($db)->with('author')->get();

        $this->assertCount(1, $posts);
        $this->assertTrue($posts->first()->relationLoaded('author'));
        $this->assertNull($posts->first()->author);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 6: BelongsToMany eager loading
    // ═══════════════════════════════════════════════════════════════

    public function testEagerLoadBelongsToManyBasic(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $alice = EL_Author::create($db, ['name' => 'Alice']);
        $t1 = EL_Tag::create($db, ['label' => 'php']);
        $t2 = EL_Tag::create($db, ['label' => 'laravel']);

        $alice->tags()->attach([$t1->getKey(), $t2->getKey()]);

        $authors = EL_Author::query($db)->with('tags')->get();

        $this->assertCount(1, $authors);
        $author = $authors->first();

        $this->assertTrue($author->relationLoaded('tags'));
        $this->assertInstanceOf(ModelCollection::class, $author->tags);
        $this->assertCount(2, $author->tags);

        $labels = $author->tags->pluck('label');
        sort($labels);
        $this->assertSame(['laravel', 'php'], $labels);
    }

    public function testEagerLoadBelongsToManyMultipleParents(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $alice = EL_Author::create($db, ['name' => 'Alice']);
        $bob = EL_Author::create($db, ['name' => 'Bob']);

        $php = EL_Tag::create($db, ['label' => 'php']);
        $js = EL_Tag::create($db, ['label' => 'js']);
        $go = EL_Tag::create($db, ['label' => 'go']);

        $alice->tags()->attach([$php->getKey(), $js->getKey()]);
        $bob->tags()->attach([$js->getKey(), $go->getKey()]);

        $authors = EL_Author::query($db)->with('tags')->orderBy('name')->get();

        $this->assertCount(2, $authors);

        // Alice → php, js
        $aliceTags = $authors[0]->tags->pluck('label');
        sort($aliceTags);
        $this->assertSame(['js', 'php'], $aliceTags);

        // Bob → go, js
        $bobTags = $authors[1]->tags->pluck('label');
        sort($bobTags);
        $this->assertSame(['go', 'js'], $bobTags);
    }

    public function testEagerLoadBelongsToManyEmptyResult(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        EL_Author::create($db, ['name' => 'Alice']);

        $authors = EL_Author::query($db)->with('tags')->get();

        $author = $authors->first();
        $this->assertTrue($author->relationLoaded('tags'));
        $this->assertCount(0, $author->tags);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 7: Multiple relations in one query
    // ═══════════════════════════════════════════════════════════════

    public function testEagerLoadMultipleRelationsAtOnce(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $alice = EL_Author::create($db, ['name' => 'Alice']);
        EL_Post::create($db, ['title' => 'Post 1', 'author_id' => $alice->getKey()]);
        EL_Profile::create($db, ['bio' => 'Engineer', 'author_id' => $alice->getKey()]);

        $authors = EL_Author::query($db)->with('posts', 'profile')->get();

        $author = $authors->first();
        $this->assertTrue($author->relationLoaded('posts'));
        $this->assertTrue($author->relationLoaded('profile'));

        $this->assertCount(1, $author->posts);
        $this->assertSame('Engineer', $author->profile->bio);
    }

    public function testEagerLoadThreeRelationsAtOnce(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $alice = EL_Author::create($db, ['name' => 'Alice']);
        EL_Post::create($db, ['title' => 'Post 1', 'author_id' => $alice->getKey()]);
        EL_Profile::create($db, ['bio' => 'Engineer', 'author_id' => $alice->getKey()]);
        $tag = EL_Tag::create($db, ['label' => 'php']);
        $alice->tags()->attach($tag->getKey());

        $authors = EL_Author::query($db)->with('posts', 'profile', 'tags')->get();

        $author = $authors->first();
        $this->assertTrue($author->relationLoaded('posts'));
        $this->assertTrue($author->relationLoaded('profile'));
        $this->assertTrue($author->relationLoaded('tags'));

        $this->assertCount(1, $author->posts);
        $this->assertSame('Engineer', $author->profile->bio);
        $this->assertCount(1, $author->tags);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 8: Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testWithoutWithDoesNotPreloadRelations(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $alice = EL_Author::create($db, ['name' => 'Alice']);
        EL_Post::create($db, ['title' => 'Post 1', 'author_id' => $alice->getKey()]);

        $authors = EL_Author::query($db)->get();

        $author = $authors->first();
        $this->assertFalse($author->relationLoaded('posts'));
    }

    public function testEagerLoadingOnEmptyResultSetDoesNotFail(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        // No authors exist
        $authors = EL_Author::query($db)->with('posts', 'profile', 'tags')->get();

        $this->assertCount(0, $authors);
    }

    public function testEagerLoadingSkipsUnknownRelations(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        EL_Author::create($db, ['name' => 'Alice']);

        // 'nonExistent' is not a method on EL_Author
        $authors = EL_Author::query($db)->with('posts', 'nonExistent')->get();

        $author = $authors->first();
        // posts should be loaded, unknown should be silently skipped
        $this->assertTrue($author->relationLoaded('posts'));
        $this->assertFalse($author->relationLoaded('nonExistent'));
    }

    public function testEagerLoadedRelationIsReturnedFromCacheOnPropertyAccess(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $alice = EL_Author::create($db, ['name' => 'Alice']);
        EL_Post::create($db, ['title' => 'Post 1', 'author_id' => $alice->getKey()]);

        $authors = EL_Author::query($db)->with('posts')->get();
        $author = $authors->first();

        // First access returns eager-loaded data
        $posts1 = $author->posts;
        // Second access returns same cached data (no extra query)
        $posts2 = $author->posts;

        $this->assertSame($posts1, $posts2);
    }

    public function testEagerLoadWithWhereConstraints(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $alice = EL_Author::create($db, ['name' => 'Alice']);
        $bob = EL_Author::create($db, ['name' => 'Bob']);

        EL_Post::create($db, ['title' => 'PA1', 'author_id' => $alice->getKey()]);
        EL_Post::create($db, ['title' => 'PB1', 'author_id' => $bob->getKey()]);

        // Only query for Alice with eager loading
        $authors = EL_Author::query($db)
            ->where('name=:n', ['n' => 'Alice'])
            ->with('posts')
            ->get();

        $this->assertCount(1, $authors);
        $this->assertSame('Alice', $authors->first()->name);
        $this->assertCount(1, $authors->first()->posts);
    }

    public function testEagerLoadWithOrderBy(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $alice = EL_Author::create($db, ['name' => 'Alice']);
        $bob = EL_Author::create($db, ['name' => 'Bob']);

        EL_Post::create($db, ['title' => 'PA', 'author_id' => $alice->getKey()]);
        EL_Post::create($db, ['title' => 'PB', 'author_id' => $bob->getKey()]);

        $authors = EL_Author::query($db)->with('posts')->orderBy('name', 'DESC')->get();

        // Bob first, Alice second
        $this->assertSame('Bob', $authors[0]->name);
        $this->assertSame('Alice', $authors[1]->name);

        // Both should have eagerly loaded posts
        $this->assertTrue($authors[0]->relationLoaded('posts'));
        $this->assertTrue($authors[1]->relationLoaded('posts'));
    }

    public function testEagerLoadWithLimit(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $alice = EL_Author::create($db, ['name' => 'Alice']);
        $bob = EL_Author::create($db, ['name' => 'Bob']);

        EL_Post::create($db, ['title' => 'PA', 'author_id' => $alice->getKey()]);
        EL_Post::create($db, ['title' => 'PB', 'author_id' => $bob->getKey()]);

        $authors = EL_Author::query($db)->with('posts')->orderBy('name')->limit(1)->get();

        $this->assertCount(1, $authors);
        $this->assertSame('Alice', $authors->first()->name);
        $this->assertTrue($authors->first()->relationLoaded('posts'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 9: N+1 prevention verification
    // ═══════════════════════════════════════════════════════════════

    public function testEagerLoadingPreloadsForAllModels(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        // Create 10 authors with 2 posts each
        for ($i = 1; $i <= 10; $i++) {
            $author = EL_Author::create($db, ['name' => 'Author ' . $i]);
            EL_Post::create($db, ['title' => "Post A{$i}", 'author_id' => $author->getKey()]);
            EL_Post::create($db, ['title' => "Post B{$i}", 'author_id' => $author->getKey()]);
        }

        $authors = EL_Author::query($db)->with('posts')->get();

        $this->assertCount(10, $authors);

        // All 10 authors should have their posts preloaded
        foreach ($authors as $author) {
            $this->assertTrue($author->relationLoaded('posts'));
            $this->assertCount(2, $author->posts);
        }
    }

    public function testEagerLoadingPreloadsHasOneForAllModels(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        for ($i = 1; $i <= 5; $i++) {
            $author = EL_Author::create($db, ['name' => 'Author ' . $i]);
            EL_Profile::create($db, ['bio' => 'Bio ' . $i, 'author_id' => $author->getKey()]);
        }

        $authors = EL_Author::query($db)->with('profile')->get();

        $this->assertCount(5, $authors);
        foreach ($authors as $author) {
            $this->assertTrue($author->relationLoaded('profile'));
            $this->assertInstanceOf(EL_Profile::class, $author->profile);
        }
    }

    public function testEagerLoadingPreloadsBelongsToForAllModels(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $alice = EL_Author::create($db, ['name' => 'Alice']);
        $bob = EL_Author::create($db, ['name' => 'Bob']);

        for ($i = 1; $i <= 5; $i++) {
            $authorId = ($i % 2 === 0) ? $bob->getKey() : $alice->getKey();
            EL_Post::create($db, ['title' => "Post {$i}", 'author_id' => $authorId]);
        }

        $posts = EL_Post::query($db)->with('author')->get();

        $this->assertCount(5, $posts);
        foreach ($posts as $post) {
            $this->assertTrue($post->relationLoaded('author'));
            $this->assertInstanceOf(EL_Author::class, $post->author);
        }
    }

    public function testEagerLoadingPreloadsBelongsToManyForAllModels(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $php = EL_Tag::create($db, ['label' => 'php']);
        $js = EL_Tag::create($db, ['label' => 'js']);

        for ($i = 1; $i <= 5; $i++) {
            $author = EL_Author::create($db, ['name' => 'Author ' . $i]);
            $author->tags()->attach([$php->getKey(), $js->getKey()]);
        }

        $authors = EL_Author::query($db)->with('tags')->get();

        $this->assertCount(5, $authors);
        foreach ($authors as $author) {
            $this->assertTrue($author->relationLoaded('tags'));
            $this->assertCount(2, $author->tags);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 10: Complex scenarios
    // ═══════════════════════════════════════════════════════════════

    public function testEagerLoadAllFourRelationTypes(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $alice = EL_Author::create($db, ['name' => 'Alice']);
        EL_Post::create($db, ['title' => 'Post 1', 'author_id' => $alice->getKey()]);
        EL_Post::create($db, ['title' => 'Post 2', 'author_id' => $alice->getKey()]);
        EL_Profile::create($db, ['bio' => 'Engineer', 'author_id' => $alice->getKey()]);
        $tag = EL_Tag::create($db, ['label' => 'php']);
        $alice->tags()->attach($tag->getKey());

        $authors = EL_Author::query($db)->with('posts', 'profile', 'tags')->get();

        $author = $authors->first();

        // HasMany
        $this->assertCount(2, $author->posts);
        $titles = $author->posts->pluck('title');
        sort($titles);
        $this->assertSame(['Post 1', 'Post 2'], $titles);

        // HasOne
        $this->assertSame('Engineer', $author->profile->bio);

        // BelongsToMany
        $this->assertCount(1, $author->tags);
        $this->assertSame('php', $author->tags->first()->label);
    }

    public function testEagerLoadWithPagination(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        for ($i = 1; $i <= 5; $i++) {
            $author = EL_Author::create($db, ['name' => 'Author ' . $i]);
            EL_Post::create($db, ['title' => "Post {$i}", 'author_id' => $author->getKey()]);
        }

        // Paginate calls get() internally
        $result = EL_Author::query($db)->with('posts')->paginate(1, 3);

        $this->assertSame(5, $result['total']);
        $this->assertSame(2, $result['last_page']);
        $this->assertCount(3, $result['data']);

        foreach ($result['data'] as $author) {
            $this->assertTrue($author->relationLoaded('posts'));
        }
    }

    public function testEagerLoadPreservesWhereFiltering(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $alice = EL_Author::create($db, ['name' => 'Alice']);
        $bob = EL_Author::create($db, ['name' => 'Bob']);

        EL_Post::create($db, ['title' => 'PA1', 'author_id' => $alice->getKey()]);
        EL_Post::create($db, ['title' => 'PA2', 'author_id' => $alice->getKey()]);
        EL_Post::create($db, ['title' => 'PB1', 'author_id' => $bob->getKey()]);

        // Query only Bob
        $authors = EL_Author::query($db)
            ->where('name=:n', ['n' => 'Bob'])
            ->with('posts')
            ->get();

        $this->assertCount(1, $authors);
        $this->assertSame('Bob', $authors->first()->name);
        $this->assertCount(1, $authors->first()->posts);
        $this->assertSame('PB1', $authors->first()->posts->first()->title);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 11: setRelation override
    // ═══════════════════════════════════════════════════════════════

    public function testManualSetRelationOverridesLazyLoad(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $alice = EL_Author::create($db, ['name' => 'Alice']);
        EL_Post::create($db, ['title' => 'Real Post', 'author_id' => $alice->getKey()]);

        // Manually set an empty collection — simulating pre-loaded data
        $alice->setRelation('posts', new ModelCollection([]));

        // Even though the DB has posts, the cached (pre-set) value is returned
        $this->assertTrue($alice->relationLoaded('posts'));
        $this->assertCount(0, $alice->posts);
    }

    public function testSetRelationWithModelInstance(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $alice = EL_Author::create($db, ['name' => 'Alice']);
        $profile = EL_Profile::create($db, ['bio' => 'Custom', 'author_id' => $alice->getKey()]);

        $alice->setRelation('profile', $profile);

        $this->assertTrue($alice->relationLoaded('profile'));
        $this->assertSame('Custom', $alice->profile->bio);
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    private function createDb(): Database
    {
        static $counter = 0;
        $db = new Database('el_test_' . (++$counter));
        $db->connectWithDriver('sqlite', ['path' => ':memory:']);

        return $db;
    }

    private function createSchema(Database $db): void
    {
        $adapter = $db->getDBAdapter();

        $db->clearStatementPool();
        $adapter->exec('
            CREATE TABLE IF NOT EXISTS el_authors (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                created_at TEXT,
                updated_at TEXT
            )
        ');

        $db->clearStatementPool();
        $adapter->exec('
            CREATE TABLE IF NOT EXISTS el_posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                author_id INTEGER,
                created_at TEXT,
                updated_at TEXT
            )
        ');

        $db->clearStatementPool();
        $adapter->exec('
            CREATE TABLE IF NOT EXISTS el_profiles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                bio TEXT,
                author_id INTEGER NOT NULL,
                created_at TEXT,
                updated_at TEXT
            )
        ');

        $db->clearStatementPool();
        $adapter->exec('
            CREATE TABLE IF NOT EXISTS el_tags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                label TEXT NOT NULL
            )
        ');

        // Pivot table: el_author_el_tag (alphabetical convention)
        $db->clearStatementPool();
        $adapter->exec('
            CREATE TABLE IF NOT EXISTS el_author_el_tag (
                el_author_id INTEGER NOT NULL,
                el_tag_id INTEGER NOT NULL
            )
        ');
    }
}

// ═══════════════════════════════════════════════════════════════
// Test Model Doubles
// ═══════════════════════════════════════════════════════════════

class EL_Author extends Model
{
    protected static string $table = 'el_authors';
    protected static array $fillable = ['name'];

    public function posts(): HasMany
    {
        return $this->hasMany(EL_Post::class, 'author_id');
    }

    public function profile(): HasOne
    {
        return $this->hasOne(EL_Profile::class, 'author_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(EL_Tag::class);
    }
}

class EL_Post extends Model
{
    protected static string $table = 'el_posts';
    protected static array $fillable = ['title', 'author_id'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(EL_Author::class, 'author_id');
    }
}

class EL_Profile extends Model
{
    protected static string $table = 'el_profiles';
    protected static array $fillable = ['bio', 'author_id'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(EL_Author::class, 'author_id');
    }
}

class EL_Tag extends Model
{
    protected static string $table = 'el_tags';
    protected static array $fillable = ['label'];
    protected static bool $timestamps = false;

    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(EL_Author::class);
    }
}
