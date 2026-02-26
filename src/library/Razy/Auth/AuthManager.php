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

namespace Razy\Auth;

use InvalidArgumentException;
use Razy\Contract\GuardInterface;
use Razy\Contract\AuthenticatableInterface;

/**
 * Authentication manager supporting multiple named guards.
 *
 * Manages a registry of authentication guards and delegates
 * authentication checks to the appropriate guard. One guard
 * is designated as the default.
 *
 * Usage:
 * ```php
 * $auth = new AuthManager();
 * $auth->addGuard('web', new SessionGuard(...));
 * $auth->addGuard('api', new TokenGuard(...));
 * $auth->setDefaultGuard('web');
 *
 * // Use default guard
 * if ($auth->check()) {
 *     $user = $auth->user();
 * }
 *
 * // Use specific guard
 * if ($auth->guard('api')->check()) {
 *     $apiUser = $auth->guard('api')->user();
 * }
 * ```
 *
 * @package Razy\Auth
 */
class AuthManager
{
    /**
     * Registered guards.
     *
     * @var array<string, GuardInterface>
     */
    private array $guards = [];

    /**
     * The name of the default guard.
     */
    private string $defaultGuard = 'default';

    /**
     * Create a new AuthManager with optional initial guards.
     *
     * @param array<string, GuardInterface> $guards       Initial guards to register
     * @param string                        $defaultGuard The name of the default guard
     */
    public function __construct(array $guards = [], string $defaultGuard = 'default')
    {
        foreach ($guards as $name => $guard) {
            $this->addGuard($name, $guard);
        }

        $this->defaultGuard = $defaultGuard;
    }

    /**
     * Register an authentication guard.
     *
     * @param string         $name  Guard name (e.g., 'web', 'api')
     * @param GuardInterface $guard The guard instance
     *
     * @return static For method chaining
     */
    public function addGuard(string $name, GuardInterface $guard): static
    {
        $this->guards[$name] = $guard;

        return $this;
    }

    /**
     * Get a guard by name, or the default guard.
     *
     * @param string|null $name Guard name, or null for the default
     *
     * @return GuardInterface The requested guard
     *
     * @throws InvalidArgumentException If the guard name is not registered
     */
    public function guard(?string $name = null): GuardInterface
    {
        $name ??= $this->defaultGuard;

        if (!isset($this->guards[$name])) {
            throw new InvalidArgumentException("Guard [{$name}] is not registered.");
        }

        return $this->guards[$name];
    }

    /**
     * Set the default guard name.
     *
     * @param string $name The guard name
     *
     * @return static For method chaining
     */
    public function setDefaultGuard(string $name): static
    {
        $this->defaultGuard = $name;

        return $this;
    }

    /**
     * Get the default guard name.
     *
     * @return string The default guard name
     */
    public function getDefaultGuard(): string
    {
        return $this->defaultGuard;
    }

    /**
     * Check if a guard is registered.
     *
     * @param string $name Guard name
     *
     * @return bool True if the guard exists
     */
    public function hasGuard(string $name): bool
    {
        return isset($this->guards[$name]);
    }

    /**
     * Get all registered guard names.
     *
     * @return string[] Array of guard names
     */
    public function getGuardNames(): array
    {
        return array_keys($this->guards);
    }

    // ── Default Guard Delegation ──────────────────────────────────

    /**
     * Determine if the current user is authenticated (via default guard).
     *
     * @return bool True if authenticated
     */
    public function check(): bool
    {
        return $this->guard()->check();
    }

    /**
     * Determine if the current user is a guest (via default guard).
     *
     * @return bool True if not authenticated
     */
    public function guest(): bool
    {
        return $this->guard()->guest();
    }

    /**
     * Get the currently authenticated user (via default guard).
     *
     * @return AuthenticatableInterface|null The user, or null
     */
    public function user(): ?AuthenticatableInterface
    {
        return $this->guard()->user();
    }

    /**
     * Get the ID of the currently authenticated user (via default guard).
     *
     * @return string|int|null The user ID, or null
     */
    public function id(): string|int|null
    {
        return $this->guard()->id();
    }

    /**
     * Validate credentials via the default guard.
     *
     * @param array<string, mixed> $credentials
     *
     * @return bool True if credentials are valid
     */
    public function validate(array $credentials): bool
    {
        return $this->guard()->validate($credentials);
    }

    /**
     * Set the user on the default guard.
     *
     * @param AuthenticatableInterface $user The user to authenticate
     */
    public function setUser(AuthenticatableInterface $user): void
    {
        $this->guard()->setUser($user);
    }
}
