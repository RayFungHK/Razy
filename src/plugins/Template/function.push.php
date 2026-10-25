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
 * Template Function Plugin: push (RZ-27).
 *
 * Renders the enclosed markup with the current values and adds it to a named
 * anchor; prints nothing where it stands. Inside a false {@if} branch it never
 * runs. Duplicates are dropped by `key`, else by the first src/href. Lower
 * `order` comes first. Scripts get the CSP nonce; an inline script is allowed
 * only for type="importmap" or type="application/json".
 *
 * Usage in templates:
 *   {@push to="head.styles" key="app-css"}<link rel="stylesheet" href="{$css}">{/push}
 *   {@push to="body.scripts" order=10}<script type="module" src="{$js}"></script>{/push}
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

        protected bool $encloseContent = true;

        protected array $allowedParameters = [
            'to' => '',
            'key' => null,
            'order' => 0,
        ];

        protected function processor(Entity $entity, array $parameters = [], array $arguments = [], string $wrappedText = ''): ?string
        {
            $anchor = (string) ($parameters['to'] ?? '');
            if ('' === $anchor) {
                throw new TemplateException('{@push} needs a target anchor, e.g. {@push to="body.scripts"}…{/push}.');
            }
            $key = $parameters['key'] ?? null;
            $key = (null === $key || '' === $key) ? null : (string) $key;

            self::anchors($entity)->push($anchor, $entity->parseText($wrappedText), $key, (int) ($parameters['order'] ?? 0));

            return '';
        }
    };
};
