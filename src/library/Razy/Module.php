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
use ReflectionClass;
use Throwable;

class Module
{
    /**
     * The module is not active.
     */
    public const STATUS_DISABLED = 0;

    /**
     * The module is initialing.
     */
    public const STATUS_INITIALING = 1;

    /**
     * The module is ready.
     */
    public const STATUS_READY = 2;

    /**
     * The module is waiting for validation
     */
    public const STATUS_WAITING_VALIDATE = 3;

    /**
     * The module is in the preloading stage
     */
    public const STATUS_PRELOADING = 4;

    /**
     * The module is loaded successfully.
     */
    public const STATUS_LOADED = 5;

    /**
     * The module is unloaded, version not match the requirement or not in pass list.
     */
    public const STATUS_UNLOADED = -1;

    /**
     * Some error cause, the module is not loaded.
     */
    public const STATUS_FAILED = -2;

    /**
     * @var array
     */
    private array $binding = [];

    /**
     * @var array
     */
    private array $closures = [];

    /**
     * The storage of the API commands
     *
     * @var bool[]
     */
    private array $commands = [];

    /**
     * The Controller entity
     *
     * @var null|Controller
     */
    private ?Controller $controller = null;

    /**
     * The storage of the events
     *
     * @var bool[]
     */
    private array $events = [];

    /**
     * @var ModuleInfo
     */
    private ModuleInfo $moduleInfo;

    /**
     * The Pilot entity
     *
     * @var Pilot
     */
    private Pilot $pilot;

    /**
     * The status of the module
     *
     * @var int
     */
    private int $status = self::STATUS_DISABLED;

    /**
     * Module constructor.
     *
     * @param Distributor $distributor The Distributor instance
     * @param string $path The folder path of the Distributor
     * @param string $version The specified version to load, leave blank to load default or dev
     * @param bool $sharedModule
     *
     * @throws Throwable
     */
    public function __construct(private readonly Distributor $distributor, string $path, string $version = 'default', bool $sharedModule = false)
    {
        $this->moduleInfo = new ModuleInfo($path, $version, $sharedModule);

        if (strlen($this->moduleInfo->getAPICode()) > 0) {
            $this->distributor->enableAPI($this);
        }

        foreach ($this->moduleInfo->getPrerequisite() as $package => $pVersion) {
            $this->distributor->prerequisite($package, $pVersion);
        }

        $this->pilot = new Pilot($this);
    }

    /**
     * Register the API command.
     *
     * @param string $command The API command will register
     *
     * @return $this
     * @throws Throwable
     *
     */
    public function addAPI(string $command, string $path): self
    {
        if (array_key_exists($command, $this->commands)) {
            throw new Error('The command `' . $command . '` is already registered.');
        }
        $this->commands[$command] = $path;

        return $this;
    }

    /**
     * Add lazy route into the route list.
     *
     * @param string $route The path of the route
     * @param string $path The path of the closure file
     *
     * @return $this
     */
    public function addLazyRoute(string $route, string $path): self
    {
        $this->distributor->setLazyRoute($this, $route, $path);

        return $this;
    }

    /**
     * Add CLI script path into the script list.
     *
     * @param string $route The path of the route
     * @param string $path The path of the closure file
     *
     * @return $this
     */
    public function addScript(string $route, string $path): self
    {
        $this->distributor->registerScript($this, $route, $path);

        return $this;
    }

    /**
     * Add standard route into the route list, regular expression string supported.
     *
     * @param string $route The path of the route
     * @param string|Route $path The Route entity or the path of the closure file by the method name
     *
     * @return $this
     */
    public function addRoute(string $route, mixed $path): self
    {
        // Standardize the route string
        $route = rtrim(tidy($route, false, '/'), '/');

        if (!$route) {
            $route = '/';
        }

        $this->distributor->setRoute($this, $route, $path);

        return $this;
    }

    /**
     * Bind the specified closure file to a method.
     *
     * @param string $method
     * @param string $path
     *
     * @return $this
     */
    public function bind(string $method, string $path): Module
    {
        $path = trim($path);
        if ($path) {
            $path = tidy($path);

            $this->binding[$method] = $path;
        }

        return $this;
    }

