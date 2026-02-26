<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Core module lifecycle manager responsible for loading, initializing,
 * and orchestrating module controllers including routing, API commands,
 * bridge commands, event listeners, and inter-module communication.
 *
 * @package Razy
 * @license MIT
 */

namespace Razy;

use Closure;
use Razy\Contract\ContainerInterface;
use Razy\Contract\DistributorInterface;
use Razy\Contract\MiddlewareInterface;
use Razy\Contract\ModuleInterface;
use Razy\Distributor\RouteDispatcher;
use Razy\Module\ClosureLoader;
use Razy\Module\CommandRegistry;
use Razy\Module\EventDispatcher;
use Razy\Exception\ModuleConfigException;
use Razy\Exception\ModuleLoadException;
use Razy\Module\ModuleStatus;
use ReflectionClass;
use Throwable;

use Razy\Util\PathUtil;
/**
 * Represents a single loaded module within a Distributor context.
 *
 * Manages the full module lifecycle: loading the controller, initialization,
 * prerequisite validation, API/bridge command registration, event dispatching,
 * route registration, and inter-module communication. Each Module instance
 * wraps a Controller and its associated ModuleInfo metadata.
 *
 * @class Module
 */
class Module implements ModuleInterface
{
    /** @var bool Whether this module can handle route requests */
    private bool $routable = true;

    /** @var ClosureLoader Manages closure file loading and binding */
    private ClosureLoader $closureLoader;

    /** @var CommandRegistry Manages API and bridge command registration */
    private CommandRegistry $commands;

    /** @var EventDispatcher Manages event listener registration and dispatching */
    private EventDispatcher $eventDispatcher;

    /** @var Controller|null The module's controller instance */
    private ?Controller $controller = null;

    /** @var ModuleInfo Module metadata and configuration */
    private ModuleInfo $moduleInfo;

    /** @var Agent The agent proxy for this module */
    private Agent $agent;

    /** @var ThreadManager Thread manager for background tasks */
    private ThreadManager $threadManager;

    /** @var ModuleStatus Current lifecycle status of the module */
    private ModuleStatus $status = ModuleStatus::Pending;

    /** @var bool Whether the module has completed its first initialization */
    private bool $initialized = false;

    /** @var Container|null Module-level child container (delegates to Application container) */
    private ?Container $container = null;

