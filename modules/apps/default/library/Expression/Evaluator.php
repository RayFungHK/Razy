<?php

/**
 * razymod/apps — runs a typed AST in PHP with a step budget.
 *
 * Used for computed values, defaults and visibility rules (filters and row
 * rules compile to SQL instead, RZA-C). It is an interpreter over the closed
 * node and function list: dispatch is a `match` on fixed names, never a PHP
 * callable built from input, so an AST cannot call anything outside the list
 * even if it was tampered with after type checking.
 *
 * Bounds: every node visited and every loop round inside a function costs a
 * step (eval_steps); recursion follows the AST, which is capped at
 * expression_depth, and is guarded again here; every string built is checked
 * against eval_string_bytes before it is built, lists against
 * eval_list_items. Errors are ExpressionError with a stable code.
 *
 * Values: number = int|float, text = string, boolean = bool, date = 'Y-m-d',
 * datetime = 'Y-m-d\TH:i:s\Z' (UTC), principals and refs = string ids,
 * lists = PHP lists, blank = null. Semantics of blanks: see Types.
 */

declare(strict_types=1);

namespace razymod\apps\Expression;

use ArithmeticError;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use razymod\apps\Caps;
use TypeError;
use ValueError;

final class Evaluator
{
    private const DATE = 'Y-m-d';

    private const DATETIME = 'Y-m-d\TH:i:s\Z';

    /** Operators and their operand count. */
    private const OPS = ['+' => 2, '-' => 2, '*' => 2, '/' => 2, '&' => 2, '=' => 2, '<>' => 2, '<' => 2, '<=' => 2,
        '>' => 2, '>=' => 2, 'in' => 2, 'neg' => 1];

    private Values $values;

    private Context $ctx;

    private int $steps = 0;

    private int $depth = 0;

    private DateTimeZone $utc;

    public function __construct(private readonly Caps $caps = new Caps())
    {
        $this->utc = new DateTimeZone('UTC');
    }

    /**
     * @param array<string, mixed> $ast a typed AST (TypeChecker output)
     *
     * @throws ExpressionError
     */
    public function evaluate(array $ast, Values $values, Context $ctx): mixed
    {
        $this->values = $values;
        $this->ctx = $ctx;
        $this->steps = 0;
        $this->depth = 0;

        try {
            return $this->visit($ast);
        } catch (TypeError|ValueError|ArithmeticError) {
            // last line of defence for an AST that passed checkNode() but still
            // carries a value its type does not allow (a tampered plan cache)
            throw new ExpressionError(ExpressionError::SYNTAX, 'Malformed expression node');
        }
    }

    /**
     * Steps used by the last evaluate() call.
     */
    public function steps(): int
    {
        return $this->steps;
    }

    /**
     * @param array<string, mixed> $n
     */
    private function visit(mixed $n): mixed
    {
        $this->step();
        if (++$this->depth > $this->caps->get('expression_depth') + 1) {
            throw new ExpressionError(ExpressionError::DEPTH, 'Expression is nested too deep');
        }
        try {
            $this->checkNode($n);
            $out = match ($n['k'] ?? null) {
                'num', 'str', 'bool' => $n['v'],
                'field' => $this->field($n),
                'call' => $this->call((string) $n['fn'], $n['args'], (string) $n['t']),
                'op' => $this->op((string) $n['op'], $n['args'], (string) $n['t']),
                'list' => $this->evalList($n['items']),
                default => throw new ExpressionError(ExpressionError::SYNTAX, 'Malformed expression node'),
            };
            // a date flowing into a datetime-typed result (IF / MIN / COALESCE over both)
            if (($n['t'] ?? null) === 'datetime' && \is_string($out) && \strlen($out) === 10) {
                $out .= 'T00:00:00Z';
            }

            return $out;
        } finally {
            --$this->depth;
        }
    }

