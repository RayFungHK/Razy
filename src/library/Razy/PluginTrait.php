<?php
/*
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace Razy;

use Closure;
use Throwable;

trait PluginTrait
{
    private static array $pluginFolder = [];
    private static array $pluginsCache = [];

    /**
     * Get the plugin closure from the plugin pool.
     *
     * @param string $pluginName
     * @return array|null
     * @throws Error
     */
    static private function GetPlugin(string $pluginName): ?array
    {
        foreach (self::$pluginFolder as $folder => $args) {
            $pluginFile = append($folder, $pluginName . '.php');
            if (is_file($pluginFile)) {
                try {
                    $plugin = require $pluginFile;
                    if ($plugin instanceof Closure) {
                        return (self::$pluginsCache[$pluginName] = [
                            'entity' => $plugin,
                            'args' => $args,
                        ]);
                    }
                    return null;
                } catch (Throwable $e) {
                    throw new Error($e);
                }
            }
        }

        return self::$pluginsCache[$pluginName] ?? null;
    }

    /**
     * Add a plugin folder which the plugin is load
     *
     * @param string $folder
     * @param mixed $args
     * @return void
     */
    final public static function AddPluginFolder(string $folder, mixed $args = null): void
    {
        // Setup plugin folder
        $folder = tidy(trim($folder));
        if ($folder && is_dir($folder)) {
            self::$pluginFolder[$folder] = $args;
        }
    }
}