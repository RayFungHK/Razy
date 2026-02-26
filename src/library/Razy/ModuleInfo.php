<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Module metadata container that parses and validates a module's
 * package.php configuration, including its code, author, API name,
 * prerequisites, requirements, assets, and inter-module metadata.
 *
 *
 * @license MIT
 */

namespace Razy;

use Exception;
use Razy\Config\ConfigLoader;
use Razy\Exception\ModuleConfigException;
use Razy\Util\PathUtil;
use Throwable;

/**
 * Immutable metadata descriptor for a Razy module.
 *
 * Constructed from a module's directory and its configuration array,
 * ModuleInfo validates and exposes all module properties such as code,
 * alias, API name, version, prerequisites, required dependencies,
 * asset mappings, and per-module metadata for inter-module communication.
 *
 * @class ModuleInfo
 */
class ModuleInfo
{
    /**
     * Regex pattern for validating module codes in "vendor/package" format.
     * Allows alphanumeric segments with dots, underscores, or hyphens,
     * separated by forward slashes.
     *
     * @var string
     */
    public const REGEX_MODULE_CODE = '/^[a-z0-9]([_.-]?[a-z0-9]+)*(\/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*)+$/i';

    /** @var string Module display alias (defaults to class name) */
    private string $alias = '';

    /** @var string API endpoint name for inter-module calls */
    private string $apiName = '';

    /** @var array<string, array{path: string, system_path: string}> Asset path mappings */
    private array $assets = [];

    /** @var string Module description text */
    private string $description = '';

    /** @var string Module author identifier */
    private string $author;

    /** @var string Fully-qualified module code (vendor/package) */
    private string $code;

    /** @var string Resolved filesystem path to the module's versioned directory */
    private string $modulePath;

    /** @var string Module class name derived from the package segment of the code */
    private string $className = '';

    /** @var array<string, string> Prerequisite packages: package => version constraint */
    private array $prerequisite = [];

    /** @var string Module path relative to SYSTEM_ROOT */
    private string $relativePath;

    /** @var array<string, string> Required modules: module code => version constraint */
    private array $require = [];

    /** @var bool Whether the module uses shadow (symlinked) assets */
    private bool $shadowAsset = false;

    /** @var bool Whether the module is packaged as a .phar archive */
    private bool $pharArchive = false;

    /** @var array<string, array<string, mixed>> Per-requester metadata for module-to-module data */
    private array $moduleMetadata = [];

    /** @var array<string, string> Module-level DI service bindings (abstract → concrete class) */
    private array $services = [];

    /** @var ConfigLoader Configuration file loader (injectable for testing) */
    private ConfigLoader $configLoader;

    /** @var bool Whether this module is in standalone (lite) mode */
    private bool $standalone = false;

