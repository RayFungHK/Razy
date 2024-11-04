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
use Razy\Database\Statement;
use Razy\Template\Source;
use Throwable;

/**
 * The Controller class is responsible for handling the lifecycle events of a module,
 * managing assets, configurations, templates, and API interactions.
 */
class Controller {
    const PLUGIN_ALL = 0b1111;
    const PLUGIN_TEMPLATE = 0b0001;
    const PLUGIN_COLLECTION = 0b0010;
    const PLUGIN_FLOWMANAGER = 0b0100;
    const PLUGIN_STATEMENT = 0b1000;

    private array $externalClosure = [];
    private array $cachedAPI = [];

    /**
     * Controller constructor
     *
     * @param Module|null $module The module associated with this controller
     */
    final public function __construct(private readonly ?Module $module = null) { }

    /**
     * Controller Event __onInit, will be triggered when the module is scanned and ready to load.
     *
     * @param Agent $agent The agent responsible for module initialization
     *
     * @return bool Return true if the module is loaded, or return false to mark the module status as "Failed"
     */
    public function __onInit(Agent $agent): bool {
        return true;
    }

    /**
     * __onDispose event, all modules will be executed after route and script is completed
     *
     * @return void
     */
    public function __onDispose(): void { }

    /**
     * __onDispatch event, all modules will be executed before verify the module require
     * Return false to remove from the queue
     *
     * @return bool
     */
    public function __onDispatch(): bool {
        return true;
    }

    /**
     * __onRouted event, will trigger when other module has matched the route.
     *
     * @param ModuleInfo $moduleInfo Information about the matched module
     *
     * @return void
     */
    public function __onRouted(ModuleInfo $moduleInfo): void { }

    /**
     * __onScriptReady event, will be triggered when other module is ready to execute the script.
     *
     * @param ModuleInfo $module Information about the module ready to execute the script
     *
     * @return void
     */
    public function __onScriptReady(ModuleInfo $module): void { }

    /**
     * __onLoad event, trigger after all modules are loaded in queue.
     *
     * @param Agent $agent The agent responsible for loading the module
     * @return bool
     */
    public function __onLoad(Agent $agent): bool {
        return true;
    }

    /**
     * Controller Event __onReady, will be triggered if all modules are loaded
     */
    public function __onReady(): void { }

    /**
     * __onEntry event, execute when route is matched and ready to execute
     *
     * @param array $routedInfo Information about the matched route
     *
     * @return void
     */
    public function __onEntry(array $routedInfo): void { }

    /**
     * Handling the error of the closure.
     *
     * @param string $path The path where the error occurred
     * @param Throwable $exception The exception that was thrown
     *
     * @throws Throwable
     */
    public function __onError(string $path, Throwable $exception): void {
        Error::ShowException($exception);
    }

    /**
     * Controller Event __onAPICall, will be executed if the module is accessed via API.
     * Return false to refuse API access.
     *
     * @param ModuleInfo $module The ModuleInfo entity that is accessed via API
     * @param string $method The command method will be called via API
     * @param string $fqdn The well-formatted FQDN string includes the domain name and distributor code
     *
     * @return bool Return false to refuse API access
     */
    public function __onAPICall(ModuleInfo $module, string $method, string $fqdn = ''): bool {
        return true;
    }

    /**
     * Get the module's asset URL that defined in .htaccess rewrite, before running the application the rewrite must be updated in CLI first.
     *
     * @return string The asset URL
     */
    final public function getAssetPath(): string {
        return append(
                $this->module->getSiteURL(),
                'webassets',
                $this->module->getModuleInfo()->getAlias(),
                $this->module->getModuleInfo()->getVersion()
            ) . '/';
    }

    /**
     * Get the module's data folder of the application.
     *
     * @param string $module The module name
     *
     * @return string The data path
     */
    final public function getDataPath(string $module = ''): string {
        return $this->module->getDataPath($module);
    }

    /**
     * Get the Configuration entity.
     *
     * @return Configuration
     * @throws Error
     */
    final public function getModuleConfig(): Configuration {
        return $this->module->loadConfig();
    }

    /**
     * Get the root URL of the module.
     *
     * @return string The module URL
     */
    final public function getModuleURL(): string {
        return $this->module->getModuleURL();
    }

    /**
     * Redirect to a specified path in the module
     *
     * @param string $path The path to redirect to
     * @param array $query The query parameters to append to the URL
     * @return void
     */
    final public function goto(string $path, array $query = []): void {
        header('location: ' . append($this->getModuleURL(), $path), true, 301);
        exit;
    }

    /**
     * Prepare the EventEmitter to trigger the event.
     *
     * @param string $event The name of the event
     * @param callable|null $callback The callback to execute when the EventEmitter starts to resolve
     *
     * @return EventEmitter The EventEmitter instance
     */
    final public function trigger(string $event, ?callable $callback = null): EventEmitter {
        return $this->module->propagate($event, !$callback ? null : $callback(...));
    }

    /**
     * Load the template file.
     *
     * @param string $path The path to the template file
     *
     * @return Source
     * @throws Throwable
     */
    final public function loadTemplate(string $path): Template\Source {
        $path = $this->getTemplateFilePath($path);
        if (!is_file($path)) {
            throw new Error('The path ' . $path . ' is not a valid path.');
        }
        $template = $this->module->getGlobalTemplateEntity();
        return $template->load($path, $this->getModuleInfo());
    }

