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
 * Template Modifier Plugin: trim
 *
 * Strips leading and trailing whitespace from a string value.
 * Usage in templates: {$variable|trim}
 *
 * @package Razy
 * @license MIT
 */

use Razy\Template\Plugin\TModifier;

/**
 * Factory closure that creates and returns the `trim` modifier instance.
 *
 * @param mixed ...$arguments Arguments forwarded to the anonymous TModifier class constructor
 *
 * @return TModifier The modifier instance that trims whitespace
 */
return function (...$arguments) {
    return new class(...$arguments) extends TModifier {
        /**
         * Process the value by trimming leading and trailing whitespace.
         *
         * @param mixed  $value The input value to transform
         * @param string ...$args Additional modifier arguments (unused)
         *
         * @return string The trimmed string
         */
        protected function process(mixed $value, string ...$args): string
        {
            // Remove whitespace from both ends of the string
            return trim($value);
        }
    };
};
