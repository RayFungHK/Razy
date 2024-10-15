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

class Distributor
{
	/**
	 * The storage of the Distributor stacking
	 *
	 * @var Distributor[]
	 */
	private static array $stacking = [];
	/**
	 * The storage of the Module that has API alias
	 *
	 * @var array
	 */
	private array $APIModules = [];
	/**
	 * The storage of the await function
	 *
	 * @var array
	 */
	private array $awaitList = [];
	/**
	 * The `autoload` setting
	 *
	 * @var bool
	 */
	private bool $autoloadShared = false;
	/**
	 * The distributor code
	 *
	 * @var string
	 */
	private string $code;
	/**
	 * The storage of the Module that enabled in distributor
	 *
	 * @var array
	 */
	private array $enableModules = [];
	/**
	 * The `greedy` setting
	 *
	 * @var bool
	 */
	private bool $greedy = false;
	/**
	 * The storage of the lazy route
	 *
	 * @var array
	 */
	private array $lazyRoute = [];
	/**
	 * The storage of the CLI script
	 *
	 * @var array
	 */
	private array $registeredScript = [];
	/**
	 * The storage of the Module entity
	 *
	 * @var Module[]
	 */
	private array $modules = [];

	/**
	 * The storage of prerequisites, used for composer.
	 *
	 * @var array
	 */
	private array $prerequisites = [];
	/**
	 * The storage of the regular expression route
	 *
	 * @var array
	 */
	private array $regexRoute = [];
	/**
	 * The storage of the routed information
	 *
	 * @var array
	 */
	private array $routedInfo = [];
	/**
	 * The Template entity
	 *
	 * @var ?Template
	 */
	private ?Template $templateEngine = null;

    private array $callFlow = [];

	/**
	 * Distributor constructor.
	 *
	 * @param string $folderPath The folder path of the distributor
	 * @param string $identifier The distributor identifier
	 * @param null|Domain $domain The Domain Instance
	 * @param string $urlPath The URL path of the distributor
	 * @param string $urlQuery The URL Query string
	 *
	 * @throws Throwable
	 */
	public function __construct(private string $folderPath, private readonly string $identifier = '*', private readonly ?Domain $domain = null, private readonly string $urlPath = '', private string $urlQuery = '/')
	{
		$this->folderPath = append(SITES_FOLDER, $this->folderPath);
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

		if (!preg_match('/^[a-z]\w*$/i', $config['dist'])) {
			throw new Error('Invalid distribution code.');
		}
		$this->code = $config['dist'];

		// Put the main distributor into the stacking list
		self::$stacking[] = $this;

		// Not allow calling API if it has no domain
		if ($this->domain) {
			// If this distributor is accessed by internal API call, check the pass list
			if (($peer = $this->domain->getPeer()) !== null) {
				// Remove self
				array_pop(self::$stacking);

				$peerDomain = $peer->getDomain()->getDomainName();
				$acceptConnection = false;
				// Load the external config in a data path by its distribution folder: $this->getDataPath()
				foreach ($config['whitelist'] ?? [] as $whitelist) {
					if (is_fqdn($whitelist)) {
						if ('*' === $whitelist) {
							$acceptConnection = true;

							break;
						}
						if (str_contains($whitelist, '*')) {
							$whitelist = preg_replace('/\\\\.(*SKIP)(*FAIL)|\*/', '[^.]+', $whitelist);
						}

						if (preg_match('/^' . $whitelist . '$/', $peerDomain)) {
							$acceptConnection = true;

							break;
						}
					}
				}

				if (!$acceptConnection) {
					throw new Error($peerDomain . ' is not allowed to access to ' . $this->domain->getDomainName() . '::' . $this->getDistCode());
				}
			}
		}

		$config['enable_module'] = $config['enable_module'] ?? [];
		if (is_array($config['enable_module'])) {
			if (isset($config['enable_module'][$this->identifier])) {
				foreach ($config['enable_module'][$this->identifier] as $moduleCode => $version) {
					$moduleCode = trim($moduleCode);
					$version = trim($version);
					if (strlen($moduleCode) > 0 && strlen($version) > 0) {
						$this->enableModules[$moduleCode] = $version;
					}
				}
			}
		}

		$config['autoload_shared'] = (bool)($config['autoload_shared'] ?? false);
		$this->autoloadShared = $config['autoload_shared'];

		$this->greedy = (bool)($config['greedy'] ?? false);

		// Load all modules into the pending list
		$this->loadModule($this->folderPath);

		if ($config['autoload_shared']) {
			// Load shared modules into the pending list
			$this->loadModule(append(SHARED_FOLDER, 'module'), true);
		}


		// Initialize all modules in the pending list
		foreach ($this->modules as $module) {
			// Check the module is in an enabled list and activate it
			if ((!$this->greedy && !array_key_exists($module->getModuleInfo()->getCode(), $this->enableModules)) || !$this->activate($module)) {
				$module->unload();
			} else {
				$module->ready();
			}
		}

		$preloadQueue = [];
		// Validate all modules
		foreach ($this->modules as $module) {
			if ($module->getStatus() !== MODULE::STATUS_UNLOADED && !$module->validate()) {
				$preloadQueue[] = $module;
			}
		}

		// Preload all modules, if the module preloads event return false,
		// the preload queue will stop and not enter the routing stage.
		$readyToRoute = true;
		if (!empty($preloadQueue)) {
			foreach ($preloadQueue as $module) {
				if (!$module->preload()) {
					$readyToRoute = false;
					break;
				}
			}
		}

		$this->setSession();
		// If the distributor is under a domain, initial all modules.
		if ($this->domain) {
			foreach ($this->modules as $module) {
				if ($module->getStatus() === MODULE::STATUS_READY) {
					// Notify module that the system is ready
					$module->notify();
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
			}
		}

		// Start routing if it is in the Web mode
		if (WEB_MODE) {
			if ($readyToRoute) {
				// Convert the regex route
				$regexRoute = $this->regexRoute;
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

					$data['route'] = $route;
					$route = '/^' . preg_replace('/\\\\.(*SKIP)(*FAIL)|\//', '\\/', $route) . '$/';

					$this->regexRoute[$route] = $data;
				}
			}
		}
	}

