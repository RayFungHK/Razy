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
 * Template Function Plugin: asset (RZ-09).
 *
 * Prints the content-hashed URL of a module's web asset, HTML-escaped for
 * an attribute: `/webassets/{alias}/{version}/{file}?v=<hash>`. One URL per
 * file, so the browser caches it until the content changes.
 *
 * Usage in templates:
 *   <script type="module" src="{@asset file='js/app.js'}"></script>
 *   <link rel="stylesheet" href="{@asset file='css/site.css' module='theme'}">
 *
 * Parameters (named; the engine's positional form does not reach plugins):
 *   file   (str) path below the module's webassets/ folder
 *   module (str) module alias; default: the module that loaded the template
 *
 * @package Razy
 *
 * @license MIT
 */

use Razy\Asset\Assets;
use Razy\Template\Entity;
use Razy\Template\Plugin\TFunction;

/**
 * Factory closure that creates and returns the `asset` function plugin instance.
 *
 * @param mixed ...$arguments Arguments forwarded to the anonymous TFunction class constructor
 *
 * @return TFunction The asset URL function
 */
return function (...$arguments) {
    return new class(...$arguments) extends TFunction {
        /** @var array Declared parameters */
        protected array $allowedParameters = [
            'file' => '',
            'module' => '',
        ];

        /**
         * Print the hashed URL.
         *
         * @param Entity $entity The current template entity context
         * @param array $parameters Named parameters ('file', 'module')
         * @param array $arguments Positional arguments (unused)
         * @param string $wrappedText Enclosed content (unused)
         *
         * @return string The escaped URL
         */
        protected function processor(Entity $entity, array $parameters = [], array $arguments = [], string $wrappedText = ''): ?string
        {
            $alias = \trim((string) ($parameters['module'] ?? ''));
            if ('' === $alias) {
                $alias = (string) $entity->getModule()?->getAlias();
            }
            if ('' === $alias) {
                throw new InvalidArgumentException("{@asset} needs module='alias' in a template that no module loaded.");
            }

            return \htmlspecialchars(Assets::current()->url($alias, (string) ($parameters['file'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    };
};
