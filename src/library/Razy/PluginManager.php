<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Centralized plugin registry that replaces the static state previously
 * scattered across PluginTrait consumers (Template, Collection, Pipeline,
 * Statement). Each consumer class gets an independent registry (folders +
 * cache), but they are all managed through a single PluginManager instance,
 * enabling a one-call `resetAll()` for worker mode cleanup.
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy;

use Closure;
use Razy\Exception\ConfigurationException;
use Razy\Util\PathUtil;
use Throwable;

class PluginManager
{
    /**
     * Global singleton instance.
     */
    private static ?self $instance = null;

    /**
     * Per-owner registries.
     *
     * Structure:
     * [
     *     'Razy\Template' => [
     *         'folders' => [ '/path/to/plugins' => $args, ... ],
     *         'cache'   => [ 'modifier.upper' => ['entity' => Closure, 'args' => $args], ... ],
     *     ],
     *     ...
     * ]
     *
     * @var array<string, array{folders: array<string, mixed>, cache: array<string, array{entity: Closure, args: mixed}>}>
     */
    private array $registries = [];

    /**
     * Private constructor — use getInstance().
     */
    public function __construct()
    {
    }

    /**
     * Get the global singleton PluginManager.
     */
    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Replace the global instance (useful for testing).
     *
     * @param self|null $manager Pass null to clear the singleton
     */
    public static function setInstance(?self $manager): void
    {
        self::$instance = $manager;
    }

    /**
     * Register a plugin folder for a specific owner class.
     *
     * @param string $owner The fully-qualified class name (e.g. 'Razy\Template')
     * @param string $folder Absolute path to the plugin directory
     * @param mixed $args Optional arguments passed to the plugin closure on load
     */
    public function addFolder(string $owner, string $folder, mixed $args = null): void
    {
        $folder = PathUtil::tidy(\trim($folder));
        if ($folder && \is_dir($folder)) {
            $this->registries[$owner]['folders'][$folder] = $args;
        }
    }

    /**
     * Load and return a plugin closure (with caching) for a specific owner.
     *
     * @param string $owner The fully-qualified class name
     * @param string $pluginName The plugin identifier (e.g. 'modifier.upper')
     *
     * @return array{entity: Closure, args: mixed}|null
     *
     * @throws ConfigurationException
     */
    public function getPlugin(string $owner, string $pluginName): ?array
    {
        // Check cache first
        if (isset($this->registries[$owner]['cache'][$pluginName])) {
            return $this->registries[$owner]['cache'][$pluginName];
        }

        // Search through registered folders in order
        $folders = $this->registries[$owner]['folders'] ?? [];
        foreach ($folders as $folder => $args) {
            $pluginFile = PathUtil::append($folder, $pluginName . '.php');
            if (\is_file($pluginFile)) {
                try {
                    $plugin = require $pluginFile;
                    if ($plugin instanceof Closure) {
                        return ($this->registries[$owner]['cache'][$pluginName] = [
                            'entity' => $plugin,
                            'args' => $args,
                        ]);
                    }
                    return null;
                } catch (Throwable $e) {
                    throw new ConfigurationException($e->getMessage(), $e);
                }
            }
        }

        return null;
    }

    /**
     * Reset the registry for a single owner.
     *
     * @param string $owner The fully-qualified class name
     */
    public function reset(string $owner): void
    {
        unset($this->registries[$owner]);
    }

    /**
     * Reset ALL registries. Worker mode cleanup — replaces 4 separate
     * resetPlugins() calls with a single PluginManager::getInstance()->resetAll().
     */
    public function resetAll(): void
    {
        $this->registries = [];
    }

    /**
     * Get all registered folders for a specific owner (for diagnostics/testing).
     *
     * @param string $owner The fully-qualified class name
     *
     * @return array<string, mixed> Folder path => args map
     */
    public function getFolders(string $owner): array
    {
        return $this->registries[$owner]['folders'] ?? [];
    }

    /**
     * Get the cached plugins for a specific owner (for diagnostics/testing).
     *
     * @param string $owner The fully-qualified class name
     *
     * @return array<string, array{entity: Closure, args: mixed}>
     */
    public function getCachedPlugins(string $owner): array
    {
        return $this->registries[$owner]['cache'] ?? [];
    }

    /**
     * Get all registered owner names.
     *
     * @return list<string>
     */
    public function getRegisteredOwners(): array
    {
        return \array_keys($this->registries);
    }
}
