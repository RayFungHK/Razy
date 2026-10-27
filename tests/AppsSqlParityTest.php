<?php

declare(strict_types=1);

namespace Razy\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use razymod\apps\Expression\ArrayValues;
use razymod\apps\Expression\Context;
use razymod\apps\Expression\Decimal;
use razymod\apps\Expression\Evaluator;
use razymod\apps\Expression\Functions;
use razymod\apps\Expression\MapScope;
use razymod\apps\Expression\Parser;
use razymod\apps\Expression\TypeChecker;

require_once __DIR__ . '/fixtures/apps/autoload.php';

/**
 * SQL parity vectors (tests/fixtures/apps/sql-parity.json): the blank,
 * decimal, comparison and time-zone semantics of the expression language,
 * as data. The PHP evaluator must pass every vector here; the SQL
 * translation of filters and row rules (RZA-C) reuses the same file and must
 * pass it too, so the two never disagree about which rows match or what a
 * value is.
 */
#[CoversNothing]
class AppsSqlParityTest extends TestCase
{
    private const FILE = __DIR__ . '/fixtures/apps/sql-parity.json';

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function vectors(): iterable
    {
        foreach (self::load()['cases'] as $i => $case) {
            yield \sprintf('#%03d %s %s', $i, $case['expr'], \json_encode($case['values'])) => [$case];
        }
    }

    /**
     * @return array{context: array<string, mixed>, fields: array<string, mixed>, cases: list<array<string, mixed>>}
     */
    private static function load(): array
    {
        return \json_decode((string) \file_get_contents(self::FILE), true, 32, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('vectors')]
    public function testVector(array $case): void
    {
        $doc = self::load();
        $ctx = $doc['context'];
        // sql = true: every vector must be valid where SQL is required
        $ast = (new TypeChecker())->check((new Parser())->parse($case['expr']), new MapScope($doc['fields']), null, true);
        $result = (new Evaluator())->evaluate($ast, new ArrayValues($case['values']), new Context(
            $ctx['user'],
            $ctx['groups'],
            $ctx['departments'],
            new DateTimeImmutable($case['now'] ?? $ctx['now']),
            $case['timezone'] ?? $ctx['timezone'],
        ));
        if (isset($case['type'])) {
            $this->assertSame($case['type'], $ast['t'], 'result type');
        }
        if ($result instanceof Decimal) {
            $this->assertSame('decimal', $case['type'] ?? null, 'a decimal result needs "type": "decimal"');
            $result = (string) $result;
        }
        $this->assertSame($case['expected'], $result);
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

    public function testEveryFunctionsBlankPolicyIsPinnedByAVector(): void
    {
        $pinned = [];
        foreach (self::load()['cases'] as $case) {
            if (!isset($case['blank'])) {
                continue;
            }
            \preg_match('/^=([A-Z_]+)\(/', $case['expr'], $m);
            $this->assertNotEmpty($m, "{$case['expr']}: a blank-policy vector starts with its function");
            $this->assertSame(Functions::get($m[1])['blank'], $case['blank'], "{$case['expr']}: policy differs from Functions::LIST");
            $pinned[$m[1]] = true;
        }
        foreach (Functions::LIST as $name => $sig) {
            $this->assertContains($sig['blank'], Functions::BLANK_POLICIES, $name);
            if ($sig['sql'] && $sig['blank'] !== 'none') {
                $this->assertArrayHasKey($name, $pinned, "no blank-policy vector for {$name}()");
            }
        }
    }
}
