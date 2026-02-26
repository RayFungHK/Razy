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
use Razy\Contract\ContainerInterface;
use Razy\Exception\ContainerException;
use Razy\Exception\ContainerNotFoundException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Lightweight Dependency Injection Container with auto-wiring support.
 *
 * Supports multiple kinds of registrations:
 * - **bind()**: transient — a new instance is created every time.
 * - **singleton()**: shared — only one instance is created and reused.
 * - **scoped()**: request-scoped — shared within a scope, cleared between requests.
 * - **instance()**: store a pre-built object directly.
 *
 * Additional features:
 * - **Tagged bindings**: group related services under a tag for batch resolution.
 * - **Parent container**: child containers delegate to a parent when local lookup fails.
 * - **Resolving hooks**: callbacks fired during and after service resolution.
 * - **Method injection**: auto-resolve parameters when calling any callable via call().
 *
 * The container can also auto-resolve classes by inspecting constructor
 * type-hints (auto-wiring). Named parameters can override auto-wired
 * dependencies.
 *
 * @class Container
 *
 * @package Razy
 *
 * @license MIT
 */
class Container implements ContainerInterface
{
    /**
     * @var array<string, array{concrete: string|Closure, shared: bool}> Registered bindings
     */
    private array $bindings = [];

    /**
     * @var array<string, object> Resolved singleton instances
     */
    private array $instances = [];

    /**
     * @var array<string, string> Alias mappings (alias → abstract)
     */
    private array $aliases = [];

    /**
     * @var array<string, true> Tracks types currently being resolved to detect circular dependencies
     */
    private array $resolving = [];

    /**
     * @var array<string, string[]> Tag → abstract[] mappings for tagged binding groups
     */
    private array $tags = [];

    /**
     * @var array<string, true> Tracks which abstracts are scoped (cleared between requests)
     */
    private array $scopedBindings = [];

    /**
     * @var array<string, Closure[]> Callbacks fired before resolving a service (keyed by abstract or '*' for global)
     */
    private array $beforeResolvingCallbacks = [];

    /**
     * @var array<string, Closure[]> Callbacks fired while resolving a service (keyed by abstract or '*' for global)
     */
    private array $resolvingCallbacks = [];

    /**
     * @var array<string, Closure[]> Callbacks fired after resolving a service (keyed by abstract or '*' for global)
     */
    private array $afterResolvingCallbacks = [];

    /**
     * @var array<string, Closure[]> Extender closures keyed by abstract type
     */
    private array $extenders = [];

    /**
     * @var array<string, Closure[]> Callbacks fired when a binding is replaced via rebind()
     */
    private array $rebindCallbacks = [];

    /**
     * @var array<string, int> Tracks how many times each abstract has been rebound
     */
    private array $rebindCounts = [];

    /**
     * @var int Maximum total rebinds before the worker should restart (prevent class table bloat)
     */
    private int $maxRebindsBeforeRestart = 50;

    /**
     * @var array<string, array<string, string|Closure>> Contextual bindings.
     *
     * Maps consumer class → abstract dependency → concrete implementation.
     * When auto-resolving constructor parameters for a consumer class, the
     * container checks this map before falling back to global bindings.
     *
     * Example: ['App\PhotoController']['FilesystemInterface'] = 'LocalFilesystem'
     */
    private array $contextualBindings = [];

    /**
     * Create a new Container, optionally with a parent for hierarchical resolution.
     *
     * @param ContainerInterface|null $parent Parent container to delegate to when local lookup fails
     */
    public function __construct(private readonly ?ContainerInterface $parent = null)
    {
    }

    /**
     * Get the parent container, if any.
     *
     * @return ContainerInterface|null
     */
    public function getParent(): ?ContainerInterface
    {
        return $this->parent;
    }

    // ── Contextual Binding ─────────────────────────────────

    /**
     * Begin a contextual binding definition.
     *
     * Returns a fluent builder that allows specifying which abstract
     * type should be resolved differently when consumed by the given class.
     *
     * Usage:
     *   $container->when(PhotoController::class)
     *       ->needs(FilesystemInterface::class)
     *       ->give(LocalFilesystem::class);
     *
     * @param string $consumer The consuming class name
     *
     * @return ContextualBindingBuilder
     */
    public function when(string $consumer): ContextualBindingBuilder
    {
        return new ContextualBindingBuilder($this, $consumer);
    }

