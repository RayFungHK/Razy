<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Razy\Rzx\Compile;

use Razy\Rzx\CompileError;
use Razy\Rzx\Expr\Binary;
use Razy\Rzx\Expr\Call;
use Razy\Rzx\Expr\Expr;
use Razy\Rzx\Expr\Index;
use Razy\Rzx\Expr\ListLiteral;
use Razy\Rzx\Expr\Literal;
use Razy\Rzx\Expr\MapLiteral;
use Razy\Rzx\Expr\Member;
use Razy\Rzx\Expr\Ternary;
use Razy\Rzx\Expr\Unary;
use Razy\Rzx\Expr\Variable;
use Razy\Rzx\Prop;
use Razy\Rzx\Source;

/**
 * Expression AST to PHP, plus the compile-time checks that need the scope:
 * every name must be a prop or a loop variable in scope, and a field read
 * on a declared shape must be one of its fields.
 *
 * Generated code reads the scope array `$__s` and calls the Runtime
 * `$__rt` for anything with semantics (access, truthiness, comparison,
 * functions), so no `.rzx` value ever reaches PHP as code: names are
 * validated identifiers and every literal goes through var_export().
 */
final class ExprGen
{
    /** @var list<array<string, Prop|null>> innermost last */
    private array $scopes = [];

    public function __construct(private readonly Source $source)
    {
    }

    /**
     * The value of a constant expression (literals, lists and maps of them).
     *
     * @return array{bool, mixed} [is constant, value]
     */
    public static function constant(Expr $expr): array
    {
        if ($expr instanceof Literal) {
            return [true, $expr->value];
        }
        if ($expr instanceof Unary && $expr->operator === '-' && $expr->operand instanceof Literal
            && (\is_int($expr->operand->value) || \is_float($expr->operand->value))) {
            return [true, -$expr->operand->value];
        }
        if ($expr instanceof ListLiteral || $expr instanceof MapLiteral) {
            $out = [];
            $pairs = $expr instanceof ListLiteral ? \array_map(static fn (Expr $e): array => [null, $e], $expr->items) : $expr->entries;
            foreach ($pairs as [$key, $value]) {
                [$ok, $v] = self::constant($value);
                if (!$ok) {
                    return [false, null];
                }
                if ($key === null) {
                    $out[] = $v;
                    continue;
                }
                [$ok, $k] = self::constant($key);
                if (!$ok || (!\is_string($k) && !\is_int($k))) {
                    return [false, null];
                }
                $out[$k] = $v;
            }

            return [true, $out];
        }

        return [false, null];
    }

    /**
     * @param array<string, Prop|null> $names
     */
    public function push(array $names): void
    {
        $this->scopes[] = $names;
    }

    public function pop(): void
    {
        \array_pop($this->scopes);
    }

    public function declared(string $name): bool
    {
        foreach ($this->scopes as $scope) {
            if (\array_key_exists($name, $scope)) {
                return true;
            }
        }

        return false;
    }

    public function compile(Expr $expr): string
    {
        return match (true) {
            $expr instanceof Literal => \var_export($expr->value, true),
            $expr instanceof Variable => $this->variable($expr),
            $expr instanceof Member => $this->member($expr),
            $expr instanceof Index => '$__rt->get(' . $this->compile($expr->object) . ', $__rt->key(' . $this->compile($expr->index) . '))',
            $expr instanceof Call => $this->call($expr),
            $expr instanceof Unary => $expr->operator === '!'
                ? '!$__rt->truthy(' . $this->compile($expr->operand) . ')'
                : '(-$__rt->num(' . $this->compile($expr->operand) . '))',
            $expr instanceof Binary => $this->binary($expr),
            $expr instanceof Ternary => '($__rt->truthy(' . $this->compile($expr->test) . ') ? ' . $this->compile($expr->then) . ' : ' . $this->compile($expr->else) . ')',
            $expr instanceof ListLiteral => '[' . \implode(', ', \array_map($this->compile(...), $expr->items)) . ']',
            $expr instanceof MapLiteral => $this->map($expr),
            default => throw $this->error('RZX100', 'Unsupported expression', $expr),
        };
    }

