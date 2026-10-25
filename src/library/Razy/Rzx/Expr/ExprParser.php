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

namespace Razy\Rzx\Expr;

use Razy\Rzx\CompileError;
use Razy\Rzx\Source;

/**
 * Recursive-descent parser for the `{…}` expression grammar.
 *
 * Precedence, lowest first:
 *
 *     ternary   a ? b : c            (right-associative)
 *     coalesce  a ?? b               (right-associative)
 *     or        a || b
 *     and       a && b
 *     equality  a == b, a != b       (not chainable)
 *     compare   a < b, <=, >, >=     (not chainable)
 *     concat    a ~ b
 *     additive  a + b, a - b
 *     unary     !a, -a
 *     postfix   a.b, a[b], f(x)
 *     primary   literal, name, (…), […], {…}
 *
 * Only a bare name can be called. When a function list is given, an unknown
 * name is a compile error here; without one, the check is left to a later
 * stage.
 */
final class ExprParser
{
    private const MAX_DEPTH = 64;

    /** @var list<ExprToken> */
    private array $tokens = [];

    private int $pos = 0;

    private int $depth = 0;

    private Source $source;

    /**
     * @param list<string>|null $functions names callable from an expression; null = do not check
     */
    public function __construct(private readonly ?array $functions = null)
    {
    }

    /**
     * Parse a whole string as one expression (used by tests and tools).
     */
    public function parseString(string $expression, string $file = '<expr>'): Expr
    {
        $source = new Source($expression, $file);
        [$tokens] = ExprLexer::scan($source, 0, false);

        return $this->parseTokens($source, $tokens, 0);
    }

    /**
     * @param list<ExprToken> $tokens as returned by ExprLexer::scan (ending with END)
     * @param int $braceOffset offset of the opening `{` (or of the expression), for an empty-expression error
     */
    public function parseTokens(Source $source, array $tokens, int $braceOffset): Expr
    {
        $this->source = $source;
        $this->tokens = $tokens;
        $this->pos = 0;
        $this->depth = 0;
        if ($this->peek()->type === ExprToken::END) {
            throw new CompileError('RZX040', 'Empty expression', $source, $braceOffset);
        }
        $expr = $this->ternary();
        $next = $this->peek();
        if ($next->type !== ExprToken::END) {
            throw $this->error('RZX041', 'Unexpected ' . $next->describe() . ' after the expression', $next);
        }

        return $expr;
    }

    private function ternary(): Expr
    {
        $this->enter();
        $test = $this->coalesce();
        if ($this->peek()->is('?')) {
            $this->pos++;
            $then = $this->ternary();
            $this->expect(':', 'in a `? :` expression');
            $else = $this->ternary();
            $test = new Ternary($test->offset, $test, $then, $else);
        }
        --$this->depth;

        return $test;
    }

    private function coalesce(): Expr
    {
        $left = $this->binary(0);
        if ($this->peek()->is('??')) {
            $this->pos++;
            $this->enter();
            $right = $this->coalesce();
            --$this->depth;

            return new Binary($left->offset, '??', $left, $right);
        }

        return $left;
    }

    /**
     * Left-associative binary levels; equality and comparison do not chain.
     */
    private function binary(int $level): Expr
    {
        static $levels = [['||'], ['&&'], ['==', '!='], ['<', '<=', '>', '>='], ['~'], ['+', '-']];
        if ($level === \count($levels)) {
            return $this->unary();
        }
        $operators = $levels[$level];
        $left = $this->binary($level + 1);
        $chained = false;
        while ($this->peek()->type === ExprToken::PUNCT && \in_array($this->peek()->value, $operators, true)) {
            $operator = (string) $this->next()->value;
            $right = $this->binary($level + 1);
            if ($chained && ($level === 2 || $level === 3)) {
                throw new CompileError('RZX042', 'Comparisons do not chain; combine them with `&&`', $this->source, $right->offset);
            }
            $chained = true;
            $left = new Binary($left->offset, $operator, $left, $right);
        }

        return $left;
    }

    private function unary(): Expr
    {
        $token = $this->peek();
        if ($token->is('!') || $token->is('-')) {
            $this->pos++;
            $this->enter();
            $operand = $this->unary();
            --$this->depth;

            return new Unary($token->offset, (string) $token->value, $operand);
        }

        return $this->postfix();
    }

