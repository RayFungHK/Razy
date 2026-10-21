<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Log;

use Razy\Contract\Log\LogLevel;
use Razy\Error\ErrorReporter;

/**
 * RZ-14: process-wide log output chosen by config, applied once at boot.
 *
 * `RAZY_LOG_FORMAT` (env) or config.inc.php `log_format`:
 *  - `text` (default): nothing changes; error reports go to PHP's error_log()
 *    with a ` rid=<id>` suffix.
 *  - `json`: when no error logger is registered yet, framework error reports
 *    and warnings go to stderr as one JSON object per line ({@see JsonFormatter}),
 *    each carrying the request id. An app that registers its own logger with
 *    `Error::setLogger()` keeps it.
 *
 * `RAZY_LOG_LEVEL` (env) or config `log_level` sets the minimum level of that
 * stderr logger (default `info`).
 *
 * @license MIT
 */
final class RuntimeLogging
{
    public const FORMAT_TEXT = 'text';

    public const FORMAT_JSON = 'json';

    /**
     * Apply the configured format. Returns the logger it installed, or null
     * when it left the current setup alone.
     *
     * @param array<string, mixed> $config config.inc.php values
     */
    public static function configure(array $config): ?LogManager
    {
        if (self::format($config) !== self::FORMAT_JSON || ErrorReporter::getLogger() !== null) {
            return null;
        }

        $logger = new LogManager('razy');
        $logger->addHandler('razy', new StderrHandler(self::level($config), new JsonFormatter()));
        ErrorReporter::setLogger($logger);

        return $logger;
    }

    /**
     * `text` or `json`; env wins over config, unknown values mean `text`.
     *
     * @param array<string, mixed> $config
     */
    public static function format(array $config): string
    {
        $value = \strtolower(\trim((string) (self::env('RAZY_LOG_FORMAT') ?? $config['log_format'] ?? '')));

        return $value === self::FORMAT_JSON ? self::FORMAT_JSON : self::FORMAT_TEXT;
    }

    /**
     * Minimum PSR-3 level for the stderr logger; env wins over config,
     * unknown values mean `info`.
     *
     * @param array<string, mixed> $config
     */
    public static function level(array $config): string
    {
        $value = \strtolower(\trim((string) (self::env('RAZY_LOG_LEVEL') ?? $config['log_level'] ?? '')));
        $levels = [
            LogLevel::DEBUG, LogLevel::INFO, LogLevel::NOTICE, LogLevel::WARNING,
            LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY,
        ];

        return \in_array($value, $levels, true) ? $value : LogLevel::INFO;
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? \getenv($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
