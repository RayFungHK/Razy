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
 *
 * @license MIT
 */

namespace Razy\Distributor;

use Closure;
use InvalidArgumentException;
use Razy\Contract\MiddlewareInterface;
use Razy\Exception\RedirectException;
use Razy\Module;
use Razy\Module\ModuleStatus;
use Razy\Route;
use Razy\Util\PathUtil;
use Razy\Util\StringUtil;
use RuntimeException;
use Throwable;

/**
 * Class RouteDispatcher.
 *
 * Handles route registration (standard, lazy, shadow, CLI script) and URL matching/dispatching.
 *
 * Extracted from the Distributor god class to follow Single Responsibility Principle.
 *
 * @class RouteDispatcher
 *
 * @package Razy\Distributor
 */
class RouteDispatcher
{
    /** @var string[] Recognized HTTP methods for route constraints */
    public const VALID_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', '*'];

    /** @var array<string, array> Registered routes keyed by path pattern */
    private array $routes = [];

    /** @var array<string, array> CLI script routes keyed by path */
    private array $CLIScripts = [];

    /** @var array Metadata about the currently matched route */
    private array $routedInfo = [];

    /** @var bool Whether routes need re-sorting before next match */
    private bool $routesDirty = false;

    /** @var bool Whether CLI scripts need re-sorting before next match */
    private bool $scriptsDirty = false;

    /** @var array<MiddlewareInterface|Closure> Global middleware applied to all routes */
    private array $globalMiddleware = [];

    /** @var array<string, string> Named routes: name → route key */
    private array $namedRoutes = [];

    /** @var array<string, array<MiddlewareInterface|Closure>> Module-level middleware keyed by module code */
    private array $moduleMiddleware = [];

    /**
     * Substitute positional parameters into a route pattern.
     *
     * Replaces each Razy route placeholder (`:d+`, `:a{2,5}`, `:[a-z]+`, etc.)
     * with the corresponding parameter value.
     *
     * @param string $pattern The route pattern (e.g., '/users/:d+/posts/:a+/')
     * @param array $params Positional parameter values
     *
     * @return string The URL with placeholders replaced
     *
     * @throws InvalidArgumentException If there are more placeholders than parameters
     */
    public static function substituteParams(string $pattern, array $params): string
    {
        $paramIndex = 0;
        $paramValues = \array_values($params);
        $paramCount = \count($paramValues);

        $result = \preg_replace_callback(
            '/\\\.(*SKIP)(*FAIL)|:(?:([awdWD])|\[([^\[\]]+)\])(?:\{\d+,?\d*\})?\+?/',
            function ($match) use (&$paramIndex, $paramValues, $paramCount) {
                if ($paramIndex >= $paramCount) {
                    throw new InvalidArgumentException(
                        'Not enough parameters provided for route pattern. '
                        . 'Expected at least ' . ($paramIndex + 1) . ", got {$paramCount}.",
                    );
                }
                return (string) $paramValues[$paramIndex++];
            },
            $pattern,
        );

        return $result;
    }

    /**
     * Pre-compile a route pattern into a regex string.
     *
     * Converts route parameter placeholders (e.g., :a, :d, :[a-z]) into
     * regex capture groups. Called once at registration time instead of
     * on every matchRoute() invocation — P4 optimization.
     *
     * @param string $route The route pattern (e.g., '/users/:d{1,5}/')
     *
     * @return string The compiled regex pattern
     */
    public static function compileRouteRegex(string $route): string
    {
        $compiled = \preg_replace_callback(
            '/\\\.(*SKIP)(*FAIL)|:(?:([awdWD])|(\[[^\[\]]+]))({\d+,?\d*})?/',
            function ($matches) {
                $regex = (\strlen($matches[2] ?? '')) > 0
                    ? $matches[2]
                    : (('a' === $matches[1]) ? '[^/]' : '\\' . $matches[1]);
                return $regex . ((0 !== \strlen($matches[3] ?? '')) ? $matches[3] : '+');
            },
            $route,
        );
        return '/^(' . \preg_replace('/\\\.(*SKIP)(*FAIL)|\//', '\/', $compiled) . ')((?:.+)?)/';
    }

