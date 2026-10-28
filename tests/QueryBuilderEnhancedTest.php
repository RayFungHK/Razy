<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Database;
use Razy\ORM\Model;
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

    /**
     * @return array<string, array{0: callable(ModelQuery): mixed}>
     */
    public static function helperColumnInjections(): array
    {
        return [
            'whereIn' => [fn (ModelQuery $q) => $q->whereIn('1=1|id', [0])],
            'whereNotIn' => [fn (ModelQuery $q) => $q->whereNotIn('id|1=1', [0])],
            'whereBetween' => [fn (ModelQuery $q) => $q->whereBetween('1=1|price', 0, 0)],
            'whereNotBetween' => [fn (ModelQuery $q) => $q->whereNotBetween('price,1', 0, 0)],
            'whereNull' => [fn (ModelQuery $q) => $q->whereNull('id|1=1,name')],
            'whereNotNull' => [fn (ModelQuery $q) => $q->whereNotNull('(name)')],
        ];
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

    // ═══════════════════════════════════════════════════════════════
    // RZ-41: two calls on one column keep their own bindings
    // ═══════════════════════════════════════════════════════════════

    public function testTwoWhereInOnOneColumnBothApply(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        // Before RZ-41 the second call reused the first call's key and overwrote its values.
        $query = QE_Item::query($db)
            ->whereIn('category', ['electronics', 'books'])
            ->whereIn('category', ['books', 'food']);

        $this->assertSame(['Novel'], $query->get()->pluck('name'));
        $this->assertSame(1, $query->count());
    }

    public function testTwoWhereBetweenOnOneColumnBothApply(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        $results = QE_Item::query($db)
            ->whereBetween('price', 10, 600)
            ->whereBetween('price', 400, 2000)
            ->get();

        $this->assertSame(['Phone'], $results->pluck('name'));
    }

    public function testTwoNegatedHelpersOnOneColumnBothApply(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        $notIn = QE_Item::query($db)
            ->whereNotIn('category', ['electronics'])
            ->whereNotIn('category', ['food'])
            ->orderBy('name')
            ->get();
        $this->assertSame(['Desk', 'Novel'], $notIn->pluck('name'));

        $notBetween = QE_Item::query($db)
            ->whereNotBetween('price', 0, 10)
            ->whereNotBetween('price', 900, 2000)
            ->orderBy('name')
            ->get();
        $this->assertSame(['Desk', 'Novel', 'Phone'], $notBetween->pluck('name'));
    }

    public function testHelpersMixedOnOneColumnKeepTheirValues(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        $results = QE_Item::query($db)
            ->whereIn('category', ['electronics', 'books', 'food'])
            ->whereNotIn('category', ['food'])
            ->whereBetween('price', 10, 2000)
            ->whereNotBetween('price', 900, 2000)
            ->whereNotNull('category')
            ->whereNotNull('name')
            ->orderBy('name')
            ->get();

        $this->assertSame(['Novel', 'Phone'], $results->pluck('name'));
    }

    /**
     * Reproduces on this PR's 91ba90d: the column is spliced into Simple Syntax,
     * so `whereIn('1=1|id', [0])` returned every row. (On master the column-derived
     * placeholder name `_wi_1=1|id` broke the syntax first.).
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('helperColumnInjections')]
    public function testHelperColumnMustBeOneIdentifier(callable $call): void
    {
        $db = $this->createDb();
        $this->seed($db);

        $this->expectException(\Razy\Exception\QueryException::class);
        $call(QE_Item::query($db));
    }

    public function testHelperAcceptsAliasQualifiedColumn(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        $this->assertSame(['Novel'], QE_Item::query($db)->whereIn('qe_items.category', ['books'])->get()->pluck('name'));
    }

    public function testWhereParamCannotReplaceAHelperBinding(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        // Reproduces on master: `_wi_category` was the helper's key, so this where()
        // parameter replaced ['books'] and every row came back.
        $results = QE_Item::query($db)
            ->whereIn('category', ['books'])
            ->where('price>:p', ['p' => 0, '_wi_category' => ['electronics', 'books']])
            ->get();
        $this->assertSame(['Novel'], $results->pluck('name'));
    }

    public function testReservedHelperPrefixIsRefusedInWhereAndOrWhere(): void
    {
        $db = $this->createDb();

        foreach (['where', 'orWhere'] as $method) {
            try {
                QE_Item::query($db)->whereIn('category', ['books'])->{$method}('price>:p', ['p' => 0, '_mq_wi_1' => ['electronics']]);
                $this->fail($method . ' accepted a reserved _mq_ parameter');
            } catch (\Razy\Exception\QueryException $e) {
                $this->assertStringContainsString('reserved', $e->getMessage());
            }
        }
    }

    public function testBulkUpdateKeyMustBeAColumnName(): void
    {
        $db = $this->createDb();
        $this->seed($db);

        // Reproduces on master: the key was Update Simple Syntax, so the subquery ran
        // and wrote every category into the row.
        try {
            QE_Item::query($db)->whereIn('category', ['books'])
                ->bulkUpdate(['name=(SELECT group_concat(category) FROM qe_items)' => 'x']);
            $this->fail('expected QueryException');
        } catch (\Razy\Exception\QueryException) {
        }
        $this->assertSame(['Novel'], QE_Item::query($db)->whereIn('category', ['books'])->get()->pluck('name'));

        $this->assertSame(1, QE_Item::query($db)->whereIn('category', ['books'])->bulkUpdate(['name' => 'Novella']));
    }

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
