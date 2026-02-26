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
 * Template Modifier Plugin: capitalize
 *
 * Capitalizes the first letter of each word in the string, converting
 * the rest to lowercase first for consistent output.
 * Usage in templates: {$variable|capitalize}
 *
 * @package Razy
 * @license MIT
 */

use Razy\Template\Plugin\TModifier;

/**
 * Factory closure that creates and returns the `capitalize` modifier instance.
 *
 * @param mixed ...$arguments Arguments forwarded to the anonymous TModifier class constructor
 *
 * @return TModifier The modifier instance that capitalizes words
 */
return function (...$arguments) {
    return new class(...$arguments) extends TModifier {
        /**
         * Process the value by capitalizing the first letter of each word.
         *
         * @param mixed  $value The input value to transform
         * @param string ...$args Additional modifier arguments (unused)
         *
         * @return string The capitalized string with each word's first letter uppercased
         */
        protected function process(mixed $value, string ...$args): string
        {
            // First lowercase the entire string, then capitalize each word's first letter
            return ucwords(strtolower($value));
        }
    };
};
