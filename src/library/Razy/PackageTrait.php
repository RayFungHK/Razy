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

namespace Razy;

use Closure;
use RuntimeException;

/**
 * Trait providing package lifecycle hooks and inter-package communication.
 *
 * Use this trait in any Controller subclass that needs to act as a
 * standalone package (executed via `php Razy.phar pkg <package_name>`).
 *
 * When a standalone module is executed as a package, or loaded as a
 * dependency with `wait: "load"`, these hooks are triggered by the
 * PackageRunner through Module trigger methods.
 *
 * Because "load"-mode dependencies become full co-modules in the
 * same Standalone runtime, the controller retains full access to
 * API, events, templates, routing, and all Module features.
 *
 * Package API vs Module API:
 *   - Module API (Controller::api()) works via the Distributor/Module
 *     system for cross-module calls within a single dist.
 *   - Package API (registerPackageAPI/callPackageAPI) provides a
 *     lightweight inter-package communication layer for packages
 *     running in the same process (e.g., co-modules loaded via
 *     on_depend "load" mode).
 *
 * Package Events vs Module Events:
 *   - Module Events (Controller::trigger()/listen()) use the
 *     Distributor's event system, tied to module codes.
 *   - Package Events (onPackageEvent/emitPackageEvent) are package-
 *     level pub/sub, scoped to the package runtime, decoupled from
 *     the Distributor event infrastructure.
 *
 * Detection: check `defined('RAZY_PACKAGE_MODE')` in `__onInit` to
 * branch between normal web mode and package mode.
 *
 * Usage:
 * ```php
 * class MyController extends Controller
 * {
 *     use PackageTrait;
 *
 *     public function __onPackageStart(array $packageInfo): bool
 *     {
 *         // Register package API for other packages to call
 *         $this->registerPackageAPI('greet', fn(string $name) => "Hello, {$name}!");
 *
 *         // Subscribe to package events
 *         $this->onPackageEvent('data:ready', fn(array $data) => $this->processData($data));
 *
 *         return true;
 *     }
 *
 *     public function __onPackageExec(array $packageInfo): int
 *     {
 *         // Call another package's API
 *         $result = $this->callPackageAPI('vendor/helper', 'transform', $input);
 *
 *         // Emit a package event
 *         $this->emitPackageEvent('data:ready', ['key' => 'value']);
 *
 *         return 0;
 *     }
 * }
 * ```
 *
 * @trait PackageTrait
 */
trait PackageTrait
{
    // ── Package API & Event Registry ──────────────────────────────
    // Static so all packages in the same process share the registry.

    /** @var array<string, array<string, Closure>> Package APIs: packageName => action => handler */
    private static array $_packageAPIs = [];

    /** @var array<string, list<Closure>> Package event listeners: eventName => handlers[] */
    private static array $_packageEventListeners = [];

    // ── Lifecycle Hooks ───────────────────────────────────────────

    /**
     * __onPackageStart event — triggered before main package execution
     * begins (after prerequisites and dependencies are resolved).
     *
     * Use this to initialise package-specific resources, validate
     * configuration, register package APIs, and subscribe to package events.
     *
     * Return false to abort package execution.
     *
     * @param array{package_name: string, version: string, mode: string, args: string[]} $packageInfo
     *
     * @return bool
     */
    public function __onPackageStart(array $packageInfo): bool
    {
        return true;
    }

    /**
     * __onPackageExec event — triggered for exec-mode packages.
     *
     * Override this with the package's core logic. The return value is
     * used as the process exit code (0 = success).
     *
     * For serve-mode packages this is NOT called — __onPackageServe()
     * is used instead.
     *
     * @param array{package_name: string, version: string, mode: string, args: string[]} $packageInfo
     *
     * @return int Exit code (0 = success)
     */
    public function __onPackageExec(array $packageInfo): int
    {
        return 0;
    }

