<?php

/*
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

/**
 * Razy Framework - Web Entry Point (Setup Asset)
 *
 * This is the default web entry point bootstrapper distributed with the Razy
 * setup assets. It locates and loads the Razy.phar archive, then delegates
 * execution to the framework's main entry script.
 *
 * Razy.phar is found via the RAZY_PHAR environment variable, else next to this
 * file. config.inc.php is NOT read here (RZ-08): the framework loads `.env` and
 * then evaluates config.inc.php, so config can use env values. Only when the phar
 * is elsewhere does this file fall back to reading `phar_location` from
 * config.inc.php (the pre-RZ-08 behaviour; .env values are then not visible to
 * config.inc.php, though they still are to the application).
 *
 * @package Razy
 * @license MIT
 */

namespace Razy;

use Phar;
use Throwable;

$pharPath = \getenv('RAZY_PHAR') ?: __DIR__ . '/Razy.phar';

if (!\is_file($pharPath) && \is_file(__DIR__ . '/config.inc.php')) {
    try {
        $razyConfig = require __DIR__ . '/config.inc.php';
        $pharPath = (string) \realpath(($razyConfig['phar_location'] ?? __DIR__) . '/Razy.phar');
    } catch (Throwable) {
        echo 'Failed to load config.inc.php';

        exit;
    }
}

if (!\is_file($pharPath)) {
    echo 'Razy.phar not found. Set RAZY_PHAR or place Razy.phar next to index.php.';

    exit;
}

// Register the phar archive so its internal files can be accessed via the phar:// stream wrapper
Phar::loadPhar($pharPath, 'Razy.phar');

// Hand off to the framework's main bootstrap script inside the phar
include 'phar://Razy.phar/main.php';
