<?php

declare(strict_types=1);

namespace Razy\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Razy\Database\Driver;
use Razy\Database\Driver\MySQL;
use Razy\Database\Driver\PostgreSQL;
use Razy\Database\Driver\SQLite;

/**
 * Tests for individual Database Driver implementations.
 *
 * All tests here are pure unit tests — no database connection required.
 * They verify SQL syntax generation, identifier quoting, and driver metadata.
 *
 * @covers \Razy\Database\Driver
 * @covers \Razy\Database\Driver\MySQL
 * @covers \Razy\Database\Driver\SQLite
 * @covers \Razy\Database\Driver\PostgreSQL
 */
class DatabaseDriverTest extends TestCase
{
    private MySQL $mysql;
    private SQLite $sqlite;
    private PostgreSQL $pgsql;

    protected function setUp(): void
    {
        $this->mysql = new MySQL();
        $this->sqlite = new SQLite();
        $this->pgsql = new PostgreSQL();
    }

    // ── getType ──────────────────────────────────────────────

    public function testMySQLGetType(): void
    {
        $this->assertSame('mysql', $this->mysql->getType());
    }

    public function testSQLiteGetType(): void
    {
        $this->assertSame('sqlite', $this->sqlite->getType());
    }

    public function testPostgreSQLGetType(): void
    {
        $this->assertSame('pgsql', $this->pgsql->getType());
    }

    // ── isConnected (fresh instance) ─────────────────────────

    public function testMySQLNotConnectedByDefault(): void
    {
        $this->assertFalse($this->mysql->isConnected());
    }

    public function testSQLiteNotConnectedByDefault(): void
    {
        $this->assertFalse($this->sqlite->isConnected());
    }

    public function testPostgreSQLNotConnectedByDefault(): void
    {
        $this->assertFalse($this->pgsql->isConnected());
    }

    // ── getAdapter (fresh instance) ──────────────────────────

    public function testMySQLAdapterNullByDefault(): void
    {
        $this->assertNull($this->mysql->getAdapter());
    }

    public function testSQLiteAdapterNullByDefault(): void
    {
        $this->assertNull($this->sqlite->getAdapter());
    }

    public function testPostgreSQLAdapterNullByDefault(): void
    {
        $this->assertNull($this->pgsql->getAdapter());
    }

    // ── lastInsertId (no adapter) ────────────────────────────

    public function testLastInsertIdZeroWithoutAdapter(): void
    {
        $this->assertSame(0, $this->mysql->lastInsertId());
        $this->assertSame(0, $this->sqlite->lastInsertId());
        $this->assertSame(0, $this->pgsql->lastInsertId());
    }

    // ── getConnectionOptions ─────────────────────────────────

    public function testMySQLConnectionOptions(): void
    {
        $opts = $this->mysql->getConnectionOptions();
        $this->assertSame(PDO::ERRMODE_EXCEPTION, $opts[PDO::ATTR_ERRMODE]);
        $this->assertTrue($opts[PDO::ATTR_PERSISTENT]);
        $this->assertSame(5, $opts[PDO::ATTR_TIMEOUT]);
        $this->assertTrue($opts[PDO::MYSQL_ATTR_FOUND_ROWS]);
    }

    public function testSQLiteConnectionOptions(): void
    {
        $opts = $this->sqlite->getConnectionOptions();
        $this->assertSame(PDO::ERRMODE_EXCEPTION, $opts[PDO::ATTR_ERRMODE]);
        $this->assertSame(PDO::FETCH_ASSOC, $opts[PDO::ATTR_DEFAULT_FETCH_MODE]);
        // SQLite should NOT have persistent connections
        $this->assertArrayNotHasKey(PDO::ATTR_PERSISTENT, $opts);
    }

    public function testPostgreSQLConnectionOptions(): void
    {
        $opts = $this->pgsql->getConnectionOptions();
        $this->assertSame(PDO::ERRMODE_EXCEPTION, $opts[PDO::ATTR_ERRMODE]);
        $this->assertTrue($opts[PDO::ATTR_PERSISTENT]);
        $this->assertSame(5, $opts[PDO::ATTR_TIMEOUT]);
    }

    // ── quoteIdentifier ──────────────────────────────────────

    public function testMySQLQuoteIdentifierSimple(): void
    {
        $this->assertSame('`users`', $this->mysql->quoteIdentifier('users'));
    }

