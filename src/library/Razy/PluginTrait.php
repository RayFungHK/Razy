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

namespace Razy;

use Closure;
/**
 * Trait for loading and caching plugin closures from registered folders.
 *
 * Any class using this trait can register plugin directories and dynamically
 * load plugin closures by name. Plugins are PHP files that return a Closure.
 *
 * Since Phase 5, all state is delegated to PluginManager. The trait methods
 * remain for backward compatibility but are thin wrappers around the
 * centralized PluginManager singleton.
 *
 * @class PluginTrait
 */
trait PluginTrait
{
    /**
     * Get the plugin closure from the plugin pool.
     *
     * Delegates to PluginManager::getInstance()->getPlugin().
     *
     * @param string $pluginName
     * @return array|null
     * @throws Error
     */
    static private function GetPlugin(string $pluginName): ?array
    {
        return PluginManager::getInstance()->getPlugin(static::class, $pluginName);
    }

    /**
     * Add a plugin folder which the plugin is load
     *
     * Delegates to PluginManager::getInstance()->addFolder().
     *
     * @param string $folder
     * @param mixed $args
     * @return void
     */
    final public static function addPluginFolder(string $folder, mixed $args = null): void
    {
        PluginManager::getInstance()->addFolder(static::class, $folder, $args);
    }

    /**
     * Reset all registered plugin folders and cached plugins for this class.
     * Essential for worker mode (FrankenPHP) to prevent state leaking between requests.
     *
     * Delegates to PluginManager::getInstance()->reset().
     */
    final public static function resetPlugins(): void
    {
        PluginManager::getInstance()->reset(static::class);
    }
}