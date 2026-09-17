<?php

/**
 * bench/gate-refused — same declared database as gate-ready; its migration is
 * staged but never applied (entry copies it in AFTER migrate), so the gate
 * answers its honest 503 on every request.
 */

return [
    'database' => [
        'type' => 'mysql',
        'connection' => [
            'host' => 'bench-mysql',
            'port' => 3306,
            'database' => 'benchmark',
            'username' => 'benchmark',
            'password' => 'benchmark',
            'charset' => 'utf8mb4',
        ],
    ],
];