    /**
     * Parse an HTTP method prefix from a route string.
     *
     * Supports the syntax "METHOD /path" where METHOD is a valid HTTP method.
     * Returns an array of [method, cleanRoute]. If no method prefix is found,
     * returns ['*', originalRoute].
     *
     * Examples:
     *   'GET /users'    → ['GET', '/users']
     *   'POST /api'     → ['POST', '/api']
     *   '/users'        → ['*', '/users']
     *   'GET|POST /api' → ['GET|POST', '/api']
     *
     * @param string $route The raw route string
     *
     * @return array{string, string} [method, cleanRoute]
     */
    public static function parseMethodPrefix(string $route): array
    {
        $route = \trim($route);
        if (\preg_match('/^((?:GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)(?:\|(?:GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS))*)\s+(.+)$/i', $route, $matches)) {
            return [\strtoupper($matches[1]), \trim($matches[2])];
        }
        return ['*', $route];
    }

    /**
     * Set up a standard route.
     *
     * @param Module $module
     * @param string $route
     * @param mixed $path
     * @param string $method HTTP method constraint ('*' = any)
     *
     * @return $this
     */
    public function setRoute(Module $module, string $route, mixed $path, string $method = '*'): static
    {
        $route = PathUtil::tidy($route, true, '/');
        // If $path is a Route object with a non-wildcard method, it takes precedence
        if ($path instanceof Route && $path->getMethod() !== '*') {
            $method = $path->getMethod();
        }
        $this->routes[$route] = [
            'module' => $module,
            'path' => $path,
            'route' => $route,
            'type' => 'standard',
            'method' => \strtoupper($method),
            'compiled_regex' => self::compileRouteRegex($route),
        ];
        $this->routesDirty = true;

        // Register named route if the path is a Route object with a name
        if ($path instanceof Route && $path->hasName()) {
            $this->registerNamedRoute($path->getName(), $route);
        }

        return $this;
    }

    /**
     * Set up a shadow route that delegates execution to another module.
     *
     * @param Module $module The owning module
     * @param string $route The route path
     * @param Module|null $targetModule The target module to delegate to
     * @param string $path The closure path within the target module
     *
     * @return $this
     */
    public function setShadowRoute(Module $module, string $route, ?Module $targetModule, string $path, string $method = '*'): static
    {
        if ($targetModule) {
            $this->routes['/' . PathUtil::tidy(PathUtil::append($module->getModuleInfo()->getAlias(), $route), true, '/')] = [
                'module' => $module,
                'target' => $targetModule,
                'path' => $path,
                'route' => $route,
                'type' => 'lazy',
                'method' => \strtoupper($method),
            ];
            $this->routesDirty = true;
        }

        return $this;
    }

    /**
     * Set up a lazy route.
     *
     * @param Module $module The module entity
     * @param string $route The route path string
     * @param string $path The closure path of method
     *
     * @return $this
     */
    public function setLazyRoute(Module $module, string $route, string $path, string $method = '*'): static
    {
        $this->routes['/' . PathUtil::tidy(PathUtil::append($module->getModuleInfo()->getAlias(), $route), true, '/')] = [
            'module' => $module,
            'path' => $path,
            'route' => $route,
            'type' => 'lazy',
            'method' => \strtoupper($method),
        ];
        $this->routesDirty = true;

        return $this;
    }

    /**
     * Set up a CLI script route.
     *
     * @param Module $module
     * @param string $route
     * @param string $path
     *
     * @return $this
     */
    public function setScript(Module $module, string $route, string $path): static
    {
        $this->CLIScripts['/' . PathUtil::tidy(PathUtil::append($module->getModuleInfo()->getAlias(), $route), true, '/')] = [
            'module' => $module,
            'path' => $path,
            'route' => $route,
            'type' => 'script',
        ];
        $this->scriptsDirty = true;
        return $this;
    }

    /**
     * Check if a named route exists.
     *
     * @param string $name The route name
     *
     * @return bool
     */
    public function hasNamedRoute(string $name): bool
    {
        return isset($this->namedRoutes[$name]);
    }

    /**
     * Get the route data for a named route.
     *
     * @param string $name The route name
     *
     * @return ?array The route data array, or null if not found
     */
    public function getNamedRoute(string $name): ?array
    {
        if (!isset($this->namedRoutes[$name])) {
            return null;
        }
        $key = $this->namedRoutes[$name];
        return $this->routes[$key] ?? null;
    }

