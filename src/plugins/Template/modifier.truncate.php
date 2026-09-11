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
 * Template Modifier Plugin: truncate.
 *
 * Shortens a string to a maximum character count (multibyte-safe — counts UTF-8
 * characters, not bytes, so CJK content truncates correctly). The suffix counts
 * toward the limit, matching the classic `truncate:50` semantics the docs taught.
 *
 * Usage in templates:
 *   {$excerpt->truncate}                default limit 50, suffix '...'
 *   {$excerpt->truncate:120}            custom limit
 *   {$excerpt->truncate:120:'…'}        custom suffix
 *   {$excerpt->truncate:120:'…':1}      cut on word boundary (break mid-word never)
 *
 * @package Razy
 *
 * @license MIT
 */

use Razy\Template\Plugin\TModifier;

/**
 * Factory closure that creates and returns the `truncate` modifier instance.
 *
 * @param mixed ...$arguments Arguments forwarded to the anonymous TModifier class constructor
 *
 * @return TModifier The truncation modifier
 */
return function (...$arguments) {
    return new class(...$arguments) extends TModifier {
        /**
         * Truncate the value to at most $length characters including the suffix.
         *
         * Positional args: [0]=length (default 50), [1]=suffix (default '...'),
         * [2]='1'/'true' to end on a word boundary.
         *
         * @return string The truncated string
         */
        protected function process(mixed $value, string ...$args): string
        {
            if (\is_array($value) || \is_object($value) || \is_resource($value)) {
                return '';
            }

            $text = (string) $value;
            $length = \max(0, (int) ($args[0] ?? 50));
            $suffix = $args[1] ?? '...';
            $cutWord = \strtolower($args[2] ?? '');
            $suffixLen = \mb_strlen($suffix, 'UTF-8');

            // Degenerate: suffix alone would exceed the budget → plain hard cut.
            if ($suffixLen > $length) {
                return \mb_substr($text, 0, $length, 'UTF-8');
            }

            if (\mb_strlen($text, 'UTF-8') <= $length) {
                return $text;
            }

            $keep = $length - $suffixLen;
            $cut = \mb_substr($text, 0, $keep, 'UTF-8');

            if (\in_array($cutWord, ['1', 'true'], true) && $keep > 0) {
                // Back off to the last word boundary; keep the word if the tail runs into one.
                $rest = \mb_substr($text, $keep, 1, 'UTF-8');
                if (\trim($rest) !== '' && \preg_match('/^(.*)(\s+|\p{P})(?=\S)/us', $cut, $m) === 1) {
                    $cut = $m[1];
                }
                $cut = \rtrim($cut);
            }

            return $cut . $suffix;
        }
    };
};
