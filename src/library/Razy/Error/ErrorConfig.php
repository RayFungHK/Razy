<?php

/**
 * This file is part of Razy.
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

    /** @var bool Whether a refused RAZY_DEBUG override was already logged by this process */
    private static bool $overrideRefusalLogged = false;

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
     * Configure from a config array. Reads the 'debug' key, then applies the
     * `RAZY_DEBUG` environment override (RZ-01):
     *
     * - `RAZY_DEBUG` unset or empty: the config value stands.
     * - `RAZY_DEBUG` falsy (`0`, `false`, `off`, `no`): debug is forced off on any host.
     * - `RAZY_DEBUG` truthy: debug is turned on only for a loopback request (loopback
     *   host name AND loopback client address); elsewhere the override is refused
     *   and logged, and the config value stands.
     *
     * @param array<string, mixed> $config Configuration array
     * @param array<string, mixed>|null $server Request server vars (defaults to $_SERVER)
     */
    public static function configure(array $config, ?array $server = null): void
    {
        self::$debug = (bool) ($config['debug'] ?? false);

        $override = \getenv('RAZY_DEBUG');
        if (false === $override || '' === \trim($override)) {
            return;
        }

        $enable = \filter_var($override, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if (false === $enable) {
            self::$debug = false;

            return;
        }
        if (true !== $enable) {
            return;
        }

        if (self::isLoopbackRequest($server ?? $_SERVER)) {
            self::$debug = true;
        } elseif (!self::$debug && !self::$overrideRefusalLogged) {
            self::$overrideRefusalLogged = true;
            ErrorReporter::warn('RAZY_DEBUG refused: debug output is only allowed for loopback requests.');
        }
    }

    /**
     * Whether the request is a loopback request: the host name is loopback and the
     * client address is present and loopback too, so a spoofed `Host: localhost`
     * from a remote client does not count.
     *
     * @param array<string, mixed> $server Request server vars
     *
     * @return bool
     */
    public static function isLoopbackRequest(array $server): bool
    {
        $host = (string) ($server['SERVER_NAME'] ?? $server['HTTP_HOST'] ?? '');
        if ('' === $host) {
            return false;
        }
        // Strip brackets / port: "[::1]:8080" -> "::1", "localhost:80" -> "localhost"
        if (\str_starts_with($host, '[')) {
            $host = \substr($host, 1, (\strpos($host, ']') ?: \strlen($host)) - 1);
        } elseif (\substr_count($host, ':') === 1) {
            $host = \explode(':', $host)[0];
        }

        if (!self::isLoopbackName($host)) {
            return false;
        }

        // Fail closed: no client address means we cannot prove the request is local.
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');

        return '' !== $remote && self::isLoopbackName($remote);
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

    /**
     * Whether a host name or IP literal is loopback (localhost, *.localhost, 127.0.0.0/8, ::1).
     *
     * @param string $host
     *
     * @return bool
     */
    private static function isLoopbackName(string $host): bool
    {
        $host = \strtolower(\rtrim($host, '.'));
        if ('localhost' === $host || \str_ends_with($host, '.localhost')) {
            return true;
        }
        if (\filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return \str_starts_with($host, '127.');
        }
        if (\filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = \inet_pton($host);

            return $packed === \inet_pton('::1')
                // IPv4-mapped loopback ::ffff:127.x.x.x
                || (false !== $packed && \str_starts_with($packed, \str_repeat("\0", 10) . "\xff\xff\x7f"));
        }

        return false;
    }
}
