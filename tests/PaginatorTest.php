<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Database;
use Razy\ORM\Model;
use Razy\ORM\ModelCollection;
use Razy\ORM\ModelQuery;
use Razy\ORM\Paginator;

/**
 * Tests for P25: Pagination Object with Links.
 *
 * Covers the new Paginator class and ModelQuery::simplePaginate().
 * Existing paginate() now returns Paginator instead of array,
 * with backward-compatible ArrayAccess for ['data'], ['total'], etc.
 *
 * Sections:
 *  1. Paginator — Basic Getters
 *  2. Paginator — Convenience Booleans
 *  3. Paginator — URL Generation
 *  4. Paginator — Page Range & Links
 *  5. Paginator — Serialization (toArray, toJson, JsonSerializable)
 *  6. Paginator — ArrayAccess Backward Compatibility
 *  7. Paginator — Countable & IteratorAggregate
 *  8. ModelQuery::paginate() — Returns Paginator
 *  9. ModelQuery::simplePaginate() — No COUNT query
 * 10. Integration — Paginator with scopes, eager loading
 */
#[CoversClass(Paginator::class)]
#[CoversClass(ModelQuery::class)]
#[CoversClass(Model::class)]
#[CoversClass(ModelCollection::class)]
class PaginatorTest extends TestCase
{
    protected function tearDown(): void
    {
        Model::clearBootedModels();
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 1: Paginator — Basic Getters
    // ═══════════════════════════════════════════════════════════════

    public function testPaginatorBasicGetters(): void
    {
        $items = new ModelCollection([]);
        $paginator = new Paginator($items, 50, 3, 10);

        $this->assertSame($items, $paginator->items());
        $this->assertSame(50, $paginator->total());
        $this->assertSame(3, $paginator->currentPage());
        $this->assertSame(10, $paginator->perPage());
        $this->assertSame(5, $paginator->lastPage());
    }

    public function testPaginatorLastPageCalculation(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 23, 1, 5);
        $this->assertSame(5, $paginator->lastPage()); // ceil(23/5) = 5
    }

    public function testPaginatorPageClampedToLastPage(): void
    {
        // Requesting page 100 but only 3 pages exist → clamped to 3
        $paginator = new Paginator(new ModelCollection([]), 12, 100, 5);
        $this->assertSame(3, $paginator->currentPage());
        $this->assertSame(3, $paginator->lastPage());
    }

