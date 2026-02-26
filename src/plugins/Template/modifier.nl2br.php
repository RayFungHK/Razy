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
 * Template Modifier Plugin: nl2br
 *
 * Converts newline characters to HTML <br> tags for proper rendering in HTML output.
 * Usage in templates: {$variable|nl2br}
 *
 * @package Razy
 * @license MIT
 */

use Razy\Template\Plugin\TModifier;

/**
 * Factory closure that creates and returns the `nl2br` modifier instance.
 *
 * @param mixed ...$arguments Arguments forwarded to the anonymous TModifier class constructor
 *
 * @return TModifier The modifier instance that converts newlines to <br> tags
 */
return function (...$arguments) {
    return new class(...$arguments) extends TModifier {
        /**
         * Process the value by converting newline characters to HTML line breaks.
         *
         * @param mixed  $value The input value containing newline characters
         * @param string ...$args Additional modifier arguments (unused)
         *
         * @return string The string with newlines replaced by <br> tags
         */
        protected function process(mixed $value, string ...$args): string
        {
            // Insert HTML <br> tags before each newline character
            return nl2br($value);
        }
    };
};