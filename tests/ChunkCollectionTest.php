<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Database;
use Razy\ORM\Model;
use Razy\ORM\ModelCollection;
use Razy\ORM\ModelQuery;

/**
 * Tests for P22: Chunking/Cursor + Enhanced Collection Methods.
 *
 * Part A: ModelQuery::chunk() and ModelQuery::cursor()
 * Part B: ModelCollection methods — reduce, sum, avg, min, max, sortBy,
 *         unique, groupBy, keyBy, flatMap, firstWhere, chunk
 */
#[CoversClass(ModelQuery::class)]
#[CoversClass(Model::class)]
#[CoversClass(ModelCollection::class)]
class ChunkCollectionTest extends TestCase
{
    protected function tearDown(): void
    {
        Model::clearBootedModels();
    }

    // ═══════════════════════════════════════════════════════════════
    // Part A — Section 1: ModelQuery::chunk()
    // ═══════════════════════════════════════════════════════════════

    public function testChunkProcessesAllRecords(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        $this->seedItems($db, 10);

        $collected = [];
        CC_Item::query($db)->chunk(3, function (ModelCollection $chunk) use (&$collected) {
            foreach ($chunk as $item) {
                $collected[] = $item->getRawAttribute('name');
            }
        });

        $this->assertCount(10, $collected);
    }

    public function testChunkCallbackReceivesCorrectSizes(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        $this->seedItems($db, 7);

        $sizes = [];
        CC_Item::query($db)->chunk(3, function (ModelCollection $chunk, int $page) use (&$sizes) {
            $sizes[$page] = $chunk->count();
        });

        // 7 items / 3 per chunk = pages of 3, 3, 1
        $this->assertSame([1 => 3, 2 => 3, 3 => 1], $sizes);
    }

    public function testChunkReturnsTrue(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        $this->seedItems($db, 5);

        $result = CC_Item::query($db)->chunk(2, function () {});

        $this->assertTrue($result);
    }

    public function testChunkReturnsFalseWhenCallbackStops(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        $this->seedItems($db, 10);

        $pagesProcessed = 0;
        $result = CC_Item::query($db)->chunk(3, function () use (&$pagesProcessed) {
            ++$pagesProcessed;
            if ($pagesProcessed >= 2) {
                return false;
            }
        });

        $this->assertFalse($result);
        $this->assertSame(2, $pagesProcessed);
    }

    public function testChunkWithNoResults(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $called = false;
        CC_Item::query($db)->chunk(5, function () use (&$called) {
            $called = true;
        });

        $this->assertFalse($called);
    }

    public function testChunkWithWhereClause(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        $this->seedItems($db, 10);

        $count = 0;
        CC_Item::query($db)
            ->where('price=:p', ['p' => 100])
            ->chunk(5, function (ModelCollection $chunk) use (&$count) {
                $count += $chunk->count();
            });

        // All 10 items have price=100
        $this->assertSame(10, $count);
    }

    // ═══════════════════════════════════════════════════════════════
    // Part A — Section 2: ModelQuery::cursor()
    // ═══════════════════════════════════════════════════════════════

    public function testCursorReturnsGenerator(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        $this->seedItems($db, 3);

        $cursor = CC_Item::query($db)->cursor();

        $this->assertInstanceOf(\Generator::class, $cursor);
    }

    public function testCursorYieldsAllModels(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        $this->seedItems($db, 5);

        $names = [];
        foreach (CC_Item::query($db)->cursor() as $item) {
            $this->assertInstanceOf(CC_Item::class, $item);
            $names[] = $item->getRawAttribute('name');
        }

        $this->assertCount(5, $names);
    }

    public function testCursorWithWhereClause(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        CC_Item::create($db, ['name' => 'A', 'price' => '50', 'category' => 'x']);
        CC_Item::create($db, ['name' => 'B', 'price' => '200', 'category' => 'y']);
        CC_Item::create($db, ['name' => 'C', 'price' => '300', 'category' => 'x']);

        $names = [];
        foreach (CC_Item::query($db)->where('category=:c', ['c' => 'x'])->cursor() as $item) {
            $names[] = $item->getRawAttribute('name');
        }

        $this->assertSame(['A', 'C'], $names);
    }

