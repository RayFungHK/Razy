<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Extracted from bootstrap.inc.php (Phase 2.5).
 * Provides static utility methods for date and weekday calculations.
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy\Util;

use DateTime;
use Exception;

/**
 * Date calculation utilities.
 *
 * Provides methods for computing future weekdays, weekday differences,
 * and day differences between dates, with optional holiday exclusion.
 */
class DateUtil
{
    /**
     * Get the future weekday date after skipping a given number of business days.
     *
     * @param string $startDate The starting date (parseable by DateTime)
     * @param int $numberOfDays The number of weekdays to advance
     * @param array $holidays An array of holiday date strings (Y-m-d format) to skip
     *
     * @return string The resulting date in Y-m-d format
     *
     * @throws Exception If the date string is invalid
     */
    public static function getFutureWeekday(string $startDate, int $numberOfDays, array $holidays = []): string
    {
        $holidays = \array_fill_keys($holidays, true);
        $datetime = new DateTime($startDate);
        for ($day = 0; $day < $numberOfDays; ++$day) {
            do {
                $date = $datetime->modify('+1 weekday')->format('Y-m-d');
            } while (isset($holidays[$date]));
        }
        return $date ?? $startDate;
    }

    /**
     * Compare two dates and return the weekday difference.
     *
     * @param string|null $startDate The start date (null or empty for 'now')
     * @param string|null $endDate The end date (null or empty for 'now')
     * @param array $holidays An array of holiday date strings (Y-m-d format) to exclude
     *
     * @return int The number of weekdays between the two dates (negative if start > end)
     *
     * @throws Exception If the date string is invalid
     */
    public static function getWeekdayDiff(?string $startDate = '', ?string $endDate = '', array $holidays = []): int
    {
        $holidays = \array_fill_keys($holidays, true);
        $datetime = $startDate ? new DateTime($startDate) : new DateTime('now');
        $endDate = $endDate ? new DateTime($endDate) : new DateTime('now');

        $days = 0;
        $adj = ($datetime < $endDate) ? 1 : -1;

        if ($datetime > $endDate) {
            [$datetime, $endDate] = [$endDate, $datetime];
        }
        while ($datetime->diff($endDate)->days > 0) {
            do {
                $days++;
                $datetime->modify('+1 weekday');
                $date = $datetime->format('Y-m-d');
            } while (isset($holidays[$date]));
        }

        return $days * $adj;
    }

    /**
     * Compare two dates and return the calendar day difference.
     *
     * @param string|null $startDate The start date (null or empty for 'now')
     * @param string|null $endDate The end date (null or empty for 'now')
     *
     * @return int The number of calendar days between the two dates
     *
     * @throws Exception If the date string is invalid
     */
    public static function getDayDiff(?string $startDate = '', ?string $endDate = ''): int
    {
        $datetime = $startDate ? new DateTime($startDate) : new DateTime('now');
        $endDate = $endDate ? new DateTime($endDate) : new DateTime('now');

        return ((int) $datetime->diff($endDate)->format('%a')) * (($datetime > $endDate) ? 1 : -1);
    }
}
