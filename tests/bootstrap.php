<?php

/**
 * PHPUnit bootstrap file for Razy framework tests.
 *
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

// Define constants
define('SYSTEM_ROOT', dirname(__DIR__));
define('PHPUNIT_RUNNING', true);

// Define mode constants needed by Error class and other components
if (!defined('CLI_MODE')) {
    define('CLI_MODE', true);
}
if (!defined('WEB_MODE')) {
    define('WEB_MODE', false);
}
if (!defined('WORKER_MODE')) {
    define('WORKER_MODE', false);
}

// Load test helper functions (provides Razy\tidy, Razy\append, Razy\guid, etc.)
require_once __DIR__ . '/test_functions.php';

// Autoload Razy classes
spl_autoload_register(function ($class) {
    $prefix = 'Razy\\';
    $baseDir = SYSTEM_ROOT . '/src/library/Razy/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Load Composer autoloader if exists
$autoloadFile = SYSTEM_ROOT . '/vendor/autoload.php';
if (file_exists($autoloadFile)) {
    require $autoloadFile;
}
