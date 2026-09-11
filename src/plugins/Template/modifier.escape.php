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
 * Template Modifier Plugin: escape.
 *
 * HTML-escapes a value so it can be rendered as text safely. The engine does NOT
 * auto-escape template output, so any value that may contain user input must be
 * passed through this modifier (or escaped earlier in the controller). Semantics
 * match the DOM builder's text-node escaping (ENT_QUOTES, UTF-8).
 *
 * Usage in templates: {$variable->escape}
 * Chains with other modifiers: {$comment.body->trim->escape}
 *
 * @package Razy
 *
 * @license MIT
 */

use Razy\Template\Plugin\TModifier;

/**
 * Factory closure that creates and returns the `escape` modifier instance.
 *
 * @param mixed ...$arguments Arguments forwarded to the anonymous TModifier class constructor
 *
 * @return TModifier The modifier instance that HTML-escapes values
 */
return function (...$arguments) {
    return new class(...$arguments) extends TModifier {
        /**
         * Process the value by HTML-escaping it.
         *
         * Non-scalar values (arrays, objects without __toString) render as an
         * empty string rather than leaking PHP notices or "Array" text. Invalid
         * UTF-8 byte sequences are substituted (ENT_SUBSTITUTE) instead of
         * causing htmlspecialchars() to return an empty string.
         *
         * @param mixed $value The input value to escape
         * @param string ...$args Additional modifier arguments (unused)
         *
         * @return string The HTML-escaped string
         */
        protected function process(mixed $value, string ...$args): string
        {
            if (\is_array($value) || \is_resource($value)) {
                return '';
            }

            if (\is_object($value) && !\method_exists($value, '__toString')) {
                return '';
            }

            return \htmlspecialchars((string) $value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        }
    };
};
