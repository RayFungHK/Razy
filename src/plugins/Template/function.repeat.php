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
 * Template Function Plugin: repeat
 *
 * Repeats the enclosed content a specified number of times.
 * Usage in templates: {@repeat length=3}content{/repeat}
 *
 * @package Razy
 * @license MIT
 */

use Razy\Template\Entity;
use Razy\Template\Plugin\TFunction;

/**
 * Factory closure that creates and returns the `repeat` function plugin instance.
 *
 * @param mixed ...$arguments Arguments forwarded to the anonymous TFunction class constructor
 *
 * @return TFunction The function plugin instance for repeating content
 */
return function (...$arguments) {
    return new class(...$arguments) extends TFunction {
        /** @var bool This function requires enclosed content between opening and closing tags */
        protected bool $encloseContent = true;

        /** @var array Allowed parameters: 'length' controls the number of repetitions */
        protected array $allowedParameters = [
            'length' => 1,
        ];

        /**
         * Process the repeat function by repeating the wrapped content.
         *
         * @param Entity $entity      The current template entity context
         * @param array  $parameters  Named parameters ('length' = number of repetitions)
         * @param array  $arguments   Positional arguments (unused)
         * @param string $wrappedText The enclosed content to repeat
         *
         * @return string The repeated content, or empty string if length is 0 or negative
         */
        public function processor(Entity $entity, array $parameters = [], array $arguments = [], string $wrappedText = ''): string
        {
            // Cast length to integer and clamp negative values to 0
            $parameters['length'] = (int)$parameters['length'];
            if ($parameters['length'] < 0) {
                $parameters['length'] = 0;
            }

            // Repeat the wrapped text the specified number of times
            return ($parameters['length'] > 0) ? str_repeat($wrappedText, $parameters['length']) : '';
        }
    };
};