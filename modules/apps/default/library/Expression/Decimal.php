<?php

/**
 * razymod/apps — an exact decimal value (the expression type `decimal`).
 *
 * integer, decimal, money, percent and duration fields, number literals and
 * the results of exact functions are decimals, never IEEE floats, so
 * 0.1 + 0.2 = 0.3 and money adds up to the cent. Own fixed-point on digit
 * strings (no bcmath / gmp dependency):
 *
 * - add / subtract: scale = the larger scale
 * - multiply: scale = the sum of the scales (exact)
 * - divide: scale = max(scale of either side, DIVISION_SCALE), rounded
 *   HALF_UP (a half rounds away from zero); 5 / 2 = 2.5, never 2
 * - round(n): HALF_UP to n places (n may be negative)
 * - values hold at most MAX_DIGITS digits; more is ExpressionError VALUE
 *
 * The SQL side (RZA-C) casts to numeric and uses the same rules
 * (manual/15-razy-apps.md §5.1). (string) gives the shortest form without
 * trailing zeros ("2.5", "-3", "0.3"); scale() is the declared scale.
 */

declare(strict_types=1);

namespace razymod\apps\Expression;

use JsonSerializable;
use Stringable;

final class Decimal implements JsonSerializable, Stringable
{
    public const DIVISION_SCALE = 6;

    public const MAX_DIGITS = 60;

    public const MAX_SCALE = 30;

    private const PATTERN = '/^(-?)(\d{1,60})(?:\.(\d{1,30}))?$/';

    /**
     * @param string $digits unscaled magnitude, no leading zeros ("0" for zero)
     */
    private function __construct(private readonly bool $negative, private readonly string $digits, private readonly int $scale)
    {
    }

    public function __toString(): string
    {
        $n = $this->normalize();
        $digits = \str_pad($n->digits, $n->scale + 1, '0', STR_PAD_LEFT);
        $out = $n->scale > 0 ? \substr($digits, 0, -$n->scale) . '.' . \substr($digits, -$n->scale) : $digits;

        return ($n->negative ? '-' : '') . $out;
    }

    public static function of(string $value): self
    {
        if (\preg_match(self::PATTERN, $value, $m) !== 1) {
            throw new ExpressionError(ExpressionError::VALUE, 'Not a decimal number');
        }
        $frac = $m[3] ?? '';

        return self::make($m[1] === '-', $m[2] . $frac, \strlen($frac));
    }

    public static function isValid(string $value): bool
    {
        return \preg_match(self::PATTERN, $value) === 1;
    }

    public static function fromInt(int $value): self
    {
        if ($value === PHP_INT_MIN) {
            return self::of((string) $value);
        }

        return self::make($value < 0, (string) \abs($value), 0);
    }

    /**
     * A float as the decimal it was written as: 15 significant digits,
     * so 0.7 is 0.7 and not 0.6999999999999999555910790149937.
     */
    public static function fromFloat(float $value): self
    {
        if (!\is_finite($value)) {
            throw new ExpressionError(ExpressionError::VALUE, 'Number is not finite');
        }
        if ($value == 0.0) {
            return self::fromInt(0);
        }
        \preg_match('/^(-?)(\d)\.(\d+)e([+-]\d+)$/', \sprintf('%.14e', $value), $m);
        $mantissa = $m[2] . $m[3];
        $exp = (int) $m[4] - \strlen($m[3]);
        if ($exp >= 0) {
            return self::make($m[1] === '-', $mantissa . \str_repeat('0', $exp), 0)->normalize();
        }

        return self::make($m[1] === '-', $mantissa, -$exp)->normalize();
    }

    /**
     * int, float, numeric string or Decimal; anything else is VALUE.
     */
    public static function from(mixed $value): self
    {
        return match (true) {
            $value instanceof self => $value,
            \is_int($value) => self::fromInt($value),
            \is_float($value) => self::fromFloat($value),
            \is_string($value) => self::of($value),
            default => throw new ExpressionError(ExpressionError::VALUE, 'Not a decimal number'),
        };
    }

    // ── unsigned digit-string arithmetic ───────────────────────────

    private static function make(bool $negative, string $digits, int $scale): self
    {
        $digits = \ltrim($digits, '0');
        if ($digits === '') {
            $digits = '0';
            $negative = false;
        }
        if (\strlen($digits) > self::MAX_DIGITS + self::MAX_SCALE + 1) {
            throw new ExpressionError(ExpressionError::VALUE, 'Number is too large');
        }
        if (\strlen($digits) - $scale > self::MAX_DIGITS) {
            throw new ExpressionError(ExpressionError::VALUE, 'Number is too large');
        }

        return new self($negative, $digits, $scale);
    }