    /**
     * Module constructor.
     *
     * @param string $containerPath
     * @param array $moduleConfig
     * @param string $version
     * @param bool $sharedModule
     * @param ConfigLoader|null $configLoader Optional config loader (defaults to new ConfigLoader)
     * @param bool $standalone When true, uses ultra-flat layout (no version subdir, no package.php required)
     *
     * @throws ModuleConfigException
     */
    public function __construct(private readonly string $containerPath, array $moduleConfig, private string $version = 'default', private readonly bool $sharedModule = false, ?ConfigLoader $configLoader = null, bool $standalone = false)
    {
        $this->configLoader = $configLoader ?? new ConfigLoader();
        $this->standalone = $standalone;
        // Compute the relative path from SYSTEM_ROOT for URL generation
        $this->relativePath = PathUtil::getRelativePath($this->containerPath, SYSTEM_ROOT);
        $this->version = \trim($this->version);

        if (\is_dir($this->containerPath)) {
            $this->modulePath = $this->containerPath;

            if ($this->standalone) {
                // Standalone mode: ultra-flat layout — no version subdirectory, no package.php required.
                // The containerPath IS the module path (e.g., SYSTEM_ROOT/standalone/).
                // Controller expected at: standalone/controller/{className}.php
                $settings = $moduleConfig['_standalone_settings'] ?? [];

                if (isset($moduleConfig['module_code'])) {
                    $code = \trim($moduleConfig['module_code']);
                    $this->code = $code;
                    $namespaces = \explode('/', $code);
                    $vendor = (string) \array_shift($namespaces);
                    $this->className = \array_pop($namespaces);
                } else {
                    $this->code = 'standalone/app';
                    $this->className = 'app';
                    $namespaces = [];
                    $vendor = 'standalone';
                }

                $this->description = \trim($moduleConfig['description'] ?? 'Standalone application');
                $this->author = \trim($moduleConfig['author'] ?? 'standalone');
            } else {
                // Standard mode: version subdirectory and package.php required

                // Validate version format: must be semver-like (e.g. 1.0.0) unless 'default' or 'dev'
                if ($this->version !== 'default' && $this->version !== 'dev') {
                    if (!\preg_match('/^(\d+)(?:\.(?:\d+|\*)){0,3}$/', $this->version)) {
                        throw new ModuleConfigException("Invalid version format '{$this->version}' for module at '{$this->containerPath}'. Expected semver (e.g. '1.0.0'), 'default', or 'dev'.");
                    }
                }

                // Append version subdirectory to the module path
                $this->modulePath = PathUtil::append($this->modulePath, $this->version);
                $this->relativePath = PathUtil::append($this->relativePath, $this->version);

                // Check for phar archive packaging (app.phar in the version directory)
                if (\is_file(PathUtil::append($this->modulePath, 'app.phar'))) {
                    $this->pharArchive = true;
                    $this->modulePath = 'phar://' . PathUtil::append($this->modulePath, 'app.phar');
                }

                try {
                    // Load the module's package.php settings file
                    $packagePath = PathUtil::append($this->modulePath, 'package.php');
                    $settings = $this->configLoader->loadRaw($packagePath);
                    if (!\is_array($settings)) {
                        throw new ModuleConfigException("Invalid module settings in '{$packagePath}': expected array, got " . \gettype($settings) . '.');
                    }
                } catch (ModuleConfigException $e) {
                    throw $e;
                } catch (Exception $e) {
                    throw new ModuleConfigException("Unable to load module package.php at '{$this->modulePath}/package.php': " . $e->getMessage());
                }

                if (isset($moduleConfig['module_code'])) {
                    if (!\is_string($moduleConfig['module_code'])) {
                        throw new ModuleConfigException('The module code should be a string, got ' . \gettype($moduleConfig['module_code']) . " in '{$this->containerPath}'.");
                    }
                    $code = \trim($moduleConfig['module_code']);

                    // Validate module code format: must match "vendor/package" pattern
                    if (!\preg_match(self::REGEX_MODULE_CODE, $code)) {
                        throw new ModuleConfigException('The module code ' . $code . ' is not a correct format, it should be `vendor/package`.');
                    }

                    $this->code = $code;
                    // Extract vendor and class name from the module code path segments
                    $namespaces = \explode('/', $code);
                    $vendor = \array_shift($namespaces);
                    $className = \array_pop($namespaces);

                    $this->className = $className;
                } else {
                    throw new ModuleConfigException("Missing 'module_code' in module.php at '{$this->containerPath}'.");
                }

                $this->description = \trim($moduleConfig['description'] ?? '');
            }

            $this->author = \trim($moduleConfig['author'] ?? ($this->standalone ? 'standalone' : ''));
            if (!$this->author && !$this->standalone) {
                throw new ModuleConfigException("Missing 'author' for module '{$this->code}' at '{$this->containerPath}'.");
            }

            $this->alias = \trim($settings['alias'] ?? '');
            if (empty($this->alias)) {
                // Default the alias to the class name when not explicitly set
                $this->alias = $this->className;
            }

            // Map asset paths from package.php to their resolved filesystem locations
            if (!\is_array($settings['assets'] ??= [])) {
                $settings['assets'] = [];
            }
            foreach ($settings['assets'] as $asset => $destPath) {
                // Resolve and validate the asset path relative to the module directory
                $assetPath = PathUtil::fixPath(PathUtil::append($this->modulePath, $asset), DIRECTORY_SEPARATOR, true);
                if (false !== $assetPath) {
                    $this->assets[$destPath] = [
                        'path' => $asset,
                        'system_path' => \realpath($assetPath),
                    ];
                }
            }

            // Collect prerequisite package constraints (e.g., external dependencies)
            if (!\is_array($settings['prerequisite'] ??= [])) {
                $settings['prerequisite'] = [];
            }
            foreach ($settings['prerequisite'] as $package => $version) {
                if (\is_string($package) && ($version)) {
                    $this->prerequisite[$package] = $version;
                }
            }

            // Parse and validate the API name for inter-module API registration
            $this->apiName = \trim($settings['api_name'] ?? '');
            if (\strlen($this->apiName) > 0) {
                // API name must start with a letter followed by word characters
                if (!\preg_match('/^[a-z]\w*$/i', $this->apiName)) {
                    throw new ModuleConfigException("Invalid API name '{$this->apiName}' for module '{$this->code}'. Must start with a letter followed by word characters (a-z, 0-9, _).");
                }
            }

            // Shadow asset mode: symlink assets instead of copying (disabled for phar archives)
            $settings['shadow_asset'] ??= false;
            $this->shadowAsset = !!$settings['shadow_asset'] && !\preg_match('/^phar:\/\//', $this->modulePath);

            if (isset($settings['require']) && \is_array($settings['require'])) {
                // Parse explicit module dependencies with version constraints
                foreach ($settings['require'] as $moduleCode => $version) {
                    $moduleCode = \trim($moduleCode);
                    // Validate the required module code format and version string
                    if (\preg_match(self::REGEX_MODULE_CODE, $moduleCode) && \is_string($version)) {
                        $this->require[$moduleCode] = \trim($version);
                    }
                }

                // Auto-add implicit parent namespace dependencies (e.g., vendor/parent for vendor/parent/child)
                if (\count($namespaces)) {
                    $requireNamespace = $vendor;
                    foreach ($namespaces as $namespace) {
                        $requireNamespace .= '/' . $namespace;
                        // Only add if not explicitly declared in the require list
                        if (!isset($this->require[$requireNamespace])) {
                            $this->require[$requireNamespace] = '*';
                        }
                    }
                }
            }

            // Load module-level DI service bindings (abstract → concrete class)
            // These are registered in the module's child container during initialization
            if (isset($settings['services']) && \is_array($settings['services'])) {
                foreach ($settings['services'] as $abstract => $concrete) {
                    if (\is_string($abstract) && \is_string($concrete)) {
                        $this->services[$abstract] = $concrete;
                    }
                }
            }

            // Load per-module metadata for inter-module communication
            // Metadata entries are keyed by target module code in package.php
            if (isset($settings['metadata']) && \is_array($settings['metadata'])) {
                foreach ($settings['metadata'] as $moduleCode => $variables) {
                    // Validate module code format; only accept array values
                    if (\preg_match(self::REGEX_MODULE_CODE, $moduleCode) && \is_array($variables)) {
                        $this->moduleMetadata[$moduleCode] = $variables;
                    }
                }
            }
        } else {
            throw new ModuleConfigException("The module folder does not exist: '{$this->containerPath}'.");
        }
    }

