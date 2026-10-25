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
 * Template Function Plugin: anchor (RZ-27).
 *
 * Prints a named anchor that {@push} fills. The anchor's content is put in
 * place when the page finishes rendering, so a head anchor printed before the
 * page body still receives the body's pushes. `content` is the reserved anchor
 * for the page body inside a site layout.
 *
 * Usage in templates:
 *   {@anchor name="head.styles"}
 *   {@anchor name="content"}        (site layout only)
 *
 * @package Razy
 *
 * @license MIT
 */

use Razy\Exception\TemplateException;
use Razy\Template\Entity;
use Razy\Template\Plugin\TFunction;

return function (...$arguments) {
    return new class(...$arguments) extends TFunction {
        private static function anchors(Entity $entity): \Razy\View\Anchors
        {
            $manager = $entity->getManager();
            if (!$manager) {
                throw new TemplateException('Anchors need a template that is being rendered.');
            }

            return $manager->anchors();
        }

        protected array $allowedParameters = [
            'name' => '',
        ];

        protected function processor(Entity $entity, array $parameters = [], array $arguments = [], string $wrappedText = ''): ?string
        {
            $name = (string) ($parameters['name'] ?? '');
            if ('' === $name) {
                throw new TemplateException('{@anchor} needs a name, e.g. {@anchor name="head.styles"}.');
            }

            return self::anchors($entity)->marker($name);
        }
    };
};
