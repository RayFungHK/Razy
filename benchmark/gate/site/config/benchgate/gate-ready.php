<?php

/**
 * bench/gate-ready — module config (dist benchgate).
 *
 * The declared database is what makes "has migrations" legal at all:
 * ModuleDatabaseConnector has NO fallback link by design — declaring a
 * migration without declaring a database is a loud error, not a silent one.
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