    /**
     * The declared type of an expression, when the schema knows it.
     */
    public function typeOf(Expr $expr): ?Prop
    {
        if ($expr instanceof Variable) {
            for ($i = \count($this->scopes) - 1; $i >= 0; --$i) {
                if (\array_key_exists($expr->name, $this->scopes[$i])) {
                    return $this->scopes[$i][$expr->name];
                }
            }

            return null;
        }
        if ($expr instanceof Member) {
            $base = $this->typeOf($expr->object);

            return $base?->type === 'shape' ? ($base->fields[$expr->name] ?? null) : (($base?->type === 'map') ? $base->item : null);
        }
        if ($expr instanceof Index) {
            $base = $this->typeOf($expr->object);

            return $base !== null && ($base->type === 'list' || $base->type === 'map') ? $base->item : null;
        }

        return null;
    }

    private function variable(Variable $expr): string
    {
        if (!$this->declared($expr->name)) {
            throw $this->error('RZX101', 'Unknown name `' . $expr->name . '`; declare it in <props> or as a <for> variable', $expr);
        }

        return '($__s[' . \var_export($expr->name, true) . '] ?? null)';
    }

    private function member(Member $expr): string
    {
        $base = $this->typeOf($expr->object);
        if ($base !== null && $base->type === 'shape' && !isset($base->fields[$expr->name])) {
            $fields = \array_keys($base->fields);
            throw $this->error('RZX102', 'No field `' . $expr->name . '` in this shape (fields: ' . \implode(', ', $fields) . ')', $expr);
        }

        return '$__rt->get(' . $this->compile($expr->object) . ', ' . \var_export($expr->name, true) . ')';
    }

    private function call(Call $expr): string
    {
        $args = \array_map($this->compile(...), $expr->arguments);
        $arity = static fn (int $n) => \count($args) === $n;

        return match ($expr->name) {
            'count' => $arity(1) ? '$__rt->count(' . $args[0] . ')' : throw $this->error('RZX103', 'count() takes one argument', $expr),
            'json' => $arity(1) ? '$__rt->json(' . $args[0] . ')' : throw $this->error('RZX103', 'json() takes one argument', $expr),
            'html' => throw $this->error('RZX137', 'html(…) can only be the whole of a text expression: {html(x)}', $expr),
            default => '$__rt->call(' . \var_export($expr->name, true) . ', [' . \implode(', ', $args) . '])',
        };
    }

    private function binary(Binary $expr): string
    {
        $left = $this->compile($expr->left);
        $right = $this->compile($expr->right);

        return match ($expr->operator) {
            '&&', '||' => '($__rt->truthy(' . $left . ') ' . $expr->operator . ' $__rt->truthy(' . $right . '))',
            '??' => '(' . $left . ' ?? ' . $right . ')',
            '==' => '$__rt->eq(' . $left . ', ' . $right . ')',
            '!=' => '!$__rt->eq(' . $left . ', ' . $right . ')',
            '<', '<=', '>', '>=' => '$__rt->cmp(' . $left . ', ' . \var_export($expr->operator, true) . ', ' . $right . ')',
            '~' => '($__rt->str(' . $left . ') . $__rt->str(' . $right . '))',
            '+', '-' => '($__rt->num(' . $left . ') ' . $expr->operator . ' $__rt->num(' . $right . '))',
            default => throw $this->error('RZX100', 'Unsupported operator ' . $expr->operator, $expr),
        };
    }

    private function map(MapLiteral $expr): string
    {
        $items = [];
        foreach ($expr->entries as [$key, $value]) {
            $k = $key instanceof Literal && \is_string($key->value) ? \var_export($key->value, true) : '$__rt->key(' . $this->compile($key) . ')';
            $items[] = $k . ' => ' . $this->compile($value);
        }

        return '[' . \implode(', ', $items) . ']';
    }

    private function error(string $code, string $reason, Expr $at): CompileError
    {
        return new CompileError($code, $reason, $this->source, $at->offset);
    }
}
