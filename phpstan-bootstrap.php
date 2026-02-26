<?php

/**
 * PHPStan bootstrap file — stubs runtime constants defined in main.php / bootstrap.inc.php.
 *
 * These constants are always available when the framework runs, but PHPStan
 * cannot see them because they are defined conditionally at runtime.
 */

// Defined in src/main.php
define('SYSTEM_ROOT', __DIR__);
define('PHAR_PATH', __DIR__ . '/src');

// Defined in src/system/bootstrap.inc.php
define('SITES_FOLDER', __DIR__ . '/sites');
