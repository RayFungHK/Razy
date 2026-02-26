<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\Routing;

use Closure;
use Razy\Contract\MiddlewareInterface;
use Razy\Route;
use Razy\Util\PathUtil;

/**
 * Groups related routes under a shared prefix, middleware set, and/or name prefix.
 *
 * Route groups allow defining common attributes for a set of routes:
 * - **URL prefix**: All routes in the group share a URL prefix (e.g., `/api/v1`)
 * - **Middleware**: Shared middleware applied to all routes in the group
 * - **Name prefix**: Prefix for named routes (e.g., `api.` → `api.users.index`)
 * - **Method constraint**: Default HTTP method for routes without explicit method
 * - **Nesting**: Groups can be nested, and attributes accumulate
 *
 * Usage (standalone):
 * ```php
 * $group = RouteGroup::create('/admin')
 *     ->middleware(new AuthMiddleware())
 *     ->namePrefix('admin.')
 *     ->routes(function (RouteGroup $group) {
 *         $group->addRoute('/', 'dashboard');
 *         $group->addRoute('/users', (new Route('users/index'))->name('users'));
 *         $group->group('/settings', function (RouteGroup $sub) {
 *             $sub->addRoute('/', 'settings/index');
 *             $sub->addRoute('/profile', 'settings/profile');
 *         });
 *     });
 * ```
 *
 * Usage (with Agent):
 * ```php
 * $agent->group('/api', function (RouteGroup $group) {
 *     $group->middleware(new RateLimitMiddleware());
 *     $group->addRoute('/users', 'api/users');
 *     $group->addRoute('/posts', 'api/posts');
 * });
 * ```
 *
 * @package Razy\Routing
 */
class RouteGroup
{
    /**
     * URL prefix for all routes in this group.
     */
    private string $prefix;

    /**
     * Middleware to apply to all routes in this group.
     *
     * @var array<MiddlewareInterface|Closure>
     */
    private array $middleware = [];

    /**
     * Name prefix appended to named routes in this group.
     */
    private string $namePrefix = '';

    /**
     * Default HTTP method constraint for routes in this group.
     */
    private string $method = '*';

    /**
     * Collected route entries.
     *
     * Each entry is one of:
     * - `['type' => 'route', 'path' => string, 'handler' => string|Route, 'routeType' => 'Route'|'LazyRoute']`
     * - `['type' => 'group', 'group' => RouteGroup]`
     *
     * @var list<array>
     */
    private array $entries = [];

    /**
     * Create a new RouteGroup instance.
     *
     * @param string $prefix URL prefix for all routes in this group
     */
    public function __construct(string $prefix = '')
    {
        $this->prefix = trim($prefix, '/');
    }

    /**
     * Static factory method for fluent creation.
     *
     * @param string $prefix URL prefix
     */
    public static function create(string $prefix = ''): static
    {
        return new static($prefix);
    }

    // ═══════════════════════════════════════════════════════════════
    // Configuration
    // ═══════════════════════════════════════════════════════════════

    /**
     * Set middleware for the group.
     *
     * @param MiddlewareInterface|Closure ...$middleware
     */
    public function middleware(MiddlewareInterface|Closure ...$middleware): static
    {
        foreach ($middleware as $mw) {
            $this->middleware[] = $mw;
        }

        return $this;
    }

    /**
     * Set a name prefix for named routes in this group.
     *
     * @param string $prefix e.g. 'admin.' — dots are NOT auto-appended
     */
    public function namePrefix(string $prefix): static
    {
        $this->namePrefix = $prefix;

        return $this;
    }

    /**
     * Set a default HTTP method constraint for routes in this group.
     *
     * Individual routes can override this.
     *
     * @param string $method HTTP method or '*'
     */
    public function method(string $method): static
    {
        $this->method = strtoupper(trim($method));

        return $this;
    }

