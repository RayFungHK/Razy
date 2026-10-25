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
 * Template Function Plugin: each.
 *
 * Iterates over an array source and renders the enclosed content for each element.
 * Each iteration assigns the current key and value to a named variable (defaults to 'kvp').
 * Usage in templates: {@each source=$items as='item'}{$item.key}: {$item.value}{/each}
 *
 * @package Razy
 *
 * @license MIT
 */

use Razy\Template\Entity;
use Razy\Template\Plugin\TFunction;

/**
 * Factory closure that creates and returns the `each` function plugin instance.
 *
 * @param mixed ...$arguments Arguments forwarded to the anonymous TFunction class constructor
 *
 * @return TFunction The function plugin instance for iterating over arrays
 */
return function (...$arguments) {
    return new class(...$arguments) extends TFunction {
        /** @var bool This function requires enclosed content between opening and closing tags */
        protected bool $encloseContent = true;

        // RZ-28: returns {$__each…->raw} placeholders (template source it builds, never a
        // value) for the caller's single substitution, which inserts each rendered item.
        protected bool $templateOutput = true;

        /** @var array Allowed parameters: 'source' is the array to iterate, 'as' is the loop variable name */
        protected array $allowedParameters = [
            'source' => '',
            'as' => 'kvp',
        ];

        /**
         * Process the each function by iterating over the source array.
         *
         * @param Entity $entity The current template entity context
         * @param array $parameters Named parameters ('source' and 'as')
         * @param array $arguments Positional arguments (unused)
         * @param string $wrappedText The enclosed content rendered per iteration
         *
         * @return string The concatenated output from all iterations
         */
        public function processor(Entity $entity, array $parameters = [], array $arguments = [], string $wrappedText = ''): string
        {
            // Ensure the source parameter is an array before iteration
            if (!is_array($parameters['source'])) {
                return '';
            }

            // Default the alias variable name to 'kvp' if empty
            $parameters['as'] = trim($parameters['as']);
            if (0 === strlen($parameters['as'])) {
                $parameters['as'] = 'kvp';
            }

            // Render every item while this alias is assigned. Do not return that
            // text: the caller still scans {$…} once, and a value that contains
            // {$…} would run as template (RZ-31). Each iteration is stored and
            // inserted by that one scan, which does not rescan the value.
            $result = '';
            $token = \bin2hex(\random_bytes(8));
            $index = 0;
            foreach ($parameters['source'] as $key => $value) {
                $entity->assign($parameters['as'], [
                    'key' => $key,
                    'value' => $value,
                ]);

                $slot = '__each' . $token . '_' . $index;
                $entity->assign($slot, $entity->parseText($wrappedText));
                $result .= '{$' . $slot . '->raw}';
                ++$index;
            }

            return $result;
        }
    };
};
