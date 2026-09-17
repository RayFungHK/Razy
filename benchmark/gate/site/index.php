<?php

/**
 * Worker front controller — mirrors benchmark/razy (chdir pins SYSTEM_ROOT to
 * the site; config; phar boot). The gate site is a FULL dist site, so the
 * Distributor (and its readiness probe) is what actually serves traffic.
 */

namespace Razy;

use Exception;
use Phar;

chdir(__DIR__);
$razyConfig = require __DIR__ . '/config.inc.php';
$pharPath = ($razyConfig['phar_location'] ?? __DIR__) . '/Razy.phar';
Phar::loadPhar($pharPath, 'Razy.phar');
include 'phar://Razy.phar/main.php';
