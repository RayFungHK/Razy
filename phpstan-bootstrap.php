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
define('PHAR_FILE', __DIR__ . '/razy.phar');
// CLI_MODE and WEB_MODE are determined at runtime.
// Listed in phpstan.neon dynamicConstantNames so PHPStan treats them as bool, not literal.
define('CLI_MODE', false);
define('WEB_MODE', true);

// Defined in src/system/bootstrap.inc.php
define('SITES_FOLDER', __DIR__ . '/sites');
define('SHARED_FOLDER', __DIR__ . '/shared');
define('DATA_FOLDER', __DIR__ . '/data');
define('HOSTNAME', 'localhost');
define('SITE_URL_ROOT', '/');
