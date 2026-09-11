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
 * Template Modifier Plugin: strip_tags.
 *
 * Removes HTML/PHP tags from a value, optionally keeping an allow-list.
 * For rendering UNtrusted rich text prefer stripping first, then ->escape.
 *
 * Usage in templates:
 *   {$bio->strip_tags}                remove everything
 *   {$bio->strip_tags:'b i em a'}     keep a small allow-list
 *
 * The allow-list accepts both plain names ('b i em') and PHP's native bracket
 * form ('<b> <i>'); plain names are normalised because PHP (all 8.x versions
 * to date) rejects bare space-separated names.
 *
 * @package Razy
 *
 * @license MIT
 */

use Razy\Template\Plugin\TModifier;

/**
 * Factory closure that creates and returns the `strip_tags` modifier instance.
 *
 * @param mixed ...$arguments Arguments forwarded to the anonymous TModifier class constructor
 *
 * @return TModifier The tag-stripping modifier
 */
return function (...$arguments) {
    return new class(...$arguments) extends TModifier {
        /**
         * Strip HTML tags (and comment content) from the value.
         *
         * Positional args: [0]=tag allow-list — 'b i em' or '<b> <i>' ('' = strip all).
         *
         * @return string The stripped text
         */
        protected function process(mixed $value, string ...$args): string
        {
            $allowed = trim($args[0] ?? '');

            if (\is_array($value) || \is_resource($value)) {
                return '';
            }

            if (\is_object($value) && !\method_exists($value, '__toString')) {
                return '';
            }

            // Normalise 'b i em' → '<b> <i> <em>': PHP's strip_tags() only understands
            // the bracket form; space-separated names arrive in 8.4+.
            if ($allowed !== '' && !\str_contains($allowed, '<')) {
                $tags = \array_filter(\preg_split('/\s+/', $allowed) ?: []);
                $allowed = \implode(' ', \array_map(static fn (string $t): string => '<' . $t . '>', $tags));
            }

            // PHP 8.2+: null strips every tag; a string is the allow-list.
            return \strip_tags((string) $value, $allowed === '' ? null : $allowed);
        }
    };
};
