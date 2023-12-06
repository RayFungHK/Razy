<?php

/*
 * This file is part of Razy v0.4.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

use Razy\Template\Entity;
use Razy\Template\Plugin\TFunction;

return new class() extends TFunction
{
    protected array $allowedParameters = [
        'name'  => '',
        'value' => '',
    ];

    #[Override] public function processor(Entity $entity, array $parameters = [], array $arguments = [], string $wrappedText = ''): string
    {
        $parameters['name'] = trim($parameters['name']);

        if (!$parameters['name']) {
            return '';
        }

        $entity->assign([
            $parameters['name'] => $parameters['value'] ?? null,
        ]);

        return '';
    }
};