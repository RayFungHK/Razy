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
 * Tests for P24: Query Builder Enhancements.
 *
 * - whereIn / whereNotIn
 * - whereBetween / whereNotBetween
 * - orWhere
 * - whereNull / whereNotNull (already existed but need integration tests)
 */
#[CoversClass(ModelQuery::class)]
#[CoversClass(Model::class)]
class QueryBuilderEnhancedTest extends TestCase
{
    protected function tearDown(): void
    {
        Model::clearBootedModels();
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 1: whereIn
    // ═══════════════════════════════════════════════════════════════

    public function testWhereInMatchesMultipleValues(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        $results = QE_Item::query($db)->whereIn('category', ['electronics', 'books'])->get();

        $this->assertCount(3, $results);
        $names = $results->pluck('name');
        $this->assertContains('Laptop', $names);
        $this->assertContains('Phone', $names);
        $this->assertContains('Novel', $names);
    }

    public function testWhereInSingleValue(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        $results = QE_Item::query($db)->whereIn('category', ['food'])->get();

        $this->assertCount(1, $results);
        $this->assertSame('Apple', $results->first()->getRawAttribute('name'));
    }

    public function testWhereInNoMatches(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        $results = QE_Item::query($db)->whereIn('category', ['nonexistent'])->get();

        $this->assertCount(0, $results);
    }

    public function testWhereInCombinedWithWhere(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        $results = QE_Item::query($db)
            ->whereIn('category', ['electronics', 'books'])
            ->where('price>=:min', ['min' => 800])
            ->get();

        $this->assertCount(1, $results);
        $this->assertSame('Laptop', $results->first()->getRawAttribute('name'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 2: whereNotIn
    // ═══════════════════════════════════════════════════════════════

    public function testWhereNotInExcludesValues(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        $results = QE_Item::query($db)->whereNotIn('category', ['electronics'])->get();

        $names = $results->pluck('name');
        $this->assertNotContains('Laptop', $names);
        $this->assertNotContains('Phone', $names);
        $this->assertContains('Novel', $names);
        $this->assertContains('Apple', $names);
        $this->assertContains('Desk', $names);
    }

    public function testWhereNotInAllExcluded(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        $results = QE_Item::query($db)
            ->whereNotIn('category', ['electronics', 'books', 'food', 'furniture'])
            ->get();

        $this->assertCount(0, $results);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 3: whereBetween
    // ═══════════════════════════════════════════════════════════════

    public function testWhereBetweenInclusive(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        // Prices: 1000, 500, 15, 2, 200
        $results = QE_Item::query($db)->whereBetween('price', 10, 500)->get();

        $names = $results->pluck('name');
        $this->assertContains('Phone', $names);   // 500
        $this->assertContains('Novel', $names);    // 15
        $this->assertContains('Desk', $names);     // 200
        $this->assertNotContains('Laptop', $names); // 1000 > 500
        $this->assertNotContains('Apple', $names);  // 2 < 10
    }

    public function testWhereBetweenNoMatches(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        $results = QE_Item::query($db)->whereBetween('price', 5000, 9999)->get();

        $this->assertCount(0, $results);
    }

    public function testWhereBetweenCombinedWithWhere(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        $results = QE_Item::query($db)
            ->whereBetween('price', 10, 1000)
            ->where('category=:cat', ['cat' => 'electronics'])
            ->get();

        $names = $results->pluck('name');
        $this->assertContains('Laptop', $names);
        $this->assertContains('Phone', $names);
        $this->assertCount(2, $results);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 4: whereNotBetween
    // ═══════════════════════════════════════════════════════════════

    public function testWhereNotBetweenExcludesRange(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        // Exclude 10-500. Remaining: Laptop(1000), Apple(2)
        $results = QE_Item::query($db)->whereNotBetween('price', 10, 500)->get();

        $names = $results->pluck('name');
        $this->assertContains('Laptop', $names);
        $this->assertContains('Apple', $names);
        $this->assertNotContains('Phone', $names);
        $this->assertNotContains('Novel', $names);
        $this->assertNotContains('Desk', $names);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 5: orWhere
    // ═══════════════════════════════════════════════════════════════

    public function testOrWhereBasic(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        // category = 'electronics' OR category = 'food'
        $results = QE_Item::query($db)
            ->where('category=:c1', ['c1' => 'electronics'])
            ->orWhere('category=:c2', ['c2' => 'food'])
            ->get();

        $names = $results->pluck('name');
        $this->assertContains('Laptop', $names);
        $this->assertContains('Phone', $names);
        $this->assertContains('Apple', $names);
        $this->assertCount(3, $results);
    }

    public function testOrWhereWithSingleCondition(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        // Just orWhere alone (acts as a simple WHERE)
        $results = QE_Item::query($db)
            ->orWhere('category=:c', ['c' => 'books'])
            ->get();

        $this->assertCount(1, $results);
        $this->assertSame('Novel', $results->first()->getRawAttribute('name'));
    }

    public function testOrWhereMultipleConditions(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        // electronics OR books OR food → everything except furniture(Desk)
        $results = QE_Item::query($db)
            ->where('category=:c1', ['c1' => 'electronics'])
            ->orWhere('category=:c2', ['c2' => 'books'])
            ->orWhere('category=:c3', ['c3' => 'food'])
            ->get();

        $this->assertCount(4, $results);
        $names = $results->pluck('name');
        $this->assertNotContains('Desk', $names);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 6: whereNull / whereNotNull integration
    // ═══════════════════════════════════════════════════════════════

    public function testWhereNullFindsNullRows(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        // Add an item with NULL category
        QE_Item::create($db, ['name' => 'Mystery', 'price' => '0']);

        $results = QE_Item::query($db)->whereNull('category')->get();

        $this->assertCount(1, $results);
        $this->assertSame('Mystery', $results->first()->getRawAttribute('name'));
    }

    public function testWhereNotNullExcludesNullRows(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        QE_Item::create($db, ['name' => 'Mystery', 'price' => '0']);

        $results = QE_Item::query($db)->whereNotNull('category')->get();

        // 5 seeded items have non-null category
        $this->assertCount(5, $results);
        $names = $results->pluck('name');
        $this->assertNotContains('Mystery', $names);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 7: Chaining combinations
    // ═══════════════════════════════════════════════════════════════

    public function testWhereInWithOrderBy(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        $results = QE_Item::query($db)
            ->whereIn('category', ['electronics', 'furniture'])
            ->orderBy('price', 'ASC')
            ->get();

        $prices = $results->pluck('price');
        $this->assertSame([200, 500, 1000], $prices);
    }

    public function testWhereInWithLimit(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        $results = QE_Item::query($db)
            ->whereIn('category', ['electronics', 'books', 'food'])
            ->limit(2)
            ->get();

        $this->assertCount(2, $results);
    }

    public function testWhereBetweenWithOrderByDesc(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        $results = QE_Item::query($db)
            ->whereBetween('price', 1, 500)
            ->orderBy('price', 'DESC')
            ->get();

        $prices = $results->pluck('price');
        $this->assertSame([500, 200, 15, 2], $prices);
    }

    public function testMultipleWhereMethodsCombined(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        // whereIn + whereBetween → electronics/books with price 10-600
        $results = QE_Item::query($db)
            ->whereIn('category', ['electronics', 'books'])
            ->whereBetween('price', 10, 600)
            ->get();

        $names = $results->pluck('name');
        $this->assertContains('Phone', $names);  // electronics, 500
        $this->assertContains('Novel', $names);   // books, 15
        $this->assertNotContains('Laptop', $names); // electronics, 1000 > 600
        $this->assertCount(2, $results);
    }

    public function testWhereInWithCount(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        $count = QE_Item::query($db)->whereIn('category', ['electronics'])->count();

        $this->assertSame(2, $count);
    }

    public function testWhereInWithFirst(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        $item = QE_Item::query($db)
            ->whereIn('category', ['food'])
            ->first();

        $this->assertNotNull($item);
        $this->assertSame('Apple', $item->getRawAttribute('name'));
    }

    public function testOrWhereWithWhereIn(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        // (category IN electronics) OR (price < 5) → Laptop, Phone, Apple(2)
        $results = QE_Item::query($db)
            ->whereIn('category', ['electronics'])
            ->orWhere('price<:maxp', ['maxp' => 5])
            ->get();

        $names = $results->pluck('name');
        $this->assertContains('Laptop', $names);
        $this->assertContains('Phone', $names);
        $this->assertContains('Apple', $names);
        $this->assertCount(3, $results);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 8: Bulk operations with new methods
    // ═══════════════════════════════════════════════════════════════

    public function testBulkUpdateWithWhereIn(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        $affected = QE_Item::query($db)
            ->whereIn('category', ['electronics'])
            ->bulkUpdate(['price' => 999]);

        $this->assertSame(2, $affected);

        // Verify
        $results = QE_Item::query($db)->whereIn('category', ['electronics'])->get();
        foreach ($results as $item) {
            $this->assertSame(999, $item->getRawAttribute('price'));
        }
    }

    public function testBulkDeleteWithWhereIn(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        $affected = QE_Item::query($db)->whereIn('category', ['food', 'furniture'])->bulkDelete();

        $this->assertSame(2, $affected);
        $this->assertCount(3, QE_Item::all($db));
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    private function createDb(): Database
    {
        static $counter = 0;
        $db = new Database('qe_test_' . (++$counter));
        $db->connectWithDriver('sqlite', ['path' => ':memory:']);
        $db->clearStatementPool();
        $db->getDBAdapter()->exec('
            CREATE TABLE IF NOT EXISTS qe_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                price INTEGER,
                category TEXT,
                created_at TEXT,
                updated_at TEXT
            )
        ');

        return $db;
    }

    private function seed(Database $db): void
    {
        QE_Item::create($db, ['name' => 'Laptop', 'price' => '1000', 'category' => 'electronics']);
        QE_Item::create($db, ['name' => 'Phone', 'price' => '500', 'category' => 'electronics']);
        QE_Item::create($db, ['name' => 'Novel', 'price' => '15', 'category' => 'books']);
        QE_Item::create($db, ['name' => 'Apple', 'price' => '2', 'category' => 'food']);
        QE_Item::create($db, ['name' => 'Desk', 'price' => '200', 'category' => 'furniture']);
    }
}

// ═══════════════════════════════════════════════════════════════
// Test Model Double
// ═══════════════════════════════════════════════════════════════

class QE_Item extends Model
{
    protected static string $table = 'qe_items';
    protected static array $fillable = ['name', 'price', 'category'];
    protected static array $casts = ['price' => 'int'];
}
