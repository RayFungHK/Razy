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
 * Template Function Plugin: template
 *
 * Renders a named template block within the current template context.
 * Looks up a template block by name, creates a new entity from it,
 * assigns the provided parameters, and returns the processed output.
 * Usage in templates: {@template 'block_name' param1=$value1}
 *
 * @package Razy
 * @license MIT
 */

use Razy\Template\Block;
use Razy\Template\Entity;
use Razy\Template\Plugin\TFunction;

/**
 * Factory closure that creates and returns the `template` function plugin instance.
 *
 * @param mixed ...$arguments Arguments forwarded to the anonymous TFunction class constructor
 *
 * @return TFunction The function plugin instance for rendering sub-templates
 */
return function (...$arguments) {
    return new class(...$arguments) extends TFunction {
        /** @var bool Enable extended parameter parsing for named parameters */
        protected bool $extendedParameter = true;

        /** @var array Allowed parameters with their default values */
        protected array $allowedParameters = [
            'length' => 1,
        ];

        /**
         * Process the template function by rendering a named template block.
         *
         * @param Entity $entity     The current template entity context
         * @param array  $parameters Named parameters passed to the function
         * @param array  $arguments  Positional arguments (first is the template block name)
         * @param string $wrappedText Any text wrapped between opening and closing tags
         *
         * @return string The rendered template block output, or empty string if not found
         */
        public function processor(Entity $entity, array $parameters = [], array $arguments = [], string $wrappedText = ''): string
        {
            // Get the template block name from the first positional argument
            $tplName = $arguments[0] ?? '';

            // Return empty if no template name was provided
            if (!$tplName) {
                return '';
            }

            // Look up the template block by name from the entity's template registry
            $template = $entity->getTemplate($tplName);

            // Verify the retrieved template is a valid Block instance
            if (!($template instanceof Block)) {
                return '';
            }

            // Create a new entity from the block, assign parameters, and process it
            return $template->newEntity()->assign($parameters)->process();
        }
    };
};