    /**
     * Get all registered named routes.
     *
     * @return array<string, string> Route names mapped to route keys
     */
    public function getNamedRoutes(): array
    {
        return $this->namedRoutes;
    }

    /**
     * Generate a URL for a named route with parameter substitution.
     *
     * Route patterns use Razy's custom syntax:
     *   :d  → digit(s)
     *   :a  → alphanumeric/non-slash
     *   :w  → word characters
     *   :[regex] → custom character class
     *
     * Parameters are substituted left-to-right into the route pattern's
     * placeholder positions.
     *
     * @param string $name The route name
     * @param array $params Positional parameters to fill placeholders
     * @param array $query Optional query string parameters
     *
     * @return string The generated URL path
     *
     * @throws InvalidArgumentException If the named route does not exist
     * @throws InvalidArgumentException If not enough parameters are provided
     */
    public function route(string $name, array $params = [], array $query = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new InvalidArgumentException("Named route '{$name}' is not defined.");
        }

        $routeKey = $this->namedRoutes[$name];
        $url = self::substituteParams($routeKey, $params);

        if (!empty($query)) {
            $url .= '?' . \http_build_query($query);
        }

        return $url;
    }

    /**
     * Return the registered routes.
     *
     * @return array
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Get the routed information after the route is matched.
     *
     * @return array
     */
    public function getRoutedInfo(): array
    {
        return $this->routedInfo;
    }

    /**
     * Match the registered route and execute the matched path.
     *
     * @param string $urlQuery The URL query to match
     * @param string $siteURL The site base URL
     * @param ModuleRegistry $registry The module registry (for announce)
     *
     * @return bool True if a route was matched and executed
     *
     * @throws Throwable
     */
    public function matchRoute(string $urlQuery, string $siteURL, ModuleRegistry $registry): bool
    {
        // Sort routes only when new routes have been added (dirty flag)
        if (CLI_MODE) {
            if ($this->scriptsDirty) {
                StringUtil::sortPathLevel($this->CLIScripts);
                $this->scriptsDirty = false;
            }
            $list = $this->CLIScripts;
        } else {
            if ($this->routesDirty) {
                StringUtil::sortPathLevel($this->routes);
                $this->routesDirty = false;
            }
            $list = $this->routes;
        }

        // Determine the current request method for HTTP method filtering
        $requestMethod = !CLI_MODE ? ($_SERVER['REQUEST_METHOD'] ?? 'GET') : 'CLI';

        foreach ($list as $route => $data) {
            if (ModuleStatus::Loaded === $data['module']->getStatus()) {
                // HTTP method filtering: skip if the route is restricted to a different method
                $routeMethod = $data['method'] ?? '*';
                if ($routeMethod !== '*' && $routeMethod !== $requestMethod) {
                    continue;
                }

                if ($data['type'] === 'standard') {
                    // Use pre-compiled regex from registration time (P4 optimization)
                    $compiledRegex = $data['compiled_regex'] ?? self::compileRouteRegex($route);

                    if (!\preg_match($compiledRegex, $urlQuery, $matches)) {
                        continue;
                    }
                    // Extract the matched route, remaining URL query, and captured arguments
                    \array_shift($matches);
                    $route = \array_shift($matches);
                    $remainingQuery = \array_pop($matches);
                    $args = $matches;
                    $args += \explode('/', \trim($remainingQuery, '/'));
                } else {
                    if (!\str_starts_with($urlQuery, $route)) {
                        continue;
                    }
                    $remainingQuery = \rtrim(\substr($urlQuery, \strlen($route)), '/');
                    $args = \explode('/', $remainingQuery);
                }

                $path = (\is_string($data['path'])) ? $data['path'] : $data['path']->getClosurePath();
                $path = PathUtil::tidy($path, false, '/');

                // Handle redirect routes: paths starting with "r:" or "r@:" trigger HTTP redirect
                if (\preg_match('/^r(@?):(.+)/', $path, $matches)) {
                    $path = $matches[2];
                    $redirectUrl = PathUtil::append($matches[1] ? $siteURL : $data['module']->getModuleURL(), $path);
                    \header('Location: ' . $redirectUrl);
                    throw new RedirectException($redirectUrl, 302);
                }

                // Determine the executor: shadow routes delegate to the target module
                $executor = (isset($data['target'])) ? $data['target'] : $data['module'];
                if (!$path || !($closure = $executor->getClosure($path))) {
                    return false;
                }

                if ($executor->getStatus() === ModuleStatus::Loaded) {
                    // Build the routed info metadata for the matched route
                    $this->routedInfo = [
                        'url_query' => $urlQuery,
                        'base_url' => PathUtil::append($siteURL, \rtrim($route, '/')),
                        'route' => PathUtil::tidy('/' . $data['route'], false, '/'),
                        'module' => $data['module']->getModuleInfo()->getCode(),
                        'closure_path' => $path,
                        'arguments' => $args,
                        'type' => $data['type'],
                        'method' => $data['method'] ?? '*',
                        'is_shadow' => isset($data['target']),
                    ];

                    if (!\is_string($data['path'])) {
                        $this->routedInfo['contains'] = $data['path']->getData();
                    }

                    $registry->announce($data['module']);

                    if ($data['type'] !== 'script') {
                        $data['module']->entry($this->routedInfo);
                    }

                    // Build middleware pipeline: global → module → route-level
                    $pipeline = new MiddlewarePipeline();
                    $pipeline->pipeMany($this->globalMiddleware);

                    $moduleCode = $data['module']->getModuleInfo()->getCode();
                    if (isset($this->moduleMiddleware[$moduleCode])) {
                        $pipeline->pipeMany($this->moduleMiddleware[$moduleCode]);
                    }

                    // Route-level middleware from Route objects
                    if (!\is_string($data['path']) && $data['path'] instanceof Route && $data['path']->hasMiddleware()) {
                        $pipeline->pipeMany($data['path']->getMiddleware());
                    }

                    if (!$pipeline->isEmpty()) {
                        $finalClosure = $closure;
                        $finalArgs = $args;
                        $pipeline->process($this->routedInfo, static function (array $context) use ($finalClosure, $finalArgs): mixed {
                            \call_user_func_array($finalClosure, $finalArgs);
                            return null;
                        });
                    } else {
                        \call_user_func_array($closure, $args);
                    }
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Register global middleware that applies to all routes.
     *
     * Global middleware runs before module-level and route-level middleware.
     *
     * @param MiddlewareInterface|Closure ...$middleware One or more middleware
     *
     * @return $this Fluent interface
     */
    public function addGlobalMiddleware(MiddlewareInterface|Closure ...$middleware): static
    {
        foreach ($middleware as $mw) {
            $this->globalMiddleware[] = $mw;
        }
        return $this;
    }

    /**
     * Register module-level middleware that applies to all routes of a specific module.
     *
     * Module middleware runs after global middleware but before route-level middleware.
     *
     * @param string $moduleCode The module code (e.g., 'vendor/module')
     * @param MiddlewareInterface|Closure ...$middleware One or more middleware
     *
     * @return $this Fluent interface
     */
    public function addModuleMiddleware(string $moduleCode, MiddlewareInterface|Closure ...$middleware): static
    {
        if (!isset($this->moduleMiddleware[$moduleCode])) {
            $this->moduleMiddleware[$moduleCode] = [];
        }
        foreach ($middleware as $mw) {
            $this->moduleMiddleware[$moduleCode][] = $mw;
        }
        return $this;
    }

    /**
     * Get all global middleware.
     *
     * @return array<MiddlewareInterface|Closure>
     */
    public function getGlobalMiddleware(): array
    {
        return $this->globalMiddleware;
    }

    /**
     * Get module-level middleware for a specific module.
     *
     * @param string $moduleCode
     *
     * @return array<MiddlewareInterface|Closure>
     */
    public function getModuleMiddleware(string $moduleCode): array
    {
        return $this->moduleMiddleware[$moduleCode] ?? [];
    }

    /**
     * Register a named route mapping.
     *
     * @param string $name The route name
     * @param string $routeKey The route key in the $routes array
     *
     * @throws RuntimeException If a route with the same name is already registered
     */
    private function registerNamedRoute(string $name, string $routeKey): void
    {
        if (isset($this->namedRoutes[$name]) && $this->namedRoutes[$name] !== $routeKey) {
            throw new RuntimeException(
                "Duplicate named route '{$name}'. Already registered to '{$this->namedRoutes[$name]}', cannot re-register to '{$routeKey}'.",
            );
        }
        $this->namedRoutes[$name] = $routeKey;
    }
}
