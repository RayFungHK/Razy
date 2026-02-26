<?php

/**
 * Razy Framework Bootstrap.
 *
 * Core bootstrap file that initializes the Razy framework environment.
 * This file is loaded at startup and is responsible for:
 * - Defining framework-wide constants (version, paths, URL routing)
 * - Registering the SPL autoloader for class resolution
 * - Setting up the global exception handler
 * - Detecting runtime mode (CLI, Web, or Caddy Worker)
 * - Registering default plugin folders for Collection, Template, Statement, and Pipeline
 * - Declaring globally available utility functions (path manipulation, version comparison, etc.)
 *
 * @license MIT
 */

namespace Razy;

use Exception;
use Razy\Database\Statement;
use Razy\Util\NetworkUtil;
use Razy\Util\PathUtil;
use Throwable;

// Remove the X-Powered-By header for security
\header_remove('X-Powered-By');

// Pre-load utility classes that are needed before the autoloader is registered
require_once PHAR_PATH . DIRECTORY_SEPARATOR . 'library' . DIRECTORY_SEPARATOR . 'Razy' . DIRECTORY_SEPARATOR . 'Util' . DIRECTORY_SEPARATOR . 'PathUtil.php';

// Define core framework constants for versioning and directory paths
\define('RAZY_VERSION', '1.0-beta');
\define('PLUGIN_FOLDER', PathUtil::append(SYSTEM_ROOT, 'plugins'));
\define('PHAR_PLUGIN_FOLDER', PathUtil::append(PHAR_PATH, 'plugins'));
\define('SITES_FOLDER', PathUtil::append(SYSTEM_ROOT, 'sites'));
\define('DATA_FOLDER', PathUtil::append(SYSTEM_ROOT, 'data'));
\define('SHARED_FOLDER', PathUtil::append(SYSTEM_ROOT, 'shared'));
\define('CACHE_FOLDER', PathUtil::append(DATA_FOLDER, 'cache'));

// Register a global exception handler to display errors via Razy's Error class
\set_exception_handler(function (Throwable $exception) {
    try {
        // Use Razy's Error exception to replace the PHP built in Error exception
        Error::showException($exception);
    } catch (Throwable $e) {
        echo $e;
        // Display error
    }
});

// Register the SPL autoloader
\spl_autoload_register(function ($className) {
    if (!autoload($className, PathUtil::append(SYSTEM_ROOT, 'library'))) {
        // Load the library in phar file
        if (!autoload($className, PathUtil::append(PHAR_PATH, 'library'))) {
            // Try load the library in distributor
            return false;
        }
    }

    return true;
});

// Detect the runtime mode and define mode-specific constants
if (\php_sapi_name() === 'cli' || \defined('STDIN')) {
    // CLI mode, define environmental variable
    \define('CLI_MODE', true);
    \define('WEB_MODE', false);
    \define('WORKER_MODE', false);
} else {
    \define('CLI_MODE', false);
    \define('WEB_MODE', true);
    // Detect Caddy/FrankenPHP worker mode
    \define('WORKER_MODE', \function_exists('frankenphp_handle_request') || \getenv('CADDY_WORKER_MODE') === 'true');

    // Declare `HOSTNAME`
    // The hostname, if the REQUEST PATH is http://yoursite.com:8080/Razy, the HOSTNAME will declare as yoursite.com
    \define('HOSTNAME', $_SERVER['SERVER_NAME'] ?? 'UNKNOWN');

    // Declare `RELATIVE_ROOT`
    \define('RELATIVE_ROOT', \preg_replace('/\\\+/', '/', \substr(SYSTEM_ROOT, \strpos(SYSTEM_ROOT, $_SERVER['DOCUMENT_ROOT']) + \strlen($_SERVER['DOCUMENT_ROOT']))));

    // Declare `PORT`
    // The protocol, if the REQUEST PATH is http://yoursite.com:8080/Razy, the PORT will declare as 8080
    \define('PORT', (int) $_SERVER['SERVER_PORT']);

    // Declare `SITE_URL_ROOT`
    $protocol = (NetworkUtil::isSsl()) ? 'https' : 'http';
    \define('SITE_URL_ROOT', $protocol . '://' . HOSTNAME . ((PORT != '80') ? ':' . PORT : ''));

    // Declare `RAZY_URL_ROOT`
    \define('RAZY_URL_ROOT', PathUtil::append(SITE_URL_ROOT, RELATIVE_ROOT));

    // Declare `RAZY_URL_ROOT`
    \define('SCRIPT_URL', PathUtil::append(SITE_URL_ROOT, \strtok($_SERVER['REQUEST_URI'], '?')));

    // Declare `URL_QUERY` & `FULL_URL_QUERY`
    if (RELATIVE_ROOT) {
        \preg_match('/^' . \preg_quote(RELATIVE_ROOT, '/') . '(.+)$/', $_SERVER['REQUEST_URI'], $matches);
        $urlQuery = $matches[1] ?? '';
    } else {
        $urlQuery = $_SERVER['REQUEST_URI'];
    }

    \define('FULL_URL_QUERY', $urlQuery);
    \define('URL_QUERY', PathUtil::tidy(\strtok($urlQuery, '?'), true, '/'));
}

// Register default plugin folders for each component from both local and phar sources
Collection::addPluginFolder(PathUtil::append(PLUGIN_FOLDER, 'Collection'));
Collection::addPluginFolder(PathUtil::append(PHAR_PLUGIN_FOLDER, 'Collection'));

