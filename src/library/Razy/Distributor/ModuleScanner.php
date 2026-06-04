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
                            // Ensure the resolved path stays within the package folder (defense-in-depth)
                            $realConfigPath = \realpath($moduleConfigPath);
                            $realPackageFolder = \realpath($packageFolder);
                            if ($realConfigPath === false || $realPackageFolder === false
                                || !\str_starts_with($realConfigPath, $realPackageFolder . DIRECTORY_SEPARATOR)) {
                                continue;
                            }
                            try {
                                $config = require $realConfigPath;

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
            $segments = \explode('/', $moduleClassName);
            if (\count($segments) < 3) {
                return \Razy\autoload($className, PathUtil::append(SYSTEM_ROOT, 'autoload', $code));
            }

            // Module code is always vendor/package; remainder is library-relative (supports ChatRegistries/Pipeline).
            $moduleCode = $segments[0] . '/' . $segments[1];
            $libraryRelative = \implode('/', \array_slice($segments, 2));

            // Registry keys use package.php module_code (oaaoai/slide-designer); library NS uses api_name (slide_designer).
            $module = $this->resolveLoadedModule($modules, $segments[0], $segments[1]);
            if ($module !== null) {
                $moduleCode = $module->getModuleInfo()->getCode();
            }

            // Try to load the class from the module library
            if ($module !== null) {
                $status = $module->getStatus();
                $mayAutoloadLibrary = \in_array($status, [
                    ModuleStatus::Processing,
                    ModuleStatus::Initialing,
                    ModuleStatus::InQueue,
                    ModuleStatus::Loaded,
                ], true);
                if ($mayAutoloadLibrary) {
                    $moduleInfo = $module->getModuleInfo();
                    $path = PathUtil::append($moduleInfo->getPath(), 'library');
                    if (\is_dir($path)) {
                        $libraryPath = PathUtil::append($path, $libraryRelative);
                        if (\is_file($libraryPath . '.php')) {
                            $libraryPath .= '.php';
                        } elseif (\is_dir($libraryPath) && \is_file(PathUtil::append($libraryPath, \basename($libraryRelative) . '.php'))) {
                            $libraryPath = PathUtil::append($libraryPath, \basename($libraryRelative) . '.php');
                        }

                        if (\is_file($libraryPath)) {
                            // Ensure the resolved path stays within the module path (defense-in-depth)
                            $realLibPath = \realpath($libraryPath);
                            $realBasePath = \realpath($path);
                            if ($realLibPath === false || $realBasePath === false
                                || !\str_starts_with($realLibPath, $realBasePath . DIRECTORY_SEPARATOR)) {
                                return false;
                            }
                            try {
                                include_once $realLibPath;

                                return \trait_exists($className, false) || \class_exists($className, false);
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

    /**
     * Map library namespace {@code oaaoai\{api_name}\…} to a loaded {@see Module}.
     *
     * Registry keys follow {@code package.php} {@code module_code} (e.g. {@code oaaoai/slide-designer});
     * library namespaces use {@code api_name} with underscores ({@code slide_designer}).
     */
    private function resolveLoadedModule(array $modules, string $vendor, string $packageSegment): ?Module
    {
        $vendorLower = \strtolower($vendor);
        $segmentLower = \strtolower($packageSegment);
        $candidates = [
            $vendorLower . '/' . $segmentLower,
            $vendorLower . '/' . \str_replace('_', '-', $segmentLower),
        ];

        foreach ($candidates as $code) {
            $module = $modules[$code] ?? null;
            if ($module instanceof Module) {
                return $module;
            }
        }

        foreach ($modules as $module) {
            if (!$module instanceof Module) {
                continue;
            }
            $info = $module->getModuleInfo();
            $code = $info->getCode();
            $parts = \explode('/', $code, 2);
            if (\count($parts) !== 2 || \strtolower($parts[0]) !== $vendorLower) {
                continue;
            }
            if (\strtolower($info->getAPIName()) === $segmentLower) {
                return $module;
            }
        }

        return null;
    }
}