    /**
     * Connect another domain API.
     *
     * @param string $fqdn The well-formatted FQDN
     *
     * @return null|API
     * @throws Throwable
     *
     */
    public function connect(string $fqdn): ?API
    {
        return $this->distributor->connect($fqdn);
    }

    /**
     * Check the event is dispatched.
     *
     * @param string $event The event name
     *
     * @return bool
     */
    public function eventDispatched(string $event): bool
    {
        return array_key_exists($event, $this->events);
    }

    /**
     * Execute API command.
     *
     * @param ModuleInfo $module The request module
     * @param string $command The API command
     * @param array $args The arguments will pass to API command
     *
     * @return mixed
     * @throws Throwable
     */
    public function execute(ModuleInfo $module, string $command, array $args): mixed
    {
        $this->distributor->stackState(Controller::STATE_API);
        $result = $this->accessAPI($module, $command, $args);
        $this->distributor->releaseState();
        return $result;
    }

    /**
     * Execute the API command.
     *
     * @param ModuleInfo $module The request module
     * @param string $command The API command
     * @param array $args The arguments will pass to the API command
     *
     * @return null|mixed
     * @throws Throwable
     */
    public function accessAPI(ModuleInfo $module, string $command, array $args): mixed
    {
        $result = null;
        $this->distributor->attention();
        try {
            if (array_key_exists($command, $this->commands)) {
                if ($this->controller->__onAPICall($module, $command)) {
                    if (!str_contains($command, '/') && method_exists($this->controller, $command)) {
                        $closure = [$this->controller, $command];
                    } elseif (($closure = $this->getClosure($this->commands[$command])) !== null) {
                        $closure->bindTo($this->controller);
                    }

                    if ($closure) {
                        $result = call_user_func_array($closure, $args);
                    }
                }
            }
        } catch (Throwable $exception) {
            $this->controller->__onError($command, $exception);
        }
        $this->distributor->rest();

        return $result;
    }

    /**
     * @param string $path
     *
     * @return null|Closure
     * @throws Error
     *
     */
    public function getClosure(string $path): ?Closure
    {
        $path = tidy($path, false, '/');

        if (0 === substr_count($path, '/')) {
            // Root closure function file name should start with the class name
            $path = $this->moduleInfo->getClassName() . '.' . $path;
        }
        $path = append($this->moduleInfo->getPath(), 'controller', $path . '.php');

        if (!isset($this->closures[$path])) {
            if (is_file($path)) {
                /** @var Closure $closure */
                $closure = require $path;
                if (!is_callable($closure) && $closure instanceof Closure) {
                    throw new Error('The object is not a Closure.');
                }
                $this->closures[$path] = $closure->bindTo($this->controller, get_class($this->controller));
            }
        }

        return $this->closures[$path] ?? null;
    }

    /**
     * Get the module's data folder of the application.
     *
     * @param string $module
     * @param bool $isURL
     * @return string
     */
    public function getDataPath(string $module = '', bool $isURL = false): string
    {
        return $this->distributor->getDataPath($module ?? $this->moduleInfo->getCode(), $isURL);
    }

    /**
     * Trigger the event.
     *
     * @param string $event The event name
     * @param array $args The arguments will pass to the listener
     *
     * @return null|mixed
     * @throws Throwable
     */
    public function fireEvent(string $event, array $args): mixed
    {
        $result = null;

        $this->distributor->stackState(Controller::STATE_EVENT);
        try {
            if (array_key_exists($event, $this->events)) {
                $closure = null;
                if (!str_contains($event, '/') && method_exists($this->controller, $event)) {
                    $closure = [$this->controller, $event];
                } elseif (($closure = $this->getClosure($this->events[$event])) !== null) {
                    $closure->bindTo($this->controller);
                }

                if ($closure) {
                    $result = call_user_func_array($closure, $args);
                }
            }
        } catch (Throwable $exception) {
            $this->controller->__onError($event, $exception);
        }
        $this->distributor->releaseState();

        return $result;
    }

