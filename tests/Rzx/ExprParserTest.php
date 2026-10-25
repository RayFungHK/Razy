<?php

declare(strict_types=1);

namespace Razy\Tests\Rzx;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Rzx\CompileError;
use Razy\Rzx\Expr\Binary;
use Razy\Rzx\Expr\Call;
use Razy\Rzx\Expr\Expr;
use Razy\Rzx\Expr\ExprLexer;
use Razy\Rzx\Expr\ExprParser;
use Razy\Rzx\Expr\Index;
use Razy\Rzx\Expr\ListLiteral;
use Razy\Rzx\Expr\Literal;
use Razy\Rzx\Expr\MapLiteral;
use Razy\Rzx\Expr\Member;
use Razy\Rzx\Expr\Ternary;
use Razy\Rzx\Expr\Unary;
use Razy\Rzx\Expr\Variable;

/**
 * RZ-28a: the `{…}` expression grammar. Each expression is printed back
 * fully parenthesised, so precedence and associativity are visible in the
 * assertion.
 */
#[CoversClass(ExprParser::class)]
#[CoversClass(ExprLexer::class)]
class ExprParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function grammar(): iterable
    {
        yield 'path' => ['copy.empty.title', 'copy.empty.title'];
        yield 'index' => ['rows[0].name', 'rows[0].name'];
        yield 'computed index' => ["row[key ~ '_id']", "row[(key ~ '_id')]"];
        yield 'strings' => ["'it\\'s' ~ \"a \\\"b\\\"\"", "('it\\'s' ~ 'a \"b\"')"];
        yield 'numbers' => ['1 + 2.5 - 3', '((1 + 2.5) - 3)'];
        yield 'keywords' => ['true || false ?? null', '((true || false) ?? null)'];
        yield 'not and or' => ['!a && b || c', '(((!a) && b) || c)'];
        yield 'negative' => ['-n + 1', '((-n) + 1)'];
        yield 'equality below concat' => ["s ~ 'x' == 'ax'", "((s ~ 'x') == 'ax')"];
        yield 'concat below additive' => ["'n' ~ 1 + 2", "('n' ~ (1 + 2))"];
        yield 'compare below concat' => ['a < b ~ c', '(a < (b ~ c))'];
        yield 'compare above equality' => ['a < b == c >= d', '((a < b) == (c >= d))'];
        yield 'ternary right assoc' => ["a ? 'x' : b ? 'y' : 'z'", "(a ? 'x' : (b ? 'y' : 'z'))"];
        yield 'ternary lowest' => ["state == 'error' ? error : null", "((state == 'error') ? error : null)"];
        yield 'coalesce right assoc' => ['a ?? b ?? c', '(a ?? (b ?? c))'];
        yield 'parens' => ['(a + b) ~ c', '((a + b) ~ c)'];
        yield 'call' => ["join(searchKeys, ',')", "join(searchKeys, ',')"];
        yield 'call no args' => ['now()', 'now()'];
        yield 'nested call' => ["t('k', {n: count(rows)})", "t('k', {'n': count(rows)})"];
        yield 'list' => ["['name', 'login', 'email',]", "['name', 'login', 'email']"];
        yield 'empty list compare' => ['rows == []', '(rows == [])'];
        yield 'map' => ["{ value: 'all', 'Microsoft Entra': 'grid', [t('k')]: 'info' }", "{'value': 'all', 'Microsoft Entra': 'grid', [t('k')]: 'info'}"];
        yield 'empty map' => ['{}', '{}'];
        yield 'nested map' => ['{ a: { b: [1, {c: 2}] } }', "{'a': {'b': [1, {'c': 2}]}}"];
        yield 'member of call' => ["t('labels').search", "t('labels').search"];
        yield 'keyword as member' => ['flags.null', 'flags.null'];
        yield 'whitespace' => ["\n  a\n  ==\tb\n", '(a == b)'];
    }

    /**
     * @return iterable<string, array{string, string, int, string}>
     */
    public static function malformed(): iterable
    {
        yield 'php variable' => ['$name', 'RZX032', 1, 'not `$name`'];
        yield 'arrow' => ['user->name', 'RZX032', 5, 'Use `.`'];
        yield 'static' => ['Foo::bar', 'RZX032', 4, 'Static access'];
        yield 'assignment' => ['a = 1', 'RZX033', 3, 'Assignment'];
        yield 'strict eq' => ['a === 1', 'RZX033', 3, '`===`'];
        yield 'strict neq' => ['a !== 1', 'RZX033', 3, '`!==`'];
        yield 'pipe modifier' => ['name | upper', 'RZX033', 6, 'modifiers are functions'];
        yield 'single amp' => ['a & b', 'RZX033', 3, '`&&`'];
        yield 'multiply' => ['a * 2', 'RZX033', 3, 'Only `+` and `-`'];
        yield 'backtick' => ['`ls`', 'RZX032', 1, 'Backticks'];
        yield 'semicolon' => ['a; b', 'RZX032', 2, '`;`'];
        yield 'unknown char' => ['a # b', 'RZX031', 3, 'Unexpected character `#`'];
        yield 'digit name' => ['1abc', 'RZX031', 1, 'start with a digit'];
        yield 'unterminated string' => ["'abc", 'RZX034', 1, 'Unterminated string'];
        yield 'newline in string' => ["'ab\ncd'", 'RZX034', 1, 'Unterminated string'];
        yield 'bad escape' => ["'a\\qb'", 'RZX035', 3, 'Unknown escape'];
        yield 'empty' => ['   ', 'RZX040', 1, 'Empty expression'];
        yield 'trailing token' => ['a b', 'RZX041', 3, 'Unexpected `b`'];
        yield 'dangling operator' => ['a +', 'RZX041', 4, 'Expected a value, found end of expression'];
        yield 'missing paren' => ['(a + b', 'RZX041', 7, 'Expected `)` to close `(`'];
        yield 'missing bracket' => ['rows[0', 'RZX041', 7, 'Expected `]`'];
        yield 'ternary without colon' => ['a ? b', 'RZX041', 6, 'Expected `:`'];
        yield 'chained equality' => ['a == b == c', 'RZX042', 11, 'do not chain'];
        yield 'chained compare' => ['a < b < c', 'RZX042', 9, 'do not chain'];
        yield 'numeric member' => ['rows.0', 'RZX043', 6, 'x[0]'];
        yield 'method call' => ['user.save()', 'RZX044', 10, 'Only a registered function'];
        yield 'call a call' => ['f()()', 'RZX044', 4, 'Only a registered function'];
        yield 'bad map key' => ['{ 1: 2 }', 'RZX046', 3, 'Expected a map key'];
        yield 'map shorthand' => ['{ a }', 'RZX041', 5, 'Expected `:` after a map key'];
        yield 'int overflow' => ['9223372036854775808', 'RZX036', 1, 'Number out of range'];
        yield 'too deep' => [\str_repeat('(', 70) . 'a' . \str_repeat(')', 70), 'RZX047', 65, 'nested too deeply'];
    }

    private static function show(Expr $expr): string
    {
        return match (true) {
            $expr instanceof Literal => \is_string($expr->value) ? "'" . \addcslashes($expr->value, "'") . "'" : \strtolower(\var_export($expr->value, true)),
            $expr instanceof Variable => $expr->name,
            $expr instanceof Member => self::show($expr->object) . '.' . $expr->name,
            $expr instanceof Index => self::show($expr->object) . '[' . self::show($expr->index) . ']',
            $expr instanceof Call => $expr->name . '(' . \implode(', ', \array_map(self::show(...), $expr->arguments)) . ')',
            $expr instanceof Unary => '(' . $expr->operator . self::show($expr->operand) . ')',
            $expr instanceof Binary => '(' . self::show($expr->left) . ' ' . $expr->operator . ' ' . self::show($expr->right) . ')',
            $expr instanceof Ternary => '(' . self::show($expr->test) . ' ? ' . self::show($expr->then) . ' : ' . self::show($expr->else) . ')',
            $expr instanceof ListLiteral => '[' . \implode(', ', \array_map(self::show(...), $expr->items)) . ']',
            $expr instanceof MapLiteral => '{' . \implode(', ', \array_map(
                static fn (array $e): string => ($e[0] instanceof Literal ? self::show($e[0]) : '[' . self::show($e[0]) . ']') . ': ' . self::show($e[1]),
                $expr->entries,
            )) . '}',
            default => '?',
        };
    }

    #[DataProvider('grammar')]
    public function testGrammar(string $source, string $expected): void
    {
        $this->assertSame($expected, self::show((new ExprParser())->parseString($source)));
    }

    public function testLiteralTypes(): void
    {
        $parser = new ExprParser();
        $this->assertSame(10, $this->literal($parser->parseString('10')));
        $this->assertSame(2.5, $this->literal($parser->parseString('2.5')));
        $this->assertTrue($this->literal($parser->parseString('true')));
        $this->assertNull($this->literal($parser->parseString('null')));
        $this->assertSame("a\nb\t\\", $this->literal($parser->parseString('"a\nb\t\\\"')));
    }

    public function testLargestIntegerIsKept(): void
    {
        $expr = (new ExprParser())->parseString('9223372036854775807 + 007');
        $this->assertInstanceOf(Binary::class, $expr);
        $this->assertSame(\PHP_INT_MAX, $this->literal($expr->left));
        $this->assertSame(7, $this->literal($expr->right));
    }

    public function testOffsetsAreAbsolute(): void
    {
        $expr = (new ExprParser())->parseString('  a.b ~ c');
        $this->assertInstanceOf(Binary::class, $expr);
        $this->assertSame(2, $expr->offset);
        $this->assertSame(8, $expr->right->offset);
    }

    public function testKnownFunctionsAreChecked(): void
    {
        $parser = new ExprParser(['count', 'join']);
        $this->assertInstanceOf(Call::class, $parser->parseString('count(rows)'));

        try {
            $parser->parseString('1 + exec(cmd)');
            $this->fail('unknown function accepted');
        } catch (CompileError $e) {
            $this->assertSame('RZX045', $e->rzxCode);
            $this->assertSame(5, $e->sourceColumn);
            $this->assertStringContainsString('Unknown function `exec`', $e->getMessage());
        }
    }

    #[DataProvider('malformed')]
    public function testMalformed(string $source, string $code, int $column, string $message): void
    {
        try {
            (new ExprParser())->parseString($source, 'expr.rzx');
            $this->fail('accepted: ' . $source);
        } catch (CompileError $e) {
            $this->assertSame($code, $e->rzxCode, $e->getMessage());
            $this->assertSame($column, $e->sourceColumn, $e->getMessage());
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    private function literal(Expr $expr): string|int|float|bool|null
    {
        $this->assertInstanceOf(Literal::class, $expr);

        return $expr->value;
    }
}
