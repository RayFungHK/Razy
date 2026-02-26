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
 * Template Modifier Plugin: gettype
 *
 * Returns the PHP type name of the given value (e.g., 'string', 'integer', 'array').
 * Usage in templates: {$variable|gettype}
 *
 * @package Razy
 * @license MIT
 */

use Razy\Template\Plugin\TModifier;

/**
 * Factory closure that creates and returns the `gettype` modifier instance.
 *
 * @param mixed ...$arguments Arguments forwarded to the anonymous TModifier class constructor
 *
 * @return TModifier The modifier instance that returns the type name
 */
return function (...$arguments) {
    return new class(...$arguments) extends TModifier {
        /**
         * Process the value by returning its PHP type name.
         *
         * @param mixed  $value The input value to inspect
         * @param string ...$args Additional modifier arguments (unused)
         *
         * @return string The type name of the value
         */
        protected function process(mixed $value, string ...$args): string
        {
            // Return the PHP type name of the value
            return gettype($value);
        }
    };
};
