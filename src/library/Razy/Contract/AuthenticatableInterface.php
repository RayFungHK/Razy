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
 * Contract for entities that can be authenticated.
 *
 * Any user model or entity used with the Auth system must implement
 * this interface to provide identity and credential information.
 *
 * @package Razy\Contract
 */
interface AuthenticatableInterface
{
    /**
     * Get the unique identifier value for the user (e.g., user ID).
     *
     * @return string|int The identifier value
     */
    public function getAuthIdentifier(): string|int;

    /**
     * Get the name of the unique identifier column (e.g., 'id', 'uuid').
     *
     * @return string The identifier column name
     */
    public function getAuthIdentifierName(): string;

    /**
     * Get the hashed password for the user.
     *
     * @return string The hashed password
     */
    public function getAuthPassword(): string;
}
