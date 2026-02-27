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

namespace Razy;

use Razy\Config\ConfigLoader;
use Razy\Contract\ContainerInterface;
use Razy\Contract\DistributorInterface;
use Razy\Distributor\ModuleRegistry;
use Razy\Distributor\ModuleScanner;
use Razy\Distributor\PrerequisiteResolver;
use Razy\Distributor\RouteDispatcher;
use Razy\Exception\ConfigurationException;
use Razy\Module\ModuleStatus;
use Razy\Util\NetworkUtil;
use Razy\Util\PathUtil;
use Throwable;

/**
 * Class Distributor.
 *
 * Core routing and module management engine for a Razy site. A Distributor represents
 * a single site distribution (identified by code + tag), responsible for:
 * - Scanning, loading, and initializing modules from the configured source path
 * - Managing module lifecycle (pending -> processing -> loaded) with dependency resolution
 * - Route registration, matching, and dispatching (standard, lazy, shadow, CLI scripts)
 * - Cross-module API communication and event emission
 * - Session management, data path resolution, and package prerequisite validation
 *
 * @class Distributor
 */
class Distributor implements DistributorInterface
{
    /** @var string The distribution code from dist.php configuration */
    private string $code = '';

    /** @var array<string, string|null> Required module codes mapped to their version constraints */
    private array $requires = [];

    /** @var bool Whether to also scan the shared/module folder for global modules */
    private bool $globalModule = false;

    /** @var bool Strict mode: throw errors for missing closure/route files */
    private bool $strict = false;

    /** @var string Filesystem path to the distributor's configuration folder (sites/) */
    private string $folderPath = '';

    /** @var string Filesystem path where modules are loaded from (may differ from folderPath) */
    private string $moduleSourcePath = '';

    /** @var Template|null Lazy-initialized global Template instance shared across modules */
    private ?Template $globalTemplate = null;

    /** @var array<string, array{domain: string, dist: string}> Cross-site data mapping configuration */
    private array $dataMapping = [];

    /** @var bool Whether unmatched requests fall back to index.php for route matching */
    private bool $fallback = true;

    /** @var bool Whether the full module lifecycle has completed (matchRoute ran) */
    private bool $coreInitialized = false;

    // --- Extracted sub-objects (Phase 2 refactoring) ---

    /** @var ModuleRegistry Module tracking, lookup, API registration, and lifecycle management */
    private ModuleRegistry $registry;

    /** @var ModuleScanner Filesystem scanning, manifest caching, module autoloading */
    private ModuleScanner $scanner;

    /** @var RouteDispatcher Route registration, matching, and dispatching */
    private RouteDispatcher $router;

    /** @var PrerequisiteResolver Package prerequisite tracking and conflict detection */
    private PrerequisiteResolver $prerequisites;

    /** @var ConfigLoader Configuration file loader (injectable for testing) */
    private ConfigLoader $configLoader;

    /**
     * Distributor constructor.
     *
     * @param string $distCode The folder path of the distributor
     * @param string $tag The distributor tag
     * @param Domain|null $domain The Domain Instance
     * @param string $urlPath The URL path of the distributor
     * @param string $urlQuery The URL Query string
     * @param ConfigLoader|null $configLoader Optional config loader (defaults to new ConfigLoader)
     *
     * @throws Throwable
     */
    public function __construct(private string $distCode, private readonly string $tag = '*', private readonly ?Domain $domain = null, private readonly string $urlPath = '', private string $urlQuery = '/', ?ConfigLoader $configLoader = null)
    {
        $this->configLoader = $configLoader ?? new ConfigLoader();

        $this->folderPath = PathUtil::append(SITES_FOLDER, $distCode);
        $this->urlQuery = PathUtil::tidy($this->urlQuery, false, '/');

        if (!$this->urlQuery) {
            $this->urlQuery = '/';
        }

        $config = $this->loadAndValidateConfig();
        $this->parseModuleRequirements($config);
        $this->parseDataMappings($config);
        $this->resolveModuleSourcePath($config);

        $this->globalModule = (bool) ($config['global_module'] ?? false);
        $this->strict = (bool) ($config['strict'] ?? false);
        $this->fallback = (bool) ($config['fallback'] ?? true);

        $this->initializeSubComponents((bool) ($config['autoload'] ?? false));
    }

