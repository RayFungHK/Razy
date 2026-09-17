<?php

/**
 * Scale-site config — multisite ON (main.php: 'multiple_site' drives the
 * switch; no standalone/ folder exists here anyway). Same phar_location and
 * debug posture as the fpm baseline site.
 */
return [
    'debug' => false,
    'phar_location' => '/app',
    'timezone' => 'UTC',
    'multiple_site' => true,
];
