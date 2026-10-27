<?php

declare(strict_types=1);

namespace Razy\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use razymod\apps\Caps;
use razymod\apps\Compiler;
use razymod\apps\Definition\ElementIds;
use razymod\apps\Definition\PackageLoader;
use razymod\apps\Definition\Validator;
use razymod\apps\Expression\ArrayValues;
use razymod\apps\Expression\Context;
use razymod\apps\Expression\Evaluator;
use razymod\apps\Expression\ExpressionError;
use razymod\apps\Expression\Functions;
use razymod\apps\Expression\MapScope;
use razymod\apps\Expression\Parser;
use razymod\apps\Expression\TypeChecker;
use razymod\apps\Weight;
use Throwable;

require_once __DIR__ . '/fixtures/apps/autoload.php';

/**
 * Fuzz for the expression pipeline and the definition validator
 * (manual/15-razy-apps.md §5.2, formula / expression injection).
 *
 * Expressions come from users and from AI, so for ANY input the contract is:
 * the parser, type checker and evaluator either succeed or throw
 * ExpressionError with a known code, never another Throwable, never a PHP
 * warning, and never more than the step budget. The validator never throws.
 *
 * Seeded with mt_srand so a failure is reproducible: the failing input is in
 * the assertion message. RAZY_APPS_FUZZ_ROUNDS raises the round count for a
 * longer local run.
 */
#[CoversNothing]
class AppsExpressionFuzzTest extends TestCase
{
    private const CODES = [
        ExpressionError::ENCODING, ExpressionError::LENGTH, ExpressionError::SYNTAX, ExpressionError::DEPTH,
        ExpressionError::NODES, ExpressionError::LITERAL, ExpressionError::UNKNOWN_FUNCTION, ExpressionError::ARITY,
        ExpressionError::TYPE, ExpressionError::UNKNOWN_FIELD, ExpressionError::UNKNOWN_OPTION, ExpressionError::CYCLE,
        ExpressionError::NOT_SQL, ExpressionError::BUDGET, ExpressionError::SIZE, ExpressionError::DIV_ZERO,
        ExpressionError::VALUE,
    ];

    private const TOKENS = [
        '=', '+', '-', '*', '/', '&', '<', '>', '<=', '>=', '<>', '(', ')', ',', '.', ' ', 'IN', 'TRUE', 'FALSE',
        '0', '1', '2.5', '99999999999999999999', '-1', "'a'", '"b"', "''", "'", '"', 'amount', 'title', 'done', 'due',
        'stage', 'probability', 'owner', 'tags', 'unknown', 'Amount', '$x', '`', ';', '[', ']', '{', '}', '\\', '#',
        '::', '->', 'system', 'eval', 'exec', 'phpinfo', 'SELECT', 'DROP', '--', '/*', "\x00", "\xff", '香港', '😀',
    ];

    private MapScope $scope;

    private Caps $caps;

    private int $rounds;

    protected function setUp(): void
    {
        \mt_srand(20261027);
        $this->rounds = \max(1, (int) (\getenv('RAZY_APPS_FUZZ_ROUNDS') ?: 1500));
        $this->caps = new Caps(['eval_steps' => 2000]);
        $this->scope = new MapScope([
            'amount' => 'number',
            'title' => 'text',
            'done' => 'boolean',
            'due' => 'date',
            'owner' => 'user',
            'tags' => ['type' => 'list:text', 'options' => ['vip', 'new']],
            'stage' => ['type' => 'status', 'options' => ['lead', 'won']],
            'stage.probability' => 'number',
        ]);
    }

    public function testRandomTokenSoupOnlyEverRaisesTypedErrors(): void
    {
        for ($i = 0; $i < $this->rounds; ++$i) {
            $n = \mt_rand(1, 40);
            $src = '';
            for ($j = 0; $j < $n; ++$j) {
                $src .= self::TOKENS[\mt_rand(0, \count(self::TOKENS) - 1)];
                if (\mt_rand(0, 3) === 0) {
                    $src .= ' ';
                }
            }
            if (\mt_rand(0, 9) === 0) {
                $src .= \chr(\mt_rand(0, 255));
            }
            $this->pipeline($src);
        }
    }

