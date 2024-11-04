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
use ReflectionClass;
use Throwable;

class Module
{
    public const STATUS_DISABLED = -2;
    public const STATUS_PENDING = 0;
    public const STATUS_PROCESSING = 2;
    public const STATUS_INITIALING = 2;
    public const STATUS_IN_QUEUE = 3;
    public const STATUS_LOADED = 4;
    public const STATUS_UNLOADED = -1;
    public const STATUS_FAILED = -3;

    private bool $routable = true;
    private array $binding = [];
    private array $closures = [];
    private array $apiCommands = [];
    private ?Controller $controller = null;
    private array $events = [];
    private ModuleInfo $moduleInfo;
    private Agent $agent;
    private int $status = self::STATUS_PENDING;

    /**
     * Module constructor.
     *
     * @param Distributor $distributor The Distributor instance
     * @param string $path The folder path of the Distributor
     * @param array $moduleConfig
     * @param string $version The specified version to load, leave blank to load default or dev
     * @param bool $sharedModule
     *
     * @throws Error
     */
    public function __construct(private readonly Distributor $distributor, string $path, array $moduleConfig, string $version = 'default', bool $sharedModule = false)
    {
        $this->moduleInfo = new ModuleInfo($path, $moduleConfig, $version, $sharedModule);

        if (strlen($this->moduleInfo->getAPIName()) > 0) {
            $this->distributor->registerAPI($this);
        }

        foreach ($this->moduleInfo->getPrerequisite() as $package => $pVersion) {
            $this->distributor->prerequisite($package, $pVersion);
        }

        $this->agent = new Agent($this);
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
                    // Create controller entity, the routing, event and API will be configured in the controller
                    $this->controller = $reflected->newInstance($this);

                    // Ensure the controller is inherited by Controller class
                    if (!$this->controller instanceof Controller) {
                        throw new Error('The controller must instance of Controller');
                    }

                    // Initialing module
                    $this->status = self::STATUS_INITIALING;
                    $this->status = (!$this->controller->__onInit($this->agent)) ? self::STATUS_FAILED : static::STATUS_IN_QUEUE;

                    return true;
                }