    /**
     * Module constructor.
     *
     * @param Distributor $distributor The Distributor instance
     * @param string $path The folder path of the Distributor
     * @param array $moduleConfig
     * @param string $version The specified version to load, leave blank to load default or dev
     * @param bool $sharedModule
     * @param bool $standalone When true, uses ultra-flat standalone layout (no version subdir, no package.php)
     *
     * @throws ModuleConfigException
     */
    public function __construct(private readonly DistributorInterface $distributor, string $path, array $moduleConfig, string $version = 'default', bool $sharedModule = false, bool $standalone = false)
    {
        $this->moduleInfo = new ModuleInfo($path, $moduleConfig, $version, $sharedModule, null, $standalone);

        // Register this module's API name with the distributor if one is defined
        if (strlen($this->moduleInfo->getAPIName()) > 0) {
            $this->distributor->getRegistry()->registerAPI($this);
        }

        // Register each prerequisite package with the distributor for conflict tracking
        $moduleCode = $this->moduleInfo->getCode();
        foreach ($this->moduleInfo->getPrerequisite() as $package => $pVersion) {
            $this->distributor->getPrerequisites()->prerequisite($package, $pVersion, $moduleCode);
        }

        // Create module-level child container (parent = Application container)
        $this->initializeContainer();

        // Create sub-objects via factory method (shared with resetForWorker)
        $this->agent = $this->createAgent();
        $this->threadManager = $this->createThreadManager();

        // Initialize sub-objects for delegated responsibilities
        $this->closureLoader = new ClosureLoader($this->moduleInfo, $this->distributor->isStrict(), $this->distributor->getCode());
        $this->commands = new CommandRegistry();
        $this->eventDispatcher = new EventDispatcher();
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
        // In worker mode (Caddy/FrankenPHP), reset module state between requests
        if (defined('WORKER_MODE') && WORKER_MODE && $this->initialized && $this->controller) {
            $this->resetForWorker();
            // Re-run initialization with a fresh agent
            $this->status = ModuleStatus::Initialing;
            $this->status = (!$this->controller->__onInit($this->agent)) ? ModuleStatus::Failed : ModuleStatus::InQueue;
            return true;
        }

        // Verify that all prerequisite packages are installed at compatible versions
        $moduleCode = $this->moduleInfo->getCode();
        foreach ($this->moduleInfo->getPrerequisite() as $package => $pVersion) {
            $satisfies = $this->distributor->getPrerequisites()->checkInstalledVersion($package, $pVersion);
            if (false === $satisfies) {
                // Installed version doesn't satisfy this module's constraint
                throw new ModuleLoadException(
                    "Module '{$moduleCode}' failed to load: prerequisite version conflict.\n" .
                    "Package '{$package}' requires '{$pVersion}', but installed version is incompatible.\n" .
                    "Run 'php Razy.phar compose {$this->distributor->getCode()}' to resolve dependencies."
                );
            }
        }

        // If the Controller entity does not initialize
        if (null === $this->controller) {
            // Build the expected controller file path from module metadata
            $controllerPath = PathUtil::append($this->moduleInfo->getPath(), 'controller', $this->moduleInfo->getClassName() . '.php');

            if (is_file($controllerPath)) {
                // Include the controller file, which should return an anonymous class
                $controller = include $controllerPath;

                $reflected = new ReflectionClass($controller);

                if ($reflected->isAnonymous()) {
                    // Instantiate the anonymous controller class, passing this Module
                    $this->controller = $reflected->newInstance($this);

                    // Validate the controller extends the abstract Controller class
                    if (!$this->controller instanceof Controller) {
                        throw new ModuleLoadException(
                            "Controller for module '{$this->moduleInfo->getCode()}' must extend Razy\\Controller, got '" . get_class($this->controller) . "'.\n" .
                            "File: {$controllerPath}"
                        );
                    }

                    // Run initialization: __onInit returns false on failure
                    $this->status = ModuleStatus::Initialing;
                    $this->status = (!$this->controller->__onInit($this->agent)) ? ModuleStatus::Failed : ModuleStatus::InQueue;
                    $this->initialized = true;

                    return true;
                }

                throw new ModuleLoadException(
                    "Controller at '{$controllerPath}' for module '{$this->moduleInfo->getCode()}' must return an anonymous class extending Controller.\n" .
                    "Got: " . get_class($controller)
                );
            }

            // D2 Fix: Improved error message with expected file path and naming convention
            throw new ModuleLoadException(
                "Main controller file not found for module '{$this->moduleInfo->getCode()}'.\n" .
                "Expected file: {$controllerPath}\n" .
                "The controller filename must match the module class name: {$this->moduleInfo->getClassName()}.php\n" .
                "Ensure the file exists and follows the naming convention."
            );
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
        $this->distributor->getRegistry()->addAwait($moduleCode, $caller);

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
        return $this->commands->execute($module, $command, $args, $this->controller, $this->closureLoader);
    }

    /**
     * Execute an API command internally without ModuleInfo validation.
     * Used by the internal HTTP bridge.
     *
     * @param string $command
     * @param array $args
     *
     * @return mixed
     */
    public function executeInternalCommand(string $command, array $args): mixed
    {
        return $this->commands->executeInternalCommand($command, $args, $this->controller, $this->closureLoader);
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
        return $this->distributor->getRegistry()->handshakeTo($moduleCode, $this->moduleInfo, $this->moduleInfo->getVersion(), $message);
    }

    /**
     * Update module status to processing that it is already in require list
     *
     * @return $this
     */
    public function standby(): Module
    {
        if (ModuleStatus::Pending === $this->status) {
            $this->status = ModuleStatus::Processing;
        }

        return $this;
    }

    /**
     * Reset module state for Caddy/FrankenPHP worker mode
     * Called between requests to clear request-specific state
     *
     * @return void
     */
    private function resetForWorker(): void
    {
        // Reset routing state
        $this->routable = true;

        // Clear closures, bindings, and events (they will be re-registered in __onInit)
        $this->closureLoader->reset();
        $this->eventDispatcher->reset();

        // Remove this module's listener registrations from centralized index
        $this->distributor->getRegistry()->unregisterModuleListeners($this);

        // Reset agent and thread manager via factory methods
        $this->agent = $this->createAgent();
        $this->threadManager = $this->createThreadManager();

        // Clear scoped singleton instances in the module container
        $this->container?->forgetScopedInstances();

        // Controller persists but its state should be reset
        // The controller's __onInit will handle its own reset
    }

    /**
     * Reload the module from disk during worker mode without process restart.
     *
     * This method supports the "Rebind" strategy (Strategy C+) for worker
     * lifecycle management. It re-includes the controller file (which uses
     * anonymous classes), reloads package.php config, rebinds container
     * services, and re-runs initialization.
     *
     * Because Razy controllers use anonymous classes (`return new class extends Controller`),
     * each re-include produces a new unique class definition that does not
     * conflict with the previously loaded one. The old controller instance
     * and its anonymous class definition will be garbage-collected when all
     * references are released (though the class entry persists in PHP's
     * class table until process termination).
     *
     * @return bool True if reload succeeded
     */
    public function reloadFromDisk(): bool
    {
        if ($this->controller === null) {
            return false;
        }

        try {
            // 1. Build controller path and re-include
            $controllerPath = PathUtil::append(
                $this->moduleInfo->getPath(),
                'controller',
                $this->moduleInfo->getClassName() . '.php'
            );

            if (!is_file($controllerPath)) {
                return false;
            }

            $newController = include $controllerPath;
            $reflected = new ReflectionClass($newController);

            if (!$reflected->isAnonymous()) {
                // Named class detected — cannot rebind, needs restart
                return false;
            }

            // 2. Instantiate the new controller
            $newControllerInstance = $reflected->newInstance($this);

            if (!$newControllerInstance instanceof Controller) {
                return false;
            }

            // 3. Replace controller reference (old one will be GC'd)
            $this->controller = $newControllerInstance;

            // 4. Reset subsystems (same as resetForWorker)
            $this->routable = true;
            $this->closureLoader->reset();
            $this->eventDispatcher->reset();
            $this->distributor->getRegistry()->unregisterModuleListeners($this);
            $this->agent = $this->createAgent();
            $this->threadManager = $this->createThreadManager();

            // 5. Reload container bindings from refreshed package.php
            $this->reloadContainerBindings();

            // 6. Re-initialize the controller
            $this->status = ModuleStatus::Initialing;
            $this->status = (!$this->controller->__onInit($this->agent))
                ? ModuleStatus::Failed
                : ModuleStatus::InQueue;

            return $this->status !== ModuleStatus::Failed;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Reload container service bindings from package.php.
     *
     * Removes old service bindings, re-reads package.php via
     * ModuleInfo::reloadConfig(), and registers the new bindings
     * using Container::rebind() for atomic replacement with
     * change notification.
     */
    private function reloadContainerBindings(): void
    {
        if ($this->container === null) {
            return;
        }

        // Track old service keys for cleanup
        $oldServices = $this->moduleInfo->getServices();

        // Reload package.php to get fresh services
        $this->moduleInfo->reloadConfig();
        $newServices = $this->moduleInfo->getServices();

        // Remove bindings that no longer exist in new config
        foreach ($oldServices as $abstract => $_) {
            if (!isset($newServices[$abstract])) {
                $this->container->forget($abstract);
            }
        }

        // Rebind updated or new services
        foreach ($newServices as $abstract => $concrete) {
            if ($this->container->bound($abstract)) {
                $this->container->rebind($abstract, $concrete);
            } else {
                $this->container->bind($abstract, $concrete);
            }
        }
    }

    /**
     * Initialize the module-level child container.
     *
     * The child container delegates to the Application container as parent,
     * allowing module-specific bindings to override or extend application services.
     * Module metadata bindings (from package.php 'services' key) are registered
     * automatically if present.
     */
    private function initializeContainer(): void
    {
        $parentContainer = $this->distributor->getContainer();

        if ($parentContainer instanceof Container) {
            $this->container = new Container($parentContainer);
        } elseif ($parentContainer !== null) {
            // Parent is a ContainerInterface but not our Container — wrap it
            $this->container = new Container($parentContainer);
        }

        // Register module-specific service bindings from ModuleInfo
        // These come from the 'services' key in the module's package.php
        if ($this->container !== null) {
            // Bind this module's ModuleInfo so child-container resolves get the local instance
            $this->container->bind(ModuleInfo::class, fn() => $this->moduleInfo);

            foreach ($this->moduleInfo->getServices() as $abstract => $concrete) {
                $this->container->bind($abstract, $concrete);
            }
        }
    }

    /**
     * Create a new Agent instance for this module.
     * Uses the DI Container if available, falls back to direct instantiation.
     *
     * @return Agent
     */
    private function createAgent(): Agent
    {
        $container = $this->getContainer();
        if ($container) {
            return $container->make(Agent::class, ['module' => $this]);
        }
        return new Agent($this);
    }

    /**
     * Create a new ThreadManager instance.
     * Uses the DI Container if available, falls back to direct instantiation.
     *
     * @return ThreadManager
     */
    private function createThreadManager(): ThreadManager
    {
        $container = $this->getContainer();
        if ($container) {
            return $container->make(ThreadManager::class);
        }
        return new ThreadManager();
    }

    /**
     * Get the module thread manager.
     *
     * @return ThreadManager
     */
    public function getThreadManager(): ThreadManager
    {
        return $this->threadManager;
    }

    /**
     * Unload the module.
     *
     * @return $this
     */
    public function unload(): Module
    {
        if (ModuleStatus::Failed !== $this->status) {
            $this->status = ModuleStatus::Unloaded;
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
     * @return ModuleStatus
     */
    public function getStatus(): ModuleStatus
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
        return $this->distributor->getRouter()->getRoutedInfo();
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
        $this->commands->addAPICommand($command, $path, $this->closureLoader);

        return $this;
    }

    /**
     * Get all registered API commands.
     *
     * @return array<string, string> Array of command => closure path
     */
    public function getAPICommands(): array
    {
        return $this->commands->getAPICommands();
    }

    /**
     * Register a bridge command for cross-distributor communication.
     * Bridge commands are separate from API commands - they are exposed to other distributors.
     *
     * @param string $command The bridge command name
     * @param string $path The path to the closure file
     *
     * @return $this
     * @throws Error
     */
    public function addBridgeCommand(string $command, string $path): static
    {
        $this->commands->addBridgeCommand($command, $path);

        return $this;
    }

    /**
     * Get all registered bridge commands.
     *
     * @return array<string, string> Array of command => closure path
     */
    public function getBridgeCommands(): array
    {
        return $this->commands->getBridgeCommands();
    }

    /**
     * Execute a bridge command for cross-distributor calls.
     * Unlike API commands, bridge commands are designed for external distributor access.
     *
     * @param string $sourceDistributor The identifier of the calling distributor
     * @param string $command The bridge command
     * @param array $args The arguments to pass
     *
     * @return mixed
     * @throws Throwable
     */
    public function executeBridgeCommand(string $sourceDistributor, string $command, array $args): mixed
    {
        return $this->commands->executeBridgeCommand($sourceDistributor, $command, $args, $this->controller, $this->closureLoader);
    }

    /**
     * Register a listener for an event from another module.
     *
     * The listener is always registered, but returns whether the target module
     * is currently loaded. This helps developers know if their listener will fire:
     * - true: Target module is loaded, listener will fire when event is triggered
     * - false: Target module not loaded yet (may load later, or may never load)
     *
     * @param string $event Event name in format 'vendor/module:event_name'
     * @param string|Closure $path Closure or path to closure file
     * @return bool True if target module is loaded, false otherwise
     * @throws Error If event is already registered
     */
    public function listen(string $event, string|Closure $path): bool
    {
        $this->eventDispatcher->listen($event, $path);

        // Register in centralized listener index for O(1) event resolution
        [$moduleCode, $eventName] = explode(':', $event);
        $this->distributor->getRegistry()->registerListener($moduleCode, $eventName, $this);

        return $this->distributor->getRegistry()->getLoadedModule($moduleCode) !== null;
    }

    /**
     * Create an EventEmitter instance to fire an event.
     *
     * Creates an EventEmitter that can be used to trigger listeners.
     * Call resolve() on the returned emitter to dispatch the event.
     *
     * Flow: createEmitter() -> EventEmitter::resolve() -> Module::fireEvent() -> Listeners
     *
     * @param string $event The event name to fire
     * @param callable|null $callback Optional callback executed when resolving
     *
     * @return EventEmitter The emitter instance - call resolve() to dispatch
     *
     * @see Controller::trigger() Preferred method from controllers
     */
    public function createEmitter(string $event, ?callable $callback = null): EventEmitter
    {
        return $this->distributor->getRegistry()->createEmitter($this, $event, !$callback ? null : $callback(...));
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
        return $this->eventDispatcher->isEventListening($moduleCode, $event);
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
        return $this->eventDispatcher->fireEvent($moduleCode, $event, $args, $this->controller, $this->closureLoader);
    }

    /**
     * Prepare stage, module will trigger __onLoad event to determine the module is allowed to load or not.
     *
     * @return bool
     */
    public function prepare(): bool
    {
        if ($this->controller->__onLoad($this->agent)) {
            $this->status = ModuleStatus::Loaded;
            return true;
        }

        return false;
    }

    /**
     * Add standard route into the route list, regular expression string supported.
     *
     * Supports HTTP method prefix syntax: 'GET /users', 'POST /api/data', 'GET|POST /form'.
     * Routes without a method prefix match any HTTP method.
     *
     * @param string $route The path of the route (optionally prefixed with HTTP method)
     * @param string|Route $path The Route entity or the path of the closure file by the method name
     *
     * @return $this
     */
    public function addRoute(string $route, mixed $path): static
    {
        // Parse HTTP method prefix (e.g., 'GET /users' → method='GET', route='/users')
        [$method, $route] = RouteDispatcher::parseMethodPrefix($route);

        // Standardize the route string
        $route = rtrim(PathUtil::tidy($route, false, '/'), '/');

        if (!$route) {
            $route = '/';
        }

        // R1 Fix: Auto-prepend leading slash if missing
        if ($route !== '/' && $route[0] !== '/') {
            $route = '/' . $route;
        }

        $this->distributor->getRouter()->setRoute($this, $route, $path, $method);

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
        $targetModule = $this->distributor->getRegistry()->getLoadedModule($moduleCode);
        $this->distributor->getRouter()->setShadowRoute($this, $route, $targetModule, $path);

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
        $this->distributor->getRouter()->setScript($this, $route, $path);

        return $this;
    }

    /**
     * Trigger __onRouted event when the application has routed into a module
     */
    public function announce(ModuleInfo $moduleInfo): void
    {
        if (ModuleStatus::Loaded === $this->status) {
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
        // Check each required module is loaded and can satisfy its own requirements
        $requires = $this->moduleInfo->getRequire();
        foreach ($requires as $moduleCode => $package) {
            $module = $this->distributor->getRegistry()->getLoadedModule($moduleCode);
            // Fail if the required module isn't loaded, isn't fully loaded, or fails its own require()
            if (!$module || $module->getStatus() !== ModuleStatus::Loaded || !$module->require()) {
                $this->status = ModuleStatus::Loaded;
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
     * Get the module-level DI container. Falls back to the Distributor chain
     * (Application container) if no module container was created.
     *
     * @return ContainerInterface|null The Container instance, or null if unavailable
     */
    public function getContainer(): ?ContainerInterface
    {
        return $this->container ?? $this->distributor->getContainer();
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
        return PathUtil::tidy(PathUtil::append($this->distributor->getSiteURL(), $this->moduleInfo->getAlias()), true, '/');
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
     * Supports HTTP method prefix syntax: 'GET /users', 'POST /api/data'.
     * Routes without a method prefix match any HTTP method.
     *
     * @param string $route The path of the route (optionally prefixed with HTTP method)
     * @param string $path The path of the closure file
     *
     * @return $this
     */
    public function addLazyRoute(string $route, string $path): static
    {
        // Parse HTTP method prefix (e.g., 'POST /submit' → method='POST', route='/submit')
        [$method, $route] = RouteDispatcher::parseMethodPrefix($route);

        $this->distributor->getRouter()->setLazyRoute($this, $route, $path, $method);

        return $this;
    }

    /**
     * Register module-level middleware for all routes of this module.
     *
     * @param MiddlewareInterface|Closure ...$middleware
     * @return $this
     */
    public function addModuleMiddleware(MiddlewareInterface|Closure ...$middleware): static
    {
        $this->distributor->getRouter()->addModuleMiddleware(
            $this->moduleInfo->getCode(),
            ...$middleware
        );
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
        $this->closureLoader->bind($method, $path);

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
        return $this->closureLoader->getBinding($method);
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
        return $this->closureLoader->getClosure($path, $this->controller);
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
        return $this->distributor->getRegistry()->createAPI($this)->request($moduleCode);
    }

    /**
     * Load the module configuration file.
     *
     * @throws Error
     */
    public function loadConfig(): Configuration
    {
        $path = PathUtil::append(SYSTEM_ROOT, 'config', $this->distributor->getCode(), $this->moduleInfo->getClassName() . '.php');
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