<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\Config;

use Razy\Exception\ConfigurationException;

/**
 * Loads configuration files (PHP arrays) in a way that can be mocked for testing.
 *
 * Replaces direct `require` calls in Distributor and ModuleInfo so that
 * configuration loading can be intercepted, overridden, or replaced
 * with test doubles.
 */
class ConfigLoader
{
    /**
     * Load a PHP configuration file that returns an array.
     *
     * @param string $filePath Absolute path to the configuration file
     * @return array The configuration array
     * @throws ConfigurationException If the file does not exist
     */
    public function load(string $filePath): array
    {
        if (!is_file($filePath)) {
            throw new ConfigurationException("Config not found: {$filePath}");
        }

        $result = require $filePath;

        return is_array($result) ? $result : [];
    }

    /**
     * Load a PHP configuration file, returning the raw result (may be non-array).
     *
     * @param string $filePath Absolute path to the configuration file
     * @return mixed The raw return value of the required file
     * @throws ConfigurationException If the file does not exist
     */
    public function loadRaw(string $filePath): mixed
    {
        if (!is_file($filePath)) {
            throw new ConfigurationException("Config not found: {$filePath}");
        }

        return require $filePath;
    }
}