    /**
     * Split off the last $n digits: [kept, dropped] (dropped left-padded).
     *
     * @return array{0: string, 1: string}
     */
    private static function shiftDown(string $digits, int $n): array
    {
        if ($n <= 0) {
            return [$digits, ''];
        }
        $padded = \str_pad($digits, $n + 1, '0', STR_PAD_LEFT);

        return [\substr($padded, 0, -$n), \substr($padded, -$n)];
    }

    private static function cmpU(string $a, string $b): int
    {
        $a = \ltrim($a, '0');
        $b = \ltrim($b, '0');

        return \strlen($a) === \strlen($b) ? \strcmp($a, $b) <=> 0 : \strlen($a) <=> \strlen($b);
    }

    private static function addU(string $a, string $b): string
    {
        $out = '';
        $carry = 0;
        for ($i = \strlen($a) - 1, $j = \strlen($b) - 1; $i >= 0 || $j >= 0 || $carry; --$i, --$j) {
            $sum = $carry + ($i >= 0 ? (int) $a[$i] : 0) + ($j >= 0 ? (int) $b[$j] : 0);
            $out = ($sum % 10) . $out;
            $carry = \intdiv($sum, 10);
        }

        return \ltrim($out, '0') ?: '0';
    }

    /**
     * $a - $b for $a >= $b.
     */
    private static function subU(string $a, string $b): string
    {
        $out = '';
        $borrow = 0;
        for ($i = \strlen($a) - 1, $j = \strlen($b) - 1; $i >= 0; --$i, --$j) {
            $d = (int) $a[$i] - $borrow - ($j >= 0 ? (int) $b[$j] : 0);
            $borrow = $d < 0 ? 1 : 0;
            $out = ($d + 10 * $borrow) . $out;
        }

        return \ltrim($out, '0') ?: '0';
    }

    private static function mulU(string $a, string $b): string
    {
        if ($a === '0' || $b === '0') {
            return '0';
        }
        $res = \array_fill(0, \strlen($a) + \strlen($b), 0);
        for ($i = \strlen($a) - 1; $i >= 0; --$i) {
            for ($j = \strlen($b) - 1; $j >= 0; --$j) {
                $p = (int) $a[$i] * (int) $b[$j] + $res[$i + $j + 1];
                $res[$i + $j + 1] = $p % 10;
                $res[$i + $j] += \intdiv($p, 10);
            }
        }

        return \ltrim(\implode('', $res), '0') ?: '0';
    }

    /**
     * Long division of digit strings.
     *
     * @return array{0: string, 1: string} [quotient, remainder]
     */
    private static function divmodU(string $a, string $b): array
    {
        $q = '';
        $r = '0';
        $len = \strlen($a);
        for ($i = 0; $i < $len; ++$i) {
            $r = \ltrim($r . $a[$i], '0') ?: '0';
            $d = 0;
            while (self::cmpU($r, $b) >= 0) {
                $r = self::subU($r, $b);
                ++$d;
            }
            $q .= $d;
        }

        return [\ltrim($q, '0') ?: '0', $r];
    }

    public function scale(): int
    {
        return $this->scale;
    }

    public function isZero(): bool
    {
        return $this->digits === '0';
    }

    public function isNegative(): bool
    {
        return $this->negative;
    }

    public function negate(): self
    {
        return self::make(!$this->negative, $this->digits, $this->scale);
    }

    public function abs(): self
    {
        return self::make(false, $this->digits, $this->scale);
    }

    public function add(self $o): self
    {
        $s = \max($this->scale, $o->scale);
        $a = $this->rescaled($s);
        $b = $o->rescaled($s);
        if ($this->negative === $o->negative) {
            return self::make($this->negative, self::addU($a, $b), $s);
        }
        $c = self::cmpU($a, $b);
        if ($c === 0) {
            return self::make(false, '0', $s);
        }

        return $c > 0 ? self::make($this->negative, self::subU($a, $b), $s) : self::make($o->negative, self::subU($b, $a), $s);
    }

    public function sub(self $o): self
    {
        return $this->add($o->negate());
    }

    public function mul(self $o): self
    {
        $r = self::make($this->negative !== $o->negative, self::mulU($this->digits, $o->digits), $this->scale + $o->scale);

        return $r->scale > self::MAX_SCALE ? $r->round(self::MAX_SCALE) : $r;
    }

