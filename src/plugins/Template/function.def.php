<?php

/*
 * This file is part of Razy v0.4.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

use Razy\Template\Plugin\Container;

return [
    'enclose_content' => false,
    'bypass_parser'   => false,
    'parameters'      => [
        'name'  => '',
        'value' => '',
    ],
    'processor' => function (Container $container) {
        $parameters         = $container->getParameters();
        $parameters['name'] = trim($parameters['name']);

        if (!$parameters['name']) {
            return '';
        }

        $this->assign([
            $parameters['name'] => $parameters['value'] ?? null,
        ]);

        return '';
    },
];