    public function testRandomWellFormedExpressionsOnlyEverRaiseTypedErrors(): void
    {
        $typed = 0;
        for ($i = 0; $i < $this->rounds; ++$i) {
            $src = '=' . $this->gen(\mt_rand(1, 6));
            if ($this->pipeline($src)) {
                ++$typed;
            }
        }
        // the generator must reach the evaluator often enough to mean something
        $this->assertGreaterThan($this->rounds / 20, $typed, 'too few generated expressions type-checked');
    }

    public function testMutatedTypedAstsFailClosedInCheckerAndEvaluator(): void
    {
        $seeds = [
            '=amount * stage.probability',
            "=AND(owner = CURRENT_USER(), stage <> 'won')",
            '=IF(done, LEFT(title, 3), CONCAT(title, "!"))',
            '=WORKDAY(due, 3) - due',
            "='vip' IN tags",
            '=ROUND(SUM(amount, 1, 2) / 3, 2)',
        ];
        $junk = [null, true, false, 0, -1, 1.5, \NAN, '', 'x', '1; DROP', [], [1], ['k' => 'num'], 'system', 'shell_exec',
            'list:text', 'number', 'datetime', \str_repeat('z', 300)];
        $keys = ['k', 'v', 't', 'fn', 'op', 'args', 'items', 'path', 'at'];
        $checker = new TypeChecker($this->caps);
        $evaluator = new Evaluator($this->caps);
        $values = new ArrayValues(['amount' => 5, 'title' => 'abc', 'done' => true, 'due' => '2026-10-27', 'owner' => 'u1',
            'tags' => ['vip'], 'stage' => 'lead', 'stage.probability' => 0.5]);
        $ctx = new Context('u1', [], [], new DateTimeImmutable('2026-10-27T00:00:00Z'));

        foreach ($seeds as $seed) {
            $base = $checker->check((new Parser($this->caps))->parse($seed), $this->scope);
            for ($i = 0; $i < (int) ($this->rounds / 3); ++$i) {
                $ast = $this->mutate($base, $junk, $keys);
                foreach ([
                    'checker' => fn () => $checker->check($ast, $this->scope),
                    'evaluator' => fn () => $evaluator->evaluate($ast, $values, $ctx),
                ] as $stage => $run) {
                    try {
                        $run();
                    } catch (ExpressionError $e) {
                        $this->assertContains($e->getErrorCode(), self::CODES);
                    } catch (Throwable $e) {
                        $this->fail("{$stage} threw " . $e::class . ': ' . $e->getMessage() . ' for ' . \json_encode($ast, JSON_PARTIAL_OUTPUT_ON_ERROR));
                    }
                }
            }
        }
    }

    public function testHugeAndDeepInputsAreRefusedCheaply(): void
    {
        $inputs = [
            '=' . \str_repeat('(', 200000),
            '=' . \str_repeat('1+', 100000) . '1',
            '=' . \str_repeat('NOT(', 30) . 'TRUE' . \str_repeat(')', 30),
            "='" . \str_repeat('x', 100000) . "'",
            '=CONCAT(' . \implode(',', \array_fill(0, 500, '"a"')) . ')',
            '=' . \str_repeat('a.', 50) . 'b',
        ];
        foreach ($inputs as $src) {
            $start = \microtime(true);
            try {
                (new TypeChecker())->check((new Parser())->parse($src), $this->scope);
                $this->fail('accepted a hostile input of ' . \strlen($src) . ' bytes');
            } catch (ExpressionError $e) {
                $this->assertContains($e->getErrorCode(), self::CODES);
            }
            $this->assertLessThan(1.0, \microtime(true) - $start, 'refusal took too long');
        }
    }

