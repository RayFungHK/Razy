<?php
/*
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

use Razy\Template\Block;
use Razy\Template\Entity;
use Razy\Template\Plugin\TFunction;

return function (...$arguments) {
    return new class(...$arguments) extends TFunction {
        protected bool $extendedParameter = true;
        protected array $allowedParameters = [
            'length' => 1,
        ];

        public function processor(Entity $entity, array $parameters = [], array $arguments = [], string $wrappedText = ''): string
        {
            $tplName = $arguments[0] ?? '';

            if (!$tplName) {
                return '';
            }
            $template = $entity->getTemplate($tplName);
            if (!($template instanceof Block)) {
                return '';
            }

            return $template->newEntity()->assign($parameters)->process();
        }
    };
};
