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
use Razy\API\Emitter;
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
     * The module is unloaded, version not match the requirement or not in whitelist.
     */
    public const STATUS_UNLOADED = -1;

    /**
     * Some error cause, the module is not loaded.
     */
    public const STATUS_FAILED = -2;
    /**
     * The alias of the module, default as package name
     * @var string
     */
    private string $alias = '';
    /**
     * The API command alias
     * @var string
     */
    private string $apiAlias = '';
    /**
     * The storage of the assets
     * @var array
     */
    private array $assets = [];
    /**
     * The module author
     * @var string
     */
    private string $author;
    /**
     * @var array
     */
    private array $binding = [];
    /**
     * @var array
     */
    private array $closures = [];
    /**
     * The module code
     * @var string
     */
    private string $code;
    /**
     * The storage of the API commands
     * @var bool[]
     */
    private array $commands = [];
    /**
     * The Controller entity
     * @var null|Controller
     */
    private ?Controller $controller = null;
    /**
     * The Distributor entity
     * @var Distributor
     */
    private Distributor $distributor;
    /**
     * The storage of the events
     * @var bool[]
     */
    private array $events = [];
    /**
     * The module package folder system path
     * @var string
     */
    private string $modulePath;
    /**
     * The package name
     * @var string
     */
    private string $packageName = '';
    /**
     * The Pilot entity
     * @var Pilot
     */
    private Pilot $pilot;
    /**
     * The storage of the required modules
     * @var string[]
     */
    private array $require = [];
    /**
     * Is the module a shared module
     * @var bool
     */
    private bool $sharedModule = false;
    /**
     * The status of the module
     * @var int
     */
    private int $status = self::STATUS_DISABLED;
    /**
     * The module version
     * @var string
     */
    private string $version;

    /**
     * Module constructor.
     *
     * @param Distributor $distributor The Distributor instance
     * @param string $path The folder path of the Distributor
     * @param array $settings An array of the setting that included from the dist.php
     * @param bool $sharedModule
     *
     * @throws Throwable
     */
    public function __construct(Distributor $distributor, string $path, array $settings, bool $sharedModule = false)
    {
        $this->distributor  = $distributor;
        $this->modulePath   = $path;
        $this->sharedModule = $sharedModule;

        if (isset($settings['module_code'])) {
            if (!is_string($settings['module_code'])) {
                throw new Error('The module code should be a string');
            }
            $code = trim($settings['module_code']);

            if (!preg_match('/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$/i', $code)) {
                throw new Error('The module code ' . $code . ' is not a correct format, it should be `vendor/package`.');
            }

            $this->code      = $code;
            [, $package]     = explode('/', $code);
            $this->packageName = $package;
        } else {
            throw new Error('Missing module code.');
        }

        $this->version = trim($settings['version'] ?? '');
        if (!$this->version) {
            throw new Error('Missing module version.');
        }

        $this->author = trim($settings['author'] ?? '');
        if (!$this->author) {
            throw new Error('Missing module author.');
        }

        $this->alias = trim($settings['alias'] ?? '');
        if (empty($this->alias)) {
            $this->alias = $this->packageName;
        }

        if (!is_array($settings['assets'] = $settings['assets'] ?? [])) {
            $settings['assets'] = [];
        }
        foreach ($settings['assets'] as $asset => $destPath) {
            $assetPath = fix_path(append($this->modulePath, $asset), DIRECTORY_SEPARATOR, true);
            if (false !== $assetPath) {
                $this->assets[realpath($assetPath)] = $destPath;
            }
        }

        if (!is_array($settings['prerequisite'] = $settings['prerequisite'] ?? [])) {
            $settings['prerequisite'] = [];
        }
        foreach ($settings['prerequisite'] as $package => $version) {
            if (is_string($package) && is_string($version)) {
                $this->distributor->prerequisite($package, $version);
            }
        }

        $this->apiAlias = trim($settings['api'] ?? '');
        if (strlen($this->apiAlias) > 0) {
            if (!preg_match('/^[a-z]\w*$/i', $this->apiAlias)) {
                throw new Error('Invalid API code format.');
            }
            $this->distributor->enableAPI($this);
        }

        if (isset($settings['require']) && is_array($settings['require'])) {
            foreach ($settings['require'] as $moduleCode => $version) {
                $moduleCode = trim($moduleCode);
                if (preg_match('/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$/i', $moduleCode) && is_string($version)) {
                    $this->require[$moduleCode] = trim($version);
                }
            }
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
     * Add standard route into the route list, regular expression string supported.
     *
     * @param string $route The path of the route
     * @param string $path The path of the closure file of the method name
     *
     * @return $this
     */
    public function addRoute(string $route, string $path): self
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
     * @param string $command The API command
     * @param array $args The arguments will pass to API command
     *
     * @return mixed
     * @throws Throwable
     *
     */
    public function execute(string $command, array $args)
    {
        return $this->accessAPI($command, $args);
    }

    /**
     * Execute the API command.
     *
     * @param string $command The API command
     * @param array $args The arguments will pass to the API command
     *
     * @return null|mixed
     * @throws Throwable
     *
     */
    public function accessAPI(string $command, array $args)
    {
        $result = null;
        $this->distributor->attention();
        try {
            if (array_key_exists($command, $this->commands)) {
                if (($closure = $this->getClosure($this->commands[$command])) !== null) {
                    $result = call_user_func_array($closure->bindTo($this->controller), $args);
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
            $path = $this->getClassName() . '.' . $path;
        }
        $path = append($this->getPath(), 'controller', $path . '.php');

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
     * Get the module class name.
     *
     * @return string
     */
    public function getClassName(): string
    {
        return $this->packageName;
    }

    /**
     * Get the module file path.
     *
     * @return string
     */
    public function getPath(): string
    {
        return $this->modulePath;
    }

    /**
     * Trigger the event.
     *
     * @param string $event The event name
     * @param array $args The arguments will pass to listener
     *
     * @return null|mixed
     * @throws Throwable
     */
    public function fireEvent(string $event, array $args)
    {
        try {
            if (array_key_exists($event, $this->events)) {
                if (($closure = $this->getClosure($this->events[$event])) !== null) {
                    return call_user_func_array($closure, $args);
                }
            }
        } catch (Throwable $exception) {
            $this->controller->__onError($event, $exception);

            return null;
        }

        return null;
    }

    /**
     * Get the API code.
     *
     * @return string
     */
    public function getAPICode(): string
    {
        return $this->apiAlias;
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
     * Return the module author.
     *
     * @return string
     */
    public function getAuthor(): string
    {
        return $this->author;
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
     * Get the module code.
     *
     * @return string
     */
    public function getCode(): string
    {
        return $this->code;
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
     * @return Emitter
     */
    public function getEmitter(string $moduleCode): API\Emitter
    {
        return $this->distributor->createAPI()->request($moduleCode);
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
     * Get the routed information.
     *
     * @return array
     */
    public function getRoutedInfo(): array
    {
        return $this->distributor->getRoutedInfo();
    }

    /**
     * Get the syy
     *
     * @return string
     */
    public function getSiteURL(): string
    {
        return $this->distributor->getBaseURL();
    }

    /**
     * Get the module relative URL path.
     *
     * @return string
     */
    public function getBaseURL(): string
    {
        return tidy(append($this->distributor->getBaseURL(), $this->alias), true, '/');
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
     * Return the module version.
     *
     * @return string
     */
    public function getVersion(): string
    {
        return $this->version;
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
        return $this->distributor->handshakeTo($moduleCode, $this->code, $this->version, $message);
    }

    /**
     * Start initial the module. If the controller is missing or the module cannot
     * initialize (__onInit event), the module will not add into the loaded module list.
     *
     * @return bool
     * @throws Throwable
     */
    public function initialize(): bool
    {
        // If the Controller entity does not initialize
        if (null === $this->controller) {
            // TODO: Require
            // Load the controller
            $controllerPath = append($this->modulePath, 'controller', $this->packageName . '.php');

            if (is_file($controllerPath)) {
                // Import the anonymous class file.
                // The module controller object must extend Controller abstract class
                $controller = include $controllerPath;

                $reflected = new ReflectionClass($controller);

                if ($reflected->isAnonymous()) {
                    // Create controller instance, the routing, event and API will be configured in controller
                    $this->controller = $reflected->newInstance($this);

                    // Ensure the controller is inherited by Controller class
                    if (!$this->controller instanceof Controller) {
                        throw new Error('The controller must instance of Controller');
                    }

                    $this->status = self::STATUS_INITIALING;
                    $this->status = (!$this->controller->__onInit($this->pilot)) ? self::STATUS_FAILED : self::STATUS_WAITING_VALIDATE;

                    // Seal the pilot
                    $this->pilot = clone $this->pilot;
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
     * Return true if the module is a shared module.
     *
     * @return bool
     */
    public function isShared(): bool
    {
        return $this->sharedModule;
    }

    /**
     * Start listen the event.
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
        $path = append(SYSTEM_ROOT, 'config', $this->distributor->getDistCode(), $this->packageName . '.php');

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

    public function preload(): bool
    {
        return $this->controller->__onPreload($this->pilot);
    }

    /**
     * Send the signal to controller that the distributor trying to route in. Return false to refuse.
     *
     * @param array $args
     *
     * @return bool
     */
    public function prepare(array $args): bool
    {
        return $this->controller->__onRoute($args);
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
    public function standby(string $moduleCode): void
    {
        if (self::STATUS_LOADED === $this->status) {
            $this->controller->__onDispatch($moduleCode);
        }
    }

    /**
     * Touch to inform it has handshake from other module.
     *
     * @param string $moduleCode
     * @param string $version
     * @param string $message
     *
     * @return bool
     */
    public function touch(string $moduleCode, string $version, string $message): bool
    {
        return $this->controller->__onTouch($moduleCode, $version, $message);
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
     * Unpack the module asset to shared view folder.
     *
     * @param string $folder
     *
     * @return array
     */
    public function unpackAsset(string $folder): array
    {
        if (empty($this->assets)) {
            return [];
        }

        $unpackedAsset = [];
        $folder        = append(trim($folder), $this->alias);
        if (!is_dir($folder)) {
            try {
                mkdir($folder, 0777, true);
            } catch (Exception $e) {
                return [];
            }
        }

        foreach ($this->assets as $assetPath => $destPath) {
            xcopy($assetPath, append($folder, $destPath), '', $unpacked);
            $unpackedAsset = array_merge($unpackedAsset, $unpacked);
        }

        return $unpackedAsset;
    }

    /**
     * Validate the module is ready to initial, if the event return false, it will put the module into preload list.
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
}
