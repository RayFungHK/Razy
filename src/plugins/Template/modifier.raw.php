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
 * Template Modifier Plugin: raw.
 *
 * Marks a tag as trusted HTML so a dist with `'auto_escape' => true` prints it
 * unescaped (RZ-03). The value itself passes through unchanged, so the
 * modifier is a no-op where auto-escape is off (beyond casting to text).
 *
 * Usage in templates: {$article.html->raw}
 * Every use needs a written justification for the module-discipline lint:
 *   <!-- lint-allow: RZ-004 sanitized by the markdown renderer -->
 *
 * @package Razy
 *
 * @license MIT
 */

use Razy\Template\Plugin\TModifier;

/**
 * Factory closure that creates and returns the `raw` modifier instance.
 *
 * @param mixed ...$arguments Arguments forwarded to the anonymous TModifier class constructor
 *
 * @return TModifier The modifier instance that returns the value unchanged
 */
return function (...$arguments) {
    return new class(...$arguments) extends TModifier {
        /**
         * Return the value unchanged as text. Values that cannot render as text
         * (arrays, plain objects) yield null, which renders as an empty string.
         *
         * @param mixed $value The input value
         * @param string ...$args Unused
         *
         * @return string|null
         */
        protected function process(mixed $value, string ...$args): ?string
        {
            if (\is_scalar($value) || (\is_object($value) && \method_exists($value, '__toString'))) {
                return (string) $value;
            }

            return null;
        }
    };
};
