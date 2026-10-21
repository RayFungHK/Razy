<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Config;

use Razy\Exception\ConfigurationException;

/**
 * RZ-17: shape check for `sites.inc.php`.
 *
 * The file must return
 * `['domains' => [host => [path => 'dist[@tag]' | [subpath => ...]]], 'alias' => [alias => host], 'exclude_paths' => [...]]`.
 * A file with no `return` (PHP yields int 1), a non-array section, a domain
 * whose value is not an array, or a dist identifier that is not a string in
 * `code[@tag]` form used to be dropped silently or end in a TypeError that
 * did not name the file. They now throw a ConfigurationException naming the
 * file and the offending key.
 *
 * Problems that depend on the file system or host grammar (a dist with no
 * dist.php, a host key that is not a valid FQDN) are not shape errors; the
 * Application warns about them and skips the entry, as before.
 *
 * @license MIT
 */
final class SiteConfig
{
    /** Expected shape, quoted in every error. */
    public const SHAPE = "sites.inc.php must return ['domains' => [...], 'alias' => [...]]";

    /**
     * Dist identifier: `code` or `code@tag`. A tag starts with a letter or digit
     * and may hold letters, digits, `_`, `-` and `.` (`beta`, `1.2.0`, `v1.2.0`).
     * One grammar for this file, `Application::updateSites()` and
     * `DomainList::parse()`.
     */
    public const DIST_IDENTIFIER = '/^[a-z0-9][\w\-]*(@[a-z0-9][\w.\-]*)?$/i';

    /**
     * Validate the value a sites.inc.php returned and fill in missing sections.
     *
     * @param mixed $config What the file returned
     * @param string $file The file path, for error messages
     *
     * @return array{domains: array<array-key, mixed>, alias: array<array-key, mixed>, exclude_paths: array<array-key, mixed>}
     *
     * @throws ConfigurationException on any shape error
     */
    public static function validate(mixed $config, string $file): array
    {
        if (!\is_array($config)) {
            throw self::error($file, \sprintf('it returned %s (missing `return`?)', \get_debug_type($config)));
        }

        foreach (['domains', 'alias', 'exclude_paths'] as $section) {
            if (isset($config[$section]) && !\is_array($config[$section])) {
                throw self::error($file, \sprintf("'%s' must be an array, got %s", $section, \get_debug_type($config[$section])));
            }
            $config[$section] ??= [];
        }

        foreach ($config['domains'] as $domain => $paths) {
            if (!\is_array($paths)) {
                throw self::error($file, \sprintf(
                    "'domains' => '%s' must be an array of URL path => dist code (e.g. ['/' => 'main']), got %s",
                    $domain,
                    \get_debug_type($paths),
                ));
            }
            self::validatePaths($paths, "domains.{$domain}", $file);
        }

        foreach ($config['alias'] as $alias => $domain) {
            if (!\is_string($domain)) {
                throw self::error($file, \sprintf("'alias' => '%s' must map to a domain string, got %s", $alias, \get_debug_type($domain)));
            }
        }

        return $config;
    }

    /**
     * @param array<array-key, mixed> $paths
     */
    private static function validatePaths(array $paths, string $where, string $file): void
    {
        foreach ($paths as $path => $dist) {
            $key = $where . '[' . $path . ']';
            if (\is_array($dist)) {
                self::validatePaths($dist, $key, $file);
            } elseif (!\is_string($dist)) {
                throw self::error($file, \sprintf('%s must be a dist code string or a nested array, got %s', $key, \get_debug_type($dist)));
            } elseif (\preg_match(self::DIST_IDENTIFIER, $dist) !== 1) {
                throw self::error($file, \sprintf("%s: '%s' is not a dist identifier (code or code@tag)", $key, $dist));
            }
        }
    }

    private static function error(string $file, string $detail): ConfigurationException
    {
        return new ConfigurationException(self::SHAPE . " ({$file}): {$detail}.");
    }
}
