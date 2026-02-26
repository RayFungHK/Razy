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
 * Template Modifier Plugin: lower
 *
 * Converts a string value to lowercase using PHP's strtolower() function.
 * Usage in templates: {$variable|lower}
 *
 * @package Razy
 * @license MIT
 */

use Razy\Template\Plugin\TModifier;

/**
 * Factory closure that creates and returns the `lower` modifier instance.
 *
 * @param mixed ...$arguments Arguments forwarded to the anonymous TModifier class constructor
 *
 * @return TModifier The modifier instance that converts values to lowercase
 */
return function (...$arguments) {
    return new class(...$arguments) extends TModifier {
        /**
         * Process the value by converting it to lowercase.
         *
         * @param mixed  $value The input value to transform
         * @param string ...$args Additional modifier arguments (unused)
         *
         * @return string The lowercase string
         */
        protected function process(mixed $value, string ...$args): string
        {
            // Convert the entire string to lowercase characters
            return strtolower($value);
        }
    };
};
