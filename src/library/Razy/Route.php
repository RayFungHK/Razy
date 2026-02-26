<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Represents a route entry that maps a closure path to its associated
 * controller, with optional attached data for route-level context.
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy;

use Closure;
use InvalidArgumentException;
use Razy\Contract\MiddlewareInterface;
use Razy\Util\PathUtil;

/**
 * Route entry binding a closure path to a controller.
 *
 * Encapsulates a normalized closure path, optional HTTP method constraint,
 * and optional data payload that is passed to the controller when the
 * route is matched.
 *
 * @class Route
 */
class Route
{
    /** @var string[] Valid HTTP methods that can be used as route constraints */
    private const VALID_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', '*'];

    /** @var string HTTP method constraint ('*' = any method) */
    private string $method = '*';

    /** @var ?string Optional route name for named route lookups */
    private ?string $name = null;

    /** @var mixed Arbitrary data attached to this route for controller consumption */
    private mixed $data = null;

    /** @var array<MiddlewareInterface|Closure> Route-level middleware */
    private array $middleware = [];

    /**
     * Route constructor.
     *
     * @param string $closurePath
     *
     * @throws InvalidArgumentException
     */
    public function __construct(private string $closurePath)
    {
        // Normalize the path separators and strip leading/trailing slashes
        $this->closurePath = \trim(PathUtil::tidy($this->closurePath, false, '/'), '/');
        if (\strlen($this->closurePath) === 0) {
            throw new InvalidArgumentException('The closure path cannot be empty.');
        }
    }

    /**
     * Insert data for passing data to controller that routed in.
     *
     * @param $data
     *
     * @return $this
     */
    public function contain($data = null): self
    {
        $this->data = $data;
        return $this;
    }

    /**
     * Get the data that inserted before.
     *
     * @return mixed
     */
    public function getData(): mixed
    {
        return $this->data;
    }

    /**
     * Set the HTTP method constraint for this route.
     *
     * @param string $method HTTP method (GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS, or '*' for any)
     *
     * @return $this Fluent interface
     *
     * @throws InvalidArgumentException If method is not a valid HTTP method
     */
    public function method(string $method): self
    {
        $method = \strtoupper(\trim($method));
        if (!\in_array($method, self::VALID_METHODS, true)) {
            throw new InvalidArgumentException(
                "Invalid HTTP method '{$method}'. Valid methods: " . \implode(', ', self::VALID_METHODS),
            );
        }
        $this->method = $method;
        return $this;
    }

    /**
     * Get the HTTP method constraint.
     *
     * @return string The HTTP method ('*' means any)
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Attach middleware to this route.
     *
     * Middleware is executed in the order added, wrapping the route handler
     * in an onion-style pipeline. Accepts MiddlewareInterface objects or Closures.
     *
     * @param MiddlewareInterface|Closure ...$middleware One or more middleware
     *
     * @return $this Fluent interface
     */
    public function middleware(MiddlewareInterface|Closure ...$middleware): self
    {
        foreach ($middleware as $mw) {
            $this->middleware[] = $mw;
        }
        return $this;
    }

    /**
     * Get all middleware attached to this route.
     *
     * @return array<MiddlewareInterface|Closure>
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Check if this route has any middleware.
     *
     * @return bool
     */
    public function hasMiddleware(): bool
    {
        return \count($this->middleware) > 0;
    }

    /**
     * Assign a name to this route for named route lookups and URL generation.
     *
     * @param string $name The route name (e.g., 'users.show')
     *
     * @return $this Fluent interface
     *
     * @throws InvalidArgumentException If the name is empty or contains invalid characters
     */
    public function name(string $name): self
    {
        $name = \trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Route name cannot be empty.');
        }
        if (!\preg_match('/^[A-Za-z_][A-Za-z0-9_.\-]*$/', $name)) {
            throw new InvalidArgumentException(
                "Invalid route name '{$name}'. Names must start with a letter or underscore and contain only alphanumeric characters, dots, hyphens, and underscores.",
            );
        }
        $this->name = $name;
        return $this;
    }

    /**
     * Get the route name.
     *
     * @return ?string The route name, or null if unnamed
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Check if this route has a name.
     *
     * @return bool
     */
    public function hasName(): bool
    {
        return $this->name !== null;
    }

    /**
     * Get the closure path.
     *
     * @return string
     */
    public function getClosurePath(): string
    {
        return $this->closurePath;
    }
}
