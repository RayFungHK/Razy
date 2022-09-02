<?php

/*
 * This file is part of Razy v0.4.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

use Razy\Template\Block;
use Razy\Template\Plugin\Container;

return [
    'enclose_content'    => false,
    'bypass_parser'      => false,
    'extended_parameter' => true,
    'parameters'         => [],
    'processor'          => function (Container $container) {
        $parameters = $container->getParameters();
        $arguments  = $container->getArguments();
        $tplName    = $arguments[0] ?? '';

        if (!$tplName) {
            return '';
        }
        $template = $this->getTemplate($tplName);
        if (!($template instanceof Block)) {
            return '';
        }

        return $template->newEntity()->assign($parameters)->process();
    },
];
