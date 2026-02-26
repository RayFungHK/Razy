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
use Razy\Contract\MiddlewareInterface;
use Razy\Routing\RouteGroup;
use Throwable;

use Razy\Util\PathUtil;
/**
 * Agent acts as the public interface for module initialization and configuration.
 *
 * During the __onInit lifecycle event, the Controller receives an Agent instance
 * to register routes, API commands, bridge commands, event listeners, shadow routes,
 * and scripts. It delegates all registration calls to the underlying Module instance.
 *
 * @class Agent
 * @package Razy
 * @license MIT
 */
class Agent
{
    /**
     * Agent constructor.
     *
     * @param Module $module
     */
    public function __construct(private readonly Module $module)
    {
    }

    /**
     * Register an API command.
     *
     * @param array|string $command An array of API command or the API command will register
     * @param null|string $path The path of the closure file
     *
     * @return $this Fluent interface
     * @throws Throwable
     */
    public function addAPICommand(mixed $command, ?string $path = null): static
    {
        if (is_array($command)) {
            // Batch registration: recursively register each command => path pair
            foreach ($command as $_command => $_path) {
                if (is_string($_path)) {
                    $this->addAPICommand($_command, $_path);
                }
            }
        } else {
            $command = trim($command);
            // Validate command name: optional '#' prefix, starts with letter, then word chars
            if (1 !== preg_match('/^#?[a-z]\w*$/i', $command)) {
                throw new \InvalidArgumentException('Invalid command name format');
            }

            $this->module->addAPICommand($command, $path);
        }

        return $this;
    }

    /**
     * Register a bridge command for cross-distributor communication.
     * Bridge commands are separate from API commands - they are exposed to external distributors.
     *
     * @param array|string $command An array of bridge commands or single command name
     * @param null|string $path The path of the closure file
     *
     * @return $this Fluent interface
     * @throws Throwable
     */
    public function addBridgeCommand(mixed $command, ?string $path = null): static
    {
        if (is_array($command)) {
            // Batch registration: recursively register each bridge command => path pair
            foreach ($command as $_command => $_path) {
                if (is_string($_path)) {
                    $this->addBridgeCommand($_command, $_path);
                }
            }
        } else {
            $command = trim($command);
            // Validate: must start with a letter, followed by word characters (no '#' prefix unlike API commands)
            if (1 !== preg_match('/^[a-z]\w*$/i', $command)) {
                throw new \InvalidArgumentException('Invalid bridge command name format');
            }

            $this->module->addBridgeCommand($command, $path);
        }

        return $this;
    }

    /**
     * Register a listener for an event from another module.
     *
     * Returns whether the target module(s) are currently loaded:
     * - For single event: returns bool
     * - For array of events: returns array of bools keyed by event name
     *
     * @param array|string $event An array of events or a single event name
     * @param string|Closure|null $path The path of the closure or anonymous function
     *
     * @return bool|array True if target module loaded, false if not (or array for multiple)
     * @throws \InvalidArgumentException
     * @throws Throwable
     */
    public function listen(mixed $event, null|string|callable $path = null): bool|array
    {
        if (is_array($event)) {
            $results = [];
            foreach ($event as $_event => $_path) {
                $results[$_event] = $this->listen($_event, $_path);
            }
            return $results;
        }

        $event = trim($event);
        // Split event into "moduleCode:eventName" parts
        [$moduleCode, $eventName] = explode(':', $event . ':');
        // Validate module code format and event name (dot-separated identifiers)
        if (!preg_match(ModuleInfo::REGEX_MODULE_CODE, $moduleCode) || !preg_match('/^[a-z]\w*(\.[a-z][\w-]*)*$/i', $eventName)) {
            throw new \InvalidArgumentException('Invalid event name format');
        }

        // Convert callable to first-class closure for consistent handling
        if (is_callable($path)) {
            $path = $path(...);
        }

        return $this->module->listen($moduleCode . ':' . $eventName, $path);
    }