    /**
     * The evaluator runs typed ASTs from the compiler, usually through a
     * cache. It re-checks each node's shape (kinds, operators, the closed
     * function list, arity, literal types) so a tampered AST fails closed
     * with a typed error instead of reaching PHP with unexpected values.
     */
    private function checkNode(mixed $n): void
    {
        $ok = \is_array($n) && \is_string($n['t'] ?? null);
        if ($ok) {
            switch ($n['k'] ?? null) {
                case 'num':
                    $ok = (\is_int($n['v'] ?? null) || \is_float($n['v'] ?? null)) && \is_finite((float) $n['v']);
                    break;

                case 'str':
                    $ok = \is_string($n['v'] ?? null);
                    break;

                case 'bool':
                    $ok = \is_bool($n['v'] ?? null);
                    break;

                case 'field':
                    $ok = \is_array($n['path'] ?? null) && $n['path'] !== [] && \array_is_list($n['path'])
                        && \count(\array_filter($n['path'], static fn (mixed $s): bool => \is_string($s))) === \count($n['path']);
                    break;

                case 'call':
                    $fn = $n['fn'] ?? null;
                    $ok = \is_string($fn) && Functions::has($fn) && $this->children($n['args'] ?? null);
                    if ($ok) {
                        $sig = Functions::get($fn);
                        $count = \count($n['args']);
                        $ok = $count >= $sig['min'] && $count <= ($sig['max'] ?? $this->caps->get('expression_args'));
                    }
                    break;

                case 'op':
                    $op = $n['op'] ?? null;
                    $ok = \is_string($op) && isset(self::OPS[$op]) && $this->children($n['args'] ?? null)
                        && \count($n['args']) === self::OPS[$op];
                    break;

                case 'list':
                    $ok = $this->children($n['items'] ?? null);
                    break;

                default:
                    $ok = false;
            }
        }
        if (!$ok) {
            throw new ExpressionError(ExpressionError::SYNTAX, 'Malformed expression node');
        }
    }

    private function children(mixed $list): bool
    {
        if (!\is_array($list) || !\array_is_list($list) || \count($list) > $this->caps->get('eval_list_items')) {
            return false;
        }
        foreach ($list as $child) {
            if (!\is_array($child)) {
                return false;
            }
        }

        return true;
    }

    private function step(int $cost = 1): void
    {
        $this->steps += $cost;
        if ($this->steps > $this->caps->get('eval_steps')) {
            throw new ExpressionError(
                ExpressionError::BUDGET,
                'Expression used more than ' . $this->caps->get('eval_steps') . ' steps',
            );
        }
    }

    /**
     * @param array<string, mixed> $n
     */
    private function field(array $n): mixed
    {
        return $this->normalize($this->values->get($n['path']), (string) $n['t'], \implode('.', $n['path']));
    }

    /**
     * Coerce a raw value (as a database driver returns it) to the AST type.
     */
    private function normalize(mixed $v, string $type, string $where): mixed
    {
        if ($v === null) {
            return null;
        }
        if (Types::isList($type)) {
            if (!\is_array($v) || !\array_is_list($v)) {
                throw $this->bad($where, $type);
            }
            if (\count($v) > $this->caps->get('eval_list_items')) {
                throw new ExpressionError(ExpressionError::SIZE, "'{$where}' has too many items");
            }
            $this->step(\count($v));
            $item = Types::itemType($type);
            $out = [];
            foreach ($v as $x) {
                $x = $this->normalize($x, $item, $where);
                if ($x !== null) {
                    $out[] = $x;
                }
            }

            return $out;
        }
        if (\str_starts_with($type, 'ref:')) {
            $type = 'user';
        }

        switch ($type) {
            case 'number':
                if (\is_int($v) || \is_float($v)) {
                    return $this->finite($v);
                }
                if (\is_string($v) && \preg_match('/^-?\d{1,30}(\.\d{1,30})?$/', $v) === 1) {
                    return \str_contains($v, '.') ? (float) $v : $this->toNumber($v);
                }

                throw $this->bad($where, $type);
            case 'text':
            case 'status':
                if (\is_string($v)) {
                    return $this->text($v);
                }
                if (\is_int($v) || \is_float($v)) {
                    return (string) $v;
                }

                throw $this->bad($where, $type);
            case 'boolean':
                if (\is_bool($v)) {
                    return $v;
                }
                if ($v === 1 || $v === 0 || $v === '1' || $v === '0' || $v === 't' || $v === 'f') {
                    return $v === 1 || $v === '1' || $v === 't';
                }

                throw $this->bad($where, $type);
            case 'date':
                return $this->toDate($v, $where);

            case 'datetime':
                return $this->toDatetime($v, $where);

            case 'user':
            case 'group':
            case 'department':
                if (\is_string($v) && $v !== '' && \strlen($v) <= 200) {
                    return $v;
                }
                if (\is_int($v)) {
                    return (string) $v;
                }

                throw $this->bad($where, $type);
            case 'opaque':
                return $v;
        }

        throw $this->bad($where, $type);
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<mixed>
     */
    private function evalList(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[] = $this->visit($item);
        }

        return $out;
    }

