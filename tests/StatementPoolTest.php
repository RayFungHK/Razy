<?php

declare(strict_types=1);

namespace Razy\Tests;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Database\StatementPool;
use ValueError;

/**
 * Tests for P2: StatementPool — prepared statement caching with LRU eviction.
 */
#[CoversClass(StatementPool::class)]
class StatementPoolTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)');
        $this->pdo->exec("INSERT INTO test (id, name) VALUES (1, 'Alice'), (2, 'Bob')");
    }

    // ─── Basic Functionality ──────────────────────────────────────

    public function testGetOrPreparePreparesSql(): void
    {
        $pool = new StatementPool($this->pdo);
        $stmt = $pool->getOrPrepare('SELECT * FROM test');

        $this->assertInstanceOf(PDOStatement::class, $stmt);
    }

    public function testGetOrPrepareReturnsCachedStatement(): void
    {
        $pool = new StatementPool($this->pdo);
        $sql = 'SELECT * FROM test WHERE id = 1';

        $stmt1 = $pool->getOrPrepare($sql);
        $stmt2 = $pool->getOrPrepare($sql);

        // Same object should be returned (cached)
        $this->assertSame($stmt1, $stmt2);
    }

    public function testDifferentSqlReturnsDifferentStatements(): void
    {
        $pool = new StatementPool($this->pdo);

        $stmt1 = $pool->getOrPrepare('SELECT * FROM test WHERE id = 1');
        $stmt2 = $pool->getOrPrepare('SELECT * FROM test WHERE id = 2');

        $this->assertNotSame($stmt1, $stmt2);
    }

    public function testCachedStatementExecutesCorrectly(): void
    {
        $pool = new StatementPool($this->pdo);
        $sql = 'SELECT name FROM test WHERE id = 1';

        // First use
        $stmt = $pool->getOrPrepare($sql);
        $stmt->execute();
        $result1 = $stmt->fetchColumn();

        // Second use (cached, closeCursor should be called internally)
        $stmt = $pool->getOrPrepare($sql);
        $stmt->execute();
        $result2 = $stmt->fetchColumn();

        $this->assertSame('Alice', $result1);
        $this->assertSame('Alice', $result2);
    }

    // ─── Pool Size Tracking ──────────────────────────────────────

    public function testGetPoolSizeInitiallyZero(): void
    {
        $pool = new StatementPool($this->pdo);
        $this->assertSame(0, $pool->getPoolSize());
    }

    public function testGetPoolSizeIncrements(): void
    {
        $pool = new StatementPool($this->pdo);
        $pool->getOrPrepare('SELECT * FROM test');
        $this->assertSame(1, $pool->getPoolSize());

        $pool->getOrPrepare('SELECT * FROM test WHERE id = 1');
        $this->assertSame(2, $pool->getPoolSize());
    }

    public function testGetPoolSizeDoesNotIncrementOnCacheHit(): void
    {
        $pool = new StatementPool($this->pdo);
        $sql = 'SELECT * FROM test';

        $pool->getOrPrepare($sql);
        $pool->getOrPrepare($sql); // cache hit

        $this->assertSame(1, $pool->getPoolSize());
    }

    // ─── LRU Eviction ────────────────────────────────────────────

    public function testLRUEvictionAtMaxSize(): void
    {
        $pool = new StatementPool($this->pdo, maxSize: 3);

        $pool->getOrPrepare('SELECT 1');
        $pool->getOrPrepare('SELECT 2');
        $pool->getOrPrepare('SELECT 3');
        $this->assertSame(3, $pool->getPoolSize());

        // This should evict 'SELECT 1' (LRU)
        $pool->getOrPrepare('SELECT 4');
        $this->assertSame(3, $pool->getPoolSize());
    }

    public function testLRUEvictsLeastRecentlyUsed(): void
    {
        $pool = new StatementPool($this->pdo, maxSize: 2);

        $stmt1 = $pool->getOrPrepare('SELECT 1');
        $pool->getOrPrepare('SELECT 2');

        // Access 'SELECT 1' again to make it most recently used
        $pool->getOrPrepare('SELECT 1');

        // Add new entry — should evict 'SELECT 2' (LRU), not 'SELECT 1'
        $pool->getOrPrepare('SELECT 3');

        // 'SELECT 1' should still be cached (same object)
        $stmt1Again = $pool->getOrPrepare('SELECT 1');
        $this->assertSame($stmt1, $stmt1Again);
    }

    // ─── Clear ───────────────────────────────────────────────────

    public function testClearEmptiesPool(): void
    {
        $pool = new StatementPool($this->pdo);
        $pool->getOrPrepare('SELECT 1');
        $pool->getOrPrepare('SELECT 2');

        $pool->clear();
        $this->assertSame(0, $pool->getPoolSize());
    }

    public function testClearAllowsRepreparation(): void
    {
        $pool = new StatementPool($this->pdo);
        $sql = 'SELECT * FROM test';

        $stmt1 = $pool->getOrPrepare($sql);
        $pool->clear();

        // After clear, should prepare a new statement (different object)
        $stmt2 = $pool->getOrPrepare($sql);
        $this->assertNotSame($stmt1, $stmt2);
    }

    // ─── Edge Cases ──────────────────────────────────────────────

    public function testMaxSizeOne(): void
    {
        $pool = new StatementPool($this->pdo, maxSize: 1);

        $pool->getOrPrepare('SELECT 1');
        $pool->getOrPrepare('SELECT 2');

        $this->assertSame(1, $pool->getPoolSize());
    }

    public function testInvalidSqlReturnsFalse(): void
    {
        $pool = new StatementPool($this->pdo);

        // In PHP 8.3+, PDO::prepare() throws ValueError for empty SQL
        // Test that pool handles non-poolable edge cases gracefully
        $this->expectException(ValueError::class);
        $pool->getOrPrepare('');
    }
}
