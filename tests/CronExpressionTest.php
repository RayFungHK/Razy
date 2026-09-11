<?php

declare(strict_types=1);

namespace Razy\Tests;

use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Scheduler\CronExpression;

/**
 * Cron parser semantics (Lane B2 Scheduler). All cases evaluate in explicit
 * UTC to keep them independent of the machine timezone.
 */
#[CoversClass(CronExpression::class)]
class CronExpressionTest extends TestCase
{
    private const UTC = 'UTC';

    public static function dueProvider(): array
    {
        return [
            'every minute' => ['* * * * *', '2026-07-14 09:30 UTC', true],
            'quarter hours' => ['*/15 * * * *', '2026-07-14 09:30 UTC', true],
            'quarter hours miss' => ['*/15 * * * *', '2026-07-14 09:07 UTC', false],
            'business hours weekday' => ['*/15 9-17 * * MON-FRI', '2026-07-14 17:45 UTC', true],
            'business hours saturday' => ['*/15 9-17 * * MON-FRI', '2026-07-18 09:30 UTC', false],
            'business hours evening' => ['*/15 9-17 * * MON-FRI', '2026-07-14 18:00 UTC', false],
            'month name' => ['0 12 1 JAN *', '2027-01-01 12:00 UTC', true],
            'month name other month' => ['0 12 1 JAN *', '2026-07-01 12:00 UTC', false],
            'dow sunday alias 7' => ['0 0 * * 7', '2026-07-19 00:00 UTC', true],
            'dow sunday alias negative' => ['0 0 * * 7', '2026-07-20 00:00 UTC', false],
            'dow number sunday 0' => ['0 0 * * 0', '2026-07-19 00:00 UTC', true],
            'dow names SUN' => ['0 0 * * SUN', '2026-07-19 00:00 UTC', true],
            'a-n stepped to max' => ['10/20 * * * *', '2026-07-14 12:30 UTC', true],
            'a-n before start' => ['10/20 * * * *', '2026-07-14 12:05 UTC', false],
            'a-n past max' => ['10/20 * * * *', '2026-07-14 12:55 UTC', false],
            'list' => ['0,30 * * * *', '2026-07-14 12:30 UTC', true],
            'list miss' => ['0,30 * * * *', '2026-07-14 12:20 UTC', false],
        ];
    }

    public static function invalidProvider(): array
    {
        return [
            'four fields' => ['* * * *'],
            'six fields' => ['* * * * * *'],
            'minute out of range' => ['60 * * * *'],
            'hour out of range' => ['* 24 * * *'],
            'day zero' => ['* * 0 * *'],
            'month thirteen' => ['* * * 13 *'],
            'dow eight' => ['* * * * 8'],
            'token' => ['a * * * *'],
            'zero step' => ['*/0 * * * *'],
            'negative step' => ['*/-1 * * * *'],
            'empty term' => ['1,, * * * *'],
            'bad name' => ['0 0 * * MONXY'],
        ];
    }

    #[DataProvider('dueProvider')]
    public function testIsDue(string $cron, string $when, bool $expected): void
    {
        $this->assertSame($expected, CronExpression::parse($cron)->isDue(\strtotime($when), self::UTC), "{$cron} @ {$when}");
    }

    public function testPosixOrSemanticsWhenDayAndDowBothRestricted(): void
    {
        $cron = CronExpression::parse('0 0 13 * FRI');

        $this->assertTrue($cron->isDue(\strtotime('2026-07-13 00:00 UTC'), self::UTC), '13th (monday) matches dom');
        $this->assertTrue($cron->isDue(\strtotime('2026-07-17 00:00 UTC'), self::UTC), 'friday matches dow');
        $this->assertFalse($cron->isDue(\strtotime('2026-07-15 00:00 UTC'), self::UTC), 'wed 15th matches neither');
    }

    public function testWildcardCollapseUsesAndNotOr(): void
    {
        $cron = CronExpression::parse('0 0 13 * *');

        $this->assertTrue($cron->isDue(\strtotime('2026-07-13 00:00 UTC'), self::UTC));
        $this->assertFalse($cron->isDue(\strtotime('2026-07-17 00:00 UTC'), self::UTC), 'dow is wildcard: no OR expansion');
    }

    public function testWrappedDayRange(): void
    {
        $cron = CronExpression::parse('0 9 * * FRI-MON');

        $this->assertTrue($cron->isDue(\strtotime('2026-07-17 09:00 UTC'), self::UTC));
        $this->assertTrue($cron->isDue(\strtotime('2026-07-18 09:00 UTC'), self::UTC));
        $this->assertFalse($cron->isDue(\strtotime('2026-07-15 09:00 UTC'), self::UTC));
    }

    public function testTimezoneChangesVerdictForSameInstant(): void
    {
        $cron = CronExpression::parse('30 17 * * *');
        $instant = \strtotime('2026-07-14 09:30 UTC'); // = 17:30 HKT

        $this->assertTrue($cron->isDue($instant, 'Asia/Hong_Kong'));
        $this->assertFalse($cron->isDue($instant, new DateTimeZone('UTC')));
    }

    public function testNextRunIsStrictlyAfter(): void
    {
        $cron = CronExpression::parse('30 23 * * *');

        $this->assertSame(
            \strtotime('2026-07-15 23:30 UTC'),
            $cron->nextRun(\strtotime('2026-07-14 23:30 UTC'), self::UTC),
        );
    }

    public function testApproximateInterval(): void
    {
        $this->assertSame(60, CronExpression::parse('* * * * *')->approximateInterval(self::UTC));
        $this->assertSame(3600, CronExpression::parse('0 * * * *')->approximateInterval(self::UTC));
        $this->assertSame(600, CronExpression::parse('*/10 * * * *')->approximateInterval(self::UTC));
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidExpressionsRejected(string $expression): void
    {
        $this->expectException(InvalidArgumentException::class);
        CronExpression::parse($expression);
    }

    public function testNormalizedExpressionString(): void
    {
        $this->assertSame('0 12 * * 1', CronExpression::parse('  0   12  *   *  1 ')->expression);
    }
}
