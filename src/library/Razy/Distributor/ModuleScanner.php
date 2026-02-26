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

namespace Razy\Distributor;

use Exception;
use Razy\Cache;
use Razy\Exception\ModuleConfigException;
use Razy\Exception\ModuleLoadException;
use Razy\Module;
use Razy\Module\ModuleStatus;
use Razy\ModuleInfo;
use Razy\Util\PathUtil;
use Throwable;

/**
 * Class ModuleScanner.
 *
 * Handles filesystem scanning for module discovery, manifest caching, and
 * module namespace autoloading.
 *
 * Extracted from the Distributor god class to follow Single Responsibility Principle.
 *
 * @class ModuleScanner
 */
class ModuleScanner
{
    /**
     * ModuleScanner constructor.
     *
     * @param object $distributor The parent Distributor instance (passed to Module constructors)
     */
    public function __construct(
        private readonly object $distributor,
    ) {
    }

    /**
     * Scan and find available modules under the given folder path.
     *
     * Builds or restores a module manifest from cache, keyed by directory
     * modification signatures for invalidation.
     *
     * @param string $path The path of the module folder
     * @param bool $globally Set true to define the module is loaded globally
     * @param array<string, string|null> $requires Required module codes mapped to version constraints
     * @param array<string, Module> &$modules Reference to the module registry to populate
     *
     * @throws ModuleConfigException
     * @throws ModuleLoadException
     * @throws Throwable
     */
    public function scan(string $path, bool $globally, array $requires, array &$modules): void
    {
        $path = PathUtil::tidy($path, true);
        if (!\is_dir($path)) {
            return;
        }

        // Try to load module manifest from cache
        $cacheKey = 'modules.' . \md5($path);
        $dirSignature = $this->getModuleDirSignature($path);
        $cached = Cache::get($cacheKey);

        if (\is_array($cached) && isset($cached['sig'], $cached['manifest']) && $cached['sig'] === $dirSignature) {
            // Load modules from cached manifest (skip filesystem scanning)
            foreach ($cached['manifest'] as $entry) {
                $version = $requires[$entry['module_code']] ?? 'default';
                if (\is_file(PathUtil::append($entry['packageFolder'], $version, 'package.php'))) {
                    try {
                        $module = new Module($this->distributor, $entry['packageFolder'], $entry['config'], $version, $globally);
                        if (!isset($modules[$entry['module_code']])) {
                            $modules[$entry['module_code']] = $module;
                        }
                    } catch (Exception $e) {
                        // Skip modules that fail to load from cache — will be caught on next full scan
                    }
                }
            }

            return;
        }

        // Full filesystem scan — build module manifest
        $manifest = [];

        foreach (\scandir($path) as $vendor) {
            if ('.' === $vendor || '..' === $vendor) {
                continue;
            }

            $moduleFolder = PathUtil::append($path, $vendor);
            if (\is_dir($moduleFolder)) {
                foreach (\scandir($moduleFolder) as $packageName) {
                    if ('.' === $packageName || '..' === $packageName) {
                        continue;
                    }

                    $packageFolder = PathUtil::append($moduleFolder, $packageName);
                    if (\is_dir($packageFolder)) {
                        // Look for module.php configuration file in vendor/package directory
                        $moduleConfigPath = PathUtil::append($packageFolder, 'module.php');
                        if (\is_file($moduleConfigPath)) {
                            try {
                                $config = require $moduleConfigPath;

                                $config['module_code'] ??= '';
                                if (!\preg_match(ModuleInfo::REGEX_MODULE_CODE, $config['module_code'])) {
                                    throw new ModuleConfigException("Incorrect module code format '{$config['module_code']}' in '{$moduleConfigPath}'. Expected 'vendor/package'.");
                                }
                                $config['author'] ??= '';
                                $config['description'] ??= '';
                            } catch (Exception $e) {
                                throw new ModuleConfigException("Unable to read module config at '{$moduleConfigPath}': " . $e->getMessage());
                            }

                            // Store in manifest for caching
                            $manifest[] = [
                                'module_code' => $config['module_code'],
                                'packageFolder' => $packageFolder,
                                'config' => $config,
                            ];

                            $version = (isset($requires[$config['module_code']])) ? $requires[$config['module_code']] : 'default';

                            // Only load the module if the versioned package.php exists
                            if (\is_file(PathUtil::append($packageFolder, $version, 'package.php'))) {
                                try {
                                    $module = new Module($this->distributor, $packageFolder, $config, $version, $globally);

                                    if (!isset($modules[$config['module_code']])) {
                                        $modules[$config['module_code']] = $module;
                                    } else {
                                        throw new ModuleLoadException("Duplicated module '{$config['module_code']}' loaded from '{$packageFolder}'. A module with the same code is already loaded.");
                                    }
                                } catch (Exception $e) {
                                    throw new ModuleLoadException("Unable to load module '{$config['module_code']}' from '{$packageFolder}': " . $e->getMessage());
                                }
                            }
                        }
                    }
                }
            }
        }

        // Cache the manifest with directory signature for invalidation
        Cache::set($cacheKey, ['sig' => $dirSignature, 'manifest' => $manifest]);
    }

