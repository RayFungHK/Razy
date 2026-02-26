<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Contract;

use Closure;
use Razy\Contract\Container\PsrContainerInterface;

/**
 * PSR-11 compatible container interface with extended DI features.
 *
 * Extends the PSR-11 ContainerInterface for full compliance.
 * Also provides bind/singleton/scoped/instance/make, tagged bindings,
 * resolving hooks, and method injection via call().
 */
interface ContainerInterface extends PsrContainerInterface
{
    /**
     * Find an entry by its identifier and return it.
     *
     * @param string $id Identifier of the entry to look for
     *
     * @return mixed The entry
     */
    public function get(string $id): mixed;

    /**
     * Returns true if the container can return an entry for the given identifier.
     *
     * @param string $id Identifier of the entry to look for
     *
     * @return bool
     */
    public function has(string $id): bool;

    /**
     * Register a transient binding in the container.
     *
     * @param string $abstract The abstract type or service name
     * @param string|Closure $concrete The concrete class name or factory closure
     */
    public function bind(string $abstract, string|Closure $concrete): void;

    /**
     * Register a shared (singleton) binding in the container.
     *
     * @param string $abstract The abstract type or service name
     * @param string|Closure|null $concrete The concrete class name, factory closure, or null to self-bind
     */
    public function singleton(string $abstract, string|Closure|null $concrete = null): void;

    /**
     * Register a scoped binding. Instance is shared within a scope but cleared
     * between HTTP requests via forgetScopedInstances().
     *
     * @param string $abstract The abstract type or service name
     * @param string|Closure|null $concrete The concrete class name, factory closure, or null to self-bind
     */
    public function scoped(string $abstract, string|Closure|null $concrete = null): void;

    /**
     * Clear all scoped singleton instances. Call between HTTP requests in worker mode.
     */
    public function forgetScopedInstances(): void;

    /**
     * Register an existing instance in the container.
     *
     * @param string $abstract The abstract type or service name
     * @param object $instance The pre-built instance
     */
    public function instance(string $abstract, object $instance): void;

    /**
     * Resolve the given type from the container.
     *
     * @param string $abstract The abstract type or service name
     * @param array $params Optional parameters to pass to the constructor
     *
     * @return mixed The resolved instance
     */
    public function make(string $abstract, array $params = []): mixed;

    /**
     * Assign one or more abstract types to a named tag for batch resolution.
     *
     * @param string[] $abstracts Service identifiers to tag
     * @param string $tag The tag name
     */
    public function tag(array $abstracts, string $tag): void;

    /**
     * Resolve all services registered under the given tag.
     *
     * @param string $tag The tag name
     *
     * @return array<object> Resolved instances
     */
    public function tagged(string $tag): array;

    /**
     * Register a binding only if it has not already been registered.
     *
     * @param string $abstract The abstract type or service name
     * @param string|Closure $concrete The concrete class name or factory closure
     */
    public function bindIf(string $abstract, string|Closure $concrete): void;

    /**
     * Register a singleton binding only if it has not already been registered.
     *
     * @param string $abstract The abstract type or service name
     * @param string|Closure|null $concrete The concrete class name, factory closure, or null to self-bind
     */
    public function singletonIf(string $abstract, string|Closure|null $concrete = null): void;

    /**
     * Register a scoped binding only if it has not already been registered.
     *
     * @param string $abstract The abstract type or service name
     * @param string|Closure|null $concrete The concrete class name, factory closure, or null to self-bind
     */
    public function scopedIf(string $abstract, string|Closure|null $concrete = null): void;

    /**
     * Check if an abstract type has an explicit binding or instance registered.
     * Unlike has(), this does NOT consider auto-wiring or parent container.
     *
     * @param string $abstract The abstract type or service name
     *
     * @return bool True if explicitly bound or instantiated
     */
    public function bound(string $abstract): bool;

    /**
     * Get a closure that resolves a fresh instance of the given type each time.
     *
     * @param string $abstract The abstract type or service name
     *
     * @return Closure A factory closure
     */
    public function factory(string $abstract): Closure;

    /**
     * Register a decorator/extender for a resolved type.
     * The extender receives the resolved instance and the container.
     *
     * @param string $abstract The abstract type or service name
     * @param Closure $extender The decorator closure: fn(object $instance, ContainerInterface $container): object
     */
    public function extend(string $abstract, Closure $extender): void;

    /**
     * Register a callback to be invoked before a service is resolved.
     *
     * @param string|Closure $abstract Service identifier or global callback
     * @param Closure|null $callback The callback (required when $abstract is a string)
     */
    public function beforeResolving(string|Closure $abstract, ?Closure $callback = null): void;

    /**
     * Register a callback to be invoked while a service is being resolved.
     *
     * @param string|Closure $abstract Service identifier or global callback
     * @param Closure|null $callback The callback (required when $abstract is a string)
     */
    public function resolving(string|Closure $abstract, ?Closure $callback = null): void;

    /**
     * Register a callback to be invoked after a service has been resolved.
     *
     * @param string|Closure $abstract Service identifier or global callback
     * @param Closure|null $callback The callback (required when $abstract is a string)
     */
    public function afterResolving(string|Closure $abstract, ?Closure $callback = null): void;

    /**
     * Call a callable with auto-resolved dependencies (method injection).
     *
     * @param callable|array $callback The callable to invoke
     * @param array $params Named parameters to override auto-wired dependencies
     *
     * @return mixed The return value of the callable
     */
    public function call(callable|array $callback, array $params = []): mixed;

    /**
     * Atomically replace an existing binding, fire rebind callbacks,
     * and return the previously cached instance for cleanup.
     *
     * @param string $abstract Service identifier
     * @param string|Closure $concrete New class name or factory closure
     *
     * @return object|null The previous cached instance, or null
     */
    public function rebind(string $abstract, string|Closure $concrete): ?object;

    /**
     * Register a callback to be invoked when a binding is replaced via rebind().
     *
     * @param string $abstract Service identifier to watch
     * @param Closure $callback fn(string $abstract, ?object $oldInstance, ContainerInterface $container): void
     */
    public function onRebind(string $abstract, Closure $callback): void;

    /**
     * Register a contextual binding directly.
     *
     * When the container auto-resolves $consumer and encounters a constructor
     * parameter type-hinted as $abstract, it will use $concrete instead of
     * the global binding.
     *
     * @param string $consumer The consuming class
     * @param string $abstract The abstract dependency type
     * @param string|Closure $concrete The implementation to provide
     */
    public function addContextualBinding(string $consumer, string $abstract, string|Closure $concrete): void;

    /**
     * Get a contextual binding for a consumer/abstract pair, if registered.
     *
     * @param string $consumer The consuming class
     * @param string $abstract The abstract dependency type
     *
     * @return string|Closure|null The concrete implementation, or null
     */
    public function getContextualBinding(string $consumer, string $abstract): string|Closure|null;
}