    public function testValidatorNeverThrowsOnMutatedDefinitions(): void
    {
        $base = [
            'apps_schema' => 1, 'code' => 'fuzz-app', 'version' => '1.0.0', 'roles' => ['rep'],
            'entities' => [[
                'id' => '01JBQ8AAAAAAAAAAAAAAAAAAAA', 'code' => 'deal',
                'fields' => [
                    ['id' => '01JBQ9AAAAAAAAAAAAAAAAAAAA', 'code' => 'amount', 'type' => 'money', 'currency' => 'HKD'],
                    ['id' => '01JBQAAAAAAAAAAAAAAAAAAAAA', 'code' => 'kind', 'type' => 'select', 'options' => ['a', 'b']],
                    ['id' => '01JBQBAAAAAAAAAAAAAAAAAAAA', 'code' => 'x', 'type' => 'computed', 'expr' => '=amount * 2'],
                ],
                'views' => ['list' => ['columns' => ['amount'], 'saved' => [['id' => '01JBQCAAAAAAAAAAAAAAAAAAAA', 'code' => 's', 'filter' => "=kind = 'a'"]]],
                    'board' => ['group_by' => 'kind', 'card' => ['amount']]],
            ]],
            'workflows' => [['id' => '01JBQDAAAAAAAAAAAAAAAAAAAA', 'code' => 'w', 'entity' => 'deal',
                'nodes' => [['id' => '01JBQEAAAAAAAAAAAAAAAAAAAA', 'code' => 'a', 'type' => 'trigger', 'on' => 'created'],
                    ['id' => '01JBQFAAAAAAAAAAAAAAAAAAAA', 'code' => 'b', 'type' => 'end']],
                'edges' => [['from' => 'a', 'to' => 'b']]]],
            'permissions' => ['rules' => [['role' => 'rep', 'entity' => 'deal', 'ops' => ['read'], 'row' => '=amount > 0']]],
            'i18n' => ['en' => ['app.label' => 'Fuzz']],
        ];
        $junk = [null, true, 0, -5, 1.5, '', 'x', '=1/0', '=system()', [], [[]], ['a' => 1], [1, 2], \str_repeat('y', 500),
            ['code' => ['x']], 'deal', 'crm-core.company', 'many_to_one', 'stage', '=stage.probability', '=company.name'];
        $loader = new PackageLoader();
        $core = $loader->load(__DIR__ . '/fixtures/apps/packages/crm-core')['definition'];
        $deals = $loader->load(__DIR__ . '/fixtures/apps/packages/crm-deals')['definition'];
        $validator = new Validator();
        $compiler = new Compiler();
        foreach ([[$base, null], [$core, null], [$deals, $core]] as [$tree, $dep]) {
            for ($i = 0; $i < $this->rounds; ++$i) {
                $def = $this->mutateTree($tree, $junk, \mt_rand(1, 4));
                $options = $dep === null ? [] : ['dependencies' => ['crm-core' => \mt_rand(0, 4) === 0 ? $this->mutateTree($dep, $junk, 2) : $dep],
                    'previous' => \mt_rand(0, 3) === 0 ? $tree : null];
                try {
                    // the API also hands unvalidated input to these two
                    Weight::counts($def);
                    ElementIds::assign($def, $options['previous'] ?? null);
                    $result = $validator->validate($def, $options);
                    if ($result->ok()) {
                        // whatever validates must also compile
                        $this->assertTrue($compiler->compile($def, $options)['ok']);
                    }
                } catch (Throwable $e) {
                    $this->fail('validator threw ' . $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine()
                        . ' for ' . \json_encode($def, JSON_PARTIAL_OUTPUT_ON_ERROR));
                }
            }
        }
    }

    // ── helpers ────────────────────────────────────────────────────

    /**
     * Parse, type-check and evaluate; true when it reached the evaluator.
     */
    private function pipeline(string $src): bool
    {
        $evaluator = new Evaluator($this->caps);
        try {
            $ast = (new Parser($this->caps))->parse($src);
            $typed = (new TypeChecker($this->caps))->check($ast, $this->scope);
            $values = new ArrayValues([
                'amount' => \mt_rand(-3, 3), 'title' => 'abc', 'done' => (bool) \mt_rand(0, 1), 'due' => '2026-10-27',
                'owner' => 'u1', 'tags' => ['vip'], 'stage' => 'lead', 'stage.probability' => 0.25,
            ]);
            $evaluator->evaluate($typed, $values, new Context('u1', ['g'], ['d'], new DateTimeImmutable('2026-10-27T00:00:00Z')));
            $this->assertLessThanOrEqual($this->caps->get('eval_steps'), $evaluator->steps());

            return true;
        } catch (ExpressionError $e) {
            $this->assertContains($e->getErrorCode(), self::CODES, 'for ' . \json_encode($src, JSON_INVALID_UTF8_SUBSTITUTE));
            $this->assertLessThanOrEqual($this->caps->get('eval_steps') + 1000, $evaluator->steps());
        } catch (Throwable $e) {
            $this->fail($e::class . ': ' . $e->getMessage() . ' for ' . \json_encode($src, JSON_INVALID_UTF8_SUBSTITUTE));
        }

        return false;
    }