    /**
     * Generate a composite signature for the module directory tree.
     *
     * Checks the modification time of the root path and all vendor-level subdirectories.
     * This detects new/removed vendors and new/removed packages within vendors without
     * scanning the full tree.
     *
     * @param string $path The module root path
     *
     * @return string A hash signature of directory modification times
     */
    public function getModuleDirSignature(string $path): string
    {
        $mtimes = [(string) @\filemtime($path)];

        foreach (\scandir($path) as $vendor) {
            if ($vendor === '.' || $vendor === '..') {
                continue;
            }

            $vendorPath = PathUtil::append($path, $vendor);
            if (\is_dir($vendorPath)) {
                $mtimes[] = $vendor . ':' . @\filemtime($vendorPath);
            }
        }

        return \md5(\implode('|', $mtimes));
    }

    /**
     * Autoload a class from a module's library folder.
     *
     * Converts namespace separators to directory paths and attempts to locate
     * the class file within the loaded module's library directory.
     *
     * @param string $className The fully-qualified class name
     * @param array<string, Module> $modules The current module registry
     * @param string $code The distributor code (for global autoload fallback)
     *
     * @return bool True if the class was successfully loaded
     */
    public function autoload(string $className, array $modules, string $code): bool
    {
        // Convert namespace separators to directory separators for file lookup
        $moduleClassName = \str_replace('\\', '/', $className);
        if (\preg_match(ModuleInfo::REGEX_MODULE_CODE, $moduleClassName, $matches)) {
            $namespaces = \explode('/', $moduleClassName);
            $moduleClassName = \array_pop($namespaces);
            $moduleCode = \implode('/', $namespaces);

            // Try to load the class from the module library
            if (isset($modules[$moduleCode])) {
                $module = $modules[$moduleCode];
                if ($module->getStatus() === ModuleStatus::Loaded) {
                    $moduleInfo = $module->getModuleInfo();
                    $path = PathUtil::append($moduleInfo->getPath(), 'library');
                    if (\is_dir($path)) {
                        $libraryPath = PathUtil::append($path, $moduleClassName);
                        if (\is_file($libraryPath . '.php')) {
                            $libraryPath .= '.php';
                        } elseif (\is_dir($libraryPath) && \is_file(PathUtil::append($libraryPath, $moduleClassName . '.php'))) {
                            $libraryPath = PathUtil::append($libraryPath, $moduleClassName . '.php');
                        }

                        if (\is_file($libraryPath)) {
                            try {
                                include $libraryPath;

                                return \class_exists($moduleClassName);
                            } catch (Exception) {
                                return false;
                            }
                        }
                    }
                }
            }
        }

        $libraryPath = PathUtil::append(SYSTEM_ROOT, 'autoload', $code);
        return \Razy\autoload($className, $libraryPath);
    }
}
