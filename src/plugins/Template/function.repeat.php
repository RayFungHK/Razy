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
        'length' => 1,
    ],
    'processor' => function (Container $container) {
        $parameters           = $container->getParameters();
        $parameters['length'] = (int) $parameters['length'];
        if ($parameters['length'] < 0) {
            $parameters['length'] = 0;
        }

        return ($parameters['length'] > 0) ? str_repeat($container->getContent(), $parameters['length']) : '';
    },
];
