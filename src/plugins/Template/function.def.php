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
 * Template Function Plugin: def
 *
 * Defines (assigns) a variable in the current template entity context.
 * The variable can then be referenced elsewhere in the template.
 * Usage in templates: {@def name='varname' value=$someValue}
 *
 * @package Razy
 * @license MIT
 */

use Razy\Template\Entity;
use Razy\Template\Plugin\TFunction;

/**
 * Factory closure that creates and returns the `def` function plugin instance.
 *
 * @param mixed ...$arguments Arguments forwarded to the anonymous TFunction class constructor
 *
 * @return TFunction The function plugin instance for defining template variables
 */
return function (...$arguments) {
    return new class(...$arguments) extends TFunction {
        /** @var array Allowed parameters: 'name' is the variable name, 'value' is the variable value */
        protected array $allowedParameters = [
            'name' => '',
            'value' => '',
        ];

        /**
         * Process the def function by assigning a variable to the template entity.
         *
         * @param Entity $entity      The current template entity context
         * @param array  $parameters  Named parameters ('name' and 'value')
         * @param array  $arguments   Positional arguments (unused)
         * @param string $wrappedText Any enclosed content (unused)
         *
         * @return string Always returns empty string (no visible output)
         */
        public function processor(Entity $entity, array $parameters = [], array $arguments = [], string $wrappedText = ''): string
        {
            // Trim the variable name to remove any accidental whitespace
            $parameters['name'] = trim($parameters['name']);

            // Skip assignment if no variable name was provided
            if (!$parameters['name']) {
                return '';
            }

            // Assign the value to the entity under the given variable name
            $entity->assign([
                $parameters['name'] => $parameters['value'] ?? null,
            ]);

            // This function produces no visible output
            return '';
        }
    };
};