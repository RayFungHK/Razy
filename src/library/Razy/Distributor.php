<?php

/**
 * This file is part of Razy v0.4.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy;

use Closure;
use Exception;
use Phar;
use PharException;
use Throwable;

class Distributor
{
    /**
     * @var Distributor[]
     */
    private static array $stacking = [];

    /**
     * @var string
     */
    private string $urlPath;

    /**
     * @var string
     */
    private string $folderPath;

    /**
     * @var string
     */
    private string $urlQuery;

    /**
     * @var string
     */
    private string $code;

    /**
     * @var null|Domain
     */
    private ?Domain $domain;

    /**
     * @var array
     */
    private array $lazyRoute = [];

    /**
     * @var array
     */
    private array $regexRoute = [];

    /**
     * @var Module[]
     */
    private array $modules = [];

    /**
     * @var array
     */
    private array $enableModules = [];

    /**
     * @var bool
     */
    private bool $greedy = false;

    /**
     * @var bool
     */
    private bool $autoloadShared = false;

    /**
     * @var array
     */
    private array $enabledModule = [];

    /**
     * @var array
     */
    private array $prerequisites = [];

    /**
     * @var ?Template
     */
    private ?Template $templateEngine = null;

    /**
     * @var null|string
     */
    private ?string $redirect = null;

    /**
     * @var string
     */
    private string $routedPath = '';

    /**
     * Distributor constructor.
     *
     * @param string $folderPath The folder path of the distributor
     * @param null|Domain $domain The Domain Instance
     * @param string $urlPath The URL path of the distributor
     * @param string $urlQuery The URL Query string
     *
     * @throws Throwable
     */
    public function __construct(string $folderPath, ?Domain $domain = null, string $urlPath = '', string $urlQuery = '/')
    {
        $this->folderPath = append(SITES_FOLDER, $folderPath);
        $this->urlPath    = $urlPath;
        $this->urlQuery   = tidy($urlQuery, false, '/');
        if (!$this->urlQuery) {
            $this->urlQuery = '/';
        }

        $this->domain = $domain;
        if (!$configFile = realpath(append($this->folderPath, 'dist.php'))) {
            throw new Error('Missing distributor configuration file (dist.php).');
        }

        $config = require $configFile;

        if (!($config['dist'] = $config['dist'] ?? '')) {
            throw new Error('The distributor code is empty.');
        }
        if (!preg_match('/^[a-z]\w*$/i', $config['dist'])) {
            throw new Error('Invalid distribution code.');
        }
        $this->code = $config['dist'];

        // Put the main distributor into the stacking list
        self::$stacking[] = $this;

        // Not allow calling API if it has no domain
        if ($this->domain) {
            // If this distributor is accessed by internal API call, check the whitelist
            if (($peer = $this->domain->getPeer()) !== null) {
                // Remove self
                array_pop(self::$stacking);

                $peerDomain       = $peer->getDomain()->getDomainName();
                $acceptConnection = false;
                // Load the external config in data path by its distribution folder: $this->getDataPath()
                foreach ($config['whitelist'] ?? [] as $whitelist) {
                    if (is_fqdn($whitelist)) {
                        if ('*' === $whitelist) {
                            $acceptConnection = true;

                            break;
                        }
                        if (false !== strpos($whitelist, '*')) {
                            $whitelist = preg_replace('/\\\\.(*SKIP)(*FAIL)|\*/', '[^.]+', $whitelist);
                        }

                        if (preg_match('/^' . $whitelist . '$/', $peerDomain)) {
                            $acceptConnection = true;

                            break;
                        }
                    }
                }

                if (!$acceptConnection) {
                    throw new Error($peerDomain . ' is not allowed to access to ' . $domain->getDomainName() . '::' . $this->getDistCode());
                }
            }
        }

        $config['enable_module'] = $config['enable_module'] ?? [];
        if (is_array($config['enable_module'])) {
            foreach ($config['enable_module'] as $moduleCode => $version) {
                $moduleCode = trim($moduleCode);
                $version    = trim($version);
                if (strlen($moduleCode) > 0 && strlen($version) > 0) {
                    $this->enableModules[$moduleCode] = $version;
                }
            }
        }

        $config['autoload_shared'] = (bool)($config['autoload_shared'] ?? false);
        $this->autoloadShared      = $config['autoload_shared'];

        $this->greedy = (bool)($config['greedy'] ?? false);

        // Load all modules into the pending list
        $this->loadModule($this->folderPath);

        if ($config['autoload_shared']) {
            // Load shared modules into the pending list
            $this->loadSharedModule(append(SHARED_FOLDER, 'module'));
        }

        // Initialize all modules in the pending list
        foreach ($this->modules as $module) {
            // Check the module is in enable list and activate it
            if ((!$this->greedy && !array_key_exists($module->getCode(), $this->enableModules)) || !$this->activate($module)) {
                $module->unload();
            } else {
                $module->ready();
            }
        }

        $preloadQueue = [];
        // Validate all modules
        foreach ($this->modules as $module) {
            if (!$module->validate()) {
                $preloadQueue[] = $module;
            }
        }

        // Preload all modules, if the module preload event return false, the preload queue will stop and not enter the routing stage.
        $readyToRoute = true;
        if (!empty($preloadQueue)) {
            foreach ($preloadQueue as $module) {
                if (!$module->preload()) {
                    $readyToRoute = false;
                    break;
                }
            }
        }

        if (WEB_MODE) {
            $this->setSession();

            if ($readyToRoute) {
                // If the distributor is under a domain, initial all modules.
                if ($this->domain) {
                    foreach ($this->modules as $module) {
                        // Notify module that the system is ready
                        $module->notify();
                    }
                }

                // Convert the regex route
                $regexRoute       = $this->regexRoute;
                $this->regexRoute = [];

                sort_path_level($regexRoute);
                foreach ($regexRoute as $route => $data) {
                    $route = tidy($route, true, '/');
                    $route = preg_replace_callback('/\\\\.(*SKIP)(*FAIL)|:(?:([awdWD])|(\[[^\\[\\]]+]))({\d+,?\d*})?/', function ($matches) {
                        if (strlen($matches[2] ?? '') > 0) {
                            $regex = $matches[2];
                        } else {
                            $regex = ('a' === $matches[1]) ? '[^/]' : '\\' . $matches[1];
                        }

                        $regex .= (0 !== strlen($matches[3] ?? '')) ? $matches[3] : $regex .= '+';

                        return $regex;
                    }, $route);

                    $data['route']            = $route;
                    $route                    = '/^' . preg_replace('/\\\\.(*SKIP)(*FAIL)|\//', '\\/', $route) . '$/';
                    $this->regexRoute[$route] = $data;
                }
            }
        }
    }

    /**
     * @param string $package
     * @param string $version
     *
     * @return $this
     */
    public function prerequisite(string $package, string $version): Distributor
    {
        $package = trim($package);
        $version = trim($version);
        if (isset($this->prerequisites[$package])) {
            if ('*' != $version) {
                $this->prerequisites[$package] .= ',' . $version;
            }
        } else {
            $this->prerequisites[$package] = $version;
        }

        return $this;
    }

    /**
     * Get the distributor code.
     *
     * @return string
     */
    public function getDistCode(): string
    {
        return $this->code;
    }

    /**
     * @param string $className
     *
     * @return bool
     */
    public static function SPLAutoload(string $className): bool
    {
        if (!empty(self::$stacking)) {
            $distributor = end(self::$stacking);

            return $distributor->autoload($className);
        }

        return false;
    }

    /**
     * @param string $className
     *
     * @return bool
     */
    public function autoload(string $className): bool
    {
        if (preg_match('/^Razy\\\\(?:Module\\\\' . $this->code . '|Shared)\\\\([^\\\\]+)\\\\(.+)/', $className, $matches)) {
            $moduleCode = $matches[1];
            $className  = $matches[2];
            // Try to load the class from the module library
            if (isset($this->modules[$moduleCode])) {
                $module = $this->modules[$moduleCode];
                $path   = append($module->getPath(), 'library');
                if (is_dir($path)) {
                    $libraryPath = append($path, $className . '.php');
                    if (is_file($libraryPath)) {
                        try {
                            include $libraryPath;

                            return class_exists($className);
                        } catch (Exception $e) {
                            return false;
                        }
                    }
                }
            }
        }

        // TODO: Load the class in autoload folder
        $libraryPath = append(SYSTEM_ROOT, 'autoload', $this->code);

        return autoload($className, $libraryPath);
    }

    /**
     * Check if the distributor allow load the module in shared folder.
     *
     * @return bool
     */
    public function allowAutoloadShared(): bool
    {
        return $this->autoloadShared;
    }

    /**
     * Set the cookie path and start the session.
     *
     * @return $this
     */
    public function setSession(): self
    {
        session_set_cookie_params(0, '/', HOSTNAME);
        session_name($this->code);
        session_start();

        return $this;
    }

    /**
     * Get the distributor data folder file path, the path contains site config and file storage.
     *
     * @return string
     */
    public function getDataPath(): string
    {
        return append(SHARED_FOLDER, $this->getIdentity());
    }

    /**
     * Get the distributor identity, the identity contains the domain and its code.
     *
     * @return string
     */
    public function getIdentity(): string
    {
        return $this->domain->getDomainName() . '-' . $this->code;
    }

    /**
     * Get the distributor URL path.
     *
     * @return string
     */
    public function getURLPath(): string
    {
        return (defined('RAZY_URL_ROOT')) ? append(RAZY_URL_ROOT, $this->urlPath) : '';
    }

    /**
     * Get the routed path after routing.
     *
     * @return string
     */
    public function getRoutedPath(): string
    {
        return $this->routedPath;
    }

    /**
     * @param Module $module
     * @return string
     */
    public function landing(Module $module): string
    {
        sort_path_level($this->lazyRoute);
        foreach ($this->regexRoute as $regex => $data) {
            if ($data['module']->getCode() === $module->getCode()) {
                if (1 === preg_match($regex, $this->urlQuery, $matches)) {
                    return $module->getCode() . ':' . $data['route'];
                }
            }
        }

        $urlQuery = $this->urlQuery;
        foreach ($this->lazyRoute as $route => $data) {
            if ($data['module']->getCode() === $module->getCode()) {
                if (0 === strpos($urlQuery, $route)) {
                    return $route;
                }
            }
        }

        return '';
    }

    /**
     * Match the registered route and execute the matched path.
     *
     * @return bool
     * @throws Throwable
     */
    public function matchRoute(): bool
    {
        if (null !== $this->redirect) {
            header('location: ' . $this->redirect, true, 301);

            exit;
        }

        $this->attention();
        sort_path_level($this->lazyRoute);

        // Match regex route first
        foreach ($this->regexRoute as $regex => $data) {
            if (1 === preg_match($regex, $this->urlQuery, $matches)) {
                array_shift($matches);

                /** @var Module $module */
                $module = $data['module'];
                if (Module::STATUS_LOADED === $module->getStatus()) {
                    $path = $data['path'];
                    if (!$path || !($closure = $module->getClosure($path))) {
                        return false;
                    }

                    if ($module->prepare($matches)) {
                        $this->dispatch($module);
                        $this->routedPath = $this->urlQuery;
                        call_user_func_array($closure, $matches);
                    }

                    return true;
                }
            }
        }

        // Match lazy route
        $urlQuery = $this->urlQuery;
        foreach ($this->lazyRoute as $route => $data) {
            if (0 === strpos($urlQuery, $route)) {
                // Extract the url query string when the route is matched
                $urlQuery = rtrim(substr($urlQuery, strlen($route)), '/');
                $args     = explode('/', $urlQuery);

                /** @var Module $module */
                $module = $data['module'];
                if (Module::STATUS_LOADED === $module->getStatus()) {
                    $path = $data['path'];
                    if (!$path || !($closure = $module->getClosure($path))) {
                        return false;
                    }

                    if ($module->prepare($args)) {
                        $this->dispatch($module);
                        $this->routedPath = $route;
                        call_user_func_array($closure, $args);
                    }

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Trigger all module __onDispatch event when the Application before route into a module.
     *
     * @param Module $target
     */
    private function dispatch(Module $target)
    {
        if ($this->domain) {
            foreach ($this->modules as $module) {
                if (Module::STATUS_LOADED === $module->getStatus()) {
                    $module->standby($target->getCode());
                }
            }
        }
    }

    /**
     * Put the current distributor into stacking list.
     *
     * @return $this
     */
    public function attention(): self
    {
        self::$stacking[] = $this;

        return $this;
    }

    /**
     * Set up the lazy route.
     *
     * @param Module $module The module entity
     * @param string $route The route path string
     * @param string $path The closure path of method
     *
     * @return $this
     */
    public function setLazyRoute(Module $module, string $route, string $path): self
    {
        $this->lazyRoute[$route] = [
            'module' => $module,
            'path'   => $path,
        ];

        return $this;
    }

    /**
     * Set up the standard route.
     *
     * @param Module $module
     * @param string $route
     * @param string $path
     *
     * @return $this
     */
    public function setRoute(Module $module, string $route, string $path): self
    {
        $this->regexRoute[$route] = [
            'module' => $module,
            'path'   => $path,
        ];

        return $this;
    }

    /**
     * Get the module by given module code.
     *
     * @param string $moduleCode The module code used to obtain the Module
     *
     * @return null|Module
     */
    public function getModule(string $moduleCode): ?Module
    {
        return $this->modules[$moduleCode] ?? null;
    }

    /**
     * Get all module instances.
     *
     * @return array
     */
    public function getAllModules(): array
    {
        return $this->modules;
    }

    /**
     * Extract the command and pass the arguments to matched module.
     *
     * @param string $command The API command
     * @param array $args The arguments will pass to API command
     *
     * @return null|mixed
     * @throws Throwable
     *
     */
    public function execute(string $command, array $args)
    {
        $this->attention();
        if (1 !== substr_count($command, '.')) {
            throw new Error('`' . $command . '` is not a valid API command.');
        }

        [$moduleCode, $command] = explode('.', $command);
        $module                 = $this->enabledModule[$moduleCode] ?? null;

        if (null !== $module) {
            /** @var Module $module */
            $module = $this->enabledModule[$moduleCode];

            $result = $module->accessAPI($command, $args);
            $this->rest();

            return $result;
        }

        $this->rest();

        return null;
    }

    /**
     * Release and shift off the distributor.
     *
     * @return $this
     */
    public function rest(): self
    {
        array_pop(self::$stacking);

        return $this;
    }

    /**
     * Create the API instance.
     *
     * @return API
     */
    public function createAPI(): API
    {
        return new API($this);
    }

    /**
     * Enable API protocol.
     *
     * @param Module $module The module instance
     *
     * @return $this
     */
    public function enableAPI(Module $module): self
    {
        if (strlen($module->getAPICode()) > 0) {
            $this->enabledModule[$module->getAPICode()] = $module;
        }

        return $this;
    }

    /**
     * Connect to another application under the same Razy structure.
     *
     * @param string $fqdn The well-formatted FQDN string
     *
     * @return null|API
     * @throws Throwable
     *
     */
    public function connect(string $fqdn): ?API
    {
        return $this->domain->connect($fqdn);
    }

    /**
     * Create an EventEmitter.
     *
     * @param Module $module The module instance
     * @param string $event The event name
     * @param callable $callback The callback will be executed when the event is resolved
     *
     * @return EventEmitter
     */
    public function createEmitter(Module $module, string $event, callable $callback): EventEmitter
    {
        return new EventEmitter($this, $module, $event, $callback);
    }

    /**
     * Get the initialized Template Engine entity.
     *
     * @return Template
     */
    public function getTemplateEngine(): Template
    {
        if (!$this->templateEngine) {
            $this->templateEngine = new Template();
        }

        return $this->templateEngine;
    }

    /**
     * Unpack all module asset into shared view folder.
     *
     * @param Closure $closure
     *
     * @return int
     */
    public function unpackAllAsset(Closure $closure): int
    {
        $unpackedCount = 0;
        foreach ($this->modules as $module) {
            $unpacked = $module->unpackAsset(append(SYSTEM_ROOT, 'view', $this->code));
            $closure($module->getCode(), $unpacked);
            $unpackedCount += count($unpacked);
        }

        return $unpackedCount;
    }

    /**
     * Validate all registered package.
     *
     * @param Closure $closure
     *
     * @return bool
     * @throws Error
     *
     */
    public function validatePackage(Closure $closure): bool
    {
        PackageManager::SetupInspector($closure);

        $validated = true;
        foreach ($this->prerequisites as $package => $versionRequired) {
            $packageManager = new PackageManager($this, $package, $versionRequired);
            if (!$packageManager->fetch() || !$packageManager->validate()) {
                $validated = false;
            }
        }
        PackageManager::UpdateLock();

        return $validated;
    }

    /**
     * Get the distributor located directory.
     *
     * @return string
     */
    public function getDistPath(): string
    {
        return $this->folderPath;
    }

    /**
     * Handshake with specified module.
     *
     * @param string $targetModuleCode
     * @param string $moduleCode
     * @param string $version
     * @param string $message
     *
     * @return bool
     */
    public function handshakeTo(string $targetModuleCode, string $moduleCode, string $version, string $message): bool
    {
        if (!isset($this->modules[$targetModuleCode])) {
            return false;
        }

        return $this->modules[$targetModuleCode]->touch($moduleCode, $version, $message);
    }

    /**
     * Simulate the distributor routing and return the matched module.
     *
     * @param string $urlQuery
     * @param string $ignoreModule
     *
     * @return string
     */
    public function simulate(string $urlQuery, string $ignoreModule): string
    {
        $urlQuery = tidy($urlQuery, false, '/');
        sort_path_level($this->lazyRoute);

        // Match regex route first
        foreach ($this->regexRoute as $regex => $data) {
            if (1 === preg_match($regex, $urlQuery)) {
                /** @var Module $module */
                $module = $data['module'];
                if (Module::STATUS_LOADED === $module->getStatus() && $module->getCode() !== $ignoreModule) {
                    return $module->getCode();
                }
            }
        }

        // Match lazy route
        $urlQuery = $urlQuery . '/';
        foreach ($this->lazyRoute as $route => $data) {
            if (0 === strpos($urlQuery, $route)) {
                /** @var Module $module */
                $module = $data['module'];
                if (Module::STATUS_LOADED === $module->getStatus() && $module->getCode() !== $ignoreModule) {
                    return $module->getCode();
                }
            }
        }

        return '';
    }

    /**
     * Set the redirect url, only allowed to.
     *
     * @param string $url
     *
     * @return $this
     */
    public function setRedirect(string $url): Distributor
    {
        if (null === $this->redirect) {
            $this->redirect = $url;
        }

        return $this;
    }

    /**
     * Get the URLQuery string.
     *
     * @return string
     */
    public function getURLQuery(): string
    {
        return $this->urlQuery;
    }

    /**
     * Load the module under the distributor folder.
     *
     * @param string $path The path of the module folder
     *
     * @throws Throwable
     */
    private function loadModule(string $path): void
    {
        $path = tidy($path, true);
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) as $node) {
            if ('.' === $node || '..' === $node) {
                continue;
            }

            // Get the module path
            $modulePath = append($path, $node);

            if (is_dir($modulePath)) {
                if (is_file(append($modulePath, 'dist.php'))) {
                    // Do not scan the folder if it contains a distributor config file
                    continue;
                }

                if (!$this->createModule($modulePath)) {
                    $this->loadModule($modulePath);
                }
            } elseif (is_file($modulePath)) {
                // If the module is a phar file
                if (preg_match('/\.phar$/', $modulePath)) {
                    try {
                        $hash = md5($modulePath);
                        Phar::loadPhar($modulePath, $hash . '.phar');
                        $this->createModule('phar://' . $hash . '.phar');
                    } catch (PharException $e) {
                    }
                }
            }
        }
    }

    /**
     * @param string $path
     * @return bool
     * @throws Error
     * @throws Throwable
     */
    private function createModule(string $path): bool
    {
        $configFile = append($path, 'package.php');
        if (is_file($configFile)) {
            // Create Module entity if the folder contains module config file
            try {
                $module = new Module($this, $path, require $configFile);

                if (!isset($this->modules[$module->getCode()])) {
                    $this->modules[$module->getCode()] = $module;
                } else {
                    throw new Error('Duplicated module loaded, module load abort.');
                }
            } catch (Exception $e) {
                throw new Error('Unable to load the module.');
            }

            return true;
        }

        return false;
    }

    /**
     * Load the module from the shared folder.
     *
     * @param string $path The path of the shared module folder
     *
     * @throws Throwable
     */
    private function loadSharedModule(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) as $node) {
            if ('.' === $node || '..' === $node) {
                continue;
            }

            // Get the module path
            $folder = append($path, $node);

            if (is_dir($folder)) {
                $configFile = append($folder, 'package.php');
                if (is_file($configFile)) {
                    // Load the module if it is not exists in distributor
                    try {
                        $module = new Module($this, $folder, require $configFile, true);

                        if (!isset($this->modules[$module->getCode()])) {
                            $this->modules[$module->getCode()] = $module;
                        }
                    } catch (Exception $e) {
                        throw new Error('Unable to load the module.');
                    }
                } else {
                    $this->loadSharedModule($folder);
                }
            }
        }
    }

    /**
     * @param Module $module
     *
     * @return bool
     * @throws Throwable
     */
    private function activate(Module $module): bool
    {
        $requireList = $module->getRequire();
        foreach ($requireList as $moduleCode => $version) {
            $reqModule = $this->modules[$moduleCode] ?? null;
            if (!$reqModule) {
                return false;
            }

            if (!$reqModule->isInitialized()) {
                if (!$this->activate($reqModule)) {
                    $reqModule->unload();

                    return false;
                }
            } elseif (Module::STATUS_FAILED == $reqModule->getStatus()) {
                return false;
            }
        }

        if (!$module->isInitialized()) {
            if (!$module->initialize()) {
                return false;
            }
        }

        return true;
    }
}
