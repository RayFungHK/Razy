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
 * Template Modifier Plugin: json.
 *
 * JSON-encodes a value for embedding in views. The encoder flags
 * (JSON_HEX_TAG/AMP/APOS/QUOT) make the output safe inside <script> blocks
 * and inside HTML attributes without a further ->escape hop: '</script>'
 * payloads and '<'/'>'/'&'/'\''/'"' all become hex entities.
 *
 * Usage in templates:
 *   <script>const cfg = {$config->json};</script>
 *   <div data-state="{$state->json}">     (attribute-safe)
 *
 * Encoding failure (invalid UTF-8, INF/NAN, depth) renders an empty string —
 * never broken JSON silently. Chain ->escape after this only if you are
 * inserting into a context the hex flags do not cover.
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
