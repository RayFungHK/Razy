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

return [
    'enclose_content'    => false,
    'bypass_parser'      => false,
    'extended_parameter' => true,
    'parameters'         => [],
    'processor'          => function (string $content, array $parameters) {
        $parameters['name'] = trim($parameters['name']);

        if (!$parameters['name']) {
            return '';
        }
        $template = $this->getTemplate($parameters['name']);
        unset($parameters['name']);

        if (!($template instanceof Block) || !$template->isReadonly()) {
            return '';
        }

        return $template->newEntity()->assign($parameters)->process();
    },
];
