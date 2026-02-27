<?php
/**
 * Razy Framework — Main Entry Point.
 *
 * This is the primary bootstrap file for the Razy framework. It handles both
 * web (HTTP) and CLI execution modes. In web mode, it initialises the Application
 * object and routes the incoming request. In CLI mode, it parses command-line
 * arguments and dispatches to the appropriate terminal command script.
 *
 * Supports FrankenPHP / Caddy worker mode for persistent-process request handling.
 *
 *
 * @version 0.5
 *
 * @author  Ray Fung <hello@rayfung.hk>
 * @license MIT
 *
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy;

use Phar;
use Razy\Exception\HttpException;
use Razy\Template\CompiledTemplate;
use Razy\Util\PathUtil;
use Throwable;

use const DIRECTORY_SEPARATOR;

// --- Resolve SYSTEM_ROOT: the filesystem root of the Razy installation ---
if (!\defined('SYSTEM_ROOT')) {
    // Try to use current working directory first (when phar is called from a different location)
    // Fall back to phar directory if current directory is not valid
    $pharPath = Phar::running(false); // Absolute path to the .phar archive (empty string if not running as phar)
    $currentDir = \getcwd();           // Current working directory at invocation time

    // Check if we're running from a different directory than where the phar is located
    // and if the current directory has a 'sites' subdirectory (characteristic of a Razy installation)
    // Also accept 'standalone' subdirectory for standalone/lite mode installations
    if ($pharPath && \dirname($pharPath) !== $currentDir && (
        \is_dir($currentDir . DIRECTORY_SEPARATOR . 'sites')
        || \is_dir($currentDir . DIRECTORY_SEPARATOR . 'standalone')
    )) {
        // Current working directory looks like a valid Razy installation
        \define('SYSTEM_ROOT', $currentDir);
    } else {
        // Fall back to the directory containing the phar archive
        \define('SYSTEM_ROOT', \dirname($pharPath));
    }
}

// PHAR_FILE: the filename of the running phar (e.g. "razy.phar")
\define('PHAR_FILE', \basename(Phar::running(false)));
// PHAR_PATH: the phar:// stream-wrapper URI used to include files from within the archive
\define('PHAR_PATH', Phar::running());

// Abort early if the resolved SYSTEM_ROOT does not point to a valid directory
if (!\is_dir(SYSTEM_ROOT)) {
    echo 'Invalid application setup directory (SYSTEM_ROOT).';

    return false;
}

// CORE_FOLDER: absolute phar:// path to the framework's core system directory
\define('CORE_FOLDER', PHAR_PATH . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);

// Pull in the core bootstrap which registers the autoloader, reads config, and sets
// WEB_MODE / WORKER_MODE / HOSTNAME / PORT / URL_QUERY constants, among others.
require CORE_FOLDER . 'bootstrap.inc.php';

// ========================
// WEB MODE — HTTP request handling
// ========================
if (WEB_MODE) {
    // Enable or disable verbose error output based on the site configuration
    Error::configure($razyConfig);

    // Apply the configured timezone and compute the UTC offset string (e.g. "+8:00")
    if (isset($razyConfig['timezone'], $razyConfig['timezone']) && $razyConfig['timezone']) {
        try {
            \date_default_timezone_set($razyConfig['timezone']);
        } catch (\Exception $e) {
            // Silently ignore invalid timezone values
        }

        // Calculate the current UTC offset in seconds, then convert to ±H:MM format
        $utcOffset = \date('Z');                           // offset in seconds
        $minutes = \abs($utcOffset % 60);                  // remaining minutes
        $hours = \floor($utcOffset / 3600);                // whole hours
        $offset = \sprintf('%+d:%02d', $hours, $minutes);  // e.g. "+8:00"
        \define('TIMEZONE', \date_default_timezone_get());
        \define('TIMEZONE_OFFSET', $offset);
    }

    // --- Standalone Mode Detection ---
    // Standalone is the DEFAULT mode: activates when standalone/ folder exists
    // AND multisite has NOT been explicitly enabled via config or environment.
    // To switch to multisite, set 'multiple_site' => true in config.inc.php
    // or set the RAZY_MULTIPLE_SITE=true environment variable.
    $standalonePath = PathUtil::append(SYSTEM_ROOT, 'standalone');
    $isMultisiteEnabled = !empty($razyConfig['multiple_site']) || \getenv('RAZY_MULTIPLE_SITE') === 'true';
    $isStandaloneMode = \is_dir($standalonePath) && !$isMultisiteEnabled;
    \define('STANDALONE_MODE', $isStandaloneMode);

    // --- Caddy / FrankenPHP Worker Mode ---
    // In worker mode the PHP process stays alive across requests, so we wrap
    // each request in a closure passed to frankenphp_handle_request().
    if (WORKER_MODE && \function_exists('frankenphp_handle_request')) {
        // Cache RELATIVE_ROOT for per-request URL query computation
        $relativeRoot = \defined('RELATIVE_ROOT') ? RELATIVE_ROOT : '';

        // ===============================================================
        // BOOT PHASE — runs once per worker process, NOT per request.
        // Build the Application/Module/Route object graph so that incoming
        // requests only pay the cost of URL parsing + route dispatch.
        // ===============================================================
        $app = new Application();
        $firstRequestHandled = false;

        if ($isStandaloneMode) {
            $app->standalone($standalonePath);
        } else {
            $app->host(HOSTNAME . ':' . PORT);
        }

        Application::Lock();

        // Build a request handler closure that will be invoked for every incoming HTTP request
        $handler = function () use ($razyConfig, $isStandaloneMode, $relativeRoot, $app, &$firstRequestHandled) {
            try {
                // --- Per-request state reset (prevent cross-request leaks) ---
                \http_response_code(200);
                \header_remove();           // Clear headers from previous request
                // Note: Error::reset() is only called in the finally block below
                // to avoid redundant double-reset per request.

                // Drain any leftover output buffers from a previous request
                // that threw before flushing (e.g. ob_start in XHR/SSE).
                while (\ob_get_level() > 0) {
                    \ob_end_clean();
                }

                // Recompute URL query per-request: constants like URL_QUERY are stale
                // in worker mode because they were defined once at script startup before
                // any real HTTP request arrived. $_SERVER is refreshed per-request by FrankenPHP.
                if ($relativeRoot) {
                    \preg_match('/^' . \preg_quote($relativeRoot, '/') . '(.+)$/', $_SERVER['REQUEST_URI'] ?? '', $urlMatches);
                    $rawUrlQuery = $urlMatches[1] ?? '';
                } else {
                    $rawUrlQuery = $_SERVER['REQUEST_URI'] ?? '/';
                }
                $urlQuery = PathUtil::tidy(\strtok($rawUrlQuery, '?'), true, '/');
                if (!$urlQuery) {
                    $urlQuery = '/';
                }

                if (!$firstRequestHandled) {
                    // ── First request: full module lifecycle ──────────────
                    // Run the complete init chain (Module::initialize → __onInit
                    // → prepare → validate → matchRoute) so that routes, middleware,
                    // and lifecycle hooks are all wired up.
                    if ($isStandaloneMode) {
                        if (!$app->queryStandalone($urlQuery)) {
                            Error::show404();
                        }
                    } else {
                        if (!$app->query($urlQuery)) {
                            Error::show404();
                        }
                    }
                    $firstRequestHandled = true;
                } else {
                    // ── Subsequent requests: lightweight fast dispatch ────
                    // The object graph (Application → Standalone → Module →
                    // RouteDispatcher) persists from the boot phase. No module
                    // re-init, no route re-registration, no session_start(),
                    // no gc_collect_cycles() — just URL match and execute.
                    //
                    // Multisite: Domain::dispatchQuery() caches Distributors
                    // keyed by distCode@tag, with periodic config fingerprint
                    // checks for hot-reload when dist.php or modules change.
                    if ($isStandaloneMode) {
                        if (!$app->dispatchStandalone($urlQuery)) {
                            Error::show404();
                        }
                    } else {
                        if (!$app->dispatch($urlQuery)) {
                            Error::show404();
                        }
                    }
                }
            } catch (HttpException $e) {
                // HTTP-level exceptions (404, redirect, XHR response sent) —
                // response was already sent, request ends gracefully.
            } catch (Throwable $e) {
                // Attempt to render a friendly error page; fall back to raw output
                try {
                    Error::showException($e);
                } catch (Throwable $e) {
                    echo $e;
                }
            } finally {
                // --- Per-request cleanup ---
                // Reset error state and close any session that user code may
                // have opened (prevents session file-lock deadlocks).
                Error::reset();
                if (\session_status() === PHP_SESSION_ACTIVE) {
                    \session_write_close();
                }
            }
        };

        // Hand the closure to FrankenPHP which will call it for each HTTP request.
        // Loop until the worker is told to stop (frankenphp_handle_request returns false).
        // Each iteration blocks until a new request arrives, calls $handler with the
        // superglobals populated, and then returns true to keep the loop alive.
        $maxRequests = (int) (\getenv('WORKER_MAX_REQUESTS') ?: 0);  // 0 = unlimited
        $requestCount = 0;
        $gcInterval = (int) (\getenv('WORKER_GC_INTERVAL') ?: 500); // GC every N requests
        do {
            $running = \frankenphp_handle_request($handler);
            ++$requestCount;
            // Periodic GC instead of per-request — avoids the ~5% overhead of
            // gc_collect_cycles() on every single request.
            if ($gcInterval > 0 && $requestCount % $gcInterval === 0) {
                \gc_collect_cycles();
                CompiledTemplate::clearCache(); // Prevent unbounded template cache growth
            }
        } while ($running && ($maxRequests <= 0 || $requestCount < $maxRequests));
    } else {
        // --- Standard (non-worker) request handling ---
        try {
            $app = new Application();

            if ($isStandaloneMode) {
                // Standalone mode: bypass Domain/multisite, load single module
                $app->standalone($standalonePath);
            } else {
                // Normal multisite mode: resolve domain and match distributor
                $app->host(HOSTNAME . ':' . PORT);
            }

            Application::Lock();

            // Schedule post-request validation on shutdown
            \register_shutdown_function(function () use ($app) {
                $app->validation();
            });

            // Attempt to route the request; show 404 if no matching route is found
            if ($isStandaloneMode) {
                if (!$app->queryStandalone(URL_QUERY)) {
                    Error::show404();
                }
            } else {
                if (!$app->query(URL_QUERY)) {
                    Error::show404();
                }
            }

            // Clean up application resources
            $app->dispose();
        } catch (HttpException $e) {
            // HTTP-level exceptions (404, redirect, XHR response sent) —
            // response was already sent, request ends gracefully.
        } catch (Throwable $e) {
            // Render exception page; if that also fails, dump it as plain text
            try {
                Error::showException($e);
            } catch (Throwable $e) {
                echo $e;
            }
        }
    }
} else {
    // ========================
    // CLI MODE — Terminal command dispatch
    // ========================
    $argv = $_SERVER['argv']; // Raw argument list from the command line
    \array_shift($argv);       // Remove the script name (first element)

    if (!empty($argv)) {
        // The first remaining argument is the command name (e.g. "build", "serve")
        $command = \array_shift($argv);

        $parameters = [];  // Parsed named parameters (flags and key-value pairs)
        // -f <path>: override the default framework system path
        $systemPath = './';
        foreach ($argv as $index => $arg) {
            // Handle the "-f" flag which specifies an alternative Razy system directory
            if ('-f' == $arg) {
                $systemPath = $argv[$index + 1] ?? '';
                if (!$systemPath || !\is_dir($systemPath)) {
                    echo Terminal::COLOR_RED . '[Error] The location is not a valid directory.' . Terminal::COLOR_DEFAULT . PHP_EOL;

                    return false;
                }
                // Remove -f and its associated value so they are not passed to the command
                unset($argv[$index], $argv[$index + 1]);
            } elseif ('-' == $arg[0]) {
                // Any argument starting with "-" is treated as a named parameter
                $name = \substr($arg, 1); // Strip the leading dash

                // Determine parameter value: -p and -debug consume the next arg;
                // all other flags are simple booleans.
                $value = match ($name) {
                    'p', 'debug' => $argv[$index + 1] ?? '',
                    default => true,
                };

                // -p is accumulative (may appear multiple times), store as an array
                if ('p' === $name) {
                    if (!isset($parameters[$name]) || !\is_array($parameters[$name])) {
                        $parameters[$name] = [];
                    }
                    $parameters[$name][] = $value;
                } else {
                    $parameters[$name] = $value;
                }
            }
        }

        // Resolve the system path to an absolute path for consistent file operations
        \define('RAZY_PATH', \realpath($systemPath));

        // Build the path to the command's handler script inside the phar
        // e.g. phar://razy.phar/system/terminal/build.inc.php
        $closureFilePath = PathUtil::append(PHAR_PATH, 'system/terminal/', $command . '.inc.php');
        if (\is_file($closureFilePath)) {
            try {
                // Each command script returns a Closure; include it and execute via Terminal
                $closure = include $closureFilePath;
                (new Terminal($command))->run($closure, $argv, $parameters);
            } catch (Throwable $e) {
                // Print the error in red and exit gracefully
                echo PHP_EOL . Terminal::COLOR_RED . $e->getMessage() . Terminal::COLOR_DEFAULT . PHP_EOL;
            }

            return true;
        }

        // Unknown command — notify the user
        echo Terminal::COLOR_RED . '[Error] Command ' . $command . ' is not available.' . Terminal::COLOR_DEFAULT . PHP_EOL;
    }
}
// __HALT_COMPILER() marks the end of PHP code and the start of the phar stub data.
// Everything after this line is binary archive content managed by the Phar extension.
__HALT_COMPILER();