    /**
     * Define routes using a callback — for fluent one-shot group creation.
     *
     * @param Closure $callback fn(RouteGroup $group): void
     */
    public function routes(Closure $callback): static
    {
        $callback($this);

        return $this;
    }

    // ═══════════════════════════════════════════════════════════════
    // Route Registration
    // ═══════════════════════════════════════════════════════════════

    /**
     * Add a standard route to the group.
     *
     * @param string       $path    Route path (relative to group prefix)
     * @param string|Route $handler Closure path or Route entity
     */
    public function addRoute(string $path, string|Route $handler): static
    {
        $this->entries[] = [
            'type' => 'route',
            'path' => $path,
            'handler' => $handler,
            'routeType' => 'Route',
        ];

        return $this;
    }

    /**
     * Add a lazy route to the group.
     *
     * @param string       $path    Route path (relative to group prefix)
     * @param string|Route $handler Closure path or Route entity
     */
    public function addLazyRoute(string $path, string|Route $handler): static
    {
        $this->entries[] = [
            'type' => 'route',
            'path' => $path,
            'handler' => $handler,
            'routeType' => 'LazyRoute',
        ];

        return $this;
    }

    /**
     * Create a nested sub-group with an additional prefix.
     *
     * ```php
     * $group->group('/admin', function (RouteGroup $sub) {
     *     $sub->addRoute('/dashboard', 'admin/dashboard');
     * });
     * ```
     *
     * @param string  $prefix   Sub-group prefix (relative to parent)
     * @param Closure $callback fn(RouteGroup $sub): void
     */
    public function group(string $prefix, Closure $callback): static
    {
        $subGroup = new static($prefix);
        $callback($subGroup);

        $this->entries[] = [
            'type' => 'group',
            'group' => $subGroup,
        ];

        return $this;
    }

    // ═══════════════════════════════════════════════════════════════
    // Resolution (flattening)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Resolve all routes into a flat list of route definitions.
     *
     * Accumulates prefix, middleware, name prefix, and method from parent groups.
     * Each resolved entry is:
     * ```
     * ['path' => string, 'handler' => string|Route, 'routeType' => 'Route'|'LazyRoute']
     * ```
     *
     * Route objects are cloned and decorated with accumulated group middleware,
     * name prefix, and method constraint.
     *
     * @param string                              $parentPrefix     Accumulated URL prefix
     * @param array<MiddlewareInterface|Closure>   $parentMiddleware Accumulated middleware
     * @param string                              $parentNamePrefix Accumulated name prefix
     * @param string                              $parentMethod     Inherited method constraint
     *
     * @return list<array{path: string, handler: string|Route, routeType: string}>
     */
    public function resolve(
        string $parentPrefix = '',
        array $parentMiddleware = [],
        string $parentNamePrefix = '',
        string $parentMethod = '*',
    ): array {
        $currentPrefix = $this->buildPrefix($parentPrefix);
        $currentMiddleware = array_merge($parentMiddleware, $this->middleware);
        $currentNamePrefix = $parentNamePrefix . $this->namePrefix;
        $currentMethod = $this->method !== '*' ? $this->method : $parentMethod;

        $resolved = [];

        foreach ($this->entries as $entry) {
            if ($entry['type'] === 'group') {
                /** @var RouteGroup $subGroup */
                $subGroup = $entry['group'];
                $resolved = array_merge(
                    $resolved,
                    $subGroup->resolve($currentPrefix, $currentMiddleware, $currentNamePrefix, $currentMethod),
                );
            } else {
                $routePath = $this->buildRoutePath($currentPrefix, $entry['path']);
                $handler = $this->decorateHandler(
                    $entry['handler'],
                    $currentMiddleware,
                    $currentNamePrefix,
                    $currentMethod,
                );

                $resolved[] = [
                    'path' => $routePath,
                    'handler' => $handler,
                    'routeType' => $entry['routeType'],
                ];
            }
        }

        return $resolved;
    }

