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

use RuntimeException;

/**
 * Password hashing utility.
 *
 * Provides a clean static API around PHP's password_hash/password_verify
 * functions, with sensible defaults for secure password hashing.
 *
 * @package Razy\Auth
 */
class Hash
{
    /**
     * Hash a password using the specified algorithm.
     *
     * @param string $password The plain-text password
     * @param string|int|null $algo The hashing algorithm (PASSWORD_BCRYPT, PASSWORD_ARGON2ID, etc.)
     * @param array $options Algorithm-specific options (e.g., ['cost' => 12] for bcrypt)
     *
     * @return string The hashed password
     *
     * @throws RuntimeException If hashing fails
     */
    public static function make(string $password, string|int|null $algo = PASSWORD_BCRYPT, array $options = []): string
    {
        $hash = \password_hash($password, $algo, $options);

        if ($hash === false) {
            throw new RuntimeException('Failed to hash password');
        }

        return $hash;
    }

    /**
     * Verify a plain-text password against a hash.
     *
     * @param string $password The plain-text password to check
     * @param string $hash The hashed password to check against
     *
     * @return bool True if the password matches the hash
     */
    public static function check(string $password, string $hash): bool
    {
        return \password_verify($password, $hash);
    }

    /**
     * Determine if the given hash needs to be rehashed (e.g., cost changed or algorithm upgraded).
     *
     * @param string $hash The hashed password
     * @param string|int|null $algo The desired algorithm
     * @param array $options The desired options
     *
     * @return bool True if the hash should be rehashed
     */
    public static function needsRehash(string $hash, string|int|null $algo = PASSWORD_BCRYPT, array $options = []): bool
    {
        return \password_needs_rehash($hash, $algo, $options);
    }

    /**
     * Get information about a hash (algorithm, options, etc.).
     *
     * @param string $hash The hashed password
     *
     * @return array{algo: int|string|null, algoName: string, options: array} Hash information
     */
    public static function info(string $hash): array
    {
        return \password_get_info($hash);
    }
}