    /**
     * Add a shadow path used to redirect to another module's specified route when the route has matched.
     *
     * @param string $route
     * @param string $moduleCode
     * @param string $path
     * @return $this
     * @throws \InvalidArgumentException
     */
    public function addShadowRoute(string $route, string $moduleCode, string $path = ''): static
    {
        $moduleCode = trim($moduleCode);
        $route = trim($route);
        $path = trim($path);
        if (!$path) {
            $path = $route;
        }

        if ($moduleCode === $this->module->getModuleInfo()->getCode()) {
            throw new \InvalidArgumentException('You cannot add a shadow route');
        }

        $this->module->addShadowRoute($route, $moduleCode, $path);
        return $this;
    }

    /**
     * Register a script route that executes after the main route handler completes.
     *
     * @param array|string $route The script route path or an array of route => path pairs
     * @param string|Route|array|null $path The path to the closure file, a Route entity, or nested array
     *
     * @return $this Fluent interface
     * @throws \InvalidArgumentException
     */
    public function addScript($route, $path = null): self
    {
        return $this->addRoutePath('Script', $route, $path);
    }

    /**
     * Internal helper to register a route, lazy route, or script path.
     *
     * Handles scalar, array, and nested array formats for route definitions.
     * Nested arrays allow defining hierarchical routes where each level corresponds
     * to a URL path segment and folder level.
     *
     * @param string $type The registration type ('Route', 'LazyRoute', or 'Script')
     * @param mixed $route The route path or array of route => path pairs
     * @param mixed $path The closure file path, Route entity, or nested definition
     *
     * @return $this Fluent interface
     * @throws \InvalidArgumentException
     */
    private function addRoutePath(string $type, mixed $route, mixed $path = null): static
    {
        if (is_array($route)) {
            foreach ($route as $_route => $_method) {
                $this->addRoutePath($type, $_route, $_method);
            }
        } else {
            if (!is_string($route)) {
                throw new \InvalidArgumentException('The route must be a string or an array');
            }
            $route = trim($route);

            if (is_string($path) || $path instanceof Route) {
                if (is_string($path)) {
                    $path = trim(PathUtil::tidy($path, false, '/'), '/');
                }
                call_user_func_array([$this->module, 'add' . $type], [$route, $path]);
            } elseif (is_array($path)) {
                // Recursively expand nested route arrays into flat route registrations.
                // '@self' key maps the current level's path to a closure without appending a sub-path.
                $extendRoute = function (array $routeSet, string $relativePath = '') use (&$extendRoute, $type) {
                    foreach ($routeSet as $node => $path) {
                        if (is_array($path)) {
                            // Recurse into sub-directory level
                            $extendRoute($path, PathUtil::append($relativePath, $node));
                        } else {
                            $path = trim($path);
                            if (strlen($path) > 0) {
                                // '@self' references the current directory level itself
                                $routePath = ($node == '@self') ? $relativePath : PathUtil::append($relativePath, $node);
                                call_user_func_array([$this->module, 'add' . $type], [$routePath, PathUtil::append($relativePath, $path)]);
                            }
                        }
                    }
                };
                $extendRoute($path, $route);
            }
        }

        return $this;
    }

    /**
     * Add a route by given nested array, each level equal as the URL path and the folder level.
     * For example, if there is an array contains 3 level with `1`, `2`, `3` and the `3` has a value `test`, the path
     * of domain.com/module/1/2/3 will link to ./controller/1/2/3/test.php closure file.
     *
     * @param mixed $route The path of the route based on the module code
     * @param mixed|null $path
     *
     * @return $this Fluent interface
     * @throws Throwable
     */
    public function addLazyRoute(mixed $route, mixed $path = null): static
    {
        return $this->addRoutePath('LazyRoute', $route, $path);
    }

    /**
     * Put the callable into the list to wait for execute until other specified modules has ready.
     *
     * @param string $moduleCode
     * @param callable $caller
     *
     * @return $this
     */
    public function await(string $moduleCode, callable $caller): static
    {
        $this->module->await($moduleCode, $caller(...));

        return $this;
    }

