<?php

/**
 * razymod/apps — what an expression may know about the world at run time.
 *
 * The acting principal (from the host's principal adapter), the clock and
 * the working-day calendar. Nothing else: an expression cannot reach other
 * records, other data spaces, the environment or the filesystem.
 */

declare(strict_types=1);

namespace razymod\apps\Expression;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

final class Context
{
    public readonly DateTimeImmutable $now;

    public readonly DateTimeZone $timezone;

    /** @var array<string, true> holiday dates (Y-m-d) */
    public readonly array $holidays;

    /**
     * @param ?string $user acting user id, null when there is none
     * @param list<string> $groups the user's group ids
     * @param list<string> $departments the user's department ids
     * @param ?DateTimeImmutable $now the clock (injected for tests and replays)
     * @param string $timezone the data space's time zone for TODAY() and date parts
     * @param array<mixed> $holidays non-working dates (Y-m-d) for WORKDAY / NETWORKDAYS; other entries are ignored
     */
    public function __construct(
        public readonly ?string $user = null,
        public readonly array $groups = [],
        public readonly array $departments = [],
        ?DateTimeImmutable $now = null,
        string $timezone = 'UTC',
        array $holidays = [],
    ) {
        try {
            $this->timezone = new DateTimeZone($timezone);
        } catch (Exception) {
            throw new ExpressionError(ExpressionError::VALUE, "Unknown time zone '{$timezone}'");
        }
        $this->now = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $set = [];
        foreach ($holidays as $day) {
            if (\is_string($day) && \preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1) {
                $set[$day] = true;
            }
        }
        $this->holidays = $set;
    }
}
