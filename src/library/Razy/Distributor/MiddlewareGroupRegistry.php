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

use Closure;
use InvalidArgumentException;
use Razy\Contract\MiddlewareInterface;

/**
 * Registry for named middleware groups.
 *
 * Groups allow defining reusable sets of middleware that can be referenced
 * by name when registering routes or modules.
 *
 * Usage:
 * ```php
 * $registry = new MiddlewareGroupRegistry();
 *
 * $registry->define('web', [
 *     new SessionMiddleware(),
 *     new CsrfMiddleware(),
 * ]);
 *
 * $registry->define('api', [
 *     new RateLimitMiddleware(),
 *     new CorsMiddleware(),
 * ]);
 *
 * // Extend an existing group
 * $registry->appendTo('web', [new LoggingMiddleware()]);
 *
 * // Resolve to middleware instances
 * $middleware = $registry->resolve('web');
 * ```
 */
class MiddlewareGroupRegistry
{
    /**
     * Registered middleware groups.
     *
     * @var array<string, list<MiddlewareInterface|Closure>>
     */
    private array $groups = [];

    /**
     * Define a middleware group.
     *
     * Overwrites any existing group with the same name.
     *
     * @param string $name The group name (e.g. 'web', 'api')
     * @param list<MiddlewareInterface|Closure> $middleware Ordered list of middleware
     *
     * @return $this
     */
    public function define(string $name, array $middleware): static
    {
        $this->groups[$name] = [];
        foreach ($middleware as $mw) {
            $this->groups[$name][] = $mw;
        }

        return $this;
    }

    /**
     * Append middleware to an existing group.
     *
     * Creates the group if it does not exist.
     *
     * @param string $name The group name
     * @param list<MiddlewareInterface|Closure> $middleware Middleware to append
     *
     * @return $this
     */
    public function appendTo(string $name, array $middleware): static
    {
        if (!isset($this->groups[$name])) {
            $this->groups[$name] = [];
        }

        foreach ($middleware as $mw) {
            $this->groups[$name][] = $mw;
        }

        return $this;
    }

    /**
     * Prepend middleware to an existing group.
     *
     * Creates the group if it does not exist.
     *
     * @param string $name The group name
     * @param list<MiddlewareInterface|Closure> $middleware Middleware to prepend
     *
     * @return $this
     */
    public function prependTo(string $name, array $middleware): static
    {
        if (!isset($this->groups[$name])) {
            $this->groups[$name] = [];
        }

        $this->groups[$name] = \array_merge($middleware, $this->groups[$name]);

        return $this;
    }

    /**
     * Resolve a middleware group name to its middleware list.
     *
     * @param string $name The group name
     *
     * @return list<MiddlewareInterface|Closure> The middleware in the group
     *
     * @throws InvalidArgumentException If the group is not defined
     */
    public function resolve(string $name): array
    {
        if (!isset($this->groups[$name])) {
            throw new InvalidArgumentException(
                "Middleware group '{$name}' is not defined.",
            );
        }

        return $this->groups[$name];
    }

    /**
     * Resolve one or more middleware references.
     *
     * Accepts a mix of group names (strings without '\\'), concrete middleware
     * instances, and closures. Returns a flat list of resolved middleware.
     *
     * @param list<string|MiddlewareInterface|Closure> $items
     *
     * @return list<MiddlewareInterface|Closure>
     */
    public function resolveMany(array $items): array
    {
        $result = [];

        foreach ($items as $item) {
            if (\is_string($item)) {
                $result = \array_merge($result, $this->resolve($item));
            } else {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * Check if a group exists.
     *
     * @param string $name The group name
     */
    public function has(string $name): bool
    {
        return isset($this->groups[$name]);
    }

    /**
     * Get all defined group names.
     *
     * @return list<string>
     */
    public function getGroupNames(): array
    {
        return \array_keys($this->groups);
    }

    /**
     * Get the number of groups defined.
     */
    public function count(): int
    {
        return \count($this->groups);
    }

    /**
     * Remove a group.
     *
     * @param string $name The group name
     *
     * @return $this
     */
    public function remove(string $name): static
    {
        unset($this->groups[$name]);

        return $this;
    }
}
