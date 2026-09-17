<?php

/*
 * benchgate — single default site for the whole worker (:8080).
 * k6 arrives as Host "bench-gate:8080"; '*' resolves any host (Application's
 * default-site branch), which is exactly what a benchmark harness wants:
 * one site, no Host coupling.
 */

return [
    'domains' => [
        // Application::updateSites() only accepts the array form (path map);
        // the string shortcut the stock template comment advertises is parsed
        // nowhere — a bare string never enters the multisite table.
        '*' => ['/' => 'benchgate'],
    ],
    'alias' => [],
];
