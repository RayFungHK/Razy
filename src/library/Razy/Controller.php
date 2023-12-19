<?php
/*
 * This file is part of Razy v0.4.
 *
 * (c) Ray Fung 2021 <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

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
use Razy\Template\Source;
use Throwable;

abstract class Controller
{
    const PLUGIN_TEMPLATE = 1;
    const PLUGIN_COLLECTION = 2;

    /**
     * The storage of the external closures
     *
     * @var array
     */
    private array $externalClosure = [];
    /**
     * The Module entity
     *
     * @var ?Module
     */
    private ?Module $module;

    /**
     * Controller constructor
     *
     * @param Module|null $module
     */
    final public function __construct(?Module $module = null)
    {
        $this->module = $module;
    }

    /**
     * Controller method bridge.
     * When the method called which is not declared, the Controller will
     * inject the Closure from the specified path that is configured in __onInit state.
     *
     * @param string $method The string of the method name which is called
     * @param array $arguments The arguments will pass to the method
     *
     * @return mixed The return result of the method
     * @throws Throwable
     */
    final public function __call(string $method, array $arguments)
    {
        if ($path = $this->module->getBinding($method)) {
            if (null !== ($closure = $this->module->getClosure($path))) {
                return call_user_func_array($closure, $arguments);
            }
        }

        $moduleInfo = $this->module->getModuleInfo();
        $path = append($moduleInfo->getPath(), 'controller', $moduleInfo->getClassName() . '.' . $method . '.php');

        if (is_file($path)) {
            /** @var Closure $closure */
            $closure = require $path;
            if (!is_callable($closure) && $closure instanceof Closure) {
                throw new Error('The object is not a Closure.');
            }

            $this->externalClosure[$method] = $closure->bindTo($this);
        }
        $closure = $this->externalClosure[$method] ?? null;
        if (!$closure) {
            throw new Error('The method `' . $method . '` is not defined in `' . get_class($this) . '`.');
        }

        return call_user_func_array($closure, $arguments);
    }

	/**
	 * Controller Event __onAPICall, will be executed if the module is accessed via API. Return false to refuse API
	 * access.
	 *
	 * @param ModuleInfo $module The ModuleInfo entity that is accessed via API
	 * @param string $method The command method will be called via API
	 * @param string $fqdn The well-formatted FQDN string includes the domain name and distributor code
	 *
	 * @return bool Return false to refuse API access
	 */
    public function __onAPICall(ModuleInfo $module, string $method, string $fqdn = ''): bool
    {
        return true;
    }

    /**
     * __onDispatch event, all modules will be executed before the routed method executes
     *
     * @param ModuleInfo $module
     *
     * @return void
     */
    public function __onDispatch(ModuleInfo $module): void
    {
    }

    /**
     * __onScriptLoaded event, all modules will be executed before the matched script execute
     *
     * @param ModuleInfo $module
     *
     * @return void
     */
    public function __onScriptLoaded(ModuleInfo $module): void
    {
    }

    /**
     * Handling the error of the closure.
     *
     * @param string $path
     * @param Throwable $exception
     *
     * @throws Throwable
     */
    public function __onError(string $path, Throwable $exception): void
    {
        Error::ShowException($exception);
    }

    /**
     * Controller Event __onInit, will be executed if the module is loaded. Return false to mark the module loaded
     * failed.
     *
     * @param Pilot $pilot
     *
     * @return bool Return true if the module is loaded, or return false to mark the module status as "Failed"
     */
    public function __onInit(Pilot $pilot): bool
    {
        return true;
    }

    /**
     * Trigger in preload stage, used to set up the module.
     * Return false to prevent entering the routing stage, the remaining modules in queue will not trigger the preload event.
     *
     * @param Pilot $pilot
     *
     * @return bool
     */
    public function __onPreload(Pilot $pilot): bool
    {
        return true;
    }

    /**
     * Controller Event __onReady, will be executed if all modules are loaded in a system.
     */
    public function __onReady(): void
    {
    }

    /**
     * Controller Event __onRoute, will be executed before the route closure is executed. Return false that route is not
     * accepted.
     *
     * @param array $args
     *
     * @return bool
     */
    public function __onRoute(array $args): bool
    {
        return true;
    }

    /**
     * Controller Event __onScriptCall, will be executed before the route closure is executed in CLI. Return false that the CLI script is not allowed to execute.
     *
     * @param array $args
     *
     * @return bool
     */
    public function __onScriptCall(array $args): bool
    {
        return true;
    }

    /**
     * __onTouch event, handling touch request from another module.
     *
     * @param ModuleInfo $module
     * @param string $version
     * @param string $message
     *
     * @return bool
     */
    public function __onTouch(ModuleInfo $module, string $version, string $message = ''): bool
    {
        return true;
    }

    /**
     * Trigger before routing stage, return false to put the module into preload stage.
     *
     * @param Pilot $pilot
     *
     * @return bool
     */
    public function __onValidate(Pilot $pilot): bool
    {
        return true;
    }

    /**
     * Get the API Emitter.
     *
     * @param string $moduleCode
     * @return Emitter
     */
    final public function api(string $moduleCode): Emitter
    {
        return $this->module->getEmitter($moduleCode);
    }

    /**
     * Connect to another application by given FQDN in the same Razy structure.
     *
     * @param string $fqdn The well-formatted FQDN string
     *
     * @return null|API return the API instance if the Application is connected successfully
     * @throws Throwable
     */
    final public function connect(string $fqdn): ?API
    {
        if (!is_fqdn($fqdn)) {
            throw new Error('Invalid format of the string of FQDN.');
        }

        return $this->module->connect($fqdn);
    }

    /**
     * Get the module's shared asset URL.
     *
     * @return string
     */
    final public function getAssetPath(): string
    {
        return append($this->module->getSiteURL(), 'webassets', $this->module->getModuleInfo()->getAlias(), $this->module->getModuleInfo()->getVersion()) . '/';
    }

    /**
     * Get the module's data folder of the application.
     *
     * @param string $module
     *
     * @return string
     */
    final public function getDataPath(string $module = ''): string
    {
        return $this->module->getDataPath($module);
    }

    /**
     * Get the root URL of the distributor.
     *
     * @return string
     */
    final public function getSiteURL(): string
    {
        return $this->module->getSiteURL();
    }

    /**
     * Get the distributor request path
     *
     * @return string
     */
    final public function getRequestPath(): string
    {
        return $this->module->getRequestPath();
    }

    /**
     * Get the module code.
     *
     * @return string
     */
    final public function getModuleCode(): string
    {
        return $this->module->getModuleInfo()->getCode();
    }

    /**
     * Get the Configuration entity.
     *
     * @return Configuration
     * @throws Error
     */
    final public function getModuleConfig(): Configuration
    {
        return $this->module->loadConfig();
    }

    /**
     * Get the module system path.
     *
     * @return string
     */
    final public function getModulePath(): string
    {
        return $this->module->getModuleInfo()->getPath();
    }

    /**
     * Get the Module version.
     *
     * @return string
     */
    final public function getModuleVersion(): string
    {
        return $this->module->getModuleInfo()->getVersion();
    }

    /**
     * Get the routed information.
     *
     * @return array
     */
    final public function getRoutedInfo(): array
    {
        return $this->module->getRoutedInfo();
    }

    /**
     * Get the template engine entity.
     *
     * @return Template
     */
    final public function getTemplate(): Template
    {
        return $this->module->getTemplateEngine();
    }

    /**
     * Get the URLQuery string.
     *
     * @return string
     */
    final public function getURLQuery(): string
    {
        return $this->module->getURLQuery();
    }

    /**
     * Get the module's view URL.
     *
     * @return string
     */
    final public function getViewPath(): string
    {
        return append($this->module->getBaseURL(), 'view', $this->module->getModuleInfo()->getVersion());
    }

    /**
     * Get the root URL of the module.
     *
     * @return string
     */
    final public function getBaseURL(): string
    {
        return $this->module->getBaseURL();
    }

    final public function addPluginFolder(int $flag = 0): self
    {
        if ($flag & self::PLUGIN_TEMPLATE) {
            Template::addPluginFolder(append($this->getModulePath(), 'plugins', 'Template'), $this);
        }

        if ($flag & self::PLUGIN_COLLECTION) {
            Template::addPluginFolder(append($this->getModulePath(), 'plugins', 'Collection'), $this);
        }
        return $this;
    }

    /**
     * Redirect to a specified path in the module
     *
     * @param string $path
     *
     * @return void
     */
    final public function goto(string $path): void
    {
        header('location: ' . append($this->getBaseURL(), $path), true, 301);
        exit;
    }

    /**
     * Send a handshake to one or a list of modules, return true if the module is available.
     *
     * @param string $modules
     * @param string $message
     *
     * @return bool
     */
    final public function handshake(string $modules, string $message = ''): bool
    {
        $modules = explode(',', $modules);
        foreach ($modules as $module) {
            $module = trim($module);
            if (!$this->module->handshake($module, $message)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the ModuleInfo object.
     *
     * @return ModuleInfo
     */
    final public function getModuleInfo(): ModuleInfo
    {
        return $this->module->getModuleInfo();
    }

    /**
     * Load the template file.
     *
     * @param string $path
     *
     * @return Source
     * @throws Throwable
     */
    final public function load(string $path): Template\Source
    {
        $path = $this->getViewFile($path);
        if (!is_file($path)) {
            throw new Error('The path ' . $path . ' is not a valid path.');
        }
        $template = $this->module->getTemplateEngine();

        return $template->load($path, $this->getModuleInfo());
    }

    /**
     * Get the view file system path.
     *
     * @param string $path
     *
     * @return string
     */
    final public function getViewFile(string $path): string
    {
        $path = append($this->module->getModuleInfo()->getPath(), 'view', $path);
        $filename = basename($path);
        if (!preg_match('/[^.]+\..+/', $filename)) {
            $path .= '.tpl';
        }

        return $path;
    }

    /**
     * Prepare the EventEmitter to trigger the event.
     *
     * @param string $event The name of the event
     * @param callable $callback the callback to execute when the EventEmitter start to resolve
     *
     * @return EventEmitter The EventEmitter instance
     */
    final public function trigger(string $event, callable $callback): EventEmitter
    {
        return $this->module->propagate($event, $callback);
    }

    /**
     * Get the parsed template content by given list of sources.
     *
     * @param array $sources
     *
     * @throws Throwable
     */
    final public function view(array $sources): void
    {
        echo $this->module->getTemplateEngine()->outputQueued($sources);
    }

    /**
     * Get the XHR entity.
     *
     * @return XHR
     */
    final public function xhr(): XHR
    {
        return new XHR();
    }

    /**
     * Execute the internal function by given path, also an alternative of direct access the method.
     *
     * @param string $path
     * @param array ...$args
     *
     * @return mixed|null
     * @throws Error
     */
    final public function fork(string $path, array ...$args): mixed
    {
        if ($closure = $this->module->getClosure($path)) {
            return call_user_func_array($closure, $args);
        }
        return null;
    }
}
