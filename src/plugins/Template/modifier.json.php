<?php

/*
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

/**
 * Template Modifier Plugin: json.
 *
 * JSON-encodes a value for embedding in views. The encoder flags
 * (JSON_HEX_TAG/AMP/APOS/QUOT) turn '<'/'>'/'&'/'\''/'"' inside values into
 * \uXXXX escapes, so a '</script>' payload cannot end a <script> block. Under
 * auto-escape (RZ-03) a chain ENDING in ->json prints unescaped, like ->raw.
 *
 * Usage in templates:
 *   <script>const cfg = {$config->json};</script>
 *   <div data-state='{$state->json}'>            (single-quoted attribute)
 *   <div data-state="{$state->json->escape}">    (double-quoted: escape the JSON's own quotes)
 *
 * Never `attr="{$x->json}"` or an unquoted attribute: the JSON's structural
 * `"` closes the attribute (the module-discipline lint reports it as RZ-004).
 * Encoding failure (invalid UTF-8, INF/NAN, depth) renders an empty string —
 * never broken JSON silently.
 *
 * @package Razy
 *
 * @license MIT
 */

use Razy\Template\Plugin\TModifier;

/**
 * Factory closure that creates and returns the `json` modifier instance.
 *
 * @param mixed ...$arguments Arguments forwarded to the anonymous TModifier class constructor
 *
 * @return TModifier The JSON-encoding modifier
 */
return function (...$arguments) {
    return new class(...$arguments) extends TModifier {
        /**
         * Encode the value as context-safe JSON.
         *
         * Positional args: [0]=maximum nesting depth (default 512).
         *
         * @return string The encoded JSON, or '' when encoding fails
         */
        protected function process(mixed $value, string ...$args): string
        {
            try {
                return \json_encode(
                    $value,
                    \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
                    \max(1, (int) ($args[0] ?? 512)),
                );
            } catch (JsonException) {
                return '';
            }
        }
    };
};