    /**
     * Register a contextual binding directly.
     *
     * When the container auto-resolves $consumer and encounters a
     * constructor parameter type-hinted as $abstract, it will resolve
     * $concrete instead of the global binding.
     *
     * @param string $consumer The consuming class
     * @param string $abstract The abstract dependency type
     * @param string|Closure $concrete The implementation to provide
     */
    public function addContextualBinding(string $consumer, string $abstract, string|Closure $concrete): void
    {
        $this->contextualBindings[$consumer][$abstract] = $concrete;
    }

    /**
     * Get a contextual binding for a consumer/abstract pair, if registered.
     *
     * @param string $consumer The consuming class
     * @param string $abstract The abstract dependency type
     *
     * @return string|Closure|null The concrete implementation, or null if not registered
     */
    public function getContextualBinding(string $consumer, string $abstract): string|Closure|null
    {
        return $this->contextualBindings[$consumer][$abstract] ?? null;
    }

    /**
     * Register a transient binding. A new instance is created on every make() call.
     *
     * @param string $abstract Service identifier (class name, interface, or alias)
     * @param string|Closure $concrete Class name or factory closure
     */
    public function bind(string $abstract, string|Closure $concrete): void
    {
        $this->bindings[$abstract] = ['concrete' => $concrete, 'shared' => false];
        unset($this->instances[$abstract]);
    }

    /**
     * Register a transient binding only if none already exists.
     *
     * @param string $abstract Service identifier
     * @param string|Closure $concrete Class name or factory closure
     */
    public function bindIf(string $abstract, string|Closure $concrete): void
    {
        if (!$this->bound($abstract)) {
            $this->bind($abstract, $concrete);
        }
    }

    /**
     * Register a singleton binding. The instance is created once and reused.
     *
     * @param string $abstract Service identifier
     * @param string|Closure|null $concrete Class name, factory closure, or null to self-bind
     */
    public function singleton(string $abstract, string|Closure|null $concrete = null): void
    {
        $this->bindings[$abstract] = [
            'concrete' => $concrete ?? $abstract,
            'shared' => true,
        ];
    }

    /**
     * Register a singleton binding only if none already exists.
     *
     * @param string $abstract Service identifier
     * @param string|Closure|null $concrete Class name, factory closure, or null to self-bind
     */
    public function singletonIf(string $abstract, string|Closure|null $concrete = null): void
    {
        if (!$this->bound($abstract)) {
            $this->singleton($abstract, $concrete);
        }
    }

    /**
     * Register a scoped binding. Like singleton, but the cached instance is
     * cleared when forgetScopedInstances() is called (e.g. between HTTP requests
     * in worker mode).
     *
     * @param string $abstract Service identifier
     * @param string|Closure|null $concrete Class name, factory closure, or null to self-bind
     */
    public function scoped(string $abstract, string|Closure|null $concrete = null): void
    {
        $this->bindings[$abstract] = [
            'concrete' => $concrete ?? $abstract,
            'shared' => true,
        ];
        $this->scopedBindings[$abstract] = true;
    }

    /**
     * Register a scoped binding only if none already exists.
     *
     * @param string $abstract Service identifier
     * @param string|Closure|null $concrete Class name, factory closure, or null to self-bind
     */
    public function scopedIf(string $abstract, string|Closure|null $concrete = null): void
    {
        if (!$this->bound($abstract)) {
            $this->scoped($abstract, $concrete);
        }
    }

    /**
     * Clear all scoped singleton instances. Call this between HTTP requests
     * in worker mode (e.g. Caddy worker, Swoole, RoadRunner).
     */
    public function forgetScopedInstances(): void
    {
        foreach ($this->scopedBindings as $abstract => $_) {
            unset($this->instances[$abstract]);
        }
    }

