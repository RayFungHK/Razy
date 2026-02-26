<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy;

use Exception;
use Razy\Error\ErrorConfig;
use Razy\Error\ErrorRenderer;
use Throwable;

/**
 * Class Error.
 *
 * Custom exception class for the Razy framework. Extends PHP's built-in Exception
 * to provide enhanced error display with debug backtraces, custom exception pages
 * using the Template engine, and CLI-mode error output via Terminal.
 *
 * Static configuration and rendering methods delegate to ErrorConfig and ErrorRenderer
 * respectively. The proxy methods on this class are retained for backward compatibility.
 *
 * @class Error
 */
class Error extends Exception
{
    /** @var string Default heading text for exception pages */
    public const DEFAULT_HEADING = 'There seems to is something wrong...';

    /**
     * Error constructor.
     *
     * @param string $message
     * @param int $statusCode
     * @param string $heading
     * @param string $debugMessage
     * @param Throwable|null $exception
     */
    public function __construct(string $message, int $statusCode = 400, private readonly string $heading = self::DEFAULT_HEADING, private readonly string $debugMessage = '', Throwable $exception = null)
    {
        parent::__construct(\nl2br($message), $statusCode, $exception);
        if (CLI_MODE && !\defined('PHPUNIT_RUNNING')) {
            Terminal::WriteLine('{@c:red}' . $message, true);
        }
    }

    /**
     * Get the cached buffer content.
     *
     * @return string
     */
    public static function getCached(): string
    {
        return ErrorConfig::getCached();
    }

    /**
     * Configure Error behavior from a config array.
     *
     * @param array<string, mixed> $config Configuration array (e.g. from site config)
     */
    public static function configure(array $config): void
    {
        ErrorConfig::configure($config);
    }

    /**
     * Check if debug mode is currently enabled.
     *
     * @return bool
     */
    public static function isDebug(): bool
    {
        return ErrorConfig::isDebug();
    }

    /**
     * Reset static state between requests. Essential for worker mode.
     */
    public static function reset(): void
    {
        ErrorConfig::reset();
    }

    /**
     * Display 404 Not Found error page.
     */
    public static function show404(): void
    {
        ErrorRenderer::show404();
    }

    /**
     * Write a message to the debug console log.
     *
     * @param string $message The debug message to record
     */
    public static function debugConsoleWrite(string $message): void
    {
        ErrorConfig::debugConsoleWrite($message);
    }

    /**
     * Show the custom exception page by the given exception object.
     *
     * @param Throwable $exception
     *
     * @throws Throwable
     */
    public static function showException(Throwable $exception): void
    {
        ErrorRenderer::showException($exception);
    }

    /**
     * Get the heading.
     *
     * @return string The exception page heading
     */
    public function getHeading(): string
    {
        return $this->heading;
    }

    /**
     * Get the debug message.
     *
     * @return string The exception page heading
     */
    public function getDebugMessage(): string
    {
        return $this->debugMessage;
    }
}
