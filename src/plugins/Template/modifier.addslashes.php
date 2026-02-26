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
 * Template Modifier Plugin: addslashes
 *
 * Escapes special characters (single quotes, double quotes, backslashes, and NUL bytes)
 * in a string by prefixing them with a backslash. Useful for safely embedding
 * strings in JavaScript or other contexts requiring escaped content.
 * Usage in templates: {$variable|addslashes}
 *
 * @package Razy
 * @license MIT
 */

use Razy\Template\Plugin\TModifier;

/**
 * Factory closure that creates and returns the `addslashes` modifier instance.
 *
 * @param mixed ...$arguments Arguments forwarded to the anonymous TModifier class constructor
 *
 * @return TModifier The modifier instance that escapes special characters
 */
return function (...$arguments) {
    return new class(...$arguments) extends TModifier {
        /**
         * Process the value by escaping special characters with backslashes.
         *
         * @param mixed  $value The input value to escape
         * @param string ...$args Additional modifier arguments (unused)
         *
         * @return string The escaped string with backslashes added
         */
        protected function process(mixed $value, string ...$args): string
        {
            // Escape quotes, backslashes, and NUL bytes in the string
            return addslashes($value);
        }
    };
};