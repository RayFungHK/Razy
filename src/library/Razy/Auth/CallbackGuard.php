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

namespace Razy\Auth;

use Closure;
use Razy\Contract\AuthenticatableInterface;
use Razy\Contract\GuardInterface;

/**
 * A callback-based authentication guard.
 *
 * Delegates user resolution and credential validation to user-supplied
 * closures, making it easy to create custom guards without implementing
 * the full GuardInterface.
 *
 * Usage:
 * ```php
 * $guard = new CallbackGuard(
 *     userResolver: fn() => $_SESSION['user'] ?? null,
 *     credentialValidator: function (array $creds) use ($db) {
 *         $user = $db->findByEmail($creds['email']);
 *         return $user && Hash::check($creds['password'], $user->getAuthPassword());
 *     }
 * );
 * ```
 *
 * @package Razy\Auth
 */
class CallbackGuard implements GuardInterface
{
    /**
     * The currently authenticated user.
     */
    private ?AuthenticatableInterface $user = null;

    /**
     * Whether the user has been resolved for this request.
     */
    private bool $resolved = false;

    /**
     * @param Closure|null $userResolver Closure that returns AuthenticatableInterface|null.
     *                                   Called once (lazy) to resolve the current user.
     * @param Closure|null $credentialValidator Closure(array $credentials): bool.
     *                                          Validates credentials without setting state.
     */
    public function __construct(
        private readonly ?Closure $userResolver = null,
        private readonly ?Closure $credentialValidator = null,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * {@inheritdoc}
     */
    public function guest(): bool
    {
        return !$this->check();
    }

    /**
     * {@inheritdoc}
     *
     * Uses the user resolver callback (if provided) to lazily resolve
     * the authenticated user on first access.
     */
    public function user(): ?AuthenticatableInterface
    {
        if (!$this->resolved) {
            $this->resolved = true;

            if ($this->userResolver !== null) {
                $this->user = ($this->userResolver)();
            }
        }

        return $this->user;
    }

    /**
     * {@inheritdoc}
     */
    public function id(): string|int|null
    {
        return $this->user()?->getAuthIdentifier();
    }

    /**
     * {@inheritdoc}
     */
    public function validate(array $credentials): bool
    {
        if ($this->credentialValidator === null) {
            return false;
        }

        return ($this->credentialValidator)($credentials);
    }

    /**
     * {@inheritdoc}
     */
    public function setUser(AuthenticatableInterface $user): void
    {
        $this->user = $user;
        $this->resolved = true;
    }

    /**
     * Reset the guard state (clear the resolved user).
     *
     * Useful for request-scoped guards in worker mode.
     */
    public function reset(): void
    {
        $this->user = null;
        $this->resolved = false;
    }
}
