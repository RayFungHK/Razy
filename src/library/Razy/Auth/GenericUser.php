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

use Razy\Contract\AuthenticatableInterface;

/**
 * A simple, generic user implementation backed by an associative array.
 *
 * Suitable for testing, API-token-based auth, or any scenario where
 * a full user model is not needed. Attributes are accessed via
 * array-backed getters.
 *
 * @package Razy\Auth
 */
class GenericUser implements AuthenticatableInterface
{
    /**
     * Create a new GenericUser.
     *
     * @param array<string, mixed> $attributes User attributes (must include 'id' and 'password' keys,
     *                                         or custom keys specified by getAuthIdentifierName())
     */
    public function __construct(
        private readonly array $attributes,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function getAuthIdentifier(): string|int
    {
        return $this->attributes[$this->getAuthIdentifierName()];
    }

    /**
     * {@inheritdoc}
     */
    public function getAuthIdentifierName(): string
    {
        return $this->attributes['__identifier_name'] ?? 'id';
    }

    /**
     * {@inheritdoc}
     */
    public function getAuthPassword(): string
    {
        return $this->attributes['password'] ?? '';
    }

    /**
     * Get an attribute by key.
     *
     * @param string $key The attribute key
     * @param mixed $default Default value if key doesn't exist
     *
     * @return mixed The attribute value
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Get all attributes.
     *
     * @return array<string, mixed> All attributes
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Check if an attribute exists.
     *
     * @param string $key The attribute key
     *
     * @return bool True if the attribute exists
     */
    public function hasAttribute(string $key): bool
    {
        return \array_key_exists($key, $this->attributes);
    }
}
