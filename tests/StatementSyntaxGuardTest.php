<?php

/**
 * RZ-41 — JOIN USING columns and UPDATE SET tokens never reach the SQL as written.
 *
 * Each refusal below reproduces on master, where the input was concatenated into
 * the statement: `[:id); DROP TABLE t--]` into USING, and any Update Simple Syntax
 * token that was not `?` or `:name` (a subquery, `SLEEP(5)`) into SET.
 *
 * This file is part of Razy.
 *
 * @license MIT
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Database;
use Razy\Database\Statement\UpdateSyntaxBuilder;
use Razy\Database\TableJoinSyntax;
use Razy\Exception\InvalidOperandException;
use Razy\Exception\QueryException;

#[CoversClass(TableJoinSyntax::class)]
#[CoversClass(UpdateSyntaxBuilder::class)]
class StatementSyntaxGuardTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = new Database('test_statement_guard');
        $this->db->connectWithDriver('sqlite', ['path' => ':memory:']);
        $this->db->getDBAdapter()?->exec("CREATE TABLE t (id INTEGER, n INTEGER, name TEXT, role TEXT); INSERT INTO t VALUES (1, 5, 'a', 'user'), (2, 7, 'b', 'admin')");
    }

    // -- UPDATE ... SET -----------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function keptUpdateForms(): array
    {
        return [
            'bind by name' => ['name', '"name" = :rzp0'],
            'increment' => ['n++', '"n" = "n" + 1'],
            'compound' => ['n*=2', '"n" = "n" * 2'],
            'arithmetic on a column' => ['n=n+1', '"n" = n + 1'],
            'allow-listed function' => ['name=NOW()', '"name" = NOW()'],
            'quoted text is bound' => ["name='O''Brien'", '"name" = :rzp0'],
            'group' => ['n=(n+1)*2', '"n" = (n + 1) * 2'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusedUpdateTokens(): array
    {
        return [
            'subquery' => ['name=(SELECT group_concat(role) FROM t)'],
            'function with arguments' => ['n=SLEEP(5)'],
            'brace expression' => ['name={{NOW()}}'],
            'unquoted text' => ['name=a@b.c'],
        ];
    }

    // -- JOIN ... USING -----------------------------------------------------

    public function testUsingColumnsAreQuoted(): void
    {
        $this->assertSame(
            'SELECT * FROM "t" AS "a" JOIN "t" AS "b" USING ("id", "n")',
            $this->db->prepare()->select('*')->from('a.t-b.t[:id,n]')->getSyntax(),
        );
    }

    public function testUsingRefusesAnythingButAColumn(): void
    {
        $this->expectException(QueryException::class);
        $this->db->prepare()->select('*')->from('a.t-b.t[:id); DROP TABLE t--]')->getSyntax();
    }

    #[DataProvider('keptUpdateForms')]
    public function testDocumentedUpdateFormsStillCompile(string $syntax, string $set): void
    {
        $compiled = $this->db->update('t', [$syntax])->where('id=1')->assign(['name' => 'x'])->compile();
        $this->assertSame('UPDATE t SET ' . $set . ' WHERE "id" = 1', $compiled['sql']);
    }

    public function testIncrementStillChangesTheValue(): void
    {
        $this->db->update('t', ['n++'])->where('id=1')->query();
        $this->assertSame([['n' => 6]], $this->db->prepare()->select('n')->from('t')->where('id=1')->query()->fetchAll());
    }

    #[DataProvider('refusedUpdateTokens')]
    public function testRawUpdateTokenIsRefused(string $syntax): void
    {
        try {
            $this->db->update('t', [$syntax])->where('id=1')->query();
            $this->fail('expected InvalidOperandException for ' . $syntax);
        } catch (InvalidOperandException) {
        }
        $this->assertSame([['name' => 'a']], $this->db->prepare()->select('name')->from('t')->where('id=1')->query()->fetchAll());
    }

    public function testUnparseableUpdateEntryIsAnError(): void
    {
        // Was skipped silently, so the UPDATE ran without that column.
        $this->expectException(QueryException::class);
        $this->db->update('t', ['name', 'role; x']);
    }
}
