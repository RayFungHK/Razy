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
 * Template Modifier Plugin: number.
 *
 * Formats a numeric value with thousands separators and fixed decimals
 * (number_format wrapper). Non-numeric values render as an empty string.
 *
 * Usage in templates:
 *   {$price->number}                 1234567 → '1,234,567'
 *   {$price->number:2}               → '1,234,567.00'
 *   {$price->number:2:',':'.'}       European style → '1.234.567,00'
 *
 * @package Razy
 *
 * @license MIT
 */

use Razy\Template\Plugin\TModifier;

/**
 * Factory closure that creates and returns the `number` modifier instance.
 *
 * @param mixed ...$arguments Arguments forwarded to the anonymous TModifier class constructor
 *
 * @return TModifier The number-formatting modifier
 */
return function (...$arguments) {
    return new class(...$arguments) extends TModifier {
        /**
         * Format the value as a grouped decimal number.
         *
         * Positional args: [0]=decimal places (default 0), [1]=decimal separator
         * (default '.'), [2]=thousands separator (default ',').
         *
         * @return string The formatted number, or '' for non-numeric input
         */
        protected function process(mixed $value, string ...$args): string
        {
            $decimals = $args[0] ?? '0';
            $dec = $args[1] ?? '.';
            $thousand = $args[2] ?? ',';

            if (\is_array($value) || \is_resource($value) || \is_bool($value) || ($value !== null && !\is_numeric($value) && !(\is_object($value) && \method_exists($value, '__toString')))) {
                return '';
            }

            if (!\is_numeric($value)) {
                $value = (string) $value;

                if (!\is_numeric($value)) {
                    return '';
                }
            }

            return \number_format((float) $value, \max(0, (int) $decimals), $dec === '' ? '.' : $dec, $thousand);
        }
    };
};