    /**
     * Get the module bound closure.
     *
     * @param string $method
     *
     * @return string
     */
    public function getBinding(string $method): string
    {
        return $this->binding[$method] ?? '';
    }

    /**
     * Get the module's controller.
     *
     * @return Controller
     */
    public function getController(): Controller
    {
        return $this->controller;
    }

    /**
     * Get the module's API Emitter.
     *
     * @param string $moduleCode
     *
     * @return Emitter
     */
    public function getEmitter(string $moduleCode): Emitter
    {
        return $this->distributor->createAPI()->request($moduleCode);
    }

    /**
     * Get the routed information.
     *
     * @return array
     */
    public function getRoutedInfo(): array
    {
        return $this->distributor->getRoutedInfo();
    }

    /**
     * Get the distributor site's URL
     *
     * @return string
     */
    public function getSiteURL(): string
    {
        return $this->distributor->getBaseURL();
    }

    /**
     * Get the distributor's request path
     *
     * @return string
     */
    public function getRequestPath(): string
    {
        return $this->distributor->getRequestPath();
    }

    /**
     * Get the module relative URL path.
     *
     * @return string
     */
    public function getBaseURL(): string
    {
        return tidy(append($this->distributor->getBaseURL(), $this->moduleInfo->getAlias()), true, '/');
    }

    /**
     * Get the module status.
     *
     * @return int
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * @return Template
     */
    public function getTemplateEngine(): Template
    {
        return $this->distributor->getTemplateEngine();
    }

    /**
     * Get the URLQuery string.
     *
     * @return string
     */
    public function getURLQuery(): string
    {
        return $this->distributor->getURLQuery();
    }

    /**
     * Send a handshake to specified module to determine it is available.
     *
     * @param string $moduleCode
     * @param string $message
     *
     * @return bool
     */
    public function handshake(string $moduleCode, string $message = ''): bool
    {
        return $this->distributor->handshakeTo($moduleCode, $this->moduleInfo, $this->moduleInfo->getVersion(), $message);
    }

    /**
     * Start to initial the module.
     * If the controller is missing or the module cannot
     * initialize (__onInit event), the module will not add into the loaded module list.
     *
     * @return bool
     * @throws Throwable
     */
    public function initialize(): bool
    {
        // If the Controller entity does not initialize
        if (null === $this->controller) {
            // Load the controller
            $controllerPath = append($this->moduleInfo->getPath(), 'controller', $this->moduleInfo->getClassName() . '.php');

            if (is_file($controllerPath)) {
                // Import the anonymous class file.
                // The module controller object must extend Controller abstract class
                $controller = include $controllerPath;

                $reflected = new ReflectionClass($controller);

                if ($reflected->isAnonymous()) {
                    // Create controller instance, the routing, event and API will be configured in the controller
                    $this->controller = $reflected->newInstance($this);

                    // Ensure the controller is inherited by Controller class
                    if (!$this->controller instanceof Controller) {
                        throw new Error('The controller must instance of Controller');
                    }

                    $this->status = self::STATUS_INITIALING;
                    $this->status = (!$this->controller->__onInit($this->pilot)) ? self::STATUS_FAILED : self::STATUS_WAITING_VALIDATE;

                    return true;
                }

                throw new Error('No controller is found in module package');
            }

            throw new Error('The controller ' . $controllerPath . ' does not exists.');
        }

        return true;
    }

    /**
     * Return true if the module is initialized.
     *
     * @return bool
     */
    public function isInitialized(): bool
    {
        return self::STATUS_DISABLED !== $this->status;
    }

    /**
     * Start to listen to the event.
     *
     * @param string $event The event name
     *
     * @return $this
     * @throws Throwable
     *
     */
    public function listen(string $event, string $path): self
    {
        if (array_key_exists($event, $this->events)) {
            throw new Error('The event `' . $event . '` is already registered.');
        }
        $this->events[$event] = $path;

        return $this;
    }

    /**
     * Load the module configuration file.
     *
     * @throws Error
     */
    public function loadConfig(): Configuration
    {
        $path = append(SYSTEM_ROOT, 'config', $this->distributor->getDistCode(), $this->moduleInfo->getClassName() . '.php');

        return new Configuration($path);
    }

