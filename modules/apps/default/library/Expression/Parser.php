<?php

/**
 * razymod/apps — Excel-like expression parser (manual/15-razy-apps.md §5).
 *
 * Source text → untyped AST (plain arrays, JSON-safe). No eval, no PHP
 * callbacks, no regex in the language: the grammar is fixed and small.
 *
 *   expr       := ['='] comparison EOF
 *   comparison := concat [ ('=' | '<>' | '<' | '<=' | '>' | '>=') concat
 *                        | IN ( '(' comparison {',' comparison} ')' | concat ) ]
 *   concat     := additive { '&' additive }
 *   additive   := term { ('+' | '-') term }
 *   term       := unary { ('*' | '/') unary }
 *   unary      := ('-' | '+') unary | primary
 *   primary    := NUMBER | STRING | TRUE | FALSE | NAME '(' [args] ')'
 *               | path | '(' comparison ')'
 *   path       := code { '.' code }          code = [a-z][a-z0-9_]*
 *
 * Comparisons do not chain (`a = b = c` is a syntax error). Function names
 * are case-insensitive and stored upper case; whether a name exists is the
 * type checker's call (it owns the closed list). Strings use single or
 * double quotes; a doubled quote is the only escape.
 *
 * Bounded everywhere: source length, literal length, AST depth (checked as
 * the tree is built, so a 10,000-deep input fails at the cap instead of
 * exhausting the PHP stack), node count and arguments per call.
 *
 * AST node shapes ('at' = byte offset in the source):
 *   {k:num, v:int|float}  {k:str, v:string}  {k:bool, v:bool}
 *   {k:field, path:[code…]}  {k:call, fn:NAME, args:[node…]}
 *   {k:op, op:'+'|'-'|'*'|'/'|'&'|'='|'<>'|'<'|'<='|'>'|'>='|'neg'|'in', args:[node…]}
 *   {k:list, items:[node…]}  (only as the right side of IN)
 */

declare(strict_types=1);

namespace razymod\apps\Expression;

use razymod\apps\Caps;

final class Parser
{
    private const T_NUM = 'num';

    private const T_STR = 'str';

    private const T_NAME = 'name';

    private const T_OP = 'op';

    private const T_EOF = 'eof';

    private const COMPARE = ['=' => true, '<>' => true, '<' => true, '<=' => true, '>' => true, '>=' => true];

    /** @var list<array{0: string, 1: string, 2: int}> */
    private array $tokens = [];

    private int $pos = 0;

    private int $nodes = 0;

    private int $guard = 0;

    public function __construct(private readonly Caps $caps = new Caps())
    {
    }

    /**
     * Depth of an AST (a leaf is 1). Shared with the type checker and the
     * evaluator so stored ASTs are re-checked against the same cap.
     *
     * @param mixed $node a node; anything that is not an array counts as a leaf
     */
    public static function depth(mixed $node, int $limit = 1000): int
    {
        if ($limit <= 0 || !\is_array($node)) {
            return 1;
        }
        $children = $node['args'] ?? $node['items'] ?? [];
        if (!\is_array($children)) {
            return 1;
        }
        $max = 0;
        foreach ($children as $child) {
            $max = \max($max, self::depth($child, $limit - 1));
        }

        return $max + 1;
    }

    /**
     * @return array<string, mixed> untyped AST
     *
     * @throws ExpressionError
     */
    public function parse(string $source): array
    {
        if ($source === '' || \preg_match('//u', $source) !== 1) {
            throw new ExpressionError(ExpressionError::ENCODING, 'Expression is empty or not valid UTF-8', 0);
        }
        if (\strlen($source) > $this->caps->get('expression_length')) {
            throw new ExpressionError(
                ExpressionError::LENGTH,
                'Expression is longer than ' . $this->caps->get('expression_length') . ' bytes',
                0,
            );
        }

        $this->tokens = $this->tokenize($source);
        $this->pos = 0;
        $this->nodes = 0;
        $this->guard = 0;

        if ($this->peek()[0] === self::T_OP && $this->peek()[1] === '=') {
            ++$this->pos;
        }
        $ast = $this->comparison();
        $tok = $this->peek();
        if ($tok[0] !== self::T_EOF) {
            throw $this->unexpected($tok);
        }

        return $ast;
    }

    /**
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private function tokenize(string $src): array
    {
        $tokens = [];
        $len = \strlen($src);
        $literalCap = $this->caps->get('literal_length');
        $i = 0;
        while ($i < $len) {
            $c = $src[$i];
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                ++$i;
                continue;
            }
            $start = $i;
            if (\ctype_digit($c)) {
                while ($i < $len && \ctype_digit($src[$i])) {
                    ++$i;
                }
                if ($i + 1 < $len && $src[$i] === '.' && \ctype_digit($src[$i + 1])) {
                    ++$i;
                    while ($i < $len && \ctype_digit($src[$i])) {
                        ++$i;
                    }
                }
                if ($i - $start > 30) {
                    throw new ExpressionError(ExpressionError::LITERAL, 'Number literal is longer than 30 digits', $start);
                }
                $tokens[] = [self::T_NUM, \substr($src, $start, $i - $start), $start];
                continue;
            }
            if ($c === "'" || $c === '"') {
                $value = '';
                ++$i;
                $closed = false;
                while ($i < $len) {
                    if ($src[$i] === $c) {
                        if ($i + 1 < $len && $src[$i + 1] === $c) {
                            $value .= $c;
                            $i += 2;
                            continue;
                        }
                        ++$i;
                        $closed = true;
                        break;
                    }
                    $value .= $src[$i];
                    ++$i;
                }
                if (!$closed) {
                    throw new ExpressionError(ExpressionError::SYNTAX, 'Unterminated string', $start);
                }
                if (\strlen($value) > $literalCap) {
                    throw new ExpressionError(
                        ExpressionError::LITERAL,
                        "String literal is longer than {$literalCap} bytes",
                        $start,
                    );
                }
                $tokens[] = [self::T_STR, $value, $start];
                continue;
            }
            if (\ctype_alpha($c) || $c === '_') {
                while ($i < $len && (\ctype_alnum($src[$i]) || $src[$i] === '_')) {
                    ++$i;
                }
                if ($i - $start > 64) {
                    throw new ExpressionError(ExpressionError::SYNTAX, 'Name is longer than 64 characters', $start);
                }
                $tokens[] = [self::T_NAME, \substr($src, $start, $i - $start), $start];
                continue;
            }
            $two = \substr($src, $i, 2);
            if ($two === '<>' || $two === '<=' || $two === '>=') {
                $tokens[] = [self::T_OP, $two, $start];
                $i += 2;
                continue;
            }
            if (\strpos('=<>+-*/&(),.', $c) !== false) {
                $tokens[] = [self::T_OP, $c, $start];
                ++$i;
                continue;
            }
            throw new ExpressionError(ExpressionError::SYNTAX, 'Unexpected character at offset ' . $start, $start);
        }
        $tokens[] = [self::T_EOF, '', $len];

        return $tokens;
    }

    /**
     * @return array<string, mixed>
     */
    private function comparison(): array
    {
        $this->enter();
        $left = $this->concat();
        $tok = $this->peek();
        if ($tok[0] === self::T_OP && isset(self::COMPARE[$tok[1]])) {
            ++$this->pos;
            $right = $this->concat();
            $left = $this->node(['k' => 'op', 'op' => $tok[1], 'args' => [$left, $right], 'at' => $tok[2]]);
        } elseif ($tok[0] === self::T_NAME && \strtoupper($tok[1]) === 'IN') {
            ++$this->pos;
            $left = $this->node(['k' => 'op', 'op' => 'in', 'args' => [$left, $this->inList()], 'at' => $tok[2]]);
        }
        $next = $this->peek();
        if (($next[0] === self::T_OP && isset(self::COMPARE[$next[1]]))
            || ($next[0] === self::T_NAME && \strtoupper($next[1]) === 'IN')) {
            throw new ExpressionError(
                ExpressionError::SYNTAX,
                'Comparisons do not chain; use AND() to combine them',
                $next[2],
            );
        }
        $this->leave();

        return $left;
    }

    /**
     * @return array<string, mixed>
     */
    private function inList(): array
    {
        $tok = $this->peek();
        if (!($tok[0] === self::T_OP && $tok[1] === '(')) {
            return $this->concat();
        }
        // '(' a, b, … ')' is a list; '(' a ')' is still a one-item list here.
        ++$this->pos;
        $this->enter();
        $items = [$this->comparison()];
        while ($this->peekOp(',')) {
            ++$this->pos;
            $items[] = $this->comparison();
            if (\count($items) > $this->caps->get('expression_args')) {
                throw new ExpressionError(
                    ExpressionError::ARITY,
                    'IN list has more than ' . $this->caps->get('expression_args') . ' items',
                    $tok[2],
                );
            }
        }
        $this->expectOp(')');
        $this->leave();

        return $this->node(['k' => 'list', 'items' => $items, 'at' => $tok[2]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function concat(): array
    {
        return $this->binary(['&' => true], 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function additive(): array
    {
        return $this->binary(['+' => true, '-' => true], 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function term(): array
    {
        return $this->binary(['*' => true, '/' => true], 0);
    }

    /**
     * Left-associative binary level. Depth grows with each operator, so a
     * long chain (`1+1+1+…`) is refused at the depth cap like nesting is.
     *
     * @param array<string, true> $ops
     * @param int $operandLevel 0 = unary, 1 = term, 2 = additive
     *
     * @return array<string, mixed>
     */
    private function binary(array $ops, int $operandLevel): array
    {
        $left = $this->operand($operandLevel);
        $tok = $this->peek();
        while ($tok[0] === self::T_OP && isset($ops[$tok[1]])) {
            ++$this->pos;
            $right = $this->operand($operandLevel);
            $left = $this->node(['k' => 'op', 'op' => $tok[1], 'args' => [$left, $right], 'at' => $tok[2]]);
            $this->checkDepth($left, $tok[2]);
            $tok = $this->peek();
        }

        return $left;
    }

    /**
     * @return array<string, mixed>
     */
    private function operand(int $level): array
    {
        return match ($level) {
            2 => $this->additive(),
            1 => $this->term(),
            default => $this->unary(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function unary(): array
    {
        $tok = $this->peek();
        if ($tok[0] === self::T_OP && ($tok[1] === '-' || $tok[1] === '+')) {
            ++$this->pos;
            $this->enter();
            $operand = $this->unary();
            $this->leave();
            if ($tok[1] === '+') {
                return $operand;
            }

            return $this->node(['k' => 'op', 'op' => 'neg', 'args' => [$operand], 'at' => $tok[2]]);
        }

        return $this->primary();
    }

    /**
     * @return array<string, mixed>
     */
    private function primary(): array
    {
        $tok = $this->peek();
        if ($tok[0] === self::T_NUM) {
            ++$this->pos;
            $value = \str_contains($tok[1], '.') ? (float) $tok[1] : $this->intOrFloat($tok[1]);

            return $this->node(['k' => 'num', 'v' => $value, 'at' => $tok[2]]);
        }
        if ($tok[0] === self::T_STR) {
            ++$this->pos;

            return $this->node(['k' => 'str', 'v' => $tok[1], 'at' => $tok[2]]);
        }
        if ($tok[0] === self::T_OP && $tok[1] === '(') {
            ++$this->pos;
            $inner = $this->comparison();
            $this->expectOp(')');

            return $inner;
        }
        if ($tok[0] !== self::T_NAME) {
            throw $this->unexpected($tok);
        }

        ++$this->pos;
        $upper = \strtoupper($tok[1]);
        if ($this->peekOp('(')) {
            return $this->call($upper, $tok[2]);
        }
        if ($upper === 'TRUE' || $upper === 'FALSE') {
            return $this->node(['k' => 'bool', 'v' => $upper === 'TRUE', 'at' => $tok[2]]);
        }
        if ($upper === 'IN') {
            throw $this->unexpected($tok);
        }

        $path = [$this->code($tok)];
        while ($this->peekOp('.')) {
            ++$this->pos;
            $seg = $this->peek();
            if ($seg[0] !== self::T_NAME) {
                throw $this->unexpected($seg);
            }
            ++$this->pos;
            $path[] = $this->code($seg);
            if (\count($path) > 8) {
                throw new ExpressionError(ExpressionError::SYNTAX, 'Field path has more than 8 parts', $tok[2]);
            }
        }

        return $this->node(['k' => 'field', 'path' => $path, 'at' => $tok[2]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function call(string $name, int $at): array
    {
        $this->expectOp('(');
        $this->enter();
        $args = [];
        if (!$this->peekOp(')')) {
            $args[] = $this->comparison();
            while ($this->peekOp(',')) {
                ++$this->pos;
                $args[] = $this->comparison();
                if (\count($args) > $this->caps->get('expression_args')) {
                    throw new ExpressionError(
                        ExpressionError::ARITY,
                        "{$name}() has more than " . $this->caps->get('expression_args') . ' arguments',
                        $at,
                    );
                }
            }
        }
        $this->expectOp(')');
        $this->leave();

        return $this->node(['k' => 'call', 'fn' => $name, 'args' => $args, 'at' => $at]);
    }

    /**
     * @param array{0: string, 1: string, 2: int} $tok
     */
    private function code(array $tok): string
    {
        if (\preg_match('/^[a-z][a-z0-9_]{0,39}$/', $tok[1]) !== 1) {
            throw new ExpressionError(
                ExpressionError::SYNTAX,
                "'{$tok[1]}' is not a field code (lower case) or a function call",
                $tok[2],
            );
        }

        return $tok[1];
    }

    private function intOrFloat(string $digits): int|float
    {
        $trimmed = \ltrim($digits, '0');
        if ($trimmed === '') {
            return 0;
        }
        if (\strlen($trimmed) < 19 || (\strlen($trimmed) === 19 && \strcmp($trimmed, (string) PHP_INT_MAX) <= 0)) {
            return (int) $trimmed;
        }

        return (float) $trimmed;
    }

    /**
     * Recursion guard for nesting (parentheses, calls, unary). Binary chains
     * are checked by checkDepth() on the node they build.
     */
    private function enter(): void
    {
        if (++$this->guard > $this->caps->get('expression_depth')) {
            throw new ExpressionError(
                ExpressionError::DEPTH,
                'Expression is nested deeper than ' . $this->caps->get('expression_depth'),
                $this->peek()[2],
            );
        }
    }

    private function leave(): void
    {
        --$this->guard;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function checkDepth(array $node, int $at): void
    {
        $cap = $this->caps->get('expression_depth');
        if (self::depth($node, $cap + 1) > $cap) {
            throw new ExpressionError(ExpressionError::DEPTH, "Expression is nested deeper than {$cap}", $at);
        }
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private function node(array $node): array
    {
        if (++$this->nodes > $this->caps->get('expression_nodes')) {
            throw new ExpressionError(
                ExpressionError::NODES,
                'Expression has more than ' . $this->caps->get('expression_nodes') . ' parts',
                (int) $node['at'],
            );
        }

        return $node;
    }

    /**
     * @return array{0: string, 1: string, 2: int}
     */
    private function peek(): array
    {
        return $this->tokens[$this->pos];
    }

    private function peekOp(string $op): bool
    {
        $tok = $this->tokens[$this->pos];

        return $tok[0] === self::T_OP && $tok[1] === $op;
    }

    private function expectOp(string $op): void
    {
        $tok = $this->peek();
        if (!($tok[0] === self::T_OP && $tok[1] === $op)) {
            throw new ExpressionError(
                ExpressionError::SYNTAX,
                "Expected '{$op}' at offset {$tok[2]}",
                $tok[2],
            );
        }
        ++$this->pos;
    }

    /**
     * @param array{0: string, 1: string, 2: int} $tok
     */
    private function unexpected(array $tok): ExpressionError
    {
        $what = $tok[0] === self::T_EOF ? 'end of expression' : "'{$tok[1]}'";

        return new ExpressionError(ExpressionError::SYNTAX, "Unexpected {$what} at offset {$tok[2]}", $tok[2]);
    }
}