Template::addPluginFolder(PathUtil::append(PLUGIN_FOLDER, 'Template'));
Template::addPluginFolder(PathUtil::append(PHAR_PLUGIN_FOLDER, 'Template'));

Statement::addPluginFolder(PathUtil::append(PLUGIN_FOLDER, 'Statement'));
Statement::addPluginFolder(PathUtil::append(PHAR_PLUGIN_FOLDER, 'Statement'));

Pipeline::addPluginFolder(PathUtil::append(PLUGIN_FOLDER, 'Pipeline'));
Pipeline::addPluginFolder(PathUtil::append(PHAR_PLUGIN_FOLDER, 'Pipeline'));

// Initialize the cache system with the file-based adapter
if (!\defined('PHPUNIT_RUNNING')) {
    Cache::initialize(CACHE_FOLDER);
}

/**
 * Start from here, all globally function will be defined.
 */

/**
 * Remove the directory or file recursively.
 *
 * @param string $path
 *
 * @return bool
 */
function xremove(string $path): bool
{
    $path = PathUtil::tidy($path);
    $basePath = $path;

    try {
        $recursive = function (string $path = '') use (&$recursive, $basePath) {
            $path = PathUtil::append($basePath, $path);
            if (\is_dir($path)) {
                foreach (\scandir($path) as $item) {
                    if (!\preg_match('/^\.\.?$/', $item)) {
                        if (\is_dir(PathUtil::append($path, $item))) {
                            $recursive(PathUtil::append($path, $item));
                        } else {
                            \unlink(PathUtil::append($path, $item));
                        }
                    }
                }
                \rmdir($path);
            } else {
                \unlink($path);
            }
        };
        $recursive('');
    } catch (Exception) {
        return false;
    }

    return true;
}

/**
 * Copy the directory and file recursively.
 *
 * @param string $source
 * @param string $dest
 * @param string $pattern
 * @param null|array $unpacked
 *
 * @return bool
 */
function xcopy(string $source, string $dest, string $pattern = '', ?array &$unpacked = []): bool
{
    $source = PathUtil::tidy($source);
    $dest = PathUtil::tidy($dest);
    if (!\is_file($source) && !\is_dir($source)) {
        return false;
    }

    $fileName = '';
    if (\is_file($source)) {
        if (!\str_ends_with($dest, DIRECTORY_SEPARATOR)) {
            $fileName = \substr($dest, \strrpos($dest, DIRECTORY_SEPARATOR) + 1);
            $dest = \substr($dest, 0, \strrpos($dest, DIRECTORY_SEPARATOR));
        }
    }

    if (!\is_dir($dest)) {
        \mkdir($dest, 0777, true);
    }

    if (!$unpacked) {
        $unpacked = [];
    }

    try {
        $basePath = $source;
        $recursive = function (string $path = '') use (&$recursive, $basePath, $dest, &$unpacked, $pattern, $fileName) {
            $source = PathUtil::append($basePath, $path);
            if (\is_dir($source)) {
                foreach (\scandir($source) as $item) {
                    if (!\preg_match('/^\.\.?$/', $item)) {
                        if (\is_dir(PathUtil::append($source, $item))) {
                            $recursive(PathUtil::append($path, $item));
                        } else {
                            if (!$pattern || \preg_match('/^' . \preg_quote($pattern) . '$/', PathUtil::append($dest, $path))) {
                                if (!\is_dir(PathUtil::append($dest, $path))) {
                                    \mkdir(PathUtil::append($dest, $path), 0777, true);
                                }

                                $unpacked[PathUtil::append($source, $item)] = PathUtil::append($dest, $path, $item);
                                \copy(PathUtil::append($source, $item), PathUtil::append($dest, $path, $item));
                            }
                        }
                    }
                }
            } else {
                $unpacked[$source] = PathUtil::append($dest, $fileName ?? \basename($source));
                \copy($source, PathUtil::append($dest, $fileName ?? \basename($source)));
            }
        };
        $recursive('');
    } catch (Exception) {
        return false;
    }

    return true;
}

/**
 * Retrieve an environment variable with an optional default value.
 *
 * This is a convenience wrapper around {@see Env::get()}. It supports
 * automatic casting of `"true"`, `"false"`, `"null"`, and `"(empty)"`
 * string literals to their PHP equivalents.
 *
 * @param string $key The environment variable name.
 * @param mixed $default Default value if the variable is not defined.
 *
 * @return mixed
 */
function env(string $key, mixed $default = null): mixed
{
    return Env::get($key, $default);
}

/**
 * Autoloader.
 *
 * @param string $className
 * @param string $path
 *
 * @return bool
 */
function autoload(string $className, string $path = ''): bool
{
    if (\is_dir($path)) {
        $libraryPath = PathUtil::append($path, $className . '.php');

        if (!\is_file($libraryPath)) {
            $splits = \explode('\\', $className);
            $libraryPath = PathUtil::append($path, $className, \end($splits) . '.php');
            if (!\is_file($libraryPath)) {
                // Psr-0
                if (\str_contains($className, '_')) {
                    $splits = \explode('_', $className);
                    $classFolder = PathUtil::append($path, \reset($splits));
                    if (\is_dir($classFolder)) {
                        $libraryPath = PathUtil::append($classFolder, \implode(DIRECTORY_SEPARATOR, $splits) . '.php');
                    }
                }
            }
        }

        if (\is_file($libraryPath)) {
            try {
                include $libraryPath;

                return \class_exists($className);
            } catch (Exception) {
                return false;
            }
        }
    }

    return false;
}