    /**
     * Get the XHR entity.
     *
     * @param bool $returnAsArray Whether to return the XHR data as an array
     * @return XHR
     */
    final public function xhr(bool $returnAsArray = false): XHR {
        return new XHR($returnAsArray);
    }

    /**
     * Get the view file system path.
     *
     * @param string $path The path to the view file
     *
     * @return string The full file system path to the view
     */
    final public function getTemplateFilePath(string $path): string {
        $path = append($this->module->getModuleInfo()->getPath(), 'view', $path);
        $filename = basename($path);
        if (!preg_match('/[^.]+\..+/', $filename)) {
            $path .= '.tpl';
        }
        return $path;
    }

    /**
     * Get the ModuleInfo object.
     *
     * @return ModuleInfo
     */
    final public function getModuleInfo(): ModuleInfo {
        return $this->module->getModuleInfo();
    }

    /**
     * Get the distributor site root URL.
     *
     * @return string
     */
    final public function getSiteURL(): string {
        return $this->module->getSiteURL();
    }

    /**
     * Get the Module version.
     *
     * @return string The module version
     */
    final public function getModuleVersion(): string {
        return $this->module->getModuleInfo()->getVersion();
    }

    /**
     * Get the module code.
     *
     * @return string The module code
     */
    final public function getModuleCode(): string {
        return $this->module->getModuleInfo()->getCode();
    }

    /**
     * Get the global template entity.
     *
     * @return Template The global template entity
     */
    final public function getTemplate(): Template {
        return $this->module->getGlobalTemplateEntity();
    }

    /**
     * Get the routed information.
     *
     * @return array The routed information
     */
    final public function getRoutedInfo(): array {
        return $this->module->getRoutedInfo();
    }

    /**
     * Get the API Emitter.
     *
     * @param string $moduleCode The module code for which to get the Emitter
     * @return Emitter The Emitter instance
     */
    final public function api(string $moduleCode): Emitter {
        $this->cachedAPI[$moduleCode] = $this->cachedAPI[$moduleCode] ?? $this->module->getEmitter($moduleCode);
        return $this->cachedAPI[$moduleCode];
    }

    /**
     * Get the parsed template content by given list of sources.
     *
     * @param array $sources The list of template sources
     *
     * @throws Throwable
     */
    final public function view(array $sources): void {
        echo $this->module->getGlobalTemplateEntity()->outputQueued($sources);
    }

    /**
     * Get the module system path.
     *
     * @return string The module system path
     */
    final public function getModuleSystemPath(): string {
        return $this->module->getModuleInfo()->getPath();
    }

    /**
     * Register the module's plugin loader
     *
     * @param int $flag The flag to determine which plugins to load
     * @return $this
     */
    final public function registerPluginLoader(int $flag = 0): self {
        if ($flag & self::PLUGIN_TEMPLATE) {
            Template::AddPluginFolder(append($this->getModuleSystemPath(), 'plugins', 'Template'), $this);
        }
        if ($flag & self::PLUGIN_COLLECTION) {
            Collection::AddPluginFolder(append($this->getModuleSystemPath(), 'plugins', 'Collection'), $this);
        }
        if ($flag & self::PLUGIN_FLOWMANAGER) {
            FlowManager::AddPluginFolder(append($this->getModuleSystemPath(), 'plugins', 'FlowManager'), $this);
        }
        if ($flag & self::PLUGIN_STATEMENT) {
            Statement::AddPluginFolder(append($this->getModuleSystemPath(), 'plugins', 'Statement'), $this);
        }
        return $this;
    }

    /**
     * Send a handshake to one or a list of modules, return true if the module is accessible for API.
     *
     * @param string $modules The list of modules to handshake with
     * @param string $message The optional message to send with the handshake
     *
     * @return bool
     */
    final public function handshake(string $modules, string $message = ''): bool {
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
     * __onTouch event, handling touch request from another module.
     *
     * @param ModuleInfo $module The module sending the touch request
     * @param string $version The version of the module
     * @param string $message The message associated with the touch request
     *
     * @return bool
     */
    public function __onTouch(ModuleInfo $module, string $version, string $message = ''): bool {
        return true;
    }

    /**
     * __onRequire event, return false if the module is not ready.
     *
     * @return bool
     */
    public function __onRequire(): bool {
        return true;
    }

    /**
     * Get the data path URL
     *
     * @param string $module The module name
     * @return string The data path URL
     */
    final public function getDataPathURL(string $module = ''): string {
        return $this->module->getDataPath($module, true);
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
    final public function __call(string $method, array $arguments) {
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
     * Execute the internal function by given path, also an alternative of direct access the method.
     *
     * @param string $path The path to the internal function
     * @param array ...$args The arguments to pass to the function
     *
     * @return mixed|null The result of the function execution
     * @throws Error
     */
    final public function fork(string $path, array ...$args): mixed {
        $result = null;
        if ($closure = $this->module->getClosure($path, true)) {
            $result = call_user_func_array($closure, $args);
        }
        return $result;
    }
}
