<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Database;
use Razy\ORM\Model;
use Razy\ORM\ModelCollection;
use Razy\ORM\ModelQuery;
use Razy\ORM\SoftDeletes;

/**
 * Tests for P21: Model Events.
 *
 * Covers lifecycle event hooks: creating/created, updating/updated,
 * saving/saved, deleting/deleted, restoring/restored, forceDeleting/forceDeleted.
 */
#[CoversClass(ModelQuery::class)]
#[CoversClass(Model::class)]
#[CoversClass(ModelCollection::class)]
class ModelEventsTest extends TestCase
{
    protected function tearDown(): void
    {
        Model::clearBootedModels();
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 1: Creating / Created events
    // ═══════════════════════════════════════════════════════════════

    public function testCreatingEventFires(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $log = [];
        ME_Post::creating(function (ME_Post $model) use (&$log) {
            $log[] = 'creating:' . $model->getRawAttribute('title');
        });

        ME_Post::create($db, ['title' => 'Hello', 'body' => 'World']);

        $this->assertContains('creating:Hello', $log);
    }

    public function testCreatedEventFires(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $log = [];
        ME_Post::created(function (ME_Post $model) use (&$log) {
            $log[] = 'created:' . $model->getKey();
        });

        $post = ME_Post::create($db, ['title' => 'Hello', 'body' => 'World']);

        // After creation, the model should have a PK
        $this->assertContains('created:' . $post->getKey(), $log);
    }

    public function testCreatingEventCanCancelInsert(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        ME_Post::creating(function (ME_Post $model) {
            if ($model->getRawAttribute('title') === 'BLOCKED') {
                return false;
            }
        });

        $post = ME_Post::create($db, ['title' => 'BLOCKED', 'body' => 'should not save']);

        // Model was not persisted
        $this->assertFalse($post->exists());

        // No rows in DB
        $all = ME_Post::all($db);
        $this->assertCount(0, $all);
    }

    public function testCreatedEventReceivesPersistedModel(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $captured = null;
        ME_Post::created(function (ME_Post $model) use (&$captured) {
            $captured = $model;
        });

        $post = ME_Post::create($db, ['title' => 'Test', 'body' => 'Body']);

        $this->assertNotNull($captured);
        $this->assertTrue($captured->exists());
        $this->assertSame($post->getKey(), $captured->getKey());
    }

    public function testCreatingEventDoesNotFireOnUpdate(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $post = ME_Post::create($db, ['title' => 'Original', 'body' => 'Body']);

        $log = [];
        ME_Post::creating(function () use (&$log) {
            $log[] = 'creating';
        });

        $post->title = 'Updated';
        $post->save();

        $this->assertEmpty($log);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 2: Updating / Updated events
    // ═══════════════════════════════════════════════════════════════

    public function testUpdatingEventFires(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $post = ME_Post::create($db, ['title' => 'Original', 'body' => 'Body']);

        $log = [];
        ME_Post::updating(function (ME_Post $model) use (&$log) {
            $log[] = 'updating:' . $model->getRawAttribute('title');
        });

        $post->title = 'Changed';
        $post->save();

        $this->assertContains('updating:Changed', $log);
    }

    public function testUpdatedEventFires(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $post = ME_Post::create($db, ['title' => 'Original', 'body' => 'Body']);

        $log = [];
        ME_Post::updated(function (ME_Post $model) use (&$log) {
            $log[] = 'updated:' . $model->getKey();
        });

        $post->title = 'Changed';
        $post->save();

        $this->assertContains('updated:' . $post->getKey(), $log);
    }

    public function testUpdatingEventCanCancelUpdate(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $post = ME_Post::create($db, ['title' => 'Original', 'body' => 'Body']);

        ME_Post::updating(function (ME_Post $model) {
            if ($model->getRawAttribute('title') === 'BLOCKED') {
                return false;
            }
        });

        $post->title = 'BLOCKED';
        $result = $post->save();

        $this->assertFalse($result);

        // Verify DB still has original
        $reloaded = ME_Post::find($db, $post->getKey());
        $this->assertSame('Original', $reloaded->getRawAttribute('title'));
    }

    public function testUpdatingEventDoesNotFireOnInsert(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $log = [];
        ME_Post::updating(function () use (&$log) {
            $log[] = 'updating';
        });

        ME_Post::create($db, ['title' => 'New', 'body' => 'Body']);

        $this->assertEmpty($log);
    }

    public function testUpdatingDoesNotFireWhenNothingChanged(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $post = ME_Post::create($db, ['title' => 'Same', 'body' => 'Body']);

        $log = [];
        ME_Post::updating(function () use (&$log) {
            $log[] = 'updating';
        });

        // Save without changes
        $result = $post->save();
        $this->assertFalse($result);
        $this->assertEmpty($log);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 3: Saving / Saved events
    // ═══════════════════════════════════════════════════════════════

    public function testSavingEventFiresOnInsert(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $log = [];
        ME_Post::saving(function () use (&$log) {
            $log[] = 'saving';
        });

        ME_Post::create($db, ['title' => 'New', 'body' => 'Body']);

        $this->assertContains('saving', $log);
    }

    public function testSavingEventFiresOnUpdate(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $post = ME_Post::create($db, ['title' => 'Original', 'body' => 'Body']);

        $log = [];
        ME_Post::saving(function () use (&$log) {
            $log[] = 'saving';
        });

        $post->title = 'Updated';
        $post->save();

        $this->assertContains('saving', $log);
    }

    public function testSavedEventFiresOnInsert(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $log = [];
        ME_Post::saved(function () use (&$log) {
            $log[] = 'saved';
        });

        ME_Post::create($db, ['title' => 'New', 'body' => 'Body']);

        $this->assertContains('saved', $log);
    }

    public function testSavedEventFiresOnUpdate(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $post = ME_Post::create($db, ['title' => 'Original', 'body' => 'Body']);

        $log = [];
        ME_Post::saved(function () use (&$log) {
            $log[] = 'saved';
        });

        $post->title = 'Updated';
        $post->save();

        $this->assertContains('saved', $log);
    }

    public function testSavingEventCanCancelInsert(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        ME_Post::saving(function () {
            return false;
        });

        $post = ME_Post::create($db, ['title' => 'Blocked', 'body' => 'Body']);

        $this->assertFalse($post->exists());
    }

    public function testSavingEventCanCancelUpdate(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $post = ME_Post::create($db, ['title' => 'Original', 'body' => 'Body']);

        ME_Post::saving(function () {
            return false;
        });

        $post->title = 'Updated';
        $result = $post->save();

        $this->assertFalse($result);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 4: Deleting / Deleted events
    // ═══════════════════════════════════════════════════════════════

    public function testDeletingEventFires(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $post = ME_Post::create($db, ['title' => 'ToDelete', 'body' => 'Body']);

        $log = [];
        ME_Post::deleting(function (ME_Post $model) use (&$log) {
            $log[] = 'deleting:' . $model->getKey();
        });

        $post->delete();

        $this->assertContains('deleting:' . $post->getKey(), $log);
    }

    public function testDeletedEventFires(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $post = ME_Post::create($db, ['title' => 'ToDelete', 'body' => 'Body']);
        $pk = $post->getKey();

        $log = [];
        ME_Post::deleted(function (ME_Post $model) use (&$log) {
            $log[] = 'deleted';
        });

        $post->delete();

        $this->assertContains('deleted', $log);
    }

    public function testDeletingEventCanCancelDelete(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $post = ME_Post::create($db, ['title' => 'Protected', 'body' => 'Body']);

        ME_Post::deleting(function () {
            return false;
        });

        $result = $post->delete();
        $this->assertFalse($result);

        // Still exists in DB
        $this->assertTrue($post->exists());
        $this->assertNotNull(ME_Post::find($db, $post->getKey()));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 5: Event ordering
    // ═══════════════════════════════════════════════════════════════

    public function testInsertEventOrder(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $log = [];
        ME_Post::saving(function () use (&$log) { $log[] = 'saving'; });
        ME_Post::creating(function () use (&$log) { $log[] = 'creating'; });
        ME_Post::created(function () use (&$log) { $log[] = 'created'; });
        ME_Post::saved(function () use (&$log) { $log[] = 'saved'; });

        ME_Post::create($db, ['title' => 'Test', 'body' => 'Body']);

        $this->assertSame(['saving', 'creating', 'created', 'saved'], $log);
    }

    public function testUpdateEventOrder(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $post = ME_Post::create($db, ['title' => 'Original', 'body' => 'Body']);

        $log = [];
        ME_Post::saving(function () use (&$log) { $log[] = 'saving'; });
        ME_Post::updating(function () use (&$log) { $log[] = 'updating'; });
        ME_Post::updated(function () use (&$log) { $log[] = 'updated'; });
        ME_Post::saved(function () use (&$log) { $log[] = 'saved'; });

        $post->title = 'Modified';
        $post->save();

        $this->assertSame(['saving', 'updating', 'updated', 'saved'], $log);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 6: Multiple listeners
    // ═══════════════════════════════════════════════════════════════

    public function testMultipleListenersOnSameEvent(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $log = [];
        ME_Post::creating(function () use (&$log) { $log[] = 'listener1'; });
        ME_Post::creating(function () use (&$log) { $log[] = 'listener2'; });
        ME_Post::creating(function () use (&$log) { $log[] = 'listener3'; });

        ME_Post::create($db, ['title' => 'Test', 'body' => 'Body']);

        $this->assertSame(['listener1', 'listener2', 'listener3'], $log);
    }

    public function testFirstListenerCancelsStopsSubsequent(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $log = [];
        ME_Post::creating(function () use (&$log) {
            $log[] = 'first';
            return false;
        });
        ME_Post::creating(function () use (&$log) {
            $log[] = 'second'; // should not run
        });

        $post = ME_Post::create($db, ['title' => 'Test', 'body' => 'Body']);

        $this->assertSame(['first'], $log);
        $this->assertFalse($post->exists());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 7: SoftDeletes events
    // ═══════════════════════════════════════════════════════════════

    public function testSoftDeleteFiresDeletingEvent(): void
    {
        $db = $this->createDb();
        $this->createSoftDeleteSchema($db);

        $article = ME_Article::create($db, ['title' => 'Soft', 'body' => 'Body']);

        $log = [];
        ME_Article::deleting(function () use (&$log) { $log[] = 'deleting'; });
        ME_Article::deleted(function () use (&$log) { $log[] = 'deleted'; });

        $article->delete();

        $this->assertSame(['deleting', 'deleted'], $log);
        $this->assertTrue($article->trashed());
    }

    public function testSoftDeleteDeletingCanCancel(): void
    {
        $db = $this->createDb();
        $this->createSoftDeleteSchema($db);

        $article = ME_Article::create($db, ['title' => 'Protected', 'body' => 'Body']);

        ME_Article::deleting(function () {
            return false;
        });

        $result = $article->delete();

        $this->assertFalse($result);
        $this->assertFalse($article->trashed());
    }

    public function testForceDeleteFiresForceEvents(): void
    {
        $db = $this->createDb();
        $this->createSoftDeleteSchema($db);

        $article = ME_Article::create($db, ['title' => 'ToForce', 'body' => 'Body']);

        $log = [];
        ME_Article::registerPublicEvent('forceDeleting', function () use (&$log) { $log[] = 'forceDeleting'; });
        ME_Article::registerPublicEvent('forceDeleted', function () use (&$log) { $log[] = 'forceDeleted'; });

        $article->forceDelete();

        $this->assertSame(['forceDeleting', 'forceDeleted'], $log);
        $this->assertFalse($article->exists());
    }

    public function testForceDeleteForceDeletingCanCancel(): void
    {
        $db = $this->createDb();
        $this->createSoftDeleteSchema($db);

        $article = ME_Article::create($db, ['title' => 'Protected', 'body' => 'Body']);

        ME_Article::registerPublicEvent('forceDeleting', function () {
            return false;
        });

        $result = $article->forceDelete();

        $this->assertFalse($result);
        $this->assertTrue($article->exists());
    }

    public function testRestoreFiresRestoringRestoredEvents(): void
    {
        $db = $this->createDb();
        $this->createSoftDeleteSchema($db);

        $article = ME_Article::create($db, ['title' => 'Restorable', 'body' => 'Body']);
        $article->delete();

        $log = [];
        ME_Article::restoring(function () use (&$log) { $log[] = 'restoring'; });
        ME_Article::restored(function () use (&$log) { $log[] = 'restored'; });

        $article->restore();

        $this->assertSame(['restoring', 'restored'], $log);
        $this->assertFalse($article->trashed());
    }

    public function testRestoringCanCancelRestore(): void
    {
        $db = $this->createDb();
        $this->createSoftDeleteSchema($db);

        $article = ME_Article::create($db, ['title' => 'Stay Deleted', 'body' => 'Body']);
        $article->delete();

        ME_Article::restoring(function () {
            return false;
        });

        $result = $article->restore();

        $this->assertFalse($result);
        $this->assertTrue($article->trashed());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 8: Event isolation between models
    // ═══════════════════════════════════════════════════════════════

    public function testEventsAreIsolatedBetweenModelClasses(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        $this->createCommentSchema($db);

        $postLog = [];
        $commentLog = [];

        ME_Post::creating(function () use (&$postLog) { $postLog[] = 'post-creating'; });
        ME_Comment::creating(function () use (&$commentLog) { $commentLog[] = 'comment-creating'; });

        ME_Post::create($db, ['title' => 'Post', 'body' => 'Body']);

        $this->assertSame(['post-creating'], $postLog);
        $this->assertEmpty($commentLog);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 9: clearBootedModels clears events
    // ═══════════════════════════════════════════════════════════════

    public function testClearBootedModelsClearsEvents(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $log = [];
        ME_Post::creating(function () use (&$log) { $log[] = 'creating'; });

        Model::clearBootedModels();

        ME_Post::create($db, ['title' => 'Test', 'body' => 'Body']);

        $this->assertEmpty($log);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 10: Events via save() method
    // ═══════════════════════════════════════════════════════════════

    public function testSaveOnNewModelFiresCreateEvents(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $log = [];
        ME_Post::saving(function () use (&$log) { $log[] = 'saving'; });
        ME_Post::creating(function () use (&$log) { $log[] = 'creating'; });
        ME_Post::created(function () use (&$log) { $log[] = 'created'; });
        ME_Post::saved(function () use (&$log) { $log[] = 'saved'; });

        $post = new ME_Post(['title' => 'Manual', 'body' => 'Body']);
        $post->setDatabase($db);
        $post->save();

        $this->assertSame(['saving', 'creating', 'created', 'saved'], $log);
        $this->assertTrue($post->exists());
    }

    public function testSaveOnExistingModelFiresUpdateEvents(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $post = ME_Post::create($db, ['title' => 'Original', 'body' => 'Body']);

        $log = [];
        ME_Post::saving(function () use (&$log) { $log[] = 'saving'; });
        ME_Post::updating(function () use (&$log) { $log[] = 'updating'; });
        ME_Post::updated(function () use (&$log) { $log[] = 'updated'; });
        ME_Post::saved(function () use (&$log) { $log[] = 'saved'; });

        $post->title = 'Changed';
        $post->save();

        $this->assertSame(['saving', 'updating', 'updated', 'saved'], $log);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 11: Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testDeleteOnNonExistingModelDoesNotFireEvents(): void
    {
        $log = [];
        ME_Post::deleting(function () use (&$log) { $log[] = 'deleting'; });

        $post = new ME_Post(['title' => 'Never Saved']);
        $post->delete();

        $this->assertEmpty($log);
    }

    public function testListenerCanInspectModelState(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $capturedDirty = null;
        ME_Post::updating(function (ME_Post $model) use (&$capturedDirty) {
            $capturedDirty = $model->getDirty();
        });

        $post = ME_Post::create($db, ['title' => 'Original', 'body' => 'Body']);
        $post->title = 'Changed';
        $post->save();

        $this->assertArrayHasKey('title', $capturedDirty);
        $this->assertSame('Changed', $capturedDirty['title']);
    }

    public function testListenerReturningNullDoesNotCancel(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        ME_Post::creating(function () {
            return null; // should NOT cancel
        });

        $post = ME_Post::create($db, ['title' => 'Test', 'body' => 'Body']);

        $this->assertTrue($post->exists());
    }

    public function testListenerReturningTrueDoesNotCancel(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        ME_Post::creating(function () {
            return true;
        });

        $post = ME_Post::create($db, ['title' => 'Test', 'body' => 'Body']);

        $this->assertTrue($post->exists());
    }

    public function testSavedDoesNotFireWhenNoDirtyOnUpdate(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $post = ME_Post::create($db, ['title' => 'Same', 'body' => 'Body']);

        $log = [];
        ME_Post::saved(function () use (&$log) { $log[] = 'saved'; });

        // No changes — save() returns false, 'saved' should not fire
        $post->save();

        $this->assertEmpty($log);
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    private function createDb(): Database
    {
        static $counter = 0;
        $db = new Database('me_test_' . (++$counter));
        $db->connectWithDriver('sqlite', ['path' => ':memory:']);

        return $db;
    }

    private function createSchema(Database $db): void
    {
        $db->clearStatementPool();
        $db->getDBAdapter()->exec('
            CREATE TABLE IF NOT EXISTS me_posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT,
                body TEXT,
                created_at TEXT,
                updated_at TEXT
            )
        ');
    }

    private function createCommentSchema(Database $db): void
    {
        $db->clearStatementPool();
        $db->getDBAdapter()->exec('
            CREATE TABLE IF NOT EXISTS me_comments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                content TEXT,
                created_at TEXT,
                updated_at TEXT
            )
        ');
    }

    private function createSoftDeleteSchema(Database $db): void
    {
        $db->clearStatementPool();
        $db->getDBAdapter()->exec('
            CREATE TABLE IF NOT EXISTS me_articles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT,
                body TEXT,
                deleted_at TEXT,
                created_at TEXT,
                updated_at TEXT
            )
        ');
    }
}

// ═══════════════════════════════════════════════════════════════
// Test Model Doubles
// ═══════════════════════════════════════════════════════════════

class ME_Post extends Model
{
    protected static string $table = 'me_posts';
    protected static array $fillable = ['title', 'body'];
}

class ME_Comment extends Model
{
    protected static string $table = 'me_comments';
    protected static array $fillable = ['content'];
}

class ME_Article extends Model
{
    use SoftDeletes;

    protected static string $table = 'me_articles';
    protected static array $fillable = ['title', 'body'];

    /**
     * Public wrapper for registerModelEvent (for testing forceDeleting/forceDeleted).
     */
    public static function registerPublicEvent(string $event, \Closure $callback): void
    {
        static::registerModelEvent($event, $callback);
    }
}
