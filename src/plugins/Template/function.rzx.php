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
 * Template Function Plugin: rzx (RZ-28).
 *
 * Renders a registered `.rzx` component inside a `.tpl` page, for screens
 * that migrate one piece at a time. Props come from the controller as one
 * array; slot fills are not available from a `.tpl`. The component's pushes
 * go to the same anchors as the page's {@push}.
 *
 * Usage in templates:
 *   {@rzx name="Kit.ManagementList" props=$list}
 *
 * @package Razy
 *
 * @license MIT
 */

use Razy\Exception\TemplateException;
use Razy\Rzx\Host;
use Razy\Template\Entity;
use Razy\Template\Plugin\TFunction;

return function (...$arguments) {
    return new class(...$arguments) extends TFunction {
        protected array $allowedParameters = [
            'name' => '',
            'props' => null,
        ];

        protected function processor(Entity $entity, array $parameters = [], array $arguments = [], string $wrappedText = ''): ?string
        {
            $name = (string) ($parameters['name'] ?? '');
            if ('' === $name) {
                throw new TemplateException('{@rzx} needs a component name, e.g. {@rzx name="Kit.Card" props=$card}.');
            }
            $props = $parameters['props'] ?? [];
            if (!\is_array($props)) {
                throw new TemplateException('{@rzx name="' . $name . '"} props must be an array.');
            }
            $host = Host::current();
            $manager = $entity->getManager();
            if (!$host || !$manager) {
                throw new TemplateException('{@rzx} needs a dist that is rendering a page.');
            }

            return $host->renderComponent($manager, $entity->getModule(), $name, $props);
        }
    };
};
