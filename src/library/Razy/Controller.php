<?php
/*
 * This file is part of Razy v0.4.
 *
 * (c) Ray Fung 2021 <hello@rayfung.hk>
 *
 *  This source file is subject to the MIT license that is bundled
 *  with this source code in the file LICENSE.
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
    /**
     * @var Module
     */
    private Module $module;

    /**
     * @var array
     */
    private array $externalClosure = [];

    /**
     * Controller constructor.
     *
     * @param Module $module
     */
    final public function __construct(Module $module)
    {
        $this->module = $module;
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
     * Controller Event __onRoute, will be executed before the route closure is executed. Return false to route is not
     * accept.
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
     * Controller Event __onReady, will be executed if all modules are loaded in system.
     */
    public function __onReady(): void
    {
    }

    /**
     * Handling the error of the closure.
     *
     * @param string    $path
     * @param Throwable $exception
     *
     * @throws Throwable
     */
    public function __onError(string $path, Throwable $exception): void
    {
        Error::ShowException($exception);
    }

    /**
     * Controller Event __onAPICall, will be executed if the module is accessed via API. Return false to refuse API
     * access.
     *
     * @param string $module The module code that is accessed via API
     * @param string $method The command method will be called via API
     * @param string $fqdn   The well-formatted FQDN string include the domain name and distributor code
     *
     * @return bool Return false to refuse API access
     */
    public function __onAPICall(string $module, string $method, string $fqdn = ''): bool
    {
        return true;
    }

    /**
     * Controller method bridge. When the method called which is not declared, Controller will
     * inject the Closure from the specified path that configured in __onInit state.
     *
     * @param string $method    The string of the method name which is called
     * @param array  $arguments The arguments will pass to the method
     *
     * @throws Throwable
     *
     * @return mixed The return result of the method
     */
    final public function __call(string $method, array $arguments)
    {
        if ($path = $this->module->getBinding($method)) {
            if (null !== ($closure = $this->module->getClosure($path))) {
                return call_user_func_array($closure, $arguments);
            }
        }

        $path = append($this->module->getPath(), 'controller', $this->module->getCode() . '.' . $method . '.php');
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
     * __onTouch event, handling touch request from other module.
     *
     * @param string $moduleCode
     * @param string $version
     * @param string $message
     *
     * @return bool
     */
    public function __onTouch(string $moduleCode, string $version, string $message = ''): bool
    {
        return true;
    }

    /**
     * __onDispatch event, all modules will be executed before the routed method execute
     *
     * @param string $moduleCode
     *
     * @return void
     */
    public function __onDispatch(string $moduleCode): void
    {
    }

    /**
     * Get the Module version.
     *
     * @return string
     */
    final public function getModuleVersion(): string
    {
        return $this->module->getVersion();
    }

    /**
     * Execute the API command.
     *
     * @param string $command The API command
     * @param mixed  ...$args The arguments will pass to the API
     *
     * @throws Throwable
     *
     * @return mixed Return the result from the API command
     */
    final public function api(string $command, ...$args)
    {
        return $this->module->execute($command, $args);
    }

    /**
     * Prepare the EventEmitter to trigger the event.
     *
     * @param string   $event    The name of the event
     * @param callable $callback the callback to execute when the EventEmitter start to resolve
     *
     * @return EventEmitter The EventEmitter instance
     */
    final public function trigger(string $event, callable $callback): EventEmitter
    {
        return $this->module->propagate($event, $callback);
    }

    /**
     * Connect to another application by given FQDN in the same Razy structure.
     *
     * @param string $fqdn The well-formatted FQDN string
     *
     * @throws Throwable
     *
     * @return null|API return the API instance if the Application is connected successfully
     */
    final public function connect(string $fqdn): ?API
    {
        if (!is_fqdn($fqdn)) {
            throw new Error('Invalid format of the string of FQDN.');
        }

        return $this->module->connect($fqdn);
    }

    /**
     * @return string
     */
    final public function getModulePath(): string
    {
        return $this->module->getPath();
    }

    /**
     * @return string
     */
    final public function getModuleCode(): string
    {
        return $this->module->getCode();
    }

    /**
     * @return string
     */
    final public function getRootURL(): string
    {
        return $this->module->getRootURL();
    }

    /**
     * @return string
     */
    final public function getLazyRootURL(): string
    {
        return append($this->module->getRootURL(), $this->module->getCode()) . '/';
    }

    /**
     * @return string
     */
    final public function getAssetPath(): string
    {
        return append($this->module->getRootURL(), 'view', $this->module->getCode()) . '/';
    }

    /**
     * @return string
     */
    final public function getViewPath(): string
    {
        return append($this->module->getURLPath(), 'view');
    }

    /**
     * @param string $path
     *
     * @return Source
     *
     * @throws Throwable
     */
    final public function load(string $path): Template\Source
    {
        $path     = append($this->module->getPath(), 'view', $path);
        $filename = basename($path);
        if (!preg_match('/[^.]+\..+/', $filename)) {
            $path .= '.tpl';
        }

        if (!is_file($path)) {
            throw new Error('The path ' . $path . ' is not a valid path.');
        }
        $template = $this->module->getTemplateEngine();

        return $template->load($path);
    }

    /**
     * @param array $sources
     *
     * @throws Throwable
     */
    final public function view(array $sources)
    {
        echo $this->module->getTemplateEngine()->outputQueued($sources);
    }

    /**
     * @return Template
     */
    final public function getTemplate(): Template
    {
        return $this->module->getTemplateEngine();
    }

    /**
     * Get the Configuration entity.
     *
     * @throws Error
     *
     * @return Configuration
     */
    final public function getModuleConfig(): Configuration
    {
        return $this->module->loadConfig();
    }

    /**
     * Send a handshake to one or a list of modules, return true if the module is available.
     *
     * @param string $modules
     * @param string $message
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
     * Get the XHR entity.
     *
     * @return XHR
     */
    final public function xhr(): XHR
    {
        return new XHR();
    }

    /**
     * Get the routed path.
     *
     * @return string
     */
    final public function getRoutedPath(): string
    {
        return $this->module->getRoutedPath();
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
}
