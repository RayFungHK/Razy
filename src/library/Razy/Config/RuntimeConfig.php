<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Config;

use Razy\Env;
use Razy\Exception\ConfigurationException;
use Razy\Util\PathUtil;
use Throwable;

/**
 * RZ-08: boot-time environment and config.inc.php loading.
 *
 * The bootstrap loads `.env` first, then evaluates config.inc.php, so config can
 * read `Env::string()` / `\Razy\env()` values that live in the `.env` file
 * (with the RZ-08 index.php; see loadConfig()).
 *
 * `.env` lookup: `RAZY_ENV_FILE` (absolute, or relative to SYSTEM_ROOT; `none`
 * disables loading), else `SYSTEM_ROOT/.env` when present. Variables already in
 * the process environment are never overwritten.
 */
final class RuntimeConfig
{
    public const ENV_FILE_VAR = 'RAZY_ENV_FILE';

    /**
     * Load the `.env` file for this installation.
     *
     * @return string|null The file that was loaded, or null when none was.
     *
     * @throws ConfigurationException If RAZY_ENV_FILE names a file that does not exist or does not parse.
     */
    public static function loadEnv(string $systemRoot): ?string
    {
        $override = \getenv(self::ENV_FILE_VAR);
        if ($override !== false && \trim($override) !== '') {
            $override = \trim($override);
            if (\strtolower($override) === 'none') {
                return null;
            }
            $path = self::isAbsolute($override) ? $override : PathUtil::append($systemRoot, $override);
            if (!\is_file($path)) {
                throw new ConfigurationException(self::ENV_FILE_VAR . " points to a missing file: {$path}");
            }

            return self::load($path);
        }

        $path = PathUtil::append($systemRoot, '.env');

        return \is_file($path) ? self::load($path) : null;
    }

    /**
     * Evaluate SYSTEM_ROOT/config.inc.php (after loadEnv()).
     *
     * An entry point from before RZ-08 requires config.inc.php itself, before the
     * phar loads; that result is passed as `$preloaded` and returned unchanged so
     * the file is never evaluated twice (a config that declares a function or
     * constant would fatal). Only the RZ-08 index.php (which leaves config to the
     * framework) gets `.env` values inside config.inc.php.
     *
     * @param array<string, mixed>|null $preloaded Config the entry point already read, if any.
     *
     * @return array<string, mixed>
     *
     * @throws ConfigurationException If config.inc.php does not return an array.
     */
    public static function loadConfig(string $systemRoot, ?array $preloaded): array
    {
        if ($preloaded !== null) {
            return $preloaded;
        }
        $path = PathUtil::append($systemRoot, 'config.inc.php');
        if (!\is_file($path)) {
            return [];
        }

        $config = (static fn (string $file): mixed => require $file)($path);
        if (!\is_array($config)) {
            throw new ConfigurationException('config.inc.php must return an array.');
        }

        return $config;
    }

    private static function load(string $path): string
    {
        try {
            Env::load($path);
        } catch (Throwable $e) {
            // Env's messages quote the offending line, which may hold a secret:
            // keep only the line number (the original stays as $previous).
            $where = \preg_match('/line (\d+)/', $e->getMessage(), $m) ? " (invalid syntax on line {$m[1]})" : '';

            throw new ConfigurationException("Cannot load environment file {$path}{$where}.", $e);
        }

        return $path;
    }

    private static function isAbsolute(string $path): bool
    {
        return \str_starts_with($path, '/') || \str_starts_with($path, '\\') || (bool) \preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }
}
