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
use Razy\Http\RequestUrl;
use Razy\Http\TrustedProxies;
use Razy\Util\PathUtil;
use Throwable;

// Remove the X-Powered-By header for security
\header_remove('X-Powered-By');

// Ensure LC_CTYPE is UTF-8 aware so that escapeshellarg() and other C-locale
// dependent functions do not silently strip non-ASCII bytes (e.g. IDN hostnames,
// UTF-8 file paths).  Multiple candidates are tried — the first one recognised
// by the OS wins.  Windows ignores LC_CTYPE for escapeshellarg (uses its own
// double-quote wrapping), so this is mainly a Linux/macOS safeguard.
\setlocale(LC_CTYPE, 'C.UTF-8', 'en_US.UTF-8', 'UTF-8', '');

// Pre-load utility classes that are needed before the autoloader is registered
require_once PHAR_PATH . DIRECTORY_SEPARATOR . 'library' . DIRECTORY_SEPARATOR . 'Razy' . DIRECTORY_SEPARATOR . 'Util' . DIRECTORY_SEPARATOR . 'PathUtil.php';

// Define core framework constants for versioning and directory paths
\define('RAZY_VERSION', '1.2.1');
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

// RZ-08: load `.env` (RAZY_ENV_FILE, else SYSTEM_ROOT/.env; real env wins), then
// evaluate config.inc.php so it can read those values. A pre-RZ-08 index.php has
// already required config.inc.php before the phar loaded; that result is kept
// (never evaluated twice), so only the new index.php sees .env inside config.
// Runs before the mode constants exist, so a bad .env / config fails closed here
// (details to the log and CLI stderr; a web client only gets a generic 500).
try {
    Config\RuntimeConfig::loadEnv(SYSTEM_ROOT);
    $razyConfig = Config\RuntimeConfig::loadConfig(SYSTEM_ROOT, isset($razyConfig) && \is_array($razyConfig) ? $razyConfig : null);
} catch (\Razy\Exception\ConfigurationException $e) {
    \error_log('[Razy] Boot configuration error: ' . $e->getMessage());
    if (\PHP_SAPI === 'cli') {
        \fwrite(\STDERR, 'Razy: ' . $e->getMessage() . \PHP_EOL);
    } else {
        \http_response_code(500);
        echo 'Server configuration error.';
    }

    exit(1);
}

// RZ-04: reverse proxies whose X-Forwarded-* headers are believed.
// RAZY_TRUSTED_PROXIES (env) wins over config.inc.php 'trusted_proxies'.
// Unset = no proxy is trusted (forwarded headers are ignored, except the
// deprecated X-Forwarded-Proto fallback in NetworkUtil::isSsl()).
TrustedProxies::configure(TrustedProxies::fromConfig($razyConfig));

// Detect the runtime mode and define mode-specific constants.
// RZ-23: each mode constant is only defined when the app has not defined it
// already, so an entry script may set it before the phar boots (the supported
// override, e.g. `define('WORKER_MODE', false);` in index.php).
if (\php_sapi_name() === 'cli' || \defined('STDIN')) {
    // CLI mode, define environmental variable
    \defined('CLI_MODE') || \define('CLI_MODE', true);
    \defined('WEB_MODE') || \define('WEB_MODE', false);
    \defined('WORKER_MODE') || \define('WORKER_MODE', false);
} else {
    \defined('CLI_MODE') || \define('CLI_MODE', false);
    \defined('WEB_MODE') || \define('WEB_MODE', true);
    // RZ-23: worker mode only when FrankenPHP runs this script as a worker
    // (it sets FRANKENPHP_WORKER) or CADDY_WORKER_MODE=true opts in.
    // frankenphp_handle_request() alone is not enough: classic FrankenPHP
    // (php_server without a worker directive) has the function too.
    \defined('WORKER_MODE') || \define('WORKER_MODE', RuntimeMode::detectWorker(
        $_SERVER,
        \function_exists('frankenphp_handle_request'),
        \getenv('CADDY_WORKER_MODE'),
        \getenv('FRANKENPHP_WORKER'),
    ));

    // RZ-18: the request's URL facts live in Razy\Http\RequestUrl, which the
    // worker loop rebuilds per request (src/main.php). The constants below are
    // kept for code that reads them, but are DEPRECATED: under a persistent
    // worker they keep the values of the worker's boot. Read
    // RequestUrl::current() (or Controller/Distributor getSiteURL()) instead.
    //
    // RELATIVE_ROOT — URL path from the web server's document root to
    // SYSTEM_ROOT (e.g. "/abc" for https://localhost/abc/ when the project lives
    // in htdocs/abc/): DOCUMENT_ROOT as a strict prefix of SYSTEM_ROOT, else
    // dirname(SCRIPT_NAME). Fixed for the process, so it stays a constant.
    \define('RELATIVE_ROOT', RequestUrl::relativeRootFrom($_SERVER, SYSTEM_ROOT));

    // Session cookie path — scope cookies to subdirectory installs (e.g. /abc).
    \define('RELATIVE_COOKIE_PATH', RELATIVE_ROOT !== '' ? RELATIVE_ROOT : '/');

    $razyRequestUrl = RequestUrl::fromServer($_SERVER, RELATIVE_ROOT);
    RequestUrl::setCurrent($razyRequestUrl);

    // Behind a trusted proxy (RZ-04) host and port are the public ones;
    // 80 and 443 are omitted from SITE_URL_ROOT (RZ-04 port fix).
    \define('HOSTNAME', $razyRequestUrl->host);
    \define('PORT', $razyRequestUrl->port);
    \define('SITE_URL_ROOT', $razyRequestUrl->siteUrlRoot());
    \define('RAZY_URL_ROOT', $razyRequestUrl->razyUrlRoot());
    \define('SCRIPT_URL', $razyRequestUrl->scriptUrl());
    \define('FULL_URL_QUERY', $razyRequestUrl->fullUrlQuery());
    \define('URL_QUERY', $razyRequestUrl->urlQuery());
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
        \mkdir($dest, 0755, true);
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
                                    \mkdir(PathUtil::append($dest, $path), 0755, true);
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
    // COMPILE-ON-DEPLOY M1: the phar bake carries a class => file map
    // (build.php). For phar-library lookups a map hit is ONE array read
    // where the legacy ladder below pays is_dir + up to 3 is_file probes
    // over the phar stream per class (the biggest per-request boot bucket,
    // profiled 2026-09). Correctness is probe-neutral: a miss falls through,
    // and a hit whose include does not define the class ALSO falls through.
    static $classmap = false;
    if (false === $classmap) {
        $mapFile = (\defined('PHAR_PATH') && \strlen(PHAR_PATH) > 0)
            ? \rtrim(PHAR_PATH, '\\/') . '/system/classmap.php'
            : '';
        $classmap = ($mapFile && \is_file($mapFile)) ? (array) require $mapFile : [];
    }
    if ($classmap && isset($classmap[$className]) && \str_ends_with($path, 'library')) {
        $mapped = \rtrim($path, '\\/') . '/' . $classmap[$className];
        if (\is_file($mapped)) {
            include_once $mapped;
            if (\class_exists($className, false)) {
                return true;
            }
            // fall through to the legacy ladder — the map never answers wrong
        }
    }

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
                include_once $libraryPath;

                return \class_exists($className);
            } catch (Exception) {
                return false;
            }
        }
    }

    return false;
}