    /**
     * Get the thread manager for this module.
     *
     * @return ThreadManager
     */
    public function thread(): ThreadManager
    {
        return $this->module->getThreadManager();
    }

    /**
     * Add a route by the string of route, the regular route will be matched in URL query routing in priority.
     * You can use parentheses to enclose the string and pass to the closure function if the route is matched.
     * Also, you can use the following words to capture specified characters:
     * :a Match any characters
     * :d Match any digit characters, 0-9
     * :D Match any non-digit characters
     * :w Match any alphabet characters, a-zA-Z
     * :W Match any non-alphabet characters
     * :[\w\d-@*] Match the characters by the regex expression string
     * Add {min,max} to capture specified length of the string, the max value can be ignored such as {3,} to match 0 to
     * 3 length string or {5} to match fixed 5 length string.
     *
     * @param mixed $route An array of route set or a string of route. The string of route will be matched as a regular
     *                     expression
     * @param string|Route|null $path The path of the closure file or a Route entity
     *
     * @return $this Fluent interface
     * @throws Throwable
     */
    public function addRoute(mixed $route, mixed $path = null): static
    {
        if (is_array($route)) {
            // Batch registration: iterate route => closure path pairs
            foreach ($route as $_route => $_method) {
                if (is_string($_method) || $_method instanceof Route) {
                    $this->addRoute($_route, $_method);
                }
            }
        } else {
            if (!is_string($path) && !($path instanceof Route)) {
                throw new \InvalidArgumentException('The route must be a string or a Route entity');
            }

            $route = trim($route);
            if (is_string($path)) {
                // Normalize the closure file path separators
                $path = trim(PathUtil::tidy($path, false, '/'), '/');
                $method = $path;
            } else {
                // Extract closure path from the Route entity
                $method = $path->getClosurePath();
            }

            // Only register if the resolved closure path is non-empty
            if (strlen($method) > 0 && ($path instanceof Route || strlen($path) > 0)) {
                $this->module->addRoute($route, $path);
            }
        }

        return $this;
    }

    /**
     * Register middleware for all routes of the current module.
     *
     * Module-level middleware runs after global middleware but before
     * route-level middleware. Call this in __onInit or __onLoad.
     *
     * Example:
     * ```php
     * // In __onInit:
     * $agent->middleware(new AuthMiddleware(), new CorsMiddleware());
     *
     * // Or with closures:
     * $agent->middleware(function(array $ctx, Closure $next) {
     *     // pre-processing
     *     $result = $next($ctx);
     *     // post-processing
     *     return $result;
     * });
     * ```
     *
     * @param MiddlewareInterface|Closure ...$middleware One or more middleware
     * @return $this Fluent interface
     */
    public function middleware(MiddlewareInterface|Closure ...$middleware): static
    {
        $this->module->addModuleMiddleware(...$middleware);
        return $this;
    }

    /**
     * Register a group of routes with shared prefix, middleware, and name prefix.
     *
     * The callback receives a RouteGroup instance for defining routes within
     * the group. Groups can be nested for hierarchical URL structures.
     *
     * Example:
     * ```php
     * $agent->group('/api/v1', function (RouteGroup $group) {
     *     $group->middleware(new AuthMiddleware());
     *     $group->namePrefix('api.');
     *     $group->addRoute('/users', 'api/users');
     *     $group->addRoute('/posts', 'api/posts');
     *
     *     $group->group('/admin', function (RouteGroup $sub) {
     *         $sub->middleware(new AdminMiddleware());
     *         $sub->addRoute('/dashboard', 'admin/dashboard');
     *     });
     * });
     * ```
     *
     * @param string  $prefix   URL prefix for the group
     * @param Closure $callback fn(RouteGroup $group): void
     *
     * @return $this Fluent interface
     */
    public function group(string $prefix, Closure $callback): static
    {
        $group = new RouteGroup($prefix);
        $callback($group);

        foreach ($group->resolve() as $entry) {
            $registrar = 'add' . $entry['routeType'];
            $this->$registrar($entry['path'], $entry['handler']);
        }

        return $this;
    }
}