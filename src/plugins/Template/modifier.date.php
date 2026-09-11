<?php

/*
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

/**
 * Template Modifier Plugin: date.
 *
 * Formats a datetime value with a PHP date format string. Accepts Unix
 * timestamps (seconds or milliseconds), numeric strings, and any string
 * DateTimeImmutable can parse. Unparseable values render the original text
 * unchanged (so static placeholders like '—' survive).
 *
 * Usage in templates:
 *   {$order.created_at->date}                    default format 'Y-m-d H:i'
 *   {$order.created_at->date:'Y年n月j日'}         custom format (quoted)
 *   {$ts->date:'Y-m-d':'UTC'}                    explicit timezone
 *
 * @package Razy
 *
 * @license MIT
 */

use Razy\Template\Plugin\TModifier;

/**
 * Factory closure that creates and returns the `date` modifier instance.
 *
 * @param mixed ...$arguments Arguments forwarded to the anonymous TModifier class constructor
 *
 * @return TModifier The date-formatting modifier
 */
return function (...$arguments) {
    return new class(...$arguments) extends TModifier {
        /**
         * Format the value as a date string.
         *
         * Positional args: [0]=PHP date() format (default 'Y-m-d H:i'),
         * [1]=target timezone name (default: PHP's current setting).
         *
         * @return string The formatted date, or the original text when unparseable
         */
        protected function process(mixed $value, string ...$args): string
        {
            $format = $args[0] ?? 'Y-m-d H:i';
            $tz = $args[1] ?? '';

            if (\is_array($value) || \is_resource($value)) {
                return '';
            }

            if (\is_object($value) && !($value instanceof DateTimeInterface) && !\method_exists($value, '__toString')) {
                return '';
            }

            if ($value instanceof DateTimeInterface) {
                $date = DateTimeImmutable::createFromInterface($value);
            } else {
                $raw = (string) $value;

                if (\is_numeric($value) || \preg_match('/^\d{9,13}$/', $raw) === 1) {
                    $ts = (int) $value;
                    // Heuristic: 13-digit values are epoch milliseconds.
                    $date = ($ts > 9_999_999_999) ? (new DateTimeImmutable('@' . intdiv($ts, 1000))) : (new DateTimeImmutable('@' . $ts));
                } else {
                    try {
                        $date = new DateTimeImmutable($raw);
                    } catch (Throwable) {
                        return $raw;
                    }
                }
            }

            if ($tz !== '') {
                try {
                    $date = $date->setTimezone(new DateTimeZone($tz));
                } catch (Throwable) {
                    // Unknown timezone: format in the date's own zone rather than throwing.
                }
            }

            return $date->format($format);
        }
    };
};