    public function testMySQLQuoteIdentifierEscapesBackticks(): void
    {
        $this->assertSame('`col``name`', $this->mysql->quoteIdentifier('col`name'));
    }

    public function testSQLiteQuoteIdentifierSimple(): void
    {
        $this->assertSame('"users"', $this->sqlite->quoteIdentifier('users'));
    }

    public function testSQLiteQuoteIdentifierEscapesDoubleQuotes(): void
    {
        $this->assertSame('"col""name"', $this->sqlite->quoteIdentifier('col"name'));
    }

    public function testPostgreSQLQuoteIdentifierSimple(): void
    {
        $this->assertSame('"users"', $this->pgsql->quoteIdentifier('users'));
    }

    public function testPostgreSQLQuoteIdentifierEscapesDoubleQuotes(): void
    {
        $this->assertSame('"col""name"', $this->pgsql->quoteIdentifier('col"name'));
    }

    public function testQuoteIdentifierEmptyString(): void
    {
        $this->assertSame('``', $this->mysql->quoteIdentifier(''));
        $this->assertSame('""', $this->sqlite->quoteIdentifier(''));
        $this->assertSame('""', $this->pgsql->quoteIdentifier(''));
    }

    // ── getLimitSyntax ───────────────────────────────────────

    public function testMySQLLimitBothZero(): void
    {
        $this->assertSame('', $this->mysql->getLimitSyntax(0, 0));
    }

    public function testMySQLLimitPositionOnlyNoLength(): void
    {
        // position=5, length=0 → single LIMIT
        $this->assertSame(' LIMIT 5', $this->mysql->getLimitSyntax(5, 0));
    }

    public function testMySQLLimitWithLength(): void
    {
        // MySQL: LIMIT offset, count
        $this->assertSame(' LIMIT 0, 10', $this->mysql->getLimitSyntax(0, 10));
    }

    public function testMySQLLimitPaginated(): void
    {
        $this->assertSame(' LIMIT 20, 10', $this->mysql->getLimitSyntax(20, 10));
    }

    public function testSQLiteLimitBothZero(): void
    {
        $this->assertSame('', $this->sqlite->getLimitSyntax(0, 0));
    }

    public function testSQLiteLimitPositionOnlyNoLength(): void
    {
        $this->assertSame(' LIMIT 5', $this->sqlite->getLimitSyntax(5, 0));
    }

    public function testSQLiteLimitWithLength(): void
    {
        // SQLite: LIMIT count OFFSET position
        $this->assertSame(' LIMIT 10 OFFSET 0', $this->sqlite->getLimitSyntax(0, 10));
    }

    public function testSQLiteLimitPaginated(): void
    {
        $this->assertSame(' LIMIT 10 OFFSET 20', $this->sqlite->getLimitSyntax(20, 10));
    }

    public function testPostgreSQLLimitBothZero(): void
    {
        $this->assertSame('', $this->pgsql->getLimitSyntax(0, 0));
    }

    public function testPostgreSQLLimitPositionOnlyNoLength(): void
    {
        $this->assertSame(' LIMIT 5', $this->pgsql->getLimitSyntax(5, 0));
    }

    public function testPostgreSQLLimitWithLength(): void
    {
        // PostgreSQL: LIMIT count OFFSET position
        $this->assertSame(' LIMIT 10 OFFSET 0', $this->pgsql->getLimitSyntax(0, 10));
    }

    public function testPostgreSQLLimitPaginated(): void
    {
        $this->assertSame(' LIMIT 10 OFFSET 20', $this->pgsql->getLimitSyntax(20, 10));
    }

    // ── getAutoIncrementSyntax ───────────────────────────────

    public function testMySQLAutoIncrement(): void
    {
        $this->assertSame('INT(11) NOT NULL AUTO_INCREMENT', $this->mysql->getAutoIncrementSyntax(11));
    }

    public function testMySQLAutoIncrementSmall(): void
    {
        $this->assertSame('INT(4) NOT NULL AUTO_INCREMENT', $this->mysql->getAutoIncrementSyntax(4));
    }

    public function testSQLiteAutoIncrement(): void
    {
        // SQLite ignores length — always returns the same string
        $this->assertSame('INTEGER PRIMARY KEY AUTOINCREMENT', $this->sqlite->getAutoIncrementSyntax(11));
        $this->assertSame('INTEGER PRIMARY KEY AUTOINCREMENT', $this->sqlite->getAutoIncrementSyntax(4));
    }

