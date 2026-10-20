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
 * Template Function Plugin: importmap (RZ-09).
 *
 * Prints `<script type="importmap" nonce="…">` mapping every .js/.mjs file
 * of the dist's modules to its content-hashed URL (plus dist.php
 * 'assets' => ['imports' => …] bare specifiers), so a relative `import` and
 * `{@asset}` load the same URL and a module is evaluated once. The nonce is
 * the request's CSP nonce (RZ-10). Put it in <head>, before any module script.
 *
 * Usage in templates:
 *   {@importmap}
 *
 * @package Razy
 *
 * @license MIT
 */

use Razy\Asset\Assets;
use Razy\Http\SecurityHeaders;
use Razy\Template\Entity;
use Razy\Template\Plugin\TFunction;

/**
 * Factory closure that creates and returns the `importmap` function plugin instance.
 *
 * @param mixed ...$arguments Arguments forwarded to the anonymous TFunction class constructor
 *
 * @return TFunction The importmap function
 */
return function (...$arguments) {
    return new class(...$arguments) extends TFunction {
        /**
         * Print the importmap script element.
         *
         * @param Entity $entity The current template entity context (unused)
         * @param array $parameters Parameters (none)
         * @param array $arguments Positional arguments (unused)
         * @param string $wrappedText Enclosed content (unused)
         *
         * @return string The script element
         */
        protected function processor(Entity $entity, array $parameters = [], array $arguments = [], string $wrappedText = ''): ?string
        {
            return Assets::current()->importMapTag(SecurityHeaders::current()->nonce());
        }
    };
};
