<?php

/**
 * RZ-41 — an unrecognised Where Simple Syntax operand is refused, not pasted
 * into the SQL.
 *
 * Before RZ-41 any operand that was not a column, placeholder, quoted text,
 * array literal or NULL fell back to a raw expression and went into the SQL
 * text verbatim (`id=SLEEP(5)`, `a=b+1`, `meta~=x`). The forms that reached
 * that branch legitimately keep working, each pinned below.
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
use Razy\Database\WhereSyntax;
use Razy\Exception\InvalidOperandException;
use Razy\Exception\QueryException;

#[CoversClass(WhereSyntax::class)]
#[CoversClass(InvalidOperandException::class)]
class WhereSyntaxOperandTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = new Database('test_where_operand');
        $this->db->connectWithDriver('sqlite', ['path' => ':memory:']);
        $this->db->getDBAdapter()?->exec('CREATE TABLE t (id INTEGER, price REAL, email TEXT, meta TEXT, created TEXT)');
        $this->db->getDBAdapter()?->exec("INSERT INTO t VALUES (1, 9.5, 'a@gmail.com', '{\"k\":1}', '2000-01-01'), (2, 120, 'b@x.org', '{}', '2999-01-01')");
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function keptForms(): array
    {
        return [
            'integer' => ['id=1', '"id" = 1'],
            'constant condition' => ['1=1', '1 = 1'],
            'negative' => ['id>-1', '"id" > -1'],
            'decimal' => ['price<=100.00', '"price" <= 100.00'],
            'leading dot' => ['price>.5', '"price" > .5'],
            'exponent' => ['price<1e3', '"price" < 1e3'],
            'bare upper-case column' => ['UserId=1', 'UserId = 1'],
            'bare qualified identifier' => ['T.b=1', 'T.b = 1'],
            'keyword' => ['TRUE', 'TRUE'],
            'single number' => ['1', '1'],
            'zero-arg function' => ['created>NOW()', '"created" > NOW()'],
            'function any case' => ['created>now()', '"created" > now()'],
            'niladic keyword' => ['created<CURRENT_TIMESTAMP', '"created" < CURRENT_TIMESTAMP'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function refusedForms(): array
    {
        return [
            'arithmetic' => ['a=b+1', 'b+1'],
            'function with arguments' => ['id=SLEEP(5)', 'SLEEP(5)'],
            'unlisted zero-arg function' => ['id=VERSION()', 'VERSION()'],
            'session variable' => ['email=@x', '@x'],
            'hex literal' => ['id=0x1F', '0x1F'],
            'comment' => ['id=1--', '1--'],
            'raw single operand' => ['id+1', 'id+1'],
            'raw left of LIKE' => ['@gmail.com*=email', '@gmail.com'],
            'raw REGEXP pattern' => ['email#=^a.*', '^a.*'],
            'raw JSON_CONTAINS operand' => ['meta~=foo-bar', 'foo-bar'],
            'raw JSON_SEARCH operand' => ['meta&=x y', 'x y'],
            'raw IN operand' => ['id|=a+b', 'a+b'],
            'raw BETWEEN operand' => ['price><a+b', 'a+b'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function escapedQuoteLiterals(): array
    {
        return [
            'doubled single quote' => ["email='O''Brien'", "O'Brien"],
            'doubled double quote' => ['email="say ""hi"""', 'say "hi"'],
            'backslash-escaped quote' => ["email='O\\'Brien'", "O'Brien"],
            'only a quote' => ["email=''''", "'"],
            'injection text stays a value' => ["email='x'' OR 1=1 --'", "x' OR 1=1 --"],
            'plain backslash unchanged' => ["email='a\\b'", 'a\b'],
            'two backslash-escaped quotes' => ["email='a\\'\\'b'", "a''b"],
            'two doubled quotes' => ["email='a''''b'", "a''b"],
            'escaped then doubled' => ["email='\\''''", "''"],
        ];
    }

    #[DataProvider('keptForms')]
    public function testLegitimateRawFormsStillCompile(string $syntax, string $sql): void
    {
        $this->assertSame('SELECT * FROM "t" WHERE ' . $sql, $this->sql($syntax));
    }

    public function testNiladicKeywordRunsOnTheDatabase(): void
    {
        $rows = $this->db->prepare()->select('id')->from('t')->where('created<CURRENT_TIMESTAMP')->query()->fetchAll();
        $this->assertSame([['id' => 1]], $rows);
    }

    public function testOrderByAllowListedFunctionStillWorks(): void
    {
        $stmt = $this->db->prepare()->select('*')->from('t')->order('>RAND()');
        $this->assertSame('SELECT * FROM "t" ORDER BY RAND() DESC', $stmt->getSyntax());
    }

    #[DataProvider('escapedQuoteLiterals')]
    public function testQuotedLiteralWithEscapedQuoteIsBound(string $syntax, string $value): void
    {
        // These reached the raw fallback before RZ-41; now they are decoded and bound.
        $compiled = $this->db->prepare()->select('*')->from('t')->where($syntax)->compile();
        $this->assertSame('SELECT * FROM "t" WHERE "email" = :rzp0', $compiled['sql']);
        $this->assertSame(['rzp0' => $value], $compiled['params']);
    }

    public function testUnterminatedQuotedLiteralIsRefused(): void
    {
        $this->expectException(InvalidOperandException::class);
        $this->sql("email='a''");
    }

    public function testPatternTextIsStillBoundAsAValue(): void
    {
        // A LIKE / @= right side never went into the SQL raw: it was always bound.
        $like = $this->db->prepare()->select('email')->from('t')->where('email*=@gmail');
        $compiled = $like->compile();
        $this->assertSame('SELECT "email" FROM "t" WHERE "email" LIKE :rzp0 ESCAPE \'\\\'', $compiled['sql']);
        $this->assertSame(['rzp0' => '%@gmail%'], $compiled['params']);
        $this->assertSame([['email' => 'a@gmail.com']], $like->query()->fetchAll());

        $keys = $this->db->prepare()->select('*')->from('t')->where('meta@=foo-bar')->compile();
        $this->assertSame(['rzp0' => 'foo-bar'], $keys['params']);
    }

    #[DataProvider('refusedForms')]
    public function testUnrecognisedOperandIsRefused(string $syntax, string $operand): void
    {
        try {
            $this->sql($syntax);
            $this->fail('expected InvalidOperandException for ' . $syntax);
        } catch (InvalidOperandException $e) {
            $this->assertSame($operand, $e->getOperand());
            $this->assertStringNotContainsString($operand, $e->getMessage(), 'operand stays out of the message');
            $this->assertInstanceOf(QueryException::class, $e, 'existing QueryException handlers still catch it');
        }
    }

    public function testOrderByExpressionIsRefused(): void
    {
        $this->expectException(InvalidOperandException::class);
        $this->db->prepare()->select('*')->from('t')->order('>id+1')->getSyntax();
    }

    public function testStrayClosingParenthesisIsRefusedNotDropped(): void
    {
        // SimpleSyntax::parseParens() discards everything at a stray top-level ')'.
        // Before RZ-41 the whole WHERE vanished, so this DELETE removed every row.
        try {
            $this->db->delete('t', ['id' => 1], 'id=:id)--')->query();
            $this->fail('expected QueryException');
        } catch (QueryException $e) {
            $this->assertStringContainsString('unbalanced', $e->getMessage());
        }
        $this->assertSame([['n' => 2]], $this->db->prepare()->select('COUNT(*) AS n')->from('t')->query()->fetchAll());

        $this->expectException(QueryException::class);
        $this->sql('id=1),(1=1');
    }

    public function testGroupGluedToAnOperandIsAQueryException(): void
    {
        // Was a TypeError from preg_match() on the nested group.
        $this->expectException(QueryException::class);
        $this->sql('id=NOW()(SLEEP(5))');
    }

    private function sql(string $syntax): string
    {
        return $this->db->prepare()->select('*')->from('t')->where($syntax)->getSyntax();
    }
}
