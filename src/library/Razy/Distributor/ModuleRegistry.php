<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Distributor;

use Razy\API;
use Razy\Contract\ModuleInterface;
use Razy\EventEmitter;
use Razy\Module;
use Razy\Module\ModuleStatus;
use Razy\ModuleInfo;

/**
 * Class ModuleRegistry.
 *
 * Tracks loaded modules, API module registrations, await list management,
 * module handshake protocol, and module lifecycle queue.
 *
 * Extracted from the Distributor god class to follow Single Responsibility Principle.
 *
 * @class ModuleRegistry
 */
class ModuleRegistry
{
    /** @var array<string, Module> All discovered module instances keyed by module code */
    private array $modules = [];

    /** @var array<string, Module> Modules that passed initialization, ready for routing */
    private array $queue = [];

    /** @var array<string, Module> Modules registered as API providers, keyed by API name */
    private array $APIModules = [];

    /** @var array<string, array> Deferred callables waiting for specific modules to load */
    private array $awaitList = [];

    /** @var array<string, Module[]> Centralized listener index: 'moduleCode:eventName' => [listenerModules] */
    private array $listenerIndex = [];

    /** @var bool Whether to auto-load all discovered modules (vs. only those in requires) */
    private bool $autoload = false;

    /**
     * ModuleRegistry constructor.
     *
     * @param object $distributor The parent Distributor instance (passed to API/EventEmitter constructors)
     * @param bool $autoload Whether to auto-load all discovered modules
     */
    public function __construct(
        private readonly object $distributor,
        bool $autoload = false,
    ) {
        $this->autoload = $autoload;
    }

    /**
     * Get all module instances.
     *
     * @return array<string, Module>
     */
    public function getModules(): array
    {
        return $this->modules;
    }

    /**
     * Get the modules reference for direct population by ModuleScanner.
     *
     * @return array<string, Module>
     */
    public function &getModulesRef(): array
    {
        return $this->modules;
    }

    /**
     * Get all queued (initialized) modules.
     *
     * @return array<string, Module>
     */
    public function getQueue(): array
    {
        return $this->queue;
    }

    /**
     * Add a module to the queue.
     *
     * @param string $code Module code
     * @param Module $module Module instance
     */
    public function enqueue(string $code, Module $module): void
    {
        $this->queue[$code] = $module;
    }

    /**
     * Set the queue (used after filtering during lifecycle stages).
     *
     * @param array<string, Module> $queue
     */
    public function setQueue(array $queue): void
    {
        $this->queue = $queue;
    }

    /**
     * Check if a module exists in the registry.
     *
     * @param string $moduleCode
     *
     * @return bool
     */
    public function has(string $moduleCode): bool
    {
        return isset($this->modules[$moduleCode]);
    }

    /**
     * Get a module by code (regardless of load status).
     *
     * @param string $moduleCode
     *
     * @return Module|null
     */
    public function get(string $moduleCode): ?Module
    {
        return $this->modules[$moduleCode] ?? null;
    }

    /**
     * Check if the module is loadable (autoload or explicitly required).
     *
     * @param ModuleInterface $module
     *
     * @return bool
     */
    public function isLoadable(ModuleInterface $module): bool
    {
        return $this->autoload || \array_key_exists($module->getModuleInfo()->getCode(), $this->modules);
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
        if (\strlen($module->getModuleInfo()->getAPIName()) > 0) {
            $this->APIModules[$module->getModuleInfo()->getAPIName()] = $module;
        }

        return $this;
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
        return ($module && $module->getStatus() === ModuleStatus::Loaded) ? $module : null;
    }

    /**
     * Get the loaded module by given module code.
     *
     * @param string $moduleCode
     *
     * @return Module|null
     */
    public function getLoadedModule(string $moduleCode): ?Module
    {
        $module = $this->modules[$moduleCode] ?? null;
        return ($module && $module->getStatus() === ModuleStatus::Loaded) ? $module : null;
    }

