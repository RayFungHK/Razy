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
 * Template Modifier Plugin: join
 *
 * Joins array elements into a single string using a specified separator.
 * Usage in templates: {$array_variable|join:','}
 *
 * @package Razy
 * @license MIT
 */

use Razy\Template\Plugin\TModifier;

/**
 * Factory closure that creates and returns the `join` modifier instance.
 *
 * @param mixed ...$arguments Arguments forwarded to the anonymous TModifier class constructor
 *
 * @return TModifier The modifier instance that joins array elements
 */
return function (...$arguments) {
    return new class(...$arguments) extends TModifier {
        /**
         * Process the value by joining array elements with the given separator.
         *
         * @param mixed  $value The input array to join
         * @param string ...$args The first argument is the separator string
         *
         * @return string The joined string
         */
        protected function process(mixed $value, string ...$args): string
        {
            // Extract the separator from the first argument
            [$separator] = $args;

            // Implode the array elements using the specified separator
            return implode($separator, $value);
        }
    };
};