    /**
     * Store a pre-built instance in the container.
     *
     * @param string $abstract Service identifier
     * @param object $instance The instance to store
     */
    public function instance(string $abstract, object $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    /**
     * Register an alias for an abstract type.
     *
     * @param string $alias The alias name
     * @param string $abstract The target abstract type
     */
    public function alias(string $alias, string $abstract): void
    {
        $this->aliases[$alias] = $abstract;
    }

    // ── Rebinding ───────────────────────────────────────────

    /**
     * Atomically replace an existing binding.
     *
     * Removes the old binding and cached instance, registers the new binding,
     * increments the rebind counter, and fires any registered rebind callbacks
     * so that dependents can update their references.
     *
     * Returns the previously cached instance (if any) so callers can perform
     * cleanup such as closing connections or releasing resources.
     *
     * @param string $abstract Service identifier
     * @param string|Closure $concrete New class name or factory closure
     *
     * @return object|null The previous cached instance, or null
     */
    public function rebind(string $abstract, string|Closure $concrete): ?object
    {
        $abstract = $this->getAlias($abstract);
        $old = $this->instances[$abstract] ?? null;

        // Remove old binding, instance, and scoped tracking
        $this->forget($abstract);

        // Register new binding
        $this->bind($abstract, $concrete);

        // Track rebind count
        $this->rebindCounts[$abstract] = ($this->rebindCounts[$abstract] ?? 0) + 1;

        // Notify dependents
        $this->fireRebindCallbacks($abstract, $old);

        return $old;
    }

    /**
     * Register a callback to be invoked when a binding is replaced via rebind().
     *
     * The callback receives (string $abstract, ?object $oldInstance, Container $container).
     *
     * @param string $abstract Service identifier to watch
     * @param Closure $callback The rebind callback
     */
    public function onRebind(string $abstract, Closure $callback): void
    {
        $abstract = $this->getAlias($abstract);
        $this->rebindCallbacks[$abstract][] = $callback;
    }

    /**
     * Get the number of times a specific abstract has been rebound.
     *
     * @param string $abstract Service identifier
     *
     * @return int
     */
    public function getRebindCount(string $abstract): int
    {
        return $this->rebindCounts[$this->getAlias($abstract)] ?? 0;
    }

    /**
     * Get the total number of rebinds across all abstracts.
     *
     * @return int
     */
    public function getTotalRebindCount(): int
    {
        return \array_sum($this->rebindCounts);
    }

    /**
     * Check if the total rebind count exceeds the configured threshold.
     *
     * When anonymous classes are rebound, their old class definitions remain
     * in PHP's class table (cannot be unloaded). This threshold triggers a
     * graceful restart to reclaim memory from accumulated class definitions.
     *
     * @return bool True if threshold exceeded
     */
    public function exceedsRebindThreshold(): bool
    {
        return $this->getTotalRebindCount() >= $this->maxRebindsBeforeRestart;
    }

    /**
     * Set the maximum total rebinds before recommending a restart.
     *
     * @param int $max Maximum rebind count (default: 50)
     */
    public function setMaxRebindsBeforeRestart(int $max): void
    {
        $this->maxRebindsBeforeRestart = \max(1, $max);
    }

    // ── Binding Queries ───────────────────────────────────

    /**
     * Check whether an abstract type has been explicitly bound, registered
     * as an instance, or aliased in this container (without checking parent
     * and without attempting auto-wire).
     *
     * @param string $abstract Service identifier
     *
     * @return bool
     */
    public function bound(string $abstract): bool
    {
        $abstract = $this->getAlias($abstract);

        return isset($this->bindings[$abstract])
            || isset($this->instances[$abstract]);
    }

    /**
     * Get a Closure that resolves a fresh instance of the given type each
     * time it is called. Useful for injecting a "factory" rather than a
     * concrete instance.
     *
     * @param string $abstract Service identifier
     *
     * @return Closure(): mixed
     */
    public function factory(string $abstract): Closure
    {
        return fn () => $this->make($abstract);
    }

    /**
     * Register an extender closure for a given abstract type.
     *
     * Each time the type is resolved, the extender receives the resolved
     * instance and the container, and must return the (possibly decorated)
     * instance. Multiple extenders are applied in registration order.
     *
     * @param string $abstract Service identifier
     * @param Closure $extender function(object $instance, Container $container): object
     */
    public function extend(string $abstract, Closure $extender): void
    {
        $abstract = $this->getAlias($abstract);

        // If an existing singleton instance is cached, apply the extender
        // immediately and update the cache
        if (isset($this->instances[$abstract])) {
            $this->instances[$abstract] = $extender($this->instances[$abstract], $this);
            return;
        }

        $this->extenders[$abstract][] = $extender;
    }

    // ── Tagged Bindings ────────────────────────────────────

    /**
     * Assign one or more abstracts to a tag. Tags let you group related
     * services and resolve them all at once via tagged().
     *
     * @param string[] $abstracts Service identifiers to tag
     * @param string $tag The tag name
     */
    public function tag(array $abstracts, string $tag): void
    {
        $this->tags[$tag] = \array_unique(
            \array_merge($this->tags[$tag] ?? [], $abstracts),
        );
    }

    /**
     * Resolve all services registered under the given tag.
     *
     * @param string $tag The tag name
     *
     * @return array<object> Resolved instances (empty array if tag is unknown)
     */
    public function tagged(string $tag): array
    {
        if (!isset($this->tags[$tag])) {
            return [];
        }

        return \array_map(fn (string $abstract) => $this->make($abstract), $this->tags[$tag]);
    }

    // ── Resolving Hooks ────────────────────────────────────

    /**     * Register a callback to be invoked before a service is resolved
     * (before build). The callback receives (string $abstract, Container $container).
     *
     * If only a Closure is provided, it is registered as a global hook.
     * Otherwise, it is scoped to the given abstract.
     *
     * @param string|Closure $abstract Service identifier or global callback
     * @param Closure|null $callback The callback (required when $abstract is a string)
     */
    public function beforeResolving(string|Closure $abstract, ?Closure $callback = null): void
    {
        if ($abstract instanceof Closure) {
            $this->beforeResolvingCallbacks['*'][] = $abstract;
        } else {
            $this->beforeResolvingCallbacks[$abstract][] = $callback;
        }
    }

    /**     * Register a callback to be invoked while a service is being resolved.
     *
     * If only a Closure is provided, it is registered as a global hook
     * (fired for every resolution). Otherwise, it is scoped to the given abstract.
     *
     * The callback receives (object $instance, Container $container).
     *
     * @param string|Closure $abstract Service identifier or global callback
     * @param Closure|null $callback The callback (required when $abstract is a string)
     */
    public function resolving(string|Closure $abstract, ?Closure $callback = null): void
    {
        if ($abstract instanceof Closure) {
            $this->resolvingCallbacks['*'][] = $abstract;
        } else {
            $this->resolvingCallbacks[$abstract][] = $callback;
        }
    }

    /**
     * Register a callback to be invoked after a service has been resolved.
     *
     * If only a Closure is provided, it is registered as a global hook.
     * Otherwise, it is scoped to the given abstract.
     *
     * The callback receives (object $instance, Container $container).
     *
     * @param string|Closure $abstract Service identifier or global callback
     * @param Closure|null $callback The callback (required when $abstract is a string)
     */
    public function afterResolving(string|Closure $abstract, ?Closure $callback = null): void
    {
        if ($abstract instanceof Closure) {
            $this->afterResolvingCallbacks['*'][] = $abstract;
        } else {
            $this->afterResolvingCallbacks[$abstract][] = $callback;
        }
    }

    /**
     * Resolve the given type from the container.
     *
     * Resolution order:
     * 1. Alias resolution
     * 2. Existing singleton instance
     * 3. Registered binding (closure or class name)
     * 4. Auto-wiring (reflection-based constructor injection)
     * 5. Delegate to parent container (if set and local resolution fails)
     *
     * @param string $abstract Service identifier or class name
     * @param array $params Named parameters to pass to the constructor
     *
     * @return mixed The resolved instance
     *
     * @throws ContainerException If the type cannot be resolved or a circular dependency is detected
     */
    public function make(string $abstract, array $params = []): mixed
    {
        // Resolve aliases
        $abstract = $this->getAlias($abstract);

        // Return existing singleton instance
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // If no local binding exists, delegate to parent container
        if (!isset($this->bindings[$abstract]) && $this->parent !== null) {
            // Only delegate if the parent can handle it and we have no local knowledge
            if ($this->parent->has($abstract)) {
                return $this->parent instanceof self
                    ? $this->parent->make($abstract, $params)
                    : $this->parent->make($abstract);
            }
        }

        // Detect circular dependencies
        if (isset($this->resolving[$abstract])) {
            throw new ContainerException("Circular dependency detected while resolving '{$abstract}'.");
        }

        $this->resolving[$abstract] = true;

        try {
            // Fire beforeResolving hooks (before build)
            $this->fireBeforeResolvingCallbacks($abstract);

            $object = $this->build($abstract, $params);

            // Apply extenders (decorator pattern)
            foreach ($this->extenders[$abstract] ?? [] as $extender) {
                $object = $extender($object, $this);
            }

            // Store as singleton if binding is shared
            if (isset($this->bindings[$abstract]) && $this->bindings[$abstract]['shared']) {
                $this->instances[$abstract] = $object;
            }

            // Fire resolving hooks
            $this->fireResolvingCallbacks($abstract, $object);

            // Fire after-resolving hooks
            $this->fireAfterResolvingCallbacks($abstract, $object);

            return $object;
        } finally {
            unset($this->resolving[$abstract]);
        }
    }

    // ── Method Injection ───────────────────────────────────

    /**
     * Call a callable with auto-resolved dependencies.
     *
     * Supports closures, global functions, static methods, instance methods,
     * and invokable objects. Parameters in $params override auto-wired values.
     *
     * @param callable|array{object|string, string} $callback The callable to invoke
     * @param array $params Named parameters to override auto-wired dependencies
     *
     * @return mixed The return value of the callable
     *
     * @throws ContainerException If a parameter cannot be resolved
     */
    public function call(callable|array $callback, array $params = []): mixed
    {
        $reflector = $this->getCallableReflector($callback);

        $dependencies = [];
        foreach ($reflector->getParameters() as $parameter) {
            $name = $parameter->getName();

            // 1. Explicit parameter override
            if (\array_key_exists($name, $params)) {
                $dependencies[] = $params[$name];
                continue;
            }

            // 2. Type-hint based resolution
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();
                try {
                    $dependencies[] = $this->make($typeName);
                    continue;
                } catch (Throwable) {
                    // Fall through to default/null checks
                }
            }

            // 3. Default value
            if ($parameter->isDefaultValueAvailable()) {
                $dependencies[] = $parameter->getDefaultValue();
                continue;
            }

            // 4. Nullable
            if ($parameter->allowsNull()) {
                $dependencies[] = null;
                continue;
            }

            throw new ContainerException(
                "Cannot resolve parameter '\${$name}' when calling " . $this->describeCallable($callback) . '. '
                . 'No binding, default value, or nullable type-hint available.',
            );
        }

        return \call_user_func_array($callback, $dependencies);
    }

