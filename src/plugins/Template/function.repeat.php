<?php

/*
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

use Razy\Template\Entity;
use Razy\Template\Plugin\TFunction;

return function (...$arguments) {
    return new class(...$arguments) extends TFunction {
        protected bool $encloseContent = true;
        protected array $allowedParameters = [
            'length' => 1,
        ];

        public function processor(Entity $entity, array $parameters = [], array $arguments = [], string $wrappedText = ''): string
        {
            $parameters['length'] = (int)$parameters['length'];
            if ($parameters['length'] < 0) {
                $parameters['length'] = 0;
            }

            return ($parameters['length'] > 0) ? str_repeat($wrappedText, $parameters['length']) : '';
        }
    };
};