    public function testCursorWithEmptyResult(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);

        $count = 0;
        foreach (CC_Item::query($db)->cursor() as $item) {
            ++$count;
        }

        $this->assertSame(0, $count);
    }

    public function testCursorCanBreakEarly(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        $this->seedItems($db, 10);

        $count = 0;
        foreach (CC_Item::query($db)->cursor() as $item) {
            ++$count;
            if ($count >= 3) {
                break;
            }
        }

        $this->assertSame(3, $count);
    }

    // ═══════════════════════════════════════════════════════════════
    // Part B — Section 3: reduce
    // ═══════════════════════════════════════════════════════════════

    public function testReduce(): void
    {
        $collection = $this->makeCollection([10, 20, 30]);

        $sum = $collection->reduce(function ($carry, $model) {
            return $carry + $model->price;
        }, 0);

        $this->assertSame(60, $sum);
    }

    public function testReduceWithInitial(): void
    {
        $collection = $this->makeCollection([5, 5]);

        $result = $collection->reduce(fn($carry, $m) => $carry + $m->price, 100);

        $this->assertSame(110, $result);
    }

    public function testReduceOnEmpty(): void
    {
        $collection = new ModelCollection([]);

        $result = $collection->reduce(fn($carry, $m) => $carry + 1, 0);

        $this->assertSame(0, $result);
    }

    // ═══════════════════════════════════════════════════════════════
    // Part B — Section 4: sum
    // ═══════════════════════════════════════════════════════════════

    public function testSumByAttribute(): void
    {
        $collection = $this->makeCollection([10, 20, 30]);

        $this->assertSame(60, $collection->sum('price'));
    }

    public function testSumByCallback(): void
    {
        $collection = $this->makeCollection([10, 20, 30]);

        $result = $collection->sum(fn($m) => $m->price * 2);

        $this->assertSame(120, $result);
    }

    public function testSumOnEmpty(): void
    {
        $collection = new ModelCollection([]);

        $this->assertSame(0, $collection->sum('price'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Part B — Section 5: avg
    // ═══════════════════════════════════════════════════════════════

    public function testAvg(): void
    {
        $collection = $this->makeCollection([10, 20, 30]);

        $this->assertEquals(20, $collection->avg('price'));
    }

    public function testAvgOnEmpty(): void
    {
        $collection = new ModelCollection([]);

        $this->assertNull($collection->avg('price'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Part B — Section 6: min / max
    // ═══════════════════════════════════════════════════════════════

    public function testMin(): void
    {
        $collection = $this->makeCollection([30, 10, 20]);

        $this->assertSame(10, $collection->min('price'));
    }

    public function testMax(): void
    {
        $collection = $this->makeCollection([30, 10, 20]);

        $this->assertSame(30, $collection->max('price'));
    }

    public function testMinOnEmpty(): void
    {
        $this->assertNull((new ModelCollection([]))->min('price'));
    }

    public function testMaxOnEmpty(): void
    {
        $this->assertNull((new ModelCollection([]))->max('price'));
    }

    public function testMinWithCallback(): void
    {
        $collection = $this->makeCollection([30, 10, 20]);

        $this->assertSame(10, $collection->min(fn($m) => $m->price));
    }

    // ═══════════════════════════════════════════════════════════════
    // Part B — Section 7: sortBy
    // ═══════════════════════════════════════════════════════════════

    public function testSortByAttributeAsc(): void
    {
        $collection = $this->makeCollection([30, 10, 20]);

        $sorted = $collection->sortBy('price');
        $prices = $sorted->pluck('price');

        $this->assertSame([10, 20, 30], $prices);
    }

    public function testSortByAttributeDesc(): void
    {
        $collection = $this->makeCollection([10, 30, 20]);

        $sorted = $collection->sortBy('price', 'desc');
        $prices = $sorted->pluck('price');

        $this->assertSame([30, 20, 10], $prices);
    }

    public function testSortByCallback(): void
    {
        $collection = $this->makeCollection([30, 10, 20]);

        $sorted = $collection->sortBy(fn($m) => $m->price);
        $prices = $sorted->pluck('price');

        $this->assertSame([10, 20, 30], $prices);
    }

    public function testSortByReturnsNewCollection(): void
    {
        $collection = $this->makeCollection([30, 10]);

        $sorted = $collection->sortBy('price');

        // Original unchanged
        $this->assertSame([30, 10], $collection->pluck('price'));
        $this->assertSame([10, 30], $sorted->pluck('price'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Part B — Section 8: unique
    // ═══════════════════════════════════════════════════════════════

    public function testUniqueByAttribute(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        CC_Item::create($db, ['name' => 'A', 'price' => '100', 'category' => 'x']);
        CC_Item::create($db, ['name' => 'B', 'price' => '200', 'category' => 'x']);
        CC_Item::create($db, ['name' => 'C', 'price' => '300', 'category' => 'y']);

        $all = CC_Item::all($db);
        $unique = $all->unique('category');

        $this->assertCount(2, $unique);
    }

    public function testUniqueByCallback(): void
    {
        $collection = $this->makeCollection([10, 10, 20]);

        $unique = $collection->unique(fn($m) => $m->price);

        $this->assertCount(2, $unique);
    }

    // ═══════════════════════════════════════════════════════════════
    // Part B — Section 9: groupBy
    // ═══════════════════════════════════════════════════════════════

    public function testGroupByAttribute(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        CC_Item::create($db, ['name' => 'A', 'price' => '100', 'category' => 'x']);
        CC_Item::create($db, ['name' => 'B', 'price' => '200', 'category' => 'x']);
        CC_Item::create($db, ['name' => 'C', 'price' => '300', 'category' => 'y']);

        $all = CC_Item::all($db);
        $groups = $all->groupBy('category');

        $this->assertCount(2, $groups);
        $this->assertArrayHasKey('x', $groups);
        $this->assertArrayHasKey('y', $groups);
        $this->assertCount(2, $groups['x']);
        $this->assertCount(1, $groups['y']);
        $this->assertInstanceOf(ModelCollection::class, $groups['x']);
    }

    public function testGroupByCallback(): void
    {
        $collection = $this->makeCollection([10, 20, 30, 40]);

        $groups = $collection->groupBy(fn($m) => $m->price >= 25 ? 'high' : 'low');

        $this->assertCount(2, $groups);
        $this->assertCount(2, $groups['low']);
        $this->assertCount(2, $groups['high']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Part B — Section 10: keyBy
    // ═══════════════════════════════════════════════════════════════

    public function testKeyByAttribute(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        CC_Item::create($db, ['name' => 'Alpha', 'price' => '100', 'category' => 'x']);
        CC_Item::create($db, ['name' => 'Beta', 'price' => '200', 'category' => 'y']);

        $all = CC_Item::all($db);
        $keyed = $all->keyBy('name');

        $this->assertArrayHasKey('Alpha', $keyed);
        $this->assertArrayHasKey('Beta', $keyed);
        $this->assertInstanceOf(CC_Item::class, $keyed['Alpha']);
        $this->assertSame(100, $keyed['Alpha']->getRawAttribute('price'));
    }

    public function testKeyByCallback(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        CC_Item::create($db, ['name' => 'A', 'price' => '100', 'category' => 'x']);
        CC_Item::create($db, ['name' => 'B', 'price' => '200', 'category' => 'y']);

        $all = CC_Item::all($db);
        $keyed = $all->keyBy(fn($m) => 'item_' . $m->name);

        $this->assertArrayHasKey('item_A', $keyed);
        $this->assertArrayHasKey('item_B', $keyed);
    }

    // ═══════════════════════════════════════════════════════════════
    // Part B — Section 11: flatMap
    // ═══════════════════════════════════════════════════════════════

    public function testFlatMap(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        CC_Item::create($db, ['name' => 'A', 'price' => '100', 'category' => 'x']);
        CC_Item::create($db, ['name' => 'B', 'price' => '200', 'category' => 'y']);

        $all = CC_Item::all($db);
        $result = $all->flatMap(fn($m) => [$m->name, $m->name . '!']);

        $this->assertSame(['A', 'A!', 'B', 'B!'], $result);
    }

    public function testFlatMapOnEmpty(): void
    {
        $collection = new ModelCollection([]);
        $result = $collection->flatMap(fn($m) => [$m->name]);

        $this->assertSame([], $result);
    }

    // ═══════════════════════════════════════════════════════════════
    // Part B — Section 12: firstWhere
    // ═══════════════════════════════════════════════════════════════

    public function testFirstWhere(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        CC_Item::create($db, ['name' => 'A', 'price' => '100', 'category' => 'x']);
        CC_Item::create($db, ['name' => 'B', 'price' => '200', 'category' => 'y']);
        CC_Item::create($db, ['name' => 'C', 'price' => '200', 'category' => 'z']);

        $all = CC_Item::all($db);

        $found = $all->firstWhere('price', '200');
        $this->assertNotNull($found);
        $this->assertSame('B', $found->getRawAttribute('name'));
    }

    public function testFirstWhereReturnsNullWhenNotFound(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        CC_Item::create($db, ['name' => 'A', 'price' => '100', 'category' => 'x']);

        $all = CC_Item::all($db);
        $this->assertNull($all->firstWhere('price', '999'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Part B — Section 13: Collection::chunk()
    // ═══════════════════════════════════════════════════════════════

    public function testCollectionChunk(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        $this->seedItems($db, 7);

        $all = CC_Item::all($db);
        $chunks = $all->chunk(3);

        $this->assertCount(3, $chunks);
        $this->assertCount(3, $chunks[0]);
        $this->assertCount(3, $chunks[1]);
        $this->assertCount(1, $chunks[2]);
        $this->assertInstanceOf(ModelCollection::class, $chunks[0]);
    }

    public function testCollectionChunkExactDivision(): void
    {
        $db = $this->createDb();
        $this->createSchema($db);
        $this->seedItems($db, 6);

        $all = CC_Item::all($db);
        $chunks = $all->chunk(3);

        $this->assertCount(2, $chunks);
        $this->assertCount(3, $chunks[0]);
        $this->assertCount(3, $chunks[1]);
    }

    public function testCollectionChunkEmpty(): void
    {
        $collection = new ModelCollection([]);
        $chunks = $collection->chunk(5);

        $this->assertSame([], $chunks);
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    private function createDb(): Database
    {
        static $counter = 0;
        $db = new Database('cc_test_' . (++$counter));
        $db->connectWithDriver('sqlite', ['path' => ':memory:']);

        return $db;
    }

    private function createSchema(Database $db): void
    {
        $db->clearStatementPool();
        $db->getDBAdapter()->exec('
            CREATE TABLE IF NOT EXISTS cc_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                price INTEGER,
                category TEXT,
                created_at TEXT,
                updated_at TEXT
            )
        ');
    }

    private function seedItems(Database $db, int $count): void
    {
        for ($i = 1; $i <= $count; ++$i) {
            CC_Item::create($db, ['name' => 'Item' . $i, 'price' => '100', 'category' => 'cat']);
        }
    }

    /**
     * Create an in-memory collection with items having the given prices.
     */
    private function makeCollection(array $prices): ModelCollection
    {
        $items = [];
        foreach ($prices as $price) {
            $m = new CC_Item();
            $m->setRawAttribute('price', $price);
            $items[] = $m;
        }

        return new ModelCollection($items);
    }
}

// ═══════════════════════════════════════════════════════════════
// Test Model Double
// ═══════════════════════════════════════════════════════════════

class CC_Item extends Model
{
    protected static string $table = 'cc_items';
    protected static array $fillable = ['name', 'price', 'category'];
    protected static array $casts = ['price' => 'int'];
}
