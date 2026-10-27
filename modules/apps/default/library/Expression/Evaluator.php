<?php

/**
 * razymod/apps — runs a typed AST in PHP with a step budget.
 *
 * Used for computed values, defaults and visibility rules (filters and row
 * rules compile to SQL instead, RZA-C, with the same answers on every vector
 * of tests/fixtures/apps/sql-parity.json). It is an interpreter over the
 * closed node and function list: dispatch is a `match` on fixed names, never
 * a PHP callable built from input.
 *
 * Fails closed: every node is re-checked before it runs (kind, operator,
 * function, arity, literal type and size), values are read through strict
 * helpers, and any PHP warning, TypeError or ArithmeticError during an
 * evaluation becomes ExpressionError (no message or path leaks).
 *
 * Bounds: every node visited and every loop round inside a function costs a
 * step (eval_steps); recursion follows the AST, capped at expression_depth
 * and guarded again here; strings are checked against eval_string_bytes
 * before they are built, lists against eval_list_items, decimals against
 * Decimal::MAX_DIGITS.
 *
 * Values: decimal = Decimal (exact), number = float (POWER only), text =
 * string, boolean = bool, date = 'Y-m-d', datetime = 'Y-m-d\TH:i:s\Z' (UTC),
 * principals and refs = string ids, lists = PHP lists, blank = null.
 *
 * Blanks (null or '') follow Types and each function's `blank` policy in
 * Functions::LIST, applied in one place (call()). A date meeting a datetime
 * is that date's local midnight in the data space time zone (Context), the
 * zone TODAY() and the date parts use too.
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

    private static function blank(mixed $v): bool
    {
        return $v === null || $v === '';
    }

    /**
     * @param array<string, mixed> $ast a typed AST (TypeChecker output)
     *
     * @return mixed Decimal|float|string|bool|list<mixed>|null
     *
     * @throws ExpressionError
     */
    public function evaluate(array $ast, Values $values, Context $ctx): mixed
    {
        $this->values = $values;
        $this->ctx = $ctx;
        $this->steps = 0;
        $this->depth = 0;

        // a warning means a value the checks above did not foresee: fail closed,
        // without the PHP message (it can carry paths)
        \set_error_handler(static function (): bool {
            throw new ExpressionError(ExpressionError::SYNTAX, 'Expression could not be evaluated');
        });
        try {
            return $this->visit($ast);
        } catch (TypeError|ValueError|ArithmeticError) {
            throw new ExpressionError(ExpressionError::SYNTAX, 'Malformed expression node');
        } finally {
            \restore_error_handler();
        }
    }

    /**
     * Steps used by the last evaluate() call.
     */
    public function steps(): int
    {
        return $this->steps;
    }

    private function visit(mixed $n): mixed
    {
        $this->step();
        if (++$this->depth > $this->caps->get('expression_depth') + 1) {
            throw new ExpressionError(ExpressionError::DEPTH, 'Expression is nested too deep');
        }
        try {
            $this->checkNode($n);
            $out = match ($n['k']) {
                'num' => Decimal::of($n['v']),
                'str', 'bool' => $n['v'],
                'field' => $this->field($n),
                'call' => $this->call($n['fn'], $n['args'], $n['t']),
                'op' => $this->op($n['op'], $n['args'], $n['t']),
                'list' => $this->evalList($n['items']),
                default => throw $this->malformed(),
            };
            // a date flowing into a datetime-typed result (IF / MIN / COALESCE over both)
            if ($n['t'] === 'datetime' && \is_string($out) && \strlen($out) === 10) {
                $out = $this->promote($out);
            }

            return $out;
        } finally {
            --$this->depth;
        }
    }

    /**
     * The evaluator runs typed ASTs from the compiler, usually through a
     * cache. Each node's shape is re-checked (kinds, operators, the closed
     * function list, arity, literal types and sizes) so a tampered AST fails
     * closed with a typed error instead of reaching PHP with unexpected values.
     */
    private function checkNode(mixed $n): void
    {
        $ok = \is_array($n) && \is_string($n['t'] ?? null);
        if ($ok) {
            switch ($n['k'] ?? null) {
                case 'num':
                    $ok = \is_string($n['v'] ?? null) && \strlen($n['v']) <= 61 && Decimal::isValid($n['v']);
                    break;

                case 'str':
                    $ok = \is_string($n['v'] ?? null) && \strlen($n['v']) <= $this->caps->get('literal_length')
                        && \preg_match('//u', $n['v']) === 1;
                    break;

                case 'bool':
                    $ok = \is_bool($n['v'] ?? null);
                    break;

                case 'field':
                    $path = $n['path'] ?? null;
                    $ok = \is_array($path) && $path !== [] && \count($path) <= 8 && \array_is_list($path)
                        && (!isset($n['scale']) || (\is_int($n['scale']) && $n['scale'] >= 0 && $n['scale'] <= Decimal::MAX_SCALE));
                    foreach ($ok ? $path : [] as $seg) {
                        $ok = $ok && \is_string($seg) && \preg_match('/^[a-z][a-z0-9_]{0,39}$/', $seg) === 1;
                    }
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
            throw $this->malformed();
        }
    }

    private function children(mixed $list): bool
    {
        if (!\is_array($list) || !\array_is_list($list) || \count($list) > $this->caps->get('expression_args')) {
            return false;
        }
        foreach ($list as $child) {
            if (!\is_array($child) || !\is_string($child['t'] ?? null)) {
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
        $scale = \is_int($n['scale'] ?? null) ? $n['scale'] : null;

        return $this->normalize($this->values->get($n['path']), $n['t'], \implode('.', $n['path']), $scale);
    }

    /**
     * Coerce a raw value (as a database driver returns it) to the AST type.
     */
    private function normalize(mixed $v, string $type, string $where, ?int $scale = null): mixed
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
                if (!self::blank($x)) {
                    $out[] = $x;
                }
            }

            return $out;
        }
        if (\str_starts_with($type, 'ref:')) {
            $type = 'user';
        }

        switch ($type) {
            case 'decimal':
                if (\is_int($v) || \is_float($v) || $v instanceof Decimal || \is_string($v)) {
                    try {
                        $d = Decimal::from(\is_string($v) ? \trim($v) : $v);
                    } catch (ExpressionError) {
                        throw $this->bad($where, $type);
                    }

                    // read at the field's declared scale (a money column holds cents)
                    return $scale !== null ? $d->round($scale) : $d;
                }

                throw $this->bad($where, $type);
            case 'number':
                if (\is_int($v) || \is_float($v)) {
                    return $this->finite((float) $v);
                }
                if ($v instanceof Decimal) {
                    return $v->toFloat();
                }
                if (\is_string($v) && Decimal::isValid(\trim($v))) {
                    return (float) \trim($v);
                }

                throw $this->bad($where, $type);
            case 'text':
            case 'status':
                if (\is_string($v)) {
                    return $this->text($v);
                }
                if (\is_int($v) || $v instanceof Decimal) {
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
            if (self::blank($x)) {
                return null;
            }
            $x = $this->num($x);

            return $x instanceof Decimal ? $x->negate() : $this->finite(-$x);
        }
        if ($op === 'in') {
            $x = $this->visit($args[0]);
            $list = $this->visit($args[1]);
            if (self::blank($x) || !\is_array($list)) {
                return false;
            }
            $this->step(\count($list));
            $xt = $args[0]['t'];
            $it = Types::isList($args[1]['t']) ? Types::itemType($args[1]['t']) : $args[1]['t'];
            foreach ($list as $item) {
                if (!self::blank($item) && $this->equals($x, $item, $xt, $it)) {
                    return true;
                }
            }

            return false;
        }

        $l = $this->visit($args[0]);
        $r = $this->visit($args[1]);
        $lt = $args[0]['t'];
        $rt = $args[1]['t'];

        switch ($op) {
            case '&':
                return $this->joinText([$this->toText($l, $lt), $this->toText($r, $rt)]);

            case '=':
                return (self::blank($l) || self::blank($r)) ? self::blank($l) === self::blank($r) : $this->equals($l, $r, $lt, $rt);

            case '<>':
                return (self::blank($l) || self::blank($r)) ? self::blank($l) !== self::blank($r) : !$this->equals($l, $r, $lt, $rt);

            case '<':
            case '<=':
            case '>':
            case '>=':
                if (self::blank($l) || self::blank($r)) {
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

        if (self::blank($l) || self::blank($r)) {
            return null;
        }

        if ($type === 'date' || $type === 'datetime') {
            // date ± days, days + date
            [$d, $days, $dt] = Types::isNumeric($lt) ? [$r, $l, $rt] : [$l, $r, $lt];
            $days = $this->num($days);
            if ($op === '-') {
                $days = $days instanceof Decimal ? $days->negate() : -$days;
            }

            return $this->addDays($this->asString($d), $dt, $days);
        }
        if (($lt === 'date' || $lt === 'datetime') && $op === '-') {
            return $this->dayDiff($this->asString($l), $lt, $this->asString($r), $rt);
        }

        $l = $this->num($l);
        $r = $this->num($r);
        if ($l instanceof Decimal && $r instanceof Decimal) {
            $this->step(1 + \intdiv(\strlen((string) $l) + \strlen((string) $r), 20));

            return match ($op) {
                '+' => $l->add($r),
                '-' => $l->sub($r),
                '*' => $l->mul($r),
                '/' => $l->div($r),
                default => throw $this->malformed(),
            };
        }
        $l = $l instanceof Decimal ? $l->toFloat() : $l;
        $r = $r instanceof Decimal ? $r->toFloat() : $r;

        return match ($op) {
            '+' => $this->finite($l + $r),
            '-' => $this->finite($l - $r),
            '*' => $this->finite($l * $r),
            '/' => $r == 0
                ? throw new ExpressionError(ExpressionError::DIV_ZERO, 'Division by zero')
                : $this->finite($l / $r),
            default => throw $this->malformed(),
        };
    }

    private function equals(mixed $a, mixed $b, string $at, string $bt): bool
    {
        if ($this->isNum($a) || $this->isNum($b)) {
            return $this->isNum($a) && $this->isNum($b) && $this->numCmp($a, $b) === 0;
        }
        [$a, $b] = $this->alignDates($a, $b, $at, $bt);

        return $a === $b;
    }

    private function compare(mixed $a, mixed $b, string $at, string $bt): int
    {
        if ($this->isNum($a) && $this->isNum($b)) {
            return $this->numCmp($a, $b);
        }
        [$a, $b] = $this->alignDates($a, $b, $at, $bt);

        return \strcmp($this->asString($a), $this->asString($b)) <=> 0;
    }

    private function isNum(mixed $v): bool
    {
        return $v instanceof Decimal || \is_float($v) || \is_int($v);
    }

    private function numCmp(mixed $a, mixed $b): int
    {
        $a = $this->num($a);
        $b = $this->num($b);
        if ($a instanceof Decimal && $b instanceof Decimal) {
            return $a->cmp($b);
        }

        return ($a instanceof Decimal ? $a->toFloat() : $a) <=> ($b instanceof Decimal ? $b->toFloat() : $b);
    }

    /**
     * A date next to a datetime becomes its local midnight. Decided by the
     * operand types only: text that looks like a date stays text (byte-wise,
     * COLLATE "C"). Inside a datetime-typed MIN / MAX the date arguments are
     * typed date, and the value shape tells which is which.
     *
     * @return array{0: mixed, 1: mixed}
     */
    private function alignDates(mixed $a, mixed $b, string $at, string $bt): array
    {
        $dateish = static fn (string $t): bool => $t === 'date' || $t === 'datetime';
        if (!$dateish($at) || !$dateish($bt) || !\is_string($a) || !\is_string($b)) {
            return [$a, $b];
        }
        if (\strlen($a) === 10 && \strlen($b) === 20) {
            $a = $this->promote($a);
        } elseif (\strlen($a) === 20 && \strlen($b) === 10) {
            $b = $this->promote($b);
        }

        return [$a, $b];
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
                    if (!self::blank($v)) {
                        return $v;
                    }
                }

                return null;
        }

        $v = [];
        $types = [];
        foreach ($args as $a) {
            $v[] = $this->visit($a);
            $types[] = $a['t'];
        }

        // the blank policy of Functions::LIST, in one place
        switch (Functions::get($fn)['blank']) {
            case 'propagate':
                foreach ($v as $x) {
                    if (self::blank($x)) {
                        return null;
                    }
                }
                break;

            case 'skip':
                $keep = \array_keys(\array_filter($v, static fn (mixed $x): bool => !self::blank($x)));
                $v = \array_values(\array_intersect_key($v, \array_flip($keep)));
                $types = \array_values(\array_intersect_key($types, \array_flip($keep)));
                break;

            case 'empty_text':
                foreach ($v as $i => $x) {
                    if (!self::blank($x)) {
                        continue;
                    }
                    if (Functions::paramType($fn, $i) === 'number') {
                        return '';
                    }
                    $v[$i] = '';
                }
                break;
        }
        $t = static fn (int $i): string => $types[$i] ?? 'blank';

        return match ($fn) {
            'NOT' => $v[0] !== true,
            'ISBLANK' => self::blank($v[0]) || $v[0] === [],
            'BLANK' => null,

            'ABS' => ($x = $this->num($v[0])) instanceof Decimal ? $x->abs() : \abs($x),
            'ROUND' => $this->round($v[0], $v[1] ?? null),
            'FLOOR' => ($x = $this->num($v[0])) instanceof Decimal ? $x->floor() : \floor($x),
            'CEILING' => ($x = $this->num($v[0])) instanceof Decimal ? $x->floor(0, true) : \ceil($x),
            'MOD' => $this->mod($v[0], $v[1]),
            'POWER' => $this->power($v[0], $v[1]),
            'MIN', 'MAX' => $this->minMax($fn, $v, $types),
            'SUM' => $this->sum($v, $type),
            'AVERAGE' => $v === [] ? null : $this->divide($this->sum($v, $type), \count($v)),

            'LEN' => Decimal::fromInt(\mb_strlen($this->asString($v[0]))),
            'LEFT' => \mb_substr($this->asString($v[0]), 0, $this->count($v[1] ?? 1, 'LEFT')),
            'RIGHT' => $this->right($this->asString($v[0]), $this->count($v[1] ?? 1, 'RIGHT')),
            'MID' => $this->mid($this->asString($v[0]), $v[1], $v[2]),
            'UPPER' => $this->text(\mb_strtoupper($this->asString($v[0]))),
            'LOWER' => $this->text(\mb_strtolower($this->asString($v[0]))),
            'TRIM' => (string) \preg_replace('/ {2,}/', ' ', \trim($this->asString($v[0]), ' ')),
            'CONCAT' => $this->joinText(\array_map(fn (mixed $x, int $i): string => $this->toText($x, $t($i)), $v, \array_keys($v))),
            'CONTAINS' => \str_contains(\mb_strtolower($this->asString($v[0])), \mb_strtolower($this->asString($v[1]))),
            'STARTSWITH' => \str_starts_with(\mb_strtolower($this->asString($v[0])), \mb_strtolower($this->asString($v[1]))),
            'ENDSWITH' => \str_ends_with(\mb_strtolower($this->asString($v[0])), \mb_strtolower($this->asString($v[1]))),
            'SUBSTITUTE' => $this->substitute($this->asString($v[0]), $this->asString($v[1]), $this->asString($v[2])),
            'VALUE' => $this->value($this->asString($v[0])),
            'TEXT' => $this->toText($v[0], $t(0)),

            'TODAY' => $this->ctx->now->setTimezone($this->ctx->timezone)->format(self::DATE),
            'NOW' => $this->ctx->now->setTimezone($this->utc)->format(self::DATETIME),
            'DATE' => $this->date($this->intArg($v[0]), $this->intArg($v[1]), $this->intArg($v[2])),
            'YEAR' => $this->datePart($v[0], $t(0), 'Y'),
            'MONTH' => $this->datePart($v[0], $t(0), 'n'),
            'DAY' => $this->datePart($v[0], $t(0), 'j'),
            'WEEKDAY' => $this->datePart($v[0], $t(0), 'N'),
            'DAYS' => $this->dayDiff($this->asString($v[0]), 'date', $this->asString($v[1]), 'date'),
            'EDATE' => $this->addMonths($this->asString($v[0]), $this->intArg($v[1]), false),
            'EOMONTH' => $this->addMonths($this->asString($v[0]), $this->intArg($v[1]), true),
            'WORKDAY' => $this->workday($this->asString($v[0]), $this->intArg($v[1])),
            'NETWORKDAYS' => $this->networkdays($this->asString($v[0]), $this->asString($v[1])),

            'CURRENT_USER' => $this->ctx->user,
            'MY_GROUPS' => $this->principalList($this->ctx->groups),
            'MY_DEPARTMENTS' => $this->principalList($this->ctx->departments),

            default => throw new ExpressionError(ExpressionError::UNKNOWN_FUNCTION, "Unknown function {$fn}()"),
        };
    }

    private function round(mixed $x, mixed $digits): Decimal|float
    {
        $d = $digits === null ? 0 : $this->intArg($digits);
        if ($d < -15 || $d > 15) {
            throw new ExpressionError(ExpressionError::VALUE, 'ROUND() digits must be between -15 and 15');
        }
        $x = $this->num($x);

        return $x instanceof Decimal ? $x->round($d) : $this->finite(\round($x, $d));
    }

    private function mod(mixed $a, mixed $b): Decimal|float
    {
        $a = $this->num($a);
        $b = $this->num($b);
        if ($a instanceof Decimal && $b instanceof Decimal) {
            return $a->mod($b);
        }
        $a = $a instanceof Decimal ? $a->toFloat() : $a;
        $b = $b instanceof Decimal ? $b->toFloat() : $b;
        if ($b == 0) {
            throw new ExpressionError(ExpressionError::DIV_ZERO, 'MOD() by zero');
        }

        // Excel: the result takes the divisor's sign
        return $this->finite($a - $b * \floor($a / $b));
    }

    private function power(mixed $a, mixed $b): float
    {
        $a = $this->toFloatNum($a);
        $b = $this->toFloatNum($b);
        if ($a == 0 && $b < 0) {
            throw new ExpressionError(ExpressionError::DIV_ZERO, 'POWER() of zero to a negative power');
        }
        $r = (float) ($a ** $b);
        if (\is_nan($r)) {
            throw new ExpressionError(ExpressionError::VALUE, 'POWER() result is not a number');
        }

        return $this->finite($r);
    }

    /**
     * @param list<mixed> $v blanks already left out
     * @param list<string> $types the argument types, aligned with $v
     */
    private function minMax(string $fn, array $v, array $types): mixed
    {
        $best = null;
        $bestType = 'blank';
        foreach ($v as $i => $x) {
            $t = $types[$i] ?? 'blank';
            if ($best === null) {
                $best = $x;
                $bestType = $t;
                continue;
            }
            $c = $this->compare($x, $best, $t, $bestType);
            if (($fn === 'MIN' && $c < 0) || ($fn === 'MAX' && $c > 0)) {
                $best = $x;
                $bestType = $t;
            }
        }

        return $best;
    }

    /**
     * @param list<mixed> $v blanks already left out
     */
    private function sum(array $v, string $type): Decimal|float
    {
        if ($type === 'number') {
            $total = 0.0;
            foreach ($v as $x) {
                $total += $this->toFloatNum($x);
            }

            return $this->finite($total);
        }
        $total = Decimal::fromInt(0);
        foreach ($v as $x) {
            $this->step();
            $total = $total->add(Decimal::from($this->num($x)));
        }

        return $total;
    }

    private function divide(Decimal|float $total, int $count): Decimal|float
    {
        return $total instanceof Decimal ? $total->div(Decimal::fromInt($count)) : $this->finite($total / $count);
    }

    private function right(string $s, int $n): string
    {
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
        $n = $this->intArg($n);
        if ($n < 0 || $n > $this->caps->get('eval_string_bytes')) {
            throw new ExpressionError(ExpressionError::VALUE, "{$fn}() count is out of range");
        }

        return $n;
    }

    private function substitute(string $s, string $old, string $new): string
    {
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

    private function value(string $s): ?Decimal
    {
        $s = \trim($s);
        if ($s === '') {
            return null;
        }
        if (\strlen($s) > 61 || !Decimal::isValid($s)) {
            throw new ExpressionError(ExpressionError::VALUE, 'VALUE() needs a plain number');
        }

        return Decimal::of($s);
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

    // ── strict value helpers ───────────────────────────────────────

    /**
     * Text from a scalar value; anything else is a malformed AST.
     */
    private function asString(mixed $v): string
    {
        if (\is_string($v)) {
            return $v;
        }
        if ($v === null) {
            return '';
        }
        if (\is_int($v) || $v instanceof Decimal) {
            return (string) $v;
        }

        throw $this->malformed();
    }

    /**
     * A decimal or a float from a numeric value.
     */
    private function num(mixed $x): Decimal|float
    {
        if ($x instanceof Decimal || \is_float($x)) {
            return $x;
        }
        if (\is_int($x)) {
            return Decimal::fromInt($x);
        }

        throw $this->malformed();
    }

    private function toFloatNum(mixed $x): float
    {
        $x = $this->num($x);

        return $x instanceof Decimal ? $x->toFloat() : $x;
    }

    /**
     * Toward zero, as a PHP int (counts, positions, day and month offsets).
     */
    private function intArg(mixed $x): int
    {
        $x = $this->num($x);
        if ($x instanceof Decimal) {
            return $x->toInt();
        }
        if (\abs($x) > 1e15) {
            throw new ExpressionError(ExpressionError::VALUE, 'Number is out of range');
        }

        return (int) $x;
    }

    private function toText(mixed $v, string $type): string
    {
        if ($v === null) {
            return '';
        }
        if (\is_bool($v)) {
            return $v ? 'TRUE' : 'FALSE';
        }
        if (\is_float($v)) {
            return (string) Decimal::fromFloat($v);
        }
        if (\is_array($v)) {
            throw new ExpressionError(ExpressionError::TYPE, "Cannot turn {$type} into text");
        }

        return $this->asString($v);
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

    private function finite(float $x): float
    {
        if (!\is_finite($x)) {
            throw new ExpressionError(ExpressionError::VALUE, 'Number is too large');
        }

        return $x;
    }

    // ── dates ──────────────────────────────────────────────────────

    private function isDate(string $v): bool
    {
        return \preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) === 1 && \checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * A date as the datetime of its local midnight in the data space zone.
     */
    private function promote(string $date): string
    {
        if (!$this->isDate($date)) {
            throw $this->malformed();
        }

        return (new DateTimeImmutable($date . ' 00:00:00', $this->ctx->timezone))->setTimezone($this->utc)->format(self::DATETIME);
    }

    private function toDate(mixed $v, string $where): string
    {
        if ($v instanceof DateTimeInterface) {
            return $v->format(self::DATE);
        }
        if (\is_string($v) && $this->isDate($v)) {
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
            ? ($this->isDate($v) ? DateTimeImmutable::createFromFormat('!' . self::DATE, $v, $this->utc) : false)
            : DateTimeImmutable::createFromFormat(self::DATETIME, $v, $this->utc);
        if ($d === false) {
            throw new ExpressionError(ExpressionError::VALUE, "Bad {$type} value");
        }

        return $d;
    }

    private function addDays(string $d, string $type, Decimal|float $days): string
    {
        $base = $this->parse($d, $type);
        if ($type === 'date') {
            $n = $days instanceof Decimal ? $days->floor()->toInt() : (int) \floor($this->finite($days));
            if (\abs($n) > 3_660_000) {
                throw new ExpressionError(ExpressionError::VALUE, 'Day offset is out of range');
            }
            $out = $base->modify("{$n} days");
        } else {
            $seconds = $days instanceof Decimal ? $days->mul(Decimal::fromInt(86400))->round(0)->toInt() : (int) \round($this->finite($days) * 86400);
            if (\abs($seconds) > 316_224_000_000) {
                throw new ExpressionError(ExpressionError::VALUE, 'Day offset is out of range');
            }
            $out = $base->modify("{$seconds} seconds");
        }

        return $this->checkYear($out)->format($type === 'date' ? self::DATE : self::DATETIME);
    }

    /**
     * a - b in days: whole days between dates, a decimal (scale 6) when a
     * datetime takes part; a date next to a datetime is its local midnight.
     */
    private function dayDiff(string $a, string $at, string $b, string $bt): Decimal
    {
        if ($at === 'date' && $bt === 'date') {
            return Decimal::fromInt(\intdiv($this->parse($a, 'date')->getTimestamp() - $this->parse($b, 'date')->getTimestamp(), 86400));
        }
        $x = $this->parse($at === 'date' ? $this->promote($a) : $a, 'datetime');
        $y = $this->parse($bt === 'date' ? $this->promote($b) : $b, 'datetime');

        return Decimal::fromInt($x->getTimestamp() - $y->getTimestamp())->div(Decimal::fromInt(86400));
    }

    private function date(int $y, int $m, int $d): string
    {
        if ($y < 1 || $y > 9999 || \abs($m) > 120_000 || \abs($d) > 3_660_000) {
            throw new ExpressionError(ExpressionError::VALUE, 'DATE() argument is out of range');
        }
        // Excel: months and days past the end roll over
        $base = (new DateTimeImmutable('now', $this->utc))->setDate($y, 1, 1)->setTime(0, 0);
        $out = $base->modify(($m - 1) . ' months')->modify(($d - 1) . ' days');

        return $this->checkYear($out)->format(self::DATE);
    }

    private function datePart(mixed $v, string $type, string $format): Decimal
    {
        $d = $this->parse($this->asString($v), $type === 'datetime' ? 'datetime' : 'date');
        if ($type === 'datetime') {
            $d = $d->setTimezone($this->ctx->timezone);
        }

        return Decimal::fromInt((int) $d->format($format));
    }

    private function addMonths(string $v, int $months, bool $endOfMonth): string
    {
        if (\abs($months) > 120_000) {
            throw new ExpressionError(ExpressionError::VALUE, 'Month offset is out of range');
        }
        $d = $this->parse($v, 'date');
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

    private function workday(string $v, int $days): string
    {
        $d = $this->parse($v, 'date');
        $dir = $days < 0 ? -1 : 1;
        $left = \abs($days);
        $oneDay = new DateInterval('P1D');
        while ($left > 0) {
            $this->step();
            $d = $dir > 0 ? $d->add($oneDay) : $d->sub($oneDay);
            $this->checkYear($d);
            if ($this->isWorkingDay($d)) {
                --$left;
            }
        }

        return $d->format(self::DATE);
    }

    private function networkdays(string $a, string $b): Decimal
    {
        $start = $this->parse($a, 'date');
        $end = $this->parse($b, 'date');
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
            if ($day >= $from && $day <= $to && $this->isDate($day)
                && (int) $this->parse($day, 'date')->format('N') <= 5) {
                --$count;
            }
        }

        return Decimal::fromInt($sign * $count);
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

    private function malformed(): ExpressionError
    {
        return new ExpressionError(ExpressionError::SYNTAX, 'Malformed expression node');
    }
}
