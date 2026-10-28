<?php

/**
 * RZ-02 — real parameter binding (regression pins).
 *
 * Before RZ-02 every value was interpolated into the SQL text with
 * PDO::quote() (or a MySQL-only backslash escape for JSON operands), booleans
 * became `1` on every driver (Postgres rejects that for a boolean column), and
 * the statement pool was keyed on literal SQL so it never hit across values.
 *
 * Postgres cases run when RAZY_TEST_PGSQL_HOST is set (CI has a pg service);
 * otherwise they are skipped.
 *
 * This file is part of Razy.
 *
 * @license MIT
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Database;
use Razy\Database\ParameterBinder;
use Razy\Database\Statement;

#[CoversClass(Statement::class)]
#[CoversClass(ParameterBinder::class)]
class ParameterBindingTest extends TestCase
{
    private const NASTY = "O'Brien \\' OR 1=1 -- \"x\" %_";

    private Database $db;

    protected function setUp(): void
    {
        $this->db = new Database('test_param_binding');
        $this->db->connectWithDriver('sqlite', ['path' => ':memory:']);
        $this->db->getDBAdapter()?->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT, flag INTEGER, meta TEXT)');
    }

    // -- compile() ---------------------------------------------------------

    public function testInsertCompilesToPlaceholders(): void
    {
        $stmt = $this->db->insert('t', ['name', 'flag'])->assign(['name' => self::NASTY, 'flag' => true]);
        $compiled = $stmt->compile();

        $this->assertSame(['sql', 'params'], \array_keys($compiled));
        $this->assertStringNotContainsString('Brien', $compiled['sql'], 'values must not be interpolated');
        $this->assertContains(self::NASTY, $compiled['params']);
        $this->assertContains(true, $compiled['params'], 'booleans are bound as booleans');
        $this->assertSame(\count($compiled['params']), \preg_match_all('/:rzp\d+/', $compiled['sql']));
    }

    public function testWhereCompilesToPlaceholders(): void
    {
        $stmt = $this->db->prepare()->from('t')->where('name=:name,meta*=:like,id|=:ids')->assign([
            'name' => self::NASTY,
            'like' => "a'b",
            'ids' => [1, 'x\'y'],
        ]);
        $compiled = $stmt->compile();

        $this->assertStringNotContainsString('Brien', $compiled['sql']);
        $this->assertStringNotContainsString("a'b", $compiled['sql']);
        $this->assertStringNotContainsString("x'y", $compiled['sql']);
        $this->assertContains(self::NASTY, $compiled['params']);
        $this->assertContains('%a\'b%', $compiled['params']);
        $this->assertContains("x'y", $compiled['params']);
        // Every placeholder in the SQL has a value and vice versa (PDO rejects mismatches)
        \preg_match_all('/:(rzp\d+)/', $compiled['sql'], $m);
        $this->assertEqualsCanonicalizing($m[1], \array_keys($compiled['params']));
    }

    public function testUpdateCompilesToPlaceholders(): void
    {
        $compiled = $this->db->update('t', ['name', 'meta=:meta'])->where('id=:id')
            ->assign(['name' => self::NASTY, 'meta' => 'm\'', 'id' => 3])->compile();

        $this->assertStringNotContainsString('Brien', $compiled['sql']);
        $this->assertContains(self::NASTY, $compiled['params']);
        $this->assertContains("m'", $compiled['params']);
    }

    public function testGetSyntaxStillReturnsLiteralSql(): void
    {
        $sql = $this->db->insert('t', ['name'])->assign(['name' => "a'b"])->getSyntax();
        $this->assertStringContainsString("'a''b'", $sql, 'getSyntax() keeps the debug literal form');
        $this->assertStringNotContainsString(':rzp', $sql);
    }

    public function testTextLiteralInWhereIsBound(): void
    {
        $compiled = $this->db->prepare()->from('t')->where('name="it\'s"')->compile();
        $this->assertStringNotContainsString("it's", $compiled['sql']);
        $this->assertContains("it's", $compiled['params']);
    }

    // -- execution ----------------------------------------------------------

    public function testRoundTripOnSqlite(): void
    {
        $this->db->insert('t', ['name', 'flag', 'meta'])->assign(['name' => self::NASTY, 'flag' => false, 'meta' => ['k' => "v'"]])->query();
        $row = $this->db->prepare()->from('t')->where('name=:name')->assign(['name' => self::NASTY])->query()->fetch();

        $this->assertIsArray($row);
        $this->assertSame(self::NASTY, $row['name']);
        $this->assertSame(0, (int) $row['flag']);
        $this->assertSame(['k' => "v'"], \json_decode((string) $row['meta'], true));
    }

    public function testQueryHistoryShowsDebugLiterals(): void
    {
        $this->db->prepare()->from('t')->where('name=:name')->assign(['name' => "a'b"])->query();
        $this->assertStringContainsString("'a''b'", $this->db->getLastQueried());
    }

    public function testStatementPoolIsKeyedOnTemplate(): void
    {
        foreach (['a', 'b', 'c'] as $name) {
            $this->db->prepare()->from('t')->where('name=:name')->assign(['name' => $name])->query();
        }
        $this->assertSame(1, $this->db->getStatementPool()?->getPoolSize());
    }

    public function testPooledTemplateDoesNotClobberAnOpenResultSet(): void
    {
        foreach (['a', 'b', 'c'] as $name) {
            $this->db->insert('t', ['name'])->assign(['name' => $name])->query();
        }

        // Outer cursor over all rows; an inner query with the SAME template runs
        // while the outer one is still being read (classic N+1 loop).
        $outer = $this->db->prepare()->from('t')->where('name!=:skip')->assign(['skip' => 'none'])->query();
        $seen = [];
        while ($row = $outer->fetch()) {
            $inner = $this->db->prepare()->from('t')->where('name!=:skip')->assign(['skip' => $row['name']])->query()->fetchAll();
            $this->assertCount(2, $inner);
            $seen[] = $row['name'];
        }

        $this->assertSame(['a', 'b', 'c'], $seen, 'the outer result set must not be reset by the pooled inner query');
    }

    public function testJsonEncodeFailureIsAnError(): void
    {
        $this->expectException(\Razy\Exception\QueryException::class);
        $this->db->insert('t', ['meta'])->assign(['meta' => ['bad' => "\xB1\x31"]])->compile();
    }

    public function testJsonEncodeFailureInWhereIsAnError(): void
    {
        $this->expectException(\Razy\Exception\QueryException::class);
        $this->db->prepare()->from('t')->where('meta~=:v')->assign(['v' => ['bad' => "\xB1\x31"]])->compile();
    }

    // -- Postgres ------------------------------------------------------------

    public function testPostgresBooleanAndStringRoundTrip(): void
    {
        $db = $this->pg();
        $db->getDBAdapter()?->exec('DROP TABLE IF EXISTS rz_bind; CREATE TABLE rz_bind (id SERIAL PRIMARY KEY, name TEXT, is_admin BOOLEAN NOT NULL, meta JSONB)');

        $db->insert('rz_bind', ['name', 'is_admin', 'meta'])->assign(['name' => self::NASTY, 'is_admin' => true, 'meta' => ['a' => "b'"]])->query();
        $db->insert('rz_bind', ['name', 'is_admin', 'meta'])->assign(['name' => 'plain', 'is_admin' => false, 'meta' => []])->query();

        $rows = $db->prepare()->from('rz_bind')->where('is_admin=:admin,name=:name')
            ->assign(['admin' => true, 'name' => self::NASTY])->query()->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertSame(self::NASTY, $rows[0]['name']);
        $this->assertTrue((bool) $rows[0]['is_admin']);

        $like = $db->prepare()->from('rz_bind')->where('name*=:q')->assign(['q' => "Brien \\'"])->query()->fetchAll();
        $this->assertCount(1, $like);

        $db->update('rz_bind', ['is_admin'])->where('name=:name')->assign(['is_admin' => true, 'name' => 'plain'])->query();
        $count = $db->prepare()->select('COUNT(*) AS c')->from('rz_bind')->where('is_admin=:a')->assign(['a' => true])->query()->fetch();
        $this->assertSame(2, (int) ($count['c'] ?? 0));

        $db->getDBAdapter()?->exec('DROP TABLE rz_bind');
    }

    public function testPostgresJsonPathCannotCloseItsLiteral(): void
    {
        // RZ-41: the JSON path was appended as written. Postgres does not treat a
        // backslash as an escape, so this payload closed the literal and the DELETE
        // ran with `OR 1=1`.
        $db = $this->pg();
        $db->getDBAdapter()?->exec('DROP TABLE IF EXISTS rz_jp; CREATE TABLE rz_jp (id INT, meta JSONB)');
        $db->getDBAdapter()?->exec('INSERT INTO rz_jp VALUES (1, \'{"a":1}\'), (2, \'{"a":2}\')');

        try {
            $db->delete('rz_jp', ['id' => 1], "id->'$.a\\' OR 1=1 --'")->query();
            $this->fail('expected InvalidOperandException');
        } catch (\Razy\Exception\InvalidOperandException) {
        }
        $count = $db->prepare()->select('COUNT(*) AS c')->from('rz_jp')->query()->fetch();
        $this->assertSame(2, (int) ($count['c'] ?? 0));

        // A plain path is re-quoted by the driver, never pasted.
        $sql = $db->prepare()->select('id')->from('rz_jp')->where("meta->>'$.a'=:v")->assign(['v' => '1'])->getSyntax();
        $this->assertStringContainsString('"meta"->>\'$.a\'', $sql);

        $db->getDBAdapter()?->exec('DROP TABLE rz_jp');
    }

    public function testPostgresDebugLiteralForBoolean(): void
    {
        $db = $this->pg();
        $sql = $db->insert('x', ['flag'])->assign(['flag' => true])->getSyntax();
        $this->assertStringContainsString('TRUE', $sql);
        $this->assertStringNotContainsString('(1)', $sql);
    }

    private function pg(): Database
    {
        $host = \getenv('RAZY_TEST_PGSQL_HOST');
        if (!$host) {
            $this->markTestSkipped('RAZY_TEST_PGSQL_HOST not set');
        }
        $db = new Database('test_param_binding_pg');
        $ok = $db->connectWithDriver('pgsql', [
            'host' => $host,
            'port' => (int) (\getenv('RAZY_TEST_PGSQL_PORT') ?: 5432),
            'database' => \getenv('RAZY_TEST_PGSQL_DB') ?: 'razy_test',
            'username' => \getenv('RAZY_TEST_PGSQL_USER') ?: 'razy',
            'password' => \getenv('RAZY_TEST_PGSQL_PASSWORD') ?: 'razy',
        ]);
        $this->assertTrue($ok, 'Postgres connection failed');

        return $db;
    }
}
