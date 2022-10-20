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
    'processor' => function ($value, ?string $replacement = '-') {
        return trim(preg_replace('/[^a-z0-9_]+/i', $replacement ?: '-', trim($value)), '-');
    },
];