    /**
     * Initial Distributor and scan the module folder.
     *
     * @return $this
     *
     * @throws Error
     * @throws Throwable
     */
    public function initialize(bool $initialOnly = false): static
    {
        // Load all modules into the pending list from the configured module source path
        $modules = &$this->registry->getModulesRef();

        $this->scanner->scan($this->moduleSourcePath, false, $this->requires, $modules);
        if ($this->globalModule) {
            $this->scanner->scan(PathUtil::append(SHARED_FOLDER, 'module'), true, $this->requires, $modules);
        }

        // Put all modules into queue by priority with its require module.
        foreach ($this->registry->getModules() as $module) {
            $this->require($module);
        }

        // Only trigger __onInit stage
        if ($initialOnly) {
            return $this;
        }

        // Preparation Stage, __onLoad event
        $this->registry->setQueue(\array_filter($this->registry->getQueue(), function (Module $module) {
            // Remove modules that fail the preparation/load phase
            if (!$module->prepare()) {
                return false;
            }
            return true;
        }));

        // Validation Stage, __onRequire event
        $this->registry->setQueue(\array_filter($this->registry->getQueue(), function (Module $module) {
            // Remove modules that fail validation (e.g., missing dependencies)
            if (!$module->validate()) {
                return false;
            }

            return true;
        }));

        return $this;
    }

    /**
     * Autoload the class from the distributor's module library folder.
     *
     * @param string $className
     *
     * @return bool
     */
    public function autoload(string $className): bool
    {
        return $this->scanner->autoload($className, $this->registry->getModules(), $this->code);
    }

    /**
     * Get the distributor data folder file path, the path contains site config and file storage.
     *
     * @param string $module
     * @param bool $isURL
     *
     * @return string
     */
    public function getDataPath(string $module = '', bool $isURL = false): string
    {
        if ($isURL) {
            return PathUtil::append($this->getSiteURL(), 'data', $module);
        }
        return PathUtil::append(DATA_FOLDER, $this->getIdentity(), $module);
    }

    /**
     * Get the distributor identity, the identity contains the domain and its code.
     *
     * @return string
     */
    public function getIdentity(): string
    {
        if (!$this->domain) {
            return $this->code;
        }
        return $this->domain->getDomainName() . '-' . $this->code;
    }

    /**
     * Set the cookie path and start the session.
     *
     * @return $this
     */
    public function setSession(): static
    {
        if (WEB_MODE) {
            \session_set_cookie_params(0, '/', HOSTNAME);
            \session_name($this->code);
            \session_start();
        }

        return $this;
    }

    /**
     * Match the registered route and execute the matched path.
     *
     * @return bool
     *
     * @throws Throwable
     */
    public function matchRoute(): bool
    {
        if (!$this->domain) {
            return false;
        }

        $this->setSession();

        // Execute all await functions and notify ready
        $this->registry->processAwaits();
        $this->registry->notifyReady();

        $result = $this->router->matchRoute($this->urlQuery, $this->getSiteURL(), $this->registry);

        // Mark core as initialized — the full module lifecycle has completed.
        // This flag gates access to the lightweight dispatch() fast path.
        $this->coreInitialized = true;

        return $result;
    }

    /**
     * Lightweight route dispatch for worker mode (skips lifecycle overhead).
     *
     * Bypasses session_start(), processAwaits(), and notifyReady() — all of
     * which are only needed on the first request when the module graph is
     * being assembled. On subsequent worker requests the object graph is
     * already fully initialised, so we go straight to route matching.
     *
     * @param string $urlQuery The URL query to dispatch
     *
     * @return bool True if a matching route was found and executed
     *
     * @throws Throwable
     */
    public function dispatch(string $urlQuery): bool
    {
        if (!$this->domain) {
            return false;
        }

        // Guard: worker-only fast path — prevents bypassing the full module
        // lifecycle (initialize → matchRoute) which registers routes and
        // middleware. Without this, an attacker could call dispatch() directly
        // to inject routes or hijack module closures.
        if (!\defined('WORKER_MODE') || !WORKER_MODE) {
            throw new ConfigurationException(
                'Distributor::dispatch() is restricted to worker mode. Use matchRoute() for standard requests.',
            );
        }

        if (!$this->coreInitialized) {
            throw new ConfigurationException(
                'Core not initialized. The full module lifecycle (matchRoute) must complete before worker dispatch.',
            );
        }

        $this->urlQuery = PathUtil::tidy($urlQuery, false, '/');
        if (!$this->urlQuery) {
            $this->urlQuery = '/';
        }

        // Reset stale route metadata from previous request
        $this->router->resetRoutedInfo();

        return $this->router->matchRoute($this->urlQuery, $this->getSiteURL(), $this->registry);
    }

