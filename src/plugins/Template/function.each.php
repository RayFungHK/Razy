<?php

/*
 * This file is part of Razy v0.4.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

return [
    'enclose_content' => true,
    'bypass_parser'   => false,
    'parameters'      => [
        'source' => '',
        'as'     => 'kvp',
    ],
    'processor' => function (string $content, array $parameters) {
        if (!is_array($parameters['source'])) {
            return '';
        }

        $parameters['as'] = trim($parameters['as']);
        if (0 === strlen($parameters['as'])) {
            $parameters['as'] = 'kvp';
        }

        $result = '';
        foreach ($parameters['source'] as $key => $value) {
            $this->assign($parameters['as'], [
                'key'   => $key,
                'value' => $value,
            ]);
            $result .= $this->parseText($content ?? '');
        }

        return $result;
    },
];