    private function postfix(): Expr
    {
        $expr = $this->primary();
        while (true) {
            $token = $this->peek();
            if ($token->is('.')) {
                $this->pos++;
                $name = $this->next();
                if ($name->type !== ExprToken::IDENT) {
                    $hint = $name->type === ExprToken::NUMBER ? '; write `x[' . $name->value . ']` for a list index' : '';
                    throw $this->error('RZX043', 'Expected a property name after `.`, found ' . $name->describe() . $hint, $name);
                }
                $expr = new Member($expr->offset, $expr, (string) $name->value);
            } elseif ($token->is('[')) {
                $this->pos++;
                $index = $this->ternary();
                $this->expect(']', 'to close `[`');
                $expr = new Index($expr->offset, $expr, $index);
            } elseif ($token->is('(')) {
                if (!$expr instanceof Variable) {
                    throw $this->error('RZX044', 'Only a registered function can be called, e.g. `count(x)`; methods and computed callees cannot', $token);
                }
                $expr = $this->call($expr);
            } else {
                return $expr;
            }
        }
    }

    private function call(Variable $callee): Call
    {
        if ($this->functions !== null && !\in_array($callee->name, $this->functions, true)) {
            throw new CompileError('RZX045', 'Unknown function `' . $callee->name . '`', $this->source, $callee->offset);
        }
        $this->pos++; // (
        $arguments = $this->items(')', 'to close the argument list');

        return new Call($callee->offset, $callee->name, $arguments);
    }

    private function primary(): Expr
    {
        $token = $this->next();
        switch ($token->type) {
            case ExprToken::NUMBER:
            case ExprToken::STRING:
                return new Literal($token->offset, $token->value);
            case ExprToken::IDENT:
                return match ($token->value) {
                    'true' => new Literal($token->offset, true),
                    'false' => new Literal($token->offset, false),
                    'null' => new Literal($token->offset, null),
                    default => new Variable($token->offset, (string) $token->value),
                };
        }
        if ($token->is('(')) {
            $inner = $this->ternary();
            $this->expect(')', 'to close `(`');

            return $inner;
        }
        if ($token->is('[')) {
            $this->enter();
            $items = $this->items(']', 'to close the list');
            --$this->depth;

            return new ListLiteral($token->offset, $items);
        }
        if ($token->is('{')) {
            $this->enter();
            $map = $this->map($token->offset);
            --$this->depth;

            return $map;
        }

        throw $this->error('RZX041', 'Expected a value, found ' . $token->describe(), $token);
    }

    /**
     * Comma-separated expressions up to $close; a trailing comma is allowed.
     *
     * @return list<Expr>
     */
    private function items(string $close, string $context): array
    {
        $items = [];
        while (!$this->peek()->is($close)) {
            $items[] = $this->ternary();
            if (!$this->peek()->is(',')) {
                break;
            }
            $this->pos++;
        }
        $this->expect($close, $context);

        return $items;
    }

    private function map(int $offset): MapLiteral
    {
        $entries = [];
        while (!$this->peek()->is('}')) {
            $token = $this->next();
            if ($token->type === ExprToken::IDENT || $token->type === ExprToken::STRING) {
                $key = new Literal($token->offset, (string) $token->value);
            } elseif ($token->is('[')) {
                $key = $this->ternary();
                $this->expect(']', 'to close a computed key');
            } else {
                throw $this->error('RZX046', 'Expected a map key (name, string or `[expr]`), found ' . $token->describe(), $token);
            }
            $this->expect(':', 'after a map key');
            $entries[] = [$key, $this->ternary()];
            if (!$this->peek()->is(',')) {
                break;
            }
            $this->pos++;
        }
        $this->expect('}', 'to close the map');

        return new MapLiteral($offset, $entries);
    }

    private function expect(string $punct, string $context): void
    {
        $token = $this->next();
        if (!$token->is($punct)) {
            throw $this->error('RZX041', 'Expected `' . $punct . '` ' . $context . ', found ' . $token->describe(), $token);
        }
    }

    private function enter(): void
    {
        if (++$this->depth > self::MAX_DEPTH) {
            throw $this->error('RZX047', 'Expression is nested too deeply (limit ' . self::MAX_DEPTH . ')', $this->peek());
        }
    }

    private function peek(): ExprToken
    {
        return $this->tokens[$this->pos];
    }

    private function next(): ExprToken
    {
        $token = $this->tokens[$this->pos];
        if ($token->type !== ExprToken::END) {
            ++$this->pos;
        }

        return $token;
    }

    private function error(string $code, string $reason, ExprToken $at): CompileError
    {
        return new CompileError($code, $reason, $this->source, $at->offset);
    }
}