	/**
	 * Get the distributor code.
	 *
	 * @return string
	 */
	public function getDistCode(): string
	{
		return $this->code . ($this->identifier !== '*' ? '@' . $this->identifier : '');
	}

	/**
	 * Load the module under the distributor folder.
	 *
	 * @param string $path The path of the module folder
	 *
	 * @throws Error
	 * @throws Throwable
	 */
	private function loadModule(string $path, bool $loadShared = false): void
	{
		$path = tidy($path, true);
		if (!is_dir($path)) {
			return;
		}

		foreach (scandir($path) as $vendor) {
			if ('.' === $vendor || '..' === $vendor) {
				continue;
			}

			// Get the author's folder path
			$authorFolder = append($path, $vendor);
			if (is_dir($authorFolder)) {
				foreach (scandir($authorFolder) as $packageName) {
					if ('.' === $packageName || '..' === $packageName) {
						continue;
					}

					$namespace = $vendor . '/' . $packageName;
					$packageFolder = append($authorFolder, $packageName);
					if (is_dir($packageFolder)) {
						$version = 'default';
						if (isset($this->enableModules[$namespace])) {
							$version = $this->enableModules[$namespace];
						}

						if (is_file(append($packageFolder, $version, 'package.php'))) {
							try {
								$module = new Module($this, $packageFolder, $version, $loadShared);

								if (!isset($this->modules[$module->getModuleInfo()->getCode()])) {
									$this->modules[$module->getModuleInfo()->getCode()] = $module;
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

	/**
	 * Start to activate the module.
	 *
	 * @param Module $module
	 *
	 * @return bool
	 * @throws Throwable
	 */
	private function activate(Module $module): bool
	{
		$requireList = $module->getModuleInfo()->getRequire();
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
			} elseif (Module::STATUS_FAILED === $reqModule->getStatus()) {
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

	/**
	 * Set the cookie path and start the session.
	 *
	 * @return $this
	 */
	public function setSession(): self
	{
		if (WEB_MODE) {
			session_set_cookie_params(0, '/', HOSTNAME);
			session_name($this->code);
			session_start();
		}

		return $this;
	}

	/**
	 * SPL autoload function.
	 *
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
	 * Autoload the class from the distributor's module library folder
	 *
	 * @param string $className
	 *
	 * @return bool
	 */
	public function autoload(string $className): bool
	{
		if (preg_match('/^([a-z0-9](?:[_.-]?[a-z0-9]+)*\\\\[a-z0-9](?:(?:[_.]?|-{0,2})[a-z0-9]+)*)\\\\(.+)/', $className, $matches)) {
			$moduleCode = str_replace('\\', '/', $matches[1]);
			$className = $matches[2];
			// Try to load the class from the module library
			if (isset($this->modules[$moduleCode])) {
				$module = $this->modules[$moduleCode];
				$moduleInfo = $module->getModuleInfo();
				$path = append($moduleInfo->getPath(), 'library');
				if (is_dir($path)) {
					$libraryPath = append($path, $className . '.php');
					if (is_file($libraryPath)) {
						try {
							include $libraryPath;

							return class_exists($className);
						} catch (Exception) {
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
	 * Check if the distributor allows loading the module in shared folder.
	 *
	 * @return bool
	 */
	public function allowAutoloadShared(): bool
	{
		return $this->autoloadShared;
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
	 * Create the API instance.
	 *
	 * @return API
	 */
	public function createAPI(): API
	{
		return new API($this);
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
	 * Enable API protocol.
	 *
	 * @param Module $module The module instance
	 *
	 * @return $this
	 */
	public function enableAPI(Module $module): self
	{
		if (strlen($module->getModuleInfo()->getAPICode()) > 0) {
			$this->APIModules[$module->getModuleInfo()->getAPICode()] = $module;
		}

		return $this;
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
	 * Get the distributor URL path.
	 *
	 * @return string
	 */
	public function getBaseURL(): string
	{
		return (defined('RAZY_URL_ROOT')) ? append(RAZY_URL_ROOT, $this->urlPath) : '';
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
			return append($this->getBaseURL(), 'data', $module);
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
	 * Get the distributor request path
	 *
	 * @return string
	 */
	public function getRequestPath(): string
	{
		return $this->domain->getDomainName() . '/' . $this->code;
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
	 * Get the routed information after the route is matched.
	 *
	 * @return array
	 */
	public function getRoutedInfo(): array
	{
		return $this->routedInfo;
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
	 * Get the URLQuery string.
	 *
	 * @return string
	 */
	public function getURLQuery(): string
	{
		return $this->urlQuery;
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
	 * Match the registered route and execute the matched path.
	 *
	 * @return bool
	 * @throws Throwable
	 */
	public function matchRoute(): bool
	{
		$this->attention();
		if (CLI_MODE) {
			foreach ($this->registeredScript as $path => $data) {
				$urlQuery = $this->urlQuery;
				if (str_starts_with($urlQuery, $path)) {
					// Extract the url query string when the route is matched
					$urlQuery = rtrim(substr($urlQuery, strlen($path)), '/');
					$args = explode('/', $urlQuery);

					/** @var Module $module */
					$module = $data['module'];
					if (Module::STATUS_LOADED === $module->getStatus()) {
                        $path = (is_string($data['path'])) ? $data['path'] : $data['path']->getClosurePath();
						if (!$path || !($closure = $module->getClosure($data['path']))) {
							return false;
						}

						if ($module->prepare($args)) {
							$this->routedInfo = [
								'url_query' => $this->urlQuery,
								'route' => $data['route'],
								'module' => $module->getModuleInfo()->getCode(),
								'closure_path' => $path,
								'arguments' => $args,
							];

                            if (!is_string($data['path'])) {
                                $this->routedInfo['contains'] = $data['path']->getData();
                            }

							$this->dispatch($module);

                            $module->entry($this->routedInfo);
							call_user_func_array($closure, $args);
						}

						return true;
					}
				}
			}
		} else {
            $this->stackState(Controller::STATE_ROUTE);
			// Match regex route first
			foreach ($this->regexRoute as $regex => $data) {
				if (1 === preg_match($regex, $this->urlQuery, $matches)) {
					$route = array_shift($matches);

					/** @var Module $module */
					$module = $data['module'];
					if (Module::STATUS_LOADED === $module->getStatus()) {
                        $path = (is_string($data['path'])) ? $data['path'] : $data['path']->getClosurePath();
						if (!$path || !($closure = $module->getClosure($path))) {
							return false;
						}

						if ($module->prepare($matches)) {
							$this->routedInfo = [
								'url_query' => $this->urlQuery,
								'route' => $route,
								'module' => $module->getModuleInfo()->getCode(),
								'closure_path' => $path,
								'arguments' => $matches,
								'last' => '',
							];

                            if (!is_string($data['path'])) {
                                $this->routedInfo['contains'] = $data['path']->getData();
                            }

                            $this->dispatch($module);

                            $module->entry($this->routedInfo);
							call_user_func_array($closure, $matches);
						}

						return true;
					}
				}
			}

			sort_path_level($this->lazyRoute);

			// Match lazy route
			$urlQuery = $this->urlQuery;
			foreach ($this->lazyRoute as $route => $data) {
				if (str_starts_with($urlQuery, $route)) {
					// Extract the url query string when the route is matched
					$urlQuery = rtrim(substr($urlQuery, strlen($route)), '/');
					$args = explode('/', $urlQuery);

					/** @var Module $module */
					$sourceModule = $data['module'];
					$executeModule = (isset($data['target'])) ? $data['target'] : $data['module'];
					if (Module::STATUS_LOADED === $sourceModule->getStatus()) {
                        $path = (is_string($data['path'])) ? $data['path'] : $data['path']->getClosurePath();
						if (!$path || !($closure = $executeModule->getClosure($data['path']))) {
							continue;
						}

						if ($sourceModule->prepare($args)) {
							$this->routedInfo = [
								'url_query' => $this->urlQuery,
								'route' => $data['route'],
								'module' => $sourceModule->getModuleInfo()->getCode(),
								'closure_path' => $path,
								'arguments' => $args,
								'is_shadow' => isset($data['target']),
							];

                            if (!is_string($data['path'])) {
                                $this->routedInfo['contains'] = $data['path']->getData();
                            }

                            $this->dispatch($sourceModule);

                            $sourceModule->entry($this->routedInfo);
							call_user_func_array($closure, $args);
						}

						return true;
					}
				}
			}
            $this->releaseState();
		}
		return false;
	}

	/**
	 * Put the current distributor into a stacking list.
	 *
	 * @return $this
	 */
	public function attention(): self
	{
		self::$stacking[] = $this;

		return $this;
	}

	/**
	 * Trigger all module __onDispatch event when the Application before route into a module.
	 *
	 * @param Module $target
	 */
	private function dispatch(Module $target): void
	{
		if ($this->domain) {
			foreach ($this->modules as $module) {
				if (Module::STATUS_LOADED === $module->getStatus()) {
					$module->standby($target->getModuleInfo());
				}
			}
		}
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
	 * Get the enabled module by given module code.
	 *
	 * @param string $moduleCode
	 *
	 * @return Module|null
	 */
	public function requestModule(string $moduleCode): ?Module
	{
		$module = $this->APIModules[$moduleCode] ?? $this->modules[$moduleCode] ?? null;
		return ($module && $module->getStatus() >= 2) ? $module : null;
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
		$this->lazyRoute['/' . tidy(append($module->getModuleInfo()->getAlias(), $route), true, '/')] = [
			'module' => $module,
			'path' => $path,
			'route' => $route,
		];

		return $this;
	}

	public function registerScript(Module $module, string $route, string $path): self
	{
		$this->registeredScript['/' . tidy(append($module->getModuleInfo()->getAlias(), $route), true, '/')] = [
			'module' => $module,
			'path' => $path,
			'route' => $route,
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
	public function setRoute(Module $module, string $route, mixed $path): self
	{
		$this->regexRoute[$route] = [
			'module' => $module,
			'path' => $path,
		];

		return $this;
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
	 * Put the callable into the list to wait for executing until other specified modules has ready.
	 *
	 * @param string $moduleCode
	 * @param Closure $caller
	 *
	 * @return $this
	 */
	public function addAwait(string $moduleCode, Closure $caller): Distributor
	{
		$entity = [
			'required' => [],
			'caller' => $caller,
		];

		$clips = explode(',', $moduleCode);
		foreach ($clips as $code) {
			if (preg_match('/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$/i', $code)) {
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
	 * @param Module $module
	 * @param string $route
	 * @param string $moduleCode
	 * @param string $path
	 * @return $this
	 */
	public function setShadowRoute(Module $module, string $route, string $moduleCode, string $path): self
	{
		$targetModule = $this->getModule($moduleCode);
		if ($targetModule) {
			$this->lazyRoute['/' . tidy(append($module->getModuleInfo()->getAlias(), $route), true, '/')] = [
				'module' => $module,
				'target' => $targetModule,
				'path' => $path,
				'route' => $route,
			];
		}

		return $this;
	}

	/**
	 * Execute dispose event.
	 *
	 * @return $this
	 */
	public function dispose(): self
	{
		foreach ($this->modules as $module) {
			if ($module->getStatus() === Module::STATUS_LOADED) {
				$module->dispose();
			}
		}

		return $this;
	}

    /**
     * @param string $state
     * @return $this
     */
    public function stackState(string $state): self
    {
        $this->callFlow[] = $state;
        return $this;
    }

    /**
     * @return string
     */
    public function getState(): string
    {
        return end($this->callFlow);
    }

    /**
     * @return $this
     */
    public function releaseState(): self
    {
        array_pop($this->callFlow);
        return $this;
    }
}
