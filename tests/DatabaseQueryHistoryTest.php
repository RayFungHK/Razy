<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Database;

/**
 * Tests for P5: Database query history ring buffer and P2: statement pool integration.
 */
#[CoversClass(Database::class)]
class DatabaseQueryHistoryTest extends TestCase
{
    // ─── Ring Buffer: Basic Operations ────────────────────────────

    public function testGetQueriedInitiallyEmpty(): void
    {
        $db = new Database('test_ring_buffer');
        $this->assertSame([], $db->getQueried());
    }

    public function testGetLastQueriedInitiallyEmptyString(): void
    {
        $db = new Database('test_last_empty');
        $this->assertSame('', $db->getLastQueried());
    }

    public function testGetTotalQueryCountInitiallyZero(): void
    {
        $db = new Database('test_count_zero');
        $this->assertSame(0, $db->getTotalQueryCount());
    }

    public function testClearQueriedResetsState(): void
    {
        $db = new Database('test_clear');
        // Can't directly add queries without a DB connection, but clear should work
        $db->clearQueried();
        $this->assertSame([], $db->getQueried());
        $this->assertSame(0, $db->getTotalQueryCount());
        $this->assertSame('', $db->getLastQueried());
    }

    // ─── Ring Buffer with SQLite Integration ─────────────────────

    public function testQueryHistoryTracksExecutedQueries(): void
    {
        $db = new Database('test_history_track');
        $db->connectWithDriver('sqlite', ['path' => ':memory:']);

        $db->execute($db->prepare('CREATE TABLE test_hist (id INTEGER PRIMARY KEY)'));
        $db->execute($db->prepare('INSERT INTO test_hist (id) VALUES (1)'));

        $queried = $db->getQueried();
        $this->assertCount(2, $queried);
        $this->assertStringContainsString('CREATE TABLE', $queried[0]);
        $this->assertStringContainsString('INSERT INTO', $queried[1]);
    }

    public function testGetLastQueriedReturnsLastExecutedQuery(): void
    {
        $db = new Database('test_last_query');
        $db->connectWithDriver('sqlite', ['path' => ':memory:']);

        $db->execute($db->prepare('CREATE TABLE test_last (id INTEGER PRIMARY KEY)'));
        $db->execute($db->prepare('INSERT INTO test_last (id) VALUES (1)'));

        $last = $db->getLastQueried();
        $this->assertStringContainsString('INSERT INTO', $last);
    }

    public function testGetTotalQueryCountTracksTotal(): void
    {
        $db = new Database('test_total_count');
        $db->connectWithDriver('sqlite', ['path' => ':memory:']);

        $db->execute($db->prepare('CREATE TABLE test_count (id INTEGER PRIMARY KEY)'));
        $db->execute($db->prepare('INSERT INTO test_count (id) VALUES (1)'));
        $db->execute($db->prepare('SELECT * FROM test_count'));

        $this->assertSame(3, $db->getTotalQueryCount());
    }

    public function testClearQueriedAllowsNewTracking(): void
    {
        $db = new Database('test_clear_track');
        $db->connectWithDriver('sqlite', ['path' => ':memory:']);

        $db->execute($db->prepare('CREATE TABLE test_clr (id INTEGER PRIMARY KEY)'));
        $db->clearQueried();

        $this->assertSame(0, $db->getTotalQueryCount());
        $this->assertSame([], $db->getQueried());

        $db->execute($db->prepare('INSERT INTO test_clr (id) VALUES (1)'));
        $this->assertSame(1, $db->getTotalQueryCount());
    }

    // ─── Statement Pool Integration ──────────────────────────────

    public function testClearStatementPoolDoesNotThrowWhenNotConnected(): void
    {
        $db = new Database('test_pool_no_conn');
        // Should not throw even without a connection
        $result = $db->clearStatementPool();
        $this->assertInstanceOf(Database::class, $result);
    }

    public function testClearStatementPoolIsChainable(): void
    {
        $db = new Database('test_pool_chain');
        $db->connectWithDriver('sqlite', ['path' => ':memory:']);

        $result = $db->clearStatementPool();
        $this->assertSame($db, $result);
    }

    public function testStatementPoolReusesStatements(): void
    {
        $db = new Database('test_pool_reuse');
        $db->connectWithDriver('sqlite', ['path' => ':memory:']);

        $db->execute($db->prepare('CREATE TABLE test_pool (id INTEGER PRIMARY KEY, name TEXT)'));
        $db->execute($db->prepare("INSERT INTO test_pool (id, name) VALUES (1, 'Alice')"));

        // Execute same SELECT twice — pool should reuse the prepared statement
        $result1 = $db->execute($db->prepare('SELECT * FROM test_pool'));
        $result2 = $db->execute($db->prepare('SELECT * FROM test_pool'));

        $this->assertNotNull($result1);
        $this->assertNotNull($result2);
    }
}
