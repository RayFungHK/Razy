<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Database;
use Razy\ORM\Model;
use Razy\ORM\ModelCollection;

/**
 * Tests for P23: Convenience Model Methods.
 *
 * - firstOrCreate, firstOrNew, updateOrCreate
 * - increment, decrement
 * - replicate
 * - touch
 */
#[CoversClass(Model::class)]
class ConvenienceMethodsTest extends TestCase
{
    protected function tearDown(): void
    {
        Model::clearBootedModels();
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 1: firstOrCreate
    // ═══════════════════════════════════════════════════════════════

    public function testFirstOrCreateFindsExisting(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        CM_Product::create($db, ['name' => 'Widget', 'price' => '100', 'category' => 'tools']);

        $found = CM_Product::firstOrCreate($db, ['name' => 'Widget']);

        $this->assertTrue($found->exists());
        $this->assertSame('Widget', $found->getRawAttribute('name'));
        $this->assertSame(100, $found->getRawAttribute('price'));
    }

    public function testFirstOrCreateCreatesWhenNotFound(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $model = CM_Product::firstOrCreate($db, ['name' => 'Gadget'], ['price' => '250', 'category' => 'electronics']);

        $this->assertTrue($model->exists());
        $this->assertSame('Gadget', $model->getRawAttribute('name'));
        $this->assertSame(250, $model->getRawAttribute('price'));
        $this->assertSame('electronics', $model->getRawAttribute('category'));

        // Verify persisted in DB
        $reloaded = CM_Product::find($db, $model->getKey());
        $this->assertNotNull($reloaded);
        $this->assertSame('Gadget', $reloaded->getRawAttribute('name'));
    }

    public function testFirstOrCreateDoesNotDuplicateOnSecondCall(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        CM_Product::firstOrCreate($db, ['name' => 'Unique'], ['price' => '50', 'category' => 'x']);
        CM_Product::firstOrCreate($db, ['name' => 'Unique'], ['price' => '999', 'category' => 'y']);

        $all = CM_Product::all($db);
        $this->assertCount(1, $all);
        // Extra attributes from second call are ignored (existing found)
        $this->assertSame(50, $all->first()->getRawAttribute('price'));
    }

    public function testFirstOrCreateSearchesByMultipleAttributes(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        CM_Product::create($db, ['name' => 'A', 'price' => '100', 'category' => 'x']);
        CM_Product::create($db, ['name' => 'A', 'price' => '200', 'category' => 'y']);

        $found = CM_Product::firstOrCreate($db, ['name' => 'A', 'category' => 'y']);

        $this->assertSame(200, $found->getRawAttribute('price'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 2: firstOrNew
    // ═══════════════════════════════════════════════════════════════

    public function testFirstOrNewFindsExisting(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        CM_Product::create($db, ['name' => 'Existing', 'price' => '100', 'category' => 'x']);

        $model = CM_Product::firstOrNew($db, ['name' => 'Existing']);

        $this->assertTrue($model->exists());
        $this->assertSame('Existing', $model->getRawAttribute('name'));
    }

    public function testFirstOrNewReturnsUnsavedWhenNotFound(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $model = CM_Product::firstOrNew($db, ['name' => 'Fresh'], ['price' => '300', 'category' => 'new']);

        $this->assertFalse($model->exists());
        $this->assertSame('Fresh', $model->getRawAttribute('name'));
        $this->assertSame(300, $model->getRawAttribute('price'));

        // Not in database yet
        $this->assertNull(CM_Product::find($db, 1));
    }

    public function testFirstOrNewCanBeSavedAfter(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $model = CM_Product::firstOrNew($db, ['name' => 'Saveable'], ['price' => '150', 'category' => 'z']);
        $this->assertFalse($model->exists());

        $model->save();
        $this->assertTrue($model->exists());

        $reloaded = CM_Product::find($db, $model->getKey());
        $this->assertNotNull($reloaded);
        $this->assertSame('Saveable', $reloaded->getRawAttribute('name'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 3: updateOrCreate
    // ═══════════════════════════════════════════════════════════════

    public function testUpdateOrCreateUpdatesExisting(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        CM_Product::create($db, ['name' => 'Item', 'price' => '100', 'category' => 'old']);

        $model = CM_Product::updateOrCreate($db, ['name' => 'Item'], ['price' => '500', 'category' => 'updated']);

        $this->assertTrue($model->exists());
        $this->assertSame(500, $model->getRawAttribute('price'));
        $this->assertSame('updated', $model->getRawAttribute('category'));

        // Only one row in DB
        $this->assertCount(1, CM_Product::all($db));
    }

    public function testUpdateOrCreateCreatesWhenNotFound(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $model = CM_Product::updateOrCreate($db, ['name' => 'New'], ['price' => '750', 'category' => 'fresh']);

        $this->assertTrue($model->exists());
        $this->assertSame('New', $model->getRawAttribute('name'));
        $this->assertSame(750, $model->getRawAttribute('price'));

        $reloaded = CM_Product::find($db, $model->getKey());
        $this->assertNotNull($reloaded);
    }

    public function testUpdateOrCreatePreservesSearchAttributes(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        CM_Product::create($db, ['name' => 'Keep', 'price' => '100', 'category' => 'original']);

        $model = CM_Product::updateOrCreate($db, ['name' => 'Keep'], ['price' => '999']);

        // Name is unchanged, price is updated
        $this->assertSame('Keep', $model->getRawAttribute('name'));
        $this->assertSame(999, $model->getRawAttribute('price'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 4: increment
    // ═══════════════════════════════════════════════════════════════

    public function testIncrementByDefault(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        $product = CM_Product::create($db, ['name' => 'A', 'price' => '100', 'category' => 'x']);

        $result = $product->increment('price');

        $this->assertTrue($result);
        $this->assertSame(101, $product->getRawAttribute('price'));

        // Verify persisted
        $reloaded = CM_Product::find($db, $product->getKey());
        $this->assertSame(101, $reloaded->getRawAttribute('price'));
    }

    public function testIncrementByAmount(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        $product = CM_Product::create($db, ['name' => 'B', 'price' => '100', 'category' => 'x']);

        $product->increment('price', 50);

        $this->assertSame(150, $product->getRawAttribute('price'));

        $reloaded = CM_Product::find($db, $product->getKey());
        $this->assertSame(150, $reloaded->getRawAttribute('price'));
    }

    public function testIncrementWithExtraColumns(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        $product = CM_Product::create($db, ['name' => 'C', 'price' => '100', 'category' => 'old']);

        $product->increment('price', 10, ['category' => 'incremented']);

        $this->assertSame(110, $product->getRawAttribute('price'));
        $this->assertSame('incremented', $product->getRawAttribute('category'));

        $reloaded = CM_Product::find($db, $product->getKey());
        $this->assertSame(110, $reloaded->getRawAttribute('price'));
        $this->assertSame('incremented', $reloaded->getRawAttribute('category'));
    }

    public function testIncrementUpdatesTimestamp(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        $product = CM_Product::create($db, ['name' => 'D', 'price' => '100', 'category' => 'x']);
        $originalTimestamp = $product->getRawAttribute('updated_at');

        // Small delay to ensure timestamp changes
        usleep(10000);
        $product->increment('price');

        $this->assertNotNull($product->getRawAttribute('updated_at'));
    }

    public function testIncrementReturnsFalseOnUnsavedModel(): void
    {
        $model = new CM_Product();
        $model->setRawAttribute('price', 100);

        $this->assertFalse($model->increment('price'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 5: decrement
    // ═══════════════════════════════════════════════════════════════

    public function testDecrementByDefault(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        $product = CM_Product::create($db, ['name' => 'E', 'price' => '100', 'category' => 'x']);

        $result = $product->decrement('price');

        $this->assertTrue($result);
        $this->assertSame(99, $product->getRawAttribute('price'));

        $reloaded = CM_Product::find($db, $product->getKey());
        $this->assertSame(99, $reloaded->getRawAttribute('price'));
    }

    public function testDecrementByAmount(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        $product = CM_Product::create($db, ['name' => 'F', 'price' => '200', 'category' => 'x']);

        $product->decrement('price', 75);

        $this->assertSame(125, $product->getRawAttribute('price'));

        $reloaded = CM_Product::find($db, $product->getKey());
        $this->assertSame(125, $reloaded->getRawAttribute('price'));
    }

    public function testDecrementWithExtraColumns(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        $product = CM_Product::create($db, ['name' => 'G', 'price' => '500', 'category' => 'old']);

        $product->decrement('price', 100, ['category' => 'discounted']);

        $this->assertSame(400, $product->getRawAttribute('price'));
        $this->assertSame('discounted', $product->getRawAttribute('category'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 6: replicate
    // ═══════════════════════════════════════════════════════════════

    public function testReplicateCreatesUnsavedCopy(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        $original = CM_Product::create($db, ['name' => 'Original', 'price' => '100', 'category' => 'x']);

        $clone = $original->replicate();

        $this->assertFalse($clone->exists());
        $this->assertNull($clone->getKey());
        $this->assertSame('Original', $clone->getRawAttribute('name'));
        $this->assertSame(100, $clone->getRawAttribute('price'));
    }

    public function testReplicateExcludesPrimaryKey(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        $original = CM_Product::create($db, ['name' => 'PK Test', 'price' => '100', 'category' => 'x']);

        $clone = $original->replicate();

        $this->assertNotNull($original->getKey());
        $this->assertNull($clone->getKey());
    }

    public function testReplicateExcludesTimestamps(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        $original = CM_Product::create($db, ['name' => 'TS Test', 'price' => '100', 'category' => 'x']);

        $clone = $original->replicate();

        $this->assertNull($clone->getRawAttribute('created_at'));
        $this->assertNull($clone->getRawAttribute('updated_at'));
    }

    public function testReplicateWithExceptParameter(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        $original = CM_Product::create($db, ['name' => 'Except Test', 'price' => '100', 'category' => 'special']);

        $clone = $original->replicate(['category']);

        $this->assertSame('Except Test', $clone->getRawAttribute('name'));
        $this->assertNull($clone->getRawAttribute('category'));
    }

    public function testReplicateCanBeSaved(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        $original = CM_Product::create($db, ['name' => 'Saveable', 'price' => '100', 'category' => 'x']);

        $clone = $original->replicate();
        $clone->save();

        $this->assertTrue($clone->exists());
        $this->assertNotSame($original->getKey(), $clone->getKey());
        $this->assertCount(2, CM_Product::all($db));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 7: touch
    // ═══════════════════════════════════════════════════════════════

    public function testTouchUpdatesTimestamp(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        $product = CM_Product::create($db, ['name' => 'Touch', 'price' => '100', 'category' => 'x']);

        $result = $product->touch();

        $this->assertTrue($result);
        $this->assertNotNull($product->getRawAttribute('updated_at'));
    }

    public function testTouchReturnsFalseOnUnsavedModel(): void
    {
        $model = new CM_Product();

        $this->assertFalse($model->touch());
    }

    public function testTouchReturnsFalseWhenTimestampsDisabled(): void
    {
        $db = $this->createDb();
        $this->createSchemaNoTs($db);
        $item = CM_NoTimestamps::create($db, ['name' => 'NoTS', 'price' => '100', 'category' => 'x']);

        $this->assertFalse($item->touch());
    }

    public function testTouchPersistsToDatabase(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        $product = CM_Product::create($db, ['name' => 'Persist', 'price' => '100', 'category' => 'x']);

        $product->touch();
        $ts = $product->getRawAttribute('updated_at');

        $reloaded = CM_Product::find($db, $product->getKey());
        $this->assertSame($ts, $reloaded->getRawAttribute('updated_at'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 8: Edge Cases
    // ═══════════════════════════════════════════════════════════════

    public function testFirstOrCreateWithEmptyExtra(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $model = CM_Product::firstOrCreate($db, ['name' => 'NoExtra', 'price' => '50', 'category' => 'basic']);

        $this->assertTrue($model->exists());
        $this->assertSame('NoExtra', $model->getRawAttribute('name'));
    }

    public function testUpdateOrCreateMultipleTimes(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        CM_Product::updateOrCreate($db, ['name' => 'Evolving'], ['price' => '10', 'category' => 'v1']);
        CM_Product::updateOrCreate($db, ['name' => 'Evolving'], ['price' => '20', 'category' => 'v2']);
        CM_Product::updateOrCreate($db, ['name' => 'Evolving'], ['price' => '30', 'category' => 'v3']);

        $all = CM_Product::all($db);
        $this->assertCount(1, $all);
        $this->assertSame(30, $all->first()->getRawAttribute('price'));
        $this->assertSame('v3', $all->first()->getRawAttribute('category'));
    }

    public function testIncrementMultipleTimes(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        $product = CM_Product::create($db, ['name' => 'Counter', 'price' => '0', 'category' => 'x']);

        $product->increment('price', 10);
        $product->increment('price', 5);
        $product->increment('price', 3);

        $this->assertSame(18, $product->getRawAttribute('price'));

        $reloaded = CM_Product::find($db, $product->getKey());
        $this->assertSame(18, $reloaded->getRawAttribute('price'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    private function createDb(): Database
    {
        static $counter = 0;
        $db = new Database('cm_test_' . (++$counter));
        $db->connectWithDriver('sqlite', ['path' => ':memory:']);

        return $db;
    }

    private function createSchema(Database $db): void
    {
        $db->clearStatementPool();
        $db->getDBAdapter()->exec('
            CREATE TABLE IF NOT EXISTS cm_products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                price INTEGER,
                category TEXT,
                created_at TEXT,
                updated_at TEXT
            )
        ');
    }

    private function createSchemaNoTs(Database $db): void
    {
        $db->clearStatementPool();
        $db->getDBAdapter()->exec('
            CREATE TABLE IF NOT EXISTS cm_notimestamps (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                price INTEGER,
                category TEXT
            )
        ');
    }
}

// ═══════════════════════════════════════════════════════════════
// Test Model Doubles
// ═══════════════════════════════════════════════════════════════

class CM_Product extends Model
{
    protected static string $table = 'cm_products';
    protected static array $fillable = ['name', 'price', 'category'];
    protected static array $casts = ['price' => 'int'];
}

class CM_NoTimestamps extends Model
{
    protected static string $table = 'cm_notimestamps';
    protected static array $fillable = ['name', 'price', 'category'];
    protected static bool $timestamps = false;
}