    /**
     * __onPackageServe event — triggered for serve-mode packages.
     *
     * Override this to set up long-running services: HTTP servers,
     * WebSocket listeners, queue consumers, event loops, etc.
     * This method should BLOCK until the service is done (e.g., Ctrl+C).
     *
     * Razy's default serve command already handles static asset
     * serving. Use this hook for application-level serve logic
     * such as registering routes, starting listeners, or binding
     * to a port.
     *
     * @param array{package_name: string, version: string, mode: string, args: string[]} $packageInfo
     */
    public function __onPackageServe(array $packageInfo): void
    {
    }

    /**
     * __onPackageStop event — triggered when the package shuts down
     * (stop signal, exec completion, or `pkg stop`).
     *
     * Use this for graceful shutdown: close connections, flush buffers,
     * release locks, deregister services.
     */
    public function __onPackageStop(): void
    {
    }

    /**
     * __onPackageHealthcheck event — triggered by the PackageRunner's
     * healthcheck poller when another package depends on this one with
     * `wait: "healthcheck"`, or via the built-in /_razy/health
     * endpoint in serve mode.
     *
     * Return true when the service is healthy and ready to accept traffic.
     *
     * @return bool
     */
    public function __onPackageHealthcheck(): bool
    {
        return true;
    }

    /**
     * Reset all package API and event registrations.
     *
     * Useful for testing or when re-initialising the package runtime.
     */
    public static function resetPackageRegistry(): void
    {
        self::$_packageAPIs = [];
        self::$_packageEventListeners = [];
    }

    // ── Package API ───────────────────────────────────────────────
    // Lightweight inter-package RPC within the same process.

    /**
     * Register a package API action.
     *
     * Other packages in the same runtime can call this action via
     * callPackageAPI(). APIs are keyed by the registering module's code.
     *
     * @param string $action Action name (e.g., 'transform', 'getConfig')
     * @param Closure $handler Handler closure — receives call arguments
     */
    protected function registerPackageAPI(string $action, Closure $handler): void
    {
        $key = $this->getModuleCode();
        self::$_packageAPIs[$key][$action] = $handler;
    }

    /**
     * Call a package API action on another package.
     *
     * The target package must have registered the action via
     * registerPackageAPI() during __onPackageStart.
     *
     * @param string $packageName Target package's module code (e.g., 'vendor/helper')
     * @param string $action Action name
     * @param mixed ...$params Arguments forwarded to the handler
     *
     * @return mixed Handler return value
     *
     * @throws RuntimeException If the action is not registered
     */
    protected function callPackageAPI(string $packageName, string $action, mixed ...$params): mixed
    {
        if (!isset(self::$_packageAPIs[$packageName][$action])) {
            throw new RuntimeException("Package API '{$action}' not found on '{$packageName}'.");
        }

        return (self::$_packageAPIs[$packageName][$action])(...$params);
    }

    /**
     * Check whether a package API action is registered.
     *
     * @param string $packageName Target package's module code
     * @param string $action Action name
     *
     * @return bool
     */
    protected function hasPackageAPI(string $packageName, string $action): bool
    {
        return isset(self::$_packageAPIs[$packageName][$action]);
    }

    // ── Package Events ────────────────────────────────────────────
    // Lightweight pub/sub between packages in the same process.

    /**
     * Subscribe to a package event.
     *
     * Multiple handlers can subscribe to the same event. Handlers
     * are called in registration order when the event is emitted.
     *
     * @param string $event Event name (e.g., 'data:ready', 'config:changed')
     * @param Closure $handler Handler closure — receives event data
     */
    protected function onPackageEvent(string $event, Closure $handler): void
    {
        self::$_packageEventListeners[$event][] = $handler;
    }

    /**
     * Emit a package event to all subscribers.
     *
     * All registered handlers for this event are called synchronously
     * in registration order with the provided data.
     *
     * @param string $event Event name
     * @param mixed $data Data passed to each handler
     */
    protected function emitPackageEvent(string $event, mixed $data = null): void
    {
        foreach (self::$_packageEventListeners[$event] ?? [] as $handler) {
            $handler($data);
        }
    }
}
