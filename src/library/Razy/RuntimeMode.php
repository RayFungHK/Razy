<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy;

/**
 * RZ-15: whether the current request is a web request.
 *
 * The `WEB_MODE` / `CLI_MODE` constants describe the process. The in-process
 * test client (`Razy\Testing\Client`) runs web requests inside a CLI process
 * (PHPUnit), so the framework's request path asks this class instead: it
 * answers from the constants unless a simulated web request is in progress.
 *
 * @license MIT
 */
final class RuntimeMode
{
    private static bool $simulatedWeb = false;

    /**
     * Whether the current request is an HTTP request.
     */
    public static function isWeb(): bool
    {
        return self::$simulatedWeb || (\defined('WEB_MODE') && WEB_MODE);
    }

    /**
     * Whether the current run is a CLI command (false during a simulated web request).
     */
    public static function isCli(): bool
    {
        return !self::$simulatedWeb && \defined('CLI_MODE') && CLI_MODE;
    }

    /**
     * Whether a simulated web request (test client) is in progress.
     */
    public static function isSimulatedWeb(): bool
    {
        return self::$simulatedWeb;
    }

    /**
     * RZ-23: whether this web process is a persistent FrankenPHP worker.
     *
     * True when `CADDY_WORKER_MODE` is `'true'` (operator opt-in), or when
     * `frankenphp_handle_request()` exists and FrankenPHP marked the script as a
     * worker: it sets `FRANKENPHP_WORKER=1` in the worker's environment, which
     * PHP sees in `$_SERVER` (php/frankenphp worker.go, `newWorker()`:
     * `o.env["FRANKENPHP_WORKER\x00"] = "1"`, v1.13.3). Classic FrankenPHP
     * (`php_server` without a `worker` directive) also defines
     * `frankenphp_handle_request()` but sets no marker, so it is not a worker.
     *
     * bootstrap.inc.php defines `WORKER_MODE` from this unless the app defined
     * it first.
     *
     * @param array<string, mixed> $server $_SERVER of the boot
     * @param bool $hasHandleRequest function_exists('frankenphp_handle_request')
     * @param string|false $caddyWorkerMode getenv('CADDY_WORKER_MODE')
     * @param string|false $frankenphpWorkerEnv getenv('FRANKENPHP_WORKER'), used when $_SERVER lacks it
     */
    public static function detectWorker(array $server, bool $hasHandleRequest, string|false $caddyWorkerMode = false, string|false $frankenphpWorkerEnv = false): bool
    {
        if ($caddyWorkerMode === 'true') {
            return true;
        }
        if (!$hasHandleRequest) {
            return false;
        }
        $marker = $server['FRANKENPHP_WORKER'] ?? $frankenphpWorkerEnv;

        // FrankenPHP sets exactly "1"; any other value is not its marker.
        return $marker === '1' || $marker === 1;
    }

    /**
     * Start or end a simulated web request. Only the test client should call this.
     *
     * @internal
     */
    public static function simulateWeb(bool $on): void
    {
        self::$simulatedWeb = $on;
    }
}