    /**
     * @throws ExpressionError DIV_ZERO
     */
    public function div(self $o, ?int $scale = null): self
    {
        if ($o->isZero()) {
            throw new ExpressionError(ExpressionError::DIV_ZERO, 'Division by zero');
        }
        $scale ??= \max($this->scale, $o->scale, self::DIVISION_SCALE);
        // a / b at scale s, one extra digit for HALF_UP: a * 10^(s + 1 + sb - sa) / b
        $shift = $scale + 1 + $o->scale - $this->scale;
        $num = $this->digits;
        $den = $o->digits;
        if ($shift >= 0) {
            $num .= \str_repeat('0', $shift);
        } else {
            $den .= \str_repeat('0', -$shift);
        }
        [$q] = self::divmodU($num, $den);

        return self::make($this->negative !== $o->negative, $q, $scale + 1)->round($scale);
    }

    /**
     * HALF_UP to $places decimal places (negative: tens, hundreds, …).
     */
    public function round(int $places): self
    {
        if ($places >= $this->scale) {
            return $places > self::MAX_SCALE ? $this : $this->rescaledSelf($places);
        }
        $drop = $this->scale - $places;
        [$kept, $rest] = self::shiftDown($this->digits, $drop);
        if ($rest !== '' && (int) $rest[0] >= 5) {
            $kept = self::addU($kept, '1');
        }
        if ($places < 0) {
            return self::make($this->negative, $kept . \str_repeat('0', -$places), 0);
        }

        return self::make($this->negative, $kept, $places);
    }

    /**
     * Round toward negative infinity to $places (FLOOR) or toward positive
     * infinity (CEILING).
     */
    public function floor(int $places = 0, bool $ceiling = false): self
    {
        if ($places >= $this->scale) {
            return $this;
        }
        [$kept, $rest] = self::shiftDown($this->digits, $this->scale - $places);
        $exact = \trim($rest, '0') === '';
        $awayFromZero = !$exact && ($ceiling ? !$this->negative : $this->negative);
        if ($awayFromZero) {
            $kept = self::addU($kept, '1');
        }

        return self::make($this->negative, $kept, \max(0, $places));
    }

    /**
     * Excel MOD: a - b × FLOOR(a / b), so the result takes the divisor's sign.
     */
    public function mod(self $o): self
    {
        if ($o->isZero()) {
            throw new ExpressionError(ExpressionError::DIV_ZERO, 'MOD() by zero');
        }
        $s = \max($this->scale, $o->scale);
        // truncated remainder on the magnitudes, then move it to the divisor's sign
        [, $r] = self::divmodU($this->rescaled($s), $o->rescaled($s));
        $rem = self::make($this->negative, $r, $s);
        if (!$rem->isZero() && $this->negative !== $o->negative) {
            $rem = $rem->add($o);
        }

        return $rem;
    }

    public function cmp(self $o): int
    {
        if ($this->negative !== $o->negative) {
            return $this->negative ? -1 : 1;
        }
        $s = \max($this->scale, $o->scale);
        $c = self::cmpU($this->rescaled($s), $o->rescaled($s));

        return $this->negative ? -$c : $c;
    }

    public function equals(self $o): bool
    {
        return $this->cmp($o) === 0;
    }

    /**
     * Toward zero, as an int (day counts, positions, months).
     */
    public function toInt(): int
    {
        [$whole] = self::shiftDown($this->digits, $this->scale);
        if (\strlen($whole) > 18) {
            throw new ExpressionError(ExpressionError::VALUE, 'Number is out of range');
        }

        return ($this->negative ? -1 : 1) * (int) $whole;
    }

    public function toFloat(): float
    {
        return (float) (string) $this;
    }

    /**
     * Same value with trailing fractional zeros removed.
     */
    public function normalize(): self
    {
        if ($this->scale === 0) {
            return $this;
        }
        if ($this->digits === '0') {
            return self::make(false, '0', 0);
        }
        $trim = 0;
        $len = \strlen($this->digits);
        while ($trim < $this->scale && $trim < $len && $this->digits[$len - 1 - $trim] === '0') {
            ++$trim;
        }

        return $trim === 0 ? $this : self::make($this->negative, \substr($this->digits, 0, $len - $trim), $this->scale - $trim);
    }

    /**
     * The value at its declared scale ("2.50" for money).
     */
    public function toFixed(): string
    {
        $digits = \str_pad($this->digits, $this->scale + 1, '0', STR_PAD_LEFT);
        $out = $this->scale > 0 ? \substr($digits, 0, -$this->scale) . '.' . \substr($digits, -$this->scale) : $digits;

        return ($this->negative && $this->digits !== '0' ? '-' : '') . $out;
    }

    public function jsonSerialize(): string
    {
        return (string) $this;
    }

    private function rescaled(int $scale): string
    {
        return $this->digits === '0' ? '0' : $this->digits . \str_repeat('0', $scale - $this->scale);
    }

    private function rescaledSelf(int $scale): self
    {
        if ($scale >= $this->scale) {
            return self::make($this->negative, $this->rescaled($scale), $scale);
        }

        return $this->round($scale);
    }
}
