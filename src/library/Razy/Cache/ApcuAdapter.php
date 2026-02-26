<?php
/**
 * This file is part of Razy v0.5.
 *
 * APCu-based cache adapter implementing the PSR-16 CacheInterface.
 * Provides high-performance in-memory caching using the APCu extension.
 * Falls back gracefully if APCu is not available.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\Cache;

use DateInterval;
use DateTime;

/**
 * APCu cache adapter for the Razy framework.
 *
 * Uses PHP's APCu extension for shared memory caching. All keys are
 * prefixed with a configurable namespace to avoid collisions with
 * other applications sharing the same APCu store.
 *
 * Requires: ext-apcu
 *
 * @class ApcuAdapter
 */
class ApcuAdapter implements CacheInterface
{
    /** @var string Key prefix for namespace isolation */
    private string $prefix;

    /**
     * ApcuAdapter constructor.
     *
     * @param string $prefix Optional key prefix for namespace isolation (default: 'razy_')
     *
     * @throws InvalidArgumentException If APCu extension is not available
     */
    public function __construct(string $prefix = 'razy_')
    {
        if (!extension_loaded('apcu') || !apcu_enabled()) {
            throw new InvalidArgumentException('APCu extension is not available or not enabled.');
        }

        $this->prefix = $prefix;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);

        $value = apcu_fetch($this->prefix . $key, $success);

        return $success ? $value : $default;
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->validateKey($key);

        $seconds = $this->ttlToSeconds($ttl);

        // Negative or zero TTL means delete
        if ($seconds !== null && $seconds <= 0) {
            return $this->delete($key);
        }

        return apcu_store($this->prefix . $key, $value, $seconds ?? 0);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        $this->validateKey($key);

        apcu_delete($this->prefix . $key);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): bool
    {
        // Only clear keys with our prefix
        $iterator = new \APCUIterator('/^' . preg_quote($this->prefix, '/') . '/');
        foreach ($iterator as $entry) {
            apcu_delete($entry['key']);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $success = true;
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $key): bool
    {
        $this->validateKey($key);

        return apcu_exists($this->prefix . $key);
    }

    /**
     * Convert TTL to integer seconds.
     *
     * @param null|int|DateInterval $ttl The TTL value
     *
     * @return int|null Seconds, or null for no expiry
     */
    private function ttlToSeconds(null|int|DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof DateInterval) {
            $now = new DateTime();
            $future = (clone $now)->add($ttl);
            return $future->getTimestamp() - $now->getTimestamp();
        }

        return $ttl;
    }

    /**
     * Validate a cache key.
     *
     * @param string $key The cache key to validate
     *
     * @throws InvalidArgumentException If the key is invalid
     */
    private function validateKey(string $key): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('Cache key must not be empty.');
        }

        if (preg_match('/[{}()\/\\\\@:]/', $key)) {
            throw new InvalidArgumentException("Cache key '{$key}' contains reserved characters: {}()/\\@:");
        }
    }
}
