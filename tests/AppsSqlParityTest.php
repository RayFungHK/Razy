<?php

declare(strict_types=1);

namespace Razy\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use razymod\apps\Expression\ArrayValues;
use razymod\apps\Expression\Context;
use razymod\apps\Expression\Evaluator;
use razymod\apps\Expression\Functions;
use razymod\apps\Expression\MapScope;
use razymod\apps\Expression\Parser;
use razymod\apps\Expression\TypeChecker;

require_once __DIR__ . '/fixtures/apps/autoload.php';

/**
 * SQL parity vectors (tests/fixtures/apps/sql-parity.json): the blank and
 * comparison semantics of the expression language, as data. The PHP
 * evaluator must pass every vector here; the SQL translation of filters and
 * row rules (RZA-C) reuses the same file and must pass it too, so the two
 * never disagree about which rows match.
 */
#[CoversNothing]
class AppsSqlParityTest extends TestCase
{
    private const FILE = __DIR__ . '/fixtures/apps/sql-parity.json';

    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>, 2: mixed}>
     */
    public static function vectors(): iterable
    {
        foreach (self::load()['cases'] as $i => $case) {
            yield \sprintf('#%03d %s %s', $i, $case['expr'], \json_encode($case['values'])) => [$case['expr'], $case['values'], $case['expected']];
        }
    }

    /**
     * @return array{context: array<string, mixed>, fields: array<string, mixed>, cases: list<array{expr: string, values: array<string, mixed>, expected: mixed}>}
     */
    private static function load(): array
    {
        return \json_decode((string) \file_get_contents(self::FILE), true, 32, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $values
     */
    #[DataProvider('vectors')]
    public function testVector(string $expr, array $values, mixed $expected): void
    {
        $doc = self::load();
        $ctx = $doc['context'];
        // sql = true: every vector must be valid where SQL is required
        $ast = (new TypeChecker())->check((new Parser())->parse($expr), new MapScope($doc['fields']), null, true);
        $result = (new Evaluator())->evaluate($ast, new ArrayValues($values), new Context(
            $ctx['user'],
            $ctx['groups'],
            $ctx['departments'],
            new DateTimeImmutable($ctx['now']),
            $ctx['timezone'],
        ));
        $this->assertSame($expected, $result);
    }

    public function testEverySqlFunctionAndOperatorHasAVector(): void
    {
        $sources = \implode("\n", \array_column(self::load()['cases'], 'expr'));
        foreach (Functions::LIST as $name => $sig) {
            if ($sig['sql']) {
                $this->assertMatchesRegularExpression('/\b' . $name . '\(/', $sources, "no vector for {$name}()");
            }
        }
        foreach (['+', '-', '*', '/', '&', '= ', '<>', '< ', '<=', '> ', '>=', ' IN '] as $op) {
            $this->assertStringContainsString($op, $sources, "no vector for '{$op}'");
        }
    }

    public function testMostVectorsAreBlankDriven(): void
    {
        $blank = \array_filter(self::load()['cases'], static fn (array $c): bool => $c['values'] === [] || \in_array('', $c['values'], true));
        $this->assertGreaterThan(40, \count($blank), 'the file is about blanks: keep most vectors blank-driven');
    }
}
