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
    protected bool $enclosedContent = true;
    protected array $allowedParameters = [
        'source' => '',
        'as'     => 'kvp',
    ];

    #[Override] public function processor(Entity $entity, array $parameters = [], array $arguments = [], string $wrappedText = ''): string
    {
        if (!is_array($parameters['source'])) {
            return '';
        }

        $parameters['as'] = trim($parameters['as']);
        if (0 === strlen($parameters['as'])) {
            $parameters['as'] = 'kvp';
        }

        $result = '';
        foreach ($parameters['source'] as $key => $value) {
            $entity->assign($parameters['as'], [
                'key'   => $key,
                'value' => $value,
            ]);
            $result .= $entity->parseText($wrappedText);
        }

        return $result;
    }
};