    // ── operators ──────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $args
     */
    private function op(string $op, array $args, string $type): mixed
    {
        if ($op === 'neg') {
            $x = $this->visit($args[0]);

            return $x === null ? null : $this->finite(-$this->num($x));
        }
        if ($op === 'in') {
            $x = $this->visit($args[0]);
            $list = $this->visit($args[1]);
            if ($x === null || !\is_array($list)) {
                return false;
            }
            $this->step(\count($list));
            $lt = (string) $args[0]['t'];
            foreach ($list as $item) {
                if ($item !== null && $this->equals($x, $item, $lt, $this->listItemType($args[1]))) {
                    return true;
                }
            }

            return false;
        }

        $l = $this->visit($args[0]);
        $r = $this->visit($args[1]);
        $lt = (string) $args[0]['t'];
        $rt = (string) $args[1]['t'];

        switch ($op) {
            case '&':
                return $this->joinText([$this->toText($l, $lt), $this->toText($r, $rt)]);

            case '=':
                return ($l === null || $r === null) ? $l === $r : $this->equals($l, $r, $lt, $rt);

            case '<>':
                return ($l === null || $r === null) ? $l !== $r : !$this->equals($l, $r, $lt, $rt);

            case '<':
            case '<=':
            case '>':
            case '>=':
                if ($l === null || $r === null) {
                    return false;
                }
                $c = $this->compare($l, $r, $lt, $rt);

                return match ($op) {
                    '<' => $c < 0,
                    '<=' => $c <= 0,
                    '>' => $c > 0,
                    default => $c >= 0,
                };
        }

        if ($l === null || $r === null) {
            return null;
        }

        if ($type === 'date' || $type === 'datetime') {
            // date ± days, days + date
            [$d, $days, $dt] = ($lt === 'number') ? [$r, $l, $rt] : [$l, $r, $lt];
            if ($op === '-') {
                $days = -$days;
            }

            return $this->addDays((string) $d, $dt, $days);
        }
        if (($lt === 'date' || $lt === 'datetime') && $op === '-') {
            return $this->dayDiff((string) $l, $lt, (string) $r, $rt);
        }

        $l = $this->num($l);
        $r = $this->num($r);

        return match ($op) {
            '+' => $this->finite($l + $r),
            '-' => $this->finite($l - $r),
            '*' => $this->finite($l * $r),
            '/' => $r == 0
                ? throw new ExpressionError(ExpressionError::DIV_ZERO, 'Division by zero')
                : $this->finite($l / $r),
            default => throw new ExpressionError(ExpressionError::SYNTAX, 'Malformed expression node'),
        };
    }

    /**
     * @param array<string, mixed> $listNode
     */
    private function listItemType(array $listNode): string
    {
        $t = (string) $listNode['t'];

        return Types::isList($t) ? Types::itemType($t) : $t;
    }

    private function equals(mixed $a, mixed $b, string $at, string $bt): bool
    {
        if (\is_int($a) || \is_float($a)) {
            return (\is_int($b) || \is_float($b)) && $a == $b;
        }
        if ($at === 'date' && $bt === 'datetime') {
            $a = $a . 'T00:00:00Z';
        } elseif ($at === 'datetime' && $bt === 'date') {
            $b = $b . 'T00:00:00Z';
        }

        return $a === $b;
    }

    private function compare(mixed $a, mixed $b, string $at, string $bt): int
    {
        if ((\is_int($a) || \is_float($a)) && (\is_int($b) || \is_float($b))) {
            return $a <=> $b;
        }
        if ($at === 'date' && $bt === 'datetime') {
            $a = $a . 'T00:00:00Z';
        } elseif ($at === 'datetime' && $bt === 'date') {
            $b = $b . 'T00:00:00Z';
        }

        return \strcmp((string) $a, (string) $b) <=> 0;
    }

    // ── functions ──────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $args
     */
    private function call(string $fn, array $args, string $type): mixed
    {
        // lazy forms first: their arguments are evaluated only as needed
        switch ($fn) {
            case 'IF':
                return $this->visit($args[0]) === true ? $this->visit($args[1]) : $this->visit($args[2]);

            case 'AND':
                foreach ($args as $a) {
                    if ($this->visit($a) !== true) {
                        return false;
                    }
                }

                return true;

            case 'OR':
                foreach ($args as $a) {
                    if ($this->visit($a) === true) {
                        return true;
                    }
                }

                return false;

            case 'COALESCE':
                foreach ($args as $a) {
                    $v = $this->visit($a);
                    if ($v !== null && $v !== '') {
                        return $v;
                    }
                }

                return null;
        }

        $v = [];
        foreach ($args as $a) {
            $v[] = $this->visit($a);
        }
        $t = static fn (int $i): string => (string) $args[$i]['t'];

        return match ($fn) {
            'NOT' => $v[0] !== true,
            'ISBLANK' => $v[0] === null || $v[0] === '' || $v[0] === [],
            'BLANK' => null,

            'ABS' => $v[0] === null ? null : \abs($v[0]),
            'ROUND' => $this->round($v[0], $v[1] ?? 0),
            'FLOOR' => $v[0] === null ? null : $this->toNumber(\floor($v[0])),
            'CEILING' => $v[0] === null ? null : $this->toNumber(\ceil($v[0])),
            'MOD' => $this->mod($v[0], $v[1]),
            'POWER' => $this->power($v[0], $v[1]),
            'MIN', 'MAX' => $this->minMax($fn, $v, $type),
            'SUM' => $this->finite(\array_sum(\array_filter($v, static fn ($x) => $x !== null))),
            'AVERAGE' => $this->average($v),

            'LEN' => \mb_strlen((string) $v[0]),
            'LEFT' => $this->left((string) $v[0], $v[1] ?? 1),
            'RIGHT' => $this->right((string) $v[0], $v[1] ?? 1),
            'MID' => $this->mid((string) $v[0], $v[1], $v[2]),
            'UPPER' => $v[0] === null ? null : $this->text(\mb_strtoupper($v[0])),
            'LOWER' => $v[0] === null ? null : $this->text(\mb_strtolower($v[0])),
            'TRIM' => $v[0] === null ? null : (string) \preg_replace('/ {2,}/', ' ', \trim($v[0], ' ')),
            'CONCAT' => $this->joinText(\array_map(fn ($x, $i) => $this->toText($x, $t($i)), $v, \array_keys($v))),
            'CONTAINS' => \str_contains(\mb_strtolower((string) $v[0]), \mb_strtolower((string) $v[1])),
            'STARTSWITH' => \str_starts_with(\mb_strtolower((string) $v[0]), \mb_strtolower((string) $v[1])),
            'ENDSWITH' => \str_ends_with(\mb_strtolower((string) $v[0]), \mb_strtolower((string) $v[1])),
            'SUBSTITUTE' => $this->substitute($v[0], (string) $v[1], (string) $v[2]),
            'VALUE' => $this->value($v[0]),
            'TEXT' => $this->toText($v[0], $t(0)),

            'TODAY' => $this->ctx->now->setTimezone($this->ctx->timezone)->format(self::DATE),
            'NOW' => $this->ctx->now->setTimezone($this->utc)->format(self::DATETIME),
            'DATE' => $this->date($v[0], $v[1], $v[2]),
            'YEAR' => $this->datePart($v[0], $t(0), 'Y'),
            'MONTH' => $this->datePart($v[0], $t(0), 'n'),
            'DAY' => $this->datePart($v[0], $t(0), 'j'),
            'WEEKDAY' => $this->datePart($v[0], $t(0), 'N'),
            'DAYS' => ($v[0] === null || $v[1] === null) ? null : $this->dayDiff($v[0], 'date', $v[1], 'date'),
            'EDATE' => $this->addMonths($v[0], $v[1], false),
            'EOMONTH' => $this->addMonths($v[0], $v[1], true),
            'WORKDAY' => $this->workday($v[0], $v[1]),
            'NETWORKDAYS' => $this->networkdays($v[0], $v[1]),

            'CURRENT_USER' => $this->ctx->user,
            'MY_GROUPS' => $this->principalList($this->ctx->groups),
            'MY_DEPARTMENTS' => $this->principalList($this->ctx->departments),

            default => throw new ExpressionError(ExpressionError::UNKNOWN_FUNCTION, "Unknown function {$fn}()"),
        };
    }

