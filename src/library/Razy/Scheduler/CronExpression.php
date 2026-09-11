<?php

/**
 * This file is part of Razy v1.0.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * POSIX/5-field cron expression parser.
 *
 *
 * @license MIT
 */

namespace Razy\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Parses a 5-field cron expression and answers scheduling questions.
 *
 * Fields: minute hour day-of-month month day-of-week
 * Supported syntax per field:
 *   star        every value
 *   a        single value            e.g. 5
 *   a-b      inclusive range         e.g. 1-5   (month/dow accept names)
 *   a-b/n    stepped range           e.g. 0-30/10
 *   star/n   every n                 e.g. star/15
 *   a/n      stepped to field max    e.g. 10/5
 *   x,y,z    list (each item may itself be any of the above)
 *
 * Month names (JAN..DEC) and day names (SUN..SAT) are accepted. Day-of-week
 * accepts 0 and 7 for Sunday.
 *
 * Standard POSIX OR-semantics: when BOTH day-of-month and day-of-week are
 * restricted (neither is '*'), the expression matches when EITHER matches.
 *
 * Evaluation is minute-granular against a supplied unix timestamp; no global
 * timezone state is ever read (callers pass epoch seconds).
 */
class CronExpression
{
    /** @var array<string, array{min: int, max: int}> Field bounds in canonical order */
    private const FIELDS = [
        'minute' => ['min' => 0, 'max' => 59],
        'hour' => ['min' => 0, 'max' => 23],
        'day' => ['min' => 1, 'max' => 31],
        'month' => ['min' => 1, 'max' => 12],
        'dow' => ['min' => 0, 'max' => 6],
    ];

    /** @var array<string, int> Month name tokens */
    private const MONTH_NAMES = [
        'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
        'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12,
    ];

    /** @var array<string, int> Day-of-week name tokens */
    private const DOW_NAMES = [
        'SUN' => 0, 'MON' => 1, 'TUE' => 2, 'WED' => 3, 'THU' => 4, 'FRI' => 5, 'SAT' => 6,
    ];

    /** @var array<string, int> Match sets per field (dow normalized to 0..6) */
    private readonly array $sets;

    /** @var array<string, bool> Whether the field was restricted (not '*') */
    private readonly array $restricted;

    private function __construct(
        public readonly string $expression,
        array $sets,
        array $restricted,
    ) {
        $this->sets = $sets;
        $this->restricted = $restricted;
    }

    /**
     * Parse and validate a cron expression.
     *
     * @throws InvalidArgumentException On wrong field count or malformed field
     */
    public static function parse(string $expression): self
    {
        $fields = \preg_split('/\s+/', \trim($expression));

        if ($fields === false || \count($fields) !== 5) {
            throw new InvalidArgumentException("Cron expression requires exactly 5 fields, got: '{$expression}'.");
        }

        $sets = [];
        $restricted = [];
        $names = ['minute', 'hour', 'day', 'month', 'dow'];

        foreach ($names as $index => $name) {
            [$sets[$name], $restricted[$name]] = self::parseField($fields[$index], $name, self::FIELDS[$name]);
        }

        return new self(\implode(' ', $fields), $sets, $restricted);
    }

    private static function normalizeTz(DateTimeZone|string|null $tz): DateTimeZone
    {
        if ($tz instanceof DateTimeZone) {
            return $tz;
        }

        if (\is_string($tz)) {
            return new DateTimeZone($tz);
        }

        return new DateTimeZone(\date_default_timezone_get());
    }

    /**
     * Parse one cron field into [match set, restricted?].
     *
     * @param array{min: int, max: int} $bounds
     *
     * @return array{0: array<int, true>, 1: bool}
     */
    private static function parseField(string $field, string $name, array $bounds): array
    {
        [$min, $max] = [$bounds['min'], $bounds['max']];
        $set = [];
        $restricted = $field !== '*';

        foreach (\explode(',', $field) as $term) {
            if ($term === '') {
                throw new InvalidArgumentException("Empty term in {$name} field: '{$field}'.");
            }

            $step = 1;
            $range = $term;

            if (\str_contains($term, '/')) {
                [$range, $stepRaw] = \explode('/', $term, 2);

                if (\preg_match('/^\d+$/', $stepRaw) !== 1 || (int) $stepRaw < 1) {
                    throw new InvalidArgumentException("Bad step '{$stepRaw}' in {$name} field: '{$field}'.");
                }

                $step = (int) $stepRaw;
            }

            if ($range === '*') {
                $from = $min;
                $to = $max;
            } elseif (\preg_match('/^(.+)-(.+)$/', $range, $m) === 1) {
                $from = self::toValue($m[1], $name, $min, $max);
                $to = self::toValue($m[2], $name, $min, $max);
            } else {
                $value = self::toValue($range, $name, $min, $max);
                // Single value with step applies from value to field max (POSIX 'a/n').
                $from = $value;
                $to = $step > 1 ? $max : $value;
            }

            if ($from <= $to) {
                for ($v = $from; $v <= $to; $v += $step) {
                    $set[$v] = true;
                }
            } else {
                // Wrapped range (e.g. fri-mon, dow 5-1)
                $span = $max - $min + 1;

                for ($v = $from; $v <= $to + $span; ++$v) {
                    if ($step > 1 && (($v - $from) % $step) !== 0) {
                        continue;
                    }
                    $set[($v - $min) % $span + $min] = true;
                }
            }
        }

        if ($set === []) {
            throw new InvalidArgumentException("Field '{$name}' resolved to an empty set: '{$field}'.");
        }

        return [$set, $restricted];
    }

