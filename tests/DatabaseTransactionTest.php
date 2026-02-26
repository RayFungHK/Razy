<?php

/**
 * This file is part of Razy v0.5.
 *
 * Comprehensive tests for P12: Database Transaction Wrapper.
 *
 * Tests the Transaction class (savepoint management, nesting, closure runner),
 * Database transaction methods (beginTransaction, commit, rollback, inTransaction,
 * transaction), TransactionException, and integration with real SQLite databases.
 *
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Database;
use Razy\Database\Transaction;
use Razy\Exception\TransactionException;
use RuntimeException;

#[CoversClass(Transaction::class)]
#[CoversClass(Database::class)]
#[CoversClass(TransactionException::class)]
class DatabaseTransactionTest extends TestCase
{
    private PDO $pdo;

    private Transaction $tx;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        $this->tx = new Transaction($this->pdo);
    }

    // ══════════════════════════════════════════════════════════════
    //  DataProvider Tests
    // ══════════════════════════════════════════════════════════════

    public static function returnValueProvider(): array
    {
        return [
            'string' => ['hello'],
            'int' => [42],
            'float' => [3.14],
            'bool true' => [true],
            'bool false' => [false],
            'null' => [null],
            'array' => [['a' => 1, 'b' => 2]],
            'empty array' => [[]],
        ];
    }

    // ══════════════════════════════════════════════════════════════
    //  Transaction — Basic Begin / Commit / Rollback
    // ══════════════════════════════════════════════════════════════

    public function testBeginStartsTransaction(): void
    {
        $this->assertFalse($this->tx->active());
        $this->assertSame(0, $this->tx->level());

        $this->tx->begin();

        $this->assertTrue($this->tx->active());
        $this->assertSame(1, $this->tx->level());
    }

    public function testCommitEndsTransaction(): void
    {
        $this->tx->begin();
        $this->pdo->exec("INSERT INTO items (name) VALUES ('committed')");
        $this->tx->commit();

        $this->assertFalse($this->tx->active());
        $this->assertSame(0, $this->tx->level());
        $this->assertSame(1, $this->rowCount());
    }

    public function testRollbackRevertsChanges(): void
    {
        $this->tx->begin();
        $this->pdo->exec("INSERT INTO items (name) VALUES ('rolled_back')");
        $this->assertSame(1, $this->rowCount()); // visible within transaction
        $this->tx->rollback();

        $this->assertFalse($this->tx->active());
        $this->assertSame(0, $this->rowCount());
    }

    public function testCommitPersistsData(): void
    {
        $this->tx->begin();
        $this->pdo->exec("INSERT INTO items (name) VALUES ('alpha')");
        $this->pdo->exec("INSERT INTO items (name) VALUES ('beta')");
        $this->tx->commit();

        $this->assertSame(2, $this->rowCount());
    }

    public function testCommitWithoutBeginThrows(): void
    {
        $this->expectException(TransactionException::class);
        $this->expectExceptionMessage('Cannot commit: no active transaction.');

        $this->tx->commit();
    }

    public function testRollbackWithoutBeginThrows(): void
    {
        $this->expectException(TransactionException::class);
        $this->expectExceptionMessage('Cannot rollback: no active transaction.');

        $this->tx->rollback();
    }

    // ══════════════════════════════════════════════════════════════
    //  Transaction — Nested Savepoints
    // ══════════════════════════════════════════════════════════════

    public function testNestedBeginIncreasesLevel(): void
    {
        $this->tx->begin();       // level 1
        $this->assertSame(1, $this->tx->level());

        $this->tx->begin();       // level 2 (savepoint)
        $this->assertSame(2, $this->tx->level());

        $this->tx->begin();       // level 3
        $this->assertSame(3, $this->tx->level());

        // Commit all levels
        $this->tx->commit();      // 3 → 2
        $this->tx->commit();      // 2 → 1
        $this->tx->commit();      // 1 → 0
        $this->assertSame(0, $this->tx->level());
    }

    public function testNestedRollbackOnlyAffectsInnerScope(): void
    {
        $this->tx->begin();
        $this->pdo->exec("INSERT INTO items (name) VALUES ('outer')");

        $this->tx->begin(); // savepoint
        $this->pdo->exec("INSERT INTO items (name) VALUES ('inner')");
        $this->assertSame(2, $this->rowCount());
        $this->tx->rollback(); // rollback to savepoint — removes 'inner'

        $this->assertSame(1, $this->rowCount());

        $this->tx->commit(); // commit outer

        $this->assertSame(1, $this->rowCount());

        $row = $this->pdo->query('SELECT name FROM items')->fetchColumn();
        $this->assertSame('outer', $row);
    }

    public function testNestedCommitPreservesInnerChanges(): void
    {
        $this->tx->begin();       // outer
        $this->pdo->exec("INSERT INTO items (name) VALUES ('outer')");

        $this->tx->begin();       // savepoint
        $this->pdo->exec("INSERT INTO items (name) VALUES ('inner')");
        $this->tx->commit();      // release savepoint

        $this->tx->commit();      // commit outer

        $this->assertSame(2, $this->rowCount());
    }

    public function testDoublyNestedRollbackPreservesOuterLayers(): void
    {
        $this->tx->begin();       // level 1
        $this->pdo->exec("INSERT INTO items (name) VALUES ('L1')");

        $this->tx->begin();       // level 2
        $this->pdo->exec("INSERT INTO items (name) VALUES ('L2')");

        $this->tx->begin();       // level 3
        $this->pdo->exec("INSERT INTO items (name) VALUES ('L3')");
        $this->tx->rollback();    // rollback level 3 → removes L3

        $this->tx->commit();      // commit level 2 → keeps L2
        $this->tx->commit();      // commit level 1 → keeps L1 + L2

        $this->assertSame(2, $this->rowCount());

        $names = $this->pdo->query('SELECT name FROM items ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['L1', 'L2'], $names);
    }

    public function testOuterRollbackRevertsEverything(): void
    {
        $this->tx->begin();       // outer
        $this->pdo->exec("INSERT INTO items (name) VALUES ('A')");

        $this->tx->begin();       // savepoint
        $this->pdo->exec("INSERT INTO items (name) VALUES ('B')");
        $this->tx->commit();      // release savepoint

        $this->tx->rollback();    // rollback outer → everything gone

        $this->assertSame(0, $this->rowCount());
    }

    // ══════════════════════════════════════════════════════════════
    //  Transaction — Closure Runner (run)
    // ══════════════════════════════════════════════════════════════

    public function testRunCommitsOnSuccess(): void
    {
        $this->tx->run(function () {
            $this->pdo->exec("INSERT INTO items (name) VALUES ('auto_commit')");
        });

        $this->assertFalse($this->tx->active());
        $this->assertSame(1, $this->rowCount());
    }

    public function testRunRollsBackOnException(): void
    {
        try {
            $this->tx->run(function () {
                $this->pdo->exec("INSERT INTO items (name) VALUES ('will_rollback')");

                throw new RuntimeException('Simulated error');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated error', $e->getMessage());
        }

        $this->assertFalse($this->tx->active());
        $this->assertSame(0, $this->rowCount());
    }

    public function testRunReturnsCallbackValue(): void
    {
        $result = $this->tx->run(function () {
            $this->pdo->exec("INSERT INTO items (name) VALUES ('test')");

            return $this->pdo->lastInsertId();
        });

        $this->assertSame('1', $result);
    }

    public function testRunRethrowsException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Test error');

        $this->tx->run(function () {
            throw new RuntimeException('Test error');
        });
    }

    public function testRunCanBeNested(): void
    {
        $this->tx->run(function () {
            $this->pdo->exec("INSERT INTO items (name) VALUES ('outer')");

            $this->tx->run(function () {
                $this->pdo->exec("INSERT INTO items (name) VALUES ('inner')");
            });
        });

        $this->assertSame(2, $this->rowCount());
    }

    public function testRunNestedInnerFailureRollsBackInner(): void
    {
        $this->tx->run(function () {
            $this->pdo->exec("INSERT INTO items (name) VALUES ('outer')");

            try {
                $this->tx->run(function () {
                    $this->pdo->exec("INSERT INTO items (name) VALUES ('inner_fail')");

                    throw new RuntimeException('inner error');
                });
            } catch (RuntimeException) {
                // Inner rolled back, outer continues
            }
        });

        // Only outer's row should survive
        $this->assertSame(1, $this->rowCount());
        $name = $this->pdo->query('SELECT name FROM items')->fetchColumn();
        $this->assertSame('outer', $name);
    }

    public function testRunReturnsNull(): void
    {
        $result = $this->tx->run(function () {
            // No return statement
        });

        $this->assertNull($result);
    }

    public function testRunReceivesTransactionInstance(): void
    {
        $captured = null;
        $this->tx->run(function (Transaction $tx) use (&$captured) {
            $captured = $tx;
        });

        $this->assertSame($this->tx, $captured);
    }

    // ══════════════════════════════════════════════════════════════
    //  Transaction — State Tracking
    // ══════════════════════════════════════════════════════════════

    public function testActiveReturnsFalseInitially(): void
    {
        $this->assertFalse($this->tx->active());
    }

    public function testLevelReturnsZeroInitially(): void
    {
        $this->assertSame(0, $this->tx->level());
    }

    public function testActiveReturnsTrueDuringTransaction(): void
    {
        $this->tx->begin();
        $this->assertTrue($this->tx->active());
    }

    public function testLevelTracksNestingCorrectly(): void
    {
        $this->tx->begin();       // 1
        $this->tx->begin();       // 2
        $this->assertSame(2, $this->tx->level());

        $this->tx->commit();      // 1
        $this->assertSame(1, $this->tx->level());

        $this->tx->rollback();    // 0
        $this->assertSame(0, $this->tx->level());
    }

    public function testSequentialTransactions(): void
    {
        // First transaction
        $this->tx->begin();
        $this->pdo->exec("INSERT INTO items (name) VALUES ('first')");
        $this->tx->commit();

        // Second transaction
        $this->tx->begin();
        $this->pdo->exec("INSERT INTO items (name) VALUES ('second')");
        $this->tx->commit();

        $this->assertSame(2, $this->rowCount());
    }

    public function testSequentialAfterRollback(): void
    {
        // Rolled back transaction
        $this->tx->begin();
        $this->pdo->exec("INSERT INTO items (name) VALUES ('discarded')");
        $this->tx->rollback();

        // New transaction
        $this->tx->begin();
        $this->pdo->exec("INSERT INTO items (name) VALUES ('kept')");
        $this->tx->commit();

        $this->assertSame(1, $this->rowCount());
        $name = $this->pdo->query('SELECT name FROM items')->fetchColumn();
        $this->assertSame('kept', $name);
    }

    // ══════════════════════════════════════════════════════════════
    //  TransactionException
    // ══════════════════════════════════════════════════════════════

    public function testExceptionIsRuntimeException(): void
    {
        $e = new TransactionException('test');
        $this->assertInstanceOf(RuntimeException::class, $e);
    }

    public function testExceptionMessage(): void
    {
        $e = new TransactionException('Something went wrong');
        $this->assertSame('Something went wrong', $e->getMessage());
    }

    public function testExceptionCode(): void
    {
        $e = new TransactionException('error', 500);
        $this->assertSame(500, $e->getCode());
    }

    public function testExceptionPreviousChain(): void
    {
        $prev = new RuntimeException('cause');
        $e = new TransactionException('wrapper', 0, $prev);
        $this->assertSame($prev, $e->getPrevious());
    }

    public function testExceptionExtendsDatabase(): void
    {
        $e = new TransactionException('test');
        $this->assertInstanceOf(\Razy\Exception\DatabaseException::class, $e);
    }

    // ══════════════════════════════════════════════════════════════
    //  Database — Transaction Integration (via Database class)
    // ══════════════════════════════════════════════════════════════

    public function testDatabaseBeginAndCommit(): void
    {
        $db = $this->createDatabase();
        $db->getDBAdapter()->exec('CREATE TABLE test (id INTEGER PRIMARY KEY, val TEXT)');

        $db->beginTransaction();
        $db->getDBAdapter()->exec("INSERT INTO test (val) VALUES ('hello')");
        $db->commit();

        $count = (int) $db->getDBAdapter()->query('SELECT COUNT(*) FROM test')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testDatabaseBeginAndRollback(): void
    {
        $db = $this->createDatabase();
        $db->getDBAdapter()->exec('CREATE TABLE test (id INTEGER PRIMARY KEY, val TEXT)');

        $db->beginTransaction();
        $db->getDBAdapter()->exec("INSERT INTO test (val) VALUES ('gone')");
        $db->rollback();

        $count = (int) $db->getDBAdapter()->query('SELECT COUNT(*) FROM test')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testDatabaseInTransaction(): void
    {
        $db = $this->createDatabase();

        $this->assertFalse($db->inTransaction());

        $db->beginTransaction();
        $this->assertTrue($db->inTransaction());

        $db->commit();
        $this->assertFalse($db->inTransaction());
    }

    public function testDatabaseTransactionLevel(): void
    {
        $db = $this->createDatabase();

        $this->assertSame(0, $db->getTransactionLevel());

        $db->beginTransaction();
        $this->assertSame(1, $db->getTransactionLevel());

        $db->beginTransaction();
        $this->assertSame(2, $db->getTransactionLevel());

        $db->commit();
        $this->assertSame(1, $db->getTransactionLevel());

        $db->commit();
        $this->assertSame(0, $db->getTransactionLevel());
    }

    public function testDatabaseClosureTransaction(): void
    {
        $db = $this->createDatabase();
        $db->getDBAdapter()->exec('CREATE TABLE test (id INTEGER PRIMARY KEY, val TEXT)');

        $result = $db->transaction(function (Database $db) {
            $db->getDBAdapter()->exec("INSERT INTO test (val) VALUES ('auto')");

            return 'done';
        });

        $this->assertSame('done', $result);

        $count = (int) $db->getDBAdapter()->query('SELECT COUNT(*) FROM test')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testDatabaseClosureTransactionRollbackOnException(): void
    {
        $db = $this->createDatabase();
        $db->getDBAdapter()->exec('CREATE TABLE test (id INTEGER PRIMARY KEY, val TEXT)');

        try {
            $db->transaction(function (Database $db) {
                $db->getDBAdapter()->exec("INSERT INTO test (val) VALUES ('gone')");

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // expected
        }

        $count = (int) $db->getDBAdapter()->query('SELECT COUNT(*) FROM test')->fetchColumn();
        $this->assertSame(0, $count);
        $this->assertFalse($db->inTransaction());
    }

    public function testDatabaseNestedTransactions(): void
    {
        $db = $this->createDatabase();
        $db->getDBAdapter()->exec('CREATE TABLE test (id INTEGER PRIMARY KEY, val TEXT)');

        $db->beginTransaction();
        $db->getDBAdapter()->exec("INSERT INTO test (val) VALUES ('outer')");

        $db->beginTransaction(); // savepoint
        $db->getDBAdapter()->exec("INSERT INTO test (val) VALUES ('inner')");
        $db->rollback(); // rollback savepoint

        $db->commit(); // commit outer

        $count = (int) $db->getDBAdapter()->query('SELECT COUNT(*) FROM test')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testDatabaseNestedClosureTransactions(): void
    {
        $db = $this->createDatabase();
        $db->getDBAdapter()->exec('CREATE TABLE test (id INTEGER PRIMARY KEY, val TEXT)');

        $db->transaction(function (Database $db) {
            $db->getDBAdapter()->exec("INSERT INTO test (val) VALUES ('outer')");

            $db->transaction(function (Database $db) {
                $db->getDBAdapter()->exec("INSERT INTO test (val) VALUES ('inner')");
            });
        });

        $count = (int) $db->getDBAdapter()->query('SELECT COUNT(*) FROM test')->fetchColumn();
        $this->assertSame(2, $count);
    }

    public function testDatabaseTransactionWithoutConnectionThrows(): void
    {
        $db = new Database('no_connection');

        $this->expectException(TransactionException::class);
        $this->expectExceptionMessage('no database connection');

        $db->beginTransaction();
    }

    public function testDatabaseCommitWithoutConnectionThrows(): void
    {
        $db = new Database('no_connection');

        $this->expectException(TransactionException::class);

        $db->commit();
    }

    public function testDatabaseRollbackWithoutConnectionThrows(): void
    {
        $db = new Database('no_connection');

        $this->expectException(TransactionException::class);

        $db->rollback();
    }

    public function testDatabaseInTransactionReturnsFalseWithoutConnection(): void
    {
        $db = new Database('no_connection');

        $this->assertFalse($db->inTransaction());
    }

    public function testDatabaseGetTransactionLevelWithoutConnection(): void
    {
        $db = new Database('no_connection');

        $this->assertSame(0, $db->getTransactionLevel());
    }

    public function testDatabaseGetTransaction(): void
    {
        $db = $this->createDatabase();

        $this->assertInstanceOf(Transaction::class, $db->getTransaction());
    }

    public function testDatabaseGetTransactionNullWithoutConnection(): void
    {
        $db = new Database('no_connection');

        $this->assertNull($db->getTransaction());
    }

    // ══════════════════════════════════════════════════════════════
    //  Database — Statement Execution Inside Transactions
    // ══════════════════════════════════════════════════════════════

    public function testExecuteInsideTransactionCommits(): void
    {
        $db = $this->createDatabase();
        $db->getDBAdapter()->exec('CREATE TABLE test (id INTEGER PRIMARY KEY, val TEXT)');
        $db->clearStatementPool();

        $db->transaction(function (Database $db) {
            $stmt = $db->insert('test', ['val'])->assign(['val' => 'via_stmt']);
            $db->execute($stmt);
        });

        $count = (int) $db->getDBAdapter()->query('SELECT COUNT(*) FROM test')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testExecuteInsideTransactionRollback(): void
    {
        $db = $this->createDatabase();
        $db->getDBAdapter()->exec('CREATE TABLE test (id INTEGER PRIMARY KEY, val TEXT)');
        $db->clearStatementPool();

        try {
            $db->transaction(function (Database $db) {
                $stmt = $db->insert('test', ['val'])->assign(['val' => 'will_rollback']);
                $db->execute($stmt);

                throw new RuntimeException('abort');
            });
        } catch (RuntimeException) {
            // expected
        }

        $count = (int) $db->getDBAdapter()->query('SELECT COUNT(*) FROM test')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testLastIDInsideTransaction(): void
    {
        $db = $this->createDatabase();
        $db->getDBAdapter()->exec('CREATE TABLE test (id INTEGER PRIMARY KEY AUTOINCREMENT, val TEXT)');
        $db->clearStatementPool();

        $lastId = $db->transaction(function (Database $db) {
            $stmt = $db->insert('test', ['val'])->assign(['val' => 'row1']);
            $db->execute($stmt);

            return $db->lastID();
        });

        $this->assertSame(1, $lastId);
    }

    // ══════════════════════════════════════════════════════════════
    //  Integration — Complex Transaction Scenarios
    // ══════════════════════════════════════════════════════════════

    public function testThreeLevelNesting(): void
    {
        $this->tx->begin();       // L1
        $this->pdo->exec("INSERT INTO items (name) VALUES ('L1')");

        $this->tx->begin();       // L2
        $this->pdo->exec("INSERT INTO items (name) VALUES ('L2')");

        $this->tx->begin();       // L3
        $this->pdo->exec("INSERT INTO items (name) VALUES ('L3')");
        $this->tx->commit();      // release L3

        $this->tx->commit();      // release L2
        $this->tx->commit();      // commit L1

        $this->assertSame(3, $this->rowCount());

        $names = $this->pdo->query('SELECT name FROM items ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['L1', 'L2', 'L3'], $names);
    }

    public function testMiddleLevelRollbackPreservesOuter(): void
    {
        $this->tx->begin();       // L1
        $this->pdo->exec("INSERT INTO items (name) VALUES ('L1')");

        $this->tx->begin();       // L2
        $this->pdo->exec("INSERT INTO items (name) VALUES ('L2')");
        $this->tx->rollback();    // rollback L2

        $this->tx->commit();      // commit L1

        $this->assertSame(1, $this->rowCount());
        $name = $this->pdo->query('SELECT name FROM items')->fetchColumn();
        $this->assertSame('L1', $name);
    }

    public function testMultipleInnerTransactionsOneRollback(): void
    {
        $this->tx->begin();       // outer
        $this->pdo->exec("INSERT INTO items (name) VALUES ('base')");

        // Inner 1: commit
        $this->tx->begin();
        $this->pdo->exec("INSERT INTO items (name) VALUES ('inner1')");
        $this->tx->commit();

        // Inner 2: rollback
        $this->tx->begin();
        $this->pdo->exec("INSERT INTO items (name) VALUES ('inner2')");
        $this->tx->rollback();

        // Inner 3: commit
        $this->tx->begin();
        $this->pdo->exec("INSERT INTO items (name) VALUES ('inner3')");
        $this->tx->commit();

        $this->tx->commit();      // commit outer

        $this->assertSame(3, $this->rowCount());

        $names = $this->pdo->query('SELECT name FROM items ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['base', 'inner1', 'inner3'], $names);
    }

    public function testTransactionWithMultipleStatements(): void
    {
        $db = $this->createDatabase();
        $pdo = $db->getDBAdapter();
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, total REAL)');
        $db->clearStatementPool();

        $orderId = $db->transaction(function (Database $db) {
            $pdo = $db->getDBAdapter();

            $pdo->exec("INSERT INTO users (name) VALUES ('Alice')");
            $userId = $db->lastID();

            $pdo->exec("INSERT INTO orders (user_id, total) VALUES ({$userId}, 99.99)");

            return $db->lastID();
        });

        $this->assertSame(1, $orderId);

        $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $orderCount = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        $this->assertSame(1, $userCount);
        $this->assertSame(1, $orderCount);
    }

    public function testTransactionRollbackWithMultipleStatements(): void
    {
        $db = $this->createDatabase();
        $pdo = $db->getDBAdapter();
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, total REAL)');
        $db->clearStatementPool();

        try {
            $db->transaction(function (Database $db) {
                $pdo = $db->getDBAdapter();

                $pdo->exec("INSERT INTO users (name) VALUES ('Bob')");
                $pdo->exec('INSERT INTO orders (user_id, total) VALUES (1, 50.00)');

                throw new RuntimeException('Payment declined');
            });
        } catch (RuntimeException) {
            // expected
        }

        // Both tables should be empty
        $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $orderCount = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        $this->assertSame(0, $userCount);
        $this->assertSame(0, $orderCount);
    }

    // ══════════════════════════════════════════════════════════════
    //  Edge Cases
    // ══════════════════════════════════════════════════════════════

    public function testDoubleCommitThrows(): void
    {
        $this->tx->begin();
        $this->tx->commit();

        $this->expectException(TransactionException::class);
        $this->tx->commit(); // double commit
    }

    public function testDoubleRollbackThrows(): void
    {
        $this->tx->begin();
        $this->tx->rollback();

        $this->expectException(TransactionException::class);
        $this->tx->rollback(); // double rollback
    }

    public function testEmptyTransaction(): void
    {
        // Begin and commit with no operations
        $this->tx->begin();
        $this->tx->commit();

        $this->assertFalse($this->tx->active());
        $this->assertSame(0, $this->rowCount());
    }

    public function testEmptyRunTransaction(): void
    {
        $result = $this->tx->run(function () {
            return 42;
        });

        $this->assertSame(42, $result);
        $this->assertFalse($this->tx->active());
    }

    public function testRunWithTransactionExceptionDoesNotDoubleRollback(): void
    {
        $this->expectException(TransactionException::class);

        $this->tx->run(function () {
            throw new TransactionException('intentional');
        });
    }

    public function testDatabaseTransactionClosureReceivesDatabase(): void
    {
        $db = $this->createDatabase();
        $captured = null;

        $db->transaction(function (Database $inner) use (&$captured) {
            $captured = $inner;
        });

        $this->assertSame($db, $captured);
    }

    public function testMultipleSequentialClosureTransactions(): void
    {
        $db = $this->createDatabase();
        $pdo = $db->getDBAdapter();
        $pdo->exec('CREATE TABLE test (id INTEGER PRIMARY KEY AUTOINCREMENT, val TEXT)');
        $db->clearStatementPool();

        for ($i = 1; $i <= 5; $i++) {
            $db->transaction(function (Database $db) use ($i) {
                $db->getDBAdapter()->exec("INSERT INTO test (val) VALUES ('item_{$i}')");
            });
        }

        $count = (int) $pdo->query('SELECT COUNT(*) FROM test')->fetchColumn();
        $this->assertSame(5, $count);
    }

    public function testDatabaseImplementsInterface(): void
    {
        $db = $this->createDatabase();
        $this->assertInstanceOf(\Razy\Contract\DatabaseInterface::class, $db);
    }

    #[DataProvider('returnValueProvider')]
    public function testRunPreservesReturnType(mixed $expected): void
    {
        $result = $this->tx->run(fn () => $expected);

        $this->assertSame($expected, $result);
    }

    #[DataProvider('returnValueProvider')]
    public function testDatabaseTransactionPreservesReturnType(mixed $expected): void
    {
        $db = $this->createDatabase();

        $result = $db->transaction(fn (Database $db) => $expected);

        $this->assertSame($expected, $result);
    }

    // Helper: count rows in items table
    private function rowCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
    }

    // Helper: insert a row and return it
    private function insertItem(string $name): void
    {
        $this->pdo->exec("INSERT INTO items (name) VALUES ('" . $this->pdo->quote($name)[1] . "')");
    }

    // Helper: create a connected Database with SQLite :memory:
    private function createDatabase(): Database
    {
        $db = new Database('tx_test_' . \bin2hex(\random_bytes(4)));
        $db->connectWithDriver('sqlite', ['path' => ':memory:']);

        return $db;
    }
}