    /**
     * Get all loaded modules info with validation context.
     *
     * @return array<string, array{code: string, alias: string, path: string, version: string, api_name: string, module: Module}>
     */
    public function getLoadedModulesInfo(): array
    {
        $result = [];
        foreach ($this->modules as $code => $module) {
            if ($module->getStatus() === ModuleStatus::Loaded) {
                $info = $module->getModuleInfo();
                $result[$code] = [
                    'code' => $info->getCode(),
                    'alias' => $info->getAlias(),
                    'path' => $info->getPath(),
                    'version' => $info->getVersion(),
                    'api_name' => $info->getAPIName(),
                    'module' => $module, // Internal use only - do not expose externally
                ];
            }
        }
        return $result;
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
     * Put the callable into the list to wait for executing until other specified modules are ready.
     *
     * @param string $moduleCode Comma-separated module codes to wait for
     * @param callable $caller The callable to execute once all modules are ready
     */
    public function addAwait(string $moduleCode, callable $caller): void
    {
        $entity = [
            'required' => [],
            'caller' => $caller(...),
        ];

        $clips = \explode(',', $moduleCode);
        foreach ($clips as $code) {
            // Register each valid module code as a dependency for this await callable
            if (\preg_match(ModuleInfo::REGEX_MODULE_CODE, $code)) {
                $entity['required'][$code] = true;
                if (!isset($this->awaitList[$code])) {
                    $this->awaitList[$code] = [];
                }
                $this->awaitList[$code][] = &$entity;
            }
        }
    }

    /**
     * Process all await callbacks for modules in the queue.
     * Executes deferred callables whose dependencies are all satisfied.
     */
    public function processAwaits(): void
    {
        foreach ($this->queue as $module) {
            $moduleCode = $module->getModuleInfo()->getCode();
            if (isset($this->awaitList[$moduleCode])) {
                foreach ($this->awaitList[$moduleCode] as $index => &$await) {
                    unset($await['required'][$moduleCode]);
                    if (\count($await['required']) === 0) {
                        // If all required modules are ready, execute the await function immediately
                        $await['caller']();
                        unset($this->awaitList[$moduleCode][$index]);
                    }
                }
            }
        }
    }

    /**
     * Notify all queued modules that they are ready (__onReady event).
     */
    public function notifyReady(): void
    {
        foreach ($this->queue as $module) {
            $module->notify();
        }
    }

    /**
     * Trigger all module __onRouted event to announce the routed module.
     *
     * Optimised: when there is only one module in the queue (typical for
     * standalone mode), the loop body always skips itself, producing zero
     * useful work.  The count check avoids entering the loop entirely.
     *
     * @param ModuleInterface $matchedModule
     */
    public function announce(ModuleInterface $matchedModule): void
    {
        // Fast path: single-module standalone — nothing to announce
        if (\count($this->queue) <= 1) {
            return;
        }

        $matchedCode = $matchedModule->getModuleInfo()->getCode();
        foreach ($this->queue as $module) {
            if ($matchedCode !== $module->getModuleInfo()->getCode()) {
                $module->announce($matchedModule->getModuleInfo());
            }
        }
    }

    /**
     * Execute dispose event on all queued modules.
     */
    public function dispose(): void
    {
        foreach ($this->queue as $module) {
            $module->dispose();
        }
    }

    /**
     * Register a module as a listener for a specific event in the centralized index.
     * Enables O(1) event listener lookups instead of O(n) full module scans.
     *
     * @param string $sourceModuleCode The module code being listened to
     * @param string $eventName The event name
     * @param Module $listener The module that is listening
     */
    public function registerListener(string $sourceModuleCode, string $eventName, Module $listener): void
    {
        $key = $sourceModuleCode . ':' . $eventName;
        $this->listenerIndex[$key][] = $listener;
    }

    /**
     * Get all modules listening for a specific event from the centralized index.
     *
     * @param string $sourceModuleCode The module code that emitted the event
     * @param string $eventName The event name
     *
     * @return Module[] Array of listener modules
     */
    public function getEventListeners(string $sourceModuleCode, string $eventName): array
    {
        $key = $sourceModuleCode . ':' . $eventName;
        return $this->listenerIndex[$key] ?? [];
    }

    /**
     * Remove all listener registrations for a specific module from the centralized index.
     * Used during worker mode reset when a module's event dispatcher is cleared.
     *
     * @param Module $module The module to unregister
     */
    public function unregisterModuleListeners(Module $module): void
    {
        foreach ($this->listenerIndex as $key => &$listeners) {
            $listeners = \array_values(\array_filter($listeners, fn (Module $m) => $m !== $module));
            if (empty($listeners)) {
                unset($this->listenerIndex[$key]);
            }
        }
        unset($listeners);
    }

    /**
     * Clear the entire listener index.
     * Used for bulk reset during worker mode cycle.
     */
    public function clearListenerIndex(): void
    {
        $this->listenerIndex = [];
    }

    /**
     * Create the API instance.
     *
     * @param Module $module The module that is calling
     *
     * @return API
     */
    public function createAPI(Module $module): API
    {
        return new API($this->distributor, $module);
    }

    /**
     * Create an EventEmitter.
     *
     * @param Module $module The module instance
     * @param string $event The event name
     * @param callable|null $callback The callback to execute when the event is resolved
     *
     * @return EventEmitter
     */
    public function createEmitter(Module $module, string $event, ?callable $callback = null): EventEmitter
    {
        return new EventEmitter($this->distributor, $module, $event, !$callback ? null : $callback(...));
    }
}