    /**
     * Compute a fingerprint of the distributor's configuration state.
     *
     * Used by Domain's distributor cache to detect whether the dist.php config
     * or module files have changed on disk since the distributor was built.
     * When the fingerprint changes, the cached distributor is invalidated and
     * rebuilt from scratch (hot-reload).
     *
     * The fingerprint covers:
     * - dist.php file modification time
     * - Module source directory modification time
     * - Shared module directory modification time (if global_module is enabled)
     *
     * @return string MD5 hash representing current config state
     */
    public function getConfigFingerprint(): string
    {
        $parts = [];

        // dist.php mtime
        $distFile = PathUtil::append($this->folderPath, 'dist.php');
        if (\is_file($distFile)) {
            $parts[] = (string) \filemtime($distFile);
        }

        // Module source directory mtime (catches added/removed module folders)
        if (\is_dir($this->moduleSourcePath)) {
            $parts[] = (string) \filemtime($this->moduleSourcePath);
        }

        // Shared module directory mtime (if global modules enabled)
        if ($this->globalModule && \defined('SHARED_FOLDER')) {
            $sharedModulePath = PathUtil::append(SHARED_FOLDER, 'module');
            if (\is_dir($sharedModulePath)) {
                $parts[] = (string) \filemtime($sharedModulePath);
            }
        }

        return \md5(\implode('|', $parts));
    }

    /**
     * Check if global module loading is enabled for this distributor.
     *
     * @return bool
     */
    public function isGlobalModule(): bool
    {
        return $this->globalModule;
    }