    private function round(mixed $x, mixed $digits): int|float|null
    {
        if ($x === null) {
            return null;
        }
        $d = (int) ($digits ?? 0);
        if ($d < -15 || $d > 15) {
            throw new ExpressionError(ExpressionError::VALUE, 'ROUND() digits must be between -15 and 15');
        }
        $r = \round((float) $x, $d);

        return $d <= 0 ? $this->toNumber($r) : $r;
    }

    private function mod(mixed $a, mixed $b): int|float|null
    {
        if ($a === null || $b === null) {
            return null;
        }
        if ($b == 0) {
            throw new ExpressionError(ExpressionError::DIV_ZERO, 'MOD() by zero');
        }
        // Excel: the result takes the divisor's sign
        if (\is_int($a) && \is_int($b)) {
            $m = $a % $b;

            return ($m !== 0 && (($m < 0) !== ($b < 0))) ? $m + $b : $m;
        }

        return $this->finite($a - $b * \floor($a / $b));
    }

    private function power(mixed $a, mixed $b): int|float|null
    {
        if ($a === null || $b === null) {
            return null;
        }
        if ($a == 0 && $b < 0) {
            throw new ExpressionError(ExpressionError::DIV_ZERO, 'POWER() of zero to a negative power');
        }
        $r = $a ** $b;
        if (\is_float($r) && \is_nan($r)) {
            throw new ExpressionError(ExpressionError::VALUE, 'POWER() result is not a number');
        }

        return $this->finite($r);
    }

    /**
     * @param list<mixed> $v
     */
    private function minMax(string $fn, array $v, string $type): mixed
    {
        $best = null;
        foreach ($v as $x) {
            if ($x === null) {
                continue;
            }
            if ($best === null) {
                $best = $x;
                continue;
            }
            $c = $this->compare($x, $best, $type, $type);
            if (($fn === 'MIN' && $c < 0) || ($fn === 'MAX' && $c > 0)) {
                $best = $x;
            }
        }

        return $best;
    }

    /**
     * @param list<mixed> $v
     */
    private function average(array $v): int|float|null
    {
        $nums = \array_values(\array_filter($v, static fn ($x) => $x !== null));
        if ($nums === []) {
            return null;
        }

        return $this->finite(\array_sum($nums) / \count($nums));
    }

    private function left(string $s, mixed $n): string
    {
        $n = $this->count($n, 'LEFT');

        return \mb_substr($s, 0, $n);
    }

    private function right(string $s, mixed $n): string
    {
        $n = $this->count($n, 'RIGHT');

        return $n === 0 ? '' : \mb_substr($s, -$n);
    }

    private function mid(string $s, mixed $start, mixed $n): string
    {
        $from = $this->count($start, 'MID');
        if ($from < 1) {
            throw new ExpressionError(ExpressionError::VALUE, 'MID() start must be 1 or more');
        }

        return \mb_substr($s, $from - 1, $this->count($n, 'MID'));
    }

    private function count(mixed $n, string $fn): int
    {
        if ($n === null) {
            return 0;
        }
        if ($n < 0 || $n > $this->caps->get('eval_string_bytes')) {
            throw new ExpressionError(ExpressionError::VALUE, "{$fn}() count is out of range");
        }

        return (int) $n;
    }

    private function substitute(mixed $s, string $old, string $new): ?string
    {
        if ($s === null) {
            return null;
        }
        if ($old === '') {
            return $s;
        }
        $hits = \substr_count($s, $old);
        $this->step($hits);
        $size = \strlen($s) + $hits * (\strlen($new) - \strlen($old));
        if ($size > $this->caps->get('eval_string_bytes')) {
            throw $this->tooLong();
        }

        return \str_replace($old, $new, $s);
    }

