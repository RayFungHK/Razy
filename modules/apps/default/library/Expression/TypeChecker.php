<?php

/**
 * razymod/apps — types an AST against a Scope and the closed function list.
 *
 * Input is an untyped AST from Parser (or a stored AST, which is re-checked
 * node by node: unknown node kinds, operators or shapes are refused, so a
 * tampered stored AST cannot smuggle anything the parser would not build).
 * Output is the same tree with a 't' (type) on every node; field nodes keep
 * their path, call nodes their upper-case name.
 *
 * $sql = true is the rule for saved filters and row rules: every function
 * must have a SQL translation and every field must be readable in SQL.
 */

declare(strict_types=1);

namespace razymod\apps\Expression;

use razymod\apps\Caps;

final class TypeChecker
{
    private const ARITH = ['+' => true, '-' => true, '*' => true, '/' => true];

    private const EQUALITY = ['=' => true, '<>' => true];

    private const ORDERING = ['<' => true, '<=' => true, '>' => true, '>=' => true];

    private Scope $scope;

    private bool $sql = false;

    private int $count = 0;

    public function __construct(private readonly Caps $caps = new Caps())
    {
    }

    /**
     * @param array<string, mixed> $ast
     * @param ?string $expect required result type (a Types name or 'scalar' / 'any'), null = any
     *
     * @return array<string, mixed> typed AST
     *
     * @throws ExpressionError
     */
    public function check(array $ast, Scope $scope, ?string $expect = null, bool $sql = false): array
    {
        $cap = $this->caps->get('expression_depth');
        if (Parser::depth($ast, $cap + 1) > $cap) {
            throw new ExpressionError(ExpressionError::DEPTH, "Expression is nested deeper than {$cap}", 0);
        }
        $this->scope = $scope;
        $this->sql = $sql;
        $this->count = 0;

        $typed = $this->type($ast);
        if ($expect !== null && !Types::accepts($expect, $typed['t'])) {
            throw new ExpressionError(
                ExpressionError::TYPE,
                "Expression gives {$typed['t']}, expected {$expect}",
                (int) ($typed['at'] ?? 0),
            );
        }

        return $typed;
    }

