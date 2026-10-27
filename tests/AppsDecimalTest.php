<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use razymod\apps\Expression\Decimal;
use razymod\apps\Expression\ExpressionError;

require_once __DIR__ . '/fixtures/apps/autoload.php';

/**
 * razymod/apps exact decimals: the fixed-point arithmetic behind integer /
 * decimal / money / percent / duration values and number literals.
 */
#[CoversNothing]
class AppsDecimalTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function cases(): iterable
    {
        $d = static fn (string $v): Decimal => Decimal::of($v);
        yield '0.1 + 0.2' => [(string) $d('0.1')->add($d('0.2')), '0.3'];
        yield '5 / 2' => [(string) $d('5')->div($d('2')), '2.5'];
        yield '1 / 3' => [(string) $d('1')->div($d('3')), '0.333333'];
        yield '2 / 3 half up' => [(string) $d('2')->div($d('3')), '0.666667'];
        yield '-2 / 3' => [(string) $d('-2')->div($d('3')), '-0.666667'];
        yield 'division keeps the larger scale' => [(string) $d('1.00000000')->div($d('3')), '0.33333333'];
        yield 'mul exact' => [(string) $d('19.99')->mul($d('1.075')), '21.48925'];
        yield 'round half up' => [(string) $d('2.345')->round(2), '2.35'];
        yield 'round half away from zero' => [(string) $d('-2.345')->round(2), '-2.35'];
        yield 'round to hundreds' => [(string) $d('1234.5')->round(-2), '1200'];
        yield 'round 1.005' => [(string) $d('1.005')->round(2), '1.01'];
        yield 'floor' => [(string) $d('-2.5')->floor(), '-3'];
        yield 'ceiling' => [(string) $d('-2.5')->floor(0, true), '-2'];
        yield 'mod divisor sign' => [(string) $d('-7')->mod($d('3')), '2'];
        yield 'mod negative divisor' => [(string) $d('7')->mod($d('-3')), '-2'];
        yield 'mod fraction' => [(string) $d('-7.5')->mod($d('2')), '0.5'];
        yield 'float as written' => [(string) Decimal::fromFloat(0.7), '0.7'];
        yield 'float large' => [(string) Decimal::fromFloat(1e20), '100000000000000000000'];
        yield 'float small' => [(string) Decimal::fromFloat(-0.000123), '-0.000123'];
        yield 'int min' => [(string) Decimal::fromInt(\PHP_INT_MIN), '-9223372036854775808'];
        yield 'toFixed keeps scale' => [$d('12.30')->toFixed(), '12.30'];
        yield 'negative zero' => [(string) $d('-0.00'), '0'];
        yield 'leading zeros' => [(string) $d('007.50'), '7.5'];
    }

    private static function cents(int $n): string
    {
        return self::scaled($n, 2);
    }

    /**
     * An integer count of 10^-$places units as a fixed-point string.
     */
    private static function scaled(int $n, int $places): string
    {
        $sign = $n < 0 ? '-' : '';
        $abs = \str_pad((string) \abs($n), $places + 1, '0', STR_PAD_LEFT);

        return $sign . \substr($abs, 0, -$places) . '.' . \substr($abs, -$places);
    }

    #[DataProvider('cases')]
    public function testArithmetic(string $actual, string $expected): void
    {
        $this->assertSame($expected, $actual);
    }

    public function testAgreesWithIntegerArithmetic(): void
    {
        \mt_srand(20261027);
        for ($i = 0; $i < 3000; ++$i) {
            $a = \mt_rand(-1_000_000, 1_000_000);
            $b = \mt_rand(-10_000, 10_000) ?: 1;
            $da = Decimal::of(self::cents($a));
            $db = Decimal::of(self::cents($b));
            $this->assertSame(self::cents($a + $b), $da->add($db)->toFixed(), "{$a} + {$b}");
            $this->assertSame(self::cents($a - $b), $da->sub($db)->toFixed(), "{$a} - {$b}");
            $this->assertSame(self::scaled($a * $b, 4), $da->mul($db)->toFixed(), "{$a} * {$b}");
            $r = $a % $b;
            $r = ($r !== 0 && (($r < 0) !== ($b < 0))) ? $r + $b : $r;
            $this->assertSame((string) $r, (string) Decimal::fromInt($a)->mod(Decimal::fromInt($b)), "MOD({$a}, {$b})");
            $this->assertSame($a <=> $b, $da->cmp($db), "cmp {$a} {$b}");
        }
    }

    public function testRefusesWhatIsNotExact(): void
    {
        foreach (['1e3', '.5', '1.', '--1', ' 1', '0x10', \str_repeat('9', 61), '1.' . \str_repeat('1', 31)] as $bad) {
            $this->assertFalse(Decimal::isValid($bad), $bad);
        }
        foreach ([fn () => Decimal::of('abc'), fn () => Decimal::fromFloat(\NAN), fn () => Decimal::fromFloat(\INF),
            fn () => Decimal::of(\str_repeat('9', 60))->mul(Decimal::of(\str_repeat('9', 60)))] as $f) {
            try {
                $f();
                $this->fail('accepted');
            } catch (ExpressionError $e) {
                $this->assertSame(ExpressionError::VALUE, $e->getErrorCode());
            }
        }
        $this->expectException(ExpressionError::class);
        Decimal::of('1')->div(Decimal::of('0.00'));
    }

    public function testJsonIsTheShortestString(): void
    {
        $this->assertSame('{"v":"2.5"}', \json_encode(['v' => Decimal::of('2.500')]));
    }
}
