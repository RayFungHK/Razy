<?php

/*
 * This file is part of Razy v0.5.
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
 * If a config.inc.php file exists in the same directory, it reads the
 * `phar_location` setting to resolve the path to Razy.phar. Otherwise it
 * falls back to the current directory.
 *
 * @package Razy
 * @license MIT
 */

namespace Razy;

use Exception;
use Phar;

// Default path assumes Razy.phar is in the same directory
$pharPath = './Razy.phar';

// If a local configuration file exists, attempt to read the phar location from it
if (is_file('./config.inc.php')) {
    try {
        // Load the configuration array (expects 'phar_location' key)
        $razyConfig = require './config.inc.php';

        // Resolve the absolute path to Razy.phar using the configured location
        $pharPath   = realpath(($razyConfig['phar_location'] ?? '.') . '/Razy.phar');

        // Abort if the resolved phar file does not exist on disk
        if (!is_file($pharPath)) {
            echo 'No configuration file has found.';

            exit;
        }
    } catch (Exception) {
        // Configuration loading failed; halt with an error message
        echo 'No configuration file has found.';

        exit;
    }
}

// Register the phar archive so its internal files can be accessed via the phar:// stream wrapper
Phar::loadPhar($pharPath, 'Razy.phar');

// Hand off to the framework's main bootstrap script inside the phar
include 'phar://Razy.phar/main.php';