    /**
     * @param mixed $node
     *
     * @return array<string, mixed>
     */
    private function type(mixed $node): array
    {
        if (!\is_array($node) || !\is_string($node['k'] ?? null)) {
            throw new ExpressionError(ExpressionError::SYNTAX, 'Malformed expression node', 0);
        }
        if (++$this->count > $this->caps->get('expression_nodes')) {
            throw new ExpressionError(
                ExpressionError::NODES,
                'Expression has more than ' . $this->caps->get('expression_nodes') . ' parts',
                0,
            );
        }
        $at = \is_int($node['at'] ?? null) ? $node['at'] : 0;

        switch ($node['k']) {
            case 'num':
                if (!\is_string($node['v'] ?? null) || \strlen($node['v']) > 61 || !Decimal::isValid($node['v'])) {
                    throw $this->malformed($at);
                }

                return ['k' => 'num', 'v' => $node['v'], 't' => 'decimal', 'at' => $at];

            case 'str':
                if (!\is_string($node['v'] ?? null) || \preg_match('//u', $node['v']) !== 1) {
                    throw $this->malformed($at);
                }
                if (\strlen($node['v']) > $this->caps->get('literal_length')) {
                    throw new ExpressionError(ExpressionError::LITERAL, 'String literal is too long', $at);
                }

                return ['k' => 'str', 'v' => $node['v'], 't' => 'text', 'at' => $at];

            case 'bool':
                if (!\is_bool($node['v'] ?? null)) {
                    throw $this->malformed($at);
                }

                return ['k' => 'bool', 'v' => $node['v'], 't' => 'boolean', 'at' => $at];

            case 'field':
                return $this->field($node, $at);

            case 'call':
                return $this->call($node, $at);

            case 'op':
                return $this->op($node, $at);

            case 'list':
                throw new ExpressionError(ExpressionError::SYNTAX, 'A list is only allowed after IN', $at);
        }

        throw $this->malformed($at);
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private function field(array $node, int $at): array
    {
        $path = $node['path'] ?? null;
        if (!\is_array($path) || $path === [] || \count($path) > 8 || !\array_is_list($path)) {
            throw $this->malformed($at);
        }
        foreach ($path as $seg) {
            if (!\is_string($seg) || \preg_match('/^[a-z][a-z0-9_]{0,39}$/', $seg) !== 1) {
                throw $this->malformed($at);
            }
        }
        $info = $this->scope->resolve($path, $at);
        if ($this->sql && !$info['sql']) {
            throw new ExpressionError(
                ExpressionError::NOT_SQL,
                "'" . \implode('.', $path) . "' cannot be used in a filter or row rule (not stored)",
                $at,
            );
        }
        $typed = ['k' => 'field', 'path' => $path, 't' => $info['type'], 'at' => $at];
        if (\is_int($info['scale'] ?? null)) {
            // the field's declared scale: values are read at it (money: 2)
            $typed['scale'] = $info['scale'];
        }
        if ($info['options'] !== null) {
            $typed['options'] = $info['options'];
        }

        return $typed;
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private function call(array $node, int $at): array
    {
        $name = $node['fn'] ?? null;
        $args = $node['args'] ?? null;
        if (!\is_string($name) || !\is_array($args) || !\array_is_list($args)) {
            throw $this->malformed($at);
        }
        if (!Functions::has($name)) {
            throw new ExpressionError(ExpressionError::UNKNOWN_FUNCTION, "Unknown function {$name}()", $at);
        }
        $sig = Functions::get($name);
        $n = \count($args);
        $max = $sig['max'] ?? $this->caps->get('expression_args');
        if ($n < $sig['min'] || $n > $max) {
            $want = $sig['min'] === $max ? (string) $max : "{$sig['min']}-{$max}";

            throw new ExpressionError(ExpressionError::ARITY, "{$name}() takes {$want} arguments, got {$n}", $at);
        }
        if ($this->sql && !$sig['sql']) {
            throw new ExpressionError(
                ExpressionError::NOT_SQL,
                "{$name}() cannot be used in a filter or row rule",
                $at,
            );
        }

        $typedArgs = [];
        $generic = 'blank';
        foreach ($args as $i => $arg) {
            $typed = $this->type($arg);
            $param = Functions::paramType($name, $i);
            if ($param === 'T') {
                $u = Types::unify($generic, $typed['t']);
                if ($u === null || $u === 'opaque' || Types::isList($u)) {
                    throw new ExpressionError(
                        ExpressionError::TYPE,
                        "{$name}() arguments mix {$generic} and {$typed['t']}",
                        (int) $typed['at'],
                    );
                }
                $generic = $u;
            } elseif (!Types::accepts($param, $typed['t'])) {
                throw new ExpressionError(
                    ExpressionError::TYPE,
                    "{$name}() argument " . ($i + 1) . " must be {$param}, got {$typed['t']}",
                    (int) $typed['at'],
                );
            }
            $typedArgs[] = $typed;
        }
        if (($name === 'MIN' || $name === 'MAX') && $generic !== 'blank' && !\in_array($generic, Functions::ORDERED, true)) {
            throw new ExpressionError(ExpressionError::TYPE, "{$name}() needs numbers, text or dates", $at);
        }
        $ret = match ($sig['ret']) {
            'T' => $generic,
            // exact unless a float argument takes part
            'N' => \in_array('number', \array_column($typedArgs, 't'), true) ? 'number' : 'decimal',
            default => $sig['ret'],
        };

        return ['k' => 'call', 'fn' => $name, 'args' => $typedArgs, 't' => $ret, 'at' => $at];
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private function op(array $node, int $at): array
    {
        $op = $node['op'] ?? null;
        $args = $node['args'] ?? null;
        if (!\is_string($op) || !\is_array($args) || !\array_is_list($args)) {
            throw $this->malformed($at);
        }

        if ($op === 'neg') {
            if (\count($args) !== 1) {
                throw $this->malformed($at);
            }
            $x = $this->type($args[0]);
            if (!Types::accepts('number', $x['t'])) {
                throw new ExpressionError(ExpressionError::TYPE, "Cannot negate {$x['t']}", $at);
            }

            return ['k' => 'op', 'op' => 'neg', 'args' => [$x], 't' => $x['t'] === 'number' ? 'number' : 'decimal', 'at' => $at];
        }

        if (\count($args) !== 2) {
            throw $this->malformed($at);
        }

        if ($op === 'in') {
            return $this->in($args, $at);
        }

        $l = $this->type($args[0]);
        $r = $this->type($args[1]);
        $lt = $l['t'];
        $rt = $r['t'];

        if (isset(self::ARITH[$op])) {
            $t = $this->arith($op, $lt, $rt);
            if ($t === null) {
                throw new ExpressionError(ExpressionError::TYPE, "Cannot apply {$op} to {$lt} and {$rt}", $at);
            }

            return ['k' => 'op', 'op' => $op, 'args' => [$l, $r], 't' => $t, 'at' => $at];
        }
        if ($op === '&') {
            if (!Types::accepts('scalar', $lt) || !Types::accepts('scalar', $rt)) {
                throw new ExpressionError(ExpressionError::TYPE, "Cannot join {$lt} and {$rt} as text", $at);
            }

            return ['k' => 'op', 'op' => '&', 'args' => [$l, $r], 't' => 'text', 'at' => $at];
        }
        if (isset(self::EQUALITY[$op]) || isset(self::ORDERING[$op])) {
            $ok = isset(self::EQUALITY[$op]) ? Types::equatable($lt, $rt) : Types::ordered($lt, $rt);
            if (!$ok) {
                throw new ExpressionError(ExpressionError::TYPE, "Cannot compare {$lt} {$op} {$rt}", $at);
            }
            $this->checkOption($l, $r);
            $this->checkOption($r, $l);

            return ['k' => 'op', 'op' => $op, 'args' => [$l, $r], 't' => 'boolean', 'at' => $at];
        }

        throw $this->malformed($at);
    }

    /**
     * @param list<mixed> $args
     *
     * @return array<string, mixed>
     */
    private function in(array $args, int $at): array
    {
        $l = $this->type($args[0]);
        if (!Types::equatable($l['t'], $l['t'])) {
            throw new ExpressionError(ExpressionError::TYPE, "Cannot test {$l['t']} with IN", $at);
        }
        $right = $args[1];
        if (\is_array($right) && ($right['k'] ?? null) === 'list') {
            $items = $right['items'] ?? null;
            if (!\is_array($items) || $items === [] || !\array_is_list($items)
                || \count($items) > $this->caps->get('expression_args')) {
                throw $this->malformed($at);
            }
            ++$this->count;
            $typedItems = [];
            foreach ($items as $item) {
                $ti = $this->type($item);
                if (!Types::equatable($l['t'], $ti['t'])) {
                    throw new ExpressionError(ExpressionError::TYPE, "IN list mixes {$l['t']} and {$ti['t']}", $at);
                }
                $this->checkOption($l, $ti);
                $typedItems[] = $ti;
            }
            $listAt = \is_int($right['at'] ?? null) ? $right['at'] : $at;
            $r = ['k' => 'list', 'items' => $typedItems, 't' => 'list:' . $l['t'], 'at' => $listAt];
        } else {
            $r = $this->type($right);
            if (!Types::isList($r['t']) || !Types::equatable($l['t'], Types::itemType($r['t']))) {
                throw new ExpressionError(ExpressionError::TYPE, "IN needs a list of {$l['t']}, got {$r['t']}", $at);
            }
            $this->checkOption($r, $l);
        }

        return ['k' => 'op', 'op' => 'in', 'args' => [$l, $r], 't' => 'boolean', 'at' => $at];
    }

    private function arith(string $op, string $l, string $r): ?string
    {
        $num = static fn (string $t): bool => Types::isNumeric($t);
        if ($num($l) && $num($r)) {
            // exact unless a float takes part
            return ($l === 'number' || $r === 'number') ? 'number' : 'decimal';
        }
        if ($op === '*' || $op === '/') {
            return null;
        }
        $dateish = static fn (string $t): bool => $t === 'date' || $t === 'datetime';
        if ($dateish($l) && $num($r)) {
            return $l;
        }
        if ($op === '+' && $num($l) && $dateish($r)) {
            return $r;
        }
        if ($op === '-' && $dateish($l) && $dateish($r)) {
            return 'decimal';
        }

        return null;
    }

    /**
     * A text literal compared with a field that has a closed option list
     * must be one of those options (catches typos in drafted filters).
     *
     * @param array<string, mixed> $field
     * @param array<string, mixed> $literal
     */
    private function checkOption(array $field, array $literal): void
    {
        if (!isset($field['options']) || ($literal['k'] ?? null) !== 'str') {
            return;
        }
        if (!\in_array($literal['v'], $field['options'], true)) {
            throw new ExpressionError(
                ExpressionError::UNKNOWN_OPTION,
                "'{$literal['v']}' is not an option of " . \implode('.', $field['path']),
                (int) $literal['at'],
            );
        }
    }

    private function malformed(int $at): ExpressionError
    {
        return new ExpressionError(ExpressionError::SYNTAX, 'Malformed expression node', $at);
    }
}