    /**
     * Retrieve an entry by its identifier. Alias for make() with no parameters.
     *
     * @param string $id Service identifier
     *
     * @return mixed The resolved entry
     *
     * @throws ContainerNotFoundException If no binding or class exists for the given identifier
     */
    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new ContainerNotFoundException("No binding found for '{$id}'.");
        }

        return $this->make($id);
    }

    /**
     * Check whether the container has a binding or instance for the given identifier.
     * Also checks parent container if set.
     *
     * @param string $id Service identifier
     *
     * @return bool
     */
    public function has(string $id): bool
    {
        $id = $this->getAlias($id);

        if (isset($this->bindings[$id]) || isset($this->instances[$id])) {
            return true;
        }

        return $this->parent !== null && $this->parent->has($id);
    }

    /**
     * Remove all bindings and instances. Useful for worker mode between requests.
     */
    public function reset(): void
    {
        $this->bindings = [];
        $this->instances = [];
        $this->aliases = [];
        $this->resolving = [];
        $this->tags = [];
        $this->scopedBindings = [];
        $this->extenders = [];
        $this->contextualBindings = [];
        $this->beforeResolvingCallbacks = [];
        $this->resolvingCallbacks = [];
        $this->afterResolvingCallbacks = [];
        $this->rebindCallbacks = [];
        // Note: rebindCounts are intentionally NOT reset — they track
        // cumulative rebinds for class-table bloat detection across
        // the lifetime of the worker process.
    }

    /**
     * Remove a specific binding and its cached instance.
     *
     * @param string $abstract Service identifier
     */
    public function forget(string $abstract): void
    {
        $abstract = $this->getAlias($abstract);
        unset(
            $this->bindings[$abstract],
            $this->instances[$abstract],
            $this->scopedBindings[$abstract],
        );
    }

    /**
     * Get all registered binding keys.
     *
     * @return string[]
     */
    public function getBindings(): array
    {
        return \array_keys($this->bindings);
    }

    /**
     * Resolve an alias to its canonical abstract name.
     *
     * @param string $id The alias or abstract name
     *
     * @return string The canonical abstract name
     */
    private function getAlias(string $id): string
    {
        // Follow alias chain (max 10 levels to prevent infinite loops)
        $depth = 0;
        while (isset($this->aliases[$id]) && $depth < 10) {
            $id = $this->aliases[$id];
            ++$depth;
        }

        return $id;
    }

    /**
     * Build a concrete instance for the given abstract type.
     *
     * @param string $abstract Service identifier or class name
     * @param array $params Named parameters
     *
     * @return object The built instance
     *
     * @throws ContainerException If the class is not instantiable
     */
    private function build(string $abstract, array $params): object
    {
        $binding = $this->bindings[$abstract] ?? null;

        if ($binding) {
            $concrete = $binding['concrete'];

            // If binding is a Closure, invoke it with the container and params
            if ($concrete instanceof Closure) {
                return $concrete($this, $params);
            }

            // If concrete differs from abstract, resolve the concrete class
            if ($concrete !== $abstract) {
                return $this->make($concrete, $params);
            }
        }

        // Auto-wire: use reflection to inspect the constructor
        return $this->autoResolve($abstract, $params);
    }

    /**
     * Auto-wire a class by inspecting its constructor parameters.
     *
     * For each parameter:
     * 1. Check if an explicit value was provided in $params (by parameter name)
     * 2. If a type-hint exists and it's a class/interface, recursively resolve it
     * 3. Fall back to the parameter's default value
     * 4. Fall back to null if the parameter is nullable
     * 5. Throw an Error if nothing can satisfy the parameter
     *
     * @param string $class Fully-qualified class name
     * @param array $params Named parameters
     *
     * @return object The constructed instance
     *
     * @throws ContainerException|ContainerNotFoundException If the class does not exist, is not instantiable, or a parameter cannot be resolved
     */
    private function autoResolve(string $class, array $params): object
    {
        if (!\class_exists($class)) {
            throw new ContainerNotFoundException("Class '{$class}' does not exist and cannot be auto-resolved.");
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new ContainerException("Class '{$class}' is not instantiable (abstract class or interface).");
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $dependencies = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();

            // 1. Explicit parameter override
            if (\array_key_exists($name, $params)) {
                $dependencies[] = $params[$name];
                continue;
            }

            // 2. Type-hint based resolution
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();

                // 2a. Contextual binding
                if ($contextual = $this->getContextualBinding($class, $typeName)) {
                    $dependencies[] = $contextual instanceof Closure
                        ? $contextual($this, $params)
                        : $this->make($contextual);

                    continue;
                }

                // 2b. Fall back to global resolution
                try {
                    $dependencies[] = $this->make($typeName);
                    continue;
                } catch (ContainerException $e) {
                    // Always propagate circular dependency errors
                    if (\str_contains($e->getMessage(), 'Circular dependency')) {
                        throw $e;
                    }
                    // Other resolution failures fall through to default/null checks
                } catch (Throwable) {
                    // Could not resolve — fall through to default/null checks
                }
            }

            // 3. Default value
            if ($parameter->isDefaultValueAvailable()) {
                $dependencies[] = $parameter->getDefaultValue();
                continue;
            }

            // 4. Nullable
            if ($parameter->allowsNull()) {
                $dependencies[] = null;
                continue;
            }

            throw new ContainerException(
                "Cannot resolve parameter '\${$name}' for class '{$class}'. "
                . 'No binding, default value, or nullable type-hint available.',
            );
        }

        return $reflection->newInstanceArgs($dependencies);
    }

    /**
     * Get a ReflectionFunctionAbstract for any callable.
     *
     * @param callable|array $callback The callable to reflect
     *
     * @return ReflectionFunction|ReflectionMethod
     *
     * @throws ContainerException If the callable cannot be reflected
     */
    private function getCallableReflector(callable|array $callback): ReflectionFunction|ReflectionMethod
    {
        if ($callback instanceof Closure) {
            return new ReflectionFunction($callback);
        }

        if (\is_array($callback)) {
            return new ReflectionMethod($callback[0], $callback[1]);
        }

        if (\is_string($callback)) {
            if (\str_contains($callback, '::')) {
                [$class, $method] = \explode('::', $callback, 2);
                return new ReflectionMethod($class, $method);
            }
            return new ReflectionFunction($callback);
        }

        // Invokable object
        if (\is_object($callback)) {
            return new ReflectionMethod($callback, '__invoke');
        }

        throw new ContainerException('Unable to reflect the given callable.');
    }

    /**
     * Produce a human-readable description of a callable for error messages.
     *
     * @param callable|array $callback The callable
     *
     * @return string
     */
    private function describeCallable(callable|array $callback): string
    {
        if ($callback instanceof Closure) {
            return 'Closure';
        }

        if (\is_array($callback)) {
            $class = \is_object($callback[0]) ? $callback[0]::class : $callback[0];
            return $class . '::' . $callback[1] . '()';
        }

        if (\is_string($callback)) {
            return $callback . '()';
        }

        if (\is_object($callback)) {
            return $callback::class . '::__invoke()';
        }

        return 'unknown callable';
    }

    /**
     * Fire "resolving" callbacks for the given abstract.
     *
     * @param string $abstract The abstract type being resolved
     * @param object $instance The resolved instance
     */
    private function fireResolvingCallbacks(string $abstract, object $instance): void
    {
        // Fire type-specific callbacks
        foreach ($this->resolvingCallbacks[$abstract] ?? [] as $cb) {
            $cb($instance, $this);
        }

        // Fire global callbacks
        foreach ($this->resolvingCallbacks['*'] ?? [] as $cb) {
            $cb($instance, $this);
        }
    }

    /**
     * Fire "beforeResolving" callbacks for the given abstract.
     *
     * @param string $abstract The abstract type about to be resolved
     */
    private function fireBeforeResolvingCallbacks(string $abstract): void
    {
        // Fire type-specific callbacks
        foreach ($this->beforeResolvingCallbacks[$abstract] ?? [] as $cb) {
            $cb($abstract, $this);
        }

        // Fire global callbacks
        foreach ($this->beforeResolvingCallbacks['*'] ?? [] as $cb) {
            $cb($abstract, $this);
        }
    }

    /**
     * Fire "afterResolving" callbacks for the given abstract.
     *
     * @param string $abstract The abstract type that was resolved
     * @param object $instance The resolved instance
     */
    private function fireAfterResolvingCallbacks(string $abstract, object $instance): void
    {
        // Fire type-specific callbacks
        foreach ($this->afterResolvingCallbacks[$abstract] ?? [] as $cb) {
            $cb($instance, $this);
        }

        // Fire global callbacks
        foreach ($this->afterResolvingCallbacks['*'] ?? [] as $cb) {
            $cb($instance, $this);
        }
    }

    /**
     * Fire rebind callbacks for the given abstract.
     *
     * @param string $abstract The abstract type that was rebound
     * @param object|null $oldInstance The previous cached instance (if any)
     */
    private function fireRebindCallbacks(string $abstract, ?object $oldInstance): void
    {
        foreach ($this->rebindCallbacks[$abstract] ?? [] as $cb) {
            $cb($abstract, $oldInstance, $this);
        }
    }
}
