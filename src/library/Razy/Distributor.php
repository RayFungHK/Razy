<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy;

use Closure;
use Exception;
use Throwable;

class Distributor
{
    private string $code = '';
    private array $requires = [];
    private array $modules = [];
    private array $queue = [];
    private array $APIModules = [];
    private array $CLIScripts = [];
    private array $awaitList = [];
    private array $routedInfo = [];
    private bool $autoload = false;
    private bool $globalModule = false;
    private string $folderPath = '';
    private array $routes = [];
    private array $prerequisites = [];
    private ?Template $globalTemplate = null;

    /**
     * Distributor constructor.
     *
     * @param string $distCode The folder path of the distributor
     * @param string $tag The distributor tag
     * @param null|Domain $domain The Domain Instance
     * @param string $urlPath The URL path of the distributor
     * @param string $urlQuery The URL Query string
     *
     * @throws Throwable
     */
    public function __construct(private string $distCode, private readonly string $tag = '*', private readonly ?Domain $domain = null, private readonly string $urlPath = '', private string $urlQuery = '/')
    {
        $this->folderPath = append(SITES_FOLDER, $distCode);
        $this->urlQuery = tidy($this->urlQuery, false, '/');

        if (!$this->urlQuery) {
            $this->urlQuery = '/';
        }

        if (!$configFile = realpath(append($this->folderPath, 'dist.php'))) {
            throw new Error('Missing distributor configuration file (dist.php).');
        }

        $config = require $configFile;

        if (!($config['dist'] = $config['dist'] ?? '')) {
            throw new Error('The distributor code is empty.');
        }

        if (!preg_match('/^[a-z][\w\-]*$/i', $config['dist'])) {
            throw new Error('Invalid distribution code.');
        }
        $this->code = $config['dist'];

        $config['modules'] = $config['modules'] ?? [];
        if (is_array($config['modules'])) {
            if (isset($config['modules'][$this->tag])) {
                foreach ($config['modules'][$this->tag] as $moduleCode => $version) {
                    $moduleCode = trim($moduleCode);
                    $version = trim($version);
                    if (strlen($moduleCode) > 0 && strlen($version) > 0) {
                        $this->requires[$moduleCode] = null;
                    }
                }
            }
        }

        $config['global_module'] = (bool)($config['global_module'] ?? false);
        $this->globalModule = $config['global_module'];

        $config['autoload'] = (bool)($config['autoload'] ?? false);
        $this->autoload = $config['autoload'];
    }

    /**
     * Initial Distributor and scan the module folder.
     *
     * @return $this
     * @throws Error
     * @throws Throwable
     */
    public function initialize(): static
    {
        // Load all modules into the pending list
        $this->scanModule($this->folderPath);
        if ($this->globalModule) {
            $this->scanModule(append(SHARED_FOLDER, 'module'), true);
        }

        // Put all modules into queue by priority with its require module.
        foreach ($this->modules as $module) {
            $this->require($module);
        }

        // Preparation Stage, __onLoad event
        $this->queue = array_filter($this->queue, function (Module $module) {
            if (!$module->prepare()) {
                return false;
            }
            return true;
        });

        // Validation Stage, __onRequire event
        $this->queue = array_filter($this->queue, function (Module $module) {
            if (!$module->validate()) {
                return false;
            }

            return true;
        });

        return $this;
    }