    // ═══════════════════════════════════════════════════════════════
    // Accessors
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get the group prefix.
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Get the group middleware.
     *
     * @return array<MiddlewareInterface|Closure>
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Get the group name prefix.
     */
    public function getNamePrefix(): string
    {
        return $this->namePrefix;
    }

    /**
     * Get the group default method.
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Get the raw (un-resolved) entries.
     *
     * @return list<array>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    // ═══════════════════════════════════════════════════════════════
    // Internal helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Build the accumulated prefix path.
     */
    private function buildPrefix(string $parentPrefix): string
    {
        if ($parentPrefix !== '' && $this->prefix !== '') {
            return $parentPrefix . '/' . $this->prefix;
        }

        return $parentPrefix !== '' ? $parentPrefix : $this->prefix;
    }

    /**
     * Build the full route path from the accumulated prefix and local path.
     */
    private function buildRoutePath(string $prefix, string $localPath): string
    {
        $localPath = trim($localPath, '/');

        if ($prefix !== '' && $localPath !== '') {
            return $prefix . '/' . $localPath;
        }

        if ($prefix !== '') {
            return $prefix;
        }

        return $localPath;
    }

    /**
     * Decorate a handler (string or Route) with accumulated group attributes.
     *
     * For string handlers: wraps in a Route object only if group has middleware,
     * name prefix, or method constraint to apply.
     *
     * For Route handlers: adds group middleware, prepends name prefix, and sets
     * method if the route doesn't have its own.
     *
     * @param string|Route                        $handler    The original handler
     * @param array<MiddlewareInterface|Closure>   $middleware Accumulated group middleware
     * @param string                              $namePrefix Accumulated name prefix
     * @param string                              $method     Inherited method constraint
     *
     * @return string|Route
     */
    private function decorateHandler(
        string|Route $handler,
        array $middleware,
        string $namePrefix,
        string $method,
    ): string|Route {
        $hasGroupAttrs = !empty($middleware) || $namePrefix !== '' || $method !== '*';

        if (is_string($handler)) {
            if (!$hasGroupAttrs) {
                return $handler;
            }

            // Wrap in a Route to carry the group attributes
            $route = new Route($handler);
            if (!empty($middleware)) {
                $route->middleware(...$middleware);
            }
            if ($method !== '*') {
                $route->method($method);
            }

            return $route;
        }

        // Handler is already a Route — apply group attributes
        if (!empty($middleware)) {
            // Group middleware runs before route-level middleware
            $existingMw = $handler->getMiddleware();
            // We need to prepend group middleware — rebuild
            $route = new Route($handler->getClosurePath());

            // Copy existing settings
            if ($handler->getData() !== null) {
                $route->contain($handler->getData());
            }

            // Group middleware first, then route's own
            $route->middleware(...$middleware);
            if (!empty($existingMw)) {
                $route->middleware(...$existingMw);
            }

            // Name: prepend group prefix
            if ($handler->hasName()) {
                $route->name($namePrefix . $handler->getName());
            }

            // Method: route's own takes precedence
            if ($handler->getMethod() !== '*') {
                $route->method($handler->getMethod());
            } elseif ($method !== '*') {
                $route->method($method);
            }

            return $route;
        }

        // No group middleware, but may have name prefix or method
        if ($namePrefix !== '' && $handler->hasName()) {
            // Need to recreate since name is set via setter and can't be changed
            $route = new Route($handler->getClosurePath());
            if ($handler->getData() !== null) {
                $route->contain($handler->getData());
            }
            if ($handler->hasMiddleware()) {
                $route->middleware(...$handler->getMiddleware());
            }
            $route->name($namePrefix . $handler->getName());
            if ($handler->getMethod() !== '*') {
                $route->method($handler->getMethod());
            } elseif ($method !== '*') {
                $route->method($method);
            }

            return $route;
        }

        // Only method to apply
        if ($method !== '*' && $handler->getMethod() === '*') {
            $handler->method($method);
        }

        return $handler;
    }
}
