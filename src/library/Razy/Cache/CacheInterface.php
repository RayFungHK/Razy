<?php

/**
 * This file is part of Razy v0.5.
 *
 * PSR-16 compatible Simple Cache interface for the Razy framework.
 * Provides a standard contract for cache adapters with get/set/delete operations,
 * TTL support, and batch operations.
 *
 * @see https://www.php-fig.org/psr/psr-16/
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy\Cache;

use DateInterval;
use Razy\Contract\SimpleCache\PsrCacheInterface;

/**
 * PSR-16 Simple Cache interface.
 *
 * Extends the PSR-16 CacheInterface for full compliance.
 * All cache adapters must implement this interface to be compatible with
 * the Cache facade and framework integrations.
 *
 * @interface CacheInterface
 */
interface CacheInterface extends PsrCacheInterface
{
    /**
     * Fetches a value from the cache.
     *
     * @param string $key The unique key of this item in the cache.
     * @param mixed $default Default value to return if the key does not exist.
     *
     * @return mixed The value of the item from the cache, or $default in case of cache miss.
     *
     * @throws InvalidArgumentException If the $key string is not a legal value.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Persists data in the cache, uniquely referenced by a key with an optional expiration TTL time.
     *
     * @param string $key The key of the item to store.
     * @param mixed $value The value of the item to store. Must be serializable.
     * @param int|DateInterval|null $ttl Optional. The TTL value of this item. If no value is sent and
     *                                   the driver supports TTL then the library may set a default value
     *                                   for it or let the driver take care of that.
     *
     * @return bool True on success and false on failure.
     *
     * @throws InvalidArgumentException If the $key string is not a legal value.
     */
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool;

    /**
     * Delete an item from the cache by its unique key.
     *
     * @param string $key The unique cache key of the item to delete.
     *
     * @return bool True if the item was successfully removed. False if there was an error.
     *
     * @throws InvalidArgumentException If the $key string is not a legal value.
     */
    public function delete(string $key): bool;

    /**
     * Wipes clean the entire cache's keys.
     *
     * @return bool True on success and false on failure.
     */
    public function clear(): bool;

    /**
     * Obtains multiple cache items by their unique keys.
     *
     * @param iterable<string> $keys A list of keys that can be obtained in a single operation.
     * @param mixed $default Default value to return for keys that do not exist.
     *
     * @return iterable<string, mixed> A list of key => value pairs. Cache keys that do not exist or are stale
     *                                 will have $default as value.
     *
     * @throws InvalidArgumentException If $keys is neither an array nor a Traversable,
     *                                  or if any of the $keys are not a legal value.
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable;

    /**
     * Persists a set of key => value pairs in the cache, with an optional TTL.
     *
     * @param iterable<string, mixed> $values A list of key => value pairs for a multiple-set operation.
     * @param int|DateInterval|null $ttl Optional. The TTL value of this item.
     *
     * @return bool True on success and false on failure.
     *
     * @throws InvalidArgumentException If $values is neither an array nor a Traversable.
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool;

    /**
     * Deletes multiple cache items in a single operation.
     *
     * @param iterable<string> $keys A list of string-based keys to be deleted.
     *
     * @return bool True if the items were successfully removed. False if there was an error.
     *
     * @throws InvalidArgumentException If $keys is neither an array nor a Traversable.
     */
    public function deleteMultiple(iterable $keys): bool;

    /**
     * Determines whether an item is present in the cache.
     *
     * @param string $key The cache item key.
     *
     * @return bool
     *
     * @throws InvalidArgumentException If the $key string is not a legal value.
     */
    public function has(string $key): bool;
}
