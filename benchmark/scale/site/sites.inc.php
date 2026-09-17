<?php

/*
 * scale site — default-site '*' mapping (gate pattern): the k6 harness
 * arrives with the compose service Host; '*' resolves it, one site, no
 * Host coupling.
 */

return [
    'domains' => [
        '*' => ['/' => 'scaletest'],
    ],
    'alias' => [],
];
