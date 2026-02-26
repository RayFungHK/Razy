<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Error;

/**
 * Manages static configuration state for the Error system.
 *
 * Extracted from the Error class to separate configuration/state management
 * from exception semantics. Handles debug mode, cached output, and debug console.
 *
 *
 * @license MIT
 */
class ErrorConfig
{
    /** @var string Cached output buffer content captured before error display */
    private static string $cached = '';

    /** @var bool Whether debug mode is enabled for detailed backtraces */
    private static bool $debug = false;

    /** @var array<string> Debug console messages accumulated during execution */
    private static array $debugConsole = [];

    /**
     * Get the cached buffer content.
     *
     * @return string
     */
    public static function getCached(): string
    {
        return self::$cached;
    }

    /**
     * Set the cached buffer content.
     *
     * @param string $content
     */
    public static function setCached(string $content): void
    {
        self::$cached = $content;
    }

    /**
     * Enable or disable debug mode for detailed backtraces.
     *
     * @param bool $enable
     */
    public static function setDebug(bool $enable): void
    {
        self::$debug = $enable;
    }

    /**
     * Configure from a config array. Reads the 'debug' key.
     *
     * @param array<string, mixed> $config Configuration array
     */
    public static function configure(array $config): void
    {
        self::$debug = (bool) ($config['debug'] ?? false);
    }

    /**
     * Check if debug mode is currently enabled.
     *
     * @return bool
     */
    public static function isDebug(): bool
    {
        return self::$debug;
    }

    /**
     * Write a message to the debug console log.
     *
     * @param string $message The debug message to record
     */
    public static function debugConsoleWrite(string $message): void
    {
        self::$debugConsole[] = $message;
    }

    /**
     * Get all debug console messages.
     *
     * @return array<string>
     */
    public static function getDebugConsole(): array
    {
        return self::$debugConsole;
    }

    /**
     * Reset all static state. Essential for worker mode (FrankenPHP).
     */
    public static function reset(): void
    {
        self::$cached = '';
        self::$debug = false;
        self::$debugConsole = [];
    }
}