    /**
     * @return string
     */
    public function getDistCode(): string
    {
        return $this->distributor->getDistCode();
    }

    /**
     * Trigger __onReady event when all modules have loaded.
     */
    public function notify(): void
    {
        $this->controller->__onReady();
        $this->status = self::STATUS_LOADED;
    }

    /**
     * Trigger __onPreload event when the application is ready to route.
     *
     * @return bool
     */
    public function preload(): bool
    {
        return $this->controller->__onPreload($this->pilot);
    }

    /**
     * Send the signal to the controller that the distributor trying to route in. Return false to refuse.
     *
     * @param array $args
     *
     * @return bool
     */
    public function prepare(array $args): bool
    {
        if (CLI_MODE) {
            return $this->controller->__onScriptCall($args);
        } else {
            return $this->controller->__onRoute($args);
        }
    }

    /**
     * Get the EventEmitter instance from Distributor.
     *
     * @param string $event The event name
     * @param callable $callback The callback will be executed if the event is resolving
     *
     * @return EventEmitter
     */
    public function propagate(string $event, callable $callback): EventEmitter
    {
        return $this->distributor->createEmitter($this, $event, $callback);
    }

    /**
     * Set the module is ready.
     */
    public function ready(): void
    {
        $this->status = self::STATUS_READY;
    }

    /**
     * Trigger __onDispatch event when the application has routed into a module
     */
    public function standby(ModuleInfo $module): void
    {
        if (self::STATUS_LOADED === $this->status) {
            if (CLI_MODE) {
                $this->controller->__onScriptLoaded($module);
            } else {
                $this->controller->__onDispatch($module);
            }
        }
    }

    /**
     * Touch to inform it has handshake from another module.
     *
     * @param ModuleInfo $module
     * @param string $version
     * @param string $message
     *
     * @return bool
     */
    public function touch(ModuleInfo $module, string $version, string $message): bool
    {
        return $this->controller->__onTouch($module, $version, $message);
    }

    /**
     * Unload the module.
     *
     * @return $this
     */
    public function unload(): Module
    {
        if (self::STATUS_FAILED != $this->status) {
            $this->status = self::STATUS_UNLOADED;
        }

        return $this;
    }

    /**
     * Validate the module is ready to initial, if the event return false, it will put the module into a preload list.
     *
     * @return bool
     */
    public function validate(): bool
    {
        if (!$this->controller->__onValidate($this->pilot)) {
            $this->status = self::STATUS_PRELOADING;
            return false;
        }
        return true;
    }

    /**
     * Put the callable into the list to wait for executing until other specified modules has ready.
     *
     * @param string $moduleCode
     * @param Closure $caller
     *
     * @return $this
     */
    public function await(string $moduleCode, Closure $caller): Module
    {
        $caller = $caller->bindTo($this->controller);
        $this->distributor->addAwait($moduleCode, $caller);

        return $this;
    }

    /**
     * Get the ModuleInfo, includes all standalone module's settings and function
     *
     * @return ModuleInfo
     */
    public function getModuleInfo(): ModuleInfo
    {
        return $this->moduleInfo;
    }

    /**
     * @param string $route
     * @param string $moduleCode
     * @param string $path
     * @return $this
     */
    public function addShadowRoute(string $route, string $moduleCode, string $path): self
    {
        $this->distributor->setShadowRoute($this, $route, $moduleCode, $path);

        return $this;
    }

    /**
     * Trigger onDispose event.
     *
     * @return $this
     */
    public function dispose(): self
    {
        $this->controller->__onDispose();
        return $this;
    }

    /**
     * @param $state
     * @return $this
     */
    public function stackState($state): self
    {
        $this->distributor->stackState($state);
        return $this;
    }

    public function releaseState(): self
    {
        $this->distributor->releaseState();
        return $this;
    }

    /**
     * @return string
     */
    public function getState(): string
    {
        return $this->distributor->getState();
    }

    /**
     * @param array $routedInfo
     * @return $this
     */
    public function entry(array $routedInfo): self
    {
        $this->controller->__onEntry($routedInfo);
        return $this;
    }
}