    public function testPostgreSQLAutoIncrementSmall(): void
    {
        // Length <= 4 → SERIAL
        $this->assertSame('SERIAL PRIMARY KEY', $this->pgsql->getAutoIncrementSyntax(4));
    }

    public function testPostgreSQLAutoIncrementLarge(): void
    {
        // Length > 4 → BIGSERIAL
        $this->assertSame('BIGSERIAL PRIMARY KEY', $this->pgsql->getAutoIncrementSyntax(11));
    }

    public function testPostgreSQLAutoIncrementBoundary(): void
    {
        // Exactly 4 → SERIAL; 5 → BIGSERIAL
        $this->assertSame('SERIAL PRIMARY KEY', $this->pgsql->getAutoIncrementSyntax(4));
        $this->assertSame('BIGSERIAL PRIMARY KEY', $this->pgsql->getAutoIncrementSyntax(5));
    }

    // ── getConcatSyntax ──────────────────────────────────────

    public function testMySQLConcatSingle(): void
    {
        $this->assertSame('CONCAT(a)', $this->mysql->getConcatSyntax(['a']));
    }

    public function testMySQLConcatMultiple(): void
    {
        $this->assertSame('CONCAT(a, b, c)', $this->mysql->getConcatSyntax(['a', 'b', 'c']));
    }

    public function testSQLiteConcatSingle(): void
    {
        $this->assertSame('(a)', $this->sqlite->getConcatSyntax(['a']));
    }

    public function testSQLiteConcatMultiple(): void
    {
        $this->assertSame('(a || b || c)', $this->sqlite->getConcatSyntax(['a', 'b', 'c']));
    }

    public function testPostgreSQLConcatSingle(): void
    {
        $this->assertSame('(a)', $this->pgsql->getConcatSyntax(['a']));
    }

    public function testPostgreSQLConcatMultiple(): void
    {
        $this->assertSame('(a || b || c)', $this->pgsql->getConcatSyntax(['a', 'b', 'c']));
    }

    // ── getUpsertSyntax ──────────────────────────────────────

    private function valueGetter(): \Closure
    {
        return fn(string $column): string => ':' . $column;
    }

    public function testMySQLUpsertSimpleInsert(): void
    {
        $sql = $this->mysql->getUpsertSyntax(
            'users',
            ['name', 'email'],
            [],
            $this->valueGetter()
        );
        $this->assertSame(
            'INSERT INTO users (`name`, `email`) VALUES (:name, :email)',
            $sql
        );
    }

    public function testMySQLUpsertWithDuplicateKeys(): void
    {
        $sql = $this->mysql->getUpsertSyntax(
            'users',
            ['id', 'name', 'email'],
            ['name', 'email'],
            $this->valueGetter()
        );
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
        $this->assertStringContainsString('`name` = :name', $sql);
        $this->assertStringContainsString('`email` = :email', $sql);
    }

    public function testSQLiteUpsertSimpleInsert(): void
    {
        $sql = $this->sqlite->getUpsertSyntax(
            'users',
            ['name', 'email'],
            [],
            $this->valueGetter()
        );
        $this->assertSame(
            'INSERT INTO users ("name", "email") VALUES (:name, :email)',
            $sql
        );
    }

    public function testSQLiteUpsertWithDuplicateKeys(): void
    {
        $sql = $this->sqlite->getUpsertSyntax(
            'users',
            ['id', 'name', 'email'],
            ['id'],
            $this->valueGetter()
        );
        $this->assertStringContainsString('ON CONFLICT("id")', $sql);
        $this->assertStringContainsString('DO UPDATE SET', $sql);
        $this->assertStringContainsString('"name" = excluded."name"', $sql);
        $this->assertStringContainsString('"email" = excluded."email"', $sql);
    }

    public function testSQLiteUpsertAllColumnsDuplicate(): void
    {
        // When all columns are in duplicateKeys, nothing to update → DO NOTHING
        $sql = $this->sqlite->getUpsertSyntax(
            'users',
            ['id', 'name'],
            ['id', 'name'],
            $this->valueGetter()
        );
        $this->assertStringContainsString('DO NOTHING', $sql);
    }

    public function testPostgreSQLUpsertSimpleInsert(): void
    {
        $sql = $this->pgsql->getUpsertSyntax(
            'users',
            ['name', 'email'],
            [],
            $this->valueGetter()
        );
        $this->assertSame(
            'INSERT INTO users ("name", "email") VALUES (:name, :email)',
            $sql
        );
    }

    public function testPostgreSQLUpsertWithDuplicateKeys(): void
    {
        $sql = $this->pgsql->getUpsertSyntax(
            'users',
            ['id', 'name', 'email'],
            ['id'],
            $this->valueGetter()
        );
        $this->assertStringContainsString('ON CONFLICT ("id")', $sql);
        $this->assertStringContainsString('DO UPDATE SET', $sql);
        $this->assertStringContainsString('"id" = EXCLUDED."id"', $sql);
    }

    // ── SQLite-specific ──────────────────────────────────────

    public function testSQLiteGetDatabasePathEmpty(): void
    {
        $this->assertSame('', $this->sqlite->getDatabasePath());
    }

    public function testSQLiteGetCharsetHardcoded(): void
    {
        $charsets = $this->sqlite->getCharset();
        $this->assertArrayHasKey('UTF-8', $charsets);
        $this->assertSame('UTF-8', $charsets['UTF-8']['default']);
    }

    public function testSQLiteGetCollationHardcoded(): void
    {
        $collation = $this->sqlite->getCollation('any');
        $this->assertSame(['BINARY', 'NOCASE', 'RTRIM'], $collation);
    }

    public function testSQLiteSetTimezoneNoOp(): void
    {
        // Should not throw — SQLite ignores timezone setting
        $this->sqlite->setTimezone('+08:00');
        $this->assertTrue(true); // No exception = pass
    }

    // ── SQLite :memory: integration ──────────────────────────

    public function testSQLiteConnectMemory(): void
    {
        $driver = new SQLite();
        $result = $driver->connect(['database' => ':memory:']);
        $this->assertTrue($result);
        $this->assertTrue($driver->isConnected());
        $this->assertInstanceOf(PDO::class, $driver->getAdapter());
        $this->assertSame(':memory:', $driver->getDatabasePath());
    }

    public function testSQLiteTableExistsAfterCreate(): void
    {
        $driver = new SQLite();
        $driver->connect(['database' => ':memory:']);
        $this->assertFalse($driver->tableExists('test_table'));
        $driver->getAdapter()->exec('CREATE TABLE test_table (id INTEGER PRIMARY KEY, name TEXT)');
        $this->assertTrue($driver->tableExists('test_table'));
    }

    public function testSQLiteLastInsertIdAfterInsert(): void
    {
        $driver = new SQLite();
        $driver->connect(['database' => ':memory:']);
        $driver->getAdapter()->exec('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, val TEXT)');
        $driver->getAdapter()->exec("INSERT INTO items (val) VALUES ('hello')");
        $this->assertSame(1, $driver->lastInsertId());
        $driver->getAdapter()->exec("INSERT INTO items (val) VALUES ('world')");
        $this->assertSame(2, $driver->lastInsertId());
    }

    // ── MySQL connect failure (no server) ────────────────────

    public function testMySQLConnectFailure(): void
    {
        $result = $this->mysql->connect([
            'host' => '255.255.255.255',
            'database' => 'nonexistent',
            'username' => 'nobody',
            'password' => 'wrong',
            'port' => 1,
        ]);
        $this->assertFalse($result);
        $this->assertFalse($this->mysql->isConnected());
    }

    // ── Driver is abstract ───────────────────────────────────

    public function testDriverIsAbstract(): void
    {
        $reflection = new \ReflectionClass(Driver::class);
        $this->assertTrue($reflection->isAbstract());
    }

    public function testDriverAbstractMethods(): void
    {
        $reflection = new \ReflectionClass(Driver::class);
        $abstractMethods = array_filter(
            $reflection->getMethods(),
            fn(\ReflectionMethod $m) => $m->isAbstract()
        );
        $names = array_map(fn(\ReflectionMethod $m) => $m->getName(), $abstractMethods);
        $this->assertContains('getType', $names);
        $this->assertContains('connect', $names);
        $this->assertContains('getLimitSyntax', $names);
        $this->assertContains('getAutoIncrementSyntax', $names);
        $this->assertContains('getUpsertSyntax', $names);
        $this->assertContains('getConcatSyntax', $names);
        $this->assertContains('tableExists', $names);
    }
}
