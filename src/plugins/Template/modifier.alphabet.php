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
 * Template Modifier Plugin: alphabet
 *
 * Sanitizes a string by replacing all non-alphanumeric characters (except underscores)
 * with a specified replacement character (defaults to hyphen). Useful for generating
 * URL-safe slugs or identifiers.
 * Usage in templates: {$variable|alphabet} or {$variable|alphabet:'_'}
 *
 * @package Razy
 * @license MIT
 */

use Razy\Template\Plugin\TModifier;

/**
 * Factory closure that creates and returns the `alphabet` modifier instance.
 *
 * @param mixed ...$arguments Arguments forwarded to the anonymous TModifier class constructor
 *
 * @return TModifier The modifier instance that sanitizes strings to alphanumeric form
 */
return function (...$arguments) {
    return new class(...$arguments) extends TModifier {
        /**
         * Process the value by replacing non-alphanumeric characters.
         *
         * @param mixed  $value The input value to sanitize
         * @param string ...$args The first argument is the replacement character (defaults to '-')
         *
         * @return string The sanitized, slug-like string
         */
        protected function process(mixed $value, string ...$args): string
        {
            // Extract the replacement character, defaulting to hyphen if not specified
            [$replacement] = $args;

            // Replace non-alphanumeric/underscore characters and trim trailing hyphens
            return trim(preg_replace('/[^a-z0-9_]+/i', $replacement ?: '-', trim($value)), '-');
        }
    };
};