    /**
     * Resolve a numeric-or-named token to an in-bounds integer.
     */
    private static function toValue(string $token, string $name, int $min, int $max): int
    {
        $upper = \strtoupper($token);

        if ($name === 'month' && isset(self::MONTH_NAMES[$upper])) {
            return self::MONTH_NAMES[$upper];
        }

        if ($name === 'dow') {
            if (isset(self::DOW_NAMES[$upper])) {
                return self::DOW_NAMES[$upper];
            }
            if ($upper === '7') {
                return 0; // Sunday alias
            }
        }

        if (\preg_match('/^\d+$/', $token) !== 1) {
            throw new InvalidArgumentException("Invalid token '{$token}' in {$name} field.");
        }

        $value = (int) $token;

        if ($value < $min || $value > $max) {
            // Allow dow 0..7 fully via bounds above (max 6 + alias 7 handled).
            throw new InvalidArgumentException("Value {$token} out of range {$min}-{$max} for {$name} field.");
        }

        return $value;
    }

    /**
     * Whether the expression matches the given minute (epoch seconds).
     *
     * @param DateTimeZone|string|null $tz Timezone the schedule is authored in;
     *                                     null defers to the process default.
     *                                     Pass explicitly (e.g. 'UTC') for deterministic
     *                                     evaluation independent of server config.
     */
    public function isDue(int $timestamp, DateTimeZone|string|null $tz = null): bool
    {
        $zone = self::normalizeTz($tz);

        $minute = (int) \date_format((new DateTimeImmutable("@{$timestamp}"))->setTimezone($zone), 'i');
        $hour = (int) \date_format((new DateTimeImmutable("@{$timestamp}"))->setTimezone($zone), 'G');
        $day = (int) \date_format((new DateTimeImmutable("@{$timestamp}"))->setTimezone($zone), 'j');
        $month = (int) \date_format((new DateTimeImmutable("@{$timestamp}"))->setTimezone($zone), 'n');
        $dow = (int) \date_format((new DateTimeImmutable("@{$timestamp}"))->setTimezone($zone), 'w');

        if (!isset($this->sets['minute'][$minute]) || !isset($this->sets['hour'][$hour]) || !isset($this->sets['month'][$month])) {
            return false;
        }

        $domMatch = isset($this->sets['day'][$day]);
        $dowMatch = isset($this->sets['dow'][$dow]);

        // POSIX: both restricted → OR; otherwise the wildcard side always matches → AND collapses.
        if ($this->restricted['day'] && $this->restricted['dow']) {
            return $domMatch || $dowMatch;
        }

        return $domMatch && $dowMatch;
    }

    /**
     * Next matching timestamp strictly after $after (epoch → epoch).
     *
     * Worst case scans a full leap cycle (~730 days of minutes ≈ 1.05M checks);
     * fine for CLI display, not a hot path.
     */
    public function nextRun(int $after, DateTimeZone|string|null $tz = null): ?int
    {
        $limit = $after + 730 * 86400;

        for ($t = $this->floorMinute($after) + 60; $t <= $limit; $t += 60) {
            if ($this->isDue($t, $tz)) {
                return $t;
            }
        }

        return null;
    }

    /**
     * Approximate minimum spacing between consecutive due minutes, sampled over
     * two weeks. Used by missed-run heuristics; conservative, never zero.
     */
    public function approximateInterval(DateTimeZone|string|null $tz = null): int
    {
        $base = $this->floorMinute(\time());
        $prev = null;
        $gap = PHP_INT_MAX;

        for ($t = $base; $t < $base + 14 * 86400; $t += 60) {
            if ($this->isDue($t, $tz)) {
                if ($prev !== null) {
                    $gap = \min($gap, $t - $prev);
                }
                $prev = $t;
            }
        }

        return $gap === PHP_INT_MAX ? 365 * 86400 : \max(60, $gap);
    }

    private function floorMinute(int $timestamp): int
    {
        return $timestamp - $timestamp % 60;
    }
}