    /**
     * Get the module class name.
     *
     * @return string
     */
    public function getClassName(): string
    {
        return $this->className;
    }

    /**
     * Check if this module is a standalone/lite module.
     *
     * @return bool
     */
    public function isStandalone(): bool
    {
        return $this->standalone;
    }

    /**
     * Get the module file path.
     *
     * @param bool $relative
     *
     * @return string
     */
    public function getPath(bool $relative = false): string
    {
        return ($relative) ? $this->relativePath : $this->modulePath;
    }

    /**
     * Get the API name.
     *
     * @return string
     */
    public function getAPIName(): string
    {
        return $this->apiName;
    }

    /**
     * Get the prerequisite list.
     *
     * @return array
     */
    public function getPrerequisite(): array
    {
        return $this->prerequisite;
    }

    /**
     * Get the module alias.
     *
     * @return string
     */
    public function getAlias(): string
    {
        return $this->alias;
    }

    /**
     * Return the module description.
     *
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Return the module author.
     *
     * @return string
     */
    public function getAuthor(): string
    {
        return $this->author;
    }

    /**
     * Get the module code.
     *
     * @return string
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * Get the module container path.
     *
     * @param bool $isRelative
     *
     * @return string
     */
    public function getContainerPath(bool $isRelative = false): string
    {
        return ($isRelative) ? PathUtil::getRelativePath($this->containerPath, SYSTEM_ROOT) : $this->containerPath;
    }