    /**
     * Execute an internal API call via CLI process.
     * Uses Razy.phar bridge command for isolated execution to avoid class namespace conflicts.
     *
     * @param string $moduleCode The target module code
     * @param string $command The API command to execute
     * @param array $args Arguments to pass to the command
     *
     * @return mixed The result from the module's API command, or null if proc_open is unavailable
     *
     * @throws ConfigurationException If the module is not available or the CLI bridge process fails
     */
    public function executeInternalAPI(string $moduleCode, string $command, array $args = []): mixed
    {
        $module = $this->registry->getLoadedAPIModule($moduleCode);
        if (!$module) {
            throw new ConfigurationException("Module '$moduleCode' not available.");
        }

        // Check if proc_open is available for CLI process isolation
        if (!\function_exists('proc_open') || \in_array('proc_open', \explode(',', \ini_get('disable_functions')), true)) {
            return null;
        }

        // Execute via CLI bridge for process isolation
        $pharFile = SYSTEM_ROOT . DIRECTORY_SEPARATOR . PHAR_FILE;

        // Serialize API call data and spawn an isolated CLI bridge process
        $payload = \json_encode([
            'dist' => $this->code,
            'module' => $module->getModuleInfo()->getCode(),
            'command' => $command,
            'args' => $args,
        ]);

        // Open process with stdout and stderr pipes for capturing output
        $process = \proc_open(
            [PHP_BINARY, $pharFile, 'bridge', $payload],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (!\is_resource($process)) {
            throw new ConfigurationException('Failed to spawn CLI bridge process');
        }

        // Read process output, close pipes, and parse the JSON response
        $output = \stream_get_contents($pipes[1]);
        $error = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        \proc_close($process);

        $response = \json_decode($output, true);
        if (!\is_array($response)) {
            throw new ConfigurationException('Invalid CLI bridge response: ' . ($error ?: $output));
        }

        if (!($response['ok'] ?? false)) {
            throw new ConfigurationException($response['error'] ?? 'Unknown CLI bridge error');
        }

        return $response['data'] ?? null;
    }

    /**
     * Get the distributor code.
     *
     * @return string
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * Get the Domain instance that owns this Distributor.
     *
     * @return Domain|null
     */
    public function getDomain(): ?Domain
    {
        return $this->domain;
    }

    /**
     * Get the DI container from the Application via the Domain chain.
     *
     * @return ContainerInterface|null The Container instance, or null if no Domain is set
     */
    public function getContainer(): ?ContainerInterface
    {
        return $this->domain?->getApplication()->getContainer();
    }

    /**
     * Get the ModuleRegistry sub-object for direct module management access.
     *
     * @return ModuleRegistry
     */
    public function getRegistry(): ModuleRegistry
    {
        return $this->registry;
    }

    /**
     * Get the RouteDispatcher sub-object for direct route management access.
     *
     * @return RouteDispatcher
     */
    public function getRouter(): RouteDispatcher
    {
        return $this->router;
    }

    /**
     * Get the PrerequisiteResolver sub-object for package prerequisite management.
     *
     * @return PrerequisiteResolver
     */
    public function getPrerequisites(): PrerequisiteResolver
    {
        return $this->prerequisites;
    }

    /**
     * Get the ModuleScanner sub-object for filesystem scanning access.
     *
     * @return ModuleScanner
     */
    public function getScanner(): ModuleScanner
    {
        return $this->scanner;
    }

    /**
     * Get the distributor tag (e.g., 'dev', '1.0.0', 'default', '*')
     * The tag can be a label (dev, prod), a version (1.0.0), or 'default'.
     *
     * @return string
     */
    public function getTag(): string
    {
        return $this->tag;
    }

    /**
     * Get the full distributor identifier in format "code@tag"
     * For cross-distributor communication, this uniquely identifies
     * the specific distributor instance.
     *
     * Examples:
     * - "siteB@dev" - dev tag
     * - "siteA@1.0.0" - version as tag
     * - "siteC@default" - default tag
     *
     * @param bool $shorthand If true, omit @default suffix (returns "siteB" instead of "siteB@default")
     *
     * @return string
     */
    public function getIdentifier(bool $shorthand = false): string
    {
        if ($shorthand && $this->tag === 'default') {
            return $this->code;
        }
        return $this->code . '@' . $this->tag;
    }

    /**
     * Get the distributor configuration folder path (where dist.php is located).
     * This is always in the sites/ directory.
     *
     * @return string
     */
    public function getFolderPath(): string
    {
        return $this->folderPath;
    }

    /**
     * Get the module source path (where modules are loaded from).
     * This can be different from folderPath when module_path is configured in dist.php.
     * Useful for organizing modules in a shared location within the project.
     *
     * @return string
     */
    public function getModuleSourcePath(): string
    {
        return $this->moduleSourcePath;
    }

    /**
     * Check if a custom module source path is configured.
     *
     * @return bool
     */
    public function hasCustomModuleSourcePath(): bool
    {
        return $this->moduleSourcePath !== $this->folderPath;
    }

    /**
     * Check if strict mode is enabled.
     * In strict mode, missing closure/route files throw errors instead of returning null.
     *
     * @return bool
     */
    public function isStrict(): bool
    {
        return $this->strict;
    }

    /**
     * Get the distributor URL path.
     *
     * @return string
     */
    public function getSiteURL(): string
    {
        return (\defined('RAZY_URL_ROOT')) ? PathUtil::append(RAZY_URL_ROOT, $this->urlPath) : '';
    }

    /**
     * Get the data mapping.
     *
     * @return array
     */
    public function getDataMapping(): array
    {
        return $this->dataMapping;
    }

    /**
     * Check if fallback routing to index.php is enabled.
     *
     * When enabled, unmatched requests under this distributor's path prefix are forwarded
     * to index.php for PHP route matching. When disabled, unmatched requests that do not
     * correspond to an existing file or directory will return 404 at the server level.
     *
     * @return bool
     */
    public function getFallback(): bool
    {
        return $this->fallback;
    }

    /**
     * Get the initialized global Template entity.
     *
     * @return Template
     */
    public function getGlobalTemplateEntity(): Template
    {
        if (!$this->globalTemplate) {
            $this->globalTemplate = new Template();
        }

        return $this->globalTemplate;
    }

    /**
     * Load dist.php, validate the dist code format, and return the config array.
     *
     * @return array The validated distributor configuration
     *
     * @throws ConfigurationException
     */
    private function loadAndValidateConfig(): array
    {
        if (!$configFile = \realpath(PathUtil::append($this->folderPath, 'dist.php'))) {
            throw new ConfigurationException("Missing distributor configuration file: '{$this->folderPath}/dist.php'.");
        }

        $config = $this->configLoader->load($configFile);

        if (!($config['dist'] ??= '')) {
            throw new ConfigurationException('The distributor code is empty.');
        }

        if (!\preg_match('/^[a-z][\w\-]*$/i', $config['dist'])) {
            throw new ConfigurationException("Invalid distribution code '{$config['dist']}' in '{$configFile}'. Must match pattern /^[a-z][\\w\\-]*$/i.");
        }
        $this->code = $config['dist'];

        return $config;
    }

    /**
     * Extract module requirements from config for the current tag.
     *
     * @param array $config The distributor configuration
     */
    private function parseModuleRequirements(array $config): void
    {
        $config['modules'] ??= [];
        if (\is_array($config['modules'])) {
            if (isset($config['modules'][$this->tag])) {
                foreach ($config['modules'][$this->tag] as $moduleCode => $version) {
                    $moduleCode = \trim($moduleCode);
                    $version = \trim($version);
                    if (\strlen($moduleCode) > 0 && \strlen($version) > 0) {
                        $this->requires[$moduleCode] = null;
                    }
                }
            }
        }
    }

    /**
     * Parse cross-site data_mapping config into $this->dataMapping.
     *
     * @param array $config The distributor configuration
     */
    private function parseDataMappings(array $config): void
    {
        $config['data_mapping'] ??= [];
        if (\is_array($config['data_mapping'])) {
            foreach ($config['data_mapping'] as $path => $site) {
                if (\is_string($site)) {
                    [$domain, $dist] = \explode(':', $site . ':');
                    if (NetworkUtil::isFqdn($domain) && \preg_match('/^[a-z][\w\-]*$/i', $dist)) {
                        $this->dataMapping[$path] = [
                            'domain' => $domain,
                            'dist' => $dist,
                        ];
                    }
                }
            }
        }
    }

    /**
     * Validate and resolve module_path from config, or fall back to the distributor folder.
     *
     * @param array $config The distributor configuration
     *
     * @throws ConfigurationException
     */
    private function resolveModuleSourcePath(array $config): void
    {
        $customModulePath = $config['module_path'] ?? '';
        if (\is_string($customModulePath) && \strlen(\trim($customModulePath)) > 0) {
            $customModulePath = PathUtil::fixPath($customModulePath);
            if (!\is_dir($customModulePath)) {
                throw new ConfigurationException('The custom module_path does not exist: ' . $customModulePath);
            }

            // Validate that the path is within SYSTEM_ROOT
            $realCustomPath = \realpath($customModulePath);
            $realSystemRoot = \realpath(SYSTEM_ROOT);
            if ($realCustomPath === false || $realSystemRoot === false || !\str_starts_with($realCustomPath, $realSystemRoot)) {
                throw new ConfigurationException('The module_path must be inside the Razy project folder (SYSTEM_ROOT): ' . $customModulePath);
            }

            $this->moduleSourcePath = $customModulePath;
        } else {
            $this->moduleSourcePath = $this->folderPath;
        }
    }

    /**
     * Create ModuleRegistry, ModuleScanner, RouteDispatcher, PrerequisiteResolver
     * and register this Distributor in the DI container.
     *
     * @param bool $autoload Whether module autoloading is enabled
     */
    private function initializeSubComponents(bool $autoload): void
    {
        $this->registry = new ModuleRegistry($this, $autoload);
        $this->scanner = new ModuleScanner($this);
        $this->router = new RouteDispatcher();
        $this->prerequisites = new PrerequisiteResolver($this->distCode, $this);

        if ($container = $this->getContainer()) {
            $container->instance(self::class, $this);
        }
    }

    /**
     * Start to activate the module.
     *
     * @param Module $module
     *
     * @return bool
     *
     * @throws Throwable
     */
    private function require(Module $module): bool
    {
        if ($this->registry->isLoadable($module)) {
            if ($module->getStatus() === ModuleStatus::Pending) {
                $module->standby();
            }

            // Recursively resolve all required dependencies before initializing this module
            $requireModules = $module->getModuleInfo()->getRequire();
            foreach ($requireModules as $moduleCode => $version) {
                $reqModule = $this->registry->get($moduleCode);
                if (!$reqModule) {
                    return false;
                }

                if ($reqModule->getStatus() === ModuleStatus::Pending) {
                    if (!$this->require($reqModule)) {
                        return false;
                    }
                } elseif ($reqModule->getStatus() === ModuleStatus::Failed) {
                    return false;
                }
            }

            if ($module->getStatus() === ModuleStatus::Processing) {
                if (!$module->initialize()) {
                    $module->unload();
                    return false;
                }
                $this->registry->enqueue($module->getModuleInfo()->getCode(), $module);
            }
        }

        return ($module->getStatus() === ModuleStatus::InQueue);
    }
}