                throw new Error('No controller is found in module package');
            }

            throw new Error('The controller ' . $controllerPath . ' does not exists.');
        }

        return true;
    }

    /**
     * Put the callable into the list to wait for executing until other specified modules has ready.
     *
     * @param string $moduleCode
     * @param callable $caller
     *
     * @return $this
     */
    public function await(string $moduleCode, callable $caller): Module
    {
        $caller = $caller(...)->bindTo($this->controller);
        $this->distributor->addAwait($moduleCode, $caller);

        return $this;
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
        $result = null;
        try {
            if (array_key_exists($command, $this->apiCommands)) {
                if ($this->controller->__onAPICall($module, $command)) {
                    if (!str_contains($command, '/') && method_exists($this->controller, $command)) {
                        $closure = [$this->controller, $command];
                    } elseif (($closure = $this->getClosure($this->apiCommands[$command])) !== null) {
                        $closure = $closure->bindTo($this->controller);
                    }

                    if ($closure) {
                        $result = call_user_func_array($closure, $args);
                    }
                }
            }
        } catch (Throwable $exception) {
            $this->controller->__onError($command, $exception);
        }
        return $result;
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
        return$this->controller->__onTouch($module, $version, $message);
    }

    /**
     * Send a handshake to specified module to acknowledge it can access API
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
     * Update module status to processing that it is already in require list
     *
     * @return $this
     */
    public function standby(): Module
    {
        if (self::STATUS_PENDING === $this->status) {
            $this->status = self::STATUS_PROCESSING;
        }

        return $this;
    }

    /**
     * Unload the module.
     *
     * @return $this
     */
    public function unload(): Module
    {
        if (self::STATUS_FAILED !== $this->status) {
            $this->status = self::STATUS_UNLOADED;
        }

        return $this;
    }

    /**
     * Trigger __onReady event when all modules have loaded.
     */
    public function notify(): void
    {
        $this->controller->__onReady();
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
     * Get the ModuleInfo, includes all standalone module's settings and function
     *
     * @return ModuleInfo
     */
    public function getModuleInfo(): ModuleInfo
    {
        return $this->moduleInfo;
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
     * Check if the module is able to route
     *
     * @return bool
     */
    public function isRoutable(): bool
    {
        return $this->routable;
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
     * Register the API command.
     *
     * @param string $command The API command will register
     *
     * @return $this
     * @throws Throwable
     *
     */
    public function addAPICommand(string $command, string $path): static
    {
        $bindInternally = false;
        // Bind the API command internally
        if ($command[0] === '#') {
            $command = substr($command, 1);
            $bindInternally = true;
        }

        if (array_key_exists($command, $this->apiCommands)) {
            throw new Error('The command `' . $command . '` is already registered.');
        }
        $this->apiCommands[$command] = $path;

        if ($bindInternally) {
            $this->binding[$command] = $path;
        }

        return $this;
    }

    /**
     * Start to listen to the event.
     *
     * @param string $event
     * @param string|Closure $path
     * @return $this
     * @throws Error
     */
    public function listen(string $event, string|Closure $path): self
    {
        [$moduleCode, $eventName] = explode(':', $event);
        if (!isset($this->events[$moduleCode])) {
            $this->events[$moduleCode] = [];
        }

        if (array_key_exists($eventName, $this->events[$moduleCode])) {
            throw new Error('The event `' . $eventName . '` is already registered.');
        }
        $this->events[$moduleCode][$eventName] = $path;

        return $this;
    }

    /**
     * Get the EventEmitter instance from Distributor.
     *
     * @param string $event The event name
     * @param callable|null $callback The callback will be executed if the event is resolving
     *
     * @return EventEmitter
     */
    public function propagate(string $event, ?callable $callback = null): EventEmitter
    {
        return $this->distributor->createEmitter($this, $event, !$callback ? null : $callback(...));
    }

    /**
     * Check the event is dispatched.
     *
     * @param string $moduleCode
     * @param string $event The event name
     *
     * @return bool
     */
    public function isEventListening(string $moduleCode, string $event): bool
    {
        return isset($this->events[$moduleCode]) && array_key_exists($event, $this->events[$moduleCode]);
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
    public function fireEvent(string $moduleCode, string $event, array $args): mixed
    {
        $result = null;

        try {
            if (isset($this->events[$moduleCode]) && array_key_exists($event, $this->events[$moduleCode])) {
                $path = $this->events[$moduleCode][$event];
                if (is_string($path)) {
                    if (!str_contains($event, '/') && method_exists($this->controller, $event)) {
                        $closure = [$this->controller, $event];
                    } elseif (($closure = $this->getClosure($this->events[$event])) !== null) {
                        $closure = $closure->bindTo($this->controller);
                    }
                } else {
                    $result = call_user_func_array($path, $args);
                }
                $closure = null;

                if ($closure) {
                    $result = call_user_func_array($closure, $args);
                }
            }
        } catch (Throwable $exception) {
            $this->controller->__onError($event, $exception);
        }

        return $result;
    }

    /**
     * Prepare stage, module will trigger __onLoad event to determine the module is allowed to load or not.
     *
     * @return bool
     */
    public function prepare(): bool
    {
        if ($this->controller->__onLoad($this->agent)) {
            $this->status = self::STATUS_LOADED;
            return true;
        }

        return false;
    }

    /**
     * Add standard route into the route list, regular expression string supported.
     *
     * @param string $route The path of the route
     * @param string|Route $path The Route entity or the path of the closure file by the method name
     *
     * @return $this
     */
    public function addRoute(string $route, mixed $path): static
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
     * Add shadow route.
     *
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
     * Add CLI script path into the script list.
     *
     * @param string $route The path of the route
     * @param string $path The path of the closure file
     *
     * @return $this
     */
    public function addScript(string $route, string $path): self
    {
        $this->distributor->setScript($this, $route, $path);

        return $this;
    }

    /**
     * Trigger __onRouted event when the application has routed into a module
     */
    public function announce(ModuleInfo $moduleInfo): void
    {
        if (self::STATUS_LOADED === $this->status) {
            if (CLI_MODE) {
                $this->controller->__onScriptReady($moduleInfo);
            } else {
                $this->controller->__onRouted($moduleInfo);
            }
        }
    }

    /**
     * Trigger __onRequire event before ready stage
     *
     * @return bool
     */
    public function validate(): bool
    {
        $requires = $this->moduleInfo->getRequire();
        foreach ($requires as $moduleCode => $package) {
            $module = $this->distributor->getLoadedModule($moduleCode);
            if (!$module || $module->getStatus() !== self::STATUS_LOADED || !$module->require()) {
                $this->status = self::STATUS_LOADED;
                return false;
            }
        }
        return true;
    }

    /**
     * Start require the loaded module
     *
     * @return bool
     */
    public function require(): bool
    {
        return $this->controller->__onRequire();
    }

    /**
     * Get the global template entity
     *
     * @return Template
     */
    public function getGlobalTemplateEntity(): Template
    {
        return $this->distributor->getGlobalTemplateEntity();
    }

    /**
     * Get the distributor site root URL
     *
     * @return string
     */
    public function getSiteURL(): string
    {
        return $this->distributor->getSiteURL();
    }

    /**
     * Get the module relative URL path.
     *
     * @return string
     */
    public function getModuleURL(): string
    {
        return tidy(append($this->distributor->getSiteURL(), $this->moduleInfo->getAlias()), true, '/');
    }

    /**
     * @param array $routedInfo
     * @return $this
     */
    public function entry(array $routedInfo): static
    {
        $this->controller->__onEntry($routedInfo);
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
    public function addLazyRoute(string $route, string $path): static
    {
        $this->distributor->setLazyRoute($this, $route, $path);

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
     * Load the closure under the module controller folder.
     *
     * @param string $path
     *
     * @return null|Closure
     * @throws Error
     */
    public function getClosure(string $path): ?Closure
    {
        $path = tidy($path, false, '/');

        if (0 === substr_count($path, '/')) {
            if (method_exists($this->controller, $path)) {
                return $this->controller->$path(...);
            }
            // Standalone closure need should prefix with module code and placed in controller root
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
     * Get the module's API Emitter.
     *
     * @param string $moduleCode
     *
     * @return Emitter
     */
    public function getEmitter(string $moduleCode): Emitter
    {
        return $this->distributor->createAPI($this)->request($moduleCode);
    }

    /**
     * Load the module configuration file.
     *
     * @throws Error
     */
    public function loadConfig(): Configuration
    {
        $path = append(SYSTEM_ROOT, 'config', $this->distributor->getCode(), $this->moduleInfo->getClassName() . '.php');
        return new Configuration($path);
    }

    /**
     * Trigger onDispose event.
     *
     * @return $this
     */
    public function dispose(): static
    {
        $this->controller->__onDispose();
        return $this;
    }
}