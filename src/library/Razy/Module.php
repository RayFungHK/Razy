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
	 * The module is loaded successfully.
	 */
	public const STATUS_LOADED = 3;

	/**
	 * The module is unloaded, version not match the requirement or not in whitelist.
	 */
	public const STATUS_UNLOADED = 4;

	/**
	 * Some error cause, the module is not loaded.
	 */
	public const STATUS_FAILED = 5;

	/**
	 * @var Distributor
	 */
	private Distributor $distributor;

	/**
	 * @var string
	 */
	private string $modulePath;

	/**
	 * @var string
	 */
	private string $moduleRelativePath;

	/**
	 * @var string
	 */
	private string $code;

	/**
	 * @var string
	 */
	private string $version;

	/**
	 * @var string
	 */
	private string $author;

	/**
	 * @var string[]
	 */
	private array $require = [];

	/**
	 * @var string
	 */
	private string $namespace = '';

	/**
	 * @var null|Controller
	 */
	private ?Controller $controller = null;

	/**
	 * @var int
	 */
	private int $status = self::STATUS_DISABLED;

	/**
	 * @var mixed|string
	 */
	private string $className = '';

	/**
	 * @var string
	 */
	private string $alias = '';

	/**
	 * @var string
	 */
	private string $apiCode = '';

	/**
	 * @var bool[]
	 */
	private array $commands = [];

	/**
	 * @var bool[]
	 */
	private array $events = [];

	/**
	 * @var bool
	 */
	private bool $sharedModule;

	/**
	 * @var Pilot
	 */
	private Pilot $pilot;

	/**
	 * @var array
	 */
	private array $assets = [];

	/**
	 * @var array
	 */
	private array $binding  = [];
	private array $closures = [];

	/**
	 * Module constructor.
	 *
	 * @param Distributor $distributor  The Distributor instance
	 * @param string      $path         The folder path of the Distributor
	 * @param array       $settings     An array of the setting that included from the dist.php
	 * @param bool        $sharedModule
	 *
	 * @throws Throwable
	 */
	public function __construct(Distributor $distributor, string $path, array $settings, bool $sharedModule = false)
	{
		$this->distributor  = $distributor;
		$this->modulePath   = $path;
		$this->sharedModule = $sharedModule;
		if (0 === strpos($path, SYSTEM_ROOT)) {
			$relative                 = substr($path, strlen(SYSTEM_ROOT));
			$this->moduleRelativePath = ($relative) ?: '/';
		}

		if (isset($settings['module_code'])) {
			if (!is_string($settings['module_code'])) {
				throw new Error('The module code should be a string');
			}
			$code = trim($settings['module_code']);

			if (!preg_match('/^([a-z][\w]*)(\.[a-z][\w]*)*$/i', $code)) {
				throw new Error('The module code ' . $code . ' is not a correct format.');
			}

			$this->code      = $code;
			$extracted       = explode('.', $code);
			$this->className = end($extracted);
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
		if (0 === strlen($this->alias)) {
			$this->alias = $this->className;
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

		$this->apiCode = trim($settings['api'] ?? '');
		if (strlen($this->apiCode) > 0) {
			if (!preg_match('/^[a-z][\w]*$/i', $this->apiCode)) {
				throw new Error('Invalid API code format.');
			}
			$this->distributor->enableAPI($this);
		}

		if (isset($settings['require']) && is_array($settings['require'])) {
			foreach ($settings['require'] as $moduleCode => $version) {
				$moduleCode = trim($moduleCode);
				if (preg_match('/^([a-z][\w]*)(\.[a-z][\w]*)*$/', $moduleCode) && is_string($version)) {
					$this->require[$moduleCode] = trim($version);
				}
			}
		}

		$this->pilot = new Pilot($this);
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
	 * Return the module author.
	 *
	 * @return string
	 */
	public function getAuthor(): string
	{
		return $this->author;
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
	 * Get the require list of the module.
	 *
	 * @return array
	 */
	public function getRequire(): array
	{
		return $this->require;
	}

	/**
	 * @return \Razy\Template
	 */
	public function getTemplateEngine(): Template
	{
		return $this->distributor->getTemplateEngine();
	}

	/**
	 * Execute API command.
	 *
	 * @param string $command The API command
	 * @param array  $args    The arguments will pass to API command
	 *
	 * @throws Throwable
	 *
	 * @return mixed
	 */
	public function execute(string $command, array $args)
	{
		return $this->distributor->execute($command, $args);
	}

	/**
	 * @return string
	 */
	public function getDistCode(): string
	{
		return $this->distributor->getDistCode();
	}

	/**
	 * Start initial the module. If the controller is missing or the module cannot
	 * initialize (__onInit event), the module will not add into the loaded module list.
	 *
	 * @throws Throwable
	 *
	 * @return bool
	 */
	public function initialize(): bool
	{
		// If the Controller entity does not initialize
		if (null === $this->controller) {
			// TODO: Require
			// Load the controller
			$controllerPath = append($this->modulePath, 'controller', $this->className . '.php');

			if (is_file($controllerPath)) {
				if ($this->sharedModule) {
					$prefix = 'Razy\\Shared\\';
				} else {
					$prefix = 'Razy\\Module\\' . $this->distributor->getDistCode() . '\\';
				}
				// Replace dot into slash to convert as a namespace
				$this->namespace = $prefix . str_replace('.', '\\', $this->code);

				if (class_exists($this->namespace)) {
					throw new Error('The module has already declared');
				}

				// Import the class file.
				// The module controller class must extends Controller abstract class
				/** @noinspection PhpIncludeInspection */
				include $controllerPath;
				if (!class_exists($this->namespace)) {
					throw new Error('The class ' . $this->namespace . ' does not declared.');
				}

				if (class_exists($this->namespace)) {
					// Create controller instance, the routing, event and API will be configured in controller
					$this->controller = new $this->namespace($this);
					// Ensure the controller is inherited by Controller class
					if (!$this->controller instanceof Controller) {
						throw new Error('The controller must instance of Controller');
					}

					$this->status = self::STATUS_INITIALING;
					$this->status = (!$this->controller->__onInit($this->pilot)) ? self::STATUS_FAILED : self::STATUS_LOADED;

					return true;
				}

				throw new Error('The class ' . $this->namespace . ' does not declared.');
			}

			throw new Error('The controller ' . $controllerPath . ' does not exists.');
		}

		return true;
	}

	/**
	 * Set the module is ready.
	 */
	public function ready(): void
	{
		$this->status = self::STATUS_READY;
	}

	/**
	 * Trigger __onReady event when all modules has loaded.
	 */
	public function notify(): void
	{
		if (self::STATUS_READY === $this->status) {
			$this->controller->__onReady();
			$this->status = self::STATUS_LOADED;
		}
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
	 * Get the module class name.
	 *
	 * @return string
	 */
	public function getClassName(): string
	{
		return $this->className;
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
	 * Add lazy route into the route list.
	 *
	 * @param string $route The path of the route
	 * @param string $path  The path of the closure file
	 *
	 * @return $this
	 */
	public function addLazyRoute(string $route, string $path): self
	{
		$route = '/' . tidy(append($this->getAlias(), $route), true, '/');
		$this->distributor->setLazyRoute($this, $route, $path);

		return $this;
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
	 * Add standard route into the route list, regular expression string supported.
	 *
	 * @param string $route The path of the route
	 * @param string $path  The path of the closure file of the method name
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
	 * Start listen the event.
	 *
	 * @param string $event The event name
	 *
	 * @throws Throwable
	 *
	 * @return $this
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
	 * Get the module file path.
	 *
	 * @return string
	 */
	public function getPath(): string
	{
		return $this->modulePath;
	}

	/**
	 * Get the module relative URL path.
	 *
	 * @return string
	 */
	public function getURLPath(): string
	{
		return append($this->distributor->getURLPath(), $this->moduleRelativePath);
	}

	/**
	 * @return string
	 */
	public function getRootURL(): string
	{
		return $this->distributor->getURLPath();
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
	 * Get the module's controller.
	 *
	 * @return \Razy\Controller
	 */
	public function getController(): Controller
	{
		return $this->controller;
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
	 * Execute the API command.
	 *
	 * @param string $command The API command
	 * @param array  $args    The arguments will pass to the API command
	 *
	 * @throws Throwable
	 *
	 * @return null|mixed
	 */
	public function accessAPI(string $command, array $args)
	{
		try {
			if (array_key_exists($command, $this->commands)) {
				if (($closure = $this->getClosure($this->commands[$command])) !== null) {
					return call_user_func_array($closure->bindTo($this->controller), $args);
				}
			}
		} catch (Throwable $exception) {
			$this->controller->__onError($command, $exception);

			return null;
		}

		return null;
	}

	/**
	 * Trigger the event.
	 *
	 * @param string $event The event name
	 * @param array  $args  The arguments will pass to listener
	 *
	 * @throws Throwable
	 *
	 * @return null|mixed
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
	 * @param string $path
	 *
	 * @throws \Razy\Error
	 *
	 * @return null|\Closure
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
	 * Register the API command.
	 *
	 * @param string $command The API command will register
	 *
	 * @throws Throwable
	 *
	 * @return $this
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
	 * Get the API code.
	 *
	 * @return string
	 */
	public function getAPICode(): string
	{
		return $this->apiCode;
	}

	/**
	 * Get the EventEmitter instance from Distributor.
	 *
	 * @param string   $event    The event name
	 * @param callable $callback The callback will be executed if the event is resolving
	 *
	 * @return EventEmitter
	 */
	public function propagate(string $event, callable $callback): EventEmitter
	{
		return $this->distributor->createEmitter($this, $event, $callback);
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
	 * Connect another domain API.
	 *
	 * @param string $fqdn The well-formatted FQDN
	 *
	 * @throws Throwable
	 *
	 * @return null|API
	 */
	public function connect(string $fqdn): ?API
	{
		return $this->distributor->connect($fqdn);
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
		$unpackedAsset = [];
		$folder        = append(trim($folder), $this->code);
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
	 * Load the module configuration file.
	 *
	 * @throws \Razy\Error
	 */
	public function loadConfig(): Configuration
	{
		$path = append(SYSTEM_ROOT, 'config', $this->distributor->getDistCode(), $this->code . '.php');

		return new Configuration($path);
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
	 * Check the route is conflicted and return the conflicted module code.
	 *
	 * @param string $urlQuery
	 *
	 * @return string
	 */
	public function conflict(string $urlQuery): string
	{
		return $this->distributor->simulate($urlQuery, $this->code);
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
     * Trigger __onDispatch event when the application has routed into a module
     */
    public function standby(string $moduleCode): void
    {
        if (self::STATUS_LOADED === $this->status) {
            $this->controller->__onDispatch($moduleCode);
        }
    }

	/**
	 * Redirect to the specified url when all modules are ready.
	 *
	 * @param string $url
	 *
	 * @return $this
	 */
	public function redirect(string $url): Module
	{
		$this->distributor->setRedirect($url);

		return $this;
	}

	/**
	 * Get the routed path.
	 *
	 * @return string
	 */
	public function getRoutedPath(): string
	{
		return $this->distributor->getRoutedPath();
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
}