    private function value(mixed $s): int|float|null
    {
        if ($s === null || $s === '') {
            return null;
        }
        $s = \trim((string) $s);
        if (\preg_match('/^-?\d{1,30}(\.\d{1,30})?$/', $s) !== 1) {
            throw new ExpressionError(ExpressionError::VALUE, 'VALUE() needs a plain number');
        }

        return \str_contains($s, '.') ? (float) $s : $this->toNumber($s);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function principalList(array $ids): array
    {
        if (\count($ids) > $this->caps->get('eval_list_items')) {
            throw new ExpressionError(ExpressionError::SIZE, 'Principal list has too many items');
        }

        return \array_map(static fn ($id): string => (string) $id, $ids);
    }

    // ── text ───────────────────────────────────────────────────────

    private function toText(mixed $v, string $type): string
    {
        if ($v === null) {
            return '';
        }
        if (\is_bool($v)) {
            return $v ? 'TRUE' : 'FALSE';
        }
        if (\is_float($v)) {
            if (\floor($v) === $v && \abs($v) < 1e15) {
                return (string) (int) $v;
            }

            return (string) \json_encode($v);
        }
        if (\is_array($v)) {
            throw new ExpressionError(ExpressionError::TYPE, "Cannot turn {$type} into text");
        }

        return (string) $v;
    }

    /**
     * @param list<string> $parts
     */
    private function joinText(array $parts): string
    {
        $size = 0;
        foreach ($parts as $p) {
            $size += \strlen($p);
        }
        if ($size > $this->caps->get('eval_string_bytes')) {
            throw $this->tooLong();
        }

        return \implode('', $parts);
    }

    private function text(string $s): string
    {
        if (\strlen($s) > $this->caps->get('eval_string_bytes')) {
            throw $this->tooLong();
        }

        return $s;
    }

    private function tooLong(): ExpressionError
    {
        return new ExpressionError(
            ExpressionError::SIZE,
            'Text result is longer than ' . $this->caps->get('eval_string_bytes') . ' bytes',
        );
    }

    // ── numbers ────────────────────────────────────────────────────

    private function num(mixed $x): int|float
    {
        if (\is_int($x) || \is_float($x)) {
            return $x;
        }

        throw new ExpressionError(ExpressionError::SYNTAX, 'Malformed expression node');
    }

    private function finite(int|float $x): int|float
    {
        if (\is_float($x) && !\is_finite($x)) {
            throw new ExpressionError(ExpressionError::VALUE, 'Number is too large');
        }

        return $x;
    }

    private function toNumber(string|float $x): int|float
    {
        if (\is_string($x)) {
            $f = (float) $x;
            if (\abs($f) < 9.2e18) {
                return (int) $x;
            }

            return $f;
        }
        if (\abs($x) < 9.2e18) {
            return (int) $x;
        }

        return $this->finite($x);
    }

    // ── dates ──────────────────────────────────────────────────────

    private function toDate(mixed $v, string $where): string
    {
        if ($v instanceof DateTimeInterface) {
            return $v->format(self::DATE);
        }
        if (\is_string($v) && \preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) === 1
            && \checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $v;
        }

        throw $this->bad($where, 'date');
    }

    private function toDatetime(mixed $v, string $where): string
    {
        if ($v instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($v)->setTimezone($this->utc)->format(self::DATETIME);
        }
        $re = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}(:?\d{2})?)?$/';
        if (\is_string($v) && \preg_match($re, $v) === 1) {
            try {
                return (new DateTimeImmutable($v, $this->utc))->setTimezone($this->utc)->format(self::DATETIME);
            } catch (Exception) {
                // fall through
            }
        }

        throw $this->bad($where, 'datetime');
    }

    private function parse(string $v, string $type): DateTimeImmutable
    {
        $d = $type === 'date'
            ? DateTimeImmutable::createFromFormat('!' . self::DATE, $v, $this->utc)
            : DateTimeImmutable::createFromFormat(self::DATETIME, $v, $this->utc);
        if ($d === false) {
            throw new ExpressionError(ExpressionError::VALUE, "Bad {$type} '{$v}'");
        }

        return $d;
    }

    private function addDays(string $d, string $type, int|float $days): string
    {
        if (\abs($days) > 3_660_000) {
            throw new ExpressionError(ExpressionError::VALUE, 'Day offset is out of range');
        }
        $base = $this->parse($d, $type);
        if ($type === 'date') {
            $out = $base->modify(((int) \floor($days)) . ' days');
        } else {
            $out = $base->modify(((int) \round($days * 86400)) . ' seconds');
        }

        return $this->checkYear($out)->format($type === 'date' ? self::DATE : self::DATETIME);
    }

    private function dayDiff(string $a, string $at, string $b, string $bt): int|float
    {
        $x = $this->parse($a, $at);
        $y = $this->parse($b, $bt);
        $seconds = $x->getTimestamp() - $y->getTimestamp();
        if ($at === 'date' && $bt === 'date') {
            return \intdiv($seconds, 86400);
        }

        return $seconds / 86400;
    }

    private function date(mixed $y, mixed $m, mixed $d): ?string
    {
        if ($y === null || $m === null || $d === null) {
            return null;
        }
        $y = (int) $y;
        $m = (int) $m;
        $d = (int) $d;
        if ($y < 1 || $y > 9999 || \abs($m) > 120_000 || \abs($d) > 3_660_000) {
            throw new ExpressionError(ExpressionError::VALUE, 'DATE() argument is out of range');
        }
        // Excel: months and days past the end roll over
        $base = (new DateTimeImmutable('now', $this->utc))->setDate($y, 1, 1)->setTime(0, 0);
        $out = $base->modify(($m - 1) . ' months')->modify(($d - 1) . ' days');

        return $this->checkYear($out)->format(self::DATE);
    }

    private function datePart(mixed $v, string $type, string $format): ?int
    {
        if ($v === null) {
            return null;
        }
        $d = $this->parse((string) $v, $type);
        if ($type === 'datetime') {
            $d = $d->setTimezone($this->ctx->timezone);
        }

        return (int) $d->format($format);
    }

    private function addMonths(mixed $v, mixed $months, bool $endOfMonth): ?string
    {
        if ($v === null || $months === null) {
            return null;
        }
        $months = (int) $months;
        if (\abs($months) > 120_000) {
            throw new ExpressionError(ExpressionError::VALUE, 'Month offset is out of range');
        }
        $d = $this->parse((string) $v, 'date');
        $y = (int) $d->format('Y');
        $m = (int) $d->format('n') - 1 + $months;
        $y += \intdiv($m, 12) - ($m < 0 && $m % 12 !== 0 ? 1 : 0);
        $m = (($m % 12) + 12) % 12 + 1;
        if ($y < 1 || $y > 9999) {
            throw new ExpressionError(ExpressionError::VALUE, 'Date is out of range');
        }
        $last = (int) (new DateTimeImmutable('now', $this->utc))->setDate($y, $m, 1)->format('t');
        $day = $endOfMonth ? $last : \min((int) $d->format('j'), $last);

        return \sprintf('%04d-%02d-%02d', $y, $m, $day);
    }

    private function workday(mixed $v, mixed $days): ?string
    {
        if ($v === null || $days === null) {
            return null;
        }
        $days = (int) $days;
        $d = $this->parse((string) $v, 'date');
        $dir = $days < 0 ? -1 : 1;
        $left = \abs($days);
        $oneDay = new DateInterval('P1D');
        while ($left > 0) {
            $this->step();
            $d = $dir > 0 ? $d->add($oneDay) : $d->sub($oneDay);
            if ($this->isWorkingDay($d)) {
                --$left;
            }
        }

        return $this->checkYear($d)->format(self::DATE);
    }

    private function networkdays(mixed $a, mixed $b): ?int
    {
        if ($a === null || $b === null) {
            return null;
        }
        $start = $this->parse((string) $a, 'date');
        $end = $this->parse((string) $b, 'date');
        $sign = 1;
        if ($start > $end) {
            [$start, $end] = [$end, $start];
            $sign = -1;
        }
        $total = \intdiv($end->getTimestamp() - $start->getTimestamp(), 86400) + 1;
        $weeks = \intdiv($total, 7);
        $count = $weeks * 5;
        // the remaining 0-6 days after the whole weeks
        $cursor = $start->modify('+' . ($weeks * 7) . ' days');
        for ($i = $weeks * 7; $i < $total; ++$i) {
            $this->step();
            if ((int) $cursor->format('N') <= 5) {
                ++$count;
            }
            $cursor = $cursor->modify('+1 day');
        }
        $from = $start->format(self::DATE);
        $to = $end->format(self::DATE);
        foreach ($this->ctx->holidays as $day => $_) {
            $this->step();
            if ($day >= $from && $day <= $to
                && (int) $this->parse($day, 'date')->format('N') <= 5) {
                --$count;
            }
        }

        return $sign * $count;
    }

    private function isWorkingDay(DateTimeImmutable $d): bool
    {
        return (int) $d->format('N') <= 5 && !isset($this->ctx->holidays[$d->format(self::DATE)]);
    }

    private function checkYear(DateTimeImmutable $d): DateTimeImmutable
    {
        $y = (int) $d->format('Y');
        if ($y < 1 || $y > 9999) {
            throw new ExpressionError(ExpressionError::VALUE, 'Date is out of range');
        }

        return $d;
    }

    private function bad(string $where, string $type): ExpressionError
    {
        return new ExpressionError(ExpressionError::VALUE, "Value of '{$where}' is not a valid {$type}");
    }
}
