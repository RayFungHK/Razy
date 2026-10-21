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
     * Start or end a simulated web request. Only the test client should call this.
     *
     * @internal
     */
    public static function simulateWeb(bool $on): void
    {
        self::$simulatedWeb = $on;
    }
}
