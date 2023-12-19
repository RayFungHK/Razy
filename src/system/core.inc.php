<?php

/*
 * This file is part of Razy v0.4.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy;

use Throwable;
use function strlen;

header_remove('X-Powered-By');

define('RAZY_VERSION', '0.4.3-216');
define('PLUGIN_FOLDER', append(SYSTEM_ROOT, 'plugins'));
define('PHAR_PLUGIN_FOLDER', append(PHAR_PATH, 'plugins'));
define('SITES_FOLDER', append(SYSTEM_ROOT, 'sites'));
define('DATA_FOLDER', append(SYSTEM_ROOT, 'data'));
define('SHARED_FOLDER', append(SYSTEM_ROOT, 'shared'));

set_exception_handler(function (Throwable $exception) {
    try {
        Error::ShowException($exception);
    } catch (Throwable $e) {
        echo $e;
        // Display error
    }
});

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
});

// Register Autoloader
spl_autoload_register(function ($className) {
    if (!autoload($className, append(SYSTEM_ROOT, 'library'))) {
        // Load the library in phar file
        if (!autoload($className, append(PHAR_PATH, 'library'))) {
            // Try load the library in distributor
            return Distributor::SPLAutoload($className);
        }
    }

    return true;
});

if (php_sapi_name() === 'cli' || defined('STDIN')) {
    define('CLI_MODE', true);
    define('WEB_MODE', false);
} else {
    define('CLI_MODE', false);
    define('WEB_MODE', true);

    // Declare `HOSTNAME`
    // The hostname, if the REQUEST PATH is http://yoursite.com:8080/Razy, the HOSTNAME will declare as yoursite.com
    define('HOSTNAME', $_SERVER['SERVER_NAME'] ?? 'UNKNOWN');

    // Declare `RELATIVE_ROOT`
    define('RELATIVE_ROOT', preg_replace('/\\\\+/', '/', substr(SYSTEM_ROOT, strpos(SYSTEM_ROOT, $_SERVER['DOCUMENT_ROOT']) + strlen($_SERVER['DOCUMENT_ROOT']))));

    // Declare `PORT`
    // The protocol, if the REQUEST PATH is http://yoursite.com:8080/Razy, the PORT will declare as 8080
    define('PORT', (int) $_SERVER['SERVER_PORT']);

    // Declare `SITE_URL_ROOT`
    $protocol = (is_ssl()) ? 'https' : 'http';
    define('SITE_URL_ROOT', $protocol . '://' . HOSTNAME . ((PORT != '80') ? ':' . PORT : ''));

    // Declare `RAZY_URL_ROOT`
    define('RAZY_URL_ROOT', append(SITE_URL_ROOT, RELATIVE_ROOT));

    // Declare `RAZY_URL_ROOT`
    define('SCRIPT_URL', append(SITE_URL_ROOT, strtok($_SERVER['REQUEST_URI'], '?')));

    // Declare `URL_QUERY` & `FULL_URL_QUERY`
    if (RELATIVE_ROOT) {
        preg_match('/^' . preg_quote(RELATIVE_ROOT, '/') . '(.+)$/', $_SERVER['REQUEST_URI'], $matches);
        $urlQuery = $matches[1] ?? '';
    } else {
        $urlQuery = $_SERVER['REQUEST_URI'];
    }

    define('FULL_URL_QUERY', $urlQuery);
    define('URL_QUERY', tidy(strtok($urlQuery, '?'), true, '/'));
}

// Set up the plugin paths
Collection::addPluginFolder(append(PLUGIN_FOLDER, 'Collection'));
Collection::addPluginFolder(append(PHAR_PLUGIN_FOLDER, 'Collection'));

Template::addPluginFolder(append(PLUGIN_FOLDER, 'Template'));
Template::addPluginFolder(append(PHAR_PLUGIN_FOLDER, 'Template'));