    public function testPaginatorPageMinimumIsOne(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 10, 0, 5);
        $this->assertSame(1, $paginator->currentPage());
    }

    public function testPaginatorPerPageMinimumIsOne(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 10, 1, 0);
        $this->assertSame(1, $paginator->perPage());
    }

    public function testPaginatorZeroTotalHasOneLastPage(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 0, 1, 10);
        $this->assertSame(1, $paginator->lastPage());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 2: Paginator — Convenience Booleans
    // ═══════════════════════════════════════════════════════════════

    public function testHasMorePagesTrue(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 30, 1, 10);
        $this->assertTrue($paginator->hasMorePages());
    }

    public function testHasMorePagesFalseOnLastPage(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 30, 3, 10);
        $this->assertFalse($paginator->hasMorePages());
    }

    public function testOnFirstPageTrue(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 50, 1, 10);
        $this->assertTrue($paginator->onFirstPage());
    }

    public function testOnFirstPageFalse(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 50, 3, 10);
        $this->assertFalse($paginator->onFirstPage());
    }

    public function testOnLastPageTrue(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 30, 3, 10);
        $this->assertTrue($paginator->onLastPage());
    }

    public function testOnLastPageFalse(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 30, 1, 10);
        $this->assertFalse($paginator->onLastPage());
    }

    public function testHasPagesTrue(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 30, 1, 10);
        $this->assertTrue($paginator->hasPages());
    }

    public function testHasPagesFalse(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 5, 1, 10);
        $this->assertFalse($paginator->hasPages());
    }

    public function testIsEmptyTrue(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 0, 1, 10);
        $this->assertTrue($paginator->isEmpty());
    }

    public function testIsEmptyFalse(): void
    {
        $db = $this->createDb();
        $this->seedArticles($db, 3);
        $paginator = Pg_Article::query($db)->paginate(1, 10);
        $this->assertFalse($paginator->isEmpty());
    }

    public function testIsNotEmpty(): void
    {
        $db = $this->createDb();
        $this->seedArticles($db, 2);
        $paginator = Pg_Article::query($db)->paginate(1, 10);
        $this->assertTrue($paginator->isNotEmpty());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 3: Paginator — URL Generation
    // ═══════════════════════════════════════════════════════════════

    public function testUrlDefaultPath(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 50, 2, 10);
        $this->assertSame('/?page=3', $paginator->url(3));
    }

    public function testUrlCustomPath(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 50, 2, 10);
        $paginator->setPath('/users');
        $this->assertSame('/users?page=3', $paginator->url(3));
    }

    public function testUrlTrailingSlashTrimmed(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 50, 2, 10);
        $paginator->setPath('/users/');
        $this->assertSame('/users?page=1', $paginator->url(1));
    }

    public function testUrlMinimumPageIsOne(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 50, 1, 10);
        $this->assertSame('/?page=1', $paginator->url(-5));
    }

    public function testFirstPageUrl(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 50, 3, 10);
        $paginator->setPath('/items');
        $this->assertSame('/items?page=1', $paginator->firstPageUrl());
    }

    public function testLastPageUrl(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 50, 1, 10);
        $paginator->setPath('/items');
        $this->assertSame('/items?page=5', $paginator->lastPageUrl());
    }

    public function testPreviousPageUrlOnFirstPage(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 50, 1, 10);
        $this->assertNull($paginator->previousPageUrl());
    }

    public function testPreviousPageUrlOnSecondPage(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 50, 2, 10);
        $paginator->setPath('/data');
        $this->assertSame('/data?page=1', $paginator->previousPageUrl());
    }

    public function testNextPageUrlOnLastPage(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 50, 5, 10);
        $this->assertNull($paginator->nextPageUrl());
    }

    public function testNextPageUrlOnMiddlePage(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 50, 3, 10);
        $paginator->setPath('/data');
        $this->assertSame('/data?page=4', $paginator->nextPageUrl());
    }

    public function testCustomPageName(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 50, 2, 10);
        $paginator->setPath('/results')->setPageName('p');
        $this->assertSame('/results?p=3', $paginator->url(3));
    }

    public function testAppendsExtraQueryParams(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 50, 2, 10);
        $paginator->setPath('/search')->appends(['q' => 'hello', 'sort' => 'name']);
        $url = $paginator->url(3);
        $this->assertStringContainsString('page=3', $url);
        $this->assertStringContainsString('q=hello', $url);
        $this->assertStringContainsString('sort=name', $url);
        $this->assertStringStartsWith('/search?', $url);
    }

    public function testGetPathReturnsSetPath(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 10, 1, 5);
        $paginator->setPath('/api/v2/users');
        $this->assertSame('/api/v2/users', $paginator->getPath());
    }

    public function testGetPageNameReturnsSetName(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 10, 1, 5);
        $paginator->setPageName('pg');
        $this->assertSame('pg', $paginator->getPageName());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 4: Paginator — Page Range & Links
    // ═══════════════════════════════════════════════════════════════

    public function testGetPageRangeMiddle(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 100, 5, 10);
        $range = $paginator->getPageRange(2);
        $this->assertSame([3, 4, 5, 6, 7], $range);
    }

    public function testGetPageRangeNearStart(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 100, 1, 10);
        $range = $paginator->getPageRange(3);
        $this->assertSame([1, 2, 3, 4], $range);
    }

    public function testGetPageRangeNearEnd(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 100, 10, 10);
        $range = $paginator->getPageRange(3);
        $this->assertSame([7, 8, 9, 10], $range);
    }

    public function testGetPageRangeSinglePage(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 3, 1, 10);
        $range = $paginator->getPageRange(3);
        $this->assertSame([1], $range);
    }

    public function testLinksStructure(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 50, 3, 10);
        $paginator->setPath('/items');
        $links = $paginator->links(2);

        $this->assertArrayHasKey('first', $links);
        $this->assertArrayHasKey('last', $links);
        $this->assertArrayHasKey('prev', $links);
        $this->assertArrayHasKey('next', $links);
        $this->assertArrayHasKey('pages', $links);

        $this->assertSame('/items?page=1', $links['first']);
        $this->assertSame('/items?page=5', $links['last']);
        $this->assertSame('/items?page=2', $links['prev']);
        $this->assertSame('/items?page=4', $links['next']);

        // Pages: 1,2,3,4,5 (onEachSide=2 from page 3)
        $this->assertCount(5, $links['pages']);
        $this->assertTrue($links['pages'][2]['active']); // page 3 is active
        $this->assertFalse($links['pages'][0]['active']); // page 1 is not
    }

    public function testLinksOnFirstPage(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 30, 1, 10);
        $links = $paginator->links(1);

        $this->assertNull($links['prev']);
        $this->assertSame('/?page=2', $links['next']);
        $this->assertCount(2, $links['pages']); // [1, 2]
    }

    public function testLinksOnLastPage(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 30, 3, 10);
        $links = $paginator->links(1);

        $this->assertSame('/?page=2', $links['prev']);
        $this->assertNull($links['next']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 5: Paginator — Serialization
    // ═══════════════════════════════════════════════════════════════

    public function testToArrayStructure(): void
    {
        $db = $this->createDb();
        $this->seedArticles($db, 5);

        $paginator = Pg_Article::query($db)->paginate(1, 3);
        $paginator->setPath('/articles');
        $arr = $paginator->toArray();

        $this->assertArrayHasKey('data', $arr);
        $this->assertArrayHasKey('total', $arr);
        $this->assertArrayHasKey('page', $arr);
        $this->assertArrayHasKey('per_page', $arr);
        $this->assertArrayHasKey('last_page', $arr);
        $this->assertArrayHasKey('from', $arr);
        $this->assertArrayHasKey('to', $arr);
        $this->assertArrayHasKey('links', $arr);

        $this->assertSame(5, $arr['total']);
        $this->assertSame(1, $arr['page']);
        $this->assertSame(3, $arr['per_page']);
        $this->assertSame(2, $arr['last_page']);
        $this->assertSame(1, $arr['from']);
        $this->assertSame(3, $arr['to']);
        $this->assertCount(3, $arr['data']);
    }

    public function testToArrayFromToNullWhenEmpty(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 0, 1, 10);
        $arr = $paginator->toArray();
        $this->assertNull($arr['from']);
        $this->assertNull($arr['to']);
    }

    public function testToArrayFromToPage2(): void
    {
        $db = $this->createDb();
        $this->seedArticles($db, 8);

        $paginator = Pg_Article::query($db)->paginate(2, 3);
        $arr = $paginator->toArray();

        $this->assertSame(4, $arr['from']); // (2-1)*3+1 = 4
        $this->assertSame(6, $arr['to']);   // 4+3-1=6
    }

    public function testToJsonProducesValidJson(): void
    {
        $db = $this->createDb();
        $this->seedArticles($db, 3);

        $paginator = Pg_Article::query($db)->paginate(1, 2);
        $json = $paginator->toJson();

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame(3, $decoded['total']);
        $this->assertCount(2, $decoded['data']);
    }

    public function testToJsonPrettyPrint(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 0, 1, 10);
        $json = $paginator->toJson(JSON_PRETTY_PRINT);
        $this->assertStringContainsString("\n", $json);
    }

    public function testJsonSerializeViaJsonEncode(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 10, 1, 5);
        $json = json_encode($paginator);
        $decoded = json_decode($json, true);
        $this->assertSame(10, $decoded['total']);
        $this->assertSame(1, $decoded['page']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 6: Paginator — ArrayAccess Backward Compatibility
    // ═══════════════════════════════════════════════════════════════

    public function testArrayAccessData(): void
    {
        $db = $this->createDb();
        $this->seedArticles($db, 4);

        $paginator = Pg_Article::query($db)->paginate(1, 2);

        // Access like the old array format
        $this->assertInstanceOf(ModelCollection::class, $paginator['data']);
        $this->assertCount(2, $paginator['data']);
    }

    public function testArrayAccessTotal(): void
    {
        $db = $this->createDb();
        $this->seedArticles($db, 7);
        $paginator = Pg_Article::query($db)->paginate(1, 5);
        $this->assertSame(7, $paginator['total']);
    }

    public function testArrayAccessPage(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 20, 2, 5);
        $this->assertSame(2, $paginator['page']);
    }

    public function testArrayAccessPerPage(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 20, 1, 5);
        $this->assertSame(5, $paginator['per_page']);
    }

    public function testArrayAccessLastPage(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 23, 1, 5);
        $this->assertSame(5, $paginator['last_page']);
    }

    public function testArrayAccessOffsetExists(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 10, 1, 5);
        $this->assertTrue(isset($paginator['data']));
        $this->assertTrue(isset($paginator['total']));
        $this->assertTrue(isset($paginator['page']));
        $this->assertTrue(isset($paginator['per_page']));
        $this->assertTrue(isset($paginator['last_page']));
        $this->assertFalse(isset($paginator['nonexistent']));
    }

    public function testArrayAccessUnknownKeyReturnsNull(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 10, 1, 5);
        $this->assertNull($paginator['unknown_key']);
    }

    public function testArrayAccessSetThrowsException(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 10, 1, 5);
        $this->expectException(\LogicException::class);
        $paginator['data'] = 'anything';
    }

    public function testArrayAccessUnsetThrowsException(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 10, 1, 5);
        $this->expectException(\LogicException::class);
        unset($paginator['data']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 7: Paginator — Countable & IteratorAggregate
    // ═══════════════════════════════════════════════════════════════

    public function testCountReturnsCurrentPageItemCount(): void
    {
        $db = $this->createDb();
        $this->seedArticles($db, 8);

        // Page 1 of 3-per-page → 3 items
        $paginator = Pg_Article::query($db)->paginate(1, 3);
        $this->assertCount(3, $paginator);

        // Page 3 of 3-per-page → 2 items (8 total)
        $paginator2 = Pg_Article::query($db)->paginate(3, 3);
        $this->assertCount(2, $paginator2);
    }

    public function testIterateOverPaginatorItems(): void
    {
        $db = $this->createDb();
        $this->seedArticles($db, 4);

        $paginator = Pg_Article::query($db)->paginate(1, 10);

        $names = [];
        foreach ($paginator as $article) {
            $names[] = $article->title;
        }
        $this->assertCount(4, $names);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 8: ModelQuery::paginate() — Returns Paginator
    // ═══════════════════════════════════════════════════════════════

    public function testPaginateReturnsPaginator(): void
    {
        $db = $this->createDb();
        $this->seedArticles($db, 10);

        $result = Pg_Article::query($db)->paginate(2, 3);
        $this->assertInstanceOf(Paginator::class, $result);
        $this->assertSame(10, $result->total());
        $this->assertSame(2, $result->currentPage());
        $this->assertSame(3, $result->perPage());
        $this->assertSame(4, $result->lastPage()); // ceil(10/3) = 4
        $this->assertCount(3, $result->items());
    }

    public function testPaginateFirstPage(): void
    {
        $db = $this->createDb();
        $this->seedArticles($db, 5);

        $result = Pg_Article::query($db)->paginate(1, 2);
        $this->assertSame(1, $result->currentPage());
        $this->assertSame(3, $result->lastPage());
        $this->assertCount(2, $result->items());
        $this->assertTrue($result->onFirstPage());
        $this->assertFalse($result->onLastPage());
    }

    public function testPaginateLastPage(): void
    {
        $db = $this->createDb();
        $this->seedArticles($db, 7);

        $result = Pg_Article::query($db)->paginate(3, 3);
        $this->assertCount(1, $result->items()); // 7 items, page 3 → 1 item
        $this->assertTrue($result->onLastPage());
        $this->assertFalse($result->onFirstPage());
    }

    public function testPaginateOutOfRangeClamped(): void
    {
        $db = $this->createDb();
        $this->seedArticles($db, 3);

        $result = Pg_Article::query($db)->paginate(100, 5);
        $this->assertSame(1, $result->currentPage()); // Clamped to last page
        $this->assertSame(1, $result->lastPage());
    }

    public function testPaginateWithWhereClause(): void
    {
        $db = $this->createDb();
        for ($i = 1; $i <= 10; $i++) {
            Pg_Article::create($db, [
                'title' => "Article $i",
                'status' => $i <= 6 ? 'published' : 'draft',
            ]);
        }

        $result = Pg_Article::query($db)
            ->where('status=:s', ['s' => 'published'])
            ->paginate(2, 3);

        $this->assertSame(6, $result->total());
        $this->assertSame(2, $result->lastPage());
        $this->assertCount(3, $result->items());
    }

    public function testPaginateWithOrderBy(): void
    {
        $db = $this->createDb();
        Pg_Article::create($db, ['title' => 'Zebra', 'status' => 'published']);
        Pg_Article::create($db, ['title' => 'Alpha', 'status' => 'published']);
        Pg_Article::create($db, ['title' => 'Mango', 'status' => 'published']);

        $result = Pg_Article::query($db)->orderBy('title')->paginate(1, 2);
        $items = $result->items();
        $this->assertCount(2, $items);
        $this->assertSame('Alpha', $items[0]->title);
        $this->assertSame('Mango', $items[1]->title);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 9: ModelQuery::simplePaginate() — No COUNT query
    // ═══════════════════════════════════════════════════════════════

    public function testSimplePaginateReturnsPaginator(): void
    {
        $db = $this->createDb();
        $this->seedArticles($db, 10);

        $result = Pg_Article::query($db)->simplePaginate(1, 3);
        $this->assertInstanceOf(Paginator::class, $result);
    }

    public function testSimplePaginateTotalIsNull(): void
    {
        $db = $this->createDb();
        $this->seedArticles($db, 10);

        $result = Pg_Article::query($db)->simplePaginate(1, 3);
        $this->assertNull($result->total());
        $this->assertNull($result->lastPage());
    }

    public function testSimplePaginateHasMorePagesTrue(): void
    {
        $db = $this->createDb();
        $this->seedArticles($db, 10);

        $result = Pg_Article::query($db)->simplePaginate(1, 3);
        $this->assertTrue($result->hasMorePages());
        $this->assertCount(3, $result->items()); // Only 3, not 4
    }

    public function testSimplePaginateHasMorePagesFalse(): void
    {
        $db = $this->createDb();
        $this->seedArticles($db, 3);

        $result = Pg_Article::query($db)->simplePaginate(1, 5);
        $this->assertFalse($result->hasMorePages());
        $this->assertCount(3, $result->items());
    }

    public function testSimplePaginateExactlyPerPage(): void
    {
        $db = $this->createDb();
        $this->seedArticles($db, 5);

        $result = Pg_Article::query($db)->simplePaginate(1, 5);
        $this->assertFalse($result->hasMorePages());
        $this->assertCount(5, $result->items());
    }

    public function testSimplePaginateExactlyPerPagePlusOne(): void
    {
        $db = $this->createDb();
        $this->seedArticles($db, 6);

        $result = Pg_Article::query($db)->simplePaginate(1, 5);
        $this->assertTrue($result->hasMorePages());
        $this->assertCount(5, $result->items()); // Trimmed from 6 to 5
    }

    public function testSimplePaginatePageTwo(): void
    {
        $db = $this->createDb();
        $this->seedArticles($db, 8);

        $result = Pg_Article::query($db)->simplePaginate(2, 3);
        $this->assertSame(2, $result->currentPage());
        $this->assertCount(3, $result->items());
        $this->assertTrue($result->hasMorePages()); // 8 items, page 2 × 3 = 6, still 2 left
    }

    public function testSimplePaginateLastPage(): void
    {
        $db = $this->createDb();
        $this->seedArticles($db, 7);

        $result = Pg_Article::query($db)->simplePaginate(3, 3);
        $this->assertCount(1, $result->items());
        $this->assertFalse($result->hasMorePages());
    }

    public function testSimplePaginateOnFirstPage(): void
    {
        $db = $this->createDb();
        $this->seedArticles($db, 5);

        $result = Pg_Article::query($db)->simplePaginate(1, 3);
        $this->assertTrue($result->onFirstPage());
    }

    public function testSimplePaginateOnLastPage(): void
    {
        $db = $this->createDb();
        $this->seedArticles($db, 3);

        $result = Pg_Article::query($db)->simplePaginate(1, 5);
        $this->assertTrue($result->onLastPage()); // !hasMorePages
    }

    public function testSimplePaginateGetPageRangeIsNull(): void
    {
        $db = $this->createDb();
        $this->seedArticles($db, 10);

        $result = Pg_Article::query($db)->simplePaginate(1, 3);
        $this->assertNull($result->getPageRange());
    }

    public function testSimplePaginateLastPageUrlIsNull(): void
    {
        $db = $this->createDb();
        $this->seedArticles($db, 10);

        $result = Pg_Article::query($db)->simplePaginate(2, 3);
        $this->assertNull($result->lastPageUrl());
    }

    public function testSimplePaginateNextPageUrl(): void
    {
        $db = $this->createDb();
        $this->seedArticles($db, 10);

        $result = Pg_Article::query($db)->simplePaginate(2, 3);
        $result->setPath('/articles');
        $this->assertSame('/articles?page=3', $result->nextPageUrl());
        $this->assertSame('/articles?page=1', $result->previousPageUrl());
    }

    public function testSimplePaginateHasPages(): void
    {
        $db = $this->createDb();
        $this->seedArticles($db, 10);

        // Page 2 with more → has pages
        $result = Pg_Article::query($db)->simplePaginate(2, 3);
        $this->assertTrue($result->hasPages());
    }

    public function testSimplePaginateNoPages(): void
    {
        $db = $this->createDb();
        $this->seedArticles($db, 2);

        // Page 1 with no more → no pages (single page)
        $result = Pg_Article::query($db)->simplePaginate(1, 5);
        $this->assertFalse($result->hasPages());
    }

    public function testSimplePaginateToArray(): void
    {
        $db = $this->createDb();
        $this->seedArticles($db, 5);

        $result = Pg_Article::query($db)->simplePaginate(1, 3);
        $arr = $result->toArray();

        $this->assertCount(3, $arr['data']);
        $this->assertNull($arr['total']);
        $this->assertNull($arr['last_page']);
        $this->assertSame(1, $arr['page']);
        $this->assertSame(3, $arr['per_page']);
    }

    public function testSimplePaginateArrayAccessTotalIsNull(): void
    {
        $db = $this->createDb();
        $this->seedArticles($db, 5);

        $result = Pg_Article::query($db)->simplePaginate(1, 3);
        $this->assertNull($result['total']);
        $this->assertNull($result['last_page']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 10: Integration — Fluent chaining, setPath returns self
    // ═══════════════════════════════════════════════════════════════

    public function testFluentChaining(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 50, 3, 10);

        $result = $paginator
            ->setPath('/api/v2/users')
            ->setPageName('p')
            ->appends(['sort' => 'name', 'filter' => 'active']);

        $this->assertSame($paginator, $result); // All return $this
        $url = $paginator->url(4);
        $this->assertStringContainsString('/api/v2/users?', $url);
        $this->assertStringContainsString('p=4', $url);
        $this->assertStringContainsString('sort=name', $url);
        $this->assertStringContainsString('filter=active', $url);
    }

    public function testPaginateWithWhereInIntegration(): void
    {
        $db = $this->createDb();
        for ($i = 1; $i <= 10; $i++) {
            Pg_Article::create($db, [
                'title' => "Art $i",
                'status' => $i % 2 === 0 ? 'published' : 'draft',
            ]);
        }

        $result = Pg_Article::query($db)
            ->whereIn('status', ['published'])
            ->paginate(1, 3);

        $this->assertSame(5, $result->total()); // 5 published
        $this->assertCount(3, $result->items());
        $this->assertSame(2, $result->lastPage());
    }

    public function testLinksWithAppendsIntegration(): void
    {
        $paginator = new Paginator(new ModelCollection([]), 50, 3, 10);
        $paginator->setPath('/search')->appends(['q' => 'test']);
        $links = $paginator->links(1);

        // prev and next should include query params
        $this->assertStringContainsString('q=test', $links['prev']);
        $this->assertStringContainsString('q=test', $links['next']);
        $this->assertStringContainsString('page=2', $links['prev']);
        $this->assertStringContainsString('page=4', $links['next']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    private function createDb(): Database
    {
        static $counter = 0;
        $db = new Database('pg_test_' . (++$counter));
        $db->connectWithDriver('sqlite', ['path' => ':memory:']);
        $db->clearStatementPool();
        $db->getDBAdapter()->exec('
            CREATE TABLE IF NOT EXISTS pg_articles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                status TEXT DEFAULT \'draft\',
                created_at TEXT,
                updated_at TEXT
            )
        ');

        return $db;
    }

    private function seedArticles(Database $db, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            Pg_Article::create($db, ['title' => "Article $i", 'status' => 'published']);
        }
    }
}

// ═══════════════════════════════════════════════════════════════
// Test Model Double
// ═══════════════════════════════════════════════════════════════

class Pg_Article extends Model
{
    protected static string $table = 'pg_articles';
    protected static array $fillable = ['title', 'status'];
}