    /**
     * Get the `require` list of the module.
     *
     * @return array
     */
    public function getRequire(): array
    {
        return $this->require;
    }

    /**
     * Check if the module is shadow asset mode.
     *
     * @return bool
     */
    public function isShadowAsset(): bool
    {
        return $this->shadowAsset;
    }

    /**
     * Return the module version.
     *
     * @return string
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Return true if the module is a shared module.
     *
     * @return bool
     */
    public function isShared(): bool
    {
        return $this->sharedModule;
    }

    /**
     * Return turn if the module is a phar archive.
     *
     * @return bool
     */
    public function isPharArchive(): bool
    {
        return $this->pharArchive;
    }

    /**
     * Return the asset list.
     *
     * @return array
     */
    public function getAssets(): array
    {
        return $this->assets;
    }

    /**
     * Get the module-level DI service bindings declared in package.php.
     *
     * These bindings (abstract → concrete class name) are automatically
     * registered in the module's child container during initialization,
     * providing module-level contextual binding.
     *
     * @return array<string, string> Abstract → concrete class mappings
     */
    public function getServices(): array
    {
        return $this->services;
    }

    /**
     * Reload the module's package.php to refresh services and metadata.
     *
     * Re-reads and parses the package.php file from disk, updating the
     * services and metadata arrays without reconstructing the entire
     * ModuleInfo object. This is used during worker-mode hot-swap to
     * pick up config-only changes.
     *
     * @return bool True if reload succeeded, false on error
     */
    public function reloadConfig(): bool
    {
        try {
            $packagePath = PathUtil::append($this->modulePath, 'package.php');
            $settings = $this->configLoader->loadRaw($packagePath);
            if (!\is_array($settings)) {
                return false;
            }

            // Reload service bindings
            $this->services = [];
            if (isset($settings['services']) && \is_array($settings['services'])) {
                foreach ($settings['services'] as $abstract => $concrete) {
                    if (\is_string($abstract) && \is_string($concrete)) {
                        $this->services[$abstract] = $concrete;
                    }
                }
            }

            // Reload metadata
            $this->moduleMetadata = [];
            if (isset($settings['metadata']) && \is_array($settings['metadata'])) {
                foreach ($settings['metadata'] as $moduleCode => $variables) {
                    if (\preg_match(self::REGEX_MODULE_CODE, $moduleCode) && \is_array($variables)) {
                        $this->moduleMetadata[$moduleCode] = $variables;
                    }
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Get the webasset URL path segment for this module.
     *
     * Returns the relative path "webassets/{alias}/{version}/" used to
     * build the full asset URL when combined with the site URL.
     *
     * @return string The webasset URL path (e.g. "webassets/MyModule/1.0.0/")
     */
    public function getAssetPath(): string
    {
        return PathUtil::append('webassets', $this->alias, $this->version) . '/';
    }

    /**
     * Get module metadata - only accessible by passing another ModuleInfo object for verification.
     * This ensures only modules can access sensitive module-to-module communication data.
     * Metadata is stored per module under its package name in package.php, organized by requester code.
     *
     * @param ModuleInfo $requesterInfo The ModuleInfo object of the requesting module (for access verification)
     * @param string|null $key Optional specific metadata key to retrieve. If null, returns all metadata for the requester.
     *
     * @return mixed The metadata array or specific metadata value. Returns null if not found.
     */
    public function getMetadata(self $requesterInfo, ?string $key = null): mixed
    {
        // Derive the requester's module code for metadata lookup
        $requesterCode = $requesterInfo->getCode();

        // Check if any metadata was declared for this specific requester
        if (!isset($this->moduleMetadata[$requesterCode])) {
            return null;
        }

        // Return all metadata for the requester, or a specific key if provided
        if ($key === null) {
            return $this->moduleMetadata[$requesterCode];
        }

        return $this->moduleMetadata[$requesterCode][$key] ?? null;
    }
}