    private function gen(int $depth): string
    {
        $leaves = ['amount', 'title', 'done', 'due', 'owner', 'tags', 'stage', 'stage.probability', '1', '0', '2.5', "'vip'",
            '"lead"', 'TRUE', 'FALSE', 'TODAY()', 'NOW()', 'CURRENT_USER()', 'MY_GROUPS()', 'BLANK()'];
        if ($depth <= 0 || \mt_rand(0, 3) === 0) {
            return $leaves[\mt_rand(0, \count($leaves) - 1)];
        }
        $ops = ['+', '-', '*', '/', '&', '=', '<>', '<', '<=', '>', '>='];
        switch (\mt_rand(0, 4)) {
            case 0:
                return $this->gen($depth - 1) . ' ' . $ops[\mt_rand(0, \count($ops) - 1)] . ' ' . $this->gen($depth - 1);
            case 1:
                return '-' . $this->gen($depth - 1);
            case 2:
                return '(' . $this->gen($depth - 1) . ')';
            case 3:
                return $this->gen($depth - 1) . ' IN (' . $this->gen($depth - 1) . ', ' . $this->gen($depth - 1) . ')';
            default:
                $names = \array_keys(Functions::LIST);
                $fn = $names[\mt_rand(0, \count($names) - 1)];
                $sig = Functions::get($fn);
                $n = \mt_rand($sig['min'], $sig['max'] ?? $sig['min'] + 2);
                $args = [];
                for ($i = 0; $i < $n; ++$i) {
                    $args[] = $this->gen($depth - 1);
                }

                return $fn . '(' . \implode(', ', $args) . ')';
        }
    }

    /**
     * Replace one random node property (or a whole node) with junk.
     *
     * @param array<string, mixed> $ast
     * @param list<mixed> $junk
     * @param list<string> $keys
     *
     * @return array<string, mixed>
     */
    private function mutate(array $ast, array $junk, array $keys): array
    {
        $children = $ast['args'] ?? $ast['items'] ?? [];
        if (\is_array($children) && $children !== [] && \mt_rand(0, 2) > 0) {
            $slot = isset($ast['args']) ? 'args' : 'items';
            $i = \array_rand($children);
            if (\is_array($children[$i])) {
                $ast[$slot][$i] = $this->mutate($children[$i], $junk, $keys);

                return $ast;
            }
        }
        $key = $keys[\mt_rand(0, \count($keys) - 1)];
        if (\mt_rand(0, 4) === 0) {
            unset($ast[$key]);
        } else {
            $ast[$key] = $junk[\mt_rand(0, \count($junk) - 1)];
        }

        return $ast;
    }

    /**
     * @param array<mixed> $tree
     * @param list<mixed> $junk
     *
     * @return array<mixed>
     */
    private function mutateTree(array $tree, array $junk, int $times): array
    {
        for ($t = 0; $t < $times; ++$t) {
            $tree = $this->mutateOnce($tree, $junk, 0);
        }

        return $tree;
    }

    /**
     * @param array<mixed> $node
     * @param list<mixed> $junk
     *
     * @return array<mixed>
     */
    private function mutateOnce(array $node, array $junk, int $depth): array
    {
        if ($node === []) {
            return [$junk[\mt_rand(0, \count($junk) - 1)]];
        }
        $key = \array_rand($node);
        if (\is_array($node[$key]) && $depth < 8 && \mt_rand(0, 3) > 0) {
            $node[$key] = $this->mutateOnce($node[$key], $junk, $depth + 1);
        } elseif (\mt_rand(0, 5) === 0) {
            unset($node[$key]);
        } else {
            $node[$key] = $junk[\mt_rand(0, \count($junk) - 1)];
        }

        return $node;
    }
}
