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

namespace Razy\Contract;

/**
 * Contract for authentication guards.
 *
 * A guard is responsible for determining if a user is authenticated
 * and providing access to the authenticated user entity. Different
 * guards implement different authentication strategies (session,
 * token, API key, etc.).
 *
 * @package Razy\Contract
 */
interface GuardInterface
{
    /**
     * Determine if the current user is authenticated.
     *
     * @return bool True if a user is authenticated
     */
    public function check(): bool;

    /**
     * Determine if the current user is a guest (not authenticated).
     *
     * @return bool True if no user is authenticated
     */
    public function guest(): bool;

    /**
     * Get the currently authenticated user.
     *
     * @return AuthenticatableInterface|null The user, or null if not authenticated
     */
    public function user(): ?AuthenticatableInterface;

    /**
     * Get the ID of the currently authenticated user.
     *
     * @return string|int|null The user ID, or null if not authenticated
     */
    public function id(): string|int|null;

    /**
     * Validate credentials without persisting the authentication state.
     *
     * @param array<string, mixed> $credentials Key-value pairs (e.g., email + password)
     *
     * @return bool True if credentials are valid
     */
    public function validate(array $credentials): bool;

    /**
     * Set the current user (e.g., after manual authentication).
     *
     * @param AuthenticatableInterface $user The user to set as authenticated
     */
    public function setUser(AuthenticatableInterface $user): void;
}
