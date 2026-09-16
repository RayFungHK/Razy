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

    /**
     * Readiness gate (dossier MODULE-LIFECYCLE.md L3): module code whose
     * schema must be ready before this route executes, or 'self' for the
     * owning module. Enforced by the dispatcher — a gate that lives in the
     * handler body is the per-handler isInstalled() check this replaces.
     *
     * @var ?string
     */
    private ?string $readyGate = null;

    /**
     * CSRF exemption justification (CSRF-RAIL.md Q3): set only through
     * csrfExempt(), which refuses an empty reason — an exemption nobody
     * can explain is a hole, not a policy. The armed door consults it via
     * the routed context; nothing string-typed crosses module lines.
     *
     * @var ?string
     */
    private ?string $csrfExemptReason = null;

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
     * Gate this route on another module's readiness (or 'self'): while the
     * named module is not ready, the framework answers 503 (or 302 into the
     * wizard when that module declares provision 'wizard') — never a 404,
     * never a handler writing to tables that do not exist yet.
     *
     * @param string $moduleCode 'vendor/module' or 'self'
     *
     * @return $this Fluent interface
     */
    public function ready(string $moduleCode): self
    {
        $this->readyGate = \trim($moduleCode);

        return $this;
    }

    public function getReadyGate(): ?string
    {
        return $this->readyGate;
    }

    public function hasReadyGate(): bool
    {
        return $this->readyGate !== null && $this->readyGate !== '';
    }

    /**
     * Declare this route exempt from the dist's armed CSRF door, WITH the
     * reason that justifies it (CSRF-RAIL.md Q3).
     *
     * The reason is mandatory AT REGISTRATION — the wizard-without-
     * migrations rail shape: unrepresentable beats warned-about, so there
     * is nothing left for a static scan to catch. Typical legitimate
     * uses: signature-verified webhooks (the HMAC is the origin proof),
     * machine-to-machine bridge doors.
     *
     * @param string $reason non-empty justification, human-readable
     *
     * @return $this Fluent interface
     *
     * @throws InvalidArgumentException on an empty/whitespace reason
     */
    public function csrfExempt(string $reason): self
    {
        $reason = \trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException(
                'csrfExempt() requires a non-empty reason — every CSRF exemption must'
                . ' name its origin proof (e.g. "webhook: HMAC-verified upstream X").',
            );
        }

        $this->csrfExemptReason = $reason;

        return $this;
    }

    public function isCsrfExempt(): bool
    {
        return $this->csrfExemptReason !== null;
    }

    public function getCsrfExemptReason(): ?string
    {
        return $this->csrfExemptReason;
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