    /**
     * @param Module $module
     * @param string $route
     * @param string $moduleCode
     * @param string $path
     * @return $this
     */
    public function setShadowRoute(Module $module, string $route, string $moduleCode, string $path): self
    {
        $targetModule = $this->getLoadedModule($moduleCode);
        if ($targetModule) {
            $this->routes['/' . tidy(append($module->getModuleInfo()->getAlias(), $route), true, '/')] = [
                'module' => $module,
                'target' => $targetModule,
                'path' => $path,
                'route' => $route,
                'type' => 'lazy',
            ];
        }

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
    public function setRoute(Module $module, string $route, mixed $path): static
    {
        $route = tidy($route, true, '/');
        $this->routes[$route] = [
            'module' => $module,
            'path' => $path,
            'route' => $route,
            'type' => 'standard',
        ];

        return $this;
    }

    /**
     * Handshake with specified module.
     *
     * @param string $targetModuleCode
     * @param ModuleInfo $requestedBy
     * @param string $version
     * @param string $message
     *
     * @return bool
     */
    public function handshakeTo(string $targetModuleCode, ModuleInfo $requestedBy, string $version, string $message): bool
    {
        if (!isset($this->modules[$targetModuleCode])) {
            return false;
        }

        return $this->modules[$targetModuleCode]->touch($requestedBy, $version, $message);
    }

    /**
     * Put the callable into the list to wait for executing until other specified modules has ready.
     *
     * @param string $moduleCode
     * @param callable $caller
     *
     * @return $this
     */
    public function addAwait(string $moduleCode, callable $caller): Distributor
    {
        $entity = [
            'required' => [],
            'caller' => $caller(...),
        ];

        $clips = explode(',', $moduleCode);
        foreach ($clips as $code) {
            if (preg_match(ModuleInfo::REGEX_MODULE_CODE, $code)) {
                $entity['required'][$code] = true;
                if (!isset($this->awaitList[$code])) {
                    $this->awaitList[$code] = [];
                }
                $this->awaitList[$code][] = &$entity;
            }
        }

        return $this;
    }

    /**
     * Register module's API.
     *
     * @param Module $module The module instance
     *
     * @return $this
     */
    public function registerAPI(Module $module): static
    {
        if (strlen($module->getModuleInfo()->getAPIName()) > 0) {
            $this->APIModules[$module->getModuleInfo()->getAPIName()] = $module;
        }

        return $this;
    }

    /**
     * Check if the module is loadable
     *
     * @param Module $module
     * @return bool
     */
    private function isLoadable(Module $module): bool
    {
        return $this->autoload || array_key_exists($module->getModuleInfo()->getCode(), $this->modules);
    }

    /**
     * Scan and find available modules under the distributor folder.
     *
     * @param string $path The path of the module folder
     * @param bool $globally Set true to define the module is loaded globally
     *
     * @return void
     * @throws Error
     * @throws Throwable
     */
    private function scanModule(string $path, bool $globally = false): void
    {
        $path = tidy($path, true);
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) as $vendor) {
            if ('.' === $vendor || '..' === $vendor) {
                continue;
            }

            $moduleFolder = append($path, $vendor);
            if (is_dir($moduleFolder)) {
                foreach (scandir($moduleFolder) as $packageName) {
                    if ('.' === $packageName || '..' === $packageName) {
                        continue;
                    }

                    $packageFolder = append($moduleFolder, $packageName);
                    if (is_dir($packageFolder)) {
                        $moduleConfigPath = append($packageFolder, 'module.php');
                        if (is_file($moduleConfigPath)) {
                            try {
                                $config = require $moduleConfigPath;

                                $config['module_code'] = $config['module_code'] ?? '';
                                if (!preg_match(ModuleInfo::REGEX_MODULE_CODE, $config['module_code'])) {
                                    throw new Error('Incorrect module code format.');
                                }
                                $config['author'] = $config['author'] ?? '';
                                $config['description'] = $config['description'] ?? '';
                            } catch (Exception) {
                                throw new Error('Unable to read the module config.');
                            }

                            $version = (isset($this->requires[$config['module_code']])) ? $this->requires[$config['module_code']] : 'default';

                            if (is_file(append($packageFolder, $version, 'package.php'))) {
                                try {
                                    $module = new Module($this, $packageFolder, $config, $version, $globally);

                                    if (!isset($this->modules[$config['module_code']])) {
                                        $this->modules[$config['module_code']] = $module;
                                    } else {
                                        throw new Error('Duplicated module loaded, module load abort.');
                                    }
                                } catch (Exception) {
                                    throw new Error('Unable to load the module.');
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Autoload the class from the distributor's module library folder
     *
     * @param string $className
     *
     * @return bool
     */
    public function autoload(string $className): bool
    {
        $moduleClassName = str_replace('\\', '/', $className);
        if (preg_match(ModuleInfo::REGEX_MODULE_CODE, $moduleClassName, $matches)) {
            $namespaces = explode('/', $moduleClassName);
            $moduleClassName = array_pop($namespaces);
            $moduleCode = implode('/', $namespaces);

            // Try to load the class from the module library
            if (isset($this->modules[$moduleCode])) {
                $module = $this->modules[$moduleCode];
                if ($module->getStatus() === Module::STATUS_LOADED) {
                    $moduleInfo = $module->getModuleInfo();
                    $path = append($moduleInfo->getPath(), 'library');
                    if (is_dir($path)) {
                        $libraryPath = append($path, $moduleClassName);
                        if (is_file($libraryPath . '.php')) {
                            $libraryPath .= '.php';
                        } elseif (is_dir($libraryPath) && is_file(append($libraryPath, $moduleClassName . '.php'))) {
                            $libraryPath = append($libraryPath, $moduleClassName . '.php');
                        }

                        if (is_file($libraryPath)) {
                            try {
                                include $libraryPath;

                                return class_exists($moduleClassName);
                            } catch (Exception) {
                                return false;
                            }
                        }
                    }
                }
            }
        }

        $libraryPath = append(SYSTEM_ROOT, 'autoload', $this->code);
        return autoload($className, $libraryPath);
    }

    /**
     * Get the distributor data folder file path, the path contains site config and file storage.
     *
     * @param string $module
     * @param bool $isURL
     * @return string
     */
    public function getDataPath(string $module = '', bool $isURL = false): string
    {
        if ($isURL) {
            return append($this->getSiteURL(), 'data', $module);
        } else {
            return append(DATA_FOLDER, $this->getIdentity(), $module);
        }
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
     * Start to activate the module.
     *
     * @param Module $module
     *
     * @return bool
     * @throws Throwable
     */
    private function require(Module $module): bool
    {
        if ($this->isLoadable($module)) {
            if ($module->getStatus() === Module::STATUS_PENDING) {
                $module->standby();
            }

            $requireModules = $module->getModuleInfo()->getRequire();
            foreach ($requireModules as $moduleCode => $version) {
                $reqModule = $this->modules[$moduleCode] ?? null;
                if (!$reqModule) {
                    return false;
                }

                if ($reqModule->getStatus() === Module::STATUS_PENDING) {
                    if (!$this->require($reqModule)) {
                        return false;
                    }
                } elseif ($reqModule->getStatus() === Module::STATUS_FAILED) {
                    return false;
                }
            }

            if ($module->getStatus() === Module::STATUS_PROCESSING) {
                if (!$module->initialize()) {
                    $module->unload();
                    return false;
                }
                $this->queue[$module->getModuleInfo()->getCode()] = $module;
            }
        }

        return ($module->getStatus() === Module::STATUS_IN_QUEUE);
    }

    /**
     * Set the cookie path and start the session.
     *
     * @return $this
     */
    public function setSession(): static
    {
        if (WEB_MODE) {
            session_set_cookie_params(0, '/', HOSTNAME);
            session_name($this->code);
            session_start();
        }

        return $this;
    }

    /**
     * Match the registered route and execute the matched path.
     *
     * @return bool
     * @throws Throwable
     */
    public function matchRoute(): bool
    {
        // If no domain is matched, stop process ready stage.
        if (!$this->domain) {
            return false;
        }

        $this->setSession();
        // Execute all await function
        foreach ($this->queue as $module) {
            $moduleCode = $module->getModuleInfo()->getCode();
            if (isset($this->awaitList[$moduleCode])) {
                foreach ($this->awaitList[$moduleCode] as $index => &$await) {
                    unset($await['required'][$moduleCode]);
                    if (count($await['required']) === 0) {
                        // If all required modules have ready, execute the await function immediately
                        $await['caller']();
                        unset($this->awaitList[$moduleCode][$index]);
                    }
                }
            }
        }

        // Ready Stage, __onReady event
        foreach ($this->queue as $module) {
            $module->notify();
        }

        $list = (CLI_MODE) ? $this->CLIScripts : $this->routes;
        $urlQuery = $this->urlQuery;
        sort_path_level($list);

        foreach ($list as $route => $data) {
            if (Module::STATUS_LOADED === $data['module']->getStatus()) {
                if ($data['type'] === 'standard') {
                    $route = preg_replace_callback('/\\\\.(*SKIP)(*FAIL)|:(?:([awdWD])|(\[[^\\[\\]]+]))({\d+,?\d*})?/', function ($matches) {
                        $regex = (strlen($matches[2] ?? '')) > 0 ? $matches[2] : (('a' === $matches[1]) ? '[^/]' : '\\' . $matches[1]);
                        return $regex . ((0 !== strlen($matches[3] ?? '')) ? $matches[3] : $regex .= '+');
                    }, $route);

                    $route = '/^' . preg_replace('/\\\\.(*SKIP)(*FAIL)|\//', '\\/', $route) . '$/';

                    if (!preg_match($route, $this->urlQuery, $matches)) {
                        continue;
                    } else {
                        array_shift($matches);
                        $args = $matches;
                    }
                } else {
                    if (!str_starts_with($urlQuery, $route)) {
                        continue;
                    } else {
                        $urlQuery = rtrim(substr($urlQuery, strlen($route)), '/');
                        $args = explode('/', $urlQuery);
                    }
                }

                $path = (is_string($data['path'])) ? $data['path'] : $data['path']->getClosurePath();
                $path = tidy($path, false, '/');

                if (preg_match('/^r(@?):(.+)/', $path, $matches)) {
                    echo $path;
                    $path = $matches[2];
                    header('Location: ' . append($matches[1] ? $this->getSiteURL() : $data['module']->getModuleURL(), $path));
                    exit;
                }

                $executor = (isset($data['target'])) ? $data['target'] : $data['module'];
                if (!$path || !($closure = $executor->getClosure($path))) {
                    return false;
                }

                if ($executor->getStatus() === Module::STATUS_LOADED) {
                    $this->routedInfo = [
                        'url_query' => $this->urlQuery,
                        'base_url' => append($this->getSiteURL(), rtrim($route, '/')),
                        'route' => tidy('/' . $data['route'], false, '/'),
                        'module' => $data['module']->getModuleInfo()->getCode(),
                        'closure_path' => $path,
                        'arguments' => $args,
                        'type' => $data['type'],
                        'is_shadow' => isset($data['target']),
                    ];

                    if (!is_string($data['path'])) {
                        $this->routedInfo['contains'] = $data['path']->getData();
                    }

                    $this->announce($data['module']);

                    if ($data['type'] !== 'script') {
                        $data['module']->entry($this->routedInfo);
                    }

                    call_user_func_array($closure, $args);
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Get the routed information after the route is matched.
     *
     * @return array
     */
    public function getRoutedInfo(): array
    {
        return $this->routedInfo;
    }

    /**
     * Set the lazy route.
     *
     * @param Module $module The module entity
     * @param string $route The route path string
     * @param string $path The closure path of method
     *
     * @return $this
     */
    public function setLazyRoute(Module $module, string $route, string $path): static
    {
        $this->routes['/' . tidy(append($module->getModuleInfo()->getAlias(), $route), true, '/')] = [
            'module' => $module,
            'path' => $path,
            'route' => $route,
            'type' => 'lazy',
        ];

        return $this;
    }

    /**
     * Set the script route
     *
     * @param Module $module
     * @param string $route
     * @param string $path
     * @return $this
     */
    public function setScript(Module $module, string $route, string $path): static
    {
        $this->CLIScripts['/' . tidy(append($module->getModuleInfo()->getAlias(), $route), true, '/')] = [
            'module' => $module,
            'path' => $path,
            'route' => $route,
            'type' => 'script',
        ];
        return $this;
    }

    /**
     * Create the API instance.
     *
     * @param Module $module The module that calling
     * @return API
     */
    public function createAPI(Module $module): API
    {
        return new API($this, $module);
    }

    /**
     * Get the loaded API module by given module code.
     *
     * @param string $apiModule
     *
     * @return Module|null
     */
    public function getLoadedAPIModule(string $apiModule): ?Module
    {
        $module = $this->modules[$apiModule] ?? $this->APIModules[$apiModule] ?? null;
        return ($module && $module->getStatus() === Module::STATUS_LOADED) ? $module : null;
    }

    /**
     * Get the loaded module by given module code.
     *
     * @param string $moduleCode
     * @return Module|null
     */
    public function getLoadedModule(string $moduleCode): ?Module
    {
        $module = $this->modules[$moduleCode] ?? null;
        return ($module && $module->getStatus() === Module::STATUS_LOADED) ? $module : null;
    }

    /**
     * Execute dispose event.
     *
     * @return $this
     */
    public function dispose(): static
    {
        foreach ($this->queue as $module) {
            $module->dispose();
        }

        return $this;
    }

    /**
     * Trigger all module __onRouted event that Application announced the routed module.
     *
     * @param Module $matchedModule
     */
    private function announce(Module $matchedModule): void
    {
        foreach ($this->queue as $module) {
            if ($matchedModule->getModuleInfo()->getCode() !== $module->getModuleInfo()->getCode()) {
                $module->announce($matchedModule->getModuleInfo());
            }
        }
    }

    /**
     * Get the distributor code
     *
     * @return string
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * Get the distributor URL path.
     *
     * @return string
     */
    public function getSiteURL(): string
    {
        return (defined('RAZY_URL_ROOT')) ? append(RAZY_URL_ROOT, $this->urlPath) : '';
    }

    /**
     * Get all module instances.
     *
     * @return array
     */
    public function getModules(): array
    {
        return $this->modules;
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
     * Create an EventEmitter.
     *
     * @param Module $module The module instance
     * @param string $event The event name
     * @param callable|null $callback The callback will be executed when the event is resolved
     *
     * @return EventEmitter
     */
    public function createEmitter(Module $module, string $event, ?callable $callback = null): EventEmitter
    {
        return new EventEmitter($this, $module, $event, !$callback ? null : $callback(...));
    }

    /**
     * Get the prerequisite list from all modules.
     *
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
            if ('*' !== $version) {
                $this->prerequisites[$package] .= ',' . $version;
            }
        } else {
            $this->prerequisites[$package] = $version;
        }

        return $this;
    }

    /**
     * Compose all modules
     *
     * @param Closure $closure
     *
     * @return bool
     * @throws Error
     *
     */
    public function compose(Closure $closure): bool
    {
        $validated = true;
        foreach ($this->prerequisites as $package => $versionRequired) {
            $packageManager = new PackageManager($this, $package, $versionRequired, $closure);
            if (!$packageManager->fetch() || !$packageManager->validate()) {
                $validated = false;
            }
        }
        PackageManager::UpdateLock();

        return $validated;
    }
}