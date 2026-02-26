<?php

/**
 * This file is part of Razy v0.5.
 *
 * Null cache adapter implementing the PSR-16 CacheInterface.
 * A no-op adapter that never stores anything — used when caching
 * is disabled or during testing.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 *
 * @license MIT
 */

namespace Razy\Cache;

use DateInterval;

/**
 * Null (no-op) cache adapter.
 *
 * All get operations return the default value, all set operations
 * return true (pretending success), and all state checks return false.
 * Useful for environments where caching should be transparently disabled.
 *
 * @class NullAdapter
 */
class NullAdapter implements CacheInterface
{
    /**
     * {@inheritdoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $default;
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple(iterable $keys): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $key): bool
    {
        return false;
    }
}
