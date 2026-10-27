<?php

declare(strict_types=1);

namespace Razy\Tests;

use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use razymod\apps\Caps;
use razymod\apps\Expression\ArrayValues;
use razymod\apps\Expression\Context;
use razymod\apps\Expression\Decimal;
use razymod\apps\Expression\Evaluator;
use razymod\apps\Expression\ExpressionError;
use razymod\apps\Expression\Functions;
use razymod\apps\Expression\MapScope;
use razymod\apps\Expression\Parser;
use razymod\apps\Expression\TypeChecker;
use razymod\apps\Expression\Values;

require_once __DIR__ . '/fixtures/apps/autoload.php';

/**
 * razymod/apps expressions (manual/15-razy-apps.md §5): the Excel-like
 * parser, the typed AST, the closed function list and the budgeted
 * evaluator, including the blank semantics and every typed error.
 */
#[CoversNothing]
class AppsExpressionTest extends TestCase
{
    private MapScope $scope;

    protected function setUp(): void
    {
        $this->scope = new MapScope([
            'amount' => 'decimal',
            'qty' => 'decimal',
            'ratio' => 'number',
            'title' => 'text',
            'done' => 'boolean',
            'due' => 'date',
            'seen' => 'datetime',
            'owner' => 'user',
            'department' => 'department',
            'tags' => ['type' => 'list:text', 'options' => ['vip', 'new']],
            'stage' => ['type' => 'status', 'options' => ['lead', 'won', 'lost']],
            'stage.probability' => 'decimal',
            'stage.category' => ['type' => 'text', 'options' => ['open', 'active', 'done', 'cancelled']],
            'currency' => ['type' => 'text', 'options' => ['HKD', 'USD']],
            'company' => 'ref:crm-core.company',
            'company.name' => 'text',
            'preview' => ['type' => 'decimal', 'sql' => false],
            'file' => 'opaque',
        ]);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function syntaxErrors(): iterable
    {
        yield 'empty' => ['', ExpressionError::ENCODING];
        yield 'bad utf-8' => ["=\xff", ExpressionError::ENCODING];
        yield 'dangling op' => ['=1 +', ExpressionError::SYNTAX];
        yield 'unclosed paren' => ['=(1 + 2', ExpressionError::SYNTAX];
        yield 'unterminated string' => ["='abc", ExpressionError::SYNTAX];
        yield 'chained comparison' => ['=1 < 2 < 3', ExpressionError::SYNTAX];
        yield 'upper-case field' => ['=Amount', ExpressionError::SYNTAX];
        yield 'php variable' => ['=$x', ExpressionError::SYNTAX];
        yield 'backtick' => ['=`ls`', ExpressionError::SYNTAX];
        yield 'semicolon' => ['=1; 2', ExpressionError::SYNTAX];
        yield 'brackets' => ['=amount[0]', ExpressionError::SYNTAX];
        yield 'double colon' => ['=Foo::bar()', ExpressionError::SYNTAX];
        yield 'arrow' => ['=a->b', ExpressionError::SYNTAX];
        yield 'trailing comma' => ['=SUM(1,)', ExpressionError::SYNTAX];
        yield 'bang equals' => ['=1 != 2', ExpressionError::SYNTAX];
        yield 'regex-ish' => ['=title ~ "a.*"', ExpressionError::SYNTAX];
        yield 'long number' => ['=' . \str_repeat('9', 31), ExpressionError::LITERAL];
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function typeErrors(): iterable
    {
        yield 'unknown function' => ['=EVAL("1")', ExpressionError::UNKNOWN_FUNCTION];
        yield 'php function' => ['=system("id")', ExpressionError::UNKNOWN_FUNCTION];
        yield 'exec' => ['=EXEC("id")', ExpressionError::UNKNOWN_FUNCTION];
        yield 'regex' => ['=REGEXMATCH(title, "a")', ExpressionError::UNKNOWN_FUNCTION];
        yield 'indirect' => ['=INDIRECT("A1")', ExpressionError::UNKNOWN_FUNCTION];
        yield 'too few args' => ['=IF(done, 1)', ExpressionError::ARITY];
        yield 'too many args' => ['=NOT(done, done)', ExpressionError::ARITY];
        yield 'unknown field' => ['=price * 2', ExpressionError::UNKNOWN_FIELD];
        yield 'text times number' => ['=title * 2', ExpressionError::TYPE];
        yield 'text vs number' => ['=title = 2', ExpressionError::TYPE];
        yield 'ordering booleans' => ['=done < TRUE', ExpressionError::TYPE];
        yield 'if branches' => ['=IF(done, 1, "x")', ExpressionError::TYPE];
        yield 'and number' => ['=AND(amount)', ExpressionError::TYPE];
        yield 'min of users' => ['=MIN(owner, owner)', ExpressionError::TYPE];
        yield 'in non-list' => ['=title IN amount', ExpressionError::TYPE];
        yield 'in wrong item' => ['=amount IN (1, "x")', ExpressionError::TYPE];
        yield 'unknown option' => ["=currency = 'EUR'", ExpressionError::UNKNOWN_OPTION];
        yield 'unknown state' => ["=stage = 'negotiation'", ExpressionError::UNKNOWN_OPTION];
        yield 'unknown in option' => ["=stage IN ('won', 'gone')", ExpressionError::UNKNOWN_OPTION];
        yield 'unknown tag' => ["='old' IN tags", ExpressionError::UNKNOWN_OPTION];
        yield 'file arithmetic' => ['=file + 1', ExpressionError::TYPE];
        yield 'list in text' => ['=CONCAT(tags)', ExpressionError::TYPE];
    }

    /**
     * @return iterable<string, array{0: string, 1: mixed}>
     */
    public static function functionCases(): iterable
    {
        yield 'abs' => ['ABS(-3)', 3];
        yield 'round' => ['ROUND(2.5)', 3];
        yield 'round neg' => ['ROUND(-2.5)', -3];
        yield 'round digits' => ['ROUND(1234.5, -2)', 1200];
        yield 'floor' => ['FLOOR(2.7)', 2];
        yield 'ceiling' => ['CEILING(2.1)', 3];
        yield 'mod sign follows divisor' => ['MOD(-7, 3)', 2];
        yield 'mod' => ['MOD(7, -3)', -2];
        yield 'power' => ['POWER(2, 10)', 1024.0];
        yield 'min' => ['MIN(3, 1, 2)', 1];
        yield 'max text' => ['MAX("a", "c", "b")', 'c'];
        yield 'sum' => ['SUM(1, 2, 3.5)', 6.5];
        yield 'average' => ['AVERAGE(1, 2, 3)', 2];
        yield 'len unicode' => ['LEN("香港")', 2];
        yield 'left' => ['LEFT("abcdef", 2)', 'ab'];
        yield 'left default' => ['LEFT("abc")', 'a'];
        yield 'right' => ['RIGHT("abcdef", 2)', 'ef'];
        yield 'mid' => ['MID("abcdef", 2, 3)', 'bcd'];
        yield 'upper' => ['UPPER("ab")', 'AB'];
        yield 'lower' => ['LOWER("AB")', 'ab'];
        yield 'trim collapses' => ['TRIM("  a   b ")', 'a b'];
        yield 'concat' => ['CONCAT("a", 1, TRUE)', 'a1TRUE'];
        yield 'contains ci' => ['CONTAINS("Hello", "ELL")', true];
        yield 'startswith' => ['STARTSWITH("Hello", "he")', true];
        yield 'endswith' => ['ENDSWITH("Hello", "x")', false];
        yield 'substitute' => ['SUBSTITUTE("a-b-c", "-", "+")', 'a+b+c'];
        yield 'value' => ['VALUE(" 12.5 ")', 12.5];
        yield 'text number' => ['TEXT(1.50)', '1.5'];
        yield 'text bool' => ['TEXT(FALSE)', 'FALSE'];
        yield 'date' => ['DATE(2026, 10, 27)', '2026-10-27'];
        yield 'date rolls over' => ['DATE(2026, 13, 1)', '2027-01-01'];
        yield 'date day 0' => ['DATE(2026, 3, 0)', '2026-02-28'];
        yield 'year' => ['YEAR(DATE(2026, 10, 27))', 2026];
        yield 'month' => ['MONTH(DATE(2026, 10, 27))', 10];
        yield 'day' => ['DAY(DATE(2026, 10, 27))', 27];
        yield 'weekday iso' => ['WEEKDAY(DATE(2026, 10, 25))', 7];
        yield 'days' => ['DAYS(DATE(2026, 12, 25), DATE(2026, 10, 27))', 59];
        yield 'edate clamps' => ['EDATE(DATE(2026, 1, 31), 1)', '2026-02-28'];
        yield 'edate back' => ['EDATE(DATE(2026, 1, 15), -2)', '2025-11-15'];
        yield 'eomonth' => ['EOMONTH(DATE(2024, 1, 10), 1)', '2024-02-29'];
        yield 'workday' => ['WORKDAY(DATE(2026, 10, 30), 1)', '2026-11-02'];
        yield 'workday back' => ['WORKDAY(DATE(2026, 11, 2), -1)', '2026-10-30'];
        yield 'networkdays' => ['NETWORKDAYS(DATE(2026, 10, 26), DATE(2026, 11, 6))', 10];
        yield 'networkdays reversed' => ['NETWORKDAYS(DATE(2026, 11, 6), DATE(2026, 10, 26))', -10];
        yield 'date plus days' => ['DATE(2026, 12, 30) + 3', '2027-01-02'];
        yield 'date minus date' => ['DATE(2026, 12, 30) - DATE(2026, 12, 1)', 29];
        yield 'coalesce' => ['COALESCE(BLANK(), "", "x")', 'x'];
        yield 'if' => ['IF(1 > 2, "a", "b")', 'b'];
        yield 'or' => ['OR(FALSE, 1 = 1)', true];
        yield 'not' => ['NOT(FALSE)', true];
        yield 'isblank' => ['ISBLANK(BLANK())', true];
        yield 'today' => ['TODAY()', '2026-10-27'];
        yield 'now' => ['NOW()', '2026-10-27T03:04:05Z'];
    }

    /**
     * Decimals as int (whole) or float, so cases read naturally; exact
     * decimal strings are pinned in AppsSqlParityTest and AppsDecimalTest.
     */
    private static function plain(mixed $v): mixed
    {
        if ($v instanceof Decimal) {
            $s = (string) $v;

            return \preg_match('/^-?\d{1,18}$/', $s) === 1 ? (int) $s : (float) $s;
        }

        return \is_array($v) ? \array_map(self::plain(...), $v) : $v;
    }

    // ── parser ─────────────────────────────────────────────────────

    public function testParsesThePlanExamples(): void
    {
        $ast = (new Parser())->parse('=amount * stage.probability');
        $this->assertSame('op', $ast['k']);
        $this->assertSame('*', $ast['op']);
        $this->assertSame(['stage', 'probability'], $ast['args'][1]['path']);

        $ast = (new Parser())->parse("=AND(owner = CURRENT_USER(), stage.category <> 'done')");
        $this->assertSame('call', $ast['k']);
        $this->assertSame('AND', $ast['fn']);
        $this->assertCount(2, $ast['args']);
        $this->assertSame('<>', $ast['args'][1]['op']);
    }

    public function testLeadingEqualsIsOptionalAndPrecedenceIsExcelLike(): void
    {
        $p = new Parser();
        $this->assertEquals($this->strip($p->parse('1 + 2 * 3')), $this->strip($p->parse('=1 + 2 * 3')));
        $this->assertSame(7, $this->evalSrc('1 + 2 * 3'));
        $this->assertSame(9, $this->evalSrc('(1 + 2) * 3'));
        $this->assertSame(-5, $this->evalSrc('-2 - 3'));
        $this->assertSame('a3', $this->evalSrc('"a" & 1 + 2'));
        $this->assertTrue($this->evalSrc('1 + 1 = 2'));
    }

    public function testStringsUseDoubledQuotesAsTheOnlyEscape(): void
    {
        $this->assertSame("it's", $this->evalSrc("'it''s'"));
        $this->assertSame('say "hi"', $this->evalSrc('"say ""hi"""'));
        $this->assertSame('a\n', $this->evalSrc("'a\\n'"), 'backslash is a plain character');
    }

    public function testFunctionNamesAreCaseInsensitiveAndStoredUpperCase(): void
    {
        $ast = (new Parser())->parse('=round(1.25, 1)');
        $this->assertSame('ROUND', $ast['fn']);
        $this->assertSame(1.3, $this->evalSrc('round(1.25, 1)'));
        $this->assertTrue($this->evalSrc('true'));
        $this->assertFalse($this->evalSrc('FALSE'));
    }

    #[DataProvider('syntaxErrors')]
    public function testSyntaxErrorsAreTyped(string $src, string $code): void
    {
        $this->expectErrorCode($code, fn () => (new Parser())->parse($src));
    }

    public function testErrorsCarryTheSourceOffset(): void
    {
        try {
            (new Parser())->parse('=1 + @');
            $this->fail('expected an error');
        } catch (ExpressionError $e) {
            $this->assertSame(ExpressionError::SYNTAX, $e->getErrorCode());
            $this->assertSame(5, $e->getOffset());
        }
    }

    public function testLengthDepthNodesAndArgsAreCapped(): void
    {
        $caps = new Caps(['expression_length' => 50, 'expression_depth' => 5, 'expression_args' => 3, 'literal_length' => 4]);
        $p = new Parser($caps);
        $this->expectErrorCode(ExpressionError::LENGTH, fn () => $p->parse('=' . \str_repeat('1+', 30) . '1'));
        $this->expectErrorCode(ExpressionError::DEPTH, fn () => $p->parse('=((((((1))))))'));
        $this->expectErrorCode(ExpressionError::DEPTH, fn () => $p->parse('=1+1+1+1+1+1+1'));
        $this->expectErrorCode(ExpressionError::DEPTH, fn () => $p->parse('=-----1'));
        $this->expectErrorCode(ExpressionError::ARITY, fn () => $p->parse('=SUM(1,2,3,4)'));
        $this->expectErrorCode(ExpressionError::LITERAL, fn () => $p->parse("='abcde'"));
        $this->expectErrorCode(ExpressionError::NODES, fn () => (new Parser(new Caps(['expression_nodes' => 3])))->parse('=SUM(1,2,3,4)'));
    }

    public function testDeepNestingFailsAtTheCapNotThePhpStack(): void
    {
        $src = '=' . \str_repeat('(', 5000) . '1' . \str_repeat(')', 5000);
        $this->expectErrorCode(ExpressionError::LENGTH, fn () => (new Parser())->parse($src));
        $big = new Caps();
        $deep = '=' . \str_repeat('NOT(', 400) . 'TRUE' . \str_repeat(')', 400);
        $this->expectErrorCode(ExpressionError::DEPTH, fn () => (new Parser(new Caps(['expression_length' => 1000])))->parse(\substr($deep, 0, 999)));
        $this->assertSame(24, $big->get('expression_depth'));
    }

    // ── type checker ───────────────────────────────────────────────

    public function testTypesEveryNode(): void
    {
        $typed = $this->typed('=amount * stage.probability');
        $this->assertSame('decimal', $typed['t']);
        $this->assertSame('decimal', $typed['args'][0]['t']);
        $this->assertSame('number', $this->typed('=amount * ratio')['t'], 'a float operand makes a float');
        $this->assertSame('number', $this->typed('=POWER(amount, 2)')['t']);
        $this->assertSame('decimal', $this->typed('=ROUND(amount, 2) + LEN(title)')['t']);
        $this->assertSame('number', $this->typed('=SUM(amount, ratio)')['t']);
        $this->assertSame('decimal', $this->typed('=2.50')['t']);
        $this->assertSame('2.50', $this->typed('=2.50')['v'], 'literals keep their written scale, as a string');
        $typed = $this->typed('=IF(done, title, "")');
        $this->assertSame('text', $typed['t']);
        $typed = $this->typed('=company');
        $this->assertSame('ref:crm-core.company', $typed['t']);
        $this->assertSame('date', $this->typed('=due + 7')['t']);
        $this->assertSame('decimal', $this->typed('=due - TODAY()')['t']);
        $this->assertSame('datetime', $this->typed('=MAX(due, seen)')['t']);
        $this->assertSame('blank', $this->typed('=BLANK()')['t']);
    }

    #[DataProvider('typeErrors')]
    public function testTypeErrorsAreTyped(string $src, string $code): void
    {
        $this->expectErrorCode($code, fn () => $this->typed($src));
    }

    public function testExpectedResultType(): void
    {
        $this->expectErrorCode(ExpressionError::TYPE, fn () => $this->typed('=amount', 'boolean'));
        $this->assertSame('boolean', $this->typed('=amount > 0', 'boolean')['t']);
        $this->assertSame('blank', $this->typed('=BLANK()', 'boolean')['t'], 'a blank fits any site');
    }

    public function testSqlSitesRefuseNonSqlFunctionsAndUnstoredFields(): void
    {
        $tc = new TypeChecker();
        $p = new Parser();
        $this->expectErrorCode(ExpressionError::NOT_SQL, fn () => $tc->check($p->parse('=WORKDAY(due, 3) > TODAY()'), $this->scope, 'boolean', true));
        $this->expectErrorCode(ExpressionError::NOT_SQL, fn () => $tc->check($p->parse('=preview > 1'), $this->scope, 'boolean', true));
        $typed = $tc->check($p->parse("=AND(owner = CURRENT_USER(), department IN MY_DEPARTMENTS(), stage <> 'lost')"), $this->scope, 'boolean', true);
        $this->assertSame('boolean', $typed['t']);
        foreach (Functions::LIST as $name => $sig) {
            $this->assertIsBool($sig['sql'], $name);
        }
    }

    public function testStoredAstIsRecheckedAndCannotSmuggleNodes(): void
    {
        $tc = new TypeChecker();
        $bad = [
            ['k' => 'call', 'fn' => 'system', 'args' => [['k' => 'str', 'v' => 'id']]],
            ['k' => 'call', 'fn' => 'exec', 'args' => []],
            ['k' => 'php', 'code' => 'phpinfo()'],
            ['k' => 'op', 'op' => '**', 'args' => [['k' => 'num', 'v' => 1], ['k' => 'num', 'v' => 2]]],
            ['k' => 'field', 'path' => ['../etc/passwd']],
            ['k' => 'field', 'path' => 'amount'],
            ['k' => 'num', 'v' => '1; DROP TABLE x'],
            ['k' => 'str', 'v' => ['array']],
            ['k' => 'list', 'items' => [['k' => 'num', 'v' => 1]]],
            ['k' => 'op', 'op' => 'in', 'args' => [['k' => 'num', 'v' => 1], ['k' => 'list', 'items' => 'x']]],
            ['k' => 'num', 'v' => \INF],
        ];
        foreach ($bad as $i => $ast) {
            try {
                $tc->check($ast, $this->scope);
                $this->fail("tampered AST #{$i} was accepted");
            } catch (ExpressionError $e) {
                $this->assertNotSame('', $e->getErrorCode());
            }
        }
    }

    // ── evaluator ──────────────────────────────────────────────────

    public function testEvaluatesComputedValuesWithLookups(): void
    {
        $values = ['amount' => '1200.50', 'stage' => 'won', 'stage.probability' => 0.5, 'company.name' => 'Acme'];
        $this->assertSame(600.25, $this->evalSrc('=amount * stage.probability', $values));
        $this->assertSame('Acme Ltd', $this->evalSrc('=company.name & " Ltd"', $values));
        $this->assertTrue($this->evalSrc("=stage IN ('won', 'lost')", $values));
    }

    #[DataProvider('functionCases')]
    public function testFunctions(string $src, mixed $expected): void
    {
        $this->assertSame($expected, $this->evalSrc('=' . $src));
    }

    public function testEveryListedFunctionHasACase(): void
    {
        $covered = [];
        foreach (self::functionCases() as [$src]) {
            \preg_match_all('/([A-Z_]+)\(/', $src, $m);
            foreach ($m[1] as $fn) {
                $covered[$fn] = true;
            }
        }
        $covered += ['CURRENT_USER' => true, 'MY_GROUPS' => true, 'MY_DEPARTMENTS' => true, 'AND' => true];
        $this->assertSame([], \array_values(\array_diff(\array_keys(Functions::LIST), \array_keys($covered))));
    }

    public function testBlanksFollowTwoValuedLogic(): void
    {
        $v = ['amount' => null, 'title' => null, 'done' => null, 'due' => null];
        $this->assertNull($this->evalSrc('=amount * 2', $v), 'arithmetic with a blank is blank');
        $this->assertSame('x', $this->evalSrc('=title & "x"', $v), 'text join reads a blank as ""');
        $this->assertFalse($this->evalSrc('=amount > 0', $v));
        $this->assertFalse($this->evalSrc('=amount < 0', $v));
        $this->assertFalse($this->evalSrc('=amount = 0', $v));
        $this->assertTrue($this->evalSrc('=amount <> 0', $v));
        $this->assertTrue($this->evalSrc('=amount = BLANK()', $v));
        $this->assertTrue($this->evalSrc('=NOT(done)', $v), 'a blank condition is FALSE');
        $this->assertSame('no', $this->evalSrc('=IF(done, "yes", "no")', $v));
        $this->assertFalse($this->evalSrc('=AND(done, TRUE)', $v));
        $this->assertSame(0, $this->evalSrc('=SUM(amount, amount)', $v));
        $this->assertNull($this->evalSrc('=AVERAGE(amount)', $v));
        $this->assertNull($this->evalSrc('=due + 1', $v));
        $this->assertTrue($this->evalSrc('=ISBLANK(title)', $v));
    }

    public function testPrincipalFunctionsComeFromTheContext(): void
    {
        $ctx = new Context('u7', ['g1', 'g2'], ['d9'], new DateTimeImmutable('2026-10-27T00:00:00Z'));
        $values = ['owner' => 'u7', 'department' => 'd9'];
        $this->assertTrue($this->evalSrc('=owner = CURRENT_USER()', $values, $ctx));
        $this->assertTrue($this->evalSrc('=department IN MY_DEPARTMENTS()', $values, $ctx));
        $this->assertSame(['g1', 'g2'], $this->evalSrc('=MY_GROUPS()', [], $ctx));
        $this->assertFalse($this->evalSrc('=owner = CURRENT_USER()', $values, new Context(null)));
    }

    public function testTodayUsesTheDataSpaceTimeZone(): void
    {
        $now = new DateTimeImmutable('2026-10-27T20:00:00Z');
        $this->assertSame('2026-10-27', $this->evalSrc('=TODAY()', [], new Context(null, [], [], $now, 'UTC')));
        $this->assertSame('2026-10-28', $this->evalSrc('=TODAY()', [], new Context(null, [], [], $now, 'Asia/Hong_Kong')));
        $this->expectErrorCode(ExpressionError::VALUE, fn () => new Context(null, [], [], $now, 'Mars/Olympus'));
    }

    public function testHolidaysAreSkippedByWorkingDayFunctions(): void
    {
        $ctx = new Context(null, [], [], new DateTimeImmutable('2026-10-27'), 'UTC', ['2026-11-02', 'not-a-date']);
        $this->assertSame('2026-11-03', $this->evalSrc('=WORKDAY(DATE(2026, 10, 30), 1)', [], $ctx));
        $this->assertSame(9, $this->evalSrc('=NETWORKDAYS(DATE(2026, 10, 26), DATE(2026, 11, 6))', [], $ctx));
    }

    public function testRuntimeValuesAreCoercedOrRefused(): void
    {
        $this->assertSame(12, $this->evalSrc('=amount', ['amount' => '12']));
        $this->assertTrue($this->evalSrc('=done', ['done' => 't']), 'postgres boolean text');
        $this->assertSame('2026-10-27T01:00:00Z', $this->evalSrc('=seen', ['seen' => '2026-10-27 09:00:00+08:00']));
        $this->assertSame('2026-10-27', $this->evalSrc('=due', ['due' => new DateTimeImmutable('2026-10-27')]));
        $this->expectErrorCode(ExpressionError::VALUE, fn () => $this->evalSrc('=amount', ['amount' => 'abc']));
        $this->expectErrorCode(ExpressionError::VALUE, fn () => $this->evalSrc('=due', ['due' => '2026-02-30']));
        $this->expectErrorCode(ExpressionError::VALUE, fn () => $this->evalSrc('=seen', ['seen' => 'next monday']));
        $this->expectErrorCode(ExpressionError::VALUE, fn () => $this->evalSrc('=done', ['done' => 'yes']));
        $this->expectErrorCode(ExpressionError::VALUE, fn () => $this->evalSrc("='vip' IN tags", ['tags' => 'vip']));
    }

    public function testRuntimeErrorsAreTyped(): void
    {
        $this->expectErrorCode(ExpressionError::DIV_ZERO, fn () => $this->evalSrc('=1 / 0'));
        $this->expectErrorCode(ExpressionError::DIV_ZERO, fn () => $this->evalSrc('=MOD(1, 0)'));
        $this->expectErrorCode(ExpressionError::DIV_ZERO, fn () => $this->evalSrc('=POWER(0, -1)'));
        $this->expectErrorCode(ExpressionError::VALUE, fn () => $this->evalSrc('=POWER(10, 400)'));
        $this->expectErrorCode(ExpressionError::VALUE, fn () => $this->evalSrc('=POWER(-8, 0.5)'));
        $this->expectErrorCode(ExpressionError::VALUE, fn () => $this->evalSrc('=VALUE("1e3")'));
        $this->expectErrorCode(ExpressionError::VALUE, fn () => $this->evalSrc('=DATE(0, 1, 1)'));
        $this->expectErrorCode(ExpressionError::VALUE, fn () => $this->evalSrc('=DATE(9999, 12, 31) + 1'));
        $this->expectErrorCode(ExpressionError::VALUE, fn () => $this->evalSrc('=MID("abc", 0, 1)'));
        $this->expectErrorCode(ExpressionError::VALUE, fn () => $this->evalSrc('=LEFT("abc", -1)'));
        $this->expectErrorCode(ExpressionError::VALUE, fn () => $this->evalSrc('=ROUND(1, 99)'));
    }

    public function testStepBudgetStopsExpensiveWork(): void
    {
        $caps = new Caps(['eval_steps' => 50]);
        $this->expectErrorCode(ExpressionError::BUDGET, fn () => $this->evalSrc('=WORKDAY(DATE(2026, 1, 1), 1000)', [], null, $caps));
        $ev = new Evaluator(new Caps());
        $ast = (new TypeChecker())->check((new Parser())->parse('=1 + 2'), $this->scope);
        $ev->evaluate($ast, new ArrayValues([]), new Context());
        $this->assertSame(4, $ev->steps(), 'three nodes and one decimal operation');
        $this->expectErrorCode(ExpressionError::BUDGET, fn () => $this->evalSrc('=WORKDAY(DATE(2026, 1, 1), 100000)'));
    }

    public function testStringSizesAreCappedBeforeTheyAreBuilt(): void
    {
        $caps = new Caps(['eval_string_bytes' => 100]);
        $values = ['title' => \str_repeat('x', 60)];
        $this->expectErrorCode(ExpressionError::SIZE, fn () => $this->evalSrc('=title & title', $values, null, $caps));
        $this->expectErrorCode(ExpressionError::SIZE, fn () => $this->evalSrc('=CONCAT(title, title)', $values, null, $caps));
        $this->expectErrorCode(ExpressionError::SIZE, fn () => $this->evalSrc('=SUBSTITUTE(title, "x", "xx")', $values, null, $caps));
        $this->expectErrorCode(ExpressionError::SIZE, fn () => $this->evalSrc('=title', ['title' => \str_repeat('y', 101)], null, $caps));
        $this->expectErrorCode(ExpressionError::SIZE, fn () => $this->evalSrc("='vip' IN tags", ['tags' => \array_fill(0, 2000, 'vip')]));
    }

    public function testBlankPolicyOfTheFunctionsWithoutSql(): void
    {
        // the SQL-able functions are pinned in tests/fixtures/apps/sql-parity.json
        $this->assertSame('', $this->evalSrc('=MID(title, 1, 2)', ['title' => null]));
        $this->assertSame('', $this->evalSrc('=MID("abc", amount, 2)', ['amount' => null]), 'empty_text: a blank count gives ""');
        $this->assertSame('', $this->evalSrc('=SUBSTITUTE(title, "a", "b")', ['title' => '']));
        $this->assertNull($this->evalSrc('=VALUE(title)', ['title' => '']));
        $this->assertNull($this->evalSrc('=EDATE(due, 1)', ['due' => null]));
        $this->assertNull($this->evalSrc('=EOMONTH(due, amount)', ['due' => '2026-01-01', 'amount' => null]));
        $this->assertNull($this->evalSrc('=WORKDAY(due, 1)', ['due' => null]));
        $this->assertNull($this->evalSrc('=NETWORKDAYS(due, due)', ['due' => null]));
        foreach (Functions::LIST as $name => $sig) {
            $this->assertContains($sig['blank'], Functions::BLANK_POLICIES, $name);
        }
    }

    public function testDecimalFieldsReadAtTheirScale(): void
    {
        $scope = new MapScope(['price' => ['type' => 'decimal', 'scale' => 2], 'qty' => ['type' => 'decimal', 'scale' => 0]]);
        $ast = (new TypeChecker())->check((new Parser())->parse('=price * qty'), $scope);
        $this->assertSame(2, $ast['args'][0]['scale']);
        $out = (new Evaluator())->evaluate($ast, new ArrayValues(['price' => '1.005', 'qty' => '3']), new Context());
        $this->assertInstanceOf(Decimal::class, $out);
        $this->assertSame('3.03', (string) $out, '1.005 is stored as 1.01 (HALF_UP), times 3');
        $this->expectErrorCode(ExpressionError::VALUE, fn () => (new Evaluator())->evaluate($ast, new ArrayValues(['price' => '1e3', 'qty' => 1]), new Context()));
    }

    public function testEvaluationWarningsFailClosedWithoutDetail(): void
    {
        $values = new class() implements Values {
            public function get(array $path): mixed
            {
                \trigger_error('/srv/secret/path.php leaked', E_USER_WARNING);

                return null;
            }
        };
        $ast = (new TypeChecker())->check((new Parser())->parse('=title'), $this->scope);
        try {
            (new Evaluator())->evaluate($ast, $values, new Context());
            $this->fail('the warning passed through');
        } catch (ExpressionError $e) {
            $this->assertSame(ExpressionError::SYNTAX, $e->getErrorCode());
            $this->assertStringNotContainsString('/srv', $e->getMessage());
        }
        // the evaluator's handler is removed again afterwards
        $previous = \set_error_handler(static fn (): bool => false);
        \restore_error_handler();
        $this->assertNotInstanceOf(Closure::class, $previous);
    }

    public function testTamperedLiteralsAreRefused(): void
    {
        $ev = new Evaluator(new Caps(['literal_length' => 10]));
        foreach ([
            ['k' => 'num', 'v' => 1.5, 't' => 'decimal'],
            ['k' => 'num', 'v' => '1e5', 't' => 'decimal'],
            ['k' => 'num', 'v' => \str_repeat('9', 70), 't' => 'decimal'],
            ['k' => 'str', 'v' => \str_repeat('x', 11), 't' => 'text'],
            ['k' => 'str', 'v' => "\xff", 't' => 'text'],
            ['k' => 'field', 'path' => ['amount'], 'scale' => 99, 't' => 'decimal'],
            ['k' => 'call', 'fn' => 'UPPER', 'args' => [['k' => 'bool', 'v' => true, 't' => 'boolean']], 't' => 'text'],
            ['k' => 'op', 'op' => '+', 'args' => [['k' => 'str', 'v' => 'a', 't' => 'decimal'], ['k' => 'num', 'v' => '1', 't' => 'decimal']], 't' => 'decimal'],
        ] as $i => $ast) {
            try {
                $ev->evaluate($ast, new ArrayValues([]), new Context());
                $this->fail("tampered literal #{$i} ran");
            } catch (ExpressionError $e) {
                $this->assertSame(ExpressionError::SYNTAX, $e->getErrorCode(), "#{$i}");
            }
        }
    }

    public function testIfOnlyEvaluatesTheChosenBranch(): void
    {
        $this->assertSame(1, $this->evalSrc('=IF(TRUE, 1, 1 / 0)'));
        $this->assertFalse($this->evalSrc('=AND(FALSE, 1 / 0 > 1)'));
        $this->assertTrue($this->evalSrc('=OR(TRUE, 1 / 0 > 1)'));
    }

    public function testTamperedTypedAstFailsClosedInTheEvaluator(): void
    {
        $ev = new Evaluator();
        $bad = [
            ['k' => 'call', 'fn' => 'system', 'args' => [['k' => 'str', 'v' => 'id', 't' => 'text']], 't' => 'text'],
            ['k' => 'call', 'fn' => 'phpinfo', 'args' => [], 't' => 'text'],
            ['k' => 'op', 'op' => '**', 'args' => [['k' => 'num', 'v' => 1, 't' => 'number'], ['k' => 'num', 'v' => 1, 't' => 'number']], 't' => 'number'],
            ['k' => 'shell', 't' => 'text'],
        ];
        foreach ($bad as $ast) {
            try {
                $ev->evaluate($ast, new ArrayValues([]), new Context());
                $this->fail('tampered typed AST ran');
            } catch (ExpressionError $e) {
                $this->assertContains($e->getErrorCode(), [ExpressionError::UNKNOWN_FUNCTION, ExpressionError::SYNTAX]);
            }
        }
    }

    // ── helpers ────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function typed(string $src, ?string $expect = null): array
    {
        return (new TypeChecker())->check((new Parser())->parse($src), $this->scope, $expect);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function evalSrc(string $src, array $values = [], ?Context $ctx = null, ?Caps $caps = null): mixed
    {
        $caps ??= new Caps();
        $ast = (new TypeChecker($caps))->check((new Parser($caps))->parse($src), $this->scope);
        $ctx ??= new Context('u1', [], [], new DateTimeImmutable('2026-10-27T03:04:05Z'));

        return self::plain((new Evaluator($caps))->evaluate($ast, new ArrayValues($values), $ctx));
    }

    private function expectErrorCode(string $code, callable $fn): void
    {
        try {
            $fn();
        } catch (ExpressionError $e) {
            $this->assertSame($code, $e->getErrorCode(), $e->getMessage());

            return;
        }
        $this->fail("Expected ExpressionError {$code}");
    }

    /**
     * @param array<string, mixed> $ast
     *
     * @return array<string, mixed>
     */
    private function strip(array $ast): array
    {
        unset($ast['at']);
        foreach (['args', 'items'] as $k) {
            if (isset($ast[$k])) {
                $ast[$k] = \array_map(fn (array $n): array => $this->strip($n), $ast[$k]);
            }
        }

        return $ast;
    }
}
