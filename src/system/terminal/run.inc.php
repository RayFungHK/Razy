<?php
/**
 * CLI Command: run
 *
 * Runs the Razy application by simulating a web request from the CLI.
 * Accepts a path in the format "hostname/url_query" and routes it
 * through the application's routing system as if it were an HTTP request.
 *
 * Usage:
 *   php Razy.phar run <hostname/path>
 *
 * Arguments:
 *   hostname/path  The hostname followed by the URL path to route
 *                  (e.g., "localhost/demo/hello")
 *
 * @package Razy
 * @license MIT
 */

namespace Razy;

use Razy\Util\PathUtil;
return function (string $path = '') {
    $this->writeLineLogging('Running the application (' . $path . ')...');

    // Split the path into hostname and URL query components
    [$hostname, $urlQuery] = explode('/', $path . '/', 2);

    // Initialize the application with the given hostname and lock it
    ($app = new Application())->host($hostname);
    Application::Lock();

    // Route the URL query; show 404 if no route matches
    if (!$app->query(PathUtil::tidy('/' . $urlQuery, true, '/'))) {
        Error::show404();
    }

    return true;
